#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Core\Env;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\Settings\IntegrationSettingsStore;

/**
 * Copia as seções email/evotrix de storage/contracts/config.json para
 * provider_settings (chaves `email.*` / `evotrix.*`), sem alterar valores.
 *
 * Uso: php scripts/migrate_integration_settings.php [--apply] [--file=caminho]
 *   (sem --apply é só simulação). Não sobrescreve chaves já existentes em
 *   provider_settings. O arquivo de origem nunca é alterado nem apagado.
 */

require dirname(__DIR__) . '/backend/bootstrap/app.php';

$app = bootstrapApplication();
$apply = in_array('--apply', $argv ?? [], true);
$file = dirname(__DIR__) . '/storage/contracts/config.json';
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--file=')) {
        $file = substr($arg, 7);
    }
}

if (!is_file($file)) {
    echo "Nada a migrar: {$file} não existe.\n";
    exit(0);
}
$legacy = json_decode((string) file_get_contents($file), true);
if (!is_array($legacy)) {
    fwrite(STDERR, "Arquivo inválido (JSON ilegível): {$file}\n");
    exit(1);
}

$repository = new LocalRepository(new Database($app->config()), (string) Env::get('APP_PROVIDER_KEY', 'default'));
$existing = $repository->providerSettings();
$store = new IntegrationSettingsStore($repository);
$known = ['email' => array_keys((array) $app->config()->get('email', [])), 'evotrix' => array_keys((array) $app->config()->get('evotrix', []))];

$pending = [];
$skipped = 0;
foreach ($known as $section => $fields) {
    $values = is_array($legacy[$section] ?? null) ? $legacy[$section] : [];
    foreach ($fields as $field) {
        if (!array_key_exists($field, $values)) {
            continue;
        }
        if (array_key_exists($section . '.' . $field, $existing)) {
            $skipped++;
            continue;
        }
        $pending[$section][$field] = $values[$field];
    }
}

foreach ($pending as $section => $values) {
    echo ($apply ? 'Migrando' : 'Migraria') . " {$section}: " . implode(', ', array_keys($values)) . "\n";
    if ($apply) {
        $store->save($section, $values);
    }
}
echo "Já existentes em provider_settings (preservados): {$skipped}\n";
echo $apply ? "Concluído.\n" : "Simulação — nada foi gravado. Use --apply para gravar.\n";
