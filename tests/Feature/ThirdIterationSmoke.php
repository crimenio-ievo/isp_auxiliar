<?php

declare(strict_types=1);

use App\Controllers\ClientController;
use App\Core\Env;
use App\Core\View;
use App\Infrastructure\Contracts\MessageTemplateRepository;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\MkAuth\MkAuthClient;
use App\Infrastructure\MkAuth\MkAuthDatabase;
use App\Infrastructure\MkAuth\MkAuthWriteGuard;
use App\Infrastructure\MkAuth\TechnologyMapper;
use App\Services\Contracts\AcceptanceEvidenceService;
use App\Services\Contracts\ContractScannerService;
use App\Services\MkAuth\MkAuthPlanChangeService;
use App\Services\Notifications\NotificationTemplateService;
use App\Services\Processes\MigrationJourneyService;

require dirname(__DIR__, 2) . '/backend/bootstrap/app.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$app = bootstrapApplication();
$database = new Database($app->config());
$local = new LocalRepository($database, (string) Env::get('APP_PROVIDER_KEY', 'default'));
$templates = new MessageTemplateRepository($database, $local);
$templateService = new NotificationTemplateService($templates);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) throw new RuntimeException($message);
    $assertions++;
};

try {
    $mapper = new TechnologyMapper();
    $assert($mapper->family('D') === 'radio' && str_contains($mapper->label('D'), 'FWA'), 'Código D não foi mapeado para FWA.');
    $assert($mapper->family('H') === 'fibra' && str_contains($mapper->label('H'), 'FTTH'), 'Código H não foi mapeado para FTTH.');
    $unknown = $mapper->describe('Z9');
    $assert(empty($unknown['verified']) && $unknown['label'] === 'Tecnologia não identificada' && $unknown['code'] === 'Z9', 'Código desconhecido foi inferido.');

    $controller = (new ReflectionClass(ClientController::class))->newInstanceWithoutConstructor();
    $controllerReflection = new ReflectionClass(ClientController::class);
    $controllerReflection->getProperty('technologyMapper')->setValue($controller, $mapper);
    $controllerReflection->getProperty('localRepository')->setValue($controller, $local);
    $controllerReflection->getProperty('config')->setValue($controller, $app->config());
    $_SESSION['user'] = ['login' => 'teste.manager', 'role' => 'manager', 'name' => 'Teste'];
    $quickActions = new ReflectionMethod(ClientController::class, 'buildClientQuickActions');
    $actions = $quickActions->invoke($controller, [
        'phones' => ['(31) 99999-1111', '<script>'],
        'emails' => ['cliente@example.test', 'javascript:alert(1)'],
        'coordinates' => '-20.123,-42.456',
        'address' => 'Rua de Teste, 100',
        'login' => 'cliente_teste',
    ], ['ip' => '192.0.2.10']);
    $urls = array_column($actions, 'url');
    $assert(in_array('tel:+5531999991111', $urls, true) && in_array('https://wa.me/5531999991111', $urls, true), 'Ações seguras de telefone não foram geradas.');
    $assert(count(array_filter($urls, static fn (string $url): bool => str_starts_with($url, 'mailto:'))) === 1, 'E-mail inválido gerou ação.');
    $assert(count(array_filter($urls, static fn (string $url): bool => str_starts_with($url, 'http://192.0.2.10'))) === 1, 'IP autorizado e válido não gerou ação.');
    $assert(!str_contains(implode(' ', $urls), '<script>') && !str_contains(implode(' ', $urls), 'javascript:'), 'Conteúdo inseguro entrou em URL.');

    $operation = new ReflectionMethod(ClientController::class, 'determineUpgradeOperation');
    $radio10 = ['speed_down' => '10M']; $radio20 = ['speed_down' => '20M']; $fiber100 = ['speed_down' => '100M'];
    $assert($operation->invoke($controller, 'r10', 'f100', 'radio', 'fibra', $radio10, $fiber100, 80.0, 100.0) === 'migration', 'Migração automática incorreta.');
    $assert($operation->invoke($controller, 'r10', 'r20', 'radio', 'radio', $radio10, $radio20, 80.0, 100.0) === 'upgrade', 'Upgrade automático incorreto.');
    $assert($operation->invoke($controller, 'r20', 'r10', 'radio', 'radio', $radio20, $radio10, 100.0, 80.0) === 'downgrade', 'Downgrade automático incorreto.');
    $assert($operation->invoke($controller, 'r10', 'r10', 'radio', 'radio', $radio10, $radio10, 80.0, 80.0) === '', 'Ausência de mudança não foi bloqueada.');

    $validate = new ReflectionMethod(ClientController::class, 'validateUpgrade');
    $plans = [
        ['id' => 'r10', 'name' => 'R10', 'technology' => 'D'],
        ['id' => 'r20', 'name' => 'R20', 'technology' => 'D'],
    ];
    $base = ['operation_type' => 'upgrade', 'plano_atual' => 'r10', 'current_plan_id' => 'r10', 'current_plan_name' => 'R10', 'tecnologia_atual' => $mapper->label('D'), 'novo_plano' => 'r20', 'new_plan_id' => 'r20', 'new_plan_name' => 'R20', 'nova_tecnologia' => $mapper->label('D'), 'novo_valor_mensal' => 90, 'apply_fidelity' => true, 'valor_beneficio' => 0, 'fidelity_benefit_description' => '', 'fidelidade_meses' => 13, 'observacao' => ''];
    $fidelityErrors = $validate->invoke($controller, $base, ['current_plan' => 'r10', 'current_monthly_value' => 80, 'planOptions' => $plans]);
    $assert(isset($fidelityErrors['valor_beneficio'], $fidelityErrors['fidelity_benefit_description'], $fidelityErrors['fidelidade_meses']), 'Fidelidade sem benefício válido não foi bloqueada.');
    $validFidelity = $validate->invoke($controller, array_replace($base, ['valor_beneficio' => 300, 'fidelity_benefit_description' => 'Instalação isenta', 'fidelidade_meses' => 12]), ['current_plan' => 'r10', 'current_monthly_value' => 80, 'planOptions' => $plans]);
    $assert($validFidelity === [], 'Fidelidade válida foi bloqueada: ' . implode(' ', $validFidelity));
    $benefitDefaults = (new ReflectionMethod(ClientController::class, 'resolveUpgradeBenefitDefaults'))->invoke($controller, 'Rádio fixo (FWA)', 'Fibra até o imóvel (FTTH)', 'R10', 'F100', 80.0, 100.0);
    $configuredAdhesion = (float) $app->config()->get('contracts.commercial.valor_adesao_padrao', 0);
    $assert(!empty($benefitDefaults['flags']['radio_to_fiber']) && !empty($benefitDefaults['flags']['adhesion_waiver']), 'Migração rádio-fibra não aplicou a regra automática configurada.');
    $assert(abs((float) ($benefitDefaults['value'] ?? 0) - $configuredAdhesion) < 0.01, 'Benefício da migração não corresponde à adesão configurada.');
    $term = (new ReflectionMethod(ClientController::class, 'buildContractTermBody'))->invoke($controller, [
        'tipo_aceite' => 'upgrade_migracao', 'nome_cliente' => 'Cliente', 'mkauth_login' => 'cliente', 'telefone_cliente' => '5531999991111', 'fidelidade_meses' => 0,
        'upgrade_snapshot_json' => json_encode(['current_plan_name' => 'R10', 'new_plan_name' => 'F100', 'current_technology' => 'Rádio fixo (FWA)', 'new_technology' => 'Fibra até o imóvel (FTTH)', 'new_monthly_value' => 100, 'fidelity_months' => 0, 'benefit_flags' => ['radio_to_fiber' => true, 'adhesion_waiver' => false]], JSON_THROW_ON_ERROR),
    ]);
    $assert(str_contains($term, 'Nova fidelidade: não aplicada.') && !str_contains($term, 'renovação da fidelidade por 12 meses'), 'Termo ainda refidelizou automaticamente.');

    $templateService->seedDefaults();
    $assert(count($templates->listAll()) >= 20, 'Eventos iniciais de mensagens não foram preparados.');
    $assert($templateService->validate('', 'Mensagem %variavel_inexistente%', ['sms']) !== [], 'Template inválido/canal sem adapter foi aceito.');
    $assert($templateService->validate('', '<script>alert(1)</script>', ['whatsapp']) !== [], 'Conteúdo executável foi aceito.');
    $migrationTemplate = $templates->findByPurpose('migracao_solicitar_aceite', 'whatsapp');
    $rendered = $templateService->render($migrationTemplate ?? [], ['nomecliente' => '<Cliente>', 'novoplano' => 'Plano 100', 'planoatual' => 'Plano 10', 'linkaceite' => 'https://example.test/a', 'protocoloprocesso' => 'MIG-1']);
    $assert(str_contains($rendered['body'], '<Cliente>') && !str_contains($rendered['body'], '%nomecliente%'), 'Renderização fechada de variáveis falhou.');
    $assert(isset($rendered['template_snapshot']['version']), 'Snapshot do template não foi produzido.');

    $requiredTables = ['message_template_versions', 'notification_channel_registry', 'client_documents'];
    foreach ($requiredTables as $table) {
        $found = $database->fetchOne('SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table', ['table' => $table]);
        $assert((int) ($found['total'] ?? 0) === 1, 'Tabela aditiva ausente: ' . $table);
    }

    $view = new View((string) $app->config()->get('paths.views'));
    $detailHtml = $view->render('clients/detail', [
        'pageTitle' => 'Cliente', 'currentPath' => '/clientes/detalhe', 'basePath' => '', 'appName' => 'Teste',
        'user' => ['name' => 'Teste', 'access' => []], 'flash' => null,
        'detail' => [
            'login' => 'cliente_teste',
            'profile' => ['name' => 'Cliente Teste', 'short_name' => '', 'document' => '12345678901', 'status_visual' => ['label' => 'Ativo', 'class' => 'client-status-active'], 'plan' => 'Plano', 'technology' => 'Tecnologia não identificada', 'technology_detail' => ['verified' => false, 'code' => 'Z9'], 'phones' => ['31999991111', '3133332222'], 'emails' => ['a@example.test', 'b@example.test'], 'actions' => $actions, 'address' => 'Rua de Teste, 100', 'monthly_value' => '80', 'due_day' => '10'],
            'clientProfile' => ['nome' => 'Cliente Teste', 'cidade' => 'Coimbra', 'estado' => 'MG'],
            'contracts' => [], 'digitalContract' => [], 'operationalProcesses' => [], 'migrationAction' => ['label' => 'Iniciar Upgrade / Migração', 'tone' => 'start', 'url' => '/clientes/upgrade?login=cliente_teste'], 'timeline' => [], 'acceptanceHistory' => [], 'financialTask' => [], 'scannedDocuments' => [],
        ],
        'canRequestUpgrade' => true, 'canRequestContractSignature' => true, 'canManageFinancial' => true,
    ]);
    $assert(substr_count($detailHtml, 'data-open-client-panel=') >= 4 && str_contains($detailHtml, 'href="#documents"'), 'Quatro cartões e ação de documentos não foram renderizados.');
    $assert(str_contains($detailHtml, 'data-lazy-client-detail="financial"'), 'Financeiro não está em lazy-load.');
    $assert(str_contains($detailHtml, 'Código informado pelo MkAuth: Z9'), 'Código desconhecido não ficou restrito ao detalhe técnico.');
    $assert(!str_contains($detailHtml, '>Novo cliente<') && !str_contains($detailHtml, 'Conhecido como </p>'), 'Perfil exibiu ação ou campo vazio indevido.');

    $migrationStepKeys = ['migration_data', 'prepare_document', 'send_acceptance', 'confirm_acceptance', 'technical_execution', 'confirm_equipment', 'change_plan', 'validate_connection', 'open_financial_ticket', 'follow_financial_ticket', 'complete_migration'];
    $migrationSteps = [];
    foreach ($migrationStepKeys as $position => $key) {
        $migrationSteps[] = ['id' => $position + 1, 'step_key' => $key, 'step_order' => $position + 1, 'label' => ucwords(str_replace('_', ' ', $key)), 'status' => 'not_started', 'status_label' => 'Não iniciada', 'status_class' => 'muted', 'url' => '/processos/migracao?id=9&step=' . $key];
    }
    $activeMigrationStep = $migrationSteps[3];
    $migrationHtml = $view->render('processes/migration', [
        'pageTitle' => 'Migração', 'currentPath' => '/processos/migracao', 'basePath' => '', 'appName' => 'Teste', 'user' => ['name' => 'Teste'], 'flash' => null,
        'process' => ['id' => 9, 'status' => 'waiting_client', 'client_name' => 'Cliente', 'mkauth_login' => 'cliente', 'progress_completed' => 3, 'progress_total' => 11, 'progress_percent' => 27, 'metadata' => ['migration' => ['operation_type' => 'migration', 'current_plan_name' => 'R10', 'new_plan_name' => 'F100', 'current_monthly_value' => 80, 'new_monthly_value' => 100], 'client' => ['email' => 'a@example.test']], 'steps' => $migrationSteps],
        'activeStep' => $activeMigrationStep, 'previousStep' => $migrationSteps[2], 'nextStep' => $migrationSteps[4], 'contract' => ['id' => 1, 'nome_cliente' => 'Cliente'], 'acceptance' => ['id' => 2, 'status' => 'criado', 'token_hash' => str_repeat('a', 64), 'telefone_enviado' => '5531999991111'], 'financialTask' => [], 'connection' => [], 'dryRun' => null, 'csrfToken' => 'csrf', 'canOverride' => true,
        'journey' => (new MigrationJourneyService())->project(['id' => 9, 'status' => 'waiting_client', 'steps' => $migrationSteps], 'confirm_acceptance'),
    ]);
    foreach (['Nova condição', 'Aceite', 'Execução técnica', 'Finalização'] as $title) $assert(str_contains($migrationHtml, $title), 'Etapa compacta ausente: ' . $title);
    $assert(str_contains($migrationHtml, 'data-migration-workspace') && str_contains($migrationHtml, 'Ver detalhes técnicos do processo'), 'Área operacional única não foi renderizada.');
    $assert(str_contains($migrationHtml, 'data-signature-canvas') && str_contains($migrationHtml, 'channel_whatsapp') && str_contains($migrationHtml, 'channel_email'), 'Assinatura local e canais não foram agrupados.');
    $assert(!str_contains($migrationHtml, 'Copiar link') && !str_contains($migrationHtml, 'Copiar mensagem'), 'Envio manual por cópia foi exposto.');

    $scanner = new ContractScannerService();
    $image = imagecreatetruecolor(120, 180); imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    $jpegPath = tempnam(sys_get_temp_dir(), 'scan_test_'); $pdfPath = tempnam(sys_get_temp_dir(), 'scan_pdf_');
    imagejpeg($image, $jpegPath, 80); imagedestroy($image);
    (new ReflectionMethod(ContractScannerService::class, 'writePdf'))->invoke($scanner, [['path' => $jpegPath, 'width' => 120, 'height' => 180, 'position' => 0], ['path' => $jpegPath, 'width' => 120, 'height' => 180, 'position' => 1]], $pdfPath);
    $pdfBytes = (string) file_get_contents($pdfPath);
    preg_match_all('/\/Type\s*\/Page\b/', $pdfBytes, $generatedPages);
    $assert(str_starts_with($pdfBytes, '%PDF-') && filesize($pdfPath) > 500 && count($generatedPages[0] ?? []) === 2, 'Gerador local de PDF multipágina não produziu arquivo válido.');
    @unlink($jpegPath); @unlink($pdfPath);

    $evidenceService = new AcceptanceEvidenceService();
    $invalidRejected = false;
    try { $evidenceService->saveSignature(99, 'data:text/plain;base64,SGk='); } catch (RuntimeException) { $invalidRejected = true; }
    $assert($invalidRejected, 'MIME falso de assinatura foi aceito.');

    $guard = new MkAuthWriteGuard('test', false);
    $planService = new MkAuthPlanChangeService(new MkAuthDatabase('', '3306', '', '', '', 'utf8mb4', 'sha256', $guard), new MkAuthClient('', null, null, null, $guard), $guard);
    $dryRun = $planService->buildDryRunFromSnapshots('cliente', ['uuid_cliente' => 'UUID', 'plano_nome' => 'R10'], ['nome' => 'F100', 'uuid_plano' => 'P100']);
    $assert(!empty($dryRun['dry_run']) && empty($dryRun['write_enabled']) && empty($dryRun['session_disconnect']['supported']), 'Dry-run de plano prometeu escrita/desconexão indevida.');
    $assert((bool) $app->config()->get('contracts.mkauth_ticket.dry_run', false), 'Chamado MkAuth não foi forçado a dry-run no ambiente local.');

    $tracked = (string) shell_exec('git ls-files');
    $assert(!str_contains(strtolower($tracked), 'integracao-ia') && !str_contains(strtolower($tracked), 'openai'), 'Arquivo de integração com IA entrou na árvore rastreada.');
    echo "OK - {$assertions} verificações da terceira iteração; nenhum envio e nenhuma escrita externa.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FALHOU - ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
