<?php

declare(strict_types=1);

namespace App\Infrastructure\MkAuth;

use RuntimeException;

/**
 * Serviço isolado para abertura manual de chamados no MkAuth.
 *
 * Nesta fase ele nasce em modo seguro e só dispara quando o operador confirma
 * explicitamente a ação e a configuração permitir envio real.
 */
final class MkAuthTicketService
{
    public function __construct(
        private array $config,
        private string $baseUrl,
        private ?MkAuthDatabase $mkauthDatabase = null,
        private ?string $apiToken = null,
        private ?string $clientId = null,
        private ?string $clientSecret = null
    ) {
        $this->baseUrl = rtrim(trim($baseUrl), '/');
    }

    public function openFinancialTicket(array $payload): array
    {
        $enabled = (bool) ($this->config['enabled'] ?? false);
        $dryRun = (bool) ($this->config['dry_run'] ?? true);
        $endpoint = (string) ($this->config['endpoint'] ?? '/api/chamado/inserir');
        $timeoutSeconds = max(5, (int) ($this->config['timeout_seconds'] ?? 15));

        $normalizedPayload = $this->normalizePayload($payload);

        if (!$enabled || $dryRun) {
            return [
                'status' => 'simulado',
                'provider' => 'mkauth',
                'dry_run' => true,
                'enabled' => $enabled,
                'endpoint' => $endpoint,
                'payload' => $normalizedPayload,
                'queued_at' => date('Y-m-d H:i:s'),
                'message' => 'Chamado financeiro preparado em modo seguro.',
                'http_status' => null,
                'duration_ms' => 0,
            ];
        }

        if ($this->baseUrl === '') {
            throw new RuntimeException('MkAuth nao configurado para abertura de chamados.');
        }

        $headers = ['Content-Type: application/json'];
        $headers[] = 'Authorization: Bearer ' . $this->resolveJwtToken();

        $response = $this->sendHttpRequest('POST', $this->baseUrl . $endpoint, $normalizedPayload, $headers, $timeoutSeconds);
        $decoded = json_decode($response['body'], true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Resposta invalida do MkAuth ao abrir chamado.');
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $message = $decoded['mensagem'] ?? $decoded['message'] ?? 'MkAuth retornou erro ao abrir chamado.';
            throw new RuntimeException((string) $message);
        }

        if ($this->bodyIndicatesError($decoded)) {
            $message = $decoded['mensagem'] ?? $decoded['message'] ?? $decoded['erro'] ?? 'MkAuth retornou erro lógico ao abrir chamado.';
            throw new RuntimeException((string) $message);
        }

        $ticketId = $this->extractTicketId($decoded);
        $messageFallbackResult = $this->applyMessageFallback($ticketId, $normalizedPayload, $decoded);

        return [
            'status' => 'enviado',
            'provider' => 'mkauth',
            'dry_run' => false,
            'enabled' => true,
            'endpoint' => $endpoint,
            'payload' => $normalizedPayload,
            'response' => $decoded,
            'ticket_id' => $ticketId,
            'message_fallback_used' => (bool) ($messageFallbackResult['used'] ?? false),
            'message_fallback_status' => (string) ($messageFallbackResult['status'] ?? 'skipped'),
            'message_fallback_error' => (string) ($messageFallbackResult['error'] ?? ''),
            'sis_msg_id' => $messageFallbackResult['sis_msg_id'] ?? null,
            'sis_msg_message' => $messageFallbackResult['sis_msg_message'] ?? null,
            'queued_at' => date('Y-m-d H:i:s'),
            'message' => $ticketId !== null
                ? 'Chamado financeiro aberto com sucesso no MkAuth. ID: ' . $ticketId
                : 'Chamado financeiro aberto com sucesso no MkAuth.',
            'http_status' => $response['status'],
            'duration_ms' => $response['duration_ms'],
        ];
    }

    public function testConnection(): array
    {
        $enabled = (bool) ($this->config['enabled'] ?? false);
        $dryRun = (bool) ($this->config['dry_run'] ?? true);
        $endpoint = (string) ($this->config['endpoint'] ?? '/api/chamado/inserir');
        $timeoutSeconds = max(5, (int) ($this->config['timeout_seconds'] ?? 15));

        if (!$enabled || $dryRun) {
            return [
                'status' => 'simulado',
                'provider' => 'mkauth',
                'dry_run' => true,
                'enabled' => $enabled,
                'endpoint' => $endpoint,
                'http_status' => null,
                'duration_ms' => 0,
                'message' => 'Teste MkAuth validado em modo seguro. Nenhum chamado foi criado.',
            ];
        }

        if ($this->baseUrl === '') {
            throw new RuntimeException('MkAuth nao configurado para teste real.');
        }

        $startedAt = microtime(true);
        $token = $this->resolveJwtToken();
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if (trim($token) === '') {
            throw new RuntimeException('Nao foi possivel obter token de autenticacao no MkAuth.');
        }

        return [
            'status' => 'conectado',
            'provider' => 'mkauth',
            'dry_run' => false,
            'enabled' => true,
            'endpoint' => $endpoint,
            'http_status' => 200,
            'duration_ms' => $durationMs,
            'message' => 'Conexao e autenticacao com MkAuth validadas sem abrir chamado.',
        ];
    }

