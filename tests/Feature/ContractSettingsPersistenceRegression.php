<?php

declare(strict_types=1);

use App\Controllers\ContractController;
use App\Infrastructure\Local\LocalRepository;

require_once dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';

/**
 * Regressão: ContractController::saveModuleSettings() não pode mais
 * sobrescrever o storage/contracts/config.json inteiro. Antes da correção,
 * ele gravava só as chaves commercial/email/saved_at/saved_by, apagando
 * qualquer seção salva pelo SettingsController (evotrix, mkauth_ticket,
 * system). Este teste nunca toca no arquivo real de produção — opera
 * inteiramente sobre um arquivo temporário via propriedade de override.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['user'] = ['login' => 'teste.contratos', 'name' => 'Teste', 'role' => 'gestor'];

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

$temporaryRoot = sys_get_temp_dir() . '/isp_auxiliar_contract_settings_' . bin2hex(random_bytes(6));
$temporaryPath = $temporaryRoot . '/config.json';

$cleanup = static function () use ($temporaryRoot): void {
    if (!is_dir($temporaryRoot)) {
        return;
    }
    foreach (glob($temporaryRoot . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($temporaryRoot);
};

try {
    $controller = (new ReflectionClass(ContractController::class))->newInstanceWithoutConstructor();
    $controllerReflection = new ReflectionClass(ContractController::class);

    $localRepository = (new ReflectionClass(LocalRepository::class))->newInstanceWithoutConstructor();
    $controllerReflection->getProperty('localRepository')->setValue($controller, $localRepository);

    $pathOverride = $controllerReflection->getProperty('moduleSettingsPathOverride');
    $pathOverride->setValue($controller, $temporaryPath);

    $saveModuleSettings = new ReflectionMethod(ContractController::class, 'saveModuleSettings');
    $readStoredModuleSettings = new ReflectionMethod(ContractController::class, 'readStoredModuleSettings');

    // 1. Diretório ainda não existe: a primeira gravação precisa criá-lo.
    $assert(!is_dir($temporaryRoot), 'Pré-condição inválida: diretório temporário já existia.');

    // 2. Simula um config.json já salvo anteriormente pelo SettingsController,
    //    com seções que o ContractController nunca deveria tocar.
    mkdir($temporaryRoot, 0775, true);
    file_put_contents($temporaryPath, json_encode([
        'evotrix' => ['enabled' => true, 'token' => 'segredo-evotrix'],
        'mkauth_ticket' => ['enabled' => true, 'auto_create' => false],
        'system' => ['settings_saved_at' => '2026-08-01 10:00:00', 'settings_saved_by' => 'outro.operador'],
        'commercial' => ['valor_adesao_padrao' => 10.0],
        'email' => ['enabled' => false],
    ], JSON_PRETTY_PRINT));

    // 3. Reenvio manual de aceite salva novas configurações comerciais/e-mail.
    $saveModuleSettings->invoke($controller, ['valor_adesao_padrao' => 99.9, 'multa_padrao' => 5.0], ['enabled' => true, 'smtp_host' => 'smtp.exemplo.com']);

    $assert(is_file($temporaryPath), 'saveModuleSettings não escreveu o arquivo.');
    $stored = json_decode((string) file_get_contents($temporaryPath), true);
    $assert(is_array($stored), 'Arquivo salvo não é um JSON válido.');

    // 4. As seções que pertencem a outras telas devem sobreviver intactas.
    $assert(($stored['evotrix']['token'] ?? null) === 'segredo-evotrix', 'saveModuleSettings apagou a seção evotrix de outra tela.');
    $assert(($stored['mkauth_ticket']['auto_create'] ?? null) === false, 'saveModuleSettings apagou a seção mkauth_ticket de outra tela.');
    $assert(($stored['system']['settings_saved_by'] ?? null) === 'outro.operador', 'saveModuleSettings apagou o carimbo system de outra tela.');

    // 5. As seções que o ContractController realmente possui devem refletir o novo valor.
    $assert(($stored['commercial']['valor_adesao_padrao'] ?? null) === 99.9, 'Nova configuração comercial não foi persistida.');
    $assert(($stored['email']['smtp_host'] ?? null) === 'smtp.exemplo.com', 'Nova configuração de e-mail não foi persistida.');
    $assert(($stored['saved_by'] ?? null) === 'teste.contratos', 'Carimbo saved_by não usa o usuário autenticado.');

    // 6. Escrita atômica: nenhum arquivo temporário deve sobrar após o rename.
    $leftoverTemp = glob($temporaryRoot . '/config.json.tmp.*') ?: [];
    $assert($leftoverTemp === [], 'Gravação não é atômica: arquivo temporário permaneceu no disco.');

    // 7. readStoredModuleSettings deve enxergar exatamente o que foi persistido.
    $reread = $readStoredModuleSettings->invoke($controller);
    $assert(($reread['evotrix']['token'] ?? null) === 'segredo-evotrix', 'Leitura pós-gravação não reflete a mesclagem.');

    // 8. Uma segunda gravação (simulando outro reenvio) não deve perder o que a primeira preservou.
    $saveModuleSettings->invoke($controller, ['valor_adesao_padrao' => 120.0], ['enabled' => false]);
    $secondRead = json_decode((string) file_get_contents($temporaryPath), true);
    $assert(($secondRead['evotrix']['token'] ?? null) === 'segredo-evotrix', 'Segunda gravação em sequência apagou a seção evotrix.');
    $assert((float) ($secondRead['commercial']['valor_adesao_padrao'] ?? 0) === 120.0, 'Segunda gravação não atualizou a seção commercial.');

    echo "ContractSettingsPersistenceRegression: {$checks} checks passed\n";
} finally {
    $cleanup();
}
