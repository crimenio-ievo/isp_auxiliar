<?php

declare(strict_types=1);

use App\Controllers\ClientController;
use App\Controllers\AcceptanceController;
use App\Core\Env;
use App\Infrastructure\Contracts\ContractAcceptanceRepository;
use App\Infrastructure\Contracts\ContractRepository;
use App\Infrastructure\Contracts\FinancialTaskRepository;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;

require dirname(__DIR__, 2) . '/backend/bootstrap/app.php';

$app = bootstrapApplication();
$database = new Database($app->config());
$pdo = $database->pdo();
$contracts = new ContractRepository($database);
$acceptances = new ContractAcceptanceRepository($database);
$financialTasks = new FinancialTaskRepository($database);
$local = new LocalRepository($database, (string) Env::get('APP_PROVIDER_KEY', 'default'));
$assertions = 0;

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }

    $assertions++;
};

$hasError = static function (array $errors, string $fragment): bool {
    foreach ($errors as $error) {
        if (str_contains((string) $error, $fragment)) {
            return true;
        }
    }

    return false;
};

$contractData = static function (string $login, array $overrides = []): array {
    return array_replace([
        'client_id' => null,
        'mkauth_login' => $login,
        'technician_name' => 'Teste rollback',
        'technician_login' => 'teste.rollback',
        'nome_cliente' => 'Cliente sintético de teste',
        'telefone_cliente' => '11999999999',
        'tipo_adesao' => 'isenta',
        'valor_adesao' => 0,
        'parcelas_adesao' => 1,
        'valor_parcela_adesao' => 0,
        'vencimento_primeira_parcela' => null,
        'fidelidade_meses' => 12,
        'beneficio_valor' => 0,
        'multa_total' => 0,
        'tipo_aceite' => 'upgrade_migracao',
        'observacao_adesao' => 'Teste automatizado com rollback.',
        'upgrade_snapshot_json' => json_encode([
            'operation_type' => 'upgrade',
            'current_plan_id' => 'radio-10',
            'current_plan_name' => 'Rádio 10 Mega',
            'current_technology' => 'Rádio',
            'new_plan_id' => 'radio-20',
            'new_plan_name' => 'Rádio 20 Mega',
            'new_technology' => 'Rádio',
            'new_monthly_value' => 100,
            'review_confirmed' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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

$acceptanceData = static function (int $contractId, string $token, array $overrides = []): array {
    return array_replace([
        'contract_id' => $contractId,
        'technician_name' => 'Teste rollback',
        'technician_login' => 'teste.rollback',
        'token' => $token,
        'token_expires_at' => date('Y-m-d H:i:s', time() + 3600),
        'status' => 'criado',
        'telefone_enviado' => '11999999999',
        'remote_signature_reason' => null,
        'whatsapp_message_id' => null,
        'sent_at' => null,
        'accepted_at' => null,
        'revoked_at' => null,
        'revoked_by_user_id' => null,
        'revoked_by_login' => null,
        'revocation_reason' => null,
        'ip_address' => null,
        'user_agent' => null,
        'termo_versao' => 'test.rollback',
        'termo_hash' => hash('sha256', 'termo-' . $token),
        'pdf_path' => null,
        'evidence_json_path' => null,
    ], $overrides);
};

try {
    $requiredContractColumns = [
        'lifecycle_status',
        'supersedes_contract_id',
        'superseded_by_contract_id',
        'revision_number',
        'cancellation_reason',
        'cancelled_at',
        'cancelled_by_user_id',
        'cancelled_by_login',
    ];
    $contractColumns = $database->fetchAll('SHOW COLUMNS FROM client_contracts');
    $contractColumnNames = array_column($contractColumns, 'Field');
    foreach ($requiredContractColumns as $column) {
        $assert(in_array($column, $contractColumnNames, true), 'Coluna ausente em client_contracts: ' . $column);
    }

    $requiredAcceptanceColumns = ['revoked_at', 'revoked_by_user_id', 'revoked_by_login', 'revocation_reason'];
    $acceptanceColumns = $database->fetchAll('SHOW COLUMNS FROM contract_acceptances');
    $acceptanceColumnNames = array_column($acceptanceColumns, 'Field');
    foreach ($requiredAcceptanceColumns as $column) {
        $assert(in_array($column, $acceptanceColumnNames, true), 'Coluna ausente em contract_acceptances: ' . $column);
    }

    $lifecycleColumn = array_values(array_filter(
        $contractColumns,
        static fn (array $column): bool => ($column['Field'] ?? '') === 'lifecycle_status'
    ))[0] ?? [];
    foreach (['active', 'correction_pending', 'cancelled', 'superseded'] as $status) {
        $assert(str_contains((string) ($lifecycleColumn['Type'] ?? ''), "'{$status}'"), 'Estado contratual ausente: ' . $status);
    }

    $foreignKeys = $database->fetchAll(
        'SELECT CONSTRAINT_NAME
         FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = :schema
           AND TABLE_NAME = "client_contracts"',
        ['schema' => (string) $app->config()->get('database.database', '')]
    );
    $foreignKeyNames = array_column($foreignKeys, 'CONSTRAINT_NAME');
    $assert(in_array('fk_client_contracts_supersedes', $foreignKeyNames, true), 'FK de versão anterior ausente.');
    $assert(in_array('fk_client_contracts_superseded_by', $foreignKeyNames, true), 'FK de versão substituta ausente.');

    $managerAccess = $local->accessProfileForUser(['login' => 'teste.manager', 'role' => 'manager']);
    $technicianAccess = $local->accessProfileForUser(['login' => 'teste.technician', 'role' => 'technician']);
    $viewerAccess = $local->accessProfileForUser(['login' => 'teste.viewer', 'role' => 'viewer']);
    $assert(!empty($managerAccess['can_upgrade_correct']) && !empty($managerAccess['can_cancel_pending_contracts']) && !empty($managerAccess['can_supersede_contracts']), 'Gestor sem permissões corretivas esperadas.');
    $assert(!empty($technicianAccess['can_upgrade_correct']) && !empty($technicianAccess['can_cancel_pending_contracts']) && empty($technicianAccess['can_supersede_contracts']), 'Permissões do técnico estão incorretas.');
    $assert(empty($viewerAccess['can_upgrade_correct']) && empty($viewerAccess['can_cancel_pending_contracts']) && empty($viewerAccess['can_supersede_contracts']), 'Visualizador recebeu permissão corretiva indevida.');
    $assert(!(bool) $app->config()->get('app.mkauth.write_enabled', false), 'O teste exige MKAUTH_WRITE_ENABLED bloqueado.');

    $controller = (new ReflectionClass(ClientController::class))->newInstanceWithoutConstructor();
    $validate = new ReflectionMethod(ClientController::class, 'validateUpgrade');
    $plans = [
        ['id' => 'radio-10', 'name' => 'Rádio 10 Mega', 'label' => 'Rádio 10 Mega', 'install_type' => 'radio', 'value' => '100.00'],
        ['id' => 'radio-20', 'name' => 'Rádio 20 Mega', 'label' => 'Rádio 20 Mega', 'install_type' => 'radio', 'value' => '100.00'],
        ['id' => 'fibra-100', 'name' => 'Fibra 100 Mega', 'label' => 'Fibra 100 Mega', 'install_type' => 'fibra', 'value' => '120.00'],
    ];
    $validationContext = ['current_plan' => 'radio-10', 'current_monthly_value' => 100.0, 'planOptions' => $plans];
    $validBase = [
        'operation_type' => 'upgrade',
        'review_confirmed' => true,
        'plano_atual' => 'radio-10',
        'current_plan_id' => 'radio-10',
        'current_plan_name' => 'Rádio 10 Mega',
        'tecnologia_atual' => 'Rádio',
        'novo_plano' => 'radio-20',
        'new_plan_id' => 'radio-20',
        'new_plan_name' => 'Rádio 20 Mega',
        'nova_tecnologia' => 'Rádio',
        'novo_valor_mensal' => 100.0,
        'fidelidade_meses' => 12,
        'signature_mode' => 'local',
        'confirm_same_value' => true,
    ];

    $errors = $validate->invoke($controller, array_replace($validBase, ['operation_type' => 'migration']), $validationContext);
    $assert($hasError($errors, 'Migração exige tecnologias diferentes'), 'Migração na mesma tecnologia não foi bloqueada.');
    $errors = $validate->invoke($controller, array_replace($validBase, ['new_plan_id' => 'radio-10', 'novo_plano' => 'radio-10']), $validationContext);
    $assert($hasError($errors, 'Upgrade exige um plano novo diferente'), 'Upgrade para o mesmo plano não foi bloqueado.');
    $errors = $validate->invoke($controller, array_replace($validBase, ['confirm_same_value' => false]), $validationContext);
    $assert($hasError($errors, 'mesmo valor mensal'), 'Upgrade com valor igual não exigiu confirmação explícita.');
    $errors = $validate->invoke($controller, array_replace($validBase, ['review_confirmed' => false]), $validationContext);
    $assert($hasError($errors, 'Confirme a revisão final'), 'Revisão final obrigatória não foi validada.');
    $errors = $validate->invoke($controller, array_replace($validBase, [
        'operation_type' => 'migration',
        'novo_plano' => 'fibra-100',
        'new_plan_id' => 'fibra-100',
        'new_plan_name' => 'Fibra 100 Mega',
        'nova_tecnologia' => 'Fibra',
        'novo_valor_mensal' => 120.0,
        'confirm_same_value' => false,
    ]), $validationContext);
    $assert($errors === [], 'Migração entre tecnologias diferentes deveria ser válida: ' . implode(' ', $errors));

    $login = 'rollback_upgrade_' . bin2hex(random_bytes(6));
    $beforeCount = (int) ($database->fetchOne(
        'SELECT COUNT(*) AS total FROM client_contracts WHERE mkauth_login = :login',
        ['login' => $login]
    )['total'] ?? 0);
    $pdo->beginTransaction();

    $acceptedContractId = (int) $contracts->create($contractData($login));
    $acceptedToken = 'accepted-' . bin2hex(random_bytes(16));
    $acceptedId = (int) $acceptances->create($acceptanceData($acceptedContractId, $acceptedToken, [
        'status' => 'aceito',
        'accepted_at' => date('Y-m-d H:i:s'),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'UpgradeCorrectionSmoke',
        'pdf_path' => 'storage/contracts/teste/termo.pdf',
        'evidence_json_path' => 'storage/contracts/teste/evidence.json',
    ]));
    $acceptances->revoke($acceptedId, 'Correção de teste', null, 'teste.rollback', true);
    $acceptedAfterRevoke = $acceptances->findById($acceptedId) ?? [];
    $assert(($acceptedAfterRevoke['status'] ?? '') === 'aceito', 'Aceite concluído perdeu seu status histórico.');
    $assert(trim((string) ($acceptedAfterRevoke['revoked_at'] ?? '')) !== '', 'Aceite concluído não foi invalidado.');
    $assert(($acceptedAfterRevoke['evidence_json_path'] ?? '') === 'storage/contracts/teste/evidence.json', 'Evidência do aceite foi alterada.');
    $assert(($acceptedAfterRevoke['pdf_path'] ?? '') === 'storage/contracts/teste/termo.pdf', 'PDF histórico foi alterado.');
    $assert($acceptances->markAccepted($acceptedId) === 0, 'Aceite revogado pôde ser aceito novamente.');

    $newContractId = (int) $contracts->create($contractData($login, [
        'supersedes_contract_id' => $acceptedContractId,
        'revision_number' => 2,
    ]));
    $contracts->markLifecycle($acceptedContractId, 'superseded', 'Correção de teste', null, 'teste.rollback', $newContractId);
    $oldContract = $contracts->findById($acceptedContractId) ?? [];
    $newContract = $contracts->findById($newContractId) ?? [];
    $assert(($oldContract['lifecycle_status'] ?? '') === 'superseded' && (int) ($oldContract['superseded_by_contract_id'] ?? 0) === $newContractId, 'Versão antiga não aponta para a substituta.');
    $assert(($newContract['lifecycle_status'] ?? '') === 'active' && (int) ($newContract['supersedes_contract_id'] ?? 0) === $acceptedContractId && (int) ($newContract['revision_number'] ?? 0) === 2, 'Nova versão não preservou vínculo/revisão.');

    $pendingContractId = (int) $contracts->create($contractData($login . '_pending'));
    $pendingToken = 'pending-' . bin2hex(random_bytes(16));
    $pendingId = (int) $acceptances->create($acceptanceData($pendingContractId, $pendingToken));
    $taskId = (int) $financialTasks->create([
        'contract_id' => $pendingContractId,
        'mkauth_login' => $login . '_pending',
        'titulo' => 'Teste rollback',
        'descricao' => 'Não executar no MkAuth.',
        'setor' => 'financeiro',
        'status' => 'aberto',
    ]);
    $contracts->markLifecycle($pendingContractId, 'cancelled', 'Dados incorretos', null, 'teste.rollback');
    $acceptances->revoke($pendingId, 'Dados incorretos', null, 'teste.rollback', false);
    $financialTasks->updateStatus($taskId, 'cancelado');
    $pendingAfterRevoke = $acceptances->findByTokenHash($pendingToken) ?? [];
    $assert(($pendingAfterRevoke['status'] ?? '') === 'cancelado' && trim((string) ($pendingAfterRevoke['revoked_at'] ?? '')) !== '', 'Token antigo não foi invalidado logicamente.');
    $assert($acceptances->markSent($pendingId) === 0, 'Aceite cancelado pôde ser marcado como enviado.');
    $assert(($financialTasks->findById($taskId)['status'] ?? '') === 'cancelado', 'Pendência financeira antiga não foi encerrada.');

    $publicController = (new ReflectionClass(AcceptanceController::class))->newInstanceWithoutConstructor();
    $publicControllerReflection = new ReflectionClass(AcceptanceController::class);
    $acceptanceRepositoryProperty = $publicControllerReflection->getProperty('acceptanceRepository');
    $acceptanceRepositoryProperty->setValue($publicController, $acceptances);
    $contractRepositoryProperty = $publicControllerReflection->getProperty('contractRepository');
    $contractRepositoryProperty->setValue($publicController, $contracts);
    $loadContext = new ReflectionMethod(AcceptanceController::class, 'loadContextByToken');
    $oldLinkContext = $loadContext->invoke($publicController, $pendingToken);
    $friendlyMessage = 'Esta solicitação foi cancelada e não está mais disponível. Utilize a nova solicitação enviada pela iEvo Technology.';
    $assert(($oldLinkContext['error'] ?? '') === $friendlyMessage, 'Link antigo não retornou a mensagem amigável exata.');
    $assert(($oldLinkContext['unavailableReason'] ?? '') === 'cancelled_or_superseded', 'Link antigo não ativou a tela pública indisponível.');

    $pdo->rollBack();
    $afterCount = (int) ($database->fetchOne(
        'SELECT COUNT(*) AS total FROM client_contracts WHERE mkauth_login LIKE :login',
        ['login' => $login . '%']
    )['total'] ?? 0);
    $assert($beforeCount === 0 && $afterCount === 0, 'Rollback não removeu os registros sintéticos.');

    echo "OK - {$assertions} verificações; banco revertido por rollback; nenhum envio executado; nenhuma escrita no MkAuth.\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, 'FALHOU - ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
