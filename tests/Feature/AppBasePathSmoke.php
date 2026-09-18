<?php

declare(strict_types=1);

use App\Core\Request;

require dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }

    $checks++;
};

$originalServer = $_SERVER;
$originalEnv = $_ENV;

try {
    $requestFromGlobals = static function (string $scriptName, string $uri, ?string $basePath): Request {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $uri,
            'SCRIPT_NAME' => $scriptName,
        ];
        $_ENV = [];

        if ($basePath !== null) {
            $_ENV['APP_BASE_PATH'] = $basePath;
        }

        return Request::fromGlobals();
    };

    $betaHealth = $requestFromGlobals(
        '/isp_auxiliar_beta_current/public/index.php',
        '/isp_auxiliar/public/api/health',
        '/isp_auxiliar/public'
    );
    $assert($betaHealth->basePath() === '/isp_auxiliar/public', 'Base explícita Beta não foi aplicada.');
    $assert($betaHealth->path() === '/api/health', 'Rota health Beta não foi normalizada.');

    $betaLogin = $requestFromGlobals(
        '/isp_auxiliar_beta_current/public/index.php',
        '/isp_auxiliar/public/login',
        '/isp_auxiliar/public'
    );
    $assert($betaLogin->path() === '/login', 'Rota login Beta não foi normalizada.');
    $assert($betaLogin->url('/logout') === '/isp_auxiliar/public/logout', 'Link interno não respeita a base explícita.');

    $legacy = $requestFromGlobals('/isp_auxiliar/public/index.php', '/isp_auxiliar/public/login', null);
    $assert($legacy->basePath() === '/isp_auxiliar/public', 'Autodetecção legada foi alterada.');
    $assert($legacy->path() === '/login', 'Rota legada foi alterada.');

    $root = $requestFromGlobals('/index.php', '/api/health', '');
    $assert($root->basePath() === '', 'Base vazia deve representar a raiz.');
    $assert($root->path() === '/api/health', 'Rota na raiz foi alterada.');

    $rootSlash = $requestFromGlobals('/index.php', '/login', '/');
    $assert($rootSlash->basePath() === '', 'Barra isolada deve representar a raiz.');

    $normalized = $requestFromGlobals('/index.php', '/isp_auxiliar/public/login', '/isp_auxiliar//public/');
    $assert($normalized->basePath() === '/isp_auxiliar/public', 'Barras duplicadas não foram normalizadas.');

    foreach (['https://host/isp_auxiliar/public', '//host/isp_auxiliar/public', '/isp_auxiliar/../public', '/isp_auxiliar/public?x=1', '/isp_auxiliar/public#fragment'] as $invalid) {
        try {
            $requestFromGlobals('/index.php', '/login', $invalid);
            $assert(false, 'APP_BASE_PATH inválido foi aceito.');
        } catch (InvalidArgumentException) {
            $checks++;
        }
    }
} finally {
    $_SERVER = $originalServer;
    $_ENV = $originalEnv;
}

echo "AppBasePathSmoke: {$checks} checks passed; sem banco ou rede.\n";
