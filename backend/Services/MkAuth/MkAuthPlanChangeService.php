<?php

declare(strict_types=1);

namespace App\Services\MkAuth;

use App\Infrastructure\MkAuth\MkAuthClient;
use App\Infrastructure\MkAuth\MkAuthDatabase;
use App\Infrastructure\MkAuth\MkAuthWriteGuard;

/**
 * Prepara alteração nativa de plano sem tocar em Radius, MikroTik ou financeiro.
 */
final class MkAuthPlanChangeService
{
    public function __construct(
        private MkAuthDatabase $mkauthDatabase,
        private MkAuthClient $mkauthClient,
        private MkAuthWriteGuard $writeGuard
    ) {
    }

    public function prepareDryRun(string $login, string $newPlan): array
    {
        $login = strtolower(trim($login));
        $newPlan = trim($newPlan);
        if ($login === '' || $newPlan === '') {
            throw new \InvalidArgumentException('Login e novo plano são obrigatórios.');
        }

        $client = $this->mkauthDatabase->findClientProfile($login);
        if (!is_array($client)) {
            throw new \RuntimeException('Cliente não localizado no MkAuth.');
        }

        $plan = null;
        foreach ($this->mkauthDatabase->listPlans() as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $candidateName = trim((string) ($candidate['nome'] ?? ''));
            $candidateUuid = trim((string) ($candidate['uuid_plano'] ?? ''));
            if (strcasecmp($candidateName, $newPlan) === 0 || ($candidateUuid !== '' && hash_equals($candidateUuid, $newPlan))) {
                $plan = $candidate;
                break;
            }
        }
        if (!is_array($plan)) {
            throw new \RuntimeException('Plano de destino não localizado no catálogo do MkAuth.');
        }

        return $this->buildDryRunFromSnapshots($login, $client, $plan);
    }

    public function buildDryRunFromSnapshots(string $login, array $client, array $plan): array
    {
        $login = strtolower(trim($login));
        if ($login === '') {
            throw new \InvalidArgumentException('Login obrigatório para preparar o dry-run.');
        }

        $uuid = trim((string) ($client['uuid_cliente'] ?? ''));
        if ($uuid === '') {
            throw new \RuntimeException('O cliente não possui UUID para a API nativa de edição.');
        }

        $planName = trim((string) ($plan['nome'] ?? ''));
        if ($planName === '') {
            throw new \RuntimeException('O plano de destino não possui nome válido.');
        }
        $payload = [
            'uuid' => $uuid,
            'plano' => $planName,
        ];

        return [
            'status' => 'simulated',
            'dry_run' => true,
            'write_enabled' => $this->writeGuard->isWriteEnabled(),
            'login' => $login,
            'client_uuid' => $uuid,
            'before' => [
                'plan' => (string) ($client['plano_nome'] ?? $client['plano'] ?? ''),
                'technology' => (string) ($client['plano_tecnologia'] ?? ''),
                'monthly_value' => (string) ($client['plano_valor'] ?? ''),
                'online' => !empty($client['online_now']),
            ],
            'after' => [
                'plan' => $planName,
                'plan_uuid' => (string) ($plan['uuid_plano'] ?? ''),
                'technology' => (string) ($plan['tecnologia'] ?? ''),
                'monthly_value' => (string) ($plan['valor'] ?? ''),
            ],
            'payload' => $payload,
            'endpoint' => '/api/cliente/editar',
            'session_disconnect' => [
                'supported' => false,
                'automatic' => false,
                'next_action' => 'Desconectar ou reiniciar o equipamento manualmente, se necessário, e validar a reconexão.',
            ],
            'financial_recalculation' => [
                'supported' => false,
                'automatic' => false,
                'next_action' => 'Encaminhar a revisão de boletos/carnês pelo chamado financeiro.',
            ],
            'message' => $this->writeGuard->isWriteEnabled()
                ? 'Dry-run preparado. A aplicação real ainda exige confirmação explícita.'
                : 'Dry-run preparado. A escrita está bloqueada e nenhuma alteração foi enviada ao MkAuth.',
        ];
    }

    public function apply(array $dryRun): array
    {
        if (empty($dryRun['dry_run']) || !is_array($dryRun['payload'] ?? null)) {
            throw new \InvalidArgumentException('Proposta de alteração inválida.');
        }

        $this->writeGuard->assertAllowed('api PUT /api/cliente/editar change plan');
        $response = $this->mkauthClient->updateClient($dryRun['payload']);

        return [
            'status' => 'submitted',
            'dry_run' => false,
            'payload' => $dryRun['payload'],
            'response' => $response,
        ];
    }
}
