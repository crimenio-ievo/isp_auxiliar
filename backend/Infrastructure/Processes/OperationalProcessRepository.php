<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;

/**
 * Persistência comum dos processos operacionais e seus checklists.
 *
 * Os registros antigos continuam válidos: esta camada somente atua quando a
 * migration de processos está disponível e nunca altera dados do MkAuth.
 */
final class OperationalProcessRepository
{
    public function __construct(
        private Database $database,
        private LocalRepository $localRepository
    ) {
    }

    public function isAvailable(): bool
    {
        try {
            $row = $this->database->fetchOne(
                'SELECT COUNT(*) AS total
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name IN (
                       "operational_processes",
                       "operational_process_steps",
                       "operational_process_documents"
                   )'
            );

            return (int) ($row['total'] ?? 0) === 3;
        } catch (\Throwable) {
            return false;
        }
    }

    public function create(array $data): int
    {
        $providerId = (int) ($data['provider_id'] ?? $this->localRepository->currentProviderId() ?? 0);
        if ($providerId <= 0) {
            throw new \RuntimeException('Provedor local não identificado para criar o processo.');
        }

        $now = date('Y-m-d H:i:s');
        $this->database->execute(
            'INSERT INTO operational_processes (
                provider_id, process_type, mkauth_login, client_name, status,
                current_step_key, responsible_user_id, responsible_login,
                responsible_name, created_by_user_id, created_by_login,
                registration_id, contract_id, acceptance_id,
                equipment_reference, external_ticket_id, financial_task_id,
                progress_completed, progress_total, next_pending_key,
                next_pending_label, notes, metadata_json, started_at,
                completed_at, cancelled_at, created_at, updated_at
             ) VALUES (
                :provider_id, :process_type, :mkauth_login, :client_name, :status,
                :current_step_key, :responsible_user_id, :responsible_login,
                :responsible_name, :created_by_user_id, :created_by_login,
                :registration_id, :contract_id, :acceptance_id,
                :equipment_reference, :external_ticket_id, :financial_task_id,
                :progress_completed, :progress_total, :next_pending_key,
                :next_pending_label, :notes, :metadata_json, :started_at,
                :completed_at, :cancelled_at, :created_at, :updated_at
             )',
            [
                'provider_id' => $providerId,
                'process_type' => (string) ($data['process_type'] ?? ''),
                'mkauth_login' => (string) ($data['mkauth_login'] ?? ''),
                'client_name' => $this->nullableString($data['client_name'] ?? null),
                'status' => (string) ($data['status'] ?? 'draft'),
                'current_step_key' => $this->nullableString($data['current_step_key'] ?? null),
                'responsible_user_id' => $this->nullableInt($data['responsible_user_id'] ?? null),
                'responsible_login' => $this->nullableString($data['responsible_login'] ?? null),
                'responsible_name' => $this->nullableString($data['responsible_name'] ?? null),
                'created_by_user_id' => $this->nullableInt($data['created_by_user_id'] ?? null),
                'created_by_login' => $this->nullableString($data['created_by_login'] ?? null),
                'registration_id' => $this->nullableInt($data['registration_id'] ?? null),
                'contract_id' => $this->nullableInt($data['contract_id'] ?? null),
                'acceptance_id' => $this->nullableInt($data['acceptance_id'] ?? null),
                'equipment_reference' => $this->nullableString($data['equipment_reference'] ?? null),
                'external_ticket_id' => $this->nullableString($data['external_ticket_id'] ?? null),
                'financial_task_id' => $this->nullableInt($data['financial_task_id'] ?? null),
                'progress_completed' => max(0, (int) ($data['progress_completed'] ?? 0)),
                'progress_total' => max(0, (int) ($data['progress_total'] ?? 0)),
                'next_pending_key' => $this->nullableString($data['next_pending_key'] ?? null),
                'next_pending_label' => $this->nullableString($data['next_pending_label'] ?? null),
                'notes' => $this->nullableString($data['notes'] ?? null),
                'metadata_json' => $this->encodeJson($data['metadata'] ?? $data['metadata_json'] ?? null),
                'started_at' => $this->nullableString($data['started_at'] ?? $now),
                'completed_at' => $this->nullableString($data['completed_at'] ?? null),
                'cancelled_at' => $this->nullableString($data['cancelled_at'] ?? null),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        return $this->database->lastInsertId();
    }

    public function createSteps(int $processId, array $steps): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($steps as $index => $step) {
            if (!is_array($step) || trim((string) ($step['key'] ?? '')) === '') {
                continue;
            }

            $this->database->execute(
                'INSERT INTO operational_process_steps (
                    process_id, step_key, label, step_order, status, is_required,
                    responsible_role, responsible_login, started_at, completed_at,
                    completed_by_user_id, completed_by_login, completion_origin,
                    observation, pending_reason, evidence_json, next_action,
                    external_reference, last_checked_at, deferred_at,
                    deferred_by_login, created_at, updated_at
                 ) VALUES (
                    :process_id, :step_key, :label, :step_order, :status, :is_required,
                    :responsible_role, :responsible_login, :started_at, :completed_at,
                    :completed_by_user_id, :completed_by_login, :completion_origin,
                    :observation, :pending_reason, :evidence_json, :next_action,
                    :external_reference, :last_checked_at, :deferred_at,
                    :deferred_by_login, :created_at, :updated_at
                 )
                 ON DUPLICATE KEY UPDATE
                    label = VALUES(label),
                    step_order = VALUES(step_order),
                    is_required = VALUES(is_required),
                    responsible_role = VALUES(responsible_role),
                    updated_at = VALUES(updated_at)',
                [
                    'process_id' => $processId,
                    'step_key' => (string) $step['key'],
                    'label' => (string) ($step['label'] ?? $step['key']),
                    'step_order' => max(1, (int) ($step['order'] ?? ($index + 1))),
                    'status' => (string) ($step['status'] ?? 'not_started'),
                    'is_required' => !array_key_exists('required', $step) || (bool) $step['required'] ? 1 : 0,
                    'responsible_role' => $this->nullableString($step['responsible'] ?? null),
                    'responsible_login' => $this->nullableString($step['responsible_login'] ?? null),
                    'started_at' => $this->nullableString($step['started_at'] ?? null),
                    'completed_at' => $this->nullableString($step['completed_at'] ?? null),
                    'completed_by_user_id' => $this->nullableInt($step['completed_by_user_id'] ?? null),
                    'completed_by_login' => $this->nullableString($step['completed_by_login'] ?? null),
                    'completion_origin' => $this->nullableString($step['completion_origin'] ?? null),
                    'observation' => $this->nullableString($step['observation'] ?? null),
                    'pending_reason' => $this->nullableString($step['pending_reason'] ?? null),
                    'evidence_json' => $this->encodeJson($step['evidence'] ?? null),
                    'next_action' => $this->nullableString($step['next_action'] ?? null),
                    'external_reference' => $this->nullableString($step['external_reference'] ?? null),
                    'last_checked_at' => $this->nullableString($step['last_checked_at'] ?? null),
                    'deferred_at' => $this->nullableString($step['deferred_at'] ?? null),
                    'deferred_by_login' => $this->nullableString($step['deferred_by_login'] ?? null),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    public function findById(int $processId): ?array
    {
        $row = $this->database->fetchOne(
            'SELECT *
             FROM operational_processes
             WHERE id = :id
               AND provider_id = :provider_id
             LIMIT 1',
            [
                'id' => $processId,
                'provider_id' => (int) ($this->localRepository->currentProviderId() ?? 0),
            ]
        );

        return is_array($row) ? $this->hydrateProcess($row) : null;
    }

    public function findByContractAndType(int $contractId, string $processType): ?array
    {
        $row = $this->database->fetchOne(
            'SELECT *
             FROM operational_processes
             WHERE provider_id = :provider_id
               AND contract_id = :contract_id
               AND process_type = :process_type
             ORDER BY id DESC
             LIMIT 1',
            [
                'provider_id' => (int) ($this->localRepository->currentProviderId() ?? 0),
                'contract_id' => $contractId,
                'process_type' => $processType,
            ]
        );

        return is_array($row) ? $this->hydrateProcess($row) : null;
    }

    public function findByAcceptanceId(int $acceptanceId): ?array
    {
        $row = $this->database->fetchOne(
            'SELECT *
             FROM operational_processes
             WHERE provider_id = :provider_id
               AND acceptance_id = :acceptance_id
             ORDER BY id DESC
             LIMIT 1',
            [
                'provider_id' => (int) ($this->localRepository->currentProviderId() ?? 0),
                'acceptance_id' => $acceptanceId,
            ]
        );

        return is_array($row) ? $this->hydrateProcess($row) : null;
    }

    public function listByLogin(string $login, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->database->fetchAll(
            'SELECT *
             FROM operational_processes
             WHERE provider_id = :provider_id
               AND mkauth_login = :mkauth_login
             ORDER BY
                CASE WHEN status IN ("completed", "cancelled") THEN 1 ELSE 0 END ASC,
                updated_at DESC,
                id DESC
             LIMIT ' . $limit,
            [
                'provider_id' => (int) ($this->localRepository->currentProviderId() ?? 0),
                'mkauth_login' => $login,
            ]
        );

        return array_map(fn (array $row): array => $this->hydrateProcess($row), $rows);
    }

    public function list(array $filters = [], int $limit = 100): array
    {
        $limit = max(1, min(250, $limit));
        $where = ['provider_id = :provider_id'];
        $params = ['provider_id' => (int) ($this->localRepository->currentProviderId() ?? 0)];

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }

        $type = trim((string) ($filters['type'] ?? ''));
        if ($type !== '') {
            $where[] = 'process_type = :process_type';
            $params['process_type'] = $type;
        }

        $login = trim((string) ($filters['login'] ?? ''));
        if ($login !== '') {
            $where[] = 'mkauth_login = :mkauth_login';
            $params['mkauth_login'] = $login;
        }

        $rows = $this->database->fetchAll(
            'SELECT *
             FROM operational_processes
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY updated_at DESC, id DESC
             LIMIT ' . $limit,
            $params
        );

        return array_map(fn (array $row): array => $this->hydrateProcess($row), $rows);
    }

    public function steps(int $processId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT *
             FROM operational_process_steps
             WHERE process_id = :process_id
             ORDER BY step_order ASC, id ASC',
            ['process_id' => $processId]
        );

        return array_map(fn (array $row): array => $this->hydrateStep($row), $rows);
    }

    public function findStep(int $processId, string $stepKey, bool $forUpdate = false): ?array
    {
        $row = $this->database->fetchOne(
            'SELECT *
             FROM operational_process_steps
             WHERE process_id = :process_id
               AND step_key = :step_key
             LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''),
            ['process_id' => $processId, 'step_key' => $stepKey]
        );

        return is_array($row) ? $this->hydrateStep($row) : null;
    }

