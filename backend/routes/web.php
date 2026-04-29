<?php

declare(strict_types=1);

use App\Core\Router;
use App\Http\Controllers\HomeController;

return static function (Router $router): void {
    $router->get('/', [HomeController::class, 'index'], 'home');
};
