<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Infrastructure\Contracts\NotificationLogRepository;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\MkAuth\MkAuthTicketService;
use App\Infrastructure\Notifications\EmailService;
use App\Infrastructure\Notifications\EvotrixService;

final class SettingsController
{
    public function __construct(
        private View $view,
        private Config $config,
        private LocalRepository $localRepository
    ) {
    }

    public function index(Request $request): Response
    {
        $access = $this->resolveAccessProfile();
        if (!$access['can_manage_settings']) {
            Flash::set('error', 'Apenas gestores ou administradores podem acessar as configurações.');
            return Response::redirect('/dashboard');
        }

        $currentTab = $this->normalizeTab((string) $request->query('tab', 'geral'));

        try {
            $provider = $this->localRepository->currentProvider();
            $providerSettings = $this->localRepository->providerSettings();
            $databaseAvailable = $provider !== null;
        } catch (\Throwable $exception) {
            $provider = null;
            $providerSettings = [];
            $databaseAvailable = false;
        }

        if ($request->method() === 'POST') {
            try {
                $section = $this->normalizeTab((string) $request->input('settings_section', $currentTab));
                $this->saveSection($section, $request, $providerSettings);
                $testAction = trim((string) $request->input('test_action', ''));
                if ($testAction !== '') {
                    $this->runConnectivityTest($testAction, $request, $providerSettings);
                } else {
                    Flash::set('success', 'Configurações atualizadas com sucesso.');
                }
            } catch (\Throwable $exception) {
                Flash::set('error', 'Nao foi possivel salvar as configurações: ' . $exception->getMessage());
            }

            return Response::redirect('/configuracoes?tab=' . rawurlencode($currentTab));
        }

        $storedConfig = $this->readLocalPanelConfig();
        $moduleConfig = [
            'commercial' => (array) $this->config->get('contracts.commercial', []),
            'email' => (array) $this->config->get('email', []),
            'evotrix' => (array) $this->config->get('evotrix', []),
            'mkauth_ticket' => (array) $this->config->get('contracts.mkauth_ticket', []),
            'system' => (array) $this->config->get('contracts.system', []),
        ];

        $html = $this->view->render('settings/index', [
            'pageTitle' => 'Configuracoes',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->buildViewUser($access),
            'flash' => Flash::get(),
            'provider' => $provider,
            'providerSettings' => $providerSettings,
            'databaseAvailable' => $databaseAvailable,
            'currentTab' => $currentTab,
            'moduleConfig' => $moduleConfig,
            'storedConfig' => $storedConfig,
            'permissionsConfig' => [
                'manager_logins' => $this->stringifySettingList($providerSettings, 'mkauth_manager_logins', (string) ($providerSettings['mkauth_manager_login'] ?? '')),
                'contract_access_logins' => $this->stringifySettingList($providerSettings, 'contract_access_logins'),
                'financial_access_logins' => $this->stringifySettingList($providerSettings, 'financial_access_logins'),
                'admin_access_logins' => $this->stringifySettingList($providerSettings, 'admin_access_logins'),
                'settings_access_logins' => $this->stringifySettingList($providerSettings, 'settings_access_logins'),
                'rows' => $this->localRepository->permissionMap(),
            ],
            'configSource' => [
                'panel_file' => $this->localPanelConfigPath(),
                'panel_priority' => 'Painel local > backend/config/*.php > fallback interno',
            ],
        ]);

        return Response::html($html);
    }

    public function save(Request $request): Response
    {
        return $this->index($request);
    }

    private function saveSection(string $section, Request $request, array $providerSettings): void
    {
        match ($section) {
            'geral' => $this->saveGeneralSettings($request),
            'mkauth' => $this->saveMkAuthSettings($request, $providerSettings),
            'contratos' => $this->saveLocalPanelConfig([
                'commercial' => $this->normalizeCommercialSettingsFromRequest($request),
            ]),
            'email' => $this->saveLocalPanelConfig([
                'email' => $this->normalizeEmailSettingsFromRequest($request),
            ]),
            'evotrix' => $this->saveLocalPanelConfig([
                'evotrix' => $this->normalizeEvotrixSettingsFromRequest($request),
            ]),
            'sistema' => $this->saveSystemSettings($request),
            default => null,
        };
    }

