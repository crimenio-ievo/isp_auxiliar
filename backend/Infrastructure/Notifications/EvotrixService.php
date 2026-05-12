<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Infrastructure\Contracts\NotificationLogRepository;
use LogicException;
use RuntimeException;

/**
 * Camada de notificacao do Evotrix.
 *
 * Nesta fase o servico funciona apenas em modo seguro, simulando o envio e
 * registrando o evento em log local.
 */
final class EvotrixService
{
    public function __construct(
        private array $config,
        private NotificationLogRepository $notificationLogs
    ) {
    }

    public function sendMessage(
        string $telefone,
        string|array $mensagem,
        ?int $contractId = null,
        ?int $acceptanceId = null,
        bool $forceResend = false
    ): array {
        $telefone = trim($telefone);
        $messageParts = $this->normalizeMessageParts($mensagem);
        $enabled = (bool) ($this->config['enabled'] ?? false);
        $dryRun = (bool) ($this->config['dry_run'] ?? true);
        $allowOnlyTestPhone = (bool) ($this->config['allow_only_test_phone'] ?? true);
        $testPhone = trim((string) ($this->config['test_phone'] ?? ''));

        if ($telefone === '' || $messageParts === []) {
            throw new LogicException('Telefone e mensagem sao obrigatorios.');
        }

        $actualPhone = $telefone;
        $redirectedToTest = false;

        if ($allowOnlyTestPhone) {
            if ($testPhone === '') {
                throw new RuntimeException('EVOTRIX_ALLOW_ONLY_TEST_PHONE esta ativo, mas EVOTRIX_TEST_PHONE nao foi configurado.');
            }

            $actualPhone = $testPhone;
            $redirectedToTest = $actualPhone !== $telefone;
        }

        $lastAttempt = $this->notificationLogs->findLatestForRecipient(
            $contractId,
            $acceptanceId,
            (string) ($this->config['channel'] ?? 'whatsapp'),
            'evotrix',
            $actualPhone
        );

        $lastAttemptStatus = (string) ($lastAttempt['status'] ?? '');
        $duplicateShouldBlock = !$forceResend && (
            ($dryRun && in_array($lastAttemptStatus, ['simulado', 'enviado'], true))
            || (!$dryRun && $lastAttemptStatus === 'enviado')
        );

        $messageSummary = count($messageParts) > 1
            ? 'WhatsApp em ' . count($messageParts) . ' mensagens'
            : $messageParts[0];

        if (is_array($lastAttempt) && $duplicateShouldBlock) {
            $response = [
                'status' => (string) ($lastAttempt['status'] ?? 'simulado'),
                'provider' => 'evotrix',
                'dry_run' => $dryRun,
                'enabled' => $enabled,
                'recipient' => $actualPhone,
                'original_recipient' => $telefone,
                'redirected_to_test' => $redirectedToTest,
                'message' => $messageSummary,
                'message_count' => count($messageParts),
                'messages' => $messageParts,
                'queued_at' => date('Y-m-d H:i:s'),
                'repeated_attempt' => true,
                'force_resend' => $forceResend,
                'message_id' => $lastAttempt['id'] ?? null,
                'detail' => 'Ja existe um envio preparado anteriormente para este aceite.',
            ];

            $this->notificationLogs->create([
                'contract_id' => $contractId,
                'acceptance_id' => $acceptanceId,
                'channel' => 'whatsapp',
                'provider' => 'evotrix',
                'recipient' => $actualPhone,
                'message' => $messageSummary,
                'status' => $dryRun ? 'simulado' : 'enviado',
                'provider_response' => $response,
            ]);

            return $response;
        }

        if ($enabled && !$dryRun) {
            $partsResponses = [];
            $status = 'enviado';
            $errorResponse = null;

            foreach ($messageParts as $index => $partMessage) {
                $partResponse = $this->dispatchRealMessage($actualPhone, $partMessage, $forceResend);
                $partResponse['part_number'] = $index + 1;
                $partResponse['part_total'] = count($messageParts);
                $partResponse['message'] = $partMessage;
                $partsResponses[] = $partResponse;

                if (($partResponse['status'] ?? 'enviado') === 'erro') {
                    $status = 'erro';
                    $errorResponse = $partResponse;
                    break;
                }
            }

            $response = [
                'status' => $status,
                'provider' => 'evotrix',
                'dry_run' => false,
                'enabled' => true,
                'recipient' => $actualPhone,
                'original_recipient' => $telefone,
                'redirected_to_test' => $redirectedToTest,
                'message' => $messageSummary,
                'message_count' => count($messageParts),
                'messages' => $messageParts,
                'parts' => $partsResponses,
                'queued_at' => date('Y-m-d H:i:s'),
                'force_resend' => $forceResend,
            ];

            if ($errorResponse !== null) {
                $response['detail'] = (string) ($errorResponse['detail'] ?? $errorResponse['response']['message'] ?? 'Falha ao enviar uma das mensagens.');
                $response['http_status'] = (int) ($errorResponse['http_status'] ?? 0);
            } else {
                $response['http_status'] = (int) ($partsResponses[array_key_last($partsResponses)]['http_status'] ?? 0);
            }

            $this->notificationLogs->create([
                'contract_id' => $contractId,
                'acceptance_id' => $acceptanceId,
                'channel' => 'whatsapp',
                'provider' => 'evotrix',
                'recipient' => $actualPhone,
                'message' => $messageSummary,
                'status' => $status,
                'provider_response' => $response,
            ]);

            return $response;
        }

        $response = [
            'status' => 'simulado',
            'provider' => 'evotrix',
            'dry_run' => true,
            'enabled' => $enabled,
            'recipient' => $actualPhone,
            'original_recipient' => $telefone,
            'redirected_to_test' => $redirectedToTest,
            'message' => $messageSummary,
            'message_count' => count($messageParts),
            'messages' => $messageParts,
            'queued_at' => date('Y-m-d H:i:s'),
            'force_resend' => $forceResend,
        ];

        $this->notificationLogs->create([
            'contract_id' => $contractId,
            'acceptance_id' => $acceptanceId,
            'channel' => 'whatsapp',
            'provider' => 'evotrix',
            'recipient' => $actualPhone,
            'message' => $messageSummary,
            'status' => 'simulado',
            'provider_response' => $response,
        ]);

        return $response;
    }

