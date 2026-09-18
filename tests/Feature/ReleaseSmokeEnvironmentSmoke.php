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

$requiredSafetyEnvironment = [
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

$safetyKeys = [
    'APP_ENV',
    'MKAUTH_WRITE_ENABLED',
    'EVOTRIX_DRY_RUN',
    'EMAIL_DRY_RUN',
    'MKAUTH_TICKET_DRY_RUN',
    'AI_ENABLED',
    'AI_ACTIONS_ENABLED',
    'AI_REAL_CALLS_ENABLED',
];
$contextKeys = [
    'APP_RELEASE_CHANNEL',
    'APP_RELEASE_ID',
    'APP_RELEASE_COMMIT',
    'APP_RELEASE_BUILD_DATE',
    'APP_URL',
    'APP_STABLE_BASE_URL',
    'APP_BETA_BASE_URL',
];
$safety = [];
foreach ($safetyKeys as $key) {
    $safety[$key] = getenv($key);
}
$context = [];
foreach ($contextKeys as $key) {
    $context[$key] = getenv($key);
}

$root = (string) getenv('SMOKE_APP_ROOT');
require $root . '/backend/bootstrap/app.php';
$app = bootstrapApplication();
$view = new App\Core\View((string) $app->config()->get('paths.views'));
$user = ['name' => 'Gestor', 'role' => 'manager', 'access' => ['can_use_beta' => true]];
$header = $view->render('layouts/header', [
    'layoutMode' => 'app',
    'user' => $user,
    'appName' => 'ISP Auxiliar',
]);

$result = [
    'safety' => $safety,
    'context' => $context,
    'config' => [
        'channel' => $app->config()->get('app.release.channel'),
        'release_id' => $app->config()->get('app.release.id'),
        'release_commit' => $app->config()->get('app.release.commit'),
        'app_url' => $app->config()->get('app.url'),
        'stable_url' => $app->config()->get('app.release.stable_base_url'),
        'beta_url' => $app->config()->get('app.release.beta_base_url'),
    ],
    'check_25' => str_contains($header, 'Canal: <strong>Beta</strong>')
        && str_contains($header, 'Abrir Stable'),
    'check_26' => !(bool) $app->config()->get('app.mkauth.write_enabled', false)
        && (bool) $app->config()->get('evotrix.dry_run', false)
        && (bool) $app->config()->get('email.dry_run', false)
        && (bool) $app->config()->get('contracts.mkauth_ticket.dry_run', false),
];
file_put_contents((string) getenv('SMOKE_CAPTURE_PATH'), json_encode($result, JSON_THROW_ON_ERROR));
PHP
);

$runScenario = static function (string $name, bool $realOperations) use (
    $root,
    $temporaryRoot,
    $probe,
    $common,
    $requiredSafetyEnvironment,
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
        'APP_URL' => 'https://ispaux.ievo.com.br/beta',
        'APP_STABLE_BASE_URL' => 'https://ispaux.ievo.com.br/',
        'APP_BETA_BASE_URL' => 'https://ispaux.ievo.com.br/beta/',
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
export APP_RELEASE_CHANNEL=stable
export APP_RELEASE_ID=ambient-release
export APP_RELEASE_COMMIT=1111111111111111111111111111111111111111
export APP_RELEASE_BUILD_DATE=2000-01-01T00:00:00Z
export APP_URL=https://wrong.example.test
export APP_STABLE_BASE_URL=
export APP_BETA_BASE_URL=https://wrong.example.test/beta
SMOKE_CAPTURE_PATH="$5" SMOKE_APP_ROOT="$9" release_run_smoke_test "$6" "$4"
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
        . ' ' . escapeshellarg($root)
        . ' 2>&1';
    exec($command, $output, $status);
    $assert($status === 0, 'Runner falhou no cenário ' . $name . ': ' . implode(' ', $output));

    $releaseBeforeSmoke = trim((string) file_get_contents($beforeHashFile));
    $releaseValues = parse_ini_file($releaseEnv, false, INI_SCANNER_RAW);
    $releaseAfterSmoke = trim((string) file_get_contents($afterHashFile));
    $captured = json_decode((string) file_get_contents($capture), true, 512, JSON_THROW_ON_ERROR);

    foreach ($requiredSafetyEnvironment as $key => $value) {
        $assert(($captured['safety'][$key] ?? null) === $value, $key . ' não foi isolada no cenário ' . $name . '.');
    }

    $expectedContext = [
        'APP_RELEASE_CHANNEL' => 'beta',
        'APP_RELEASE_ID' => 'release-smoke-test',
        'APP_RELEASE_COMMIT' => '0000000000000000000000000000000000000000',
        'APP_RELEASE_BUILD_DATE' => $releaseValues['APP_RELEASE_BUILD_DATE'] ?? '',
        'APP_URL' => 'https://ispaux.ievo.com.br/beta',
        'APP_STABLE_BASE_URL' => 'https://ispaux.ievo.com.br/',
        'APP_BETA_BASE_URL' => 'https://ispaux.ievo.com.br/beta/',
    ];
    foreach ($expectedContext as $key => $value) {
        $assert(($captured['context'][$key] ?? null) === $value, $key . ' perdeu o contexto da release no cenário ' . $name . '.');
    }

    $assert(($captured['config']['channel'] ?? null) === 'beta', 'Configuração carregou canal incorreto no cenário ' . $name . '.');
    $assert(($captured['config']['release_id'] ?? null) === 'release-smoke-test', 'Configuração perdeu release ID no cenário ' . $name . '.');
    $assert(($captured['config']['release_commit'] ?? null) === '0000000000000000000000000000000000000000', 'Configuração perdeu commit no cenário ' . $name . '.');
    $assert(($captured['config']['app_url'] ?? null) === 'https://ispaux.ievo.com.br/beta', 'Configuração perdeu APP_URL no cenário ' . $name . '.');
    $assert(($captured['config']['stable_url'] ?? null) === 'https://ispaux.ievo.com.br/', 'Configuração perdeu destino Stable no cenário ' . $name . '.');
    $assert(($captured['config']['beta_url'] ?? null) === 'https://ispaux.ievo.com.br/beta/', 'Configuração perdeu destino Beta no cenário ' . $name . '.');
    $assert(($captured['check_25'] ?? false) === true, 'Verificação 25 não foi preservada no cenário ' . $name . '.');
    $assert(($captured['check_26'] ?? false) === true, 'Verificação 26 não foi preservada no cenário ' . $name . '.');

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
