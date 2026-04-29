<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'name' => Env::get('APP_NAME', 'ISP Auxiliar'),
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => Env::bool('APP_DEBUG', false),
    'url' => Env::get('APP_URL', 'http://localhost'),
    'provider_key' => Env::get('APP_PROVIDER_KEY', 'default'),
    'timezone' => Env::get('APP_TIMEZONE', 'UTC'),
    'mkauth' => [
        'base_url' => Env::get('MKAUTH_BASE_URL', ''),
        'api_token' => Env::get('MKAUTH_API_TOKEN', ''),
        'client_id' => Env::get('MKAUTH_CLIENT_ID', ''),
        'client_secret' => Env::get('MKAUTH_CLIENT_SECRET', ''),
    ],
];
