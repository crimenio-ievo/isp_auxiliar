<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\MkAuth\MkAuthDatabase;
use App\Core\AccessControl;
use App\Services\Logs\AuditEventCatalog;

/**
 * Centraliza telas institucionais e modulos operacionais simples desta fase.
 */
final class SystemController
{
    public function __construct(
        private View $view,
        private Config $config,
        private MkAuthDatabase $mkauthDatabase,
        private LocalRepository $localRepository,
        private Database $database
    ) {
    }

    public function installations(Request $request): Response
    {
        $html = $this->view->render('installations/index', [
            'pageTitle' => 'Instalacoes',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveViewUser(),
            'canManageRecords' => $this->canManageRecords(),
            'installations' => $this->installationRecords(),
            'recentActivities' => $this->recentInstallationActivities(),
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
            'user' => $this->resolveViewUser(),
            'users' => $users,
            'usersSource' => $this->mkauthDatabase->isConfigured() ? 'mkauth' : 'indisponivel',
            'accessUsers' => $accessUsers,
            'managerLogin' => $managerLogin,
        ]);

        return Response::html($html);
    }

    public function logs(Request $request): Response
    {
        $access = $this->localRepository->accessProfileForUser($this->resolveUser());
        if (!AccessControl::isGestor($access)) {
            Flash::set('error', 'Seu usuário não possui acesso aos logs.');
            return Response::redirect('/dashboard');
        }

        $categories = AuditEventCatalog::categories();
        $selected = trim((string) $request->query('categoria', ''));
        $selected = isset($categories[$selected]) ? $selected : '';
        $all = $this->logEntries();
        $counts = array_fill_keys(array_keys($categories), 0);
        foreach ($all as $entry) {
            $counts[$entry['category']]++;
        }
        $entries = $selected === '' ? $all : array_values(array_filter($all, static fn (array $entry): bool => $entry['category'] === $selected));

        $html = $this->view->render('logs/index', [
            'pageTitle' => 'Logs',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveViewUser(),
            'entries' => array_slice($entries, 0, 150),
            'categories' => $categories,
            'categoryCounts' => $counts,
            'selectedCategory' => $selected,
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

    public function release(Request $request): Response
    {
        $access = $this->localRepository->accessProfileForUser($this->resolveUser());
        if (empty($access['is_admin'])) {
            return Response::json(['status' => 'forbidden'], 403);
        }

        $schemaVersion = 'unavailable';
        try {
            $row = $this->database->fetchOne(
                'SELECT version FROM schema_migrations ORDER BY executed_at DESC, version DESC LIMIT 1'
            );
            $schemaVersion = trim((string) ($row['version'] ?? 'none')) ?: 'none';
        } catch (\Throwable) {
            // O endpoint continua útil durante instalação ou indisponibilidade do banco.
        }

        $version = defined('APP_VERSION_INFO') && is_array(APP_VERSION_INFO) ? APP_VERSION_INFO : [];

        return Response::json([
            'status' => 'ok',
            'channel' => $this->config->get('app.release.channel', 'stable'),
            'release_id' => $this->config->get('app.release.id', ''),
            'commit' => $this->config->get('app.release.commit', ''),
            'build_date' => trim((string) $this->config->get('app.release.build_date', ''))
                ?: (string) ($version['build_date'] ?? ''),
            'schema_version' => $schemaVersion,
            'external_writes_enabled' => (bool) $this->config->get('app.mkauth.write_enabled', false),
            'notification_dry_run' => (bool) $this->config->get('email.dry_run', true)
                && (bool) $this->config->get('evotrix.dry_run', true),
            'ticket_dry_run' => (bool) $this->config->get('contracts.mkauth_ticket.dry_run', true),
        ]);
    }

    public function notFound(Request $request): Response
    {
        $html = $this->view->render('errors/404', [
            'pageTitle' => 'Pagina nao encontrada',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveViewUser(),
            'layoutMode' => 'guest',
        ]);

        return Response::html($html, 404);
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

    /**
     * "Fluxo do dia": últimas movimentações dos cadastros em storage/installations (antes na Home).
     */
    private function recentInstallationActivities(): array
    {
        $records = [];
        foreach (glob(dirname(__DIR__, 2) . '/storage/installations/*.json') ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                $records[] = $decoded;
            }
        }

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
        $access = $this->localRepository->accessProfileForUser($this->resolveUser());

        return $access['is_manager'] || $access['is_admin'];
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

    /**
     * Junta audit_logs, notification_logs e os dois arquivos técnicos numa lista
     * única, já categorizada, da mais recente para a mais antiga.
     *
     * @return array<int, array{category: string, failure: bool, message: string, detail: string, time: string, source: string}>
     */
    private function logEntries(): array
    {
        $entries = [];

        try {
            $auditLogs = $this->localRepository->recentAuditLogs(300);
        } catch (\Throwable) {
            $auditLogs = [];
        }
        foreach ($auditLogs as $row) {
            $action = (string) ($row['action'] ?? '');
            $actor = trim((string) ($row['actor_login'] ?? '')) ?: 'sistema';
            $context = json_decode((string) ($row['context_json'] ?? '{}'), true);
            $login = is_array($context) ? trim((string) ($context['login'] ?? '')) : '';
            $entries[] = [
                'category' => AuditEventCatalog::category($action, (string) ($row['entity_type'] ?? '')),
                'failure' => AuditEventCatalog::isFailure($action),
                'message' => AuditEventCatalog::label($action),
                'detail' => 'Por ' . $actor . ($login !== '' ? ' · cliente ' . $login : '') . ' · ' . (string) ($row['ip_address'] ?? ''),
                'time' => (string) ($row['created_at'] ?? ''),
                'source' => 'audit_logs',
            ];
        }

        try {
            $notifications = $this->localRepository->recentNotificationLogs(150);
        } catch (\Throwable) {
            $notifications = [];
        }
        foreach ($notifications as $row) {
            $channel = (string) ($row['channel'] ?? '') === 'email' ? 'E-mail' : 'WhatsApp';
            $status = (string) ($row['status'] ?? '');
            $entries[] = [
                'category' => AuditEventCatalog::CONTRACT,
                'failure' => $status === 'erro',
                'message' => match ($status) {
                    'enviado' => $channel . ' enviado ao cliente.',
                    'erro' => 'Falha no envio de ' . $channel . ' ao cliente.',
                    default => $channel . ' simulado (modo de teste, nada foi enviado).',
                },
                'detail' => 'Destino ' . (string) ($row['recipient'] ?? '-') . ((int) ($row['contract_id'] ?? 0) > 0 ? ' · contrato #' . (int) $row['contract_id'] : ''),
                'time' => (string) ($row['created_at'] ?? ''),
                'source' => 'notification_logs',
            ];
        }

        $logsDir = (string) $this->config->get('paths.logs', dirname(__DIR__, 2) . '/logs');
        foreach ($this->tailFile($logsDir . '/mkauth-write-guard.log', 30) as $line) {
            $entries[] = [
                'category' => AuditEventCatalog::SECURITY,
                'failure' => true,
                'message' => 'Gravação no MkAuth bloqueada pela trava de ambiente de teste.',
                'detail' => $line,
                'time' => preg_match('/^\[([^\]]+)\]/', $line, $m) === 1 ? $this->normalizeLogTime($m[1]) : '',
                'source' => 'mkauth-write-guard.log',
            ];
        }
        foreach ($this->tailFile($logsDir . '/client-plan-confirmation.log', 30) as $line) {
            $data = json_decode($line, true);
            if (!is_array($data)) {
                continue;
            }
            $event = (string) ($data['event'] ?? '');
            $entries[] = [
                'category' => AuditEventCatalog::PROVISIONING,
                'failure' => AuditEventCatalog::isFailure($event),
                'message' => 'Confirmação de plano no cadastro: ' . ($event !== '' ? $event : 'evento'),
                'detail' => 'Diário técnico · cliente ' . (string) ($data['login'] ?? '-'),
                'time' => $this->normalizeLogTime((string) ($data['at'] ?? '')),
                'source' => 'client-plan-confirmation.log',
            ];
        }

        usort($entries, static fn (array $a, array $b): int => strcmp($b['time'], $a['time']));

        return $entries;
    }

    /** @return array<int, string> */
    private function tailFile(string $path, int $lines): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }
        $content = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return is_array($content) ? array_slice($content, -$lines) : [];
    }

    private function normalizeLogTime(string $value): string
    {
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return $value;
        }
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
}
