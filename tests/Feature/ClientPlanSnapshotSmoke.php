<?php

declare(strict_types=1);

use App\Core\View;
use App\Controllers\AcceptanceController;
use App\Infrastructure\MkAuth\ClientPayloadMapper;
use App\Infrastructure\MkAuth\ClientPlanConfirmationService;
use App\Infrastructure\MkAuth\ClientPlanReadRepository;
use App\Services\Clients\ClientPlanSnapshotService;

require dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';

final class ClientPlanSnapshotFakeRepository implements ClientPlanReadRepository
{
    public function __construct(public array $plans, public ?array $profile = null)
    {
    }

    public function listPlans(int $limit = 300): array
    {
        return $this->plans;
    }

    public function findClientProfile(string $loginOrCpfCnpj): ?array
    {
        return $this->profile;
    }
}

$scenarios = 0;
$assert = static function (bool $condition, string $message) use (&$scenarios): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $scenarios++;
};

$catalog = [
    [
        'uuid_plano' => '6667746E-1EAA-403A-8BAF-492344624D29',
        'codigo' => 'FIBRA-RURAL-40-PROMO',
        'nome' => 'FibraRural_40mbpsPromo',
        'valor' => '99.90',
        'tecnologia' => 'F',
        'veldown' => '40000',
        'velup' => '20000',
    ],
    [
        'uuid_plano' => 'PLAN-UUID-FIBRA-100',
        'codigo' => 'FIBRA-RURAL-100',
        'nome' => 'FibraRural_100mbps',
        'valor' => '149.90',
        'tecnologia' => 'F',
        'veldown' => '100000',
        'velup' => '50000',
    ],
];
$uuid = (string) $catalog[0]['uuid_plano'];
$service = new ClientPlanSnapshotService();
$snapshot = $service->capture(['plano' => $uuid], $catalog);

// 1. Seleção por UUID resolve o item oficial.
$assert(($snapshot['plan_resolution_status'] ?? '') === 'resolved', 'Seleção por UUID não foi resolvida.');
// 2. O POST/snapshot interno mantém o UUID técnico no campo selecionado.
$assert(($snapshot['plano'] ?? '') === $uuid, 'O identificador técnico do POST foi substituído.');
// 3. O snapshot preserva UUID.
$assert(($snapshot['plan_uuid'] ?? '') === $uuid, 'O snapshot não preservou o UUID.');
// 4. O snapshot preserva nome comercial.
$assert(($snapshot['plan_name'] ?? '') === 'FibraRural_40mbpsPromo', 'O snapshot não preservou o nome comercial.');
// 5. O snapshot preserva valor e metadados associados.
$assert(
    ($snapshot['plan_value'] ?? '') === '99.90'
    && ($snapshot['plan_technology'] ?? '') === 'F'
    && ($snapshot['plan_download'] ?? '') === '40000'
    && ($snapshot['plan_upload'] ?? '') === '20000',
    'O snapshot não preservou valor, tecnologia e velocidades juntos.'
);

$view = new View(dirname(__DIR__, 2) . '/backend/Views');
$baseViewData = [
    'layoutMode' => 'guest',
    'hideHeader' => true,
    'hideFooter' => true,
    'pageTitle' => 'Teste',
    'appName' => 'ISP Auxiliar',
    'flash' => null,
    'draftId' => 'draft-test',
    'checkpointToken' => '',
    'csrfToken' => 'csrf-test',
];
$summaryHtml = $view->render('clients/acceptance', array_replace($baseViewData, [
    'draft' => array_replace($snapshot, [
        'nome_completo' => 'Cliente teste',
        'login' => 'cliente_teste',
        'cpf_cnpj' => '52998224725',
        'cidade' => 'Coimbra',
        'estado' => 'MG',
        'endereco' => 'Rua Teste',
        'numero' => '10',
        'bairro' => 'Centro',
        'cep' => '36550000',
        'celular' => '31999999999',
    ]),
    'draftJson' => '{}',
]));
// 6. Resumo técnico usa nome e nunca expõe o UUID.
$assert(str_contains($summaryHtml, 'FibraRural_40mbpsPromo') && !str_contains($summaryHtml, $uuid), 'Resumo técnico expôs UUID ou omitiu o nome.');

