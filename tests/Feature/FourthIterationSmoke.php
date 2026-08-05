<?php

declare(strict_types=1);

use App\Controllers\ClientController;
use App\Core\Config;
use App\Core\Request;
use App\Core\Env;
use App\Core\View;
use App\Infrastructure\Contracts\ContractAcceptanceRepository;
use App\Infrastructure\Contracts\ContractRepository;
use App\Infrastructure\Contracts\FinancialTaskRepository;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\MkAuth\MkAuthWriteGuard;
use App\Infrastructure\MkAuth\TechnologyMapper;
use App\Infrastructure\Processes\OperationalProcessRepository;
use App\Services\Contracts\AcceptanceWorkflowService;
use App\Services\Processes\OperationalProcessService;
use App\Services\Releases\ReleaseChannelService;

require dirname(__DIR__, 2) . '/backend/bootstrap/app.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$rootPath = dirname(__DIR__, 2);
$app = bootstrapApplication();
$database = new Database($app->config());
$pdo = $database->pdo();
$local = new LocalRepository($database, (string) Env::get('APP_PROVIDER_KEY', 'default'));
$contracts = new ContractRepository($database);
$acceptances = new ContractAcceptanceRepository($database);
$financialTasks = new FinancialTaskRepository($database);
$processes = new OperationalProcessRepository($database, $local);
$processService = new OperationalProcessService($database, $processes, $contracts, $acceptances, $financialTasks, $local);
$acceptanceWorkflow = new AcceptanceWorkflowService($app->config());
$view = new View((string) $app->config()->get('paths.views'));
$checks = [];

$check = static function (int $number, bool $condition, string $message) use (&$checks): void {
    $expected = count($checks) + 1;
    if ($number !== $expected) {
        throw new RuntimeException("Ordem de verificação inválida: esperado {$expected}, recebido {$number}.");
    }
    if (!$condition) {
        throw new RuntimeException("Verificação {$number} falhou: {$message}");
    }
    $checks[$number] = $message;
};

