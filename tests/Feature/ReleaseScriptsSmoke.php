<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$scripts = [
    'deploy_beta.sh',
    'promote_beta_to_stable.sh',
    'rollback_beta.sh',
    'rollback_stable.sh',
    'release_health_check.sh',
];
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

foreach ($scripts as $script) {
    $path = $root . '/scripts/releases/' . $script;
    $assert(is_file($path) && is_executable($path), "Script ausente ou não executável: {$script}");
    $source = (string) file_get_contents($path);
    $assert(str_contains($source, 'set -Eeuo pipefail'), "Modo estrito ausente: {$script}");
    $assert(str_contains($source, '--dry-run'), "Dry-run ausente: {$script}");
    $command = 'bash -n ' . escapeshellarg($path) . ' 2>&1';
    exec($command, $output, $status);
    $assert($status === 0, "Sintaxe Bash inválida: {$script}");
}

$common = (string) file_get_contents($root . '/scripts/releases/common.sh');
$migrationAuditor = (string) file_get_contents($root . '/scripts/releases/migration_auditor.php');
$deploy = (string) file_get_contents($root . '/scripts/releases/deploy_beta.sh');
$promote = (string) file_get_contents($root . '/scripts/releases/promote_beta_to_stable.sh');
$rollbackBeta = (string) file_get_contents($root . '/scripts/releases/rollback_beta.sh');
$rollbackStable = (string) file_get_contents($root . '/scripts/releases/rollback_stable.sh');
$health = (string) file_get_contents($root . '/scripts/releases/release_health_check.sh');
$diagnosticPath = $root . '/scripts/releases/release_smoke_diagnostic.sh';
$diagnostic = (string) file_get_contents($diagnosticPath);

$assert(str_contains($common, 'release_acquire_lock') && str_contains($common, 'flock -n'), 'Lock exclusivo não foi implementado.');
$assert(str_contains($common, 'release_backup_database') && str_contains($common, '--single-transaction'), 'Backup transacional não foi implementado.');
$assert(str_contains($common, 'release_audit_migrations') && str_contains($common, 'release_run_tests'), 'Auditoria de migrations ou testes ausentes.');
$assert(str_contains($common, 'release_migration_is_compatible') && str_contains($common, 'migration_auditor.php'), 'Auditor compartilhado de SQL executável não foi integrado.');
$assert(str_contains($migrationAuditor, 'release_sql_executable_statements') && str_contains($migrationAuditor, 'release_migration_policy_violations'), 'Normalização SQL ou política destrutiva ausente.');
$assert(str_contains($common, 'release_run_smoke_test')
    && str_contains($common, 'release_prepare_smoke_sandbox')
    && str_contains($common, 'release_cleanup_smoke_sandbox')
    && str_contains($common, 'APP_ENV=test')
    && str_contains($common, 'MKAUTH_WRITE_ENABLED=false')
    && str_contains($common, 'AI_REAL_CALLS_ENABLED=false'), 'Runner não isola processo e filesystem dos Smoke Tests.');
$assert(str_contains($common, 'release_read_env_setting')
    && str_contains($common, 'APP_RELEASE_CHANNEL')
    && str_contains($common, 'APP_STABLE_BASE_URL')
    && str_contains($common, 'APP_BETA_BASE_URL')
    && str_contains($common, 'release_run_smoke_test "${test}" "${target}/.env"'), 'Runner não preserva o contexto da release nos Smoke Tests.');
$assert(substr_count($common, 'release_write_env_setting "${target_env}" AI_') === 3, 'Preparo da release não desliga todas as formas de IA.');
$assert(str_contains($common, 'release_atomic_switch') && str_contains($common, 'mv -Tf'), 'Troca atômica de symlink ausente.');
$assert(str_contains($common, 'EU_CONFIRM_REAL_OPERATIONS') && str_contains($common, 'MKAUTH_WRITE_ENABLED false'), 'Operações reais não exigem dupla confirmação ou não são seguras por padrão.');
$assert(str_contains($common, 'release_seal_immutable_code') && str_contains($common, 'chmod 444'), 'Release de código não é selada como imutável.');
$assert(str_contains($deploy, '/isp_auxiliar_beta_current') && !str_contains($deploy, 'isp_auxiliar_stable_current'), 'Deploy Beta pode atingir o symlink Stable.');
$assert(str_contains($deploy, 'git -C "${SOURCE_DIR}" archive') && str_contains($deploy, '.release-manifest'), 'Deploy não usa o commit exato ou não cria manifesto.');
$assert(str_contains($promote, 'release_validate_manifest_channel "${BETA_RELEASE}" beta') && str_contains($promote, 'cp -a "${BETA_RELEASE}"'), 'Promoção não reutiliza a release Beta homologada.');
$assert(!str_contains($rollbackBeta, 'apply_migrations.php') && !str_contains($rollbackStable, 'apply_migrations.php'), 'Rollback tenta reverter ou aplicar banco automaticamente.');
$assert(str_contains($rollbackBeta, 'canal Beta') && str_contains($rollbackStable, 'canal Stable'), 'Rollback não restringe o canal alvo.');
$assert(str_contains($health, '/api/health') && str_contains($health, '/api/release') && str_contains($health, 'external_writes_enabled'), 'Health check não valida aplicação, release e bloqueio externo.');
$assert(is_file($diagnosticPath) && is_executable($diagnosticPath), 'Runner diagnóstico ausente ou não executável.');
$assert(str_contains($diagnostic, 'for test in "${tests[@]}"')
    && str_contains($diagnostic, 'resultado')
    && !str_contains($deploy, 'release_smoke_diagnostic'), 'Runner diagnóstico não continua após falhas ou foi acoplado ao deploy real.');
