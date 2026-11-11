<?php
declare(strict_types=1);
namespace App\Infrastructure\Net\Store;
use PDO;

final class PdoNetAlertStore implements NetAlertStore
{
    public function __construct(private PDO $pdo) {}

    public function eventsSince(int $deviceId, string $since): array
    {
        $s = $this->pdo->prepare('SELECT e.* FROM net_onu_events e JOIN net_onus o ON o.id=e.onu_id WHERE o.device_id=? AND e.occurred_at>=? ORDER BY e.occurred_at,e.id');
        $s->execute([$deviceId, $since]);
        return $this->decode($s->fetchAll(PDO::FETCH_ASSOC));
    }

    public function latestDeviations(int $deviceId): array
    {
        $s = $this->pdo->prepare("SELECT e.* FROM net_onu_events e JOIN net_onus o ON o.id=e.onu_id WHERE o.device_id=? AND e.event_type='rx_deviation' AND NOT EXISTS (SELECT 1 FROM net_onu_events newer WHERE newer.onu_id=e.onu_id AND newer.event_type='rx_deviation' AND (newer.occurred_at>e.occurred_at OR (newer.occurred_at=e.occurred_at AND newer.id>e.id)))");
        $s->execute([$deviceId]);
        $out = [];
        foreach ($this->decode($s->fetchAll(PDO::FETCH_ASSOC)) as $e) { $out[(int) $e['onu_id']] = $e; }
        return $out;
    }

    public function openAlerts(int $deviceId): array
    {
        $s = $this->pdo->prepare('SELECT * FROM net_alerts WHERE device_id=? AND closed_at IS NULL');
        $s->execute([$deviceId]);
        return $this->decode($s->fetchAll(PDO::FETCH_ASSOC));
    }

    public function silencedUntil(int $deviceId, string $scopeType, int $scopeId, ?int $pon, string $now): ?string
    {
        $s = $this->pdo->prepare("SELECT MAX(silenced_until) FROM net_interventions WHERE device_id=? AND silenced_until>? AND ((scope_type=? AND scope_id=?) OR (?='onu' AND scope_type='pon' AND scope_id=?))");
        $s->execute([$deviceId, $now, $scopeType, $scopeId, $scopeType, $deviceId * 1000 + ($pon ?? 0)]);
        return $s->fetchColumn() ?: null;
    }

    public function applyAlerts(int $deviceId, array $upserts, array $closeIds, string $now): void
    {
        $this->pdo->beginTransaction();
        try {
            $close = $this->pdo->prepare('UPDATE net_alerts SET closed_at=? WHERE id=? AND device_id=? AND closed_at IS NULL');
            foreach ($closeIds as $id) { $close->execute([$now, $id, $deviceId]); }
            $s = $this->pdo->prepare('INSERT INTO net_alerts (provider_id,device_id,alert_type,scope_type,scope_id,severity,opened_at,last_seen_at,silenced_until,details_json) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE severity=VALUES(severity),last_seen_at=VALUES(last_seen_at),details_json=VALUES(details_json),silenced_until=GREATEST(COALESCE(silenced_until,VALUES(silenced_until)),COALESCE(VALUES(silenced_until),silenced_until))');
            foreach ($upserts as $a) { $s->execute([$a['provider_id'], $deviceId, $a['alert_type'], $a['scope_type'], $a['scope_id'], $a['severity'], $now, $now, $a['silenced_until'], json_encode($a['details'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]); }
            $this->pdo->commit();
        } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }

    private function decode(array $rows): array
    {
        foreach ($rows as &$r) { $r['details'] = json_decode((string) ($r['details_json'] ?? '{}'), true) ?: []; }
        return $rows;
    }
}
