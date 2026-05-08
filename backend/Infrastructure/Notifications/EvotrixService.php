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
        string $mensagem,
        ?int $contractId = null,
        ?int $acceptanceId = null
    ): array {
        $telefone = trim($telefone);
        $mensagem = trim($mensagem);
        $enabled = (bool) ($this->config['enabled'] ?? false);
        $dryRun = (bool) ($this->config['dry_run'] ?? true);

        if ($telefone === '' || $mensagem === '') {
            throw new LogicException('Telefone e mensagem sao obrigatorios.');
        }

        $lastAttempt = $this->notificationLogs->findLatestForRecipient(
            $contractId,
            $acceptanceId,
            (string) ($this->config['channel'] ?? 'whatsapp'),
            'evotrix',
            $telefone
        );

        if (is_array($lastAttempt) && in_array((string) ($lastAttempt['status'] ?? ''), ['simulado', 'enviado'], true)) {
            $response = [
                'status' => (string) ($lastAttempt['status'] ?? 'simulado'),
                'provider' => 'evotrix',
                'dry_run' => $dryRun,
                'enabled' => $enabled,
                'recipient' => $telefone,
                'message' => $mensagem,
                'queued_at' => date('Y-m-d H:i:s'),
                'repeated_attempt' => true,
                'message_id' => $lastAttempt['id'] ?? null,
                'detail' => 'Ja existe um envio preparado anteriormente para este aceite.',
            ];

            $this->notificationLogs->create([
                'contract_id' => $contractId,
                'acceptance_id' => $acceptanceId,
                'channel' => 'whatsapp',
                'provider' => 'evotrix',
                'recipient' => $telefone,
                'message' => $mensagem,
                'status' => $enabled && !$dryRun ? 'erro' : 'simulado',
                'provider_response' => $response,
            ]);

            return $response;
        }

        if ($enabled && !$dryRun) {
            $response = $this->dispatchRealMessage($telefone, $mensagem);

            $this->notificationLogs->create([
                'contract_id' => $contractId,
                'acceptance_id' => $acceptanceId,
                'channel' => 'whatsapp',
                'provider' => 'evotrix',
                'recipient' => $telefone,
                'message' => $mensagem,
                'status' => 'enviado',
                'provider_response' => $response,
            ]);

            return $response;
        }

        $response = [
            'status' => 'simulado',
            'provider' => 'evotrix',
            'dry_run' => true,
            'enabled' => $enabled,
            'recipient' => $telefone,
            'message' => $mensagem,
            'queued_at' => date('Y-m-d H:i:s'),
        ];

        $this->notificationLogs->create([
            'contract_id' => $contractId,
            'acceptance_id' => $acceptanceId,
            'channel' => 'whatsapp',
            'provider' => 'evotrix',
            'recipient' => $telefone,
            'message' => $mensagem,
            'status' => 'simulado',
            'provider_response' => $response,
        ]);

        return $response;
    }

    private function dispatchRealMessage(string $telefone, string $mensagem): array
    {
        $baseUrl = rtrim(trim((string) ($this->config['base_url'] ?? '')), '/');
        $token = trim((string) ($this->config['token'] ?? ''));

        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('Evotrix precisa de base_url e token para envio real.');
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('Extensao cURL nao esta disponivel para envio Evotrix.');
        }

        $payload = [
            'to' => $telefone,
            'message' => $mensagem,
            'sender' => (string) ($this->config['sender'] ?? 'ISP Auxiliar'),
        ];

        $ch = curl_init($baseUrl);

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
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Falha ao enviar mensagem pelo Evotrix: ' . $error);
        }

        $decoded = json_decode((string) $body, true);

        return [
            'status' => $status >= 400 ? 'erro' : 'enviado',
            'provider' => 'evotrix',
            'dry_run' => false,
            'enabled' => true,
            'recipient' => $telefone,
            'message' => $mensagem,
            'queued_at' => date('Y-m-d H:i:s'),
            'response' => is_array($decoded) ? $decoded : (string) $body,
        ];
    }
}
