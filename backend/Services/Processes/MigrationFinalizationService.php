<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Infrastructure\Contracts\FinancialTaskRepository;
use App\Infrastructure\MkAuth\ClientPlanConfirmationService;
use App\Infrastructure\MkAuth\MkAuthDatabase;
use App\Infrastructure\MkAuth\MkAuthTicketService;
use App\Services\MkAuth\MkAuthPlanChangeService;

/**
 * Orquestra a etapa final sobre o motor operacional existente. Cada subetapa
 * concluída fica no próprio checklist e não é repetida em uma nova tentativa.
 */
final class MigrationFinalizationService
{
    public function __construct(
        private OperationalProcessService $processes,
        private MkAuthPlanChangeService $planChange,
        private ClientPlanConfirmationService $planConfirmation,
        private MkAuthDatabase $mkauthDatabase,
        private MkAuthTicketService $ticketService,
        private FinancialTaskRepository $financialTasks
    ) {
    }

    public function run(int $processId, array $operator, ?string $requestId = null): array
    {
        $process = $this->processes->detail($processId);
        if (!is_array($process) || (string) ($process['process_type'] ?? '') !== OperationalProcessService::TYPE_MIGRATION) {
            throw new \RuntimeException('Processo de migração não localizado.');
        }
        if (in_array((string) ($process['status'] ?? ''), ['completed', 'cancelled'], true)) {
            throw new \RuntimeException('Este processo não aceita nova finalização.');
        }

        $requestId = trim((string) $requestId);
        if ($requestId === '') {
            $requestId = $this->existingRequestId($process) ?: bin2hex(random_bytes(16));
        }
        $this->assertPrerequisites($process);
        $result = ['request_id' => $requestId, 'status' => 'running', 'actions' => []];
        $login = (string) ($process['mkauth_login'] ?? '');
        $migration = is_array($process['metadata']['migration'] ?? null) ? $process['metadata']['migration'] : [];
        $targetPlan = trim((string) ($migration['new_plan_id'] ?? $migration['new_plan_name'] ?? ''));

        if (!$this->stepCompleted($process, 'change_plan')) {
            try {
                $dryRun = $this->planChange->prepareDryRun($login, $targetPlan);
                if (empty($dryRun['write_enabled'])) {
                    $this->processes->updateStep($processId, 'change_plan', 'defer', [
                        'observation' => 'Orquestração preparada em dry-run.',
                        'pending_reason' => 'Escrita no MkAuth bloqueada neste ambiente.',
                        'next_action' => 'Autorizar a escrita no ambiente correto e tentar novamente.',
                        'evidence' => array_merge($dryRun, ['request_id' => $requestId]),
                    ], $operator);
                    return array_merge($result, [
                        'status' => 'waiting_mkauth',
                        'message' => 'Finalização preparada, mas a escrita no MkAuth está bloqueada. Nenhuma alteração foi enviada.',
                        'actions' => [['key' => 'change_plan', 'status' => 'dry_run']],
                    ]);
                }
                $apply = $this->planChange->apply($dryRun);
                $expected = $this->planConfirmation->resolveExpectedPlan($targetPlan);
                $confirmation = $this->planConfirmation->confirm($login, $expected);
                if (empty($confirmation['confirmed'])) {
                    throw new \RuntimeException('O plano enviado não foi confirmado pela releitura do MkAuth.');
                }
                $this->processes->updateStep($processId, 'change_plan', 'complete', [
                    'observation' => 'Plano alterado e confirmado por releitura.',
                    'external_reference' => (string) ($confirmation['client_uuid'] ?? ''),
                    'evidence' => [
                        'request_id' => $requestId,
                        'dry_run' => false,
                        'apply_status' => (string) ($apply['status'] ?? ''),
                        'plan_confirmation' => $confirmation,
                    ],
                ], $operator);
                $result['actions'][] = ['key' => 'change_plan', 'status' => 'completed'];
                $process = $this->processes->detail($processId) ?? $process;
            } catch (\Throwable $exception) {
                $this->processes->updateStep($processId, 'change_plan', 'attention', [
                    'observation' => 'Falha parcial na aplicação ou conferência do plano.',
                    'pending_reason' => $exception->getMessage(),
                    'next_action' => 'Corrigir a causa e tentar a finalização novamente.',
                    'evidence' => ['request_id' => $requestId, 'dry_run' => false],
                ], $operator);
                return array_merge($result, [
                    'status' => 'attention',
                    'message' => 'O plano não foi confirmado. As ações seguintes não foram executadas.',
                    'actions' => array_merge($result['actions'], [['key' => 'change_plan', 'status' => 'failed']]),
                ]);
            }
        } else {
            $result['actions'][] = ['key' => 'change_plan', 'status' => 'skipped_completed'];
        }

        if (!$this->connectionConfirmedForRequest($process, $requestId)) {
            try {
                $connection = $this->mkauthDatabase->radiusConnectionStatus($login);
            } catch (\Throwable) {
                $connection = ['available' => false, 'online' => false];
            }
            if (empty($connection['online'])) {
                $previousValidation = $this->step($process, 'validate_connection');
                $previousEvidence = is_array($previousValidation['evidence'] ?? null) ? $previousValidation['evidence'] : [];
                $authorizedException = !empty($previousEvidence['authorized_exception']);
                $this->processes->updateStep($processId, 'validate_connection', $authorizedException ? 'complete' : 'attention', [
                    'observation' => $authorizedException
                        ? 'PPPoE não confirmado após o plano; exceção gerencial previamente registrada e preservada.'
                        : 'Consulta automática realizada após a confirmação do plano.',
                    'pending_reason' => 'Plano alterado, mas a reconexão ainda não foi confirmada.',
                    'next_action' => 'Orientar reconexão manual e atualizar a situação.',
                    'evidence' => array_merge($previousEvidence, [
                        'request_id' => $requestId,
                        'online' => false,
                        'available' => (bool) ($connection['available'] ?? false),
                        'authorized_exception' => $authorizedException,
                        'checked_at' => date('Y-m-d H:i:s'),
                    ]),
                ], $operator);
                if (!$authorizedException) {
                    return array_merge($result, [
                        'status' => 'attention',
                        'message' => 'Plano alterado, mas a reconexão ainda não foi confirmada.',
                        'actions' => array_merge($result['actions'], [['key' => 'validate_connection', 'status' => 'failed']]),
                    ]);
                }
                $result['actions'][] = ['key' => 'validate_connection', 'status' => 'authorized_exception'];
                $process = $this->processes->detail($processId) ?? $process;
            } else {
                $this->processes->updateStep($processId, 'validate_connection', 'complete', [
                    'observation' => 'Reconexão PPPoE confirmada automaticamente após a alteração do plano.',
                    'evidence' => ['request_id' => $requestId, 'online' => true, 'available' => true, 'checked_at' => date('Y-m-d H:i:s')],
                ], $operator);
                $result['actions'][] = ['key' => 'validate_connection', 'status' => 'completed'];
                $process = $this->processes->detail($processId) ?? $process;
            }
        } else {
            $result['actions'][] = ['key' => 'validate_connection', 'status' => 'skipped_completed'];
        }

        if (!$this->stepCompleted($process, 'open_financial_ticket')) {
            $task = $this->financialTasks->findByContractId((int) ($process['contract_id'] ?? 0));
            try {
                if (!is_array($task) || (int) ($task['id'] ?? 0) <= 0) {
                    throw new \RuntimeException('A tarefa financeira compartilhada não foi localizada.');
                }
                $ticket = $this->ticketService->openFinancialTicket([
                    'login' => $login,
                    'nome' => (string) ($process['client_name'] ?? ''),
                    'assunto' => 'Financeiro - Upgrade / Migração',
                    'descricao' => (string) ($task['descricao'] ?? 'Revisar faturamento após Upgrade / Migração.'),
                ]);
            } catch (\Throwable $exception) {
                $this->processes->updateStep($processId, 'open_financial_ticket', 'attention', [
                    'observation' => 'Falha parcial após a conclusão das verificações técnicas.',
                    'pending_reason' => $exception->getMessage(),
                    'next_action' => 'Corrigir a integração financeira e tentar novamente; plano e conexão não serão repetidos.',
                    'evidence' => ['request_id' => $requestId, 'dry_run' => true],
                ], $operator);
                return array_merge($result, [
                    'status' => 'attention',
                    'message' => 'Plano e conexão concluídos, mas o chamado financeiro não foi aberto.',
                    'actions' => array_merge($result['actions'], [['key' => 'open_financial_ticket', 'status' => 'failed']]),
                ]);
            }
            if (!empty($ticket['dry_run'])) {
                $this->processes->updateStep($processId, 'open_financial_ticket', 'defer', [
                    'observation' => 'Chamado preparado em dry-run.',
                    'pending_reason' => 'Abertura real de chamado bloqueada neste ambiente.',
                    'next_action' => 'Abrir o chamado no ambiente autorizado e tentar novamente.',
                    'evidence' => ['request_id' => $requestId, 'dry_run' => true, 'status' => (string) ($ticket['status'] ?? '')],
                ], $operator);
                return array_merge($result, [
                    'status' => 'waiting_financial',
                    'message' => 'Atendimento técnico concluído. Processo geral aguardando abertura financeira autorizada.',
                    'actions' => array_merge($result['actions'], [['key' => 'open_financial_ticket', 'status' => 'dry_run']]),
                ]);
            }
            $ticketId = trim((string) ($ticket['ticket_id'] ?? ''));
            if ($ticketId === '') {
                throw new \RuntimeException('O MkAuth não devolveu o identificador do chamado financeiro.');
            }
            $this->financialTasks->updateTicketMetadata((int) $task['id'], [
                'mkauth_ticket_id' => $ticketId,
                'mkauth_ticket_status' => 'aberto',
                'mkauth_ticket_checked_at' => date('Y-m-d H:i:s'),
            ]);
            $this->processes->updateStep($processId, 'open_financial_ticket', 'complete', [
                'observation' => 'Chamado financeiro aberto automaticamente.',
                'external_reference' => $ticketId,
                'evidence' => ['request_id' => $requestId, 'dry_run' => false, 'ticket_id' => $ticketId],
            ], $operator);
            $result['actions'][] = ['key' => 'open_financial_ticket', 'status' => 'completed'];
        } else {
            $result['actions'][] = ['key' => 'open_financial_ticket', 'status' => 'skipped_completed'];
        }

        return array_merge($result, [
            'status' => 'waiting_financial',
            'message' => 'Atendimento técnico concluído. Processo geral aguardando revisão financeira.',
        ]);
    }

