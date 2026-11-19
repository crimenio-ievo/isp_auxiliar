<?php
declare(strict_types=1);
// Renderiza /rede-equipamentos (sem banco, sem sessao real) para o teste jsdom. Imprime o HTML.
require dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';

use App\Core\Url;
use App\Core\View;

Url::setBasePath('');
echo (new View(dirname(__DIR__, 2) . '/backend/Views'))->render('net/devices', [
    'pageTitle' => 'Equipamentos de rede', 'currentPath' => '/rede-equipamentos', 'basePath' => '', 'appName' => 'ISP Teste', 'csrf' => 'TOKEN-TESTE',
    'user' => ['name' => 'Gestor', 'role' => 'manager', 'access' => ['is_manager' => true]],
]);
