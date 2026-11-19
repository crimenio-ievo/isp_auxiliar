<?php
declare(strict_types=1);
// Renderiza /rede-ctos/ficha ou /rede-vinculos (sem banco, sem sessao real) para o teste jsdom. Uso: php render_vinculos.php ficha|vinculos [edit|read]
require dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';

use App\Core\Url;
use App\Core\View;

Url::setBasePath('');
$edit = ($argv[2] ?? 'edit') === 'edit';
$data = ['pageTitle' => 'T', 'currentPath' => '/x', 'basePath' => '', 'appName' => 'ISP Teste', 'csrf' => 'TOKEN-TESTE', 'canEdit' => $edit, 'ctoId' => 33,
    'user' => ['name' => 'U', 'role' => $edit ? 'manager' : 'technician', 'access' => $edit ? ['is_manager' => true] : ['can_search_clients' => true]]];
echo (new View(dirname(__DIR__, 2) . '/backend/Views'))->render(($argv[1] ?? 'ficha') === 'ficha' ? 'net/cto_ficha' : 'net/vinculos', $data);
