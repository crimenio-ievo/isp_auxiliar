<?php
declare(strict_types=1);
namespace App\Infrastructure\Net;
use App\Infrastructure\Net\Store\NetStore;
use App\Infrastructure\Net\Store\NetMaintenanceStore;

final class NetPurge
{
    private $clock;
    public function __construct(private NetStore $net, private NetMaintenanceStore $store, ?callable $clock = null)
    { $this->clock = $clock ?? static fn () => date('Y-m-d H:i:s'); }

    public function run(bool $apply = false): array
    {
        $now = ($this->clock)();
        $counts = ['samples' => 0, 'events' => 0, 'alerts' => 0];
        foreach ($this->store->providers() as $providerId) {
            $t = NetSettings::resolve($this->net->providerSettings((int) $providerId));
            foreach (['samples' => $t['net.retention.samples_days'], 'events' => $t['net.retention.events_days'], 'alerts' => 180] as $kind => $days) {
                $before = date('Y-m-d H:i:s', strtotime($now) - $days * 86400);
                if (!$apply) { $counts[$kind] += $this->store->countExpired($kind, (int) $providerId, $before); continue; }
                do { $n = $this->store->purgeBatch($kind, (int) $providerId, $before); $counts[$kind] += $n; } while ($n === 5000);
            }
        }
        return ['apply' => $apply, 'counts' => $counts, 'batch_size' => 5000];
    }
}
