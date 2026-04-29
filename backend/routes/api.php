<?php

declare(strict_types=1);

use App\Core\Router;
use App\Http\Controllers\HealthController;

return static function (Router $router): void {
    $router->get('/api/health', [HealthController::class, 'show'], 'api.health');
};
