<?php

declare(strict_types=1);

use App\Infrastructure\MkAuth\ClientGateway;
use App\Infrastructure\MkAuth\ClientPayloadMapper;
use App\Infrastructure\MkAuth\ClientPlanConfirmationService;
use App\Infrastructure\MkAuth\ClientPlanNotConfirmedException;
use App\Infrastructure\MkAuth\ClientPlanReadRepository;
use App\Infrastructure\MkAuth\ClientProvisioner;

require dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';

final class FakeClientPlanBackend implements ClientGateway, ClientPlanReadRepository
{
    public array $plans = [[
        'uuid_plano' => 'PLAN-UUID-FIBRA-100',
        'nome' => 'FibraRural_100mbps',
        'valor' => '149.90',
    ]];
    public ?array $profile = null;
    public int $createCalls = 0;
    public int $updateCalls = 0;
    public int $readCalls = 0;
    public bool $createAppliesPlan = true;
    public bool $updateAppliesPlan = true;
    public bool $timeoutAfterCreate = false;
    public bool $failUpdate = false;
    public bool $partialCreateResponse = false;
    public bool $blockWrites = false;
    public string $defaultPlanName = 'RadioRural_10mbps';
    public string $defaultPlanUuid = 'PLAN-UUID-RADIO-10';

    public function createClient(array $payload): array
    {
        if ($this->blockWrites) {
            throw new RuntimeException('MkAuth write blocked for test');
        }
        $this->createCalls++;
        $this->profile = $this->profileFor(
            $payload,
            $this->createAppliesPlan ? (string) $payload['plano'] : $this->defaultPlanName,
            $this->createAppliesPlan ? 'PLAN-UUID-FIBRA-100' : $this->defaultPlanUuid
        );
        if ($this->timeoutAfterCreate) {
            throw new RuntimeException('timeout after create');
        }

        return $this->partialCreateResponse
            ? ['status' => 'sucesso', 'mensagem' => 'Resposta parcial']
            : ['status' => 'sucesso', 'dados' => ['uuid_cliente' => 'CLIENT-UUID-1']];
    }

    public function updateClient(array $payload): array
    {
        if ($this->blockWrites) {
            throw new RuntimeException('MkAuth write blocked for test');
        }
        $this->updateCalls++;
        if ($this->failUpdate) {
            throw new RuntimeException('update failed');
        }
        if ($this->updateAppliesPlan) {
            $this->profile = $this->profileFor($payload, (string) $payload['plano'], 'PLAN-UUID-FIBRA-100');
        }

        return ['status' => 'sucesso', 'mensagem' => 'Cliente editado com sucesso'];
    }

    public function showClient(string $loginOrUuid): array
    {
        return ['status' => 'sucesso', 'cliente' => $this->profile ?? []];
    }

    public function listClients(array $filters = []): array
    {
        return ['status' => 'sucesso', 'clientes' => $this->profile === null ? [] : [$this->profile]];
    }

    public function listPlans(int $limit = 300): array
    {
        return $this->plans;
    }

    public function findClientProfile(string $loginOrCpfCnpj): ?array
    {
        $this->readCalls++;
        return $this->profile;
    }

