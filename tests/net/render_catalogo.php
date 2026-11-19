<?php
declare(strict_types=1);
// Renderiza /rede-credenciais, /rede-modelos ou /rede-inventario (sem banco) para o teste jsdom. Uso: php render_catalogo.php credenciais|modelos|inventario
require dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';

use App\Core\Url;
use App\Core\View;

Url::setBasePath('');
echo (new View(dirname(__DIR__, 2) . '/backend/Views'))->render('net/catalogo', ['pageTitle' => 'T', 'section' => $argv[1] ?? 'modelos', 'currentPath' => '/x', 'basePath' => '', 'appName' => 'ISP Teste', 'csrf' => 'TOKEN-TESTE',
    'user' => ['name' => 'U', 'role' => 'manager', 'access' => ['is_manager' => true]]]);
