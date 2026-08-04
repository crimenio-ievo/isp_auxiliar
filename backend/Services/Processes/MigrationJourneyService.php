<?php

declare(strict_types=1);

namespace App\Services\Processes;

/**
 * Projeta as 11 etapas auditáveis do motor nas quatro etapas operacionais.
 * Não grava estado e não cria um segundo motor de processos.
 */
final class MigrationJourneyService
{
    private const STAGES = [
        1 => ['key' => 'condition', 'label' => 'Nova condição', 'steps' => ['migration_data']],
        2 => ['key' => 'acceptance', 'label' => 'Aceite', 'steps' => ['prepare_document', 'send_acceptance', 'confirm_acceptance']],
        3 => ['key' => 'technical', 'label' => 'Execução técnica', 'steps' => ['technical_execution', 'confirm_equipment']],
        4 => ['key' => 'finalization', 'label' => 'Finalização', 'steps' => ['change_plan', 'validate_connection', 'open_financial_ticket', 'follow_financial_ticket', 'complete_migration']],
    ];

    public function project(array $process, ?string $requestedStep = null): array
    {
        $steps = array_values((array) ($process['steps'] ?? []));
        $byKey = [];
        foreach ($steps as $step) {
            if (is_array($step)) {
                $byKey[(string) ($step['step_key'] ?? '')] = $step;
            }
        }

        $activeStepKey = trim((string) ($requestedStep
            ?? $process['next_pending_key']
            ?? $process['current_step_key']
            ?? 'migration_data'));
        $activeStage = $this->stageForStep($activeStepKey);
        $visible = [];
        foreach (self::STAGES as $number => $definition) {
            $stageSteps = array_values(array_filter(array_map(
                static fn (string $key): ?array => $byKey[$key] ?? null,
                $definition['steps']
            )));
            $status = $this->deriveStatus($stageSteps, (string) ($process['status'] ?? ''));
            $firstPending = null;
            foreach ($stageSteps as $stageStep) {
                if (!in_array((string) ($stageStep['status'] ?? ''), ['completed', 'not_applicable'], true)) {
                    $firstPending = $stageStep;
                    break;
                }
            }
            $target = $firstPending ?? ($stageSteps[0] ?? null);
            $visible[] = [
                'number' => $number,
                'key' => $definition['key'],
                'label' => $definition['label'],
                'status' => $status,
                'status_label' => $this->statusLabel($status),
                'status_class' => $this->statusClass($status),
                'active' => $number === $activeStage,
                'steps' => $stageSteps,
                'url' => '/processos/migracao?id=' . (int) ($process['id'] ?? 0)
                    . '&step=' . rawurlencode((string) ($target['step_key'] ?? $definition['steps'][0])),
            ];
        }

        return [
            'active_stage' => $activeStage,
            'active' => $visible[$activeStage - 1] ?? $visible[0],
            'visible_steps' => $visible,
            'technical_steps' => $steps,
            'completed' => count(array_filter($visible, static fn (array $stage): bool => $stage['status'] === 'completed')),
            'total' => 4,
        ];
    }

    public function stageForStep(string $stepKey): int
    {
        foreach (self::STAGES as $number => $definition) {
            if (in_array($stepKey, $definition['steps'], true)) {
                return $number;
            }
        }

        return 1;
    }

    public function hasExternalActions(array $process): bool
    {
        foreach ((array) ($process['steps'] ?? []) as $step) {
            if (!is_array($step) || !in_array((string) ($step['step_key'] ?? ''), ['change_plan', 'open_financial_ticket'], true)) {
                continue;
            }
            $evidence = is_array($step['evidence'] ?? null) ? $step['evidence'] : [];
            if ((string) ($step['status'] ?? '') === 'completed'
                && empty($evidence['dry_run'])
                && (empty($evidence['simulated']) || !empty($step['external_reference']))
            ) {
                return true;
            }
        }

        return false;
    }

    private function deriveStatus(array $steps, string $processStatus): string
    {
        if ($processStatus === 'cancelled') {
            return 'cancelled';
        }
        if ($steps !== [] && count(array_filter($steps, static fn (array $step): bool => in_array((string) ($step['status'] ?? ''), ['completed', 'not_applicable'], true))) === count($steps)) {
            return 'completed';
        }
        if (array_filter($steps, static fn (array $step): bool => (string) ($step['status'] ?? '') === 'attention')) {
            return 'attention';
        }
        if (array_filter($steps, static fn (array $step): bool => (string) ($step['status'] ?? '') === 'waiting')) {
            return 'waiting';
        }
        if (array_filter($steps, static fn (array $step): bool => in_array((string) ($step['status'] ?? ''), ['in_progress', 'completed'], true))) {
            return 'in_progress';
        }

        return 'not_started';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'completed' => 'Concluída',
            'attention' => 'Requer atenção',
            'waiting' => 'Aguardando',
            'in_progress' => 'Em andamento',
            'cancelled' => 'Cancelada',
            default => 'Não iniciada',
        };
    }

    private function statusClass(string $status): string
    {
        return match ($status) {
            'completed' => 'success',
            'attention' => 'danger',
            'waiting' => 'warning',
            'in_progress' => 'info',
            default => 'muted',
        };
    }
}
