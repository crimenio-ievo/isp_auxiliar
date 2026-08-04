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
    'release' => [
        'channel' => in_array(strtolower((string) Env::get('APP_RELEASE_CHANNEL', 'stable')), ['stable', 'beta'], true)
            ? strtolower((string) Env::get('APP_RELEASE_CHANNEL', 'stable'))
            : 'stable',
        'stable_base_url' => Env::get('APP_STABLE_BASE_URL', ''),
        'beta_base_url' => Env::get('APP_BETA_BASE_URL', ''),
        'id' => Env::get('APP_RELEASE_ID', ''),
        'commit' => Env::get('APP_RELEASE_COMMIT', ''),
    ],
    'mkauth' => [
        'base_url' => Env::get('MKAUTH_BASE_URL', ''),
        'write_enabled' => Env::bool('MKAUTH_WRITE_ENABLED', false),
        'api_token' => Env::get('MKAUTH_API_TOKEN', ''),
        'client_id' => Env::get('MKAUTH_CLIENT_ID', ''),
        'client_secret' => Env::get('MKAUTH_CLIENT_SECRET', ''),
    ],
    'client_detail' => [
        'timeout_seconds' => max(8, min(12, (int) Env::get('CLIENT_DETAIL_TIMEOUT_SECONDS', '10'))),
    ],
];
