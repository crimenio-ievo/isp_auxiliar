<?php

declare(strict_types=1);

use App\Controllers\ClientController;
use App\Core\Env;
use App\Core\Request;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\MkAuth\TechnologyMapper;

require dirname(__DIR__, 2) . '/backend/bootstrap/app.php';

$root = dirname(__DIR__, 2);
$app = bootstrapApplication();
$database = new Database($app->config());
$local = new LocalRepository($database, (string) Env::get('APP_PROVIDER_KEY', 'default'));
$controller = (new ReflectionClass(ClientController::class))->newInstanceWithoutConstructor();
$reflection = new ReflectionClass(ClientController::class);
$reflection->getProperty('technologyMapper')->setValue($controller, new TechnologyMapper());
$reflection->getProperty('localRepository')->setValue($controller, $local);
$reflection->getProperty('config')->setValue($controller, $app->config());
$collect = new ReflectionMethod(ClientController::class, 'collectUpgradeFormData');
$validate = new ReflectionMethod(ClientController::class, 'validateUpgrade');
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

$_SESSION['user'] = ['login' => 'final.beta.manager', 'name' => 'Gestor Beta', 'role' => 'manager'];
$plans = [
    ['id' => 'R5', 'name' => 'Rádio Rural 5 Mbps', 'label' => 'Rádio Rural 5 Mbps — R$ 70,00', 'technology' => 'D', 'technology_label' => 'Rádio fixo (FWA)', 'install_type' => 'radio', 'speed_down' => '5M', 'value' => '70.00'],
    ['id' => 'R10', 'name' => 'Rádio Rural 10 Mbps', 'label' => 'Rádio Rural 10 Mbps — R$ 80,00', 'technology' => 'D', 'technology_label' => 'Rádio fixo (FWA)', 'install_type' => 'radio', 'speed_down' => '10M', 'value' => '80.00'],
    ['id' => 'R20', 'name' => 'Rádio Rural 20 Mbps', 'label' => 'Rádio Rural 20 Mbps — R$ 100,00', 'technology' => 'D', 'technology_label' => 'Rádio fixo (FWA)', 'install_type' => 'radio', 'speed_down' => '20M', 'value' => '100.00'],
    ['id' => 'F100', 'name' => 'Fibra Rural 100 Mbps', 'label' => 'Fibra Rural 100 Mbps — R$ 149,90', 'technology' => 'H', 'technology_label' => 'Fibra até o imóvel (FTTH)', 'install_type' => 'fibra', 'speed_down' => '100M', 'value' => '149.90'],
];
$context = [
    'login' => 'final_beta_client',
    'current_plan' => 'R10',
    'current_technology' => 'Rádio fixo (FWA)',
    'current_technology_family' => 'radio',
    'current_monthly_value' => 80.0,
    'planOptions' => $plans,
];
$requestFor = static fn (array $data): Request => new Request('POST', '/clientes/upgrade', '', [], $data);
$adhesion = (float) $app->config()->get('contracts.commercial.valor_adesao_padrao', 0);

$radioToFiber = $collect->invoke($controller, $requestFor(['novo_plano' => 'F100']), $context);
$assert(($radioToFiber['operation_type'] ?? '') === 'migration', 'Rádio para Fibra não foi classificado como migração.');
$assert($adhesion > 0 && abs((float) ($radioToFiber['valor_beneficio'] ?? 0) - $adhesion) < 0.01, 'Migração não restaurou o benefício integral configurado.');
$assert(!empty($radioToFiber['apply_fidelity']) && (int) ($radioToFiber['fidelidade_meses'] ?? 0) === 12, 'Benefício elegível não aplicou a fidelidade configurada.');

$radioUpgrade = $collect->invoke($controller, $requestFor([
    'novo_plano' => 'R20',
    'valor_beneficio' => (string) $adhesion,
    'fidelity_choice_present' => '1',
    'apply_fidelity' => '1',
]), $context);
$assert(($radioUpgrade['operation_type'] ?? '') === 'upgrade', 'Rádio para Rádio superior não foi classificado como upgrade.');
$assert((float) ($radioUpgrade['valor_beneficio'] ?? -1) === 0.0, 'Upgrade herdou benefício de migração anterior.');
$assert(empty($radioUpgrade['apply_fidelity']) && (int) ($radioUpgrade['fidelidade_meses'] ?? -1) === 0, 'Plano sem benefício manteve fidelidade.');
$assert($validate->invoke($controller, $radioUpgrade, $context) === [], 'Upgrade sem benefício elegível foi bloqueado.');