$assert(str_contains($common, 'status=$?') && str_contains($common, 'break'), 'Runner real deixou de operar em modo fail-fast.');
$diagnosticOutput = [];
$diagnosticStatus = 0;
exec('bash -n ' . escapeshellarg($diagnosticPath) . ' 2>&1', $diagnosticOutput, $diagnosticStatus);
$assert($diagnosticStatus === 0, 'Sintaxe Bash inválida no runner diagnóstico.');

$runBash = static function (string $script, array $arguments = []): array {
    $command = 'bash -c ' . escapeshellarg($script) . ' --';
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg((string) $argument);
    }

    $output = [];
    $status = 0;
    exec($command . ' 2>&1', $output, $status);

    return [
        'status' => $status,
        'output' => implode("\n", $output),
    ];
};

$temporaryPrefix = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/isp-auxiliar-release-migrations-';
$temporaryRoot = $temporaryPrefix . bin2hex(random_bytes(8));
$fixtureTarget = $temporaryRoot . '/release';
$fixtureScripts = $fixtureTarget . '/scripts';
$fixtureMigrations = $fixtureTarget . '/database/migrations';
$commonPath = $root . '/scripts/releases/common.sh';

if (!mkdir($fixtureScripts, 0700, true) || !mkdir($fixtureMigrations, 0700, true)) {
    throw new RuntimeException('Não foi possível criar o sandbox de migrations da release.');
}

$cleanupTree = static function (string $path) use (&$cleanupTree, $temporaryPrefix): void {
    if (!str_starts_with($path, $temporaryPrefix) || !file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        foreach (new FilesystemIterator($path) as $item) {
            $cleanupTree($item->getPathname());
        }
        rmdir($path);
        return;
    }

    unlink($path);
};

