<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Flash;
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
            'canManageRecords' => $this->canManageRecords(),
            'installations' => $this->installationRecords(),
        ]);

        return Response::html($html);
    }

    public function saveManager(Request $request): Response
    {
        if (!$this->canManageRecords()) {
            Flash::set('error', 'Apenas gestores podem alterar o usuário gestor.');
            return Response::redirect('/usuarios');
        }

        $managerLogin = strtolower(trim((string) $request->input('mkauth_manager_login', '')));

        try {
            $this->localRepository->saveProviderSettings([
                'mkauth_manager_login' => $managerLogin,
            ]);

            $user = $this->resolveUser();
            $this->localRepository->log(
                isset($user['id']) ? (int) $user['id'] : null,
                (string) ($user['login'] ?? ''),
                'manager_user.updated',
                'provider',
                isset($user['provider_id']) ? (int) $user['provider_id'] : null,
                ['manager_login' => $managerLogin],
                (string) $request->server('REMOTE_ADDR', ''),
                (string) $request->header('User-Agent', '')
            );

            Flash::set('success', $managerLogin !== ''
                ? 'Usuário gestor atualizado com sucesso.'
                : 'Usuário gestor removido com sucesso.');
        } catch (\Throwable $exception) {
            Flash::set('error', 'Nao foi possivel salvar o usuário gestor: ' . $exception->getMessage());
        }

        return Response::redirect('/usuarios');
    }

    public function deleteInstallation(Request $request): Response
    {
        if (!$this->canManageRecords()) {
            Flash::set('error', 'Apenas gestores podem excluir registros locais.');
            return Response::redirect('/instalacoes');
        }

        $token = trim((string) $request->input('token', ''));
        $login = trim((string) $request->input('login', ''));
        $origin = trim((string) $request->input('origin', ''));

        if ($token === '' && $login === '') {
            Flash::set('error', 'Informe o token ou o login para excluir o registro local.');
            return Response::redirect('/instalacoes');
        }

        $deleted = false;

        if ($token !== '') {
            $checkpoint = $this->localRepository->findInstallationCheckpointByToken($token);
            $registration = $this->localRepository->findClientRegistrationByRadiusToken($token);
            $payload = [];

            if (is_array($checkpoint)) {
                $decoded = json_decode((string) ($checkpoint['payload_json'] ?? ''), true);
                $payload = is_array($decoded) ? $decoded : [];
            }

            $this->removeEvidenceFolder((string) ($payload['evidence_ref'] ?? ''));
            if (is_array($registration)) {
                $this->removeEvidenceFolder((string) ($registration['evidence_ref'] ?? ''));
            }

            $this->localRepository->deleteEvidenceFilesByRegistrationId(
                isset($registration['id']) ? (int) $registration['id'] : (isset($checkpoint['registration_id']) ? (int) $checkpoint['registration_id'] : null)
            );
            $this->localRepository->deleteClientRegistrationByRadiusToken($token);
            $this->localRepository->deleteInstallationCheckpointByToken($token);

            $path = dirname(__DIR__, 2) . '/storage/installations/' . $token . '.json';
            if (is_file($path)) {
                @unlink($path);
            }

            $deleted = true;
        } elseif ($origin === 'draft' && $login !== '') {
            $deleted = $this->deleteDraftRecordsByLogin($login);
        }

        if ($deleted) {
            $this->localRepository->log(
                isset($this->resolveUser()['id']) ? (int) $this->resolveUser()['id'] : null,
                (string) ($this->resolveUser()['login'] ?? ''),
                'installation.deleted',
                'installation',
                null,
                ['token' => $token, 'login' => $login, 'origin' => $origin],
                (string) $request->server('REMOTE_ADDR', ''),
                (string) $request->header('User-Agent', '')
            );

            Flash::set('success', 'Registro local removido com sucesso.');
        } else {
            Flash::set('error', 'Nao foi possivel remover o registro local.');
        }

        return Response::redirect('/instalacoes');
    }

    public function users(Request $request): Response
    {
        $users = $this->resolveUsers();
        $managerLogin = $this->localRepository->providerSetting('mkauth_manager_login', '');
        try {
            $accessUsers = $this->mkauthDatabase->isConfigured() ? $this->mkauthDatabase->listAccessUsers(250) : [];
        } catch (\Throwable) {
            $accessUsers = [];
        }

        $html = $this->view->render('users/index', [
            'pageTitle' => 'Usuarios',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveUser(),
            'users' => $users,
            'usersSource' => $this->mkauthDatabase->isConfigured() ? 'mkauth' : 'indisponivel',
            'accessUsers' => $accessUsers,
            'managerLogin' => $managerLogin,
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
        $records = [];
        $knownLogins = [];

        $directory = dirname(__DIR__, 2) . '/storage/installations';

        if (is_dir($directory)) {
            foreach (glob($directory . '/*.json') ?: [] as $file) {
                $decoded = json_decode((string) file_get_contents($file), true);

                if (!is_array($decoded)) {
                    continue;
                }

                $login = (string) ($decoded['login'] ?? '-');
                $knownLogins[strtolower(trim($login))] = true;
                $dateValue = (string) ($decoded['updated_at'] ?? $decoded['created_at'] ?? '-');

                $records[] = [
                    'origin' => 'checkpoint',
                    'editable' => ((string) ($decoded['status'] ?? 'awaiting_connection')) !== 'completed',
                    'client' => (string) ($decoded['client_name'] ?? '-'),
                    'login' => $login,
                    'plan' => (string) ($decoded['plan'] ?? '-'),
                    'status' => (string) ($decoded['status'] ?? 'awaiting_connection'),
                    'token' => (string) ($decoded['token'] ?? ''),
                    'date' => $dateValue,
                    'last_check' => (string) ($decoded['last_connection_check_at'] ?? ''),
                    'last_connection' => is_array($decoded['last_connection'] ?? null) ? $decoded['last_connection'] : [],
                    'form_data' => is_array($decoded['form_data'] ?? null) ? $decoded['form_data'] : [],
                    'missing' => ((string) ($decoded['status'] ?? 'awaiting_connection')) === 'completed'
                        ? 'Concluida'
                        : 'Aguardando conexao',
                ];
            }
        }

        $draftDirectory = dirname(__DIR__, 2) . '/storage/cache/client_drafts';

        if (is_dir($draftDirectory)) {
            foreach (glob($draftDirectory . '/*.json') ?: [] as $file) {
                $decoded = json_decode((string) file_get_contents($file), true);

                if (!is_array($decoded)) {
                    continue;
                }

                $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
                $login = strtolower(trim((string) ($data['login'] ?? '')));

                if ($login === '' || isset($knownLogins[$login])) {
                    continue;
                }

                $photos = is_array($decoded['media']['photos'] ?? null) ? $decoded['media']['photos'] : [];
                $status = $photos === [] ? 'pending_photos' : 'pending_signature';
                $missing = $photos === [] ? 'Faltam fotos da instalação' : 'Falta assinatura do cliente';
                $dateValue = (string) ($decoded['updated_at'] ?? $decoded['created_at'] ?? (filemtime($file) ?: '-'));

                $records[] = [
                    'origin' => 'draft',
                    'editable' => true,
                    'draft_id' => basename($file, '.json'),
                    'client' => (string) ($data['nome_completo'] ?? '-'),
                    'login' => (string) ($data['login'] ?? '-'),
                    'plan' => (string) ($data['plano'] ?? '-'),
                    'status' => $status,
                    'token' => '',
                    'date' => $dateValue,
                    'last_check' => '',
                    'last_connection' => [],
                    'form_data' => $data,
                    'missing' => $missing,
                ];
            }
        }

        $counts = [];
        foreach ($records as $record) {
            $key = strtolower(trim((string) ($record['login'] ?? '')));
            if ($key === '') {
                continue;
            }
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        foreach ($records as &$record) {
            $key = strtolower(trim((string) ($record['login'] ?? '')));
            $record['duplicate'] = $key !== '' && (($counts[$key] ?? 0) > 1);
        }
        unset($record);

        usort($records, static fn (array $a, array $b): int => strcmp((string) $b['date'], (string) $a['date']));

        return $records;
    }

    private function canManageRecords(): bool
    {
        $role = strtolower((string) ($this->resolveUser()['role'] ?? ''));

        return in_array($role, ['platform_admin', 'manager', 'admin', 'administrador'], true);
    }

    private function removeEvidenceFolder(string $ref): void
    {
        $ref = trim($ref);

        if ($ref === '') {
            return;
        }

        $folder = dirname(__DIR__, 2) . '/storage/uploads/clientes/' . $ref;

        if (!is_dir($folder)) {
            return;
        }

        foreach (glob($folder . '/*') ?: [] as $item) {
            if (is_file($item)) {
                @unlink($item);
            }
        }

        @rmdir($folder);
    }

    private function deleteDraftRecordsByLogin(string $login): bool
    {
        $login = strtolower(trim($login));
        if ($login === '') {
            return false;
        }

        $baseDirectory = dirname(__DIR__, 2) . '/storage/cache/client_drafts';
        if (!is_dir($baseDirectory)) {
            return false;
        }

        $deleted = false;

        foreach (glob($baseDirectory . '/*.json') ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (!is_array($decoded)) {
                continue;
            }

            $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
            if (strtolower(trim((string) ($data['login'] ?? ''))) !== $login) {
                continue;
            }

            $draftId = basename($file, '.json');
            $folder = dirname(__DIR__, 2) . '/storage/uploads/clientes/drafts/' . $draftId;

            if (is_dir($folder)) {
                foreach (glob($folder . '/*') ?: [] as $item) {
                    if (is_file($item)) {
                        @unlink($item);
                    }
                }
                @rmdir($folder);
            }

            @unlink($file);
            $deleted = true;
        }

        return $deleted;
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