    private function normalizePayload(array $payload): array
    {
        $description = trim((string) ($payload['descricao'] ?? $payload['msg'] ?? $payload['mensagem'] ?? ''));

        return [
            'login' => trim((string) ($payload['login'] ?? '')),
            'nome' => trim((string) ($payload['nome'] ?? '')),
            'email' => trim((string) ($payload['email'] ?? '')),
            'telefone' => trim((string) ($payload['telefone'] ?? '')),
            'assunto' => trim((string) ($payload['assunto'] ?? 'Financeiro - Boleto / Carne')),
            'prioridade' => trim((string) ($payload['prioridade'] ?? 'normal')),
            'descricao' => $description,
            'msg' => $description,
            'mensagem' => $description,
            'observacao' => trim((string) ($payload['observacao'] ?? $description)),
        ];
    }

    private function applyMessageFallback(?string $ticketId, array $normalizedPayload, array $decodedResponse): array
    {
        $fallbackEnabled = (bool) ($this->config['message_fallback'] ?? true);

        if (!$fallbackEnabled || $ticketId === null || trim($ticketId) === '') {
            return ['used' => false, 'status' => 'skipped'];
        }

        $message = trim((string) ($normalizedPayload['descricao'] ?? $normalizedPayload['msg'] ?? $normalizedPayload['mensagem'] ?? ''));
        if ($message === '') {
            return ['used' => false, 'status' => 'skipped'];
        }

        if (!$this->mkauthDatabase instanceof MkAuthDatabase) {
            return ['used' => false, 'status' => 'unavailable'];
        }

        try {
            $latestMessage = $this->mkauthDatabase->findLatestTicketMessage($ticketId);
            if (is_array($latestMessage) && trim((string) ($latestMessage['msg'] ?? '')) !== '') {
                return [
                    'used' => false,
                    'status' => 'already_present',
                    'sis_msg_id' => isset($latestMessage['id']) ? (int) $latestMessage['id'] : null,
                    'sis_msg_message' => trim((string) ($latestMessage['msg'] ?? '')),
                ];
            }

            $msgId = $this->mkauthDatabase->insertTicketMessage(
                $ticketId,
                $message,
                (string) ($normalizedPayload['login'] ?? ''),
                'provedor',
                'API'
            );

            if ($msgId === null) {
                return ['used' => false, 'status' => 'not_inserted'];
            }

            return [
                'used' => true,
                'status' => 'inserted',
                'sis_msg_id' => $msgId,
                'sis_msg_message' => $message,
            ];
        } catch (\Throwable $exception) {
            return [
                'used' => false,
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function resolveJwtToken(): string
    {
        if ($this->apiToken !== null && trim($this->apiToken) !== '') {
            return trim($this->apiToken);
        }

        if ($this->clientId === null || $this->clientSecret === null || trim($this->clientId) === '' || trim($this->clientSecret) === '') {
            throw new RuntimeException('Client_Id e Client_Secret do MkAuth precisam estar configurados para abrir chamados reais.');
        }

        $headers = [
            'Authorization: Basic ' . base64_encode(trim($this->clientId) . ':' . trim($this->clientSecret)),
        ];

        $response = $this->sendHttpRequest('GET', $this->baseUrl . '/api/', [], $headers, max(5, (int) ($this->config['timeout_seconds'] ?? 15)));
        $decoded = json_decode($response['body'], true);

        if (is_array($decoded)) {
            foreach (['token', 'access_token', 'jwt', 'data.token'] as $key) {
                $value = $this->arrayGet($decoded, $key);

                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        if (preg_match('/[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+/', $response['body'], $matches)) {
            return $matches[0];
        }

        throw new RuntimeException('Nao foi possivel autenticar no MkAuth para abertura de chamado.');
    }

    private function sendHttpRequest(string $method, string $url, array $payload, array $headers, int $timeoutSeconds): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Extensao cURL nao esta disponivel neste ambiente.');
        }

        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException('Falha ao iniciar requisicao HTTP para o MkAuth.');
        }

        $startedAt = microtime(true);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Erro na requisicao MkAuth: ' . $error);
        }

        return [
            'status' => $status,
            'body' => (string) $body,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
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

    private function bodyIndicatesError(array $decoded): bool
    {
        $status = strtolower(trim((string) ($decoded['status'] ?? '')));
        if (in_array($status, ['erro', 'error', 'fail', 'failed'], true)) {
            return true;
        }

        if (array_key_exists('error', $decoded) || array_key_exists('erro', $decoded)) {
            return true;
        }

        $success = $decoded['success'] ?? null;
        if ($success === false || $success === 0 || $success === '0') {
            return true;
        }

        return false;
    }

    private function extractTicketId(array $decoded): ?string
    {
        foreach (['id', 'chamado', 'chamado_id', 'ticket_id', 'data.id', 'data.chamado', 'data.chamado_id', 'retorno.id'] as $path) {
            $value = $this->arrayGet($decoded, $path);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }
}
