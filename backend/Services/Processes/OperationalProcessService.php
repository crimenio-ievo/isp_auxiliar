<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Infrastructure\Contracts\ContractAcceptanceRepository;
use App\Infrastructure\Contracts\FinancialTaskRepository;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\Processes\OperationalProcessRepository;

/**
 * Orquestra checklists reutilizáveis, retomada e bloqueio de conclusão.
 */
final class OperationalProcessService
{
    public const TYPE_INSTALLATION = 'installation';
    public const TYPE_MIGRATION = 'migration';
    public const TYPE_STANDALONE_SIGNATURE = 'standalone_signature';

    private const FINAL_STEPS = [
        'complete_installation',
        'complete_migration',
        'complete_signature_request',
    ];

    public function __construct(
        private Database $database,
        private OperationalProcessRepository $processRepository,
        private ContractAcceptanceRepository $acceptanceRepository,
        private FinancialTaskRepository $financialTaskRepository,
        private LocalRepository $localRepository
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->processRepository->isAvailable();
    }

    public function ensureForContract(
        string $processType,
        array $contract,
        array $acceptance,
        array $context = [],
        array $operator = []
    ): array {
        $this->assertProcessType($processType);
        if (!$this->isAvailable()) {
            return [];
        }

        $contractId = (int) ($contract['id'] ?? $contract['contract_id'] ?? 0);
        $acceptanceId = (int) ($acceptance['id'] ?? $context['acceptance_id'] ?? 0);
        $login = strtolower(trim((string) ($contract['mkauth_login'] ?? $context['login'] ?? '')));
        if ($contractId <= 0 || $login === '') {
            throw new \InvalidArgumentException('Contrato e login são obrigatórios para iniciar o processo.');
        }

        $process = $this->processRepository->findByContractAndType($contractId, $processType);
        if (!is_array($process)) {
            $operatorLogin = trim((string) ($operator['login'] ?? $contract['technician_login'] ?? ''));
            $operatorName = trim((string) ($operator['name'] ?? $contract['technician_name'] ?? $operatorLogin));
            $financialTask = $this->financialTaskRepository->findByContractId($contractId);
            $processId = $this->processRepository->create([
                'process_type' => $processType,
                'mkauth_login' => $login,
                'client_name' => (string) ($contract['nome_cliente'] ?? $context['client_name'] ?? ''),
                'status' => 'in_progress',
                'responsible_user_id' => isset($operator['id']) ? (int) $operator['id'] : null,
                'responsible_login' => $operatorLogin,
                'responsible_name' => $operatorName,
                'created_by_user_id' => isset($operator['id']) ? (int) $operator['id'] : null,
                'created_by_login' => $operatorLogin,
                'registration_id' => (int) ($contract['client_id'] ?? $context['registration_id'] ?? 0),
                'contract_id' => $contractId,
                'acceptance_id' => $acceptanceId,
                'financial_task_id' => (int) ($financialTask['id'] ?? 0),
                'metadata' => $this->buildMetadata($processType, $contract, $context),
                'started_at' => date('Y-m-d H:i:s'),
            ]);
            $this->processRepository->createSteps($processId, $this->stepDefinitions($processType));
            $process = $this->processRepository->findById($processId);
        }

        if (!is_array($process)) {
            throw new \RuntimeException('O processo operacional não pôde ser preparado.');
        }

        $processId = (int) $process['id'];
        if ($acceptanceId > 0) {
            $documentVersion = trim((string) ($acceptance['termo_versao'] ?? $context['document_version'] ?? '2026.1'));
            $documentType = $this->documentType($processType);
            $existingDocument = $this->processRepository->findDocument($processId, $documentType, $documentVersion);
            $previousAcceptanceId = (int) ($existingDocument['active_acceptance_id'] ?? 0);
            if ($previousAcceptanceId > 0 && $previousAcceptanceId !== $acceptanceId) {
                $previousAcceptance = $this->acceptanceRepository->findById($previousAcceptanceId);
                if (is_array($previousAcceptance)
                    && in_array((string) ($previousAcceptance['status'] ?? ''), ['criado', 'enviado', 'assinatura_pendente'], true)
                    && trim((string) ($previousAcceptance['revoked_at'] ?? '')) === ''
                ) {
                    $this->acceptanceRepository->revoke(
                        $previousAcceptanceId,
                        'Substituído por novo aceite ativo do mesmo documento e versão.',
                        isset($operator['id']) ? (int) $operator['id'] : null,
                        (string) ($operator['login'] ?? ''),
                        false
                    );
                }
            }
            $documentId = $this->processRepository->upsertDocument(
                $processId,
                $contractId,
                $documentType,
                $documentVersion !== '' ? $documentVersion : '2026.1',
                [
                    'contract' => $this->contractSnapshot($contract),
                    'process_type' => $processType,
                    'document_version' => $documentVersion,
                    'term_hash' => (string) ($acceptance['termo_hash'] ?? ''),
                    'prepared_at' => (string) ($acceptance['created_at'] ?? date('Y-m-d H:i:s')),
                ],
                $acceptanceId
            );
            if ($documentId > 0) {
                $this->processRepository->linkAcceptance($processId, $documentId, $acceptanceId);
            }
        }

        $this->completePreparationSteps($processId, $processType, $operator, $context);
        $this->synchronizeExternalState($processId);

        return $this->detail($processId) ?? [];
    }

