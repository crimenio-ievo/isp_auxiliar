<?php

declare(strict_types=1);

use App\Core\Request;
use App\Core\Url;

require dirname(__DIR__) . '/backend/bootstrap/app.php';

// A sessao sera usada mais a frente para autenticacao e contexto do usuario.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$application = bootstrapApplication();
$request = Request::fromGlobals();

Url::setBasePath($request->basePath());

$response = $application->handle($request);
$response->send();
