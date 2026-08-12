<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$common = $root . '/scripts/releases/common.sh';
$temporaryRoot = sys_get_temp_dir() . '/release_smoke_env_' . bin2hex(random_bytes(6));

if (!mkdir($temporaryRoot, 0700, true) && !is_dir($temporaryRoot)) {
    throw new RuntimeException('Não foi possível criar diretório temporário do teste.');
}

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

$requiredSmokeEnvironment = [
    'APP_ENV' => 'test',
    'MKAUTH_WRITE_ENABLED' => 'false',
    'EVOTRIX_DRY_RUN' => 'true',
    'EMAIL_DRY_RUN' => 'true',
    'MKAUTH_TICKET_DRY_RUN' => 'true',
    'AI_ENABLED' => 'false',
    'AI_ACTIONS_ENABLED' => 'false',
    'AI_REAL_CALLS_ENABLED' => 'false',
];

$probe = $temporaryRoot . '/EnvironmentProbe.php';
file_put_contents($probe, <<<'PHP'
<?php

$keys = [
    'APP_ENV',
    'MKAUTH_WRITE_ENABLED',
    'EVOTRIX_DRY_RUN',
    'EMAIL_DRY_RUN',
    'MKAUTH_TICKET_DRY_RUN',
    'AI_ENABLED',
    'AI_ACTIONS_ENABLED',
    'AI_REAL_CALLS_ENABLED',
];
$values = [];
foreach ($keys as $key) {
    $values[$key] = getenv($key);
}
file_put_contents((string) getenv('SMOKE_CAPTURE_PATH'), json_encode($values, JSON_THROW_ON_ERROR));
PHP
);

$runScenario = static function (string $name, bool $realOperations) use (
    $temporaryRoot,
    $probe,
    $common,
    $requiredSmokeEnvironment,
    $assert
): void {
    $scenarioDir = $temporaryRoot . '/' . $name;
    if (!mkdir($scenarioDir, 0700, true) && !is_dir($scenarioDir)) {
        throw new RuntimeException('Não foi possível preparar cenário: ' . $name);
    }

    $sourceEnv = $scenarioDir . '/source.env';
    $releaseEnv = $scenarioDir . '/release.env';
    $capture = $scenarioDir . '/smoke.json';
    $beforeHashFile = $scenarioDir . '/before.sha256';
    $afterHashFile = $scenarioDir . '/after.sha256';
    $externalValues = $realOperations ? [
        'MKAUTH_WRITE_ENABLED' => 'true',
        'EVOTRIX_DRY_RUN' => 'false',
        'EMAIL_DRY_RUN' => 'false',
        'MKAUTH_TICKET_DRY_RUN' => 'false',
    ] : [
        'MKAUTH_WRITE_ENABLED' => 'false',
        'EVOTRIX_DRY_RUN' => 'true',
        'EMAIL_DRY_RUN' => 'true',
        'MKAUTH_TICKET_DRY_RUN' => 'true',
    ];
    $sourceValues = array_merge([
        'APP_ENV' => 'production',
        'AI_ENABLED' => 'true',
        'AI_ACTIONS_ENABLED' => 'true',
        'AI_REAL_CALLS_ENABLED' => 'true',
    ], $externalValues);
    $sourceContents = '';
    foreach ($sourceValues as $key => $value) {
        $sourceContents .= $key . '=' . $value . PHP_EOL;
    }
    file_put_contents($sourceEnv, $sourceContents);

    $shell = <<<'BASH'
set -Eeuo pipefail
source "$1"
DRY_RUN=false
ENABLE_REAL_OPERATIONS="$2"
release_prepare_env "$3" "$4" beta release-smoke-test 0000000000000000000000000000000000000000
sha256sum "$4" | cut -d' ' -f1 > "$7"
SMOKE_CAPTURE_PATH="$5" release_run_smoke_test "$6"
sha256sum "$4" | cut -d' ' -f1 > "$8"
BASH;
    $command = 'bash -c ' . escapeshellarg($shell)
        . ' -- ' . escapeshellarg($common)
        . ' ' . escapeshellarg($realOperations ? 'true' : 'false')
        . ' ' . escapeshellarg($sourceEnv)
        . ' ' . escapeshellarg($releaseEnv)
        . ' ' . escapeshellarg($capture)
        . ' ' . escapeshellarg($probe)
        . ' ' . escapeshellarg($beforeHashFile)
        . ' ' . escapeshellarg($afterHashFile)
        . ' 2>&1';
    exec($command, $output, $status);
    $assert($status === 0, 'Runner falhou no cenário ' . $name . ': ' . implode(' ', $output));

    $captured = json_decode((string) file_get_contents($capture), true, 512, JSON_THROW_ON_ERROR);
    $assert($captured === $requiredSmokeEnvironment, 'Processo Smoke não recebeu isolamento integral no cenário ' . $name . '.');

    $releaseBeforeSmoke = trim((string) file_get_contents($beforeHashFile));
    $releaseValues = parse_ini_file($releaseEnv, false, INI_SCANNER_RAW);
    $releaseAfterSmoke = trim((string) file_get_contents($afterHashFile));
    $assert($releaseBeforeSmoke === $releaseAfterSmoke, 'Runner alterou permanentemente o .env no cenário ' . $name . '.');
    $assert(($releaseValues['APP_ENV'] ?? '') === 'production', 'APP_ENV final foi alterado no cenário ' . $name . '.');
    foreach ($externalValues as $key => $value) {
        $assert(($releaseValues[$key] ?? '') === $value, $key . ' final divergiu no cenário ' . $name . '.');
    }
    foreach (['AI_ENABLED', 'AI_ACTIONS_ENABLED', 'AI_REAL_CALLS_ENABLED'] as $key) {
        $assert(($releaseValues[$key] ?? '') === 'false', $key . ' não ficou desligada no cenário ' . $name . '.');
    }
};

try {
    $runScenario('safe', false);
    $runScenario('real', true);

    echo 'ReleaseSmokeEnvironmentSmoke OK - ' . $checks
        . " verificações; Smokes isolados e .env final preservado.\n";
} finally {
    $files = glob($temporaryRoot . '/*/*') ?: [];
    foreach ($files as $file) {
        @unlink($file);
    }
    foreach (glob($temporaryRoot . '/*') ?: [] as $path) {
        if (is_dir($path)) {
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($temporaryRoot);
}