    private function assertPrerequisites(array $process): void
    {
        $missing = [];
        foreach (['confirm_acceptance' => 'aceite confirmado', 'technical_execution' => 'execução técnica', 'confirm_equipment' => 'equipamento confirmado'] as $key => $label) {
            if (!$this->stepCompleted($process, $key)) {
                $missing[] = $label;
            }
        }
        if ($missing !== []) {
            throw new \RuntimeException('Pré-requisitos pendentes: ' . implode(', ', $missing) . '.');
        }
    }

    private function stepCompleted(array $process, string $stepKey): bool
    {
        foreach ((array) ($process['steps'] ?? []) as $step) {
            if (is_array($step) && (string) ($step['step_key'] ?? '') === $stepKey) {
                return in_array((string) ($step['status'] ?? ''), ['completed', 'not_applicable'], true);
            }
        }

        return false;
    }

    private function step(array $process, string $stepKey): array
    {
        foreach ((array) ($process['steps'] ?? []) as $step) {
            if (is_array($step) && (string) ($step['step_key'] ?? '') === $stepKey) {
                return $step;
            }
        }

        return [];
    }

    private function connectionConfirmedForRequest(array $process, string $requestId): bool
    {
        $step = $this->step($process, 'validate_connection');

        return in_array((string) ($step['status'] ?? ''), ['completed', 'not_applicable'], true)
            && hash_equals($requestId, (string) ($step['evidence']['request_id'] ?? ''));
    }

    private function existingRequestId(array $process): string
    {
        foreach ((array) ($process['steps'] ?? []) as $step) {
            if (!is_array($step) || (string) ($step['step_key'] ?? '') !== 'change_plan') {
                continue;
            }
            return trim((string) ($step['evidence']['request_id'] ?? ''));
        }

        return '';
    }
}
