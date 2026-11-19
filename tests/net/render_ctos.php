<?php
declare(strict_types=1);
// Renderiza /rede-ctos (sem banco, sem sessao real) para o teste jsdom. Uso: php render_ctos.php [edit|read]
require dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';

use App\Core\Url;
use App\Core\View;

Url::setBasePath('');
$edit = ($argv[1] ?? 'edit') === 'edit';
echo (new View(dirname(__DIR__, 2) . '/backend/Views'))->render('net/ctos', [
    'pageTitle' => 'CTOs', 'currentPath' => '/rede-ctos', 'basePath' => '', 'appName' => 'ISP Teste', 'csrf' => 'TOKEN-TESTE', 'canEdit' => $edit, 'focusCto' => 2,
    'user' => ['name' => 'U', 'role' => $edit ? 'manager' : 'technician', 'access' => $edit ? ['is_manager' => true] : ['can_search_clients' => true]],
]);