$contractData = static function (string $login, string $type = 'upgrade_migracao', array $overrides = []): array {
    return array_replace([
        'client_id' => null,
        'mkauth_login' => $login,
        'technician_name' => 'Operador quarta iteração',
        'technician_login' => 'teste.quarta',
        'nome_cliente' => 'Cliente sintético quarta iteração',
        'telefone_cliente' => '31999991111',
        'tipo_adesao' => 'isenta',
        'valor_adesao' => 0,
        'parcelas_adesao' => 1,
        'valor_parcela_adesao' => 0,
        'vencimento_primeira_parcela' => null,
        'fidelidade_meses' => 0,
        'beneficio_valor' => 0,
        'multa_total' => 0,
        'tipo_aceite' => $type,
        'observacao_adesao' => 'Cenário sintético revertido por rollback.',
        'upgrade_snapshot_json' => $type === 'upgrade_migracao' ? json_encode([
            'operation_type' => 'migration',
            'current_plan_id' => 'radio-10',
            'current_plan_name' => 'Rádio 10',
            'current_technology' => 'Rádio fixo (FWA)',
            'current_technology_family' => 'radio',
            'current_monthly_value' => 80,
            'new_plan_id' => 'fibra-100',
            'new_plan_name' => 'Fibra 100',
            'new_technology' => 'Fibra até o imóvel (FTTH)',
            'new_technology_family' => 'fibra',
            'new_monthly_value' => 100,
            'adhesion_default_value' => 1200,
            'adhesion_charged_value' => 0,
            'benefit_value' => 1200,
            'fidelity_months' => 0,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        'status_financeiro' => 'dispensado',
        'lifecycle_status' => 'active',
        'supersedes_contract_id' => null,
        'superseded_by_contract_id' => null,
        'revision_number' => 1,
        'cancellation_reason' => null,
        'cancelled_at' => null,
        'cancelled_by_user_id' => null,
        'cancelled_by_login' => null,
    ], $overrides);
};

$createAcceptance = static function (int $contractId, string $version = 'test.fourth.1') use ($acceptanceWorkflow, $acceptances): array {
    $prepared = $acceptanceWorkflow->prepare([
        'contract_id' => $contractId,
        'technician_name' => 'Operador quarta iteração',
        'technician_login' => 'teste.quarta',
        'signature_mode' => 'local',
        'status' => 'criado',
        'phone' => '31999991111',
        'document_version' => $version,
        'term_hash' => hash('sha256', $version . ':' . $contractId),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'FourthIterationSmoke',
    ]);
    $acceptanceId = (int) ($acceptances->create($prepared) ?? 0);

    return $acceptances->findById($acceptanceId) ?? array_merge($prepared, ['id' => $acceptanceId]);
};

$baseViewData = static function (string $login, array $operationalProcesses): array {
    $activeProcess = null;
    foreach ($operationalProcesses as $candidate) {
        if (is_array($candidate) && !in_array((string) ($candidate['status'] ?? ''), ['completed', 'cancelled'], true)) {
            $activeProcess = $candidate;
            break;
        }
    }

    return [
        'pageTitle' => 'Cliente',
        'currentPath' => '/clientes/detalhe',
        'basePath' => '',
        'appName' => 'ISP Auxiliar',
        'user' => ['name' => 'Gestor', 'role' => 'manager', 'access' => ['can_use_beta' => true]],
        'flash' => null,
        'detail' => [
            'login' => $login,
            'profile' => [
                'name' => 'Cliente Teste',
                'short_name' => '',
                'document' => '12345678901',
                'status_visual' => ['label' => 'Ativo', 'class' => 'client-status-active'],
                'plan' => 'Plano atual',
                'technology' => 'Rádio fixo (FWA)',
                'technology_detail' => ['verified' => true, 'code' => 'D'],
                'phones' => ['31999991111', '3133332222'],
                'phone_contacts' => [
                    ['label' => 'Principal', 'value' => '31999991111'],
                    ['label' => 'Alternativo 1', 'value' => '3133332222'],
                ],
                'emails' => ['cliente@example.test'],
                'actions' => [
                    ['type' => 'phone', 'label' => 'Ligar · Principal', 'value' => '31999991111', 'url' => 'tel:+5531999991111'],
                    ['type' => 'phone', 'label' => 'Ligar · Alternativo 1', 'value' => '3133332222', 'url' => 'tel:+553133332222'],
                ],
                'address' => 'Rua de Teste, 100',
                'monthly_value' => '80',
                'due_day' => '10',
            ],
            'clientProfile' => ['nome' => 'Cliente Teste', 'cidade' => 'Coimbra', 'estado' => 'MG'],
            'contracts' => [],
            'digitalContract' => [],
            'operationalProcesses' => $operationalProcesses,
            'activeProcess' => $activeProcess,
            'activeFinancialTask' => [],
            'migrationAction' => [],
            'timeline' => [['label' => 'Cliente confirmou o aceite.', 'description' => '', 'time' => '2026-07-31 13:38:00']],
            'acceptanceHistory' => [],
            'financialTask' => [],
            'scannedDocuments' => [],
        ],
        'canRequestUpgrade' => true,
        'canRequestContractSignature' => false,
        'canManageFinancial' => true,
    ];
};

$renderMigration = static function (View $view, array $process, array $contract, array $acceptance, string $stepKey): string {
    $steps = array_values((array) ($process['steps'] ?? []));
    $activeIndex = 0;
    foreach ($steps as $index => $step) {
        if ((string) ($step['step_key'] ?? '') === $stepKey) {
            $activeIndex = $index;
            break;
        }
    }

    return $view->render('processes/migration', [
        'pageTitle' => 'Migração',
        'currentPath' => '/processos/migracao',
        'basePath' => '',
        'appName' => 'ISP Auxiliar',
        'user' => ['name' => 'Gestor', 'role' => 'manager', 'access' => ['can_use_beta' => true]],
        'flash' => null,
        'process' => $process,
        'activeStep' => $steps[$activeIndex] ?? [],
        'previousStep' => $activeIndex > 0 ? ($steps[$activeIndex - 1] ?? null) : null,
        'nextStep' => $steps[$activeIndex + 1] ?? null,
        'contract' => $contract,
        'acceptance' => $acceptance,
        'financialTask' => [],
        'connection' => [],
        'dryRun' => null,
        'csrfToken' => 'csrf-sintetico',
        'canOverride' => true,
    ]);
};

$operator = ['id' => null, 'login' => 'teste.quarta', 'name' => 'Gestor teste', 'role' => 'manager'];
$_SESSION['user'] = $operator;

try {
    $pdo->beginTransaction();

    $login = 'fourth_' . bin2hex(random_bytes(5));
    $contractId = (int) $contracts->create($contractData($login));
    $contract = $contracts->findById($contractId) ?? [];
    $acceptance = $createAcceptance($contractId);
    $activeProcess = $processService->ensureForContract(
        OperationalProcessService::TYPE_MIGRATION,
        $contract,
        $acceptance,
        ['signature_mode' => 'local'],
        $operator
    );
    $activeHtml = $view->render('clients/detail', $baseViewData($login, [$activeProcess]));
    $check(1, substr_count($activeHtml, '<section class="client-process-panel ') === 1, 'processo ativo apresenta painel único');
    $check(2, str_contains($activeHtml, 'client-process-panel--warning'), 'processo aguardando usa estado amarelo');

    $cancelled = $processService->cancelProcess((int) $activeProcess['id'], $operator, 'Cancelamento de homologação.');
    $cancelledHtml = $view->render('clients/detail', $baseViewData($login, [$cancelled]));
    $cancelledAcceptance = $acceptances->findById((int) $acceptance['id']) ?? [];
    $check(3, !str_contains($cancelledHtml, 'Aguardando confirmação do cliente') && !str_contains($cancelledHtml, '<section class="client-process-panel '), 'cancelado não aparece aguardando');
    $clientControllerSource = (string) file_get_contents($rootPath . '/backend/Controllers/ClientController.php');
    $contractViewSource = (string) file_get_contents($rootPath . '/backend/Views/contracts/detalhe.php');
    $check(4, trim((string) ($cancelledAcceptance['revoked_at'] ?? '')) !== ''
        && str_contains($clientControllerSource, "Csrf::verify(\$request, 'client_upgrade_cancel:' . \$contractId)")
        && str_contains($contractViewSource, "Csrf::field('client_upgrade_cancel:' . \$contractId)"), 'cancelamento revoga o aceite e exige CSRF');
    $check(5, empty($cancelled['next_pending_key']) && empty($cancelled['next_pending_label']), 'cancelamento remove próxima pendência');
    $check(6, count($processService->listByLogin($login)) === 1, 'histórico do processo cancelado permanece');

    $completedSynthetic = array_replace($activeProcess, ['status' => 'completed', 'status_label' => 'Concluído']);
    $completedHtml = $view->render('clients/detail', $baseViewData($login, [$completedSynthetic]));
    $check(7, !str_contains($completedHtml, '<section class="client-process-panel '), 'processo concluído não aparece como ativo');
    $completedRequired = count(array_filter((array) ($cancelled['steps'] ?? []), static fn (array $step): bool => !empty($step['is_required']) && in_array((string) ($step['status'] ?? ''), ['completed', 'not_applicable'], true)));
    $check(8, (int) ($cancelled['progress_completed'] ?? -1) === $completedRequired && (int) ($cancelled['progress_total'] ?? 0) === 11, 'progresso é recalculado pela fonte de etapas');

    $acceptedLogin = $login . '_accepted';
    $acceptedContractId = (int) $contracts->create($contractData($acceptedLogin));
    $acceptedContract = $contracts->findById($acceptedContractId) ?? [];
    $acceptedAcceptance = $createAcceptance($acceptedContractId, 'test.fourth.accepted');
    $acceptedProcess = $processService->ensureForContract(OperationalProcessService::TYPE_MIGRATION, $acceptedContract, $acceptedAcceptance, ['signature_mode' => 'local'], $operator);
    $acceptances->markAccepted((int) $acceptedAcceptance['id'], '127.0.0.1', 'FourthIterationSmoke');
    $processService->reconcileState((int) $acceptedProcess['id']);
    $acceptedProcess = $processService->detail((int) $acceptedProcess['id']) ?? [];
    $acceptedAcceptance = $acceptances->findById((int) $acceptedAcceptance['id']) ?? [];
    $acceptedStepStatus = array_column((array) $acceptedProcess['steps'], 'status', 'step_key');
    $check(9, ($acceptedStepStatus['prepare_document'] ?? '') === 'completed' && ($acceptedStepStatus['send_acceptance'] ?? '') === 'completed' && ($acceptedStepStatus['confirm_acceptance'] ?? '') === 'completed', 'aceite confirmado conclui pré-requisitos coerentes');

    $controller = (new ReflectionClass(ClientController::class))->newInstanceWithoutConstructor();
    $controllerReflection = new ReflectionClass(ClientController::class);
    $controllerReflection->getProperty('technologyMapper')->setValue($controller, new TechnologyMapper());
    $controllerReflection->getProperty('localRepository')->setValue($controller, $local);
    $controllerReflection->getProperty('config')->setValue($controller, $app->config());
    $plans = [
        ['id' => 'UUID-R10', 'name' => 'Rádio 10', 'label' => 'Rádio 10 — 10 Mbps — R$ 80,00', 'technology' => 'D', 'technology_label' => 'Rádio fixo (FWA)', 'install_type' => 'radio', 'speed_down' => '10M', 'value' => '80.00'],
        ['id' => 'UUID-R20', 'name' => 'Rádio 20', 'label' => 'Rádio 20 — 20 Mbps — R$ 90,00', 'technology' => 'D', 'technology_label' => 'Rádio fixo (FWA)', 'install_type' => 'radio', 'speed_down' => '20M', 'value' => '90.00'],
        ['id' => 'UUID-F100', 'name' => 'Fibra 100', 'label' => 'Fibra 100 — 100 Mbps — R$ 100,00', 'technology' => 'H', 'technology_label' => 'Fibra até o imóvel (FTTH)', 'install_type' => 'fibra', 'speed_down' => '100M', 'value' => '100.00'],
    ];
    $upgradeContext = ['login' => $login, 'clientProfile' => ['nome' => 'Cliente Teste'], 'current_plan' => 'UUID-R10', 'current_technology' => 'Rádio fixo (FWA)', 'current_technology_family' => 'radio', 'current_monthly_value' => 80, 'planOptions' => $plans, 'adhesion_default_value' => 1200, 'adhesion_waiver_mode' => 'automatic'];
    $upgradeData = ['operation_type' => 'migration', 'plano_atual' => 'UUID-R10', 'current_plan_id' => 'UUID-R10', 'current_plan_name' => 'Rádio 10', 'tecnologia_atual' => 'Rádio fixo (FWA)', 'novo_plano' => 'UUID-F100', 'new_plan_id' => 'UUID-F100', 'new_plan_name' => 'Fibra 100', 'nova_tecnologia' => 'Fibra até o imóvel (FTTH)', 'novo_valor_mensal' => 100, 'valor_mensal_atual' => 80, 'benefit_flags' => ['radio_to_fiber' => true, 'adhesion_waiver' => true], 'valor_beneficio' => 1200, 'beneficio_outro_text' => '', 'retention_condition' => false, 'apply_fidelity' => false, 'fidelity_benefit_description' => '', 'fidelidade_meses' => 0, 'observacao' => ''];
    $validateUpgrade = new ReflectionMethod(ClientController::class, 'validateUpgrade');
    $validUpgradeErrors = $validateUpgrade->invoke($controller, $upgradeData, $upgradeContext);
    $upgradeHtml = $view->render('clients/upgrade', ['pageTitle' => 'Nova condição', 'currentPath' => '/clientes/upgrade', 'basePath' => '', 'appName' => 'ISP Auxiliar', 'user' => ['name' => 'Gestor', 'role' => 'manager'], 'flash' => null, 'context' => $upgradeContext, 'form' => $upgradeData, 'errors' => [], 'csrfToken' => 'csrf']);
    preg_match('/<option value="UUID-F100"[^>]*>([^<]+)<\/option>/', $upgradeHtml, $planOptionMatch);
    $check(10, str_contains($upgradeHtml, 'value="UUID-F100"') && str_contains($upgradeHtml, 'selected'), 'plano pode ser selecionado');
    $check(11, $validUpgradeErrors === [], 'seleção válida permite prosseguir');
    $samePlanErrors = $validateUpgrade->invoke($controller, array_replace($upgradeData, ['operation_type' => '', 'novo_plano' => 'UUID-R10', 'new_plan_id' => 'UUID-R10', 'new_plan_name' => 'Rádio 10', 'nova_tecnologia' => 'Rádio fixo (FWA)', 'novo_valor_mensal' => 80]), $upgradeContext);
    $check(12, isset($samePlanErrors['novo_plano']), 'plano atual não gera mudança sem justificativa');
    $check(13, isset($planOptionMatch[1]) && str_contains($planOptionMatch[1], 'Fibra 100') && !str_contains($planOptionMatch[1], 'UUID') && !str_contains($planOptionMatch[1], 'FTTH'), 'label visível do plano é simplificado');
    $check(14, str_contains($upgradeHtml, 'data-plan-technology="Fibra até o imóvel (FTTH)"') && str_contains($upgradeHtml, 'value="UUID-F100"'), 'metadados internos do plano são preservados');
    $operation = new ReflectionMethod(ClientController::class, 'determineUpgradeOperation');
    $check(15, $operation->invoke($controller, 'UUID-R10', 'UUID-F100', 'radio', 'fibra', $plans[0], $plans[1], 80.0, 100.0) === 'migration', 'operação é calculada');
    $check(16, str_contains($upgradeHtml, 'data-plan-family="fibra"') && str_contains($upgradeHtml, 'data-plan-technology='), 'tecnologia é calculada e mantida como metadado');
    $check(17, str_contains($upgradeHtml, 'data-plan-value="100.00"'), 'valor do plano é calculável pelo formulário');
    $upgradeErrorHtml = $view->render('clients/upgrade', ['pageTitle' => 'Nova condição', 'currentPath' => '/clientes/upgrade', 'basePath' => '', 'appName' => 'ISP Auxiliar', 'user' => ['name' => 'Gestor', 'role' => 'manager'], 'flash' => null, 'context' => $upgradeContext, 'form' => $upgradeData, 'errors' => ['novo_plano' => 'Plano inválido para o teste.'], 'csrfToken' => 'csrf']);
    $check(18, preg_match('/<option value="UUID-F100"[^>]*selected/', $upgradeErrorHtml) === 1, 'formulário preserva plano após erro');
    $check(19, str_contains($upgradeErrorHtml, 'data-focus-field'), 'erro define foco lógico no campo inválido');

    $configuredAdhesion = (float) $app->config()->get('contracts.commercial.valor_adesao_padrao', 0);
    $benefitDefaultsMethod = new ReflectionMethod(ClientController::class, 'resolveUpgradeBenefitDefaults');
    $benefitDefaults = $benefitDefaultsMethod->invoke($controller, 'Rádio fixo (FWA)', 'Fibra até o imóvel (FTTH)', 'Rádio 10', 'Fibra 100', 80.0, 100.0);
    $check(20, $configuredAdhesion > 0 && str_contains($upgradeHtml, 'data-adhesion-default="' . $configuredAdhesion . '"'), 'adesão vem da configuração');
    $collectUpgrade = new ReflectionMethod(ClientController::class, 'collectUpgradeFormData');
    $tamperedBenefitData = $collectUpgrade->invoke($controller, new Request('POST', '/clientes/upgrade', '', [], [
        'novo_plano' => 'UUID-R20',
        'benefit_flags' => json_encode(['radio_to_fiber' => true, 'adhesion_waiver' => true]),
    ]), $upgradeContext);
    $check(21, !empty($benefitDefaults['flags']['radio_to_fiber']) && !empty($benefitDefaults['flags']['adhesion_waiver'])
        && empty($tamperedBenefitData['benefit_flags']['radio_to_fiber'])
        && empty($tamperedBenefitData['benefit_flags']['adhesion_waiver']), 'rádio para fibra aplica isenção configurada sem confiar em metadados enviados pelo formulário');
    $check(22, abs((float) ($benefitDefaults['value'] ?? 0) - $configuredAdhesion) < 0.01, 'benefício corresponde à adesão');
    $check(23, !preg_match('/valor_adesao[^\n]*1200|1200[^\n]*valor_adesao/i', $clientControllerSource), 'não há valor de adesão 1200 hardcoded no controller');
    $disabledConfig = new Config(['contracts' => ['commercial' => ['modo_isencao_adesao_migracao_radio_fibra' => 'disabled', 'valor_adesao_padrao' => $configuredAdhesion]]]);
    $controllerReflection->getProperty('config')->setValue($controller, $disabledConfig);
    $disabledBenefit = $benefitDefaultsMethod->invoke($controller, 'Rádio fixo (FWA)', 'Fibra até o imóvel (FTTH)', 'Rádio 10', 'Fibra 100', 80.0, 100.0);
    $controllerReflection->getProperty('config')->setValue($controller, $app->config());
    $check(24, empty($disabledBenefit['flags']['adhesion_waiver']) && (float) ($disabledBenefit['value'] ?? -1) === 0.0, 'configuração desabilitada não aplica isenção');

    $check(25, str_contains($upgradeHtml, 'Condição comercial de retenção') && str_contains($upgradeHtml, '<legend>Fidelidade</legend>'), 'retenção permanece separada da operação e fidelidade');
    $fidelityOffHtml = $view->render('clients/upgrade', ['pageTitle' => 'Nova condição', 'currentPath' => '/clientes/upgrade', 'basePath' => '', 'appName' => 'ISP Auxiliar', 'user' => ['name' => 'Gestor', 'role' => 'manager'], 'flash' => null, 'context' => $upgradeContext, 'form' => array_replace($upgradeData, ['apply_fidelity' => false]), 'errors' => [], 'csrfToken' => 'csrf']);
    $check(26, preg_match('/name="fidelity_benefit_description"[^>]*disabled/', $fidelityOffHtml) === 1 && preg_match('/name="fidelidade_meses"[^>]*disabled/', $fidelityOffHtml) === 1, 'fidelidade desativada não envia campos ocultos');
    $fidelityErrors = $validateUpgrade->invoke($controller, array_replace($upgradeData, ['apply_fidelity' => true, 'valor_beneficio' => 0, 'fidelity_benefit_description' => '', 'fidelidade_meses' => 12]), $upgradeContext);
    $check(27, isset($fidelityErrors['valor_beneficio'], $fidelityErrors['fidelity_benefit_description']), 'fidelidade exige benefício real');
    $rangeErrors = $validateUpgrade->invoke($controller, array_replace($upgradeData, ['apply_fidelity' => true, 'valor_beneficio' => 100, 'fidelity_benefit_description' => 'Benefício real', 'fidelidade_meses' => 13]), $upgradeContext);
    $collectedOutOfRange = $collectUpgrade->invoke($controller, new Request('POST', '/clientes/upgrade', '', [], [
        'novo_plano' => 'UUID-F100',
        'apply_fidelity' => '1',
        'valor_beneficio' => '100',
        'fidelity_benefit_description' => 'Benefício real',
        'fidelidade_meses' => '13',
    ]), $upgradeContext);
    $collectedRangeErrors = $validateUpgrade->invoke($controller, $collectedOutOfRange, $upgradeContext);
    $check(28, isset($rangeErrors['fidelidade_meses'])
        && (int) ($collectedOutOfRange['fidelidade_meses'] ?? 0) === 13
        && isset($collectedRangeErrors['fidelidade_meses']), 'prazo inválido é preservado e rejeitado entre a coleta e a validação');
    $check(29, str_contains($upgradeHtml, 'for="apply-fidelity"') && str_contains($upgradeHtml, 'for="fidelity-description"') && str_contains($upgradeHtml, 'for="fidelity-months"'), 'campos de fidelidade possuem labels associados');

    $revisionLogin = $login . '_revision';
    $revisionContractId = (int) $contracts->create($contractData($revisionLogin));
    $revisionContract = $contracts->findById($revisionContractId) ?? [];
    $oldRevisionAcceptance = $createAcceptance($revisionContractId, 'test.fourth.revision.1');
    $revisionProcess = $processService->ensureForContract(OperationalProcessService::TYPE_MIGRATION, $revisionContract, $oldRevisionAcceptance, ['signature_mode' => 'local'], $operator);
    $pendingWorkspace = $renderMigration($view, $revisionProcess, $revisionContract, $oldRevisionAcceptance, 'migration_data');
    $check(30, str_contains($pendingWorkspace, 'corrigida no mesmo processo') && str_contains($pendingWorkspace, 'process_id=' . (int) $revisionProcess['id']), 'aceite pendente permite corrigir o processo atual');
    $processCountBeforeRevision = (int) ($database->fetchOne('SELECT COUNT(*) AS total FROM operational_processes WHERE mkauth_login = :login', ['login' => $revisionLogin])['total'] ?? 0);
    $acceptances->revoke((int) $oldRevisionAcceptance['id'], 'Revisão sintética.', null, 'teste.quarta', false);
    $newRevisionAcceptance = $createAcceptance($revisionContractId, 'test.fourth.revision.2');
    $revisionContract['revision_number'] = 2;
    $revisedProcess = $processService->reviseMigration((int) $revisionProcess['id'], $revisionContract, $newRevisionAcceptance, ['upgrade_snapshot' => json_decode((string) $revisionContract['upgrade_snapshot_json'], true), 'revision_reason' => 'Revisão sintética.'], $operator);
    $processCountAfterRevision = (int) ($database->fetchOne('SELECT COUNT(*) AS total FROM operational_processes WHERE mkauth_login = :login', ['login' => $revisionLogin])['total'] ?? 0);
    $check(31, (int) $revisedProcess['id'] === (int) $revisionProcess['id'] && $processCountBeforeRevision === 1 && $processCountAfterRevision === 1, 'correção pendente não cria processo duplicado');
    $oldRevisionAcceptance = $acceptances->findById((int) $oldRevisionAcceptance['id']) ?? [];
    $check(32, trim((string) ($oldRevisionAcceptance['revoked_at'] ?? '')) !== '', 'correção revoga o token anterior');
    $acceptedWorkspace = $renderMigration($view, $acceptedProcess, $acceptedContract, $acceptedAcceptance, 'migration_data');
    $check(33, str_contains($acceptedWorkspace, 'Esta condição já foi aceita')
        && str_contains($acceptedWorkspace, 'Substituir condição')
        && str_contains($acceptedWorkspace, 'name="_csrf"')
        && str_contains($clientControllerSource, "Csrf::verify(\$request, 'client_upgrade_correct:' . \$contractId)"), 'aceite confirmado exige substituição protegida por CSRF');
    $newContractId = (int) $contracts->create($contractData($login, 'upgrade_migracao', ['revision_number' => 2]));
    $newContract = $contracts->findById($newContractId) ?? [];
    $newAcceptance = $createAcceptance($newContractId, 'test.fourth.after-cancel');
    $newProcess = $processService->ensureForContract(OperationalProcessService::TYPE_MIGRATION, $newContract, $newAcceptance, ['signature_mode' => 'local'], $operator);
    $check(34, (int) $newProcess['id'] !== (int) $cancelled['id'] && (string) $newProcess['status'] !== 'cancelled', 'processo cancelado permite iniciar novo processo');

    $check(35, str_contains($activeHtml, 'Principal</small>') && str_contains($activeHtml, 'Ligar · Principal'), 'telefone principal é identificado');
    $check(36, str_contains($activeHtml, 'Alternativo 1</small>') && str_contains($activeHtml, 'Ligar · Alternativo 1'), 'telefones alternativos são identificados');
    $check(37, substr_count($activeHtml, 'class="client-detail-panel__dialog" role="dialog" aria-modal="true"') === 1
        && substr_count($activeHtml, 'data-client-panel-template=') === 4
        && str_contains($activeHtml, 'data-client-modal hidden aria-hidden="true" inert'), 'modal central único inicia fechado e possui quatro conteúdos explícitos');
    $appJs = (string) file_get_contents($rootPath . '/public/assets/js/app.js');
    $check(38, str_contains($appJs, "target.closest('[data-close-client-panel]')")
        && str_contains($appJs, "event.key === 'Escape'")
        && str_contains($appJs, "clientHub.querySelector('[data-client-modal]')")
        && str_contains($appJs, "document.removeEventListener('keydown', handleClientModalKeydown)")
        && str_contains($appJs, 'document.activeElement === dialog'), 'modal fecha por controle/Escape e mantém Tab no diálogo');
    $check(39, str_contains($appJs, 'returnFocus.focus({ preventScroll: true })'), 'foco retorna ao cartão de origem');
    $check(40, substr_count($activeHtml, '<section class="client-process-panel ') === 1 && !str_contains($activeHtml, 'alert alert--warning" id="upgrade-process'), 'cliente exibe apenas um alerta de processo');
    $check(41, !str_contains($contractViewSource, 'Abrir configurações') && str_contains($contractViewSource, 'Ver eventos'), 'detalhe do contrato não exibe botão de configurações');
    $translateAudit = new ReflectionMethod(ClientController::class, 'translateAuditEvent');
    $translatedEvent = $translateAudit->invoke($controller, 'contract.acceptance.accepted', ['actor_login' => 'vanessa']);
    $check(42, ($translatedEvent['label'] ?? '') === 'Cliente confirmou o aceite.' && str_contains((string) ($translatedEvent['description'] ?? ''), 'vanessa'), 'eventos técnicos são traduzidos sem inventar responsável');

    $releaseConfig = new Config(['app' => ['release' => ['channel' => 'stable', 'stable_base_url' => 'https://stable.example.test/isp', 'beta_base_url' => 'https://beta.example.test/isp']]]);
    $releaseService = new ReleaseChannelService($releaseConfig, $local);
    $check(43, $releaseService->currentChannel() === 'stable' && $releaseService->preference(['login' => 'release.viewer', 'role' => 'viewer']) === 'stable', 'Stable é o canal atual');
    $betaDenied = false;
    try {
        $releaseService->resolveSwitch(['login' => 'release.viewer', 'role' => 'viewer'], 'beta');
    } catch (RuntimeException) {
        $betaDenied = true;
    }
    $check(44, $betaDenied, 'Beta exige permissão');
    $check(45, $releaseService->destination('beta') === 'https://beta.example.test/isp', 'URL do canal vem somente da configuração');
    $releaseResult = $releaseService->resolveSwitch(['login' => 'release.manager', 'role' => 'manager'], 'beta');
    $check(46, ($releaseResult['destination'] ?? '') === 'https://beta.example.test/isp' && !empty($releaseResult['redirect']), 'troca resolve o canal sem persistir preferência');

    $autoloadPath = var_export($rootPath . '/backend/bootstrap/autoload.php', true);
    $layoutPath = var_export($rootPath . '/backend/Views/layouts/app.php', true);
    $betaScript = 'require ' . $autoloadPath . '; define("APP_RELEASE_INFO", ["channel" => "beta"]); $pageTitle="Teste"; $appName="Teste"; $layoutMode="guest"; $hideHeader=true; $hideFooter=true; $content="ok"; require ' . $layoutPath . ';';
    $stableScript = 'require ' . $autoloadPath . '; define("APP_RELEASE_INFO", ["channel" => "stable"]); $pageTitle="Teste"; $appName="Teste"; $layoutMode="guest"; $hideHeader=true; $hideFooter=true; $content="ok"; require ' . $layoutPath . ';';
    $betaOutput = (string) shell_exec(PHP_BINARY . ' -r ' . escapeshellarg($betaScript));
    $stableOutput = (string) shell_exec(PHP_BINARY . ' -r ' . escapeshellarg($stableScript));
    $check(47, str_contains($betaOutput, 'AMBIENTE BETA') && str_contains($betaOutput, 'recursos em homologação'), 'banner Beta aparece');
    $check(48, !str_contains($stableOutput, 'AMBIENTE BETA'), 'Stable não mostra banner Beta');
    $unsafeRelease = new ReleaseChannelService(new Config(['app' => ['release' => ['channel' => 'stable', 'beta_base_url' => 'https://usuario:senha@evil.example.test/redirecionar']]]), $local);
    $check(49, $unsafeRelease->destination('beta') === '', 'open redirect e URL com credencial são bloqueados');

    $legacyProcess = $processService->detail((int) $acceptedProcess['id']);
    $check(50, is_array($legacyProcess) && count((array) ($legacyProcess['steps'] ?? [])) === 11, 'processo antigo continua legível');
    $check(51, (int) (($acceptances->findById((int) $acceptedAcceptance['id'])['id'] ?? 0)) === (int) $acceptedAcceptance['id'], 'aceite antigo continua acessível');
    $check(52, (int) (($contracts->findById($acceptedContractId)['id'] ?? 0)) === $acceptedContractId, 'contrato anterior continua acessível');
    $installationContractId = (int) $contracts->create($contractData($login . '_install', 'nova_instalacao'));
    $installationContract = $contracts->findById($installationContractId) ?? [];
    $installationAcceptance = $createAcceptance($installationContractId, 'test.fourth.installation');
    $installation = $processService->ensureForContract(OperationalProcessService::TYPE_INSTALLATION, $installationContract, $installationAcceptance, ['signature_mode' => 'local'], $operator);
    $check(53, count((array) ($installation['steps'] ?? [])) === 11, 'instalação não é afetada');
    $signatureContractId = (int) $contracts->create($contractData($login . '_signature', 'contrato_digital'));
    $signatureContract = $contracts->findById($signatureContractId) ?? [];
    $signatureAcceptance = $createAcceptance($signatureContractId, 'test.fourth.signature');
    $signature = $processService->ensureForContract(OperationalProcessService::TYPE_STANDALONE_SIGNATURE, $signatureContract, $signatureAcceptance, ['signature_mode' => 'local'], $operator);
    $check(54, count((array) ($signature['steps'] ?? [])) === 8, 'assinatura avulsa não é afetada');
    $guard = new MkAuthWriteGuard('test', false);
    $writeBlocked = false;
    try {
        $guard->assertAllowed('quarta iteração');
    } catch (RuntimeException $exception) {
        $writeBlocked = $exception->getMessage() === MkAuthWriteGuard::BLOCKED_MESSAGE;
    }
    $check(55, !(bool) $app->config()->get('app.mkauth.write_enabled', false) && $writeBlocked, 'nenhuma escrita real no MkAuth é permitida');
    $check(56, (bool) $app->config()->get('evotrix.dry_run', false) && (bool) $app->config()->get('email.dry_run', false) && (bool) $app->config()->get('contracts.mkauth_ticket.dry_run', false), 'notificações e chamado permanecem em dry-run');
    $trackedFiles = preg_split('/\R+/', trim((string) shell_exec('git ls-files')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $aiFiles = array_filter($trackedFiles, static fn (string $file): bool => preg_match('/(^|[\/_-])(openai|integracao[-_]?ia|ai[-_]?integration)([\/_.-]|$)/i', $file) === 1);
    $check(57, $aiFiles === [], 'nenhum arquivo da IA está rastreado');

    if (count($checks) !== 57) {
        throw new RuntimeException('A suíte não executou as 57 categorias obrigatórias.');
    }

    $pdo->rollBack();
    echo "OK - 57 verificações da quarta iteração; rollback concluído; nenhuma escrita externa ou notificação real.\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'FALHOU - ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
