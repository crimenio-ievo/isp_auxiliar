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

$emailOverrides = is_array($overrides['email'] ?? null) ? $overrides['email'] : [];

$email = [
    'enabled' => Env::bool('EMAIL_ENABLED', false),
    'dry_run' => Env::bool('EMAIL_DRY_RUN', true),
    'allow_only_test_email' => Env::bool('EMAIL_ALLOW_ONLY_TEST_EMAIL', true),
    'test_to' => Env::get('EMAIL_TEST_TO', ''),
    'smtp_host' => Env::get('SMTP_HOST', ''),
    'smtp_port' => (int) Env::get('SMTP_PORT', '587'),
    'smtp_username' => Env::get('SMTP_USERNAME', ''),
    'smtp_password' => Env::get('SMTP_PASSWORD', ''),
    'smtp_encryption' => Env::get('SMTP_ENCRYPTION', 'tls'),
    'smtp_from' => Env::get('SMTP_FROM', ''),
    'smtp_from_name' => Env::get('SMTP_FROM_NAME', 'ISP Auxiliar'),
];

$email = array_replace($email, array_intersect_key($emailOverrides, $email));

return $email;
