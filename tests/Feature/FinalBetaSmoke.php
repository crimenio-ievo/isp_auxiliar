<?php

declare(strict_types=1);

use App\Controllers\ClientController;
use App\Core\Config;
use App\Core\Env;
use App\Core\Request;
use App\Infrastructure\Contracts\ContractAcceptanceRepository;
use App\Infrastructure\Contracts\ContractRepository;
use App\Infrastructure\Contracts\FinancialTaskRepository;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\MkAuth\TechnologyMapper;
use App\Infrastructure\Processes\OperationalProcessRepository;
use App\Services\Commercial\UpgradeBenefitService;
use App\Services\Processes\MigrationJourneyService;
use App\Services\Processes\OperationalProcessService;

require dirname(__DIR__, 2) . '/backend/bootstrap/app.php';

$root = dirname(__DIR__, 2);
$app = bootstrapApplication();
$database = new Database($app->config());
$pdo = $database->pdo();
$local = new LocalRepository($database, (string) Env::get('APP_PROVIDER_KEY', 'default'));
$contracts = new ContractRepository($database);
$acceptances = new ContractAcceptanceRepository($database);
$financialTasks = new FinancialTaskRepository($database);
$processRepository = new OperationalProcessRepository($database, $local);
$processes = new OperationalProcessService($database, $processRepository, $contracts, $acceptances, $financialTasks, $local);
$controller = (new ReflectionClass(ClientController::class))->newInstanceWithoutConstructor();
$reflection = new ReflectionClass(ClientController::class);
$reflection->getProperty('technologyMapper')->setValue($controller, new TechnologyMapper());
$reflection->getProperty('localRepository')->setValue($controller, $local);
$configuredAdhesion = 1375.50;
$configItems = $app->config()->all();
$configItems['contracts']['commercial'] = array_replace(
    (array) ($configItems['contracts']['commercial'] ?? []),
    [
        'valor_adesao_padrao' => $configuredAdhesion,
        'modo_isencao_adesao_migracao_radio_fibra' => 'automatic',
        'isentar_adesao_migracao_radio_fibra' => true,
        'fidelidade_automatica_migracao' => true,
        'fidelidade_automatica_upgrade' => false,
        'fidelidade_meses_padrao' => 12,
    ]
);
$testConfig = new Config($configItems);
$reflection->getProperty('config')->setValue($controller, $testConfig);
$collect = new ReflectionMethod(ClientController::class, 'collectUpgradeFormData');
$validate = new ReflectionMethod(ClientController::class, 'validateUpgrade');
$buildContract = new ReflectionMethod(ClientController::class, 'buildUpgradeContractData');
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
$migrationRequest = static fn (array $data = []): Request => $requestFor(array_replace(['novo_plano' => 'F100'], $data));
$automaticFlags = ['radio_to_fiber', 'adhesion_waiver', 'plan_upgrade'];

$disabledBenefit = new UpgradeBenefitService(new Config([
    'contracts' => [
        'commercial' => [
            'valor_adesao_padrao' => $configuredAdhesion,
            'modo_isencao_adesao_migracao_radio_fibra' => 'disabled',
            'fidelidade_automatica_migracao' => true,
        ],
    ],
]));
$disabledResult = $disabledBenefit->calculate('Rádio', 'Fibra', 'Rádio 10 Mbps', 'Fibra 100 Mbps', 80, 149.90);
$assert((float) $disabledResult['value'] === 0.0 && empty($disabledResult['automatic_fidelity']), 'Modo sem isenção inventou benefício ou fidelidade automática.');

$radioToFiber = $collect->invoke($controller, $migrationRequest(), $context);
$assert(($radioToFiber['operation_type'] ?? '') === 'migration', 'Rádio para Fibra não foi classificado como migração.');
$assert(abs((float) ($radioToFiber['valor_beneficio'] ?? 0) - $configuredAdhesion) < 0.01
    && empty($radioToFiber['benefit_adjusted'])
    && $validate->invoke($controller, $radioToFiber, $context) === [], 'Migração automática não preservou o benefício integral configurado.');
$assert(!empty($radioToFiber['apply_fidelity']) && (int) ($radioToFiber['fidelidade_meses'] ?? 0) === 12, 'Benefício elegível não aplicou a fidelidade configurada.');

$manualAdjusted = $collect->invoke($controller, $migrationRequest([
    'benefit_choices_present' => '1',
    'benefit_flags' => $automaticFlags,
    'valor_beneficio' => '980,40',
    'benefit_adjustment_reason' => 'Condição comercial ajustada e aceita.',
    'fidelity_choice_present' => '1',
    'apply_fidelity' => '1',
    'fidelidade_meses' => '12',
]), $context);
$assert(abs((float) ($manualAdjusted['valor_beneficio'] ?? 0) - 980.40) < 0.01
    && !empty($manualAdjusted['benefit_adjusted'])
    && $validate->invoke($controller, $manualAdjusted, $context) === [], 'Benefício ajustado manualmente não foi preservado ou validado.');

