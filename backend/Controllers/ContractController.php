<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Url;
use App\Core\View;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Contracts\FinancialTaskRepository;
use App\Infrastructure\Contracts\NotificationLogRepository;
use App\Infrastructure\Local\LocalRepository;

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
        private Database $database,
        private LocalRepository $localRepository,
        private FinancialTaskRepository $financialTaskRepository,
        private NotificationLogRepository $notificationLogRepository
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
            'canManageContracts' => $this->canManageContracts(),
        ]);

        return Response::html($html);
    }

    public function novos(Request $request): Response
    {
        $state = $this->buildState();
        $filters = $this->normalizeListFilters($request);

        $html = $this->view->render('contracts/novos', [
            'pageTitle' => 'Novos Contratos',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveUser(),
            'moduleReady' => $state['moduleReady'],
            'moduleMessage' => $state['moduleMessage'],
            'filters' => $filters,
            'contracts' => $this->loadRecentContracts($filters, 50),
            'canManageContracts' => $this->canManageContracts(),
        ]);

        return Response::html($html);
    }

    public function aceitesPendentes(Request $request): Response
    {
        $state = $this->buildState();
        $filters = $this->normalizeListFilters($request);

        $html = $this->view->render('contracts/aceites_pendentes', [
            'pageTitle' => 'Aceites Pendentes',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveUser(),
            'moduleReady' => $state['moduleReady'],
            'moduleMessage' => $state['moduleMessage'],
            'filters' => $filters,
            'acceptances' => $this->loadPendingAcceptances($filters, 50),
            'canManageContracts' => $this->canManageContracts(),
        ]);

        return Response::html($html);
    }

    public function detalhe(Request $request): Response
    {
        $state = $this->buildState();
        $contractId = (int) $request->query('id', 0);
        $detail = $contractId > 0 ? $this->loadContractDetail($contractId) : null;

        if ($contractId <= 0 || $detail === null) {
            Flash::set('error', 'Nao foi possivel localizar o contrato solicitado.');
            return Response::redirect('/contratos/novos');
        }

        $html = $this->view->render('contracts/detalhe', [
            'pageTitle' => 'Detalhe do Contrato',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveUser(),
            'moduleReady' => $state['moduleReady'],
            'moduleMessage' => $state['moduleMessage'],
            'detail' => $detail,
            'canManageContracts' => $this->canManageContracts(),
            'simulatedAcceptanceLink' => $this->buildSimulatedAcceptanceLink($detail),
        ]);

        return Response::html($html);
    }

    public function concluirFinanceiro(Request $request): Response
    {
        if (!$this->canManageContracts()) {
            Flash::set('error', 'Somente o gestor pode concluir pendencias financeiras.');
            return Response::redirect('/contratos');
        }

        $contractId = (int) $request->input('contract_id', 0);
        $returnTo = trim((string) $request->input('return_to', '/contratos/detalhe?id=' . $contractId));
        $task = $contractId > 0 ? $this->financialTaskRepository->findByContractId($contractId) : null;

        if (!is_array($task) || !isset($task['id'])) {
            Flash::set('error', 'Nao foi possivel localizar a tarefa financeira.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        $taskId = (int) $task['id'];
        $status = (string) ($task['status'] ?? '');
        if ($status === 'concluido') {
            Flash::set('success', 'A tarefa financeira ja estava concluida.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        $this->financialTaskRepository->updateStatus($taskId, 'concluido');
        $this->recordAudit('contract.financial_task.completed', 'financial_task', $taskId, [
            'contract_id' => $contractId,
            'status' => 'concluido',
        ], $request);

        Flash::set('success', 'Pendencia financeira marcada como concluida.');

        return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
    }

    public function cancelarFinanceiro(Request $request): Response
    {
        if (!$this->canManageContracts()) {
            Flash::set('error', 'Somente o gestor pode cancelar pendencias financeiras.');
            return Response::redirect('/contratos');
        }

        $contractId = (int) $request->input('contract_id', 0);
        $returnTo = trim((string) $request->input('return_to', '/contratos/detalhe?id=' . $contractId));
        $task = $contractId > 0 ? $this->financialTaskRepository->findByContractId($contractId) : null;

        if (!is_array($task) || !isset($task['id'])) {
            Flash::set('error', 'Nao foi possivel localizar a tarefa financeira.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        $taskId = (int) $task['id'];
        $this->financialTaskRepository->updateStatus($taskId, 'cancelado');
        $this->recordAudit('contract.financial_task.canceled', 'financial_task', $taskId, [
            'contract_id' => $contractId,
            'status' => 'cancelado',
        ], $request);

        Flash::set('success', 'Pendencia financeira cancelada com registro de auditoria.');

        return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
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

    private function loadRecentContracts(array $filters = [], int $limit = 8): array
    {
        if (!$this->tablesReady(['client_contracts'])) {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $filters = $this->normalizeListFiltersFromArray($filters);
        $conditions = ['1 = 1'];
        $params = [];

        if ($filters['financeiro'] !== '') {
            $conditions[] = 'c.status_financeiro = :financeiro';
            $params['financeiro'] = $filters['financeiro'];
        }

        if ($filters['adesao'] !== '') {
            $conditions[] = 'c.tipo_adesao = :adesao';
            $params['adesao'] = $filters['adesao'];
        }

        if ($filters['aceite'] !== '') {
            $conditions[] = '(
                SELECT ca.status
                FROM contract_acceptances ca
                WHERE ca.contract_id = c.id
                ORDER BY ca.updated_at DESC, ca.id DESC
                LIMIT 1
            ) = :aceite';
            $params['aceite'] = $filters['aceite'];
        }

        try {
            return $this->database->fetchAll(
                'SELECT
                    c.id,
                    c.client_id,
                    c.mkauth_login,
                    c.nome_cliente,
                    c.telefone_cliente,
                    c.tipo_adesao,
                    c.valor_adesao,
                    c.parcelas_adesao,
                    c.valor_parcela_adesao,
                    c.vencimento_primeira_parcela,
                    c.fidelidade_meses,
                    c.beneficio_valor,
                    c.multa_total,
                    c.tipo_aceite,
                    c.observacao_adesao,
                    c.status_financeiro,
                    c.created_at,
                    c.updated_at,
                    (
                        SELECT ca.id
                        FROM contract_acceptances ca
                        WHERE ca.contract_id = c.id
                        ORDER BY ca.updated_at DESC, ca.id DESC
                        LIMIT 1
                    ) AS acceptance_id,
                    (
                        SELECT ca.status
                        FROM contract_acceptances ca
                        WHERE ca.contract_id = c.id
                        ORDER BY ca.updated_at DESC, ca.id DESC
                        LIMIT 1
                    ) AS acceptance_status,
                    (
                        SELECT ca.token_expires_at
                        FROM contract_acceptances ca
                        WHERE ca.contract_id = c.id
                        ORDER BY ca.updated_at DESC, ca.id DESC
                        LIMIT 1
                    ) AS acceptance_token_expires_at,
                    (
                        SELECT ft.id
                        FROM financial_tasks ft
                        WHERE ft.contract_id = c.id
                        ORDER BY ft.updated_at DESC, ft.id DESC
                        LIMIT 1
                    ) AS financial_task_id,
                    (
                        SELECT ft.status
                        FROM financial_tasks ft
                        WHERE ft.contract_id = c.id
                        ORDER BY ft.updated_at DESC, ft.id DESC
                        LIMIT 1
                    ) AS financial_task_status,
                    (
                        SELECT COUNT(*)
                        FROM notification_logs n
                        WHERE n.contract_id = c.id
                    ) AS notification_logs_count
                 FROM client_contracts c
                 WHERE ' . implode(' AND ', $conditions) . '
                 ORDER BY c.updated_at DESC, c.id DESC
                 LIMIT ' . (int) $limit
                ,
                $params
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private function loadPendingAcceptances(array $filters = [], int $limit = 8): array
    {
        if (!$this->tablesReady(['client_contracts', 'contract_acceptances'])) {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $filters = $this->normalizeListFiltersFromArray($filters);
        $conditions = ['a.status IN ("criado", "enviado", "expirado")'];
        $params = [];

        if ($filters['financeiro'] !== '') {
            $conditions[] = 'c.status_financeiro = :financeiro';
            $params['financeiro'] = $filters['financeiro'];
        }

        if ($filters['adesao'] !== '') {
            $conditions[] = 'c.tipo_adesao = :adesao';
            $params['adesao'] = $filters['adesao'];
        }

        if ($filters['aceite'] !== '') {
            $conditions[] = 'a.status = :aceite';
            $params['aceite'] = $filters['aceite'];
        }

        try {
            return $this->database->fetchAll(
                'SELECT
                    a.id AS acceptance_id,
                    a.contract_id,
                    a.token_hash,
                    a.token_expires_at,
                    a.status AS acceptance_status,
                    a.telefone_enviado,
                    a.whatsapp_message_id,
                    a.sent_at,
                    a.accepted_at,
                    a.ip_address,
                    a.user_agent,
                    a.termo_versao,
                    a.termo_hash,
                    a.created_at,
                    a.updated_at,
                    c.client_id,
                    c.nome_cliente,
                    c.mkauth_login,
                    c.tipo_adesao,
                    c.status_financeiro,
                    c.valor_adesao,
                    c.parcelas_adesao,
                    c.valor_parcela_adesao,
                    c.fidelidade_meses,
                    c.beneficio_valor,
                    c.multa_total,
                    c.tipo_aceite,
                    (
                        SELECT ft.id
                        FROM financial_tasks ft
                        WHERE ft.contract_id = c.id
                        ORDER BY ft.updated_at DESC, ft.id DESC
                        LIMIT 1
                    ) AS financial_task_id,
                    (
                        SELECT ft.status
                        FROM financial_tasks ft
                        WHERE ft.contract_id = c.id
                        ORDER BY ft.updated_at DESC, ft.id DESC
                        LIMIT 1
                    ) AS financial_task_status,
                    (
                        SELECT COUNT(*)
                        FROM notification_logs n
                        WHERE n.contract_id = c.id OR n.acceptance_id = a.id
                    ) AS notification_logs_count
                 FROM contract_acceptances a
                 INNER JOIN client_contracts c ON c.id = a.contract_id
                 WHERE ' . implode(' AND ', $conditions) . '
                 ORDER BY a.updated_at DESC, a.id DESC
                 LIMIT ' . (int) $limit
                ,
                $params
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private function loadContractDetail(int $contractId): ?array
    {
        if ($contractId <= 0 || !$this->tablesReady(['client_contracts', 'contract_acceptances', 'financial_tasks', 'notification_logs'])) {
            return null;
        }

        try {
            $contract = $this->database->fetchOne(
                'SELECT * FROM client_contracts WHERE id = :id LIMIT 1',
                ['id' => $contractId]
            );
        } catch (\Throwable) {
            $contract = null;
        }

        if (!is_array($contract)) {
            return null;
        }

        $acceptance = null;
        $financialTask = null;

        try {
            $acceptance = $this->database->fetchOne(
                'SELECT * FROM contract_acceptances WHERE contract_id = :contract_id ORDER BY updated_at DESC, id DESC LIMIT 1',
                ['contract_id' => $contractId]
            );
        } catch (\Throwable) {
            $acceptance = null;
        }

        try {
            $financialTask = $this->financialTaskRepository->findByContractId($contractId);
        } catch (\Throwable) {
            $financialTask = null;
        }

        try {
            $notificationLogs = $this->notificationLogRepository->listByContractId($contractId, 100);
            if ($acceptanceId !== null) {
                $acceptanceLogs = $this->notificationLogRepository->listByAcceptanceId($acceptanceId, 100);
                $indexedLogs = [];

                foreach (array_merge($notificationLogs, $acceptanceLogs) as $log) {
                    if (!is_array($log) || !isset($log['id'])) {
                        continue;
                    }

                    $indexedLogs[(int) $log['id']] = $log;
                }

                $notificationLogs = array_values($indexedLogs);
                usort(
                    $notificationLogs,
                    static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''))
                );
            }
        } catch (\Throwable) {
            $notificationLogs = [];
        }

        $acceptanceId = is_array($acceptance) && isset($acceptance['id']) ? (int) $acceptance['id'] : null;
        $financialTaskId = is_array($financialTask) && isset($financialTask['id']) ? (int) $financialTask['id'] : null;

        try {
            $auditLogs = $this->localRepository->auditLogsForContract(
                $contractId,
                $acceptanceId,
                $financialTaskId,
                isset($contract['client_id']) ? (int) $contract['client_id'] : null,
                100
            );
        } catch (\Throwable) {
            $auditLogs = [];
        }

        return [
            'contract' => $contract,
            'acceptance' => $acceptance,
            'financialTask' => $financialTask,
            'notificationLogs' => $notificationLogs,
            'auditLogs' => $auditLogs,
        ];
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

    private function normalizeListFilters(Request|array $request): array
    {
        if ($request instanceof Request) {
            $raw = [
                'financeiro' => trim((string) $request->query('financeiro', '')),
                'aceite' => trim((string) $request->query('aceite', '')),
                'adesao' => trim((string) $request->query('adesao', '')),
            ];
        } else {
            $raw = [
                'financeiro' => trim((string) ($request['financeiro'] ?? '')),
                'aceite' => trim((string) ($request['aceite'] ?? '')),
                'adesao' => trim((string) ($request['adesao'] ?? '')),
            ];
        }

        return $this->normalizeListFiltersFromArray($raw);
    }

    private function normalizeListFiltersFromArray(array $filters): array
    {
        $financeiro = $this->normalizeFilterOption((string) ($filters['financeiro'] ?? ''), [
            '',
            'pendente_lancamento',
            'lancado',
            'dispensado',
        ]);
        $aceite = $this->normalizeFilterOption((string) ($filters['aceite'] ?? ''), [
            '',
            'criado',
            'enviado',
            'aceito',
            'expirado',
            'cancelado',
        ]);
        $adesao = $this->normalizeFilterOption((string) ($filters['adesao'] ?? ''), [
            '',
            'cheia',
            'promocional',
            'isenta',
        ]);

        return [
            'financeiro' => $financeiro,
            'aceite' => $aceite,
            'adesao' => $adesao,
        ];
    }

    private function normalizeFilterOption(string $value, array $allowed): string
    {
        $value = trim($value);

        return in_array($value, $allowed, true) ? $value : '';
    }

    private function canManageContracts(): bool
    {
        $user = $this->resolveUser();
        $role = strtolower(trim((string) ($user['role'] ?? '')));

        return in_array($role, ['manager', 'platform_admin', 'gestor', 'admin', 'administrador'], true)
            || !empty($user['can_manage']);
    }

    private function buildSimulatedAcceptanceLink(array $detail): string
    {
        $acceptance = is_array($detail['acceptance'] ?? null) ? $detail['acceptance'] : [];
        $contract = is_array($detail['contract'] ?? null) ? $detail['contract'] : [];
        $contractId = (int) ($contract['id'] ?? 0);
        $acceptanceId = (int) ($acceptance['id'] ?? 0);
        $tokenFragment = $acceptanceId > 0 ? (string) $acceptanceId : (string) $contractId;

        return Url::to('/aceite/' . rawurlencode($tokenFragment));
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
