<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Infrastructure\MkAuth\MkAuthDatabase;

/**
 * Entrega a visao administrativa com indicadores operacionais disponiveis.
 */
final class DashboardController
{
    public function __construct(
        private View $view,
        private Config $config,
        private MkAuthDatabase $mkauthDatabase
    ) {
    }

    public function index(Request $request): Response
    {
        $html = $this->view->render('dashboard/index', [
            'pageTitle' => 'Dashboard',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveUser(),
            'stats' => $this->dashboardStats(),
            'recentActivities' => $this->recentInstallationActivities(),
            'pipeline' => [
                'Cadastrar novo cliente com fotos e assinatura',
                'Validar conexão Radius antes de encerrar a instalação',
                'Consultar evidências pelo link gravado no MkAuth',
            ],
        ]);

        return Response::html($html);
    }

    private function resolveUser(): array
    {
        return $_SESSION['user'] ?? [
            'name' => 'Operador',
            'login' => '',
            'role' => 'Operação',
            'source' => 'fallback',
        ];
    }

    private function dashboardStats(): array
    {
        $pending = 0;
        $completed = 0;

        foreach ($this->installationRecords() as $record) {
            if ((string) ($record['status'] ?? '') === 'completed') {
                $completed++;
            } else {
                $pending++;
            }
        }

        return [
            ['label' => 'Instalações pendentes', 'value' => (string) $pending, 'hint' => 'Aguardando validação Radius'],
            ['label' => 'Instalações finalizadas', 'value' => (string) $completed, 'hint' => 'Com conexão confirmada'],
            ['label' => 'Evidências locais', 'value' => (string) $this->countEvidenceFolders(), 'hint' => 'Fotos, aceite e assinatura'],
            ['label' => 'Planos ativos', 'value' => (string) $this->safeMkAuthCount('plans'), 'hint' => 'Carregados do MkAuth'],
        ];
    }

    private function recentInstallationActivities(): array
    {
        $records = $this->installationRecords();

        usort($records, static function (array $a, array $b): int {
            return strcmp((string) ($b['updated_at'] ?? $b['created_at'] ?? ''), (string) ($a['updated_at'] ?? $a['created_at'] ?? ''));
        });

        $activities = [];

        foreach (array_slice($records, 0, 6) as $record) {
            $status = (string) ($record['status'] ?? '');
            $activities[] = [
                'time' => (string) ($record['updated_at'] ?? $record['created_at'] ?? '-'),
                'title' => $status === 'completed' ? 'Instalação finalizada' : 'Aguardando conexão Radius',
                'description' => trim((string) ($record['client_name'] ?? 'Cliente') . ' · ' . (string) ($record['login'] ?? '')),
            ];
        }

        return $activities !== [] ? $activities : [
            ['time' => '-', 'title' => 'Nenhuma instalação registrada', 'description' => 'Os próximos cadastros aparecerão nesta linha do tempo.'],
        ];
    }

    private function installationRecords(): array
    {
        $directory = dirname(__DIR__, 2) . '/storage/installations';

        if (!is_dir($directory)) {
            return [];
        }

        $records = [];

        foreach (glob($directory . '/*.json') ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                $records[] = $decoded;
            }
        }

        return $records;
    }

    private function countEvidenceFolders(): int
    {
        $directory = dirname(__DIR__, 2) . '/storage/uploads/clientes';

        if (!is_dir($directory)) {
            return 0;
        }

        return count(array_filter(glob($directory . '/*') ?: [], 'is_dir'));
    }

    private function safeMkAuthCount(string $type): int
    {
        if (!$this->mkauthDatabase->isConfigured()) {
            return 0;
        }

        try {
            return match ($type) {
                'plans' => $this->mkauthDatabase->countActivePlans(),
                'users' => $this->mkauthDatabase->countAccessUsers(),
                default => $this->mkauthDatabase->countClients(),
            };
        } catch (\Throwable $exception) {
            return 0;
        }
    }
}