$partialBenefit = $collect->invoke($controller, $migrationRequest([
    'benefit_choices_present' => '1',
    'benefit_flags' => $automaticFlags,
    'valor_beneficio' => '412,35',
    'benefit_adjustment_reason' => 'Benefício parcial aprovado e aceito.',
    'fidelity_choice_present' => '1',
    'apply_fidelity' => '1',
    'fidelidade_meses' => '12',
]), $context);
$assert(abs((float) ($partialBenefit['valor_beneficio'] ?? 0) - 412.35) < 0.01
    && $validate->invoke($controller, $partialBenefit, $context) === [], 'Benefício parcial não foi preservado ou validado.');

$integralBenefit = $collect->invoke($controller, $migrationRequest([
    'benefit_choices_present' => '1',
    'benefit_flags' => $automaticFlags,
    'valor_beneficio' => number_format($configuredAdhesion, 2, ',', ''),
    'fidelity_choice_present' => '1',
    'apply_fidelity' => '1',
    'fidelidade_meses' => '12',
]), $context);
$assert(abs((float) ($integralBenefit['valor_beneficio'] ?? 0) - $configuredAdhesion) < 0.01
    && empty($integralBenefit['benefit_adjusted'])
    && $validate->invoke($controller, $integralBenefit, $context) === [], 'Benefício integral aceito foi tratado como ajuste ou alterado.');

$otherBenefit = $collect->invoke($controller, $migrationRequest([
    'benefit_choices_present' => '1',
    'benefit_flags' => ['radio_to_fiber', 'plan_upgrade', 'other_benefit'],
    'beneficio_outro_text' => 'Crédito de equipamento aprovado',
    'beneficio_outro_valor' => '325,40',
    'valor_beneficio' => '325,40',
    'benefit_adjustment_reason' => 'Benefício alternativo aceito pelo cliente.',
    'fidelity_choice_present' => '1',
    'apply_fidelity' => '1',
    'fidelidade_meses' => '12',
]), $context);
$assert(abs((float) ($otherBenefit['valor_beneficio'] ?? 0) - 325.40) < 0.01
    && abs((float) ($otherBenefit['beneficio_outro_valor'] ?? 0) - 325.40) < 0.01
    && str_contains((string) ($otherBenefit['beneficio_concedido'] ?? ''), 'Crédito de equipamento aprovado')
    && $validate->invoke($controller, $otherBenefit, $context) === [], 'Outro benefício não preservou descrição, valor final e aceite.');

$radioUpgrade = $collect->invoke($controller, $requestFor([
    'novo_plano' => 'R20',
    'valor_beneficio' => (string) $configuredAdhesion,
    'fidelity_choice_present' => '1',
    'apply_fidelity' => '1',
]), $context);
$assert(($radioUpgrade['operation_type'] ?? '') === 'upgrade', 'Rádio para Rádio superior não foi classificado como upgrade.');
$assert((float) ($radioUpgrade['valor_beneficio'] ?? -1) === 0.0, 'Upgrade herdou benefício de migração anterior.');
$assert(empty($radioUpgrade['apply_fidelity']) && (int) ($radioUpgrade['fidelidade_meses'] ?? -1) === 0, 'Plano sem benefício manteve fidelidade.');
$assert($validate->invoke($controller, $radioUpgrade, $context) === [], 'Upgrade sem benefício elegível foi bloqueado.');

$radioToFiberAgain = $collect->invoke($controller, $migrationRequest(), $context);
$assert(abs((float) ($radioToFiberAgain['valor_beneficio'] ?? 0) - $configuredAdhesion) < 0.01, 'Retorno para Fibra não recalculou o benefício configurado.');

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

