<?php

declare(strict_types=1);

namespace App\Infrastructure\MkAuth;

use App\Services\Clients\ClientCompletionValidator;

/**
 * Cria/atualiza o cliente e só conclui após confirmar o plano por releitura.
 */
final class ClientProvisioner
{
    public function __construct(
        private ClientPayloadMapper $mapper,
        private ClientGateway $client,
        private ClientPlanConfirmationService $planConfirmation,
        private ?string $auditLogPath = null,
        private ?ClientCompletionValidator $completionValidator = null
    ) {
    }

    public function provision(array $formData): array
    {
        // Última barreira antes de qualquer leitura de plano ou escrita externa.
        $formData = ($this->completionValidator ?? new ClientCompletionValidator())->assertComplete($formData);
        $requestId = trim((string) ($formData['provision_request_id'] ?? ''));
        if ($requestId === '') {
            $requestId = bin2hex(random_bytes(16));
        }

        $expected = $this->planConfirmation->resolveExpectedPlan((string) ($formData['plano'] ?? ''));
        $formData['plano'] = (string) $expected['payload_value'];
        $payload = $this->mapper->map($formData);
        $login = trim((string) ($payload['login'] ?? ''));
        $cpfCnpj = trim((string) ($payload['cpf_cnpj'] ?? $payload['cpf'] ?? ''));
        $clientUuid = trim((string) ($payload['uuid_cliente'] ?? $payload['uuid'] ?? ''));
        $created = false;
        $createResponse = null;

        $this->log('started', $requestId, $login, $expected, ['has_client_uuid' => $clientUuid !== '']);

        if ($clientUuid === '') {
            try {
                $createResponse = $this->client->createClient($payload);
                $this->assertSuccessfulResponse($createResponse);
                $created = true;
                $clientUuid = $this->extractClientUuid($createResponse);
            } catch (\Throwable $exception) {
                // Um timeout pode acontecer depois da criação. A releitura evita
                // repetir POST e criar um segundo cliente.
                $profileAfterError = $this->planConfirmation->readClient($login);
                $clientUuid = trim((string) ($profileAfterError['uuid_cliente'] ?? ''));
                if ($clientUuid === '') {
                    $this->log('create_failed', $requestId, $login, $expected, ['error_class' => $exception::class]);
                    throw $exception;
                }
                $created = true;
                $this->log('create_recovered_by_readback', $requestId, $login, $expected, ['client_uuid' => $clientUuid]);
            }

            if ($clientUuid === '') {
                $profile = $this->planConfirmation->readClient($login);
                $clientUuid = trim((string) ($profile['uuid_cliente'] ?? ''));
            }

            $initialConfirmation = $this->planConfirmation->confirm($login, $expected);
            if (!empty($initialConfirmation['confirmed'])) {
                return $this->successfulResult(
                    $payload,
                    is_array($createResponse) ? $createResponse : ['status' => 'sucesso', 'mensagem' => 'Cliente localizado após releitura.'],
                    'create',
                    $requestId,
                    $clientUuid,
                    $expected,
                    $initialConfirmation,
                    false
                );
            }

            if ($clientUuid === '') {
                $this->throwPartial($payload, $requestId, $login, $expected, $initialConfirmation, '', true, 'O identificador externo não foi obtido.');
            }
        }

        $payload['uuid'] = $clientUuid;
        $payload['uuid_cliente'] = $clientUuid;

        try {
            $updateResponse = $this->client->updateClient($payload);
            $this->assertSuccessfulResponse($updateResponse);
        } catch (\Throwable $exception) {
            $confirmation = $this->planConfirmation->confirm($login, $expected);
            if (!empty($confirmation['confirmed'])) {
                return $this->successfulResult(
                    $payload,
                    ['status' => 'sucesso', 'mensagem' => 'Plano confirmado após releitura.'],
                    $created ? 'create' : 'update',
                    $requestId,
                    $clientUuid,
                    $expected,
                    $confirmation,
                    true
                );
            }
            $this->throwPartial($payload, $requestId, $login, $expected, $confirmation, $clientUuid, $created, $exception->getMessage());
        }

        $confirmation = $this->planConfirmation->confirm($login, $expected);
        if (empty($confirmation['confirmed'])) {
            $this->throwPartial($payload, $requestId, $login, $expected, $confirmation, $clientUuid, $created, 'O MkAuth respondeu à gravação, mas a releitura não confirmou o plano.');
        }

        return $this->successfulResult(
            $payload,
            $updateResponse,
            $created ? 'create' : 'update',
            $requestId,
            $clientUuid,
            $expected,
            $confirmation,
            true
        );
    }