    private function saveGeneralSettings(Request $request): void
    {
        $this->localRepository->saveProviderProfile([
            'name' => (string) $request->input('provider_name', ''),
            'document' => (string) $request->input('provider_document', ''),
            'domain' => (string) $request->input('provider_domain', ''),
            'base_path' => (string) $request->input('provider_base_path', ''),
        ]);
    }

    private function saveMkAuthSettings(Request $request, array $providerSettings): void
    {
        $settings = [
            'mkauth_base_url' => trim((string) $request->input('mkauth_base_url', (string) ($providerSettings['mkauth_base_url'] ?? ''))),
            'mkauth_db_host' => trim((string) $request->input('mkauth_db_host', (string) ($providerSettings['mkauth_db_host'] ?? ''))),
            'mkauth_db_port' => trim((string) $request->input('mkauth_db_port', (string) ($providerSettings['mkauth_db_port'] ?? '3306'))),
            'mkauth_db_name' => trim((string) $request->input('mkauth_db_name', (string) ($providerSettings['mkauth_db_name'] ?? 'mkradius'))),
            'mkauth_db_user' => trim((string) $request->input('mkauth_db_user', (string) ($providerSettings['mkauth_db_user'] ?? ''))),
            'mkauth_db_charset' => trim((string) $request->input('mkauth_db_charset', (string) ($providerSettings['mkauth_db_charset'] ?? 'utf8mb4'))),
            'mkauth_db_hash_algos' => trim((string) $request->input('mkauth_db_hash_algos', (string) ($providerSettings['mkauth_db_hash_algos'] ?? 'sha256,sha1'))),
            'mkauth_client_id' => trim((string) $request->input('mkauth_client_id', (string) ($providerSettings['mkauth_client_id'] ?? ''))),
        ];

        foreach (['mkauth_api_token', 'mkauth_client_secret', 'mkauth_db_password'] as $secretKey) {
            $incoming = trim((string) $request->input($secretKey, ''));
            if ($incoming !== '') {
                $settings[$secretKey] = $incoming;
            } elseif (isset($providerSettings[$secretKey]) && trim((string) $providerSettings[$secretKey]) !== '') {
                $settings[$secretKey] = (string) $providerSettings[$secretKey];
            }
        }

        $this->localRepository->saveProviderSettings($settings);

        $this->saveLocalPanelConfig([
            'mkauth_ticket' => $this->normalizeMkAuthTicketSettingsFromRequest($request),
        ]);
    }