    public function updateStep(int $stepId, array $data): void
    {
        $allowed = [
            'status',
            'responsible_login',
            'started_at',
            'completed_at',
            'completed_by_user_id',
            'completed_by_login',
            'completion_origin',
            'observation',
            'pending_reason',
            'next_action',
            'external_reference',
            'last_checked_at',
            'deferred_at',
            'deferred_by_login',
        ];
        $sets = [];
        $params = ['id' => $stepId, 'updated_at' => date('Y-m-d H:i:s')];

        foreach ($allowed as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }
            $sets[] = $column . ' = :' . $column;
            $params[$column] = $data[$column] === '' ? null : $data[$column];
        }

        if (array_key_exists('evidence', $data) || array_key_exists('evidence_json', $data)) {
            $sets[] = 'evidence_json = :evidence_json';
            $evidence = array_key_exists('evidence', $data) ? $data['evidence'] : $data['evidence_json'];
            $params['evidence_json'] = $this->encodeJson($evidence);
        }

        if ($sets === []) {
            return;
        }

        $sets[] = 'updated_at = :updated_at';
        $this->database->execute(
            'UPDATE operational_process_steps SET ' . implode(', ', $sets) . ' WHERE id = :id',
            $params
        );
    }

    public function updateProcess(int $processId, array $data): void
    {
        $allowed = [
            'status',
            'current_step_key',
            'responsible_user_id',
            'responsible_login',
            'responsible_name',
            'registration_id',
            'contract_id',
            'acceptance_id',
            'equipment_reference',
            'external_ticket_id',
            'financial_task_id',
            'progress_completed',
            'progress_total',
            'next_pending_key',
            'next_pending_label',
            'notes',
            'started_at',
            'completed_at',
            'cancelled_at',
        ];
        $sets = [];
        $params = ['id' => $processId, 'updated_at' => date('Y-m-d H:i:s')];

        foreach ($allowed as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }
            $sets[] = $column . ' = :' . $column;
            $params[$column] = $data[$column] === '' ? null : $data[$column];
        }

        if (array_key_exists('metadata', $data) || array_key_exists('metadata_json', $data)) {
            $sets[] = 'metadata_json = :metadata_json';
            $params['metadata_json'] = $this->encodeJson($data['metadata'] ?? $data['metadata_json']);
        }

        if ($sets === []) {
            return;
        }

        $sets[] = 'updated_at = :updated_at';
        $this->database->execute(
            'UPDATE operational_processes SET ' . implode(', ', $sets) . ' WHERE id = :id',
            $params
        );
    }

    public function upsertDocument(
        int $processId,
        int $contractId,
        string $documentType,
        string $documentVersion,
        array $snapshot,
        int $acceptanceId
    ): int {
        $encodedSnapshot = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encodedSnapshot)) {
            throw new \RuntimeException('Não foi possível criar o snapshot imutável do documento.');
        }

        $now = date('Y-m-d H:i:s');
        $this->database->execute(
            'INSERT INTO operational_process_documents (
                process_id, contract_id, document_type, document_version,
                status, content_snapshot, snapshot_hash, active_acceptance_id,
                created_at, updated_at
             ) VALUES (
                :process_id, :contract_id, :document_type, :document_version,
                :status, :content_snapshot, :snapshot_hash, :active_acceptance_id,
                :created_at, :updated_at
             )
             ON DUPLICATE KEY UPDATE
                active_acceptance_id = VALUES(active_acceptance_id),
                status = VALUES(status),
                updated_at = VALUES(updated_at)',
            [
                'process_id' => $processId,
                'contract_id' => $contractId,
                'document_type' => $documentType,
                'document_version' => $documentVersion,
                'status' => 'prepared',
                'content_snapshot' => $encodedSnapshot,
                'snapshot_hash' => hash('sha256', $encodedSnapshot),
                'active_acceptance_id' => $acceptanceId > 0 ? $acceptanceId : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $row = $this->database->fetchOne(
            'SELECT id
             FROM operational_process_documents
             WHERE process_id = :process_id
               AND document_type = :document_type
               AND document_version = :document_version
             LIMIT 1',
            [
                'process_id' => $processId,
                'document_type' => $documentType,
                'document_version' => $documentVersion,
            ]
        );

        return (int) ($row['id'] ?? 0);
    }

    public function findDocument(int $processId, string $documentType, string $documentVersion): ?array
    {
        return $this->database->fetchOne(
            'SELECT *
             FROM operational_process_documents
             WHERE process_id = :process_id
               AND document_type = :document_type
               AND document_version = :document_version
             LIMIT 1',
            [
                'process_id' => $processId,
                'document_type' => $documentType,
                'document_version' => $documentVersion,
            ]
        );
    }

    public function linkAcceptance(int $processId, int $documentId, int $acceptanceId): void
    {
        $this->database->execute(
            'UPDATE contract_acceptances
             SET operational_process_id = :process_id,
                 process_document_id = :document_id,
                 updated_at = :updated_at
             WHERE id = :acceptance_id',
            [
                'process_id' => $processId,
                'document_id' => $documentId,
                'updated_at' => date('Y-m-d H:i:s'),
                'acceptance_id' => $acceptanceId,
            ]
        );
        $this->updateProcess($processId, ['acceptance_id' => $acceptanceId]);
    }

    public function markDocumentStatusByAcceptance(int $acceptanceId, string $status): void
    {
        $this->database->execute(
            'UPDATE operational_process_documents
             SET status = :status,
                 updated_at = :updated_at
             WHERE active_acceptance_id = :acceptance_id',
            [
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s'),
                'acceptance_id' => $acceptanceId,
            ]
        );
    }

    private function hydrateProcess(array $row): array
    {
        $row['metadata'] = $this->decodeJson($row['metadata_json'] ?? null);
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['progress_completed'] = (int) ($row['progress_completed'] ?? 0);
        $row['progress_total'] = (int) ($row['progress_total'] ?? 0);

        return $row;
    }

    private function hydrateStep(array $row): array
    {
        $row['evidence'] = $this->decodeJson($row['evidence_json'] ?? null);
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['process_id'] = (int) ($row['process_id'] ?? 0);
        $row['step_order'] = (int) ($row['step_order'] ?? 0);
        $row['is_required'] = (bool) ($row['is_required'] ?? false);

        return $row;
    }

    private function encodeJson(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $value;
            }
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : null;
    }

    private function decodeJson(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = (int) ($value ?? 0);

        return $value > 0 ? $value : null;
    }
}