    public function listClients(array $filters = []): array
    {
        return $this->client->listClients($filters);
    }

    private function successfulResult(
        array $payload,
        array $response,
        string $action,
        string $requestId,
        string $clientUuid,
        array $expected,
        array $confirmation,
        bool $planUpdateAttempted
    ): array {
        $response['_action'] = $action;
        $this->log('plan_confirmed', $requestId, (string) ($payload['login'] ?? ''), $expected, [
            'client_uuid' => $clientUuid,
            'matched_by' => (string) ($confirmation['matched_by'] ?? ''),
            'plan_update_attempted' => $planUpdateAttempted,
        ]);

        return [
            'payload' => $payload,
            'response' => $response,
            'action' => $action,
            'request_id' => $requestId,
            'client_uuid' => $clientUuid,
            'plan_update_attempted' => $planUpdateAttempted,
            'plan_confirmation' => $confirmation,
        ];
    }

    private function throwPartial(
        array $payload,
        string $requestId,
        string $login,
        array $expected,
        array $confirmation,
        string $clientUuid,
        bool $created,
        string $reason
    ): never {
        $observed = trim((string) ($confirmation['observed_name'] ?? ''));
        $message = 'Cliente criado, mas o plano não foi confirmado.';
        if ($observed !== '') {
            $message .= ' O MkAuth retornou o plano ' . $observed . '.';
        }
        $message .= ' O cadastro exige correção.';

        $result = [
            'status' => 'partial',
            'request_id' => $requestId,
            'client_uuid' => $clientUuid,
            'client_created' => $created,
            'payload' => $payload,
            'expected_plan' => $expected,
            'plan_confirmation' => $confirmation,
            'reason' => $reason,
        ];
        $this->log('plan_not_confirmed', $requestId, $login, $expected, [
            'client_uuid' => $clientUuid,
            'observed_name' => $observed,
            'reason_class' => 'partial_provision',
        ]);

        throw new ClientPlanNotConfirmedException($message, $result);
    }

    private function assertSuccessfulResponse(array $response): void
    {
        $status = strtolower(trim((string) ($response['status'] ?? '')));
        if (in_array($status, ['sucesso', 'success', 'simulado'], true)) {
            return;
        }

        $message = (string) ($response['mensagem'] ?? $response['message'] ?? 'Falha ao processar o cliente no MkAuth.');
        if (isset($response['error']) && is_array($response['error'])) {
            $message = (string) ($response['error']['text'] ?? $response['error']['message'] ?? $message);
        }
        throw new \RuntimeException($message);
    }

    private function extractClientUuid(array $response): string
    {
        foreach (['dados.uuid_cliente', 'dados.uuid', 'cliente.uuid_cliente', 'cliente.uuid', 'uuid_cliente', 'uuid'] as $path) {
            $value = $this->arrayGet($response, $path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }
        return '';
    }

    private function arrayGet(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    private function log(string $event, string $requestId, string $login, array $expected, array $context = []): void
    {
        if ($this->auditLogPath === null || trim($this->auditLogPath) === '') {
            return;
        }

        $directory = dirname($this->auditLogPath);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        $entry = [
            'at' => date(DATE_ATOM),
            'event' => $event,
            'request_id' => $requestId,
            'login' => $login,
            'expected_plan_uuid' => (string) ($expected['uuid'] ?? ''),
            'expected_plan_name' => (string) ($expected['name'] ?? ''),
            'context' => $context,
        ];
        @file_put_contents($this->auditLogPath, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