$radioToFiberAgain = $collect->invoke($controller, $requestFor(['novo_plano' => 'F100']), $context);
$assert(abs((float) ($radioToFiberAgain['valor_beneficio'] ?? 0) - $adhesion) < 0.01, 'Retorno para Fibra não recalculou o benefício correto.');

$retention = $collect->invoke($controller, $requestFor([
    'novo_plano' => 'R5',
    'retention_condition' => '1',
    'beneficio_outro_text' => 'Desconto de retenção mensurável',
    'valor_beneficio' => '240,00',
    'benefit_adjustment_reason' => 'Condição aprovada para retenção',
    'fidelity_choice_present' => '1',
    'apply_fidelity' => '1',
    'fidelity_benefit_description' => 'Desconto de retenção mensurável',
    'fidelidade_meses' => '12',
    'observacao' => 'Retenção autorizada para evitar cancelamento.',
]), $context);
$assert(($retention['operation_type'] ?? '') === 'downgrade' && !empty($retention['retention_condition']), 'Retenção não permaneceu separada do downgrade técnico.');
$assert(abs((float) ($retention['valor_beneficio'] ?? 0) - 240.0) < 0.01 && !empty($retention['apply_fidelity']), 'Retenção configurada não preservou benefício e fidelidade elegíveis.');
$assert($validate->invoke($controller, $retention, $context) === [], 'Retenção válida foi rejeitada.');

$migrationView = (string) file_get_contents($root . '/backend/Views/processes/migration.php');
$upgradeView = (string) file_get_contents($root . '/backend/Views/clients/upgrade.php');
$javascript = (string) file_get_contents($root . '/public/assets/js/app.js');
$stylesheet = (string) file_get_contents($root . '/public/assets/css/app.css');
$assert(str_contains($upgradeView, 'Etapa 1 de 4') && str_contains($upgradeView, 'Jornada da migração'), 'Nova condição não está no workspace de quatro etapas.');
$assert(str_contains($migrationView, 'Solicitar assinatura remota') && str_contains($javascript, "reasonInput.value = ''"), 'Motivo remoto não é apagado ao voltar à assinatura local.');
$assert(str_contains($migrationView, 'Atualizar confirmação') && str_contains($migrationView, 'Reenviar confirmação'), 'Estados do aceite não possuem ações distintas.');
$assert(!str_contains($migrationView, '<span>Serviço executado</span>') && str_contains($migrationView, 'Verificar conexão agora'), 'Execução técnica ainda exige campo genérico ou não atualiza PPPoE.');
$assert(str_contains($javascript, "cache: 'no-store'") && str_contains($javascript, 'data-connection-checked'), 'Consulta PPPoE pode reutilizar cache ou não atualizar o bloco.');
$assert(str_contains($migrationView, 'Finalizando atendimento...') && str_contains($migrationView, 'Dry-runs não serão registrados como execução real'), 'Finalização não explicita progresso e bloqueio externo.');
$assert(str_contains($stylesheet, '--font-base: 0.8125rem') && str_contains($stylesheet, '--sidebar-expanded: 150px') && str_contains($stylesheet, 'clamp(270px, 20vw, 292px)'), 'Densidade desktop não atende aos limites finais.');
$assert(str_contains($stylesheet, '@media (max-height: 800px)') && str_contains($stylesheet, '--control-height-mobile: 44px'), 'Altura reduzida ou alvos móveis não foram preservados.');
$assert(preg_match('/\bzoom\s*:/i', $stylesheet) !== 1 && preg_match('/transform\s*:\s*scale\s*\(/i', $stylesheet) !== 1, 'CSS usa escala global para simular densidade.');

echo 'FinalBetaSmoke OK - ' . $checks . " verificações; nenhuma escrita externa.\n";
