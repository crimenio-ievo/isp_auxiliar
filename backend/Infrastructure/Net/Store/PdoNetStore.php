<?php

declare(strict_types=1);

namespace App\Infrastructure\Net\Store;

use PDO;

/**
 * NetStore sobre o banco local (tabelas net_ da migration 030).
 */
final class PdoNetStore implements NetStore
{
    private const ONU_COLUMNS = ['provider_id', 'device_id', 'slot', 'pon_index', 'onu_index', 'snmp_index', 'onu_identifier', 'identifier_type', 'first_seen_at', 'last_seen_at', 'removed_at'];
    private const STATE_COLUMNS = ['oper_status', 'raw_status_code', 'status_since', 'stale', 'missing_cycles'];
    private const POLL_COLUMNS = ['last_attempt_at', 'last_success_at', 'last_result', 'consecutive_failures', 'identity_seen_json', 'requests_last_cycle', 'cycle_ms', 'last_uptime_ticks', 'last_error'];

    public function __construct(private PDO $pdo)
    {
    }

    public function findDevice(int $deviceId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT d.*, p.code AS profile_code, p.profile_json, p.status AS profile_status
               FROM net_devices d JOIN net_device_profiles p ON p.id = d.profile_id
              WHERE d.id = :id'
        );
        $st->execute(['id' => $deviceId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function pollState(int $deviceId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM net_device_poll_state WHERE device_id = :id');
        $st->execute(['id' => $deviceId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function savePollState(int $deviceId, array $fields): void
    {
        $fields = array_intersect_key($fields, array_flip(self::POLL_COLUMNS));
        $cols = array_keys($fields);
        $insertCols = implode(', ', array_merge(['device_id', 'updated_at'], $cols));
        $insertVals = implode(', ', array_merge([':device_id', 'NOW()'], array_map(static fn ($c) => ':' . $c, $cols)));
        $updates = implode(', ', array_merge(['updated_at = NOW()'], array_map(static fn ($c) => "{$c} = VALUES({$c})", $cols)));
        $st = $this->pdo->prepare("INSERT INTO net_device_poll_state ({$insertCols}) VALUES ({$insertVals}) ON DUPLICATE KEY UPDATE {$updates}");
        $st->execute(array_merge(['device_id' => $deviceId], $fields));
    }

    public function deviceOnus(int $deviceId): array
    {
        $st = $this->pdo->prepare(
            'SELECT o.*, s.oper_status, s.raw_status_code, s.status_since, s.stale, s.missing_cycles
               FROM net_onus o LEFT JOIN net_onu_state s ON s.onu_id = o.id
              WHERE o.device_id = :id'
        );
        $st->execute(['id' => $deviceId]);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function onusByIdentifiers(int $providerId, string $identifierType, array $identifiers): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($identifiers)), 200) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $st = $this->pdo->prepare(
                "SELECT o.*, s.oper_status, s.raw_status_code, s.status_since, s.stale, s.missing_cycles
                   FROM net_onus o LEFT JOIN net_onu_state s ON s.onu_id = o.id
                  WHERE o.provider_id = ? AND o.identifier_type = ? AND o.onu_identifier IN ({$in})"
            );
            $st->execute(array_merge([$providerId, $identifierType], $chunk));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[$row['onu_identifier']] = $row;
            }
        }

        return $out;
    }

    public function markDeviceStale(int $deviceId, string $now, array $pollState): int
    {
        return $this->transaction(function () use ($deviceId, $now, $pollState): int {
            $st = $this->pdo->prepare(
                'UPDATE net_onu_state s JOIN net_onus o ON o.id = s.onu_id
                    SET s.stale = 1, s.updated_at = :now
                  WHERE o.device_id = :id AND o.removed_at IS NULL'
            );
            $st->execute(['now' => $now, 'id' => $deviceId]);
            $this->savePollState($deviceId, $pollState);

            return $st->rowCount();
        });
    }

    public function applyCycle(int $deviceId, array $plan, string $now): void
    {
        $this->transaction(function () use ($deviceId, $plan, $now): void {
            $insertOnu = $this->pdo->prepare(
                'INSERT INTO net_onus (' . implode(', ', self::ONU_COLUMNS) . ') VALUES (' . implode(', ', array_map(static fn ($c) => ':' . $c, self::ONU_COLUMNS)) . ')'
            );
            foreach ($plan['insert'] as $item) {
                $insertOnu->execute($this->only($item['onu'], self::ONU_COLUMNS, true));
                $onuId = (int) $this->pdo->lastInsertId();
                $this->upsertState($onuId, $item['state'], $now);
                $this->insertEvents($onuId, $item['events'], $now);
            }
            foreach ($plan['update'] as $onuId => $item) {
                $fields = $this->only($item['onu'], self::ONU_COLUMNS, false);
                if ($fields !== []) {
                    $set = implode(', ', array_map(static fn ($c) => "{$c} = :{$c}", array_keys($fields)));
                    $st = $this->pdo->prepare("UPDATE net_onus SET {$set} WHERE id = :onu_id");
                    $st->execute(array_merge($fields, ['onu_id' => (int) $onuId]));
                }
                $this->upsertState((int) $onuId, $item['state'], $now);
                $this->insertEvents((int) $onuId, $item['events'], $now);
            }
            if (($plan['expected_identity'] ?? null) !== null) {
                $st = $this->pdo->prepare('UPDATE net_devices SET expected_identity_json = :j, updated_at = :now WHERE id = :id');
                $st->execute(['j' => json_encode($plan['expected_identity'], JSON_UNESCAPED_SLASHES), 'now' => $now, 'id' => $deviceId]);
            }
            $this->savePollState($deviceId, $plan['poll_state']);
        });
    }

    private function upsertState(int $onuId, array $state, string $now): void
    {
        $state = $this->only($state, self::STATE_COLUMNS, false);
        if ($state === []) {
            return;
        }
        $cols = array_keys($state);
        $sql = 'INSERT INTO net_onu_state (onu_id, updated_at, ' . implode(', ', $cols) . ') VALUES (:onu_id, :now, '
            . implode(', ', array_map(static fn ($c) => ':' . $c, $cols)) . ') ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at), '
            . implode(', ', array_map(static fn ($c) => "{$c} = VALUES({$c})", $cols));
        $this->pdo->prepare($sql)->execute(array_merge($state, ['onu_id' => $onuId, 'now' => $now]));
    }

    private function insertEvents(int $onuId, array $events, string $now): void
    {
        if ($events === []) {
            return;
        }
        $st = $this->pdo->prepare('INSERT INTO net_onu_events (onu_id, event_type, details_json, occurred_at) VALUES (:onu_id, :t, :d, :at)');
        foreach ($events as [$type, $details]) {
            $st->execute(['onu_id' => $onuId, 't' => $type, 'd' => json_encode($details, JSON_UNESCAPED_SLASHES), 'at' => $now]);
        }
    }

    private function only(array $data, array $allowed, bool $fillMissing): array
    {
        $out = [];
        foreach ($allowed as $c) {
            if (array_key_exists($c, $data)) {
                $out[$c] = $data[$c];
            } elseif ($fillMissing) {
                $out[$c] = null;
            }
        }

        return $out;
    }

    private function transaction(callable $fn): mixed
    {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $fn();
            if ($own) {
                $this->pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
