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
        'build_date' => Env::get('APP_RELEASE_BUILD_DATE', ''),
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
    'session' => [
        'cookie_name' => preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) Env::get('SESSION_COOKIE_NAME', 'isp_auxiliar_session'))
            ? (string) Env::get('SESSION_COOKIE_NAME', 'isp_auxiliar_session')
            : 'isp_auxiliar_session',
        'save_path' => Env::get('SESSION_PATH', ''),
        'cookie_secure' => Env::bool('SESSION_COOKIE_SECURE', true),
        'cookie_samesite' => in_array((string) Env::get('SESSION_COOKIE_SAMESITE', 'Lax'), ['Lax', 'Strict', 'None'], true)
            ? (string) Env::get('SESSION_COOKIE_SAMESITE', 'Lax')
            : 'Lax',
    ],
    'migration_evidence' => [
        'max_bytes' => max(1024, (int) Env::get('MIGRATION_EVIDENCE_MAX_BYTES', (string) (8 * 1024 * 1024))),
        'max_files' => max(1, min(12, (int) Env::get('MIGRATION_EVIDENCE_MAX_FILES', '8'))),
    ],
];