$persistenceScenarios = [
    'automatic' => [$radioToFiber, $configuredAdhesion],
    'manual_adjusted' => [$manualAdjusted, 980.40],
    'partial' => [$partialBenefit, 412.35],
    'integral' => [$integralBenefit, $configuredAdhesion],
    'other' => [$otherBenefit, 325.40],
];
$pdo->beginTransaction();
try {
    $persistedContracts = [];
    foreach ($persistenceScenarios as $scenario => [$scenarioData, $expectedValue]) {
        $scenarioContext = array_replace($context, [
            'login' => 'final_beta_' . $scenario . '_' . bin2hex(random_bytes(4)),
            'clientProfile' => ['nome' => 'Cliente ' . $scenario, 'celular' => '31999999999'],
            'contract' => [],
            'registration' => [],
        ]);
        $contractData = $buildContract->invoke($controller, $scenarioData, $scenarioContext, $migrationRequest());
        $contractId = (int) ($contracts->create($contractData) ?? 0);
        $persisted = $contracts->findById($contractId) ?? [];
        $snapshot = json_decode((string) ($persisted['upgrade_snapshot_json'] ?? ''), true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $assert($contractId > 0
            && abs((float) ($persisted['beneficio_valor'] ?? 0) - $expectedValue) < 0.01
            && abs((float) ($snapshot['benefit_value'] ?? 0) - $expectedValue) < 0.01,
            'Persistência/releitura alterou o benefício final do cenário ' . $scenario . '.');
        $persistedContracts[$scenario] = [$persisted, $snapshot, $expectedValue];
    }

    [$processContract, $processSnapshot, $processBenefit] = $persistedContracts['partial'];
    $acceptanceId = (int) ($acceptances->create([
        'contract_id' => (int) ($processContract['id'] ?? 0),
        'technician_name' => 'Gestor Beta',
        'technician_login' => 'final.beta.manager',
        'token_hash' => hash('sha256', 'final-beta-benefit-' . (string) ($processContract['id'] ?? 0)),
        'token_expires_at' => date('Y-m-d H:i:s', time() + 3600),
        'status' => 'aceito',
        'telefone_enviado' => '5531999999999',
        'accepted_at' => date('Y-m-d H:i:s'),
        'termo_versao' => 'final-beta-benefit.1',
        'termo_hash' => hash('sha256', 'termo-final-beta-benefit'),
    ]) ?? 0);
    $acceptance = $acceptances->findById($acceptanceId) ?? [];
    $process = $processes->ensureForContract(
        OperationalProcessService::TYPE_MIGRATION,
        $processContract,
        $acceptance,
        ['upgrade_snapshot' => $processSnapshot, 'signature_mode' => 'local'],
        ['login' => 'final.beta.manager', 'name' => 'Gestor Beta', 'role' => 'manager']
    );
    $process = $processes->updateStep((int) ($process['id'] ?? 0), 'technical_execution', 'complete', [
        'observation' => 'Execução sintética sem integração externa.',
        'evidence' => ['simulated' => true],
    ], ['login' => 'final.beta.manager']);
    $process = $processes->updateStep((int) ($process['id'] ?? 0), 'confirm_equipment', 'complete', [
        'observation' => 'Equipamento confirmado sinteticamente.',
        'evidence' => ['simulated' => true],
    ], ['login' => 'final.beta.manager']);
    $reopenedProcess = (new OperationalProcessService(
        $database,
        new OperationalProcessRepository($database, $local),
        $contracts,
        $acceptances,
        $financialTasks,
        $local
    ))->detail((int) ($process['id'] ?? 0)) ?? [];
    $assert(abs((float) ($reopenedProcess['metadata']['migration']['benefit_value'] ?? 0) - $processBenefit) < 0.01,
        'Processo salvo/reaberto alterou o benefício final aceito.');

    $finalizationProjection = (new MigrationJourneyService())->project($reopenedProcess);
    $assert((int) ($finalizationProjection['active_stage'] ?? 0) === 4
        && (string) ($reopenedProcess['next_pending_key'] ?? '') === 'change_plan'
        && abs((float) ($reopenedProcess['metadata']['migration']['benefit_value'] ?? 0) - $processBenefit) < 0.01,
        'Avanço até a finalização alterou o benefício persistido no processo.');
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

$migrationView = (string) file_get_contents($root . '/backend/Views/processes/migration.php');
$upgradeView = (string) file_get_contents($root . '/backend/Views/clients/upgrade.php');
$javascript = (string) file_get_contents($root . '/public/assets/js/app.js');
$stylesheet = (string) file_get_contents($root . '/public/assets/css/app.css');
$assert(str_contains($upgradeView, 'Etapa 1 de 4') && str_contains($upgradeView, 'Jornada da migração'), 'Nova condição não está no workspace de quatro etapas.');
$assert(str_contains($migrationView, 'Solicitar assinatura remota') && str_contains($javascript, "reasonInput.value = ''"), 'Motivo remoto não é apagado ao voltar à assinatura local.');
$assert(str_contains($migrationView, 'Verificar confirmação') && str_contains($migrationView, 'Reenviar confirmação'), 'Estados do aceite não possuem ações distintas no menu.');
$assert(!str_contains($migrationView, '<span>Serviço executado</span>') && str_contains($migrationView, 'Verificar conexão agora'), 'Execução técnica ainda exige campo genérico ou não atualiza PPPoE.');
$assert(str_contains($javascript, "cache: 'no-store'") && str_contains($javascript, 'data-connection-checked'), 'Consulta PPPoE pode reutilizar cache ou não atualizar o bloco.');
$assert(str_contains($migrationView, 'Concluindo migração...') && str_contains($migrationView, 'Dry-runs não serão registrados como execução real'), 'Finalização não explicita progresso e bloqueio externo.');
$assert(str_contains($stylesheet, '--font-base: 0.8125rem') && str_contains($stylesheet, '--sidebar-expanded: 150px') && str_contains($stylesheet, 'clamp(270px, 20vw, 292px)'), 'Densidade desktop não atende aos limites finais.');
$assert(str_contains($stylesheet, '@media (max-height: 800px)') && str_contains($stylesheet, '--control-height-mobile: 44px'), 'Altura reduzida ou alvos móveis não foram preservados.');
$assert(preg_match('/\bzoom\s*:/i', $stylesheet) !== 1 && preg_match('/transform\s*:\s*scale\s*\(/i', $stylesheet) !== 1, 'CSS usa escala global para simular densidade.');

echo 'FinalBetaSmoke OK - ' . $checks . " verificações; nenhuma escrita externa.\n";
