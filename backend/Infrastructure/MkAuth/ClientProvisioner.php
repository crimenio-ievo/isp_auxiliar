<?php

declare(strict_types=1);

namespace App\Infrastructure\MkAuth;

/**
 * Orquestra a montagem do payload e a chamada remota.
 */
final class ClientProvisioner
{
    public function __construct(
        private ClientPayloadMapper $mapper,
        private MkAuthClient $client
    ) {
    }

    public function provision(array $formData): array
    {
        $payload = $this->mapper->map($formData);
        $response = $this->syncClient($payload);

        return [
            'payload' => $payload,
            'response' => $response,
            'action' => $response['_action'] ?? 'create',
        ];
    }

    public function listClients(array $filters = []): array
    {
        return $this->client->listClients($filters);
    }

    private function syncClient(array $payload): array
    {
        $login = trim((string) ($payload['login'] ?? ''));
        $cpfCnpj = trim((string) ($payload['cpf_cnpj'] ?? $payload['cpf'] ?? ''));
        $payloadUuid = trim((string) ($payload['uuid_cliente'] ?? $payload['uuid'] ?? ''));

        if ($payloadUuid !== '') {
            $payload['uuid'] = $payloadUuid;
            $payload['uuid_cliente'] = $payloadUuid;
            $response = $this->client->updateClient($payload);
            $this->assertSuccessfulResponse($response);
            $response['_action'] = 'update';

            return $response;
        }

        $response = $this->client->createClient($payload);
        $this->assertSuccessfulResponse($response);

        $createdUuid = $this->extractClientUuid($response);

        if ($createdUuid === '') {
            $createdUuid = $this->resolveExistingClientUuid($login, $cpfCnpj);
        }

        if ($createdUuid !== '') {
            $payload['uuid'] = $createdUuid;
            $payload['uuid_cliente'] = $createdUuid;
            $updateResponse = $this->client->updateClient($payload);
            $this->assertSuccessfulResponse($updateResponse);
            $updateResponse['_action'] = 'create';
            $updateResponse['_created_response'] = $response;

            return $updateResponse;
        }

        $response['_action'] = 'create';

        return $response;
    }

    private function resolveExistingClientUuid(string $login, string $cpfCnpj): string
    {
        if ($login !== '') {
            try {
                $existing = $this->client->showClient($login);
                $uuid = $this->extractClientUuid($existing);

                if ($uuid !== '') {
                    return $uuid;
                }
            } catch (\Throwable $exception) {
                // Seguimos para a busca por lista.
            }
        }

        if ($cpfCnpj !== '') {
            try {
                $existingByCpf = $this->client->listClients(['cpf_cnpj' => $cpfCnpj, 'limite' => 1]);
                $uuid = $this->extractClientUuidFromList($existingByCpf);

                if ($uuid !== '') {
                    return $uuid;
                }
            } catch (\Throwable $exception) {
                return '';
            }
        }

        return '';
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
        foreach ([
            'dados.uuid_cliente',
            'dados.uuid',
            'cliente.uuid_cliente',
            'cliente.uuid',
            'uuid_cliente',
            'uuid',
        ] as $path) {
            $value = $this->arrayGet($response, $path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    private function extractClientUuidFromList(array $response): string
    {
        foreach (['clientes', 'dados', 'cliente'] as $collectionKey) {
            $collection = $response[$collectionKey] ?? null;

            if (!is_array($collection) || $collection === []) {
                continue;
            }

            $first = $collection[array_key_first($collection)] ?? null;

            if (is_array($first)) {
                foreach (['uuid_cliente', 'uuid'] as $uuidKey) {
                    $value = $first[$uuidKey] ?? null;
                    if (is_string($value) && trim($value) !== '') {
                        return trim($value);
                    }
                }
            }
        }

        return '';
    }

    private function arrayGet(array $data, string $path): mixed
    {
        $segments = explode('.', $path);
        $value = $data;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