    private function profileFor(array $payload, string $planName, string $planUuid): array
    {
        return [
            'uuid_cliente' => 'CLIENT-UUID-1',
            'login' => (string) ($payload['login'] ?? 'cliente.teste'),
            'plano' => $planName,
            'plano_nome' => $planName,
            'plano_uuid' => $planUuid,
        ];
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};
$form = static fn (array $overrides = []): array => array_replace([
    'nome_completo' => 'Cliente sintético',
    'login' => 'cliente.teste',
    'cpf_cnpj' => '12345678901',
    'plano' => 'PLAN-UUID-FIBRA-100',
], $overrides);
$makeProvisioner = static function (FakeClientPlanBackend $backend, ?string $log = null): ClientProvisioner {
    return new ClientProvisioner(
        new ClientPayloadMapper(),
        $backend,
        new ClientPlanConfirmationService($backend, 2, 0),
        $log
    );
};

// 1-5: seleção por UUID, nome oficial no payload e caminhos de uma/duas chamadas.
$oneCall = new FakeClientPlanBackend();
$oneResult = $makeProvisioner($oneCall)->provision($form());
$assert($oneCall->createCalls === 1 && $oneCall->updateCalls === 0, 'Plano aceito no POST gerou PUT desnecessário.');
$assert((string) $oneResult['payload']['plano'] === 'FibraRural_100mbps', 'UUID não foi traduzido para o nome oficial aceito pela API.');
$assert((string) $oneResult['plan_confirmation']['matched_by'] === 'uuid', 'Confirmação não priorizou UUID.');
$assert(!str_contains((string) $oneResult['payload']['plano'], 'PLAN-UUID'), 'Nome visual/UUID bruto foi usado como payload incorreto.');

$twoCalls = new FakeClientPlanBackend();
$twoCalls->createAppliesPlan = false;
$twoResult = $makeProvisioner($twoCalls)->provision($form());
$assert($twoCalls->createCalls === 1 && $twoCalls->updateCalls === 1, 'Fluxo compensado não executou criação e alteração.');
$assert(!empty($twoResult['plan_confirmation']['confirmed']), 'Plano não foi confirmado após o PUT.');

// 6-9: falha da segunda chamada, divergência e bloqueio de sucesso total.
$failedUpdate = new FakeClientPlanBackend();
$failedUpdate->createAppliesPlan = false;
$failedUpdate->failUpdate = true;
$partial = null;
try {
    $makeProvisioner($failedUpdate)->provision($form());
} catch (ClientPlanNotConfirmedException $exception) {
    $partial = $exception->provisionResult();
    $assert(str_contains($exception->getMessage(), 'plano não foi confirmado'), 'Mensagem operacional de falha parcial ausente.');
}
$assert(is_array($partial), 'Falha de segunda chamada foi tratada como sucesso total.');
$assert((string) ($partial['plan_confirmation']['observed_name'] ?? '') === 'RadioRural_10mbps', 'Releitura não registrou o plano divergente.');
$assert((string) ($partial['client_uuid'] ?? '') === 'CLIENT-UUID-1', 'UUID externo não foi preservado na falha parcial.');

// 10-11: retry idempotente usa o UUID preservado e não repete POST.
$failedUpdate->failUpdate = false;
$retry = $makeProvisioner($failedUpdate)->provision($form([
    'uuid_cliente' => (string) $partial['client_uuid'],
    'provision_request_id' => (string) $partial['request_id'],
]));
$assert($failedUpdate->createCalls === 1, 'Retry criou cliente duplicado.');
$assert($failedUpdate->updateCalls === 2 && !empty($retry['plan_confirmation']['confirmed']), 'Retry idempotente não confirmou o plano.');
$assert((string) $retry['request_id'] === (string) $partial['request_id'], 'Request ID não foi reaproveitado no retry.');

// 12: timeout depois do POST é recuperado por releitura sem duplicidade.
$timeout = new FakeClientPlanBackend();
$timeout->timeoutAfterCreate = true;
$timeoutResult = $makeProvisioner($timeout)->provision($form());
$assert($timeout->createCalls === 1 && $timeout->updateCalls === 0, 'Timeout recuperado repetiu a criação.');
$assert(!empty($timeoutResult['plan_confirmation']['confirmed']), 'Timeout recuperado não confirmou o estado final.');

// 13: resposta parcial sem UUID usa o UUID da leitura oficial.
$partialResponse = new FakeClientPlanBackend();
$partialResponse->partialCreateResponse = true;
$partialResponseResult = $makeProvisioner($partialResponse)->provision($form());
$assert((string) $partialResponseResult['client_uuid'] === 'CLIENT-UUID-1', 'Resposta parcial não recuperou UUID por leitura.');

// 14: a confirmação consulta o reader em cada tentativa, sem snapshot/cache local.
$noCache = new FakeClientPlanBackend();
$noCache->createAppliesPlan = false;
$makeProvisioner($noCache)->provision($form());
$assert($noCache->readCalls >= 2, 'Confirmação foi mascarada por cache/snapshot local.');

// 15: um bloqueio de escrita não é convertido em sucesso.
$guarded = new FakeClientPlanBackend();
$guarded->blockWrites = true;
$guardBlocked = false;
try {
    $makeProvisioner($guarded)->provision($form());
} catch (RuntimeException $exception) {
    $guardBlocked = str_contains($exception->getMessage(), 'write blocked');
}
$assert($guardBlocked && $guarded->createCalls === 0, 'Bloqueio de escrita foi ignorado.');

// 16: log contém somente contexto seguro e nunca payload/credenciais.
$logPath = sys_get_temp_dir() . '/client-plan-confirmation-' . bin2hex(random_bytes(6)) . '.log';
$logged = new FakeClientPlanBackend();
$makeProvisioner($logged, $logPath)->provision($form([
    'senha' => 'SEGREDO-NAO-LOGAR',
    'api_token' => 'TOKEN-NAO-LOGAR',
]));
$logContents = (string) file_get_contents($logPath);
@unlink($logPath);
$assert(str_contains($logContents, 'request_id') && str_contains($logContents, 'plan_confirmed'), 'Log seguro não registrou correlação e resultado.');
$assert(!str_contains($logContents, 'SEGREDO-NAO-LOGAR') && !str_contains($logContents, 'TOKEN-NAO-LOGAR'), 'Log expôs credenciais ou payload sensível.');

echo 'ClientPlanConfirmationSmoke OK - ' . $assertions . " assertions\n";
