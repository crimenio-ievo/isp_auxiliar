<?php

declare(strict_types=1);

use App\Core\Env;
use App\Core\View;
use App\Infrastructure\MkAuth\MkAuthWriteGuard;

require dirname(__DIR__, 2) . '/backend/bootstrap/app.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$rootPath = dirname(__DIR__, 2);
$app = bootstrapApplication();
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

$activeProcess = [
    'id' => 77,
    'process_type' => 'migration',
    'type_label' => 'Upgrade / Migração',
    'status' => 'waiting_financial',
    'status_label' => 'Aguardando financeiro',
    'progress_completed' => 8,
    'progress_total' => 11,
    'next_pending_key' => 'open_financial_ticket',
    'next_pending_label' => 'Revisar financeiro após migração',
    'resume_url' => '/processos/migracao?id=77&step=open_financial_ticket',
    'contract_id' => 177,
];
$cancelledProcess = array_replace($activeProcess, [
    'id' => 78,
    'status' => 'cancelled',
    'status_label' => 'Cancelado',
    'next_pending_key' => null,
    'next_pending_label' => null,
    'resume_url' => '/processos/migracao?id=78',
    'contract_id' => 178,
]);
$activeFinancialTask = ['id' => 501, 'contract_id' => 177, 'status' => 'aberto', 'titulo' => 'Revisar financeiro após migração'];
$cancelledFinancialTask = ['id' => 502, 'contract_id' => 178, 'status' => 'cancelado', 'titulo' => 'Tarefa cancelada'];

$renderClient = static function (
    array $processes = [],
    ?array $active = null,
    array $activeTask = [],
    bool $withFinancialSummary = true,
    array $timeline = []
) use ($view): string {
    return $view->render('clients/detail', [
        'pageTitle' => 'Cliente',
        'currentPath' => '/clientes/detalhe',
        'basePath' => '',
        'appName' => 'ISP Auxiliar',
        'user' => ['name' => 'Gestor', 'role' => 'manager', 'access' => ['can_use_beta' => true]],
        'flash' => null,
        'detail' => [
            'login' => 'cliente_modal_teste',
            'profile' => [
                'name' => 'Cliente Modal Teste',
                'short_name' => '',
                'document' => '12345678901',
                'status_visual' => ['label' => 'Ativo', 'class' => 'client-status-active'],
                'plan' => 'Plano Local',
                'technology' => 'Fibra',
                'technology_detail' => ['verified' => true, 'code' => 'H'],
                'phones' => [],
                'phone_contacts' => [],
                'emails' => [],
                'actions' => [],
                'address' => 'Rua de Teste, 100',
                'monthly_value' => $withFinancialSummary ? '99.90' : '',
                'due_day' => $withFinancialSummary ? '10' : '',
                'billing_type' => $withFinancialSummary ? 'boleto' : '',
                'open_titles' => $withFinancialSummary ? 1 : null,
                'overdue_titles' => $withFinancialSummary ? 0 : null,
            ],
            'clientProfile' => ['nome' => 'Cliente Modal Teste', 'cidade' => 'Coimbra', 'estado' => 'MG'],
            'contracts' => [],
            'digitalContract' => [],
            'operationalProcesses' => $processes,
            'activeProcess' => $active,
            'migrationAction' => [],
            'timeline' => $timeline,
            'acceptanceHistory' => [],
            'financialTask' => $activeTask,
            'activeFinancialTask' => $activeTask,
            'scannedDocuments' => [],
        ],
        'canRequestUpgrade' => true,
        'canRequestContractSignature' => false,
        'canManageFinancial' => true,
        'clientDetailTimeoutMs' => 10000,
    ]);
};

$_SESSION['user'] = ['login' => 'gestor.modal', 'name' => 'Gestor', 'role' => 'manager', 'access' => ['can_use_beta' => true]];

