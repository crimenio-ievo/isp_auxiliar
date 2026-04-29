<?php

declare(strict_types=1);

/**
 * Router do servidor embutido do PHP.
 *
 * Se o arquivo requisitado existir dentro de `public/`, o proprio servidor
 * entrega o asset. Caso contrario, a requisicao segue para o front controller.
 */
$requestedPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$publicFile = __DIR__ . ($requestedPath === '/' ? '' : $requestedPath);

if ($requestedPath !== '/' && is_file($publicFile)) {
    return false;
}

require __DIR__ . '/index.php';
