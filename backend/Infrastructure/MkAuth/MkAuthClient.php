<?php

declare(strict_types=1);

namespace App\Infrastructure\MkAuth;

use RuntimeException;

/**
 * Client HTTP isolado para a API do MkAuth.
 *
 * Esta camada evita espalhar detalhes de autenticação e payload pelo restante
 * da aplicação.
 */
final class MkAuthClient
{
    private ?string $jwtToken = null;

    public function __construct(
        private string $baseUrl,
        private ?string $apiToken = null,
        private ?string $clientId = null,
        private ?string $clientSecret = null
    ) {
        $this->baseUrl = $this->normalizeBaseUrl($baseUrl);
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '';
    }

    public function createClient(array $payload): array
    {
        return $this->request('POST', '/api/cliente/inserir', $payload, true);
    }

    public function updateClient(array $payload): array
    {
        return $this->request('PUT', '/api/cliente/editar', $payload, true);
    }

    public function showClient(string $loginOrUuid): array
    {
        return $this->request('GET', '/api/cliente/show/' . rawurlencode($loginOrUuid), [], true);
    }

    public function listClients(array $filters = []): array
    {
        $query = [];

        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $query[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        $path = '/api/cliente/listar/pagina=1';

        if ($query !== []) {
            $path .= '&' . implode('&', $query);
        }

        return $this->request('GET', $path, [], true);
    }

    public function listUsers(array $filters = []): array
    {
        $query = [];

        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $query[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        $path = '/api/usuario/listar/pagina=1';

        if ($query !== []) {
            $path .= '&' . implode('&', $query);
        }

        return $this->request('GET', $path, [], true);
    }

    private function request(string $method, string $path, array $payload = [], bool $authRequired = false): array
    {
        if (!$this->isConfigured()) {
            return [
                'status' => 'simulado',
                'mensagem' => 'MkAuth nao configurado. Operacao simulada com sucesso.',
                'dados' => $payload,
            ];
        }

        $headers = ['Content-Type: application/json'];

        if ($authRequired) {
            $headers[] = 'Authorization: Bearer ' . $this->getJwtToken();
        }

        $response = $this->sendHttpRequest($method, $this->baseUrl . $path, $payload, $headers);

        $decoded = json_decode($response['body'], true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Resposta invalida do MkAuth: ' . $this->summarizeBody($response['body']));
        }

        $decoded['http_status'] = $response['status'];

        if ($response['status'] >= 400) {
            $message = $decoded['mensagem'] ?? $decoded['message'] ?? 'MkAuth retornou erro HTTP.';
            throw new RuntimeException((string) $message);
        }

        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $message = $decoded['error']['text'] ?? $decoded['error']['message'] ?? 'MkAuth retornou erro.';
            throw new RuntimeException((string) $message);
        }

        return $decoded;
    }

    private function getJwtToken(): string
    {
        if ($this->jwtToken !== null) {
            return $this->jwtToken;
        }

        if ($this->apiToken !== null && $this->apiToken !== '') {
            $this->jwtToken = $this->apiToken;
            return $this->apiToken;
        }

        if ($this->clientId === null || $this->clientSecret === null || $this->clientId === '' || $this->clientSecret === '') {
            throw new RuntimeException('Cliente_id e Client_Secret do MkAuth precisam estar configurados.');
        }

        $headers = [
            'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
        ];

        $response = $this->sendHttpRequest('GET', $this->baseUrl . '/api/', [], $headers);
        $decoded = json_decode($response['body'], true);

        if (is_array($decoded)) {
            if (isset($decoded['error']) && is_array($decoded['error'])) {
                $message = $decoded['error']['text'] ?? $decoded['error']['message'] ?? 'MkAuth recusou a geracao do token.';
                throw new RuntimeException((string) $message);
            }

            foreach (['token', 'access_token', 'jwt', 'data.token'] as $key) {
                $value = $this->arrayGet($decoded, $key);

                if (is_string($value) && $value !== '') {
                    $this->jwtToken = $value;
                    return $value;
                }
            }
        }

        if ($response['status'] >= 400) {
            throw new RuntimeException('Falha ao autenticar no MkAuth. Verifique as credenciais e o HTTPS.');
        }

        if (preg_match('/[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+/', $response['body'], $matches)) {
            $this->jwtToken = $matches[0];
            return $matches[0];
        }

        throw new RuntimeException('Nao foi possivel extrair o token JWT do MkAuth: ' . $this->summarizeBody($response['body']));
    }

    private function sendHttpRequest(string $method, string $url, array $payload, array $headers): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Extensao cURL nao esta disponivel neste ambiente.');
        }

        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException('Falha ao iniciar requisicao cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
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

        return ['status' => $status, 'body' => (string) $body];
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

    private function summarizeBody(string $body): string
    {
        $summary = trim(strip_tags($body));
        $summary = preg_replace('/\s+/', ' ', $summary) ?? $summary;

        if ($summary === '') {
            return 'resposta vazia.';
        }

        return substr($summary, 0, 220);
    }

    private function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = trim($baseUrl);

        if ($baseUrl === '') {
            return '';
        }

        if (!preg_match('#^https?://#i', $baseUrl)) {
            $baseUrl = 'https://' . $baseUrl;
        }

        return rtrim($baseUrl, '/');
    }
}
