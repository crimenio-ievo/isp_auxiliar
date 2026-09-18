<?php

declare(strict_types=1);

use App\Core\Request;
use App\Core\Url;

require dirname(__DIR__) . '/backend/bootstrap/app.php';

$application = bootstrapApplication();

// Cada release pode usar cookie e armazenamento próprios. A configuração
// precisa ser aplicada antes de abrir a sessão.
if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionName = (string) $application->config()->get('app.session.cookie_name', 'isp_auxiliar_session');
    $sessionPath = trim((string) $application->config()->get('app.session.save_path', ''));
    session_name($sessionName);
    if ($sessionPath !== '') {
        $absoluteSessionPath = str_starts_with($sessionPath, '/')
            ? $sessionPath
            : dirname(__DIR__) . '/' . ltrim($sessionPath, '/');
        if (!is_dir($absoluteSessionPath)) {
            throw new RuntimeException('Diretório de sessão não preparado: ' . $absoluteSessionPath);
        }
        session_save_path($absoluteSessionPath);
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (bool) $application->config()->get('app.session.cookie_secure', true),
        'httponly' => true,
        'samesite' => (string) $application->config()->get('app.session.cookie_samesite', 'Lax'),
    ]);
    session_start();
}

$request = Request::fromGlobals();

Url::setBasePath($request->basePath());

$response = $application->handle($request);
$response->send();