try {
    $zeroPendingMarker = $temporaryRoot . '/zero-pending-reached';
    $zeroPending = $runBash(
        <<<'BASH'
set -Eeuo pipefail
source "$1"
release_pending_migrations() { :; }
release_apply_migrations "$2"
printf 'smokes\n' > "$3"
BASH,
        [$commonPath, $fixtureTarget, $zeroPendingMarker]
    );
    $assert($zeroPending['status'] === 0, 'Zero migrations pendentes não retornou sucesso sob set -Eeuo pipefail.');
    $assert(is_file($zeroPendingMarker), 'O fluxo não alcançou os Smokes quando não havia migration pendente.');
    $assert(str_contains($zeroPending['output'], 'nenhuma migration pendente'), 'Zero migrations pendentes não foi registrado no log.');

    $validMigration = $fixtureMigrations . '/999_release_valid.sql';
    file_put_contents($validMigration, "CREATE TABLE release_valid_fixture (id INT NULL);\n");
    $appliedMarker = $temporaryRoot . '/migration-applied';
    file_put_contents(
        $fixtureScripts . '/apply_migrations.php',
        "<?php\nfile_put_contents(" . var_export($appliedMarker, true) . ", \"applied\\n\", FILE_APPEND);\nexit(0);\n"
    );
    $pendingReachedMarker = $temporaryRoot . '/pending-reached';
    $pendingSuccess = $runBash(
        <<<'BASH'
set -Eeuo pipefail
source "$1"
pending_file="$3"
release_pending_migrations() { printf '%s\n' "${pending_file}"; }
CONFIRM_MIGRATIONS=true
DRY_RUN=false
release_audit_migrations "$2"
release_apply_migrations "$2"
printf 'continued\n' > "$4"
BASH,
        [$commonPath, $fixtureTarget, $validMigration, $pendingReachedMarker]
    );
    $assert($pendingSuccess['status'] === 0, 'Migration pendente compatível não retornou sucesso.');
    $assert(is_file($appliedMarker) && is_file($pendingReachedMarker), 'Migration pendente compatível não foi aplicada ou o fluxo não continuou.');

    $incompatibleMigration = $fixtureMigrations . '/999_release_incompatible.sql';
    file_put_contents($incompatibleMigration, "DROP TABLE release_incompatible_fixture;\n");
    $incompatibleReachedMarker = $temporaryRoot . '/incompatible-reached';
    $incompatible = $runBash(
        <<<'BASH'
set -Eeuo pipefail
source "$1"
pending_file="$3"
release_pending_migrations() { printf '%s\n' "${pending_file}"; }
CONFIRM_MIGRATIONS=true
release_audit_migrations "$2"
printf 'unsafe\n' > "$4"
BASH,
        [$commonPath, $fixtureTarget, $incompatibleMigration, $incompatibleReachedMarker]
    );
    $assert($incompatible['status'] === 2 && !file_exists($incompatibleReachedMarker), 'Migration incompatível deixou de bloquear o fluxo.');

    file_put_contents($fixtureScripts . '/apply_migrations.php', "<?php\nexit(7);\n");
    $failureReachedMarker = $temporaryRoot . '/failure-reached';
    $migrationFailure = $runBash(
        <<<'BASH'
set -Eeuo pipefail
source "$1"
pending_file="$3"
release_pending_migrations() { printf '%s\n' "${pending_file}"; }
CONFIRM_MIGRATIONS=true
DRY_RUN=false
release_audit_migrations "$2"
release_apply_migrations "$2"
printf 'unsafe\n' > "$4"
BASH,
        [$commonPath, $fixtureTarget, $validMigration, $failureReachedMarker]
    );
    $assert($migrationFailure['status'] === 7 && !file_exists($failureReachedMarker), 'Falha real da migration não interrompeu o fluxo com o status original.');

    require_once $root . '/backend/bootstrap/app.php';
    $app = bootstrapApplication();
    $database = new App\Infrastructure\Database\Database($app->config());
    $migrationState = static function () use ($database, $root): array {
        $rows = $database->fetchAll(
            'SELECT version, COALESCE(NULLIF(filename, ""), version) AS filename, checksum
             FROM schema_migrations
             ORDER BY version ASC'
        );
        $registry = [];
        foreach ($rows as $row) {
            $registry[basename((string) $row['filename'])] = trim((string) ($row['checksum'] ?? ''));
        }
        ksort($registry);

        $checksumsCorrect = true;
        foreach (glob($root . '/database/migrations/*.sql') ?: [] as $file) {
            $filename = basename($file);
            $checksum = hash_file('sha256', $file) ?: '';
            if (!isset($registry[$filename]) || !hash_equals($checksum, $registry[$filename])) {
                $checksumsCorrect = false;
                break;
            }
        }

        $latest = $database->fetchOne(
            'SELECT version FROM schema_migrations ORDER BY executed_at DESC, version DESC LIMIT 1'
        );

        return [
            'count' => count($rows),
            'fingerprint' => hash('sha256', json_encode($registry, JSON_THROW_ON_ERROR)),
            'checksums_correct' => $checksumsCorrect,
            'schema_version' => trim((string) ($latest['version'] ?? 'none')),
        ];
    };

    $before = $migrationState();
    $assert($before['checksums_correct'], 'O cenário exige todas as migrations aplicadas com checksums corretos.');
    $manifest = $temporaryRoot . '/release-manifest';
    $trace = $temporaryRoot . '/release-trace';
    $reapplyMarker = $temporaryRoot . '/unexpected-reapply';
    $expectedCommit = str_repeat('a', 40);
    $allApplied = $runBash(
        <<<'BASH'
set -Eeuo pipefail
source "$1"
target="$2"
manifest="$3"
trace="$4"
reapply_marker="$5"
expected_commit="$6"
DRY_RUN=false
CONFIRM_MIGRATIONS=true
release_run() { printf 'reaplicou\n' > "${reapply_marker}"; return 91; }
release_run_tests() { printf 'smokes\n' >> "${trace}"; }
release_cli_health_check() {
    [[ "$2" == beta ]]
    [[ "$3" == "${expected_commit}" ]]
    printf 'health-cli\n' >> "${trace}"
}
release_seal_immutable_code() { printf 'seal\n' >> "${trace}"; }
release_atomic_switch() { printf 'switch\n' >> "${trace}"; }

release_apply_migrations "${target}"
schema_version="$(release_schema_version "${target}")"
printf 'schema_version=%s\n' "${schema_version}" > "${manifest}"
release_run_tests "${target}"
release_cli_health_check "${target}" beta "${expected_commit}"
release_seal_immutable_code "${target}"
release_atomic_switch /tmp/isp-auxiliar-beta-fixture-current "${target}"
printf 'posterior\n' >> "${trace}"
BASH,
        [$commonPath, $root, $manifest, $trace, $reapplyMarker, $expectedCommit]
    );
    $after = $migrationState();
    $expectedTrace = "smokes\nhealth-cli\nseal\nswitch\nposterior\n";
    $assert($allApplied['status'] === 0, 'O fluxo com todas as migrations aplicadas não terminou com sucesso.');
    $assert(!file_exists($reapplyMarker), 'Uma migration já aplicada foi reaplicada.');
    $assert($before['count'] === $after['count'] && $before['fingerprint'] === $after['fingerprint'], 'O registro de migrations mudou no cenário sem pendências.');
    $assert((string) file_get_contents($manifest) === 'schema_version=' . $before['schema_version'] . "\n", 'O manifesto não recebeu a schema_version obtida do banco.');
    $assert((string) file_get_contents($trace) === $expectedTrace, 'O fluxo não alcançou Smokes, health CLI simulado e etapas posteriores.');
} finally {
    $cleanupTree($temporaryRoot);
}

echo 'ReleaseScriptsSmoke OK - ' . $checks . " verificações; nenhuma implantação executada.\n";
