<?php

declare(strict_types=1);

use App\Core\Env;

$rootPath = dirname(__DIR__, 2);
$overrideFile = $rootPath . '/storage/contracts/config.json';
$overrides = [];

if (is_file($overrideFile)) {
    $decodedOverrides = json_decode((string) file_get_contents($overrideFile), true);
    if (is_array($decodedOverrides)) {
        $overrides = $decodedOverrides;
    }
}

$evotrixOverrides = is_array($overrides['evotrix'] ?? null) ? $overrides['evotrix'] : [];

$evotrix = [
    'enabled' => Env::bool('EVOTRIX_ENABLED', false),
    'dry_run' => Env::bool('EVOTRIX_DRY_RUN', true),
    'base_url' => Env::get('EVOTRIX_API_BASE', Env::get('EVOTRIX_BASE_URL', '')),
    'token' => Env::get('EVOTRIX_API_KEY', Env::get('EVOTRIX_TOKEN', '')),
    'sender' => Env::get('EVOTRIX_SENDER', 'nossa equipe'),
    'channel' => 'whatsapp',
    'endpoint' => Env::get('EVOTRIX_ENDPOINT', '/v1/services/whatsapp/notifications/text'),
    'channel_id' => Env::get('EVOTRIX_CHANNEL_ID', ''),
    'allow_only_test_phone' => Env::bool('EVOTRIX_ALLOW_ONLY_TEST_PHONE', false),
    'test_phone' => Env::get('EVOTRIX_TEST_PHONE', ''),
    'timeout_seconds' => max(5, (int) Env::get('EVOTRIX_TIMEOUT_SECONDS', '15')),
    'retry_attempts' => max(0, (int) Env::get('EVOTRIX_RETRY_ATTEMPTS', '1')),
];

$evotrix = array_replace($evotrix, array_intersect_key($evotrixOverrides, $evotrix));

return $evotrix;