$publicDetails = [
    'cliente' => ['nome' => 'Cliente teste', 'login' => 'cliente_teste', 'cpf_cnpj' => '***.***.***-25', 'telefone' => '31999999999'],
    'instalacao' => ['endereco' => 'Rua Teste', 'cidade' => 'Coimbra', 'estado' => 'MG'],
    'plano' => ['nome' => 'FibraRural_40mbpsPromo', 'valor_mensal' => 99.90],
    'contrato' => ['tipo_adesao' => 'cheia', 'valor_adesao' => 1200, 'parcelas_adesao' => 1, 'valor_parcela_adesao' => 1200, 'fidelidade_meses' => 12],
    'termo_versao' => '2026.1',
];
$publicContext = [
    'contract' => ['id' => 1, 'nome_cliente' => 'Cliente teste', 'mkauth_login' => 'cliente_teste', 'telefone_cliente' => '31999999999'],
    'acceptance' => ['id' => 1, 'status' => 'criado', 'token_hash' => str_repeat('a', 64)],
    'publicDetails' => $publicDetails,
    'maskedDocument' => '***.***.***-25',
];
$termHtml = $view->render('contracts/termo', [
    ...$baseViewData,
    'context' => $publicContext,
    'termValidated' => true,
    'providerName' => 'iEvo Technology',
    'token' => 'token-test',
]);
// 7. Contrato/termo apresenta nome comercial.
$assert(str_contains($termHtml, 'FibraRural_40mbpsPromo') && !str_contains($termHtml, $uuid), 'Contrato exibiu identificador técnico.');

$publicAcceptanceHtml = $view->render('contracts/acceptance', [
    ...$baseViewData,
    'context' => $publicContext,
    'providerName' => 'iEvo Technology',
    'token' => 'token-test',
]);
$testTmpDirectory = dirname(__DIR__, 2) . '/tmp';
$createdTestTmpDirectory = false;
if (!is_dir($testTmpDirectory)) {
    $createdTestTmpDirectory = mkdir($testTmpDirectory, 0775, true);
}
$acceptedEvidenceRelativePath = 'tmp/client-plan-accepted-' . bin2hex(random_bytes(6)) . '.json';
$acceptedEvidencePath = dirname(__DIR__, 2) . '/' . $acceptedEvidenceRelativePath;
file_put_contents($acceptedEvidencePath, json_encode([
    'displayed_data' => ['plano' => ['nome' => 'Plano histórico assinado', 'valor_mensal' => 89.90]],
], JSON_THROW_ON_ERROR));
$acceptanceController = (new ReflectionClass(AcceptanceController::class))->newInstanceWithoutConstructor();
$loadAcceptedDisplayedData = new ReflectionMethod(AcceptanceController::class, 'loadAcceptedDisplayedData');
$historicalDisplayedData = $loadAcceptedDisplayedData->invoke($acceptanceController, [
    'status' => 'aceito',
    'evidence_json_path' => $acceptedEvidenceRelativePath,
]);
@unlink($acceptedEvidencePath);
if ($createdTestTmpDirectory) {
    @rmdir($testTmpDirectory);
}
// 8. Aceite público apresenta nome comercial.
$publicAcceptanceUuidPosition = strpos($publicAcceptanceHtml, $uuid);
$assert(
    str_contains($publicAcceptanceHtml, 'FibraRural_40mbpsPromo')
    && $publicAcceptanceUuidPosition === false
    && ($historicalDisplayedData['plano']['nome'] ?? '') === 'Plano histórico assinado',
    'Aceite público exibiu identificador técnico: ' . ($publicAcceptanceUuidPosition === false ? 'nome ausente' : substr($publicAcceptanceHtml, max(0, $publicAcceptanceUuidPosition - 80), 220))
);

