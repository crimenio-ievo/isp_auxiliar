<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Env;
use App\Infrastructure\Contracts\ContractAcceptanceRepository;
use App\Infrastructure\Contracts\ContractRepository;
use App\Infrastructure\Contracts\FinancialTaskRepository;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\Processes\OperationalProcessRepository;
use App\Services\Processes\MigrationEvidenceService;
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
$financial = new FinancialTaskRepository($database);
$repository = new OperationalProcessRepository($database, $local);
$processes = new OperationalProcessService($database, $repository, $contracts, $acceptances, $financial, $local);
$journey = new MigrationJourneyService();
$checks = [];

$check = static function (int $number, bool $condition, string $message) use (&$checks): void {
    $expected = count($checks) + 1;
    if ($number !== $expected || !$condition) {
        throw new RuntimeException("Verificação {$number} falhou: {$message}");
    }
    $checks[$number] = $message;
};

$migrationView = (string) file_get_contents($root . '/backend/Views/processes/migration.php');
$upgradeView = (string) file_get_contents($root . '/backend/Views/clients/upgrade.php');
$clientController = (string) file_get_contents($root . '/backend/Controllers/ClientController.php');
$processController = (string) file_get_contents($root . '/backend/Controllers/OperationalProcessController.php');
$finalizationSource = (string) file_get_contents($root . '/backend/Services/Processes/MigrationFinalizationService.php');

$stepKeys = [
    'migration_data', 'prepare_document', 'send_acceptance', 'confirm_acceptance',
    'technical_execution', 'confirm_equipment', 'change_plan', 'validate_connection',
    'open_financial_ticket', 'follow_financial_ticket', 'complete_migration',
];
$synthetic = [
    'id' => 991,
    'status' => 'draft',
    'current_step_key' => 'migration_data',
    'next_pending_key' => 'migration_data',
    'steps' => array_map(static fn (string $key, int $index): array => [
        'id' => $index + 1,
        'step_key' => $key,
        'label' => $key,
        'status' => 'not_started',
        'is_required' => 1,
    ], $stepKeys, array_keys($stepKeys)),
];
$projection = $journey->project($synthetic);

$check(1, (int) $projection['active_stage'] === 1, 'iniciar deve abrir a etapa 1');
$check(2, (string) $projection['active']['key'] === 'condition', 'etapa 1 não pode ser pulada');
$check(3, count((array) $projection['visible_steps']) === 4, 'quatro etapas devem permanecer visíveis');
$check(4, count((array) $projection['technical_steps']) === 11, 'onze etapas internas devem ser preservadas');
$check(5, str_contains($upgradeView, 'Condições aplicadas automaticamente'), 'condições automáticas devem ser explicitadas');
$check(6, str_contains($clientController, "'benefit_original_value'") && str_contains($upgradeView, 'benefitValue'), 'benefício deve ser preenchido pelo fluxo');
$check(7, str_contains($clientController, "'benefit_adjustment_reason'") && str_contains($upgradeView, 'Justificativa do ajuste'), 'ajuste de benefício deve ser auditável');
$check(8, str_contains($clientController, 'fidelidade_automatica_migracao') && str_contains($clientController, 'fidelidade_automatica_upgrade'), 'fidelidade automática deve ser configurável');
$check(9, str_contains($upgradeView, 'Ajustar condições'), 'condições avançadas devem ser recolhidas');
$check(10, str_contains($upgradeView, 'data-plan-technology') && str_contains($upgradeView, 'Troca de tecnologia'), 'tecnologia deve ser simplificada');
$check(11, str_contains($migrationView, 'Contatos usados no aceite (somente leitura)') && str_contains($migrationView, 'type="hidden" name="phone"'), 'contatos do aceite devem ser somente leitura');
$check(12, str_contains($migrationView, 'Corrigir contato no cadastro') && str_contains($processController, 'updateMigrationContacts'), 'correção controlada deve retornar ao mesmo processo');
$check(13, str_contains($migrationView, 'Coletar assinatura local'), 'aceite com cliente presente deve permanecer na etapa 2');
$check(14, str_contains($migrationView, 'Cliente não está presente'), 'aceite remoto deve permanecer na etapa 2');
$check(15, str_contains($migrationView, 'Enviar confirmação') && str_contains($migrationView, 'Atualizar situação'), 'envio e confirmação devem estar na mesma etapa');
$check(16, str_contains($migrationView, 'Serviço executado') && str_contains($migrationView, 'Equipamento instalado') && str_contains($processController, "'confirm_equipment'"), 'execução técnica deve ser unificada');

