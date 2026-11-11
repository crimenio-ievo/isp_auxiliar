<?php
declare(strict_types=1);
namespace App\Infrastructure\Net\Store;

final class InMemoryNetAlertStore implements NetAlertStore
{
    public array $alerts = [];
    public array $interventions = [];
    public function __construct(public InMemoryNetStore $net) {}

    public function eventsSince(int $deviceId, string $since): array
    {
        $events = [];
        foreach ($this->net->events as $i => $e) {
            if (($this->net->onus[$e['onu_id']]['device_id'] ?? null) === $deviceId && $e['occurred_at'] >= $since) {
                $events[] = $e + ['id' => $i + 1];
            }
        }
        usort($events, static fn ($a, $b) => [$a['occurred_at'], $a['id']] <=> [$b['occurred_at'], $b['id']]);
        return $events;
    }

    public function latestDeviations(int $deviceId): array
    {
        $out = [];
        foreach ($this->eventsSince($deviceId, '1000-01-01 00:00:00') as $e) {
            if ($e['event_type'] === 'rx_deviation') { $out[$e['onu_id']] = $e; }
        }
        return $out;
    }

    public function openAlerts(int $deviceId): array
    {
        return array_values(array_filter($this->alerts, static fn ($a) => $a['device_id'] === $deviceId && $a['closed_at'] === null));
    }

    public function silencedUntil(int $deviceId, string $scopeType, int $scopeId, ?int $pon, string $now): ?string
    {
        $until = null;
        foreach ($this->interventions as $i) {
            $match = ($i['scope_type'] === $scopeType && $i['scope_id'] === $scopeId)
                || ($scopeType === 'onu' && $i['scope_type'] === 'pon' && $i['scope_id'] === $deviceId * 1000 + $pon);
            if ($i['device_id'] === $deviceId && $match && $i['silenced_until'] > $now) { $until = max($until ?? '', $i['silenced_until']); }
        }
        return $until;
    }

    public function applyAlerts(int $deviceId, array $upserts, array $closeIds, string $now): void
    {
        foreach ($closeIds as $id) {
            if (($this->alerts[$id]['device_id'] ?? null) === $deviceId && $this->alerts[$id]['closed_at'] === null) { $this->alerts[$id]['closed_at'] = $now; }
        }
        foreach ($upserts as $a) {
            $id = null;
            foreach ($this->openAlerts($deviceId) as $old) {
                if ([$old['alert_type'], $old['scope_type'], $old['scope_id']] === [$a['alert_type'], $a['scope_type'], $a['scope_id']]) { $id = $old['id']; break; }
            }
            if ($id === null) { $id = $this->alerts === [] ? 1 : max(array_keys($this->alerts)) + 1; }
            $old = $this->alerts[$id] ?? [];
            $this->alerts[$id] = $a + ['id' => $id, 'device_id' => $deviceId, 'closed_at' => null, 'opened_at' => $old['opened_at'] ?? $now, 'last_seen_at' => $now];
            $this->alerts[$id]['silenced_until'] = max($old['silenced_until'] ?? '', $a['silenced_until'] ?? '') ?: null;
        }
    }
}
