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
 * Controla a entrada basica do sistema.
 *
 * A autenticacao usa os operadores cadastrados no MkAuth quando o banco remoto
 * esta configurado.
 */
final class AuthController
{
    public function __construct(
        private View $view,
        private Config $config,
        private MkAuthDatabase $mkauthDatabase,
        private LocalRepository $localRepository
    ) {
    }

    public function home(Request $request): Response
    {
        if (!empty($_SESSION['user'])) {
            return Response::redirect('/dashboard');
        }

        return Response::redirect('/login');
    }

    public function showLogin(Request $request): Response
    {
        $html = $this->view->render('auth/login', [
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'pageTitle' => 'Entrar no sistema',
            'layoutMode' => 'guest',
            'basePath' => $request->basePath(),
            'mkauthConfigured' => $this->mkauthDatabase->isConfigured(),
            'errorMessage' => match ($request->query('error')) {
                '1' => 'Preencha usuario e senha para continuar.',
                'user' => 'Usuario nao encontrado no MkAuth.',
                'password' => 'Senha invalida para este usuario.',
                'service' => 'Nao foi possivel consultar o MkAuth agora.',
                default => null,
            },
        ]);

        return Response::html($html);
    }

    public function login(Request $request): Response
    {
        $username = trim((string) $request->input('username', ''));
        $password = trim((string) $request->input('password', ''));

        if ($username === '' || $password === '') {
            return Response::redirect('/login?error=1');
        }

        try {
            $localUser = $this->localRepository->authenticateLocalUser($username, $password);
        } catch (\Throwable) {
            $localUser = null;
        }

        if ($localUser !== null) {
            $localRole = (string) ($localUser['role'] ?? 'manager');
            $_SESSION['user'] = [
                'id' => (int) $localUser['id'],
                'provider_id' => $localUser['provider_id'] ?? null,
                'name' => (string) ($localUser['name'] ?? ucfirst($username)),
                'login' => (string) ($localUser['login'] ?? $username),
                'email' => (string) ($localUser['email'] ?? ''),
                'role' => $localRole,
                'source' => 'local',
            ];

            $this->localRepository->log(
                (int) $localUser['id'],
                (string) ($localUser['login'] ?? $username),
                'auth.login',
                'app_user',
                (int) $localUser['id'],
                ['source' => 'local'],
                (string) $request->server('REMOTE_ADDR', ''),
                (string) $request->header('User-Agent', '')
            );

            return Response::redirect('/dashboard');
        }

        if ($this->mkauthDatabase->isConfigured()) {
            try {
                $remoteUser = $this->mkauthDatabase->authenticateUser($username, $password);
            } catch (\Throwable $exception) {
                return Response::redirect('/login?error=service');
            }

            if ($remoteUser === null) {
                $existingUser = $this->mkauthDatabase->findAccessUserByLogin($username);

                if ($existingUser === null) {
                    return Response::redirect('/login?error=user');
                }

                return Response::redirect('/login?error=password');
            }

            $managerLogin = strtolower(trim($this->localRepository->providerSetting('mkauth_manager_login', '')));
            $remoteLogin = strtolower(trim((string) ($remoteUser['login'] ?? $username)));
            $isManager = $managerLogin !== '' && $remoteLogin !== '' && $managerLogin === $remoteLogin;

            $_SESSION['user'] = [
                'name' => (string) ($remoteUser['nome'] ?? ucfirst($username)),
                'login' => (string) ($remoteUser['login'] ?? $username),
                'role' => $isManager ? 'manager' : 'technician',
                'mkauth_level' => (string) ($remoteUser['nivel'] ?? 'Operador'),
                'source' => 'mkauth',
                'can_manage' => $isManager,
                'uuid' => (string) ($remoteUser['uuid_acesso'] ?? $remoteUser['uuid'] ?? ''),
            ];

            try {
                $this->localRepository->log(
                    null,
                    (string) ($remoteUser['login'] ?? $username),
                    'auth.login',
                    'mkauth_user',
                    null,
                    ['source' => 'mkauth'],
                    (string) $request->server('REMOTE_ADDR', ''),
                    (string) $request->header('User-Agent', '')
                );
            } catch (\Throwable) {
                // O login operacional nao deve depender do banco complementar.
            }

            return Response::redirect('/dashboard');
        }

        $_SESSION['user'] = [
            'name' => ucfirst($username),
            'login' => $username,
            'role' => 'Administrador',
            'source' => 'local-demo',
        ];

        return Response::redirect('/dashboard');
    }

    public function logout(Request $request): Response
    {
        unset($_SESSION['user']);

        return Response::redirect('/login');
    }

    public function validateUser(Request $request): Response
    {
        $login = trim((string) $request->query('login', $request->query('value', '')));

        if ($login === '') {
            return Response::json([
                'status' => 'error',
                'message' => 'Informe o usuario para validacao.',
            ], 422);
        }

        if (!$this->mkauthDatabase->isConfigured()) {
            try {
                $localExists = $this->localRepository->localUserExists($login);
            } catch (\Throwable) {
                $localExists = false;
            }

            return Response::json([
                'status' => 'success',
                'exists' => $localExists,
                'message' => $localExists ? 'Gestor encontrado no ISP Auxiliar.' : 'Integração MkAuth não configurada neste ambiente.',
            ]);
        }

        try {
            if ($this->localRepository->localUserExists($login)) {
                return Response::json([
                    'status' => 'success',
                    'exists' => true,
                    'message' => 'Gestor encontrado no ISP Auxiliar.',
                ]);
            }

            $remoteUser = $this->mkauthDatabase->findAccessUserByLogin($login);
        } catch (\Throwable $exception) {
            return Response::json([
                'status' => 'error',
                'message' => 'Nao foi possivel consultar o MkAuth agora.',
            ], 503);
        }

        $managerLogin = strtolower(trim($this->localRepository->providerSetting('mkauth_manager_login', '')));
        $remoteLogin = strtolower(trim((string) ($remoteUser['login'] ?? $login)));

        return Response::json([
            'status' => 'success',
            'exists' => $remoteUser !== null,
            'message' => $remoteUser !== null
                ? ($managerLogin !== '' && $managerLogin === $remoteLogin ? 'Gestor encontrado no MkAuth.' : 'Usuario encontrado no MkAuth.')
                : 'Usuario nao encontrado no MkAuth.',
        ]);
    }
}
