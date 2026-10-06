<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Url;
use App\Core\View;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\MkAuth\MkAuthDatabase;

/**
 * Entrega a visao administrativa com indicadores operacionais disponiveis.
 */
final class DashboardController
{
    public function __construct(
        private View $view,
        private Config $config,
        private LocalRepository $localRepository,
        private MkAuthDatabase $mkauthDatabase,
        private Database $database
    ) {
    }

    public function index(Request $request): Response
    {
        $html = $this->view->render('dashboard/index', [
            'pageTitle' => 'Dashboard',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveViewUser(),
            'alertTiles' => $this->homeAlertTiles(),
        ]);

        return Response::html($html);
    }

    /**
     * Números semânticos da home (urgente/atenção/aguardando/ok). As regras dos três
     * blocos de clientes vivem em MkAuthDatabase::situationCondition(), compartilhada
     * com a lista filtrada de /clientes?situacao=.
     */
    private function homeAlertTiles(): array
    {
        // Cada bloco leva à lista que o alimenta; sem permissão para a lista de destino, fica sem link.
        $access = $this->localRepository->accessProfileForUser(is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : []);
        $clientsLink = !empty($access['can_search_clients']);
        $acceptancesLink = !empty($access['is_manager']) || !empty($access['can_access_contracts']);
        $clients = static fn (string $situation): string => $clientsLink ? Url::to('/clientes?situacao=' . $situation) : '';
        $grace = $this->localRepository->homeOverdueGraceDays();
        $counts = $this->situationCounts($grace);

        return [
            [
                'label' => 'Bloqueados ou reduzidos',
                'value' => (string) $counts['bloqueados'],
                'description' => 'Clientes ativos bloqueados ou com velocidade reduzida',
                'variant' => 'urgente',
                'href' => $clients('bloqueados'),
            ],
            [
                'label' => 'Atenção',
                'value' => (string) $counts['atencao'],
                'description' => 'Ativos com fatura vencida há mais de ' . $grace . ' dias',
                'variant' => 'attention',
                'href' => $clients('atencao'),
            ],
            [
                'label' => 'Aceites pendentes',
                'value' => (string) $this->pendingAcceptancesCount(),
                'description' => 'Aguardando assinatura do cliente',
                'variant' => 'queue',
                'href' => $acceptancesLink ? Url::to('/contratos/aceites/pendentes') : '',
            ],
            [
                'label' => 'Em dia',
                'value' => (string) $counts['em_dia'],
                'description' => 'Demais ativos (vencido de até ' . $grace . ' dias entra aqui)',
                'variant' => 'ok',
                'href' => $clients('em_dia'),
            ],
        ];
    }

    /** @return array{bloqueados: int, atencao: int, em_dia: int} */
    private function situationCounts(int $grace): array
    {
        try {
            return $this->mkauthDatabase->homeSituationCounts($grace);
        } catch (\Throwable $exception) {
            return ['bloqueados' => 0, 'atencao' => 0, 'em_dia' => 0];
        }
    }

    /**
     * Mesma condição de App\Controllers\ContractController::countPendingAcceptances(),
     * reaproveitada aqui porque o método de origem é privado ao módulo de Contratos.
     */
    private function pendingAcceptancesCount(): int
    {
        if (!$this->tableExists('contract_acceptances')) {
            return 0;
        }

        try {
            $row = $this->database->fetchOne(
                'SELECT COUNT(*) AS total FROM contract_acceptances WHERE status IN ("criado", "enviado", "assinatura_pendente", "expirado")'
            );

            return (int) ($row['total'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
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

    private function resolveUser(): array
    {
        $user = $_SESSION['user'] ?? [
            'name' => 'Operador',
            'login' => '',
            'role' => 'Operação',
            'source' => 'fallback',
        ];

        $user['login'] = $this->localRepository->normalizeLogin((string) ($user['login'] ?? ''));

        return $user;
    }

    private function resolveViewUser(): array
    {
        $user = $this->resolveUser();
        $access = $this->localRepository->accessProfileForUser($user);
        $user['access'] = $access;
        $user['can_manage_settings'] = $access['can_manage_settings'];
        $user['can_access_contracts'] = $access['can_access_contracts'];
        $user['can_manage_financial'] = $access['can_manage_financial'];
        $user['can_manage'] = !empty($user['can_manage']) || $access['is_manager'];

        if ($access['is_admin']) {
            $user['role'] = 'admin';
        } elseif ($access['is_manager']) {
            $user['role'] = 'manager';
        }

        return $user;
    }
}
