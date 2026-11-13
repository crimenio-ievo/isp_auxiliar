<?php
declare(strict_types=1);
// Renderiza a pagina /rede-fibra (sem banco, sem sessao real) para o teste jsdom. Imprime o HTML.
require dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';

use App\Core\Url;
use App\Core\View;

Url::setBasePath('');
echo (new View(dirname(__DIR__, 2) . '/backend/Views'))->render('net/fiber', [
    'pageTitle' => 'Rede de fibra', 'currentPath' => '/rede-fibra', 'basePath' => '', 'appName' => 'ISP Teste',
    'user' => ['name' => 'Gestor', 'role' => 'manager', 'access' => ['is_manager' => true]],
]);
