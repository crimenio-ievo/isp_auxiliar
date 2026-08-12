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

$assert(str_contains($common, 'release_acquire_lock') && str_contains($common, 'flock -n'), 'Lock exclusivo não foi implementado.');
$assert(str_contains($common, 'release_backup_database') && str_contains($common, '--single-transaction'), 'Backup transacional não foi implementado.');
$assert(str_contains($common, 'release_audit_migrations') && str_contains($common, 'release_run_tests'), 'Auditoria de migrations ou testes ausentes.');
$assert(str_contains($common, 'release_migration_is_compatible') && str_contains($common, 'migration_auditor.php'), 'Auditor compartilhado de SQL executável não foi integrado.');
$assert(str_contains($migrationAuditor, 'release_sql_executable_statements') && str_contains($migrationAuditor, 'release_migration_policy_violations'), 'Normalização SQL ou política destrutiva ausente.');
$assert(str_contains($common, 'release_atomic_switch') && str_contains($common, 'mv -Tf'), 'Troca atômica de symlink ausente.');
$assert(str_contains($common, 'EU_CONFIRM_REAL_OPERATIONS') && str_contains($common, 'MKAUTH_WRITE_ENABLED false'), 'Operações reais não exigem dupla confirmação ou não são seguras por padrão.');
$assert(str_contains($common, 'release_seal_immutable_code') && str_contains($common, 'chmod 444'), 'Release de código não é selada como imutável.');
$assert(str_contains($deploy, '/isp_auxiliar_beta_current') && !str_contains($deploy, 'isp_auxiliar_stable_current'), 'Deploy Beta pode atingir o symlink Stable.');
$assert(str_contains($deploy, 'git -C "${SOURCE_DIR}" archive') && str_contains($deploy, '.release-manifest'), 'Deploy não usa o commit exato ou não cria manifesto.');
$assert(str_contains($promote, 'release_validate_manifest_channel "${BETA_RELEASE}" beta') && str_contains($promote, 'cp -a "${BETA_RELEASE}"'), 'Promoção não reutiliza a release Beta homologada.');
$assert(!str_contains($rollbackBeta, 'apply_migrations.php') && !str_contains($rollbackStable, 'apply_migrations.php'), 'Rollback tenta reverter ou aplicar banco automaticamente.');
$assert(str_contains($rollbackBeta, 'canal Beta') && str_contains($rollbackStable, 'canal Stable'), 'Rollback não restringe o canal alvo.');
$assert(str_contains($health, '/api/health') && str_contains($health, '/api/release') && str_contains($health, 'external_writes_enabled'), 'Health check não valida aplicação, release e bloqueio externo.');

echo 'ReleaseScriptsSmoke OK - ' . $checks . " verificações; nenhuma implantação executada.\n";