$tmpRoot = sys_get_temp_dir() . '/isp-aux-evidence-' . bin2hex(random_bytes(6));
mkdir($tmpRoot, 0770, true);
$pngPath = $tmpRoot . '/source.png';
file_put_contents($pngPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
$evidenceService = new MigrationEvidenceService(new Config([
    'paths' => ['storage' => $tmpRoot],
    'app' => ['migration_evidence' => ['max_bytes' => 1024 * 1024, 'max_files' => 2]],
]), true);
$stored = $evidenceService->store(991, 'technical_execution', [
    'name' => 'foto teste.png',
    'tmp_name' => $pngPath,
    'error' => UPLOAD_ERR_OK,
    'size' => 68,
], ['login' => 'teste.quinta']);
$resolved = $evidenceService->resolve(['files' => $stored], (string) ($stored[0]['id'] ?? ''));
$check(17, count($stored) === 1 && is_array($resolved) && str_starts_with((string) $resolved['absolute_path'], $tmpRoot . '/'), 'upload protegido deve validar e resolver a evidência');
$check(18, str_contains($processController, "['online']") && str_contains($processController, 'radiusConnectionStatus'), 'PPPoE online deve ser consultado');
$check(19, str_contains($processController, 'offline_justification') && str_contains($processController, 'offline_override'), 'PPPoE offline deve exigir exceção justificada');
$check(20, str_contains($finalizationSource, 'prepareDryRun') && str_contains($finalizationSource, 'planConfirmation->confirm'), 'finalização deve orquestrar e reler o plano');
$check(21, str_contains($finalizationSource, 'skipped_completed') && str_contains($finalizationSource, 'existingRequestId'), 'retry parcial deve reaproveitar estado e correlação');
$check(22, str_contains($finalizationSource, 'openFinancialTicket'), 'chamado financeiro deve ser automatizado pelo serviço compartilhado');
$check(23, str_contains($finalizationSource, 'aguardando revisão financeira'), 'técnico concluído deve manter financeiro pendente');
$check(24, str_contains((string) file_get_contents($root . '/backend/Services/Processes/OperationalProcessService.php'), 'completeMigrationWhenFinancialClosed'), 'fechamento financeiro deve ser reconciliado');
$check(25, str_contains((string) file_get_contents($root . '/backend/Services/Processes/OperationalProcessService.php'), 'operational_process.completed_automatically'), 'conclusão geral deve ser auditada');

$operator = ['id' => null, 'login' => 'teste.quinta', 'name' => 'Teste quinta', 'role' => 'manager'];
$pdo->beginTransaction();
try {
    $login = 'quinta_' . bin2hex(random_bytes(5));
    $draft = $processes->startMigrationDraft($login, ['nome' => 'Cliente sintético'], $operator);
    $cancelled = $processes->cancelProcess((int) $draft['id'], $operator, 'Teste de cancelamento simples.');
    $check(26, (string) ($cancelled['status'] ?? '') === 'cancelled', 'cancelamento simples deve preservar o registro');
    $check(27, str_contains($migrationView, 'confirm_accepted'), 'cancelamento após aceite deve exigir confirmação específica');
    $externalSynthetic = $synthetic;
    foreach ($externalSynthetic['steps'] as &$step) {
        if ($step['step_key'] === 'change_plan') {
            $step['status'] = 'completed';
            $step['external_reference'] = 'CLIENT-UUID';
            $step['evidence'] = ['dry_run' => false];
        }
    }
    unset($step);
    $check(28, $journey->hasExternalActions($externalSynthetic) && str_contains($migrationView, 'confirm_external'), 'ação externa deve reforçar o cancelamento');
    $replacement = $processes->startMigrationDraft($login, ['nome' => 'Cliente sintético'], $operator);
    $check(29, (int) $replacement['id'] !== (int) $draft['id'] && empty($replacement['contract_id']) && empty($replacement['acceptance_id']), 'reinício deve criar rascunho sem reutilizar contrato ou aceite');
    $active = $processes->activeMigrationForLogin($login);
    $check(30, (int) ($active['id'] ?? 0) === (int) $replacement['id'], 'processo cancelado não deve voltar como ativo');
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $updatedEvidence = $evidenceService->remove(['files' => $stored], (string) ($stored[0]['id'] ?? ''));
    @rmdir($tmpRoot . '/uploads/processes/991/technical_execution');
    @rmdir($tmpRoot . '/uploads/processes/991');
    @rmdir($tmpRoot . '/uploads/processes');
    @rmdir($tmpRoot . '/uploads');
    @rmdir($tmpRoot);
}

echo 'FifthIterationSmoke OK - ' . count($checks) . " verificações; rollback local concluído; nenhuma ação externa.\n";
