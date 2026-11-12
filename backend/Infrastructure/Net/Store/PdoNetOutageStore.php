<?php

declare(strict_types=1);

namespace App\Infrastructure\Net\Store;

use PDO;

final class PdoNetOutageStore implements NetOutageStore
{
    private const COLS = ['provider_id', 'device_id', 'onu_id', 'onu_identifier', 'source', 'event_key', 'started_at', 'ended_at', 'duration_s', 'cause', 'category', 'maintenance_reason', 'dying_gasp_at', 'returned_via', 'imported_at', 'updated_at'];

    public function __construct(private PDO $pdo)
    {
    }

    public function existing(int $deviceId, string $source, array $keys): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($keys)), 200) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $st = $this->pdo->prepare("SELECT * FROM net_onu_outages WHERE device_id = ? AND source = ? AND event_key IN ({$in})");
            $st->execute(array_merge([$deviceId, $source], $chunk));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[$row['event_key']] = $row;
            }
        }

        return $out;
    }

    public function save(array $inserts, array $updates, array $audit): void
    {
        $this->pdo->beginTransaction();
        try {
            $ins = $this->pdo->prepare('INSERT INTO net_onu_outages (' . implode(',', self::COLS) . ') VALUES (' . implode(',', array_fill(0, count(self::COLS), '?')) . ')');
            foreach ($inserts as $r) {
                $ins->execute(array_map(static fn (string $c) => $r[$c] ?? null, self::COLS));
            }
            $upd = $this->pdo->prepare(
                'UPDATE net_onu_outages SET onu_id = ?, started_at = ?, ended_at = ?, duration_s = ?, cause = ?, category = ?, maintenance_reason = ?, dying_gasp_at = ?, returned_via = ?, updated_at = ?
                  WHERE device_id = ? AND source = ? AND event_key = ?'
            );
            foreach ($updates as $r) {
                $upd->execute([$r['onu_id'] ?? null, $r['started_at'], $r['ended_at'] ?? null, $r['duration_s'] ?? null, $r['cause'], $r['category'], $r['maintenance_reason'] ?? null, $r['dying_gasp_at'] ?? null, $r['returned_via'] ?? null, $r['updated_at'], $r['device_id'], $r['source'], $r['event_key']]);
            }
            $a = $this->pdo->prepare("INSERT INTO audit_logs (provider_id,actor_login,action,entity_type,entity_id,context_json,created_at) VALUES (?,'net_cli','net.log_import','net_device',?,?,?)");
            $a->execute([$audit['provider_id'], $audit['device_id'], json_encode($audit['context'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $audit['created_at']]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function outagesOverlapping(int $deviceId, string $from, string $to): array
    {
        $st = $this->pdo->prepare('SELECT * FROM net_onu_outages WHERE device_id = ? AND started_at <= ? AND (ended_at IS NULL OR ended_at >= ?) ORDER BY started_at, onu_identifier');
        $st->execute([$deviceId, $to, $from]);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function pollTransitions(int $deviceId, string $from, string $to): array
    {
        $st = $this->pdo->prepare(
            "SELECT e.onu_id, e.event_type, e.occurred_at FROM net_onu_events e JOIN net_onus o ON o.id = e.onu_id
              WHERE o.device_id = ? AND e.event_type IN ('up','down') AND e.occurred_at BETWEEN ? AND ?
              ORDER BY e.onu_id, e.occurred_at, e.id"
        );
        $st->execute([$deviceId, $from, $to]);

        return array_map(static fn (array $r): array => ['onu_id' => (int) $r['onu_id'], 'event_type' => $r['event_type'], 'occurred_at' => $r['occurred_at']], $st->fetchAll(PDO::FETCH_ASSOC));
    }
}
