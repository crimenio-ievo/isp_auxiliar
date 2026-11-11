<?php
declare(strict_types=1);
namespace App\Infrastructure\Net\Store;

final class InMemoryNetMaintenanceStore implements NetMaintenanceStore
{
    public array $audit = [];
    public array $batchSizes = [];
    public function __construct(private InMemoryNetStore $net, private InMemoryNetAlertStore $alerts) {}

    public function intervene(array $i, array $onuIds): void
    {
        $this->alerts->interventions[] = $i;
        foreach ($this->alerts->alerts as &$a) {
            if ($a['device_id'] === $i['device_id'] && $a['closed_at'] === null &&
                (($a['scope_type'] === $i['scope_type'] && $a['scope_id'] === $i['scope_id']) || ($a['scope_type'] === 'onu' && in_array($a['scope_id'], $onuIds, true)))) {
                $a['silenced_until'] = max($a['silenced_until'] ?? '', $i['silenced_until']);
            }
        }
        foreach ($onuIds as $id) {
            if (isset($this->net->power[$id])) { $this->net->power[$id] = array_merge($this->net->power[$id], ['baseline_rx_dbm' => null, 'baseline_set_at' => null, 'baseline_n' => 0, 'bad_streak' => 0, 'ok_streak' => 0, 'updated_at' => $i['created_at']]); }
        }
        $this->audit[] = ['action' => 'net.intervention.create', 'context' => $i + ['onu_ids' => $onuIds]];
    }

    public function providers(): array
    { return array_values(array_unique(array_column($this->net->devices, 'provider_id'))); }

    private function expired(string $kind, int $providerId, string $before): array
    {
        $out = [];
        $rows = match ($kind) { 'samples' => $this->net->samples, 'events' => $this->net->events, 'alerts' => $this->alerts->alerts, default => throw new \InvalidArgumentException('kind invalido') };
        foreach ($rows as $id => $r) {
            $p = $kind === 'alerts' ? $r['provider_id'] : ($this->net->onus[$r['onu_id']]['provider_id'] ?? null);
            $at = $r[match ($kind) { 'samples' => 'sampled_at', 'events' => 'occurred_at', 'alerts' => 'closed_at' }] ?? null;
            if ((int) $p === $providerId && $at !== null && $at < $before) { $out[] = $id; }
        }
        return $out;
    }
    public function countExpired(string $kind, int $providerId, string $before): int { return count($this->expired($kind, $providerId, $before)); }
    public function purgeBatch(string $kind, int $providerId, string $before): int
    {
        $ids = array_slice($this->expired($kind, $providerId, $before), 0, 5000);
        foreach ($ids as $id) {
            if ($kind === 'samples') { unset($this->net->samples[$id]); }
            elseif ($kind === 'events') { unset($this->net->events[$id]); }
            else { unset($this->alerts->alerts[$id]); }
        }
        $this->batchSizes[] = count($ids);
        return count($ids);
    }
}
