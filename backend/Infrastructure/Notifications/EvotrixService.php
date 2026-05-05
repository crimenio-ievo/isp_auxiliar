<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Infrastructure\Contracts\NotificationLogRepository;
use LogicException;

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

        if ($enabled && !$dryRun) {
            throw new LogicException('Envio real ainda nao foi habilitado nesta fase.');
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
}