    private function saveSystemSettings(Request $request): void
    {
        $permissionRows = $this->normalizePermissionRowsFromRequest($request);
        $managerLogins = [];
        $contractLogins = [];
        $financialLogins = [];
        $settingsLogins = [];
        $adminLogins = [];

        foreach ($permissionRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $login = strtolower(trim((string) ($row['login'] ?? '')));
            if ($login === '') {
                continue;
            }

            if (!empty($row['gestor_admin'])) {
                $managerLogins[$login] = $login;
                $adminLogins[$login] = $login;
            }

            if (!empty($row['contratos']) || !empty($row['gestor_admin'])) {
                $contractLogins[$login] = $login;
            }

            if (!empty($row['financeiro']) || !empty($row['gestor_admin'])) {
                $financialLogins[$login] = $login;
            }

            if (!empty($row['configuracoes']) || !empty($row['gestor_admin'])) {
                $settingsLogins[$login] = $login;
            }
        }

        $this->localRepository->saveProviderSettings([
            'mkauth_manager_logins' => implode("\n", array_values($managerLogins)),
            'contract_access_logins' => implode("\n", array_values($contractLogins)),
            'financial_access_logins' => implode("\n", array_values($financialLogins)),
            'admin_access_logins' => implode("\n", array_values($adminLogins)),
            'settings_access_logins' => implode("\n", array_values($settingsLogins)),
            'mkauth_permission_map' => json_encode($permissionRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
        ]);
    }

    private function normalizeCommercialSettingsFromRequest(Request $request): array
    {
        $commercial = (array) $this->config->get('contracts.commercial', []);

        return [
            'valor_adesao_padrao' => $this->normalizeMoney((string) $request->input('valor_adesao_padrao', (string) ($commercial['valor_adesao_padrao'] ?? 0))),
            'valor_adesao_promocional' => $this->normalizeMoney((string) $request->input('valor_adesao_promocional', (string) ($commercial['valor_adesao_promocional'] ?? 0))),
            'percentual_desconto_promocional' => $this->normalizeMoney((string) $request->input('percentual_desconto_promocional', (string) ($commercial['percentual_desconto_promocional'] ?? 0))),
            'parcelas_maximas_adesao' => max(1, (int) $request->input('parcelas_maximas_adesao', (string) ($commercial['parcelas_maximas_adesao'] ?? 3))),
            'fidelidade_meses_padrao' => max(1, (int) $request->input('fidelidade_meses_padrao', (string) ($commercial['fidelidade_meses_padrao'] ?? 12))),
            'validade_link_aceite_horas' => max(1, (int) $request->input('validade_link_aceite_horas', (string) ($commercial['validade_link_aceite_horas'] ?? 48))),
            'exigir_validacao_cpf_aceite' => $this->normalizeBoolean((string) $request->input('exigir_validacao_cpf_aceite', '1')),
            'quantidade_digitos_validacao_cpf' => max(1, (int) $request->input('quantidade_digitos_validacao_cpf', (string) ($commercial['quantidade_digitos_validacao_cpf'] ?? 3))),
            'multa_padrao' => $this->normalizeMoney((string) $request->input('multa_padrao', (string) ($commercial['multa_padrao'] ?? 0))),
        ];
    }

    private function normalizeEmailSettingsFromRequest(Request $request): array
    {
        $current = $this->readLocalPanelConfig();
        $existing = is_array($current['email'] ?? null) ? $current['email'] : [];
        $config = (array) $this->config->get('email', []);
        $storedPassword = trim((string) ($existing['smtp_password'] ?? $config['smtp_password'] ?? ''));
        $incomingPassword = trim((string) $request->input('smtp_password', ''));

        return [
            'enabled' => $this->normalizeBoolean((string) $request->input('email_enabled', !empty($config['enabled']) ? '1' : '0')),
            'dry_run' => $this->normalizeBoolean((string) $request->input('email_dry_run', !empty($config['dry_run']) ? '1' : '0')),
            'allow_only_test_email' => $this->normalizeBoolean((string) $request->input('email_allow_only_test_email', !empty($config['allow_only_test_email']) ? '1' : '0')),
            'test_to' => trim((string) $request->input('email_test_to', (string) ($config['test_to'] ?? ''))),
            'smtp_host' => trim((string) $request->input('smtp_host', (string) ($config['smtp_host'] ?? ''))),
            'smtp_port' => max(1, (int) $request->input('smtp_port', (string) ($config['smtp_port'] ?? 587))),
            'smtp_username' => trim((string) $request->input('smtp_username', (string) ($config['smtp_username'] ?? ''))),
            'smtp_password' => $incomingPassword !== '' ? $incomingPassword : $storedPassword,
            'smtp_encryption' => in_array((string) $request->input('smtp_encryption', (string) ($config['smtp_encryption'] ?? 'tls')), ['tls', 'ssl', 'none'], true)
                ? (string) $request->input('smtp_encryption', (string) ($config['smtp_encryption'] ?? 'tls'))
                : 'tls',
            'smtp_from' => trim((string) $request->input('smtp_from', (string) ($config['smtp_from'] ?? ''))),
            'smtp_from_name' => trim((string) $request->input('smtp_from_name', (string) ($config['smtp_from_name'] ?? 'ISP Auxiliar'))),
        ];
    }

    private function normalizeEvotrixSettingsFromRequest(Request $request): array
    {
        $current = $this->readLocalPanelConfig();
        $existing = is_array($current['evotrix'] ?? null) ? $current['evotrix'] : [];
        $config = (array) $this->config->get('evotrix', []);
        $storedToken = trim((string) ($existing['token'] ?? $config['token'] ?? ''));
        $incomingToken = trim((string) $request->input('evotrix_api_key', ''));

        return [
            'enabled' => $this->normalizeBoolean((string) $request->input('evotrix_enabled', !empty($config['enabled']) ? '1' : '0')),
            'dry_run' => $this->normalizeBoolean((string) $request->input('evotrix_dry_run', !empty($config['dry_run']) ? '1' : '0')),
            'base_url' => trim((string) $request->input('evotrix_api_base', (string) ($config['base_url'] ?? ''))),
            'token' => $incomingToken !== '' ? $incomingToken : $storedToken,
            'channel_id' => trim((string) $request->input('evotrix_channel_id', (string) ($config['channel_id'] ?? ''))),
            'allow_only_test_phone' => $this->normalizeBoolean((string) $request->input('evotrix_allow_only_test_phone', !empty($config['allow_only_test_phone']) ? '1' : '0')),
            'test_phone' => trim((string) $request->input('evotrix_test_phone', (string) ($config['test_phone'] ?? ''))),
            'timeout_seconds' => max(5, (int) $request->input('evotrix_timeout_seconds', (string) ($config['timeout_seconds'] ?? 15))),
            'retry_attempts' => max(0, (int) $request->input('evotrix_retry_attempts', (string) ($config['retry_attempts'] ?? 1))),
        ];
    }

    private function normalizeMkAuthTicketSettingsFromRequest(Request $request): array
    {
        $config = (array) $this->config->get('contracts.mkauth_ticket', []);

        return [
            'enabled' => $this->normalizeBoolean((string) $request->input('mkauth_ticket_enabled', !empty($config['enabled']) ? '1' : '0')),
            'dry_run' => $this->normalizeBoolean((string) $request->input('mkauth_ticket_dry_run', !empty($config['dry_run']) ? '1' : '0')),
            'auto_create' => false,
            'endpoint' => trim((string) $request->input('mkauth_ticket_endpoint', (string) ($config['endpoint'] ?? '/api/chamado/inserir'))),
            'subject' => trim((string) $request->input('mkauth_ticket_subject', (string) ($config['subject'] ?? 'Financeiro - Boleto / Carne'))),
            'priority' => trim((string) $request->input('mkauth_ticket_priority', (string) ($config['priority'] ?? 'normal'))),
            'timeout_seconds' => max(5, (int) $request->input('mkauth_ticket_timeout_seconds', (string) ($config['timeout_seconds'] ?? 15))),
        ];
    }

    private function saveLocalPanelConfig(array $sections): void
    {
        $existing = $this->readLocalPanelConfig();
        $merged = $existing;

        foreach ($sections as $key => $values) {
            $merged[$key] = is_array($values) ? $values : [];
        }

        $merged['saved_at'] = date('Y-m-d H:i:s');
        $merged['saved_by'] = (string) ($this->resolveUser()['login'] ?? '');
        $merged['system'] = array_merge(
            is_array($merged['system'] ?? null) ? $merged['system'] : [],
            [
                'settings_saved_at' => $merged['saved_at'],
                'settings_saved_by' => $merged['saved_by'],
            ]
        );

        $path = $this->localPanelConfigPath();
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Nao foi possivel criar o diretorio local de configuracoes.');
        }

        if (!is_writable($directory)) {
            throw new \RuntimeException('Diretorio local de configuracoes sem permissão de escrita.');
        }

        $written = file_put_contents(
            $path,
            json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''
        );

        if ($written === false) {
            throw new \RuntimeException('Nao foi possivel salvar o arquivo local de configuracoes.');
        }
    }

