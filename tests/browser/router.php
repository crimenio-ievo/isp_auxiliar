<?php
// Roteador do servidor embutido (só para o teste de navegador): arquivos estáticos de public/, o resto vai ao front controller.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . '/../../public' . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/../../public/index.php';
