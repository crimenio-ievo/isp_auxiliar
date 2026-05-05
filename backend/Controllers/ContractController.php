<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Infrastructure\Database\Database;

/**
 * Primeira interface visual do modulo Contratos & Aceites.
 *
 * Nesta fase o controlador apenas consulta dados reais quando as tabelas
 * existem e mostra mensagens amigaveis quando a base ainda nao foi criada.
 */
final class ContractController
{
    private const REQUIRED_TABLES = [
        'client_contracts',
        'contract_acceptances',
        'financial_tasks',
        'message_templates',
        'notification_logs',
    ];

    public function __construct(
        private View $view,
        private Config $config,
        private Database $database
    ) {
    }

    public function index(Request $request): Response
    {
        $state = $this->buildState();

        $html = $this->view->render('contracts/index', [
            'pageTitle' => 'Contratos & Aceites',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveUser(),
            'moduleReady' => $state['moduleReady'],
            'moduleMessage' => $state['moduleMessage'],
            'summaryCards' => $state['summaryCards'],
            'recentContracts' => $state['recentContracts'],
            'pendingAcceptances' => $state['pendingAcceptances'],
        ]);

        return Response::html($html);
    }

    public function novos(Request $request): Response
    {
        $state = $this->buildState();

        $html = $this->view->render('contracts/novos', [
            'pageTitle' => 'Novos Contratos',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveUser(),
            'moduleReady' => $state['moduleReady'],
            'moduleMessage' => $state['moduleMessage'],
            'contracts' => $state['recentContracts'],
        ]);

        return Response::html($html);
    }

    public function aceitesPendentes(Request $request): Response
    {
        $state = $this->buildState();

        $html = $this->view->render('contracts/aceites_pendentes', [
            'pageTitle' => 'Aceites Pendentes',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveUser(),
            'moduleReady' => $state['moduleReady'],
            'moduleMessage' => $state['moduleMessage'],
            'acceptances' => $state['pendingAcceptances'],
        ]);

        return Response::html($html);
    }

    private function buildState(): array
    {
        $missingTables = array_values(array_filter(
            self::REQUIRED_TABLES,
            fn (string $table): bool => !$this->tableExists($table)
        ));

        $moduleReady = $missingTables === [];

        if (!$moduleReady) {
            return [
                'moduleReady' => false,
                'moduleMessage' => 'O modulo Contratos & Aceites ainda aguarda as tabelas da migration 002. A interface esta pronta para quando a base estiver disponivel.',
                'summaryCards' => $this->emptySummaryCards(),
                'recentContracts' => [],
                'pendingAcceptances' => [],
                'missingTables' => $missingTables,
            ];
        }

        return [
            'moduleReady' => true,
            'moduleMessage' => '',
            'summaryCards' => [
                [
                    'label' => 'Contratos cadastrados',
                    'value' => (string) $this->countRows('client_contracts'),
                    'hint' => 'Base de contratos local do ISP Auxiliar.',
                ],
                [
                    'label' => 'Aceites pendentes',
                    'value' => (string) $this->countPendingAcceptances(),
                    'hint' => 'Aceites aguardando confirmação do cliente.',
                ],
                [
                    'label' => 'Aceites concluídos',
                    'value' => (string) $this->countRows('contract_acceptances', 'status = "aceito"'),
                    'hint' => 'Termos já aceitos com evidência registrada.',
                ],
                [
                    'label' => 'Pendências financeiras',
                    'value' => (string) $this->countRows('financial_tasks', 'status IN ("aberto", "em_andamento")'),
                    'hint' => 'Chamados financeiros ainda abertos.',
                ],
            ],
            'recentContracts' => $this->loadRecentContracts(),
            'pendingAcceptances' => $this->loadPendingAcceptances(),
        ];
    }

    private function loadRecentContracts(int $limit = 8): array
    {
        if (!$this->tablesReady(['client_contracts'])) {
            return [];
        }

        $limit = max(1, min(50, $limit));

        try {
            return $this->database->fetchAll(
                'SELECT id, client_id, mkauth_login, nome_cliente, telefone_cliente, tipo_adesao, valor_adesao, parcelas_adesao, valor_parcela_adesao, tipo_aceite, status_financeiro, created_at, updated_at
                 FROM client_contracts
                 ORDER BY updated_at DESC, id DESC
                 LIMIT ' . (int) $limit
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private function loadPendingAcceptances(int $limit = 8): array
    {
        if (!$this->tablesReady(['client_contracts', 'contract_acceptances'])) {
            return [];
        }

        $limit = max(1, min(50, $limit));

        try {
            return $this->database->fetchAll(
                'SELECT
                    a.id AS acceptance_id,
                    a.contract_id,
                    a.token_hash,
                    a.token_expires_at,
                    a.status,
                    a.telefone_enviado,
                    a.whatsapp_message_id,
                    a.sent_at,
                    a.accepted_at,
                    a.created_at,
                    a.updated_at,
                    c.nome_cliente,
                    c.mkauth_login,
                    c.tipo_adesao,
                    c.status_financeiro
                 FROM contract_acceptances a
                 INNER JOIN client_contracts c ON c.id = a.contract_id
                 WHERE a.status IN ("criado", "enviado", "expirado")
                 ORDER BY a.updated_at DESC, a.id DESC
                 LIMIT ' . (int) $limit
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private function countRows(string $table, string $where = '1 = 1'): int
    {
        if (!$this->tablesReady([$table])) {
            return 0;
        }

        try {
            $row = $this->database->fetchOne('SELECT COUNT(*) AS total FROM ' . $table . ' WHERE ' . $where);

            return (int) ($row['total'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function countPendingAcceptances(): int
    {
        if (!$this->tablesReady(['contract_acceptances'])) {
            return 0;
        }

        try {
            $row = $this->database->fetchOne(
                'SELECT COUNT(*) AS total FROM contract_acceptances WHERE status IN ("criado", "enviado", "expirado")'
            );

            return (int) ($row['total'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function tablesReady(array $tables): bool
    {
        foreach ($tables as $table) {
            if (!$this->tableExists($table)) {
                return false;
            }
        }

        return true;
    }

    private function tableExists(string $table): bool
    {
        try {
            $row = $this->database->fetchOne(
                'SELECT 1 AS found FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                 AND table_name = :table
                 LIMIT 1',
                ['table' => $table]
            );

            return is_array($row);
        } catch (\Throwable) {
            return false;
        }
    }

    private function emptySummaryCards(): array
    {
        return [
            [
                'label' => 'Contratos cadastrados',
                'value' => '0',
                'hint' => 'Aguarda migration 002.',
            ],
            [
                'label' => 'Aceites pendentes',
                'value' => '0',
                'hint' => 'Aguarda migration 002.',
            ],
            [
                'label' => 'Aceites concluídos',
                'value' => '0',
                'hint' => 'Aguarda migration 002.',
            ],
            [
                'label' => 'Pendências financeiras',
                'value' => '0',
                'hint' => 'Aguarda migration 002.',
            ],
        ];
    }

    private function resolveUser(): array
    {
        return $_SESSION['user'] ?? [
            'name' => 'Operador',
            'login' => '',
            'role' => 'Operacao',
            'source' => 'fallback',
        ];
    }
}