    private function readLocalPanelConfig(): array
    {
        $path = $this->localPanelConfigPath();
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function localPanelConfigPath(): string
    {
        return dirname(__DIR__, 2) . '/storage/contracts/config.json';
    }

    private function normalizeTab(string $tab): string
    {
        $tab = strtolower(trim($tab));
        return in_array($tab, ['geral', 'mkauth', 'contratos', 'email', 'evotrix', 'sistema'], true)
            ? $tab
            : 'geral';
    }

    private function normalizeBoolean(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'sim', 'on', 'yes'], true);
    }

    private function normalizeMoney(string $value): float
    {
        $value = trim($value);
        if ($value === '') {
            return 0.0;
        }

        $normalized = preg_replace('/[^\d,.\-]/', '', $value) ?? '';
        $lastComma = strrpos($normalized, ',');
        $lastDot = strrpos($normalized, '.');

        if ($lastComma !== false && $lastDot !== false) {
            $decimalSeparator = $lastComma > $lastDot ? ',' : '.';
            $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';
            $normalized = str_replace($thousandsSeparator, '', $normalized);
            if ($decimalSeparator === ',') {
                $normalized = str_replace(',', '.', $normalized);
            }
        } elseif ($lastComma !== false) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        }

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    private function normalizeLoginListString(string $value): string
    {
        $items = preg_split('/[\r\n,;]+/', $value) ?: [];
        $normalized = [];

        foreach ($items as $item) {
            $login = strtolower(trim((string) $item));
            if ($login === '') {
                continue;
            }
            $normalized[$login] = $login;
        }

        return implode("\n", array_values($normalized));
    }

    private function stringifySettingList(array $providerSettings, string $key, string $fallback = ''): string
    {
        $items = $this->localRepository->providerSettingList($key);
        if ($items === [] && trim($fallback) !== '') {
            $items = [strtolower(trim($fallback))];
        }

        return implode("\n", $items);
    }

    private function normalizePermissionRowsFromRequest(Request $request): array
    {
        $logins = $request->input('permission_login', []);
        $gestorAdmin = $request->input('permission_gestor_admin', []);
        $contratos = $request->input('permission_contratos', []);
        $financeiro = $request->input('permission_financeiro', []);
        $tecnico = $request->input('permission_tecnico', []);
        $configuracoes = $request->input('permission_configuracoes', []);

        if (!is_array($logins)) {
            return [];
        }

        $rows = [];

        foreach ($logins as $index => $loginValue) {
            $login = strtolower(trim((string) $loginValue));
            if ($login === '') {
                continue;
            }

            $rows[$login] = [
                'login' => $login,
                'gestor_admin' => isset($gestorAdmin[$index]),
                'contratos' => isset($contratos[$index]),
                'financeiro' => isset($financeiro[$index]),
                'tecnico' => isset($tecnico[$index]),
                'configuracoes' => isset($configuracoes[$index]),
            ];
        }

        return array_values($rows);
    }

    private function resolveAccessProfile(): array
    {
        $user = $this->resolveUser();
        $login = strtolower(trim((string) ($user['login'] ?? '')));
        $role = strtolower(trim((string) ($user['role'] ?? '')));
        $permissionProfile = $this->localRepository->permissionProfile($login);

        $managerLogins = $this->localRepository->providerSettingList('mkauth_manager_logins');
        $singleManager = strtolower(trim($this->localRepository->providerSetting('mkauth_manager_login', '')));
        if ($singleManager !== '') {
            $managerLogins[] = $singleManager;
        }

        $adminLogins = $this->localRepository->providerSettingList('admin_access_logins');
        $settingsLogins = $this->localRepository->providerSettingList('settings_access_logins');
        $contractLogins = $this->localRepository->providerSettingList('contract_access_logins');
        $financialLogins = $this->localRepository->providerSettingList('financial_access_logins');

        $isAdminRole = in_array($role, ['platform_admin', 'admin', 'administrador'], true);
        $isManagerRole = in_array($role, ['manager', 'gestor'], true) || !empty($user['can_manage']);

        $isAdmin = $isAdminRole
            || ($login !== '' && in_array($login, $adminLogins, true))
            || !empty($permissionProfile['gestor_admin']);
        $isManager = $isAdmin || $isManagerRole || ($login !== '' && in_array($login, $managerLogins, true));

        return [
            'is_admin' => $isAdmin,
            'is_manager' => $isManager,
            'can_manage_settings' => $isManager || !empty($permissionProfile['configuracoes']) || ($login !== '' && in_array($login, $settingsLogins, true)),
            'can_access_contracts' => $isManager || !empty($permissionProfile['contratos']) || ($login !== '' && in_array($login, $contractLogins, true)),
            'can_manage_financial' => $isManager || !empty($permissionProfile['financeiro']) || ($login !== '' && in_array($login, $financialLogins, true)),
        ];
    }

    private function runConnectivityTest(string $action, Request $request, array $providerSettings): void
    {
        try {
            if ($action === 'test_smtp') {
                $emailConfig = $this->normalizeEmailSettingsFromRequest($request);
                $database = new Database($this->config);
                $notificationLogs = new NotificationLogRepository($database);
                $service = new EmailService($emailConfig, $notificationLogs);
                $subject = 'Teste SMTP - ISP Auxiliar';
                $message = '<p>Teste manual de SMTP do ISP Auxiliar.</p>';
                $text = "Teste manual de SMTP do ISP Auxiliar.";
                $recipient = trim((string) ($emailConfig['test_to'] ?? ''));
                if ($recipient === '') {
                    throw new \RuntimeException('Defina EMAIL_TEST_TO para executar o teste SMTP.');
                }

                $response = $service->sendAcceptanceEmail($recipient, $subject, $message, $text, null, null);
                $this->logConnectivityTest('settings.smtp.test', [
                    'status' => (string) ($response['status'] ?? 'simulado'),
                    'dry_run' => !empty($response['dry_run']),
                    'recipient' => (string) ($response['recipient'] ?? $recipient),
                    'result' => (string) ($response['message'] ?? ''),
                ], $request);
                Flash::set('success', (($response['dry_run'] ?? true) ? 'Teste SMTP simulado com sucesso.' : 'Teste SMTP enviado com sucesso.') . ' Verifique o histórico de notificações.');
                return;
            }

            if ($action === 'test_evotrix') {
                $evotrixConfig = $this->normalizeEvotrixSettingsFromRequest($request);
                $database = new Database($this->config);
                $notificationLogs = new NotificationLogRepository($database);
                $service = new EvotrixService($evotrixConfig, $notificationLogs);
                $recipient = trim((string) ($evotrixConfig['test_phone'] ?? ''));
                if ($recipient === '') {
                    throw new \RuntimeException('Defina EVOTRIX_TEST_PHONE para executar o teste Evotrix.');
                }

                $response = $service->sendMessage($recipient, 'Teste manual do ISP Auxiliar - Evotrix.');
                $this->logConnectivityTest('settings.evotrix.test', [
                    'status' => (string) ($response['status'] ?? 'simulado'),
                    'dry_run' => !empty($response['dry_run']),
                    'recipient' => (string) ($response['recipient'] ?? $recipient),
                    'http_status' => $response['http_status'] ?? null,
                    'duration_ms' => $response['duration_ms'] ?? 0,
                    'result' => (string) ($response['message'] ?? ''),
                ], $request);
                Flash::set('success', (($response['dry_run'] ?? true) ? 'Teste Evotrix simulado com sucesso.' : 'Teste Evotrix enviado com sucesso.') . ' Verifique o histórico do contrato ou notification_logs.');
                return;
            }

            if ($action === 'test_mkauth') {
                $ticketConfig = $this->normalizeMkAuthTicketSettingsFromRequest($request);
                $service = new MkAuthTicketService(
                    $ticketConfig,
                    trim((string) $request->input('mkauth_base_url', (string) ($providerSettings['mkauth_base_url'] ?? ''))),
                    trim((string) $request->input('mkauth_api_token', (string) ($providerSettings['mkauth_api_token'] ?? ''))),
                    trim((string) $request->input('mkauth_client_id', (string) ($providerSettings['mkauth_client_id'] ?? ''))),
                    trim((string) $request->input('mkauth_client_secret', (string) ($providerSettings['mkauth_client_secret'] ?? '')))
                );

                $response = $service->testConnection();
                $this->logConnectivityTest('settings.mkauth.test', [
                    'status' => (string) ($response['status'] ?? 'simulado'),
                    'dry_run' => !empty($response['dry_run']),
                    'http_status' => $response['http_status'] ?? null,
                    'duration_ms' => $response['duration_ms'] ?? 0,
                    'endpoint' => (string) ($response['endpoint'] ?? ''),
                    'result' => (string) ($response['message'] ?? ''),
                ], $request);
                Flash::set('success', (($response['dry_run'] ?? true) ? 'Teste MkAuth em modo seguro validado.' : 'Conexão com MkAuth validada com sucesso.') . ' Status HTTP: ' . (string) ($response['http_status'] ?? '-'));
            }
        } catch (\Throwable $exception) {
            $this->logConnectivityTest('settings.' . $action . '.failed', [
                'status' => 'erro',
                'result' => $exception->getMessage(),
            ], $request);
            throw $exception;
        }
    }

    private function logConnectivityTest(string $action, array $context, Request $request): void
    {
        $user = $this->resolveUser();
        $this->localRepository->log(
            isset($user['id']) ? (int) $user['id'] : null,
            (string) ($user['login'] ?? ''),
            $action,
            'settings',
            null,
            $context,
            (string) $request->server('REMOTE_ADDR', ''),
            (string) $request->header('User-Agent', '')
        );
    }

    private function buildViewUser(array $access): array
    {
        $user = $this->resolveUser();
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
