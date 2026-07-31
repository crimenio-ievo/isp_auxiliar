<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Controllers\OperationalProcessController;
use App\Core\Env;
use App\Core\Request;
use App\Core\View;
use App\Infrastructure\Contracts\ContractAcceptanceRepository;
use App\Infrastructure\Contracts\ContractRepository;
use App\Infrastructure\Contracts\FinancialTaskRepository;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\MkAuth\MkAuthClient;
use App\Infrastructure\MkAuth\MkAuthDatabase;
use App\Infrastructure\MkAuth\MkAuthWriteGuard;
use App\Infrastructure\Processes\OperationalProcessRepository;
use App\Services\Contracts\AcceptanceWorkflowService;
use App\Services\MkAuth\MkAuthPlanChangeService;
use App\Services\Processes\OperationalProcessService;

require dirname(__DIR__, 2) . '/backend/bootstrap/app.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$app = bootstrapApplication();
$database = new Database($app->config());
$pdo = $database->pdo();
$local = new LocalRepository($database, (string) Env::get('APP_PROVIDER_KEY', 'default'));
$contracts = new ContractRepository($database);
$acceptances = new ContractAcceptanceRepository($database);
$financialTasks = new FinancialTaskRepository($database);
$processes = new OperationalProcessRepository($database, $local);
$service = new OperationalProcessService($database, $processes, $acceptances, $financialTasks, $local);
$acceptanceWorkflow = new AcceptanceWorkflowService($app->config());
$assertions = 0;

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};

