<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'enabled' => Env::bool('EVOTRIX_ENABLED', false),
    'dry_run' => Env::bool('EVOTRIX_DRY_RUN', true),
    'base_url' => Env::get('EVOTRIX_BASE_URL', ''),
    'token' => Env::get('EVOTRIX_TOKEN', ''),
    'sender' => Env::get('EVOTRIX_SENDER', 'ISP Auxiliar'),
];