    /**
     * @return string[]
     */
    private function normalizeMessageParts(string|array $mensagem): array
    {
        $parts = is_array($mensagem) ? $mensagem : [$mensagem];
        $normalized = [];

        foreach ($parts as $part) {
            $text = trim((string) $part);
            if ($text === '') {
                continue;
            }

            $normalized[] = $text;
        }

        return array_values($normalized);
    }

    private function dispatchRealMessage(string $telefone, string $mensagem, bool $forceResend = false): array
    {
        $baseUrl = rtrim(trim((string) ($this->config['base_url'] ?? '')), '/');
        $endpoint = trim((string) ($this->config['endpoint'] ?? '/v1/services/whatsapp/notifications/text'));
        $token = trim((string) ($this->config['token'] ?? ''));
        $timeoutSeconds = max(5, (int) ($this->config['timeout_seconds'] ?? 15));
        $retryAttempts = max(0, (int) ($this->config['retry_attempts'] ?? 1));

        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('Evotrix precisa de base_url e token para envio real.');
        }

        $requestCandidates = $this->buildRequestCandidates($baseUrl, $endpoint);

        if (!function_exists('curl_init')) {
            throw new RuntimeException('Extensao cURL nao esta disponivel para envio Evotrix.');
        }

        $payload = [
            'recipient' => $telefone,
            'body' => $mensagem,
            'campaign' => (string) ($this->config['campaign'] ?? 'nossa equipe'),
        ];
        if (trim((string) ($this->config['channel_id'] ?? '')) !== '') {
            $payload['channel'] = trim((string) ($this->config['channel_id'] ?? ''));
        }

        $attempts = 0;
        $lastError = '';
        $body = false;
        $status = 0;
        $startedAt = microtime(true);

        do {
            $attempts += 1;
            $requestUrl = $requestCandidates[min($attempts - 1, count($requestCandidates) - 1)];
            $ch = curl_init($requestUrl);

            if ($ch === false) {
                throw new RuntimeException('Falha ao iniciar envio Evotrix.');
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $token,
                ],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);

            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $lastError = curl_error($ch);
            curl_close($ch);

            $isRouteMissing = is_string($body) && str_contains($body, 'E_ROUTE_NOT_FOUND');
            if ($body !== false && $status > 0 && $status < 500 && !$isRouteMissing) {
                break;
            }
        } while ($attempts <= $retryAttempts && $attempts < count($requestCandidates));

        if ($body === false) {
            throw new RuntimeException('Falha ao enviar mensagem pelo Evotrix: ' . $lastError);
        }

        $decoded = json_decode((string) $body, true);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        return [
            'status' => $status >= 400 ? 'erro' : 'enviado',
            'provider' => 'evotrix',
            'dry_run' => false,
            'enabled' => true,
            'recipient' => $telefone,
            'queued_at' => date('Y-m-d H:i:s'),
            'http_status' => $status,
            'duration_ms' => $durationMs,
            'attempts' => $attempts,
            'endpoint' => $endpoint,
            'request_url' => $requestUrl,
            'response' => is_array($decoded) ? $decoded : (string) $body,
            'force_resend' => $forceResend,
        ];
    }

    private function buildRequestCandidates(string $baseUrl, string $endpoint): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        $endpoint = '/' . ltrim($endpoint, '/');

        if ($endpoint === '/') {
            return [$baseUrl];
        }

        $candidates = [$baseUrl . $endpoint];
        if (!str_starts_with($endpoint, '/api/')) {
            $candidates[] = $baseUrl . '/api' . $endpoint;
        }

        if (!str_starts_with($endpoint, '/v1/services/')) {
            $candidates[] = $baseUrl . '/v1/services' . $endpoint;
        }

        return array_values(array_unique($candidates));
    }
}
