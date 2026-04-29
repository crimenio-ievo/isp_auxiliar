<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\MkAuth\MkAuthDatabase;

/**
 * Centraliza telas institucionais e modulos operacionais simples desta fase.
 */
final class SystemController
{
    public function __construct(
        private View $view,
        private Config $config,
        private MkAuthDatabase $mkauthDatabase,
        private LocalRepository $localRepository
    ) {
    }

    public function installations(Request $request): Response
    {
        $html = $this->view->render('installations/index', [
            'pageTitle' => 'Instalacoes',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveUser(),
            'installations' => $this->installationRecords(),
        ]);

        return Response::html($html);
    }

    public function users(Request $request): Response
    {
        $users = $this->resolveUsers();

        $html = $this->view->render('users/index', [
            'pageTitle' => 'Usuarios',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveUser(),
            'users' => $users,
            'usersSource' => $this->mkauthDatabase->isConfigured() ? 'mkauth' : 'indisponivel',
        ]);

        return Response::html($html);
    }

    public function logs(Request $request): Response
    {
        $html = $this->view->render('logs/index', [
            'pageTitle' => 'Logs',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveUser(),
            'entries' => $this->logEntries(),
        ]);

        return Response::html($html);
    }

    public function health(Request $request): Response
    {
        return Response::json([
            'status' => 'ok',
            'application' => $this->config->get('app.name', 'ISP Auxiliar'),
            'environment' => $this->config->get('app.env', 'production'),
            'path' => $request->path(),
            'logged_in' => !empty($_SESSION['user']),
            'timestamp' => gmdate('c'),
        ]);
    }

    public function notFound(Request $request): Response
    {
        $html = $this->view->render('errors/404', [
            'pageTitle' => 'Pagina nao encontrada',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveUser(),
            'layoutMode' => 'guest',
        ]);

        return Response::html($html, 404);
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

    private function resolveUsers(): array
    {
        $users = [];

        try {
            foreach ($this->localRepository->listLocalUsers(100) as $item) {
                $users[] = [
                    'name' => (string) ($item['name'] ?? 'Gestor'),
                    'login' => (string) ($item['login'] ?? ''),
                    'role' => $this->roleLabel((string) ($item['role'] ?? 'manager')),
                    'status' => (($item['status'] ?? 'active') === 'active') ? 'Ativo' : 'Inativo',
                    'email' => (string) ($item['email'] ?? ''),
                    'uuid' => '',
                ];
            }
        } catch (\Throwable) {
            $users = [];
        }

        if (!$this->mkauthDatabase->isConfigured()) {
            return $users !== [] ? $users : [
                ['name' => 'MkAuth não configurado', 'login' => '-', 'role' => 'Integração', 'status' => 'Indisponível'],
            ];
        }

        try {
            $collection = $this->mkauthDatabase->listAccessUsers(100);
        } catch (\Throwable $exception) {
            return $users !== [] ? $users : [
                ['name' => 'Falha ao consultar MkAuth', 'login' => '-', 'role' => 'Integração', 'status' => 'Indisponivel'],
            ];
        }

        if (!is_array($collection) || $collection === []) {
            return $users !== [] ? $users : [
                ['name' => 'Nenhum usuário encontrado', 'login' => '-', 'role' => 'MkAuth', 'status' => 'Vazio'],
            ];
        }

        foreach ($collection as $item) {
            if (!is_array($item)) {
                continue;
            }

            $users[] = [
                'name' => (string) ($item['nome'] ?? $item['name'] ?? $item['login'] ?? 'Usuario'),
                'login' => (string) ($item['login'] ?? '-'),
                'role' => 'Tecnico MkAuth',
                'status' => (($item['ativo'] ?? 'sim') === 'sim') ? 'Ativo' : 'Inativo',
                'email' => (string) ($item['email'] ?? ''),
                'uuid' => (string) ($item['uuid_acesso'] ?? $item['uuid'] ?? ''),
            ];
        }

        return $users !== [] ? $users : [
            ['name' => 'Nenhum usuário encontrado', 'login' => '-', 'role' => 'MkAuth', 'status' => 'Vazio'],
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

            if (!is_array($decoded)) {
                continue;
            }

            $records[] = [
                'client' => (string) ($decoded['client_name'] ?? '-'),
                'login' => (string) ($decoded['login'] ?? '-'),
                'plan' => (string) ($decoded['plan'] ?? '-'),
                'status' => (string) ($decoded['status'] ?? 'awaiting_connection'),
                'date' => (string) ($decoded['updated_at'] ?? $decoded['created_at'] ?? '-'),
            ];
        }

        usort($records, static fn (array $a, array $b): int => strcmp((string) $b['date'], (string) $a['date']));

        return $records;
    }

    private function logEntries(): array
    {
        try {
            $auditLogs = $this->localRepository->recentAuditLogs(100);
        } catch (\Throwable) {
            $auditLogs = [];
        }

        if ($auditLogs !== []) {
            return array_map(function (array $row): array {
                return [
                    'level' => 'INFO',
                    'message' => $this->auditMessage($row),
                    'time' => (string) ($row['created_at'] ?? '-'),
                ];
            }, $auditLogs);
        }

        $entries = [];

        foreach ($this->installationRecords() as $record) {
            $entries[] = [
                'level' => $record['status'] === 'completed' ? 'INFO' : 'PENDENTE',
                'message' => sprintf(
                    'Instalação %s para %s (%s).',
                    $record['status'] === 'completed' ? 'finalizada' : 'aguardando conexão',
                    $record['client'],
                    $record['login']
                ),
                'time' => $record['date'],
            ];
        }

        return $entries !== [] ? $entries : [
            ['level' => 'INFO', 'message' => 'Nenhum evento operacional registrado.', 'time' => '-'],
        ];
    }

    private function roleLabel(string $role): string
    {
        return match ($role) {
            'platform_admin' => 'Administrador da plataforma',
            'manager' => 'Gestor do provedor',
            'technician' => 'Tecnico',
            default => $role,
        };
    }

    private function auditMessage(array $row): string
    {
        $action = (string) ($row['action'] ?? '');
        $actor = trim((string) ($row['actor_login'] ?? 'sistema')) ?: 'sistema';
        $context = json_decode((string) ($row['context_json'] ?? '{}'), true);
        $context = is_array($context) ? $context : [];

        return match ($action) {
            'auth.login' => "Login realizado por {$actor}.",
            'provider_settings.updated' => "Configuracoes do provedor atualizadas por {$actor}.",
            'client.provisioned' => 'Cliente enviado ao MkAuth por ' . $actor . ' (' . (string) ($context['login'] ?? '-') . ').',
            'client.provision_failed' => 'Falha no envio ao MkAuth por ' . $actor . ' (' . (string) ($context['login'] ?? '-') . ').',
            'installation.completed' => 'Instalação finalizada por ' . $actor . ' (' . (string) ($context['login'] ?? '-') . ').',
            default => $action !== '' ? "{$action} por {$actor}." : "Evento registrado por {$actor}.",
        };
    }
}
