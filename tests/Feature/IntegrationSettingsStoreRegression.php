<?php

declare(strict_types=1);

use App\Infrastructure\Settings\IntegrationSettingsStore as Store;

require dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';

/**
 * Email/Evotrix em provider_settings: salvar → carregar devolve exatamente os
 * mesmos valores (e tipos) que o arquivo legado produzia. Sem banco: store falso.
 */

$n = 0;
$assert = static function (bool $ok, string $msg) use (&$n): void {
    if (!$ok) {
        throw new RuntimeException("Verificação falhou: {$msg}");
    }
    $n++;
};

$fake = new class extends Store {
    /** @var array<string, string> */
    public array $rows = [];

    protected function persist(array $rows): void
    {
        $this->rows = array_replace($this->rows, $rows);
    }
};

$emailDefaults = ['enabled' => false, 'dry_run' => true, 'smtp_host' => '', 'smtp_port' => 587, 'smtp_password' => '', 'allow_only_test_email' => false];
$saved = ['enabled' => true, 'dry_run' => false, 'smtp_host' => 'smtp.ex.com', 'smtp_port' => 465, 'smtp_password' => 'S3nh@ "x"', 'allow_only_test_email' => false];

$fake->save('email', $saved);
$assert($fake->rows['email.enabled'] === '1' && $fake->rows['email.dry_run'] === '0', 'booleanos gravados como 1/0');
$assert($fake->rows['email.smtp_port'] === '465', 'inteiro gravado como texto');

// Em produção o valor carregado é idêntico ao salvo, com os mesmos tipos.
$loaded = Store::overlay('email', $emailDefaults, $fake->rows, 'production');
$assert($loaded === $saved, 'roundtrip idêntico em produção: ' . json_encode($loaded));

// Chaves ausentes preservam o padrão; valor vazio salvo é respeitado (como no JSON antigo).
$partial = Store::overlay('email', $emailDefaults, ['email.smtp_host' => ''], 'production');
$assert($partial === $emailDefaults, 'chave vazia/ausente mantém o padrão');
$assert(Store::overlay('email', $emailDefaults, ['email.smtp_host' => 'x', 'outro.campo' => 'y'], 'production')['smtp_host'] === 'x', 'lê só chaves da seção');

// Fora de produção a trava de dry-run continua valendo, mesmo com valor salvo em contrário.
$guard = Store::overlay('email', $emailDefaults, $fake->rows, 'staging');
$assert($guard['dry_run'] === true && $guard['allow_only_test_email'] === true, 'trava de ambiente não produtivo preservada (email)');
$evo = Store::overlay('evotrix', ['dry_run' => false, 'allow_only_test_phone' => false], ['evotrix.dry_run' => '0'], 'local');
$assert($evo['dry_run'] === true && $evo['allow_only_test_phone'] === true, 'trava de ambiente não produtivo preservada (evotrix)');

$threw = false;
try {
    $fake->save('mkauth', ['x' => 1]);
} catch (InvalidArgumentException) {
    $threw = true;
}
$assert($threw, 'seção desconhecida é rejeitada');

echo "IntegrationSettingsStoreRegression: {$n} checks passed\n";