    public function synchronizeAcceptance(int $acceptanceId): void
    {
        if ($acceptanceId <= 0 || !$this->isAvailable()) {
            return;
        }

        $process = $this->processRepository->findByAcceptanceId($acceptanceId);
        if (!is_array($process)) {
            return;
        }

        $this->synchronizeExternalState((int) $process['id']);
    }

    public function synchronizeExternalState(int $processId): void
    {
        $process = $this->processRepository->findById($processId);
        if (!is_array($process)) {
            return;
        }

        $acceptanceId = (int) ($process['acceptance_id'] ?? 0);
        $acceptance = $acceptanceId > 0 ? $this->acceptanceRepository->findById($acceptanceId) : null;
        if (is_array($acceptance)) {
            $status = (string) ($acceptance['status'] ?? '');
            $revoked = trim((string) ($acceptance['revoked_at'] ?? '')) !== '';
            $notification = $this->database->fetchOne(
                'SELECT channel, status, created_at
                 FROM notification_logs
                 WHERE acceptance_id = :acceptance_id
                 ORDER BY id DESC
                 LIMIT 1',
                ['acceptance_id' => $acceptanceId]
            );

            if (is_array($notification) || $status === 'enviado') {
                $this->completeStepAutomatically(
                    $processId,
                    'send_acceptance',
                    ['notification' => $notification, 'acceptance_status' => $status]
                );
                $this->processRepository->markDocumentStatusByAcceptance($acceptanceId, 'sent');
            } elseif ($status === 'criado' && trim((string) ($acceptance['remote_signature_reason'] ?? '')) === '') {
                $this->completeStepAutomatically(
                    $processId,
                    'send_acceptance',
                    ['channel' => 'local_device', 'acceptance_status' => $status]
                );
            }

            if ($status === 'aceito' && !$revoked) {
                $this->completeStepAutomatically(
                    $processId,
                    'confirm_acceptance',
                    [
                        'acceptance_id' => $acceptanceId,
                        'accepted_at' => (string) ($acceptance['accepted_at'] ?? ''),
                        'evidence_json_path' => (string) ($acceptance['evidence_json_path'] ?? ''),
                    ]
                );
                $this->processRepository->markDocumentStatusByAcceptance($acceptanceId, 'accepted');
            } elseif (in_array($status, ['criado', 'enviado', 'assinatura_pendente'], true) && !$revoked) {
                $confirmStep = $this->processRepository->findStep($processId, 'confirm_acceptance');
                if (is_array($confirmStep) && !in_array((string) ($confirmStep['status'] ?? ''), ['completed', 'attention'], true)) {
                    $this->processRepository->updateStep((int) $confirmStep['id'], [
                        'status' => 'waiting',
                        'started_at' => (string) ($confirmStep['started_at'] ?? '') ?: date('Y-m-d H:i:s'),
                        'pending_reason' => 'Aguardando a confirmação do titular.',
                        'next_action' => 'Atualizar a situação após o cliente concluir o aceite.',
                        'last_checked_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            } elseif ($revoked || $status === 'cancelado') {
                $this->processRepository->markDocumentStatusByAcceptance($acceptanceId, 'revoked');
                $step = $this->processRepository->findStep($processId, 'confirm_acceptance');
                if (is_array($step) && (string) ($step['status'] ?? '') !== 'completed') {
                    $this->processRepository->updateStep((int) $step['id'], [
                        'status' => 'attention',
                        'pending_reason' => 'O aceite vinculado foi cancelado, revogado ou substituído.',
                        'next_action' => 'Preparar e vincular um aceite ativo.',
                        'last_checked_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        }

        $financialTaskId = (int) ($process['financial_task_id'] ?? 0);
        $task = $financialTaskId > 0
            ? $this->financialTaskRepository->findById($financialTaskId)
            : ((int) ($process['contract_id'] ?? 0) > 0
                ? $this->financialTaskRepository->findByContractId((int) $process['contract_id'])
                : null);
        if (is_array($task)) {
            $this->processRepository->updateProcess($processId, [
                'financial_task_id' => (int) ($task['id'] ?? 0),
                'external_ticket_id' => (string) ($task['mkauth_ticket_id'] ?? ''),
            ]);
            if (trim((string) ($task['mkauth_ticket_id'] ?? '')) !== '') {
                $this->completeStepAutomatically($processId, 'open_financial_ticket', [
                    'ticket_id' => (string) $task['mkauth_ticket_id'],
                    'ticket_status' => (string) ($task['mkauth_ticket_status'] ?? ''),
                ]);
            }
            if ((string) ($task['status'] ?? '') === 'concluido'
                || strtolower((string) ($task['mkauth_ticket_status'] ?? '')) === 'fechado'
            ) {
                $this->completeStepAutomatically($processId, 'follow_financial_ticket', [
                    'ticket_id' => (string) ($task['mkauth_ticket_id'] ?? ''),
                    'ticket_status' => (string) ($task['mkauth_ticket_status'] ?? $task['status'] ?? ''),
                    'completed_at' => (string) ($task['completed_at'] ?? ''),
                    'completed_by' => (string) ($task['completed_by'] ?? ''),
                ]);
            }
        }

        $this->refreshProgress($processId);
    }

    public function updateStep(
        int $processId,
        string $stepKey,
        string $action,
        array $input,
        array $operator
    ): array {
        $process = $this->processRepository->findById($processId);
        if (!is_array($process) || in_array((string) ($process['status'] ?? ''), ['completed', 'cancelled'], true)) {
            throw new \RuntimeException('Este processo não está disponível para atualização.');
        }

        $step = $this->processRepository->findStep($processId, $stepKey);
        if (!is_array($step)) {
            throw new \RuntimeException('Etapa não localizada neste processo.');
        }
        if (in_array($stepKey, self::FINAL_STEPS, true)) {
            throw new \RuntimeException('Use a conclusão geral para validar todas as etapas obrigatórias.');
        }

        $operatorLogin = trim((string) ($operator['login'] ?? ''));
        $observation = trim((string) ($input['observation'] ?? ''));
        $pendingReason = trim((string) ($input['pending_reason'] ?? ''));
        $nextAction = trim((string) ($input['next_action'] ?? ''));
        $now = date('Y-m-d H:i:s');
        $evidence = is_array($input['evidence'] ?? null) ? $input['evidence'] : [];

        if ($action === 'complete') {
            if ($observation === '' && $evidence === []) {
                throw new \RuntimeException('Registre uma observação ou evidência para concluir manualmente.');
            }
            if ($stepKey === 'confirm_acceptance') {
                $acceptance = (int) ($process['acceptance_id'] ?? 0) > 0
                    ? $this->acceptanceRepository->findById((int) $process['acceptance_id'])
                    : null;
                if (!is_array($acceptance)
                    || (string) ($acceptance['status'] ?? '') !== 'aceito'
                    || trim((string) ($acceptance['revoked_at'] ?? '')) !== ''
                ) {
                    throw new \RuntimeException('O aceite só pode ser concluído com evidência válida do documento aceito.');
                }
            }
            if ($stepKey === 'change_plan' && !empty($evidence['dry_run'])) {
                throw new \RuntimeException('O dry-run não aplica o plano. A etapa deve permanecer aguardando execução real.');
            }

            $this->processRepository->updateStep((int) $step['id'], [
                'status' => 'completed',
                'started_at' => (string) ($step['started_at'] ?? '') ?: $now,
                'completed_at' => $now,
                'completed_by_user_id' => isset($operator['id']) ? (int) $operator['id'] : null,
                'completed_by_login' => $operatorLogin,
                'completion_origin' => 'manual',
                'observation' => $observation,
                'pending_reason' => null,
                'evidence' => array_merge($evidence, [
                    'confirmed_by' => $operatorLogin,
                    'confirmed_at' => $now,
                    'origin' => 'manual',
                ]),
                'next_action' => null,
                'external_reference' => trim((string) ($input['external_reference'] ?? '')),
                'last_checked_at' => $now,
                'deferred_at' => null,
                'deferred_by_login' => null,
            ]);
        } elseif (in_array($action, ['defer', 'save_exit'], true)) {
            if ($pendingReason === '') {
                throw new \RuntimeException('Informe o motivo da pendência antes de pular por enquanto.');
            }
            $this->processRepository->updateStep((int) $step['id'], [
                'status' => 'waiting',
                'started_at' => (string) ($step['started_at'] ?? '') ?: $now,
                'responsible_login' => trim((string) ($input['responsible_login'] ?? '')),
                'observation' => $observation,
                'pending_reason' => $pendingReason,
                'evidence' => array_merge($evidence, [
                    'deferred_by' => $operatorLogin,
                    'deferred_at' => $now,
                    'origin' => 'manual',
                ]),
                'next_action' => $nextAction !== '' ? $nextAction : 'Retomar esta etapa.',
                'deferred_at' => $now,
                'deferred_by_login' => $operatorLogin,
                'last_checked_at' => $now,
            ]);
        } elseif ($action === 'attention') {
            if ($pendingReason === '') {
                throw new \RuntimeException('Descreva a inconsistência que requer atenção.');
            }
            $this->processRepository->updateStep((int) $step['id'], [
                'status' => 'attention',
                'started_at' => (string) ($step['started_at'] ?? '') ?: $now,
                'observation' => $observation,
                'pending_reason' => $pendingReason,
                'next_action' => $nextAction !== '' ? $nextAction : 'Corrigir a inconsistência e tentar novamente.',
                'last_checked_at' => $now,
            ]);
        } else {
            $this->processRepository->updateStep((int) $step['id'], [
                'status' => 'in_progress',
                'started_at' => (string) ($step['started_at'] ?? '') ?: $now,
                'observation' => $observation,
                'pending_reason' => $pendingReason !== '' ? $pendingReason : null,
                'next_action' => $nextAction !== '' ? $nextAction : null,
                'evidence' => $evidence,
                'last_checked_at' => $now,
            ]);
        }

        $this->processRepository->updateProcess($processId, ['current_step_key' => $stepKey]);
        $this->refreshProgress($processId);
        $this->recordAudit('operational_process.step.' . $action, 'operational_process_step', (int) $step['id'], [
            'process_id' => $processId,
            'process_type' => (string) ($process['process_type'] ?? ''),
            'step_key' => $stepKey,
            'status' => $action,
            'origin' => 'manual',
            'observation' => $observation,
            'pending_reason' => $pendingReason,
        ], $operator);

        return $this->detail($processId) ?? [];
    }

    public function completeProcess(
        int $processId,
        array $operator,
        bool $override = false,
        string $justification = ''
    ): array {
        $process = $this->processRepository->findById($processId);
        if (!is_array($process)) {
            throw new \RuntimeException('Processo não localizado.');
        }
        if ((string) ($process['status'] ?? '') === 'cancelled') {
            throw new \RuntimeException('Processo cancelado não pode ser concluído.');
        }

        $steps = $this->processRepository->steps($processId);
        $pending = array_values(array_filter(
            $steps,
            static fn (array $step): bool => !in_array((string) ($step['step_key'] ?? ''), self::FINAL_STEPS, true)
                && !empty($step['is_required'])
                && !in_array((string) ($step['status'] ?? ''), ['completed', 'not_applicable'], true)
        ));

        if ($pending !== [] && !$override) {
            $labels = array_map(static fn (array $step): string => (string) ($step['label'] ?? $step['step_key']), $pending);
            throw new \RuntimeException('Conclusão bloqueada. Pendências obrigatórias: ' . implode(', ', $labels) . '.');
        }
        if ($pending !== [] && trim($justification) === '') {
            throw new \RuntimeException('A exceção autorizada exige justificativa.');
        }

        $now = date('Y-m-d H:i:s');
        foreach ($steps as $step) {
            if (!in_array((string) ($step['step_key'] ?? ''), self::FINAL_STEPS, true)) {
                continue;
            }
            $this->processRepository->updateStep((int) $step['id'], [
                'status' => 'completed',
                'started_at' => (string) ($step['started_at'] ?? '') ?: $now,
                'completed_at' => $now,
                'completed_by_user_id' => isset($operator['id']) ? (int) $operator['id'] : null,
                'completed_by_login' => (string) ($operator['login'] ?? ''),
                'completion_origin' => $pending === [] ? 'manual' : 'authorized_exception',
                'observation' => $pending === [] ? 'Processo concluído após validação das etapas obrigatórias.' : $justification,
                'evidence' => [
                    'pending_steps_at_completion' => array_map(
                        static fn (array $item): array => [
                            'key' => (string) ($item['step_key'] ?? ''),
                            'label' => (string) ($item['label'] ?? ''),
                            'status' => (string) ($item['status'] ?? ''),
                        ],
                        $pending
                    ),
                    'justification' => $justification,
                    'operator' => (string) ($operator['login'] ?? ''),
                    'completed_at' => $now,
                ],
            ]);
        }

        $this->processRepository->updateProcess($processId, [
            'status' => 'completed',
            'completed_at' => $now,
            'current_step_key' => null,
            'next_pending_key' => null,
            'next_pending_label' => null,
        ]);
        $this->refreshProgress($processId);
        $this->recordAudit('operational_process.completed', 'operational_process', $processId, [
            'override' => $pending !== [],
            'justification' => $justification,
            'pending_steps' => array_column($pending, 'step_key'),
        ], $operator);

        return $this->detail($processId) ?? [];
    }

    public function cancelProcess(int $processId, array $operator, string $reason): array
    {
        if (trim($reason) === '') {
            throw new \RuntimeException('Informe o motivo do cancelamento.');
        }
        $process = $this->processRepository->findById($processId);
        if (!is_array($process) || (string) ($process['status'] ?? '') === 'completed') {
            throw new \RuntimeException('Este processo não pode ser cancelado.');
        }

        $this->processRepository->updateProcess($processId, [
            'status' => 'cancelled',
            'cancelled_at' => date('Y-m-d H:i:s'),
            'notes' => $reason,
            'next_pending_key' => null,
            'next_pending_label' => null,
        ]);
        $this->recordAudit('operational_process.cancelled', 'operational_process', $processId, [
            'reason' => $reason,
        ], $operator);

        return $this->detail($processId) ?? [];
    }

    public function cancelForContract(string $processType, int $contractId, array $operator, string $reason): void
    {
        if (!$this->isAvailable() || $contractId <= 0) {
            return;
        }
        $process = $this->processRepository->findByContractAndType($contractId, $processType);
        if (!is_array($process) || in_array((string) ($process['status'] ?? ''), ['completed', 'cancelled'], true)) {
            return;
        }

        $this->cancelProcess((int) $process['id'], $operator, $reason);
    }

    public function detail(int $processId): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $process = $this->processRepository->findById($processId);
        if (!is_array($process)) {
            return null;
        }

        $steps = $this->processRepository->steps($processId);
        $process['steps'] = array_map(fn (array $step): array => $this->decorateStep($step), $steps);
        if ((string) ($process['process_type'] ?? '') === self::TYPE_MIGRATION) {
            foreach ($process['steps'] as &$step) {
                $step['url'] = '/processos/migracao?id=' . $processId
                    . '&screen=' . $this->migrationScreenForStep((string) ($step['step_key'] ?? ''));
            }
            unset($step);
        }
        $process['type_label'] = $this->typeLabel((string) ($process['process_type'] ?? ''));
        $process['status_label'] = $this->statusLabel((string) ($process['status'] ?? ''));
        $process['progress_percent'] = (int) ($process['progress_total'] ?? 0) > 0
            ? (int) floor(((int) ($process['progress_completed'] ?? 0) * 100) / (int) $process['progress_total'])
            : 0;
        $nextKey = (string) ($process['next_pending_key'] ?? $process['current_step_key'] ?? '');
        $process['resume_url'] = (string) ($process['process_type'] ?? '') === self::TYPE_MIGRATION
            ? '/processos/migracao?id=' . $processId . '&screen=' . $this->migrationScreenForStep($nextKey)
            : '/processos/etapa?id=' . $processId . '&step=' . rawurlencode($nextKey);
        $process['detail_url'] = '/processos/detalhe?id=' . $processId;

        return $process;
    }

    public function listByLogin(string $login): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $processes = $this->processRepository->listByLogin(strtolower(trim($login)));
        $result = [];
        foreach ($processes as $process) {
            if (!in_array((string) ($process['status'] ?? ''), ['completed', 'cancelled'], true)) {
                $this->synchronizeExternalState((int) $process['id']);
            }
            $detail = $this->detail((int) $process['id']);
            if (is_array($detail)) {
                $result[] = $detail;
            }
        }

        return $result;
    }

    public function list(array $filters = []): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (array $process): ?array => $this->detail((int) $process['id']),
            $this->processRepository->list($filters)
        )));
    }

    public function nextStep(int $processId): ?array
    {
        $steps = $this->processRepository->steps($processId);
        foreach ($steps as $step) {
            if (!in_array((string) ($step['status'] ?? ''), ['completed', 'not_applicable'], true)) {
                return $this->decorateStep($step);
            }
        }

        return null;
    }

    private function completePreparationSteps(
        int $processId,
        string $processType,
        array $operator,
        array $context
    ): void {
        $keys = match ($processType) {
            self::TYPE_MIGRATION => ['migration_data', 'prepare_document'],
            self::TYPE_INSTALLATION => ['registration', 'commercial_data', 'prepare_document'],
            self::TYPE_STANDALONE_SIGNATURE => ['select_client', 'identify_document', 'prepare_document', 'review_document'],
            default => [],
        };

        foreach ($keys as $key) {
            $this->completeStepAutomatically($processId, $key, [
                'source' => 'existing_flow',
                'operator' => (string) ($operator['login'] ?? ''),
                'context' => $context,
            ]);
        }
    }

    private function completeStepAutomatically(int $processId, string $stepKey, array $evidence): void
    {
        $step = $this->processRepository->findStep($processId, $stepKey);
        if (!is_array($step) || (string) ($step['status'] ?? '') === 'completed') {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->processRepository->updateStep((int) $step['id'], [
            'status' => 'completed',
            'started_at' => (string) ($step['started_at'] ?? '') ?: $now,
            'completed_at' => $now,
            'completion_origin' => 'automatic',
            'observation' => 'Concluída por sincronização do fluxo compartilhado.',
            'pending_reason' => null,
            'evidence' => array_merge($evidence, ['origin' => 'automatic', 'recorded_at' => $now]),
            'next_action' => null,
            'last_checked_at' => $now,
        ]);
    }

    private function refreshProgress(int $processId): void
    {
        $process = $this->processRepository->findById($processId);
        if (!is_array($process)) {
            return;
        }

        $steps = $this->processRepository->steps($processId);
        $required = array_values(array_filter($steps, static fn (array $step): bool => !empty($step['is_required'])));
        $completed = array_values(array_filter(
            $required,
            static fn (array $step): bool => in_array((string) ($step['status'] ?? ''), ['completed', 'not_applicable'], true)
        ));
        $next = null;
        foreach ($required as $step) {
            if (!in_array((string) ($step['status'] ?? ''), ['completed', 'not_applicable'], true)) {
                $next = $step;
                break;
            }
        }

        $status = (string) ($process['status'] ?? 'in_progress');
        if (!in_array($status, ['completed', 'cancelled'], true)) {
            $attention = array_values(array_filter(
                $steps,
                static fn (array $step): bool => (string) ($step['status'] ?? '') === 'attention'
            ));
            if ($attention !== []) {
                $status = 'attention';
                $next = $attention[0];
            } elseif (is_array($next) && (string) ($next['status'] ?? '') === 'waiting') {
                $status = match ((string) ($next['responsible_role'] ?? 'technician')) {
                    'client' => 'waiting_client',
                    'mkauth' => 'waiting_mkauth',
                    'financial' => 'waiting_financial',
                    default => 'waiting_technician',
                };
            } else {
                $status = 'in_progress';
            }
        }

        $this->processRepository->updateProcess($processId, [
            'status' => $status,
            'progress_completed' => count($completed),
            'progress_total' => count($required),
            'next_pending_key' => is_array($next) ? (string) ($next['step_key'] ?? '') : null,
            'next_pending_label' => is_array($next) ? (string) ($next['label'] ?? '') : null,
            'current_step_key' => (string) ($process['current_step_key'] ?? '') !== ''
                ? (string) $process['current_step_key']
                : (is_array($next) ? (string) ($next['step_key'] ?? '') : null),
        ]);
    }

    private function stepDefinitions(string $processType): array
    {
        return match ($processType) {
            self::TYPE_MIGRATION => [
                $this->step('migration_data', 'Dados da migração', 1, 'technician'),
                $this->step('prepare_document', 'Preparar documento', 2, 'technician'),
                $this->step('send_acceptance', 'Enviar ou abrir aceite', 3, 'client'),
                $this->step('confirm_acceptance', 'Confirmar aceite', 4, 'client'),
                $this->step('technical_execution', 'Executar instalação ou troca', 5, 'technician'),
                $this->step('confirm_equipment', 'Confirmar equipamento', 6, 'technician'),
                $this->step('change_plan', 'Alterar plano no MkAuth', 7, 'mkauth'),
                $this->step('validate_connection', 'Validar conexão', 8, 'technician'),
                $this->step('open_financial_ticket', 'Abrir chamado financeiro', 9, 'financial'),
                $this->step('follow_financial_ticket', 'Acompanhar chamado financeiro', 10, 'financial'),
                $this->step('complete_migration', 'Concluir migração', 11, 'technician'),
            ],
            self::TYPE_INSTALLATION => [
                $this->step('registration', 'Cadastro', 1, 'technician'),
                $this->step('commercial_data', 'Dados comerciais', 2, 'technician'),
                $this->step('prepare_document', 'Preparar contrato', 3, 'technician'),
                $this->step('send_acceptance', 'Enviar ou abrir aceite', 4, 'client'),
                $this->step('confirm_acceptance', 'Confirmar aceite', 5, 'client'),
                $this->step('technical_execution', 'Execução técnica', 6, 'technician'),
                $this->step('confirm_equipment', 'Confirmar equipamento', 7, 'technician'),
                $this->step('activate_service', 'Ativar serviço', 8, 'mkauth'),
                $this->step('open_financial_ticket', 'Abrir chamado ou validar no MkAuth', 9, 'financial'),
                $this->step('follow_financial_ticket', 'Acompanhar chamado', 10, 'financial'),
                $this->step('complete_installation', 'Concluir instalação', 11, 'technician'),
            ],
            self::TYPE_STANDALONE_SIGNATURE => [
                $this->step('select_client', 'Selecionar cliente', 1, 'technician'),
                $this->step('identify_document', 'Identificar documento sem assinatura', 2, 'technician'),
                $this->step('prepare_document', 'Preparar solicitação', 3, 'technician'),
                $this->step('review_document', 'Revisar dados', 4, 'technician'),
                $this->step('send_acceptance', 'Enviar ou abrir aceite', 5, 'client'),
                $this->step('confirm_acceptance', 'Acompanhar e confirmar aceite', 6, 'client'),
                $this->step('validate_document', 'Validar documento assinado', 7, 'technician'),
                $this->step('complete_signature_request', 'Concluir solicitação', 8, 'technician'),
            ],
            default => [],
        };
    }

    private function step(string $key, string $label, int $order, string $responsible, bool $required = true): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'order' => $order,
            'responsible' => $responsible,
            'required' => $required,
        ];
    }

    private function decorateStep(array $step): array
    {
        $status = (string) ($step['status'] ?? 'not_started');
        $step['status_label'] = match ($status) {
            'in_progress' => 'Em andamento',
            'waiting' => 'Aguardando',
            'attention' => 'Requer atenção',
            'completed' => 'Concluída',
            'not_applicable' => 'Não aplicável',
            default => 'Não iniciada',
        };
        $step['status_class'] = match ($status) {
            'completed' => 'success',
            'waiting' => 'warning',
            'attention' => 'danger',
            'in_progress' => 'info',
            default => 'muted',
        };
        $step['url'] = '/processos/etapa?id=' . (int) ($step['process_id'] ?? 0)
            . '&step=' . rawurlencode((string) ($step['step_key'] ?? ''));

        return $step;
    }

    private function migrationScreenForStep(string $stepKey): int
    {
        return match ($stepKey) {
            'migration_data' => 1,
            'prepare_document', 'send_acceptance', 'confirm_acceptance' => 2,
            'technical_execution', 'confirm_equipment', 'validate_connection' => 3,
            default => 4,
        };
    }

    private function buildMetadata(string $processType, array $contract, array $context): array
    {
        $metadata = [
            'process_type' => $processType,
            'client' => [
                'name' => (string) ($contract['nome_cliente'] ?? $context['client_name'] ?? ''),
                'login' => (string) ($contract['mkauth_login'] ?? $context['login'] ?? ''),
                'phone' => (string) ($contract['telefone_cliente'] ?? $context['phone'] ?? ''),
                'email' => (string) ($context['email'] ?? ''),
            ],
            'contract_id' => (int) ($contract['id'] ?? $contract['contract_id'] ?? 0),
        ];

        $snapshot = $context['upgrade_snapshot'] ?? null;
        if (!is_array($snapshot)) {
            $raw = (string) ($contract['upgrade_snapshot_json'] ?? '');
            $decoded = $raw !== '' ? json_decode($raw, true) : null;
            $snapshot = is_array($decoded) ? $decoded : [];
        }
        if ($snapshot !== []) {
            $metadata['migration'] = $snapshot;
        }

        return $metadata;
    }

    private function contractSnapshot(array $contract): array
    {
        $allowed = [
            'id',
            'mkauth_login',
            'nome_cliente',
            'telefone_cliente',
            'tipo_adesao',
            'valor_adesao',
            'parcelas_adesao',
            'valor_parcela_adesao',
            'vencimento_primeira_parcela',
            'fidelidade_meses',
            'beneficio_valor',
            'multa_total',
            'tipo_aceite',
            'observacao_adesao',
            'upgrade_snapshot_json',
            'status_financeiro',
            'lifecycle_status',
            'revision_number',
            'created_at',
        ];
        $snapshot = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $contract)) {
                $snapshot[$key] = $contract[$key];
            }
        }

        return $snapshot;
    }

    private function documentType(string $processType): string
    {
        return match ($processType) {
            self::TYPE_MIGRATION => 'migration_addendum',
            self::TYPE_INSTALLATION => 'installation_contract',
            default => 'standalone_contract',
        };
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_MIGRATION => 'Migração',
            self::TYPE_INSTALLATION => 'Nova instalação',
            self::TYPE_STANDALONE_SIGNATURE => 'Solicitação avulsa de assinatura',
            default => 'Processo operacional',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'Rascunho',
            'in_progress' => 'Em andamento',
            'waiting_client' => 'Aguardando cliente',
            'waiting_technician' => 'Aguardando técnico',
            'waiting_mkauth' => 'Aguardando MkAuth',
            'waiting_financial' => 'Aguardando financeiro',
            'attention' => 'Requer atenção',
            'completed' => 'Concluído',
            'cancelled' => 'Cancelado',
            default => 'Em andamento',
        };
    }

    private function assertProcessType(string $processType): void
    {
        if (!in_array($processType, [
            self::TYPE_INSTALLATION,
            self::TYPE_MIGRATION,
            self::TYPE_STANDALONE_SIGNATURE,
        ], true)) {
            throw new \InvalidArgumentException('Tipo de processo operacional inválido.');
        }
    }

    private function recordAudit(
        string $action,
        string $entityType,
        ?int $entityId,
        array $context,
        array $operator
    ): void {
        try {
            $this->localRepository->log(
                isset($operator['id']) ? (int) $operator['id'] : null,
                (string) ($operator['login'] ?? ''),
                $action,
                $entityType,
                $entityId,
                $context
            );
        } catch (\Throwable) {
            // A atualização operacional não deve ser perdida por falha isolada de auditoria.
        }
    }
}
