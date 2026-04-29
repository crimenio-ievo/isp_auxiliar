<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Infrastructure\Local\LocalRepository;

/**
 * Configuracoes administrativas do provedor.
 *
 * Gestores usam esta tela para informar os dados de conexao com o MkAuth sem
 * editar arquivos no servidor. Tecnicos continuam usando apenas o fluxo de
 * cadastro e validacao de instalacao.
 */
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
        if (!$this->canManageSettings()) {
            Flash::set('error', 'Apenas gestores podem acessar as configurações do provedor.');
            return Response::redirect('/dashboard');
        }

        try {
            $provider = $this->localRepository->currentProvider();
            $settings = $this->localRepository->providerSettings();
            $databaseAvailable = $provider !== null;
        } catch (\Throwable $exception) {
            $provider = null;
            $settings = [];
            $databaseAvailable = false;
        }

        $html = $this->view->render('settings/index', [
            'pageTitle' => 'Configuracoes',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveUser(),
            'flash' => Flash::get(),
            'provider' => $provider,
            'settings' => $settings,
            'databaseAvailable' => $databaseAvailable,
        ]);

        return Response::html($html);
    }

    public function save(Request $request): Response
    {
        if (!$this->canManageSettings()) {
            Flash::set('error', 'Apenas gestores podem alterar as configurações do provedor.');
            return Response::redirect('/dashboard');
        }

        try {
            $this->localRepository->saveProviderProfile([
                'name' => (string) $request->input('provider_name', ''),
                'document' => (string) $request->input('provider_document', ''),
                'domain' => (string) $request->input('provider_domain', ''),
                'base_path' => (string) $request->input('provider_base_path', ''),
            ]);

            $settings = [
                'mkauth_base_url' => (string) $request->input('mkauth_base_url', ''),
                'mkauth_db_host' => (string) $request->input('mkauth_db_host', ''),
                'mkauth_db_port' => (string) $request->input('mkauth_db_port', '3306'),
                'mkauth_db_name' => (string) $request->input('mkauth_db_name', 'mkradius'),
                'mkauth_db_user' => (string) $request->input('mkauth_db_user', ''),
                'mkauth_db_charset' => (string) $request->input('mkauth_db_charset', 'utf8mb4'),
                'mkauth_db_hash_algos' => (string) $request->input('mkauth_db_hash_algos', 'sha256,sha1'),
                'mkauth_client_id' => (string) $request->input('mkauth_client_id', ''),
            ];

            foreach (['mkauth_api_token', 'mkauth_client_secret', 'mkauth_db_password'] as $secretKey) {
                $secretValue = trim((string) $request->input($secretKey, ''));
                if ($secretValue !== '') {
                    $settings[$secretKey] = $secretValue;
                }
            }

            $this->localRepository->saveProviderSettings($settings);
            $user = $this->resolveUser();
            $this->localRepository->log(
                isset($user['id']) ? (int) $user['id'] : null,
                (string) ($user['login'] ?? ''),
                'provider_settings.updated',
                'provider',
                isset($user['provider_id']) ? (int) $user['provider_id'] : null,
                ['fields' => array_keys($settings)],
                (string) $request->server('REMOTE_ADDR', ''),
                (string) $request->header('User-Agent', '')
            );

            Flash::set('success', 'Configurações salvas. As próximas requisições já usarão os dados informados.');
        } catch (\Throwable $exception) {
            Flash::set('error', 'Nao foi possivel salvar as configurações: ' . $exception->getMessage());
        }

        return Response::redirect('/configuracoes');
    }

    private function canManageSettings(): bool
    {
        $user = $this->resolveUser();
        $role = strtolower((string) ($user['role'] ?? ''));

        return in_array($role, ['platform_admin', 'manager', 'admin', 'administrador'], true);
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
}