$repository = new ClientPlanSnapshotFakeRepository($catalog, [
    'uuid_cliente' => 'CLIENT-UUID-1',
    'plano_uuid' => $uuid,
    'plano_codigo' => 'FIBRA-RURAL-40-PROMO',
    'plano_nome' => 'FibraRural_40mbpsPromo',
]);
$confirmationService = new ClientPlanConfirmationService($repository, 1, 0);
$expected = $confirmationService->resolveExpectedPlan($uuid);
$payloadData = array_replace($snapshot, ['plano' => (string) $expected['payload_value']]);
$payload = (new ClientPayloadMapper())->map($payloadData);
// 9. A API desta versão recebe o nome oficial; UUID/código permanecem na identidade esperada.
$assert(
    ($payload['plano'] ?? '') === 'FibraRural_40mbpsPromo'
    && ($expected['uuid'] ?? '') === $uuid
    && ($expected['code'] ?? '') === 'FIBRA-RURAL-40-PROMO',
    'Payload/identidade oficial do plano ficaram inconsistentes.'
);
// 10. Releitura confirma prioritariamente o mesmo UUID.
$confirmed = $confirmationService->confirm('cliente_teste', $expected);
$assert(!empty($confirmed['confirmed']) && ($confirmed['matched_by'] ?? '') === 'uuid', 'Releitura não confirmou o UUID oficial.');
// 11. Divergência de UUID continua impedindo confirmação.
$repository->profile = [
    'uuid_cliente' => 'CLIENT-UUID-1',
    'plano_uuid' => 'OUTRO-PLANO-UUID',
    'plano_nome' => 'Outro plano',
];
$divergent = $confirmationService->confirm('cliente_teste', $expected);
$assert(empty($divergent['confirmed']), 'Divergência de plano foi tratada como confirmação.');

// 12. Rascunho legado somente com UUID é enriquecido com segurança.
$legacyDraft = $service->capture(['plano' => $uuid], $catalog);
$assert(($legacyDraft['plan_name'] ?? '') === 'FibraRural_40mbpsPromo', 'Rascunho UUID-only não foi enriquecido.');
// 13. Plano inexistente não ganha nome inventado.
$unknown = $service->capture(['plano' => 'UUID-INEXISTENTE'], $catalog);
$assert(($unknown['plan_name'] ?? '') === '', 'Plano inexistente recebeu nome inventado.');
// 14. Plano inexistente recebe aviso técnico controlado sem ecoar o identificador.
$unknownHtml = $view->render('clients/acceptance', array_replace($baseViewData, [
    'draft' => array_replace($unknown, ['nome_completo' => 'Cliente teste']),
    'draftJson' => '{}',
]));
$assert(
    ($unknown['plan_resolution_status'] ?? '') === 'unresolved'
    && str_contains((string) ($unknown['plan_resolution_warning'] ?? ''), 'não foi localizado')
    && !str_contains((string) ($unknown['plan_resolution_warning'] ?? ''), 'UUID-INEXISTENTE')
    && str_contains($unknownHtml, 'Plano requer conferência técnica')
    && !str_contains($unknownHtml, 'UUID-INEXISTENTE'),
    'Plano inexistente não gerou aviso técnico controlado.'
);
// 15. Trocar o identificador atualiza UUID, nome e valor como uma unidade.
$switched = $service->capture(array_replace($snapshot, ['plano' => 'PLAN-UUID-FIBRA-100']), $catalog);
$assert(
    ($switched['plan_uuid'] ?? '') === 'PLAN-UUID-FIBRA-100'
    && ($switched['plan_name'] ?? '') === 'FibraRural_100mbps'
    && ($switched['plan_value'] ?? '') === '149.90',
    'Troca de plano deixou identidade e condição comercial desencontradas.'
);
// 16. Voltar ao formulário corrige combinação antiga incompatível pelo identificador selecionado.
$incompatible = $service->capture([
    'plano' => $uuid,
    'plan_uuid' => 'PLAN-UUID-FIBRA-100',
    'plan_name' => 'FibraRural_100mbps',
    'plan_value' => '149.90',
], $catalog);
$assert(
    ($incompatible['plan_uuid'] ?? '') === $uuid
    && ($incompatible['plan_name'] ?? '') === 'FibraRural_40mbpsPromo'
    && ($incompatible['plan_value'] ?? '') === '99.90',
    'Restauração manteve combinação UUID/nome incompatível.'
);

echo 'ClientPlanSnapshotSmoke OK - ' . $scenarios . " cenários; nenhuma escrita externa.\n";
