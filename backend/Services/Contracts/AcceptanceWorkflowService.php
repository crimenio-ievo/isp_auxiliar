<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Core\Config;

/**
 * Núcleo único para preparar tokens e metadados de aceite.
 *
 * A persistência, os canais e a página pública continuam nos componentes
 * existentes. Este serviço elimina regras divergentes de token, expiração e
 * versão entre instalação, migração e solicitação avulsa.
 */
final class AcceptanceWorkflowService
{
    public function __construct(private Config $config)
    {
    }

    public function prepare(array $context): array
    {
        $contractId = (int) ($context['contract_id'] ?? 0);
        if ($contractId <= 0) {
            throw new \InvalidArgumentException('Contrato inválido para preparar o aceite.');
        }

        $termHash = strtolower(trim((string) ($context['term_hash'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $termHash)) {
            throw new \InvalidArgumentException('Hash imutável do documento inválido.');
        }

        $signatureMode = (string) ($context['signature_mode'] ?? 'remote') === 'local'
            ? 'local'
            : 'remote';
        $ttlHours = max(
            1,
            (int) ($context['ttl_hours']
                ?? $this->config->get('contracts.commercial.validade_link_aceite_horas', 48))
        );
        $token = trim((string) ($context['token'] ?? ''));
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
        }

        return [
            'contract_id' => $contractId,
            'technician_name' => trim((string) ($context['technician_name'] ?? '')) ?: null,
            'technician_login' => trim((string) ($context['technician_login'] ?? '')) ?: null,
            'token' => $token,
            'token_expires_at' => (new \DateTimeImmutable())
                ->modify('+' . $ttlHours . ' hours')
                ->format('Y-m-d H:i:s'),
            'status' => (string) ($context['status'] ?? ($signatureMode === 'remote' ? 'assinatura_pendente' : 'criado')),
            'telefone_enviado' => trim((string) ($context['phone'] ?? '')),
            'remote_signature_reason' => $signatureMode === 'remote'
                ? (trim((string) ($context['remote_signature_reason'] ?? '')) ?: null)
                : null,
            'whatsapp_message_id' => null,
            'sent_at' => null,
            'accepted_at' => null,
            'ip_address' => trim((string) ($context['ip_address'] ?? '')),
            'user_agent' => trim((string) ($context['user_agent'] ?? '')),
            'termo_versao' => trim((string) ($context['document_version']
                ?? $this->config->get('contracts.term_version', '2026.1'))),
            'termo_hash' => $termHash,
            'pdf_path' => null,
            'evidence_json_path' => null,
        ];
    }
}