$contractData = static function (string $login, string $type, array $overrides = []): array {
    return array_replace([
        'client_id' => null,
        'mkauth_login' => $login,
        'technician_name' => 'Operador sintético',
        'technician_login' => 'teste.processos',
        'nome_cliente' => 'Cliente sintético de processos',
        'telefone_cliente' => '11999999999',
        'tipo_adesao' => 'isenta',
        'valor_adesao' => 0,
        'parcelas_adesao' => 1,
        'valor_parcela_adesao' => 0,
        'vencimento_primeira_parcela' => null,
        'fidelidade_meses' => 12,
        'beneficio_valor' => 0,
        'multa_total' => 0,
        'tipo_aceite' => $type,
        'observacao_adesao' => 'Teste automatizado com rollback.',
        'upgrade_snapshot_json' => $type === 'upgrade_migracao' ? json_encode([
            'operation_type' => 'migration',
            'current_plan_id' => 'radio-10',
            'current_plan_name' => 'Rádio 10 Mega',
            'current_technology' => 'Rádio',
            'current_monthly_value' => 90,
            'new_plan_id' => 'fibra-100',
            'new_plan_name' => 'Fibra 100 Mega',
            'new_technology' => 'Fibra',
            'new_monthly_value' => 120,
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

$createAcceptance = static function (
    int $contractId,
    string $signatureMode = 'local'
) use ($acceptanceWorkflow, $acceptances): array {
    $data = $acceptanceWorkflow->prepare([
        'contract_id' => $contractId,
        'technician_name' => 'Operador sintético',
        'technician_login' => 'teste.processos',
        'signature_mode' => $signatureMode,
        'status' => $signatureMode === 'local' ? 'criado' : 'assinatura_pendente',
        'phone' => '11999999999',
        'remote_signature_reason' => $signatureMode === 'remote' ? 'Teste sintético' : null,
        'document_version' => 'test.processes.1',
        'term_hash' => hash('sha256', 'documento-' . $contractId),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'OperationalProcessSmoke',
    ]);
    $id = (int) ($acceptances->create($data) ?? 0);
    $record = $acceptances->findById($id) ?? [];
    $record['plain_token_for_test'] = $data['token'];

    return $record;
};

$operator = [
    'id' => null,
    'login' => 'teste.processos',
    'name' => 'Operador sintético',
    'role' => 'manager',
];

try {
    $assert($processes->isAvailable(), 'Tabelas de processos não estão disponíveis.');
    $assert(!(bool) $app->config()->get('app.mkauth.write_enabled', false), 'O teste exige escrita MkAuth bloqueada.');

    $requiredProcessColumns = [
        'process_type',
        'mkauth_login',
        'status',
        'progress_completed',
        'progress_total',
        'next_pending_key',
        'contract_id',
        'acceptance_id',
        'financial_task_id',
    ];
    $processColumnNames = array_column($database->fetchAll('SHOW COLUMNS FROM operational_processes'), 'Field');
    foreach ($requiredProcessColumns as $column) {
        $assert(in_array($column, $processColumnNames, true), 'Coluna de processo ausente: ' . $column);
    }

    $requiredStepColumns = [
        'step_key',
        'status',
        'is_required',
        'completion_origin',
        'pending_reason',
        'evidence_json',
        'next_action',
    ];
    $stepColumnNames = array_column($database->fetchAll('SHOW COLUMNS FROM operational_process_steps'), 'Field');
    foreach ($requiredStepColumns as $column) {
        $assert(in_array($column, $stepColumnNames, true), 'Coluna de etapa ausente: ' . $column);
    }

    $preparedOne = $acceptanceWorkflow->prepare([
        'contract_id' => 999,
        'signature_mode' => 'remote',
        'phone' => '11999999999',
        'document_version' => 'test.1',
        'term_hash' => hash('sha256', 'test-1'),
    ]);
    $preparedTwo = $acceptanceWorkflow->prepare([
        'contract_id' => 999,
        'signature_mode' => 'remote',
        'phone' => '11999999999',
        'document_version' => 'test.1',
        'term_hash' => hash('sha256', 'test-1'),
    ]);
    $assert(strlen((string) $preparedOne['token']) === 32, 'Token compartilhado não possui 128 bits.');
    $assert($preparedOne['token'] !== $preparedTwo['token'], 'Tokens duplicados foram gerados.');
    $assert((string) $preparedOne['termo_versao'] === 'test.1', 'Versão do documento não foi preservada.');
    $assert(strtotime((string) $preparedOne['token_expires_at']) > time(), 'Expiração do aceite não foi definida.');

    $csrfScope = 'operational-process-smoke';
    $csrfToken = Csrf::token($csrfScope);
    $assert(Csrf::verify(new Request('POST', '/test', '', [], ['_csrf' => $csrfToken]), $csrfScope), 'CSRF válido foi rejeitado.');
    $assert(!Csrf::verify(new Request('POST', '/test', '', [], ['_csrf' => 'invalid']), $csrfScope), 'CSRF inválido foi aceito.');

    $pdo->beginTransaction();

    $login = 'process_smoke_' . bin2hex(random_bytes(5));
    $migrationContractId = (int) $contracts->create($contractData($login, 'upgrade_migracao'));
    $migrationContract = $contracts->findById($migrationContractId) ?? [];
    $migrationAcceptance = $createAcceptance($migrationContractId, 'local');
    $migration = $service->ensureForContract(
        OperationalProcessService::TYPE_MIGRATION,
        $migrationContract,
        $migrationAcceptance,
        ['signature_mode' => 'local'],
        $operator
    );
    $assert((int) ($migration['id'] ?? 0) > 0, 'Processo de migração não foi criado.');
    $assert(count((array) ($migration['steps'] ?? [])) === 11, 'Checklist de migração não possui 11 etapas.');
    $assert((string) ($migration['status'] ?? '') === 'waiting_client', 'Migração não ficou aguardando o aceite do cliente.');
    $assert((string) ($migration['next_pending_key'] ?? '') === 'confirm_acceptance', 'Próxima pendência da migração está incorreta.');
    $assert((int) ($migration['progress_completed'] ?? 0) === 3, 'Etapas automáticas iniciais da migração estão incorretas.');

    $view = new View((string) $app->config()->get('paths.views'));
    $renderedChecklist = $view->render('processes/detail', [
        'pageTitle' => 'Migração',
        'currentPath' => '/processos/detalhe',
        'basePath' => '',
        'appName' => 'ISP Auxiliar',
        'user' => ['name' => 'Teste', 'access' => ['contratos' => true]],
        'flash' => null,
        'process' => $migration,
        'csrfToken' => 'synthetic-csrf',
        'canOverride' => true,
    ]);
    $assert(str_contains($renderedChecklist, 'Continuar próxima pendência'), 'Checklist renderizado não mostrou retomada.');
    $assert(str_contains($renderedChecklist, 'Alterar plano no MkAuth'), 'Checklist renderizado não mostrou a etapa de plano.');
    $renderedStep = $view->render('processes/step', [
        'pageTitle' => 'Confirmar aceite',
        'currentPath' => '/processos/etapa',
        'basePath' => '',
        'appName' => 'ISP Auxiliar',
        'user' => ['name' => 'Teste', 'access' => ['contratos' => true]],
        'flash' => null,
        'process' => $migration,
        'step' => array_values(array_filter(
            (array) $migration['steps'],
            static fn (array $step): bool => ($step['step_key'] ?? '') === 'confirm_acceptance'
        ))[0],
        'previousStep' => null,
        'nextStep' => null,
        'dryRun' => null,
        'csrfToken' => 'synthetic-csrf',
    ]);
    $assert(str_contains($renderedStep, 'Pular por enquanto'), 'Etapa mobile não mostrou adiamento.');
    $assert(str_contains($renderedStep, 'data-single-submit-form'), 'Proteção visual contra duplo clique não foi renderizada.');

    $sameMigration = $service->ensureForContract(
        OperationalProcessService::TYPE_MIGRATION,
        $migrationContract,
        $migrationAcceptance,
        ['signature_mode' => 'local'],
        $operator
    );
    $assert((int) $sameMigration['id'] === (int) $migration['id'], 'Retomada criou processo duplicado.');
    $documentCount = (int) ($database->fetchOne(
        'SELECT COUNT(*) AS total FROM operational_process_documents WHERE process_id = :process_id',
        ['process_id' => (int) $migration['id']]
    )['total'] ?? 0);
    $assert($documentCount === 1, 'Documento ativo foi duplicado na retomada.');

    $service->updateStep((int) $migration['id'], 'technical_execution', 'complete', [
        'observation' => 'Troca física executada antes do retorno do titular.',
        'evidence' => ['equipment' => 'ONT-SYNTHETIC'],
    ], $operator);
    $afterNonLinear = $service->detail((int) $migration['id']) ?? [];
    $technicalStep = array_values(array_filter(
        (array) ($afterNonLinear['steps'] ?? []),
        static fn (array $step): bool => ($step['step_key'] ?? '') === 'technical_execution'
    ))[0] ?? [];
    $assert(($technicalStep['status'] ?? '') === 'completed', 'Avanço não linear não concluiu a etapa técnica.');
    $assert(($afterNonLinear['next_pending_key'] ?? '') === 'confirm_acceptance', 'Avanço não linear apagou a pendência anterior.');

    $service->updateStep((int) $migration['id'], 'confirm_equipment', 'defer', [
        'observation' => 'Serial ainda será conferido.',
        'pending_reason' => 'Etiqueta do equipamento sem leitura no local.',
        'next_action' => 'Conferir serial na próxima visita.',
    ], $operator);
    $equipmentStep = $processes->findStep((int) $migration['id'], 'confirm_equipment') ?? [];
    $assert(($equipmentStep['status'] ?? '') === 'waiting', 'Pular por enquanto não preservou estado aguardando.');
    $assert(trim((string) ($equipmentStep['deferred_by_login'] ?? '')) === 'teste.processos', 'Usuário do adiamento não foi registrado.');

    $blocked = false;
    try {
        $service->completeProcess((int) $migration['id'], $operator);
    } catch (RuntimeException $exception) {
        $blocked = str_contains($exception->getMessage(), 'Conclusão bloqueada');
    }
    $assert($blocked, 'Conclusão geral não foi bloqueada com pendências obrigatórias.');

    $assert($acceptances->markAccepted((int) $migrationAcceptance['id'], '127.0.0.1', 'OperationalProcessSmoke') === 1, 'Aceite sintético não pôde ser confirmado.');
    $service->synchronizeAcceptance((int) $migrationAcceptance['id']);
    $acceptedMigration = $service->detail((int) $migration['id']) ?? [];
    $confirmStep = array_values(array_filter(
        (array) ($acceptedMigration['steps'] ?? []),
        static fn (array $step): bool => ($step['step_key'] ?? '') === 'confirm_acceptance'
    ))[0] ?? [];
    $assert(($confirmStep['status'] ?? '') === 'completed', 'Aceite confirmado não concluiu automaticamente a etapa correta.');
    $assert(($confirmStep['completion_origin'] ?? '') === 'automatic', 'Origem automática do aceite não foi registrada.');

    foreach ([
        'confirm_equipment',
        'change_plan',
        'validate_connection',
        'open_financial_ticket',
        'follow_financial_ticket',
    ] as $stepKey) {
        $service->updateStep((int) $migration['id'], $stepKey, 'complete', [
            'observation' => 'Confirmação manual sintética para ' . $stepKey,
            'external_reference' => str_contains($stepKey, 'ticket') ? 'TICKET-SYNTHETIC' : '',
            'evidence' => ['synthetic' => true, 'dry_run' => false],
        ], $operator);
    }
    $migrationComplete = $service->completeProcess((int) $migration['id'], $operator);
    $assert(($migrationComplete['status'] ?? '') === 'completed', 'Migração válida não foi concluída.');
    $assert((int) ($migrationComplete['progress_completed'] ?? 0) === 11, 'Progresso final da migração está incorreto.');

    $installationContractId = (int) $contracts->create($contractData($login . '_install', 'nova_instalacao'));
    $installationContract = $contracts->findById($installationContractId) ?? [];
    $installationAcceptance = $createAcceptance($installationContractId, 'remote');
    $installation = $service->ensureForContract(
        OperationalProcessService::TYPE_INSTALLATION,
        $installationContract,
        $installationAcceptance,
        ['signature_mode' => 'remote'],
        $operator
    );
    $assert(count((array) ($installation['steps'] ?? [])) === 11, 'Checklist de instalação não reutilizou a estrutura de 11 etapas.');
    $assert((int) ($installation['progress_completed'] ?? 0) === 3, 'Preparação compartilhada da instalação está incorreta.');
    $installationOverride = $service->completeProcess(
        (int) $installation['id'],
        $operator,
        true,
        'Exceção sintética autorizada para validar auditoria.'
    );
    $assert(($installationOverride['status'] ?? '') === 'completed', 'Exceção autorizada não concluiu o processo.');

    $signatureContractId = (int) $contracts->create($contractData($login . '_signature', 'contrato_digital'));
    $signatureContract = $contracts->findById($signatureContractId) ?? [];
    $signatureAcceptance = $createAcceptance($signatureContractId, 'local');
    $signature = $service->ensureForContract(
        OperationalProcessService::TYPE_STANDALONE_SIGNATURE,
        $signatureContract,
        $signatureAcceptance,
        ['signature_mode' => 'local'],
        $operator
    );
    $assert(count((array) ($signature['steps'] ?? [])) === 8, 'Solicitação avulsa não reutilizou o checklist compartilhado.');
    $assert((int) ($signature['progress_completed'] ?? 0) === 5, 'Preparação automática da solicitação avulsa está incorreta.');
    $service->cancelProcess((int) $signature['id'], $operator, 'Cancelamento sintético.');
    $assert(($service->detail((int) $signature['id'])['status'] ?? '') === 'cancelled', 'Cancelamento não preservou estado do processo.');

    $guard = new MkAuthWriteGuard('test', false);
    $planService = new MkAuthPlanChangeService(
        new MkAuthDatabase('', '3306', '', '', '', 'utf8mb4', 'sha256', $guard),
        new MkAuthClient('', null, null, null, $guard),
        $guard
    );
    $planDryRun = $planService->buildDryRunFromSnapshots(
        'synthetic.client',
        [
            'uuid_cliente' => 'SYNTHETIC-UUID',
            'plano_nome' => 'Rádio 10 Mega',
            'plano_tecnologia' => 'Rádio',
            'plano_valor' => '90.00',
            'online_now' => true,
        ],
        [
            'nome' => 'Fibra 100 Mega',
            'uuid_plano' => 'SYNTHETIC-PLAN',
            'tecnologia' => 'Fibra',
            'valor' => '120.00',
        ]
    );
    $assert(!empty($planDryRun['dry_run']), 'Proposta sintética não foi classificada como dry-run.');
    $assert(($planDryRun['payload']['plano'] ?? '') === 'Fibra 100 Mega', 'Payload de troca de plano está incorreto.');
    $assert(empty($planDryRun['session_disconnect']['supported']), 'Dry-run prometeu desconexão de sessão não suportada.');
    $assert(empty($planDryRun['financial_recalculation']['supported']), 'Dry-run prometeu recálculo financeiro não suportado.');
    $writeBlocked = false;
    try {
        $planService->apply([
            'dry_run' => true,
            'payload' => ['uuid' => 'SYNTHETIC', 'plano' => 'Fibra 100 Mega'],
        ]);
    } catch (RuntimeException $exception) {
        $writeBlocked = $exception->getMessage() === MkAuthWriteGuard::BLOCKED_MESSAGE;
    }
    $assert($writeBlocked, 'Alteração de plano contornou o MkAuthWriteGuard.');

    $_SESSION['user'] = $operator;
    $processController = new OperationalProcessController(
        $view,
        $app->config(),
        $local,
        $service,
        $planService,
        $contracts,
        $acceptances,
        $financialTasks,
        new MkAuthDatabase('', '3306', '', '', '', 'utf8mb4', 'sha256', $guard)
    );
    $detailResponse = $processController->detail(new Request(
        'GET',
        '/processos/detalhe',
        '',
        ['id' => (int) $migration['id']]
    ));
    $responseReflection = new ReflectionClass($detailResponse);
    $bodyProperty = $responseReflection->getProperty('body');
    $statusProperty = $responseReflection->getProperty('status');
    $bodyProperty->setAccessible(true);
    $statusProperty->setAccessible(true);
    $assert((int) $statusProperty->getValue($detailResponse) === 200, 'Gestor autorizado não abriu o processo.');
    $assert(str_contains((string) $bodyProperty->getValue($detailResponse), 'Todas as etapas'), 'Controller não renderizou o checklist.');

    $_SESSION['user'] = ['login' => 'teste.viewer', 'name' => 'Viewer', 'role' => 'viewer'];
    $deniedResponse = $processController->detail(new Request(
        'GET',
        '/processos/detalhe',
        '',
        ['id' => (int) $migration['id']]
    ));
    $assert((int) $statusProperty->getValue($deniedResponse) === 302, 'Visualizador sem permissão acessou o processo.');
    $_SESSION['user'] = $operator;

    $pdo->rollBack();
    $remaining = (int) ($database->fetchOne(
        'SELECT COUNT(*) AS total FROM operational_processes WHERE mkauth_login LIKE :login',
        ['login' => $login . '%']
    )['total'] ?? 0);
    $assert($remaining === 0, 'Rollback não removeu os processos sintéticos.');

    echo "OK - {$assertions} verificações; três tipos de processo; rollback concluído; nenhum envio e nenhuma escrita no MkAuth.\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'FALHOU - ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