try {
    $withoutProcess = $renderClient([], null, [], false);
    $withActiveProcess = $renderClient([$activeProcess], $activeProcess, $activeFinancialTask, true);
    $withCancelledProcess = $renderClient(
        [$cancelledProcess],
        null,
        [],
        true,
        [['label' => 'Tarefa financeira cancelada.', 'description' => 'Preservada somente no histórico.', 'time' => '2026-08-04 10:00:00']]
    );
    $withFinancial = $renderClient([], null, [], true);
    $withoutFinancial = $renderClient([], null, [], false);
    $appJs = (string) file_get_contents($rootPath . '/public/assets/js/app.js');
    $appCss = (string) file_get_contents($rootPath . '/public/assets/css/app.css');
    $layoutSource = (string) file_get_contents($rootPath . '/backend/Views/layouts/app.php');
    $headerSource = (string) file_get_contents($rootPath . '/backend/Views/layouts/header.php');
    $controllerSource = (string) file_get_contents($rootPath . '/backend/Controllers/ClientController.php');

    $check(1, substr_count($withoutProcess, 'data-client-modal hidden aria-hidden="true" inert') === 1, 'abrir cliente não abre modal');
    $check(2, str_contains($withActiveProcess, 'Revisar financeiro após migração') && str_contains($withActiveProcess, 'data-client-modal hidden'), 'cliente com pendência mantém modal fechado');
    $check(3, !str_contains($withCancelledProcess, '<section class="client-process-panel ')
        && !str_contains($withCancelledProcess, 'Pendência financeira operacional ativa')
        && str_contains($controllerSource, 'resolveActiveClientProcess')
        && str_contains($controllerSource, "['cancelled', 'superseded']"), 'cliente cancelado não mostra pendência ativa');
    $check(4, empty($cancelledProcess['next_pending_key']) && empty($cancelledProcess['next_pending_label']), 'processo cancelado não possui próxima pendência');
    $check(5, str_contains($withoutProcess, 'data-open-client-panel="client"') && str_contains($withoutProcess, 'data-client-panel-template="client"'), 'modal Cliente depende de clique explícito');
    $check(6, str_contains($withoutProcess, 'data-open-client-panel="address"') && str_contains($withoutProcess, 'data-client-panel-template="address"'), 'modal Endereço depende de clique explícito');
    $check(7, str_contains($withFinancial, 'data-open-client-panel="financial"') && str_contains($withFinancial, 'data-client-panel-template="financial"') && str_contains($withoutFinancial, 'Sem pendências financeiras operacionais ativas.'), 'modal Financeiro depende de clique e aceita ausência de resumo');
    $check(8, substr_count($withFinancial, 'role="dialog" aria-modal="true"') === 1 && substr_count($withFinancial, 'client-detail-panel__backdrop') === 1, 'existe somente um modal e um overlay');
    $check(9, str_contains($appJs, 'closeClientPanel(false);') && str_contains($appJs, 'modalBody.replaceChildren(template.content.cloneNode(true))'), 'abrir Financeiro limpa o conteúdo anterior');
    $check(10, str_contains($appJs, 'const closeClientPanel = (restoreFocus = true)') && str_contains($appJs, 'abortActiveRequest();'), 'botão fechar funciona durante loading');
    $check(11, str_contains($appJs, "event.key === 'Escape'") && str_contains($appJs, 'closeClientPanel();'), 'Escape fecha durante loading');
    $check(12, str_contains($withFinancial, 'data-close-client-panel aria-label="Fechar detalhes"'), 'overlay possui fechamento explícito');
    $check(13, str_contains($appJs, 'activeRequestController.abort()') && str_contains($appJs, 'signal: controller.signal'), 'fechamento aborta o fetch');
    $check(14, str_contains($appJs, 'Não foi possível carregar os dados financeiros no tempo esperado.') && str_contains($appJs, 'Math.max(8000, Math.min(12000'), 'timeout configurável apresenta mensagem controlada');
    $check(15, str_contains($appJs, 'data-retry-client-detail') && str_contains($appJs, 'Tentar novamente') && str_contains($appJs, 'Os detalhes financeiros não puderam ser carregados.'), 'erro controlado oferece nova tentativa');
    $check(16, str_contains($appJs, "contentType.includes('application/json')") && str_contains($appJs, 'resposta inesperada'), 'HTML inesperado é rejeitado');
    $check(17, str_contains($appJs, 'response.redirected') && str_contains($appJs, 'Sua sessão expirou.') && str_contains($appJs, 'Ir para o login'), 'redirect de login é tratado');
    $check(18, str_contains($appJs, "document.body.classList.remove('client-panel-open')") && str_contains($appCss, 'body.client-panel-open'), 'scroll do body é restaurado');
    $check(19, str_contains($appJs, "document.removeEventListener('keydown', handleClientModalKeydown)") && str_contains($appJs, 'restoreClientHubBackground();'), 'focus trap e inert do fundo são removidos ao fechar');
    $check(20, str_contains($appJs, "returnFocus.setAttribute('aria-expanded', 'false')") && str_contains($appJs, 'returnFocus.focus({ preventScroll: true })'), 'gatilho é recolhido e o foco retorna ao elemento de origem');
    $check(21, str_contains($withCancelledProcess, 'Tarefa financeira cancelada.') && str_contains($withCancelledProcess, 'Preservada somente no histórico.'), 'pendência cancelada aparece somente no histórico');
    $check(22, str_contains($withActiveProcess, '<section class="client-process-panel ') && !preg_match('/client-process-panel[\s\S]{0,1200}data-open-client-panel="financial"/', $withActiveProcess), 'alerta de processo não abre modal financeiro');
    $check(23, str_contains($withActiveProcess, '/processos/migracao?id=77&amp;step=open_financial_ticket'), 'Continuar processo aponta para a etapa correta');
    $check(24, !str_contains($layoutSource, '20260803a') && str_contains($layoutSource, "releaseInfo['id']") && str_contains($layoutSource, "releaseInfo['commit']") && str_contains($layoutSource, 'filemtime(') && substr_count($layoutSource, '?v=') === 2 && substr_count($layoutSource, 'rawurlencode($assetVersion)') === 2, 'assets possuem cache busting por release, commit ou filemtime');

    $releaseHeader = $view->render('layouts/header', ['layoutMode' => 'app', 'user' => $_SESSION['user'], 'appName' => 'ISP Auxiliar']);
    $check(25, str_contains($releaseHeader, 'Canal: <strong>Beta</strong>') && str_contains($releaseHeader, 'Abrir Stable') && !str_contains($headerSource, 'data-shared-build'), 'Stable/Beta usam destinos reais e exibem o canal atual');

    $guard = new MkAuthWriteGuard('test', false);
    $writeBlocked = false;
    try {
        $guard->assertAllowed('hotfix modal pendências');
    } catch (RuntimeException $exception) {
        $writeBlocked = $exception->getMessage() === MkAuthWriteGuard::BLOCKED_MESSAGE;
    }
    $check(26, !(bool) $app->config()->get('app.mkauth.write_enabled', false) && $writeBlocked && str_contains($controllerSource, 'clientFinancialSummary'), 'nenhuma escrita no MkAuth é permitida');
    $check(27, (bool) $app->config()->get('evotrix.dry_run', false) && (bool) $app->config()->get('email.dry_run', false) && (bool) $app->config()->get('contracts.mkauth_ticket.dry_run', false), 'nenhuma notificação real é permitida');
    $trackedFiles = preg_split('/\R+/', trim((string) shell_exec('git ls-files')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $aiFiles = array_filter($trackedFiles, static fn (string $file): bool => preg_match('/(^|[\/_-])(openai|integracao[-_]?ia|ai[-_]?integration)([\/_.-]|$)/i', $file) === 1);
    $check(28, $aiFiles === [], 'nenhum arquivo da IA está rastreado');

    if (count($checks) !== 28) {
        throw new RuntimeException('A suíte não executou as 28 categorias obrigatórias.');
    }

    echo "OK - 28 verificações do hotfix de modal e pendências; nenhuma escrita externa ou notificação real.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FALHOU - ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
