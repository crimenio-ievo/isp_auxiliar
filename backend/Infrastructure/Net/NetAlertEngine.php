<?php
declare(strict_types=1);
namespace App\Infrastructure\Net;
use App\Infrastructure\Net\Store\NetStore;
use App\Infrastructure\Net\Store\NetAlertStore;

/** Regras deterministicas. Sem transporte ou acesso ao equipamento. CLI serializa por equipamento. */
final class NetAlertEngine
{
    private $clock;
    public function __construct(private NetStore $net, private NetAlertStore $store, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn () => date('Y-m-d H:i:s');
    }

    public function run(int $deviceId, bool $dryRun = false): array
    {
        $now = ($this->clock)();
        $device = $this->net->findDevice($deviceId);
        if ($device === null) { throw new \InvalidArgumentException('Equipamento inexistente.'); }
        $t = NetSettings::resolve($this->net->providerSettings((int) $device['provider_id']));
        $open = $this->store->openAlerts($deviceId);
        $byKey = [];
        foreach ($open as $a) { $byKey[$a['alert_type'].':'.$a['scope_id']] = $a; }
        $upserts = []; $close = [];
        $emit = function (string $type, string $scope, int $id, string $severity, array $details, ?int $pon = null) use (&$upserts, $deviceId, $device, $now): void {
            $upserts[] = ['provider_id' => (int) $device['provider_id'], 'alert_type' => $type, 'scope_type' => $scope, 'scope_id' => $id,
                'severity' => $severity, 'details' => $details,
                'silenced_until' => $this->store->silencedUntil($deviceId, $scope, $id, $pon, $now)];
        };
        $finish = function (bool $suppressed) use ($deviceId, $dryRun, $now, &$upserts, &$close): array {
            if (!$dryRun) { $this->store->applyAlerts($deviceId, $upserts, array_values(array_unique($close)), $now); }
            return ['device_id' => $deviceId, 'dry_run' => $dryRun, 'suppressed' => $suppressed, 'upserts' => $upserts, 'closed_ids' => array_values(array_unique($close))];
        };
        $poll = $this->net->pollState($deviceId) ?? [];
        $unreachable = $byKey['device_unreachable:'.$deviceId] ?? null;
        if (($poll['last_result'] ?? null) === 'ok') {
            if ($unreachable) { $close[] = (int) $unreachable['id']; }
        } elseif ((int) ($poll['consecutive_failures'] ?? 0) >= $t['net.alert.unreachable_failures'] && in_array($poll['last_result'] ?? '', ['timeout', 'partial'], true)) {
            $emit('device_unreachable', 'device', $deviceId, 'critico', ['consecutive_failures' => (int) $poll['consecutive_failures'], 'last_result' => $poll['last_result']]);
            return $finish(true);
        } elseif ($unreachable) {
            // So um status ok prova a recuperacao. Os alertas filhos existentes ficam preservados.
            return $finish(true);
        }

        $massSince = date('Y-m-d H:i:s', strtotime($now) - $t['net.alert.mass_window_min'] * 60);
        $unstableSince = date('Y-m-d H:i:s', strtotime($now) - $t['net.alert.unstable_hours'] * 3600);
        $since = min($massSince, $unstableSince);
        foreach ($open as $a) {
            if ($a['alert_type'] === 'mass_down_pon') { $since = min($since, $a['details']['window_started_at'] ?? $a['opened_at']); }
        }
        $events = $this->store->eventsSince($deviceId, $since);
        $onuEvents = []; $onus = []; $pons = [];
        foreach ($events as $e) { if ($e['occurred_at'] <= $now) { $onuEvents[(int) $e['onu_id']][] = $e; } }
        foreach ($this->net->deviceOnus($deviceId) as $o) {
            if ($o['removed_at'] !== null) { continue; }
            $onus[(int) $o['id']] = $o;
            $pons[(int) $o['pon_index']][] = $o;
        }

        foreach ($pons as $pon => $members) {
            if ($pon < 1 || $pon > 999) { throw new \RuntimeException('PON fora do intervalo de escopo (1..999).'); }
            $scope = $deviceId * 1000 + $pon;
            $existing = $byKey['mass_down_pon:'.$scope] ?? null;
            $onlineBefore = 0; $downs = []; $firstDown = $now;
            foreach ($members as $o) {
                $id = (int) $o['id']; $statusBefore = $o['oper_status'] ?? 'unknown';
                $changes = array_values(array_filter($onuEvents[$id] ?? [], static fn ($e) => $e['occurred_at'] >= $massSince && in_array($e['event_type'], ['up','down'], true)));
                if ($changes !== []) { $statusBefore = $changes[0]['event_type'] === 'down' ? 'online' : 'offline'; }
                if ($statusBefore !== 'online') { continue; }
                $onlineBefore++;
                foreach ($changes as $e) {
                    if ($e['event_type'] === 'down') { $downs[$id] = $id; $firstDown = min($firstDown, $e['occurred_at']); }
                }
            }
            $ids = $existing ? ($existing['details']['onu_ids'] ?? []) : array_values($downs);
            $start = $existing ? ($existing['details']['window_started_at'] ?? $existing['opened_at']) : $firstDown;
            $returned = 0;
            foreach ($ids as $id) {
                if (($onus[$id]['oper_status'] ?? null) !== 'online') { continue; }
                foreach ($onuEvents[$id] ?? [] as $e) {
                    if ($e['event_type'] === 'up' && $e['occurred_at'] >= $start) { $returned++; break; }
                }
            }
            if ($existing) {
                if ($returned > count($ids) / 2) { $close[] = (int) $existing['id']; }
                else { $emit('mass_down_pon', 'pon', $scope, 'critico', array_merge($existing['details'], ['returned' => $returned]), $pon); }
            } elseif (count($downs) >= 2 && count($downs) >= min($t['net.alert.mass_min_onus'], $onlineBefore)
                && $onlineBefore > 0 && count($downs) * 100 >= $onlineBefore * $t['net.alert.mass_pct'] && $returned <= count($ids) / 2) {
                $emit('mass_down_pon', 'pon', $scope, 'critico', ['pon_index' => $pon, 'onu_ids' => $ids, 'online_before' => $onlineBefore,
                    'window_started_at' => $firstDown, 'returned' => $returned, 'causa' => 'indeterminada (sem dying gasp no SNMP)'], $pon);
            }
        }
        // PON sem ONUs (movidas/removidas): nao inferir recuperacao da ausencia de inventario.

        $power = $this->net->powerStates($deviceId);
        $deviations = $this->store->latestDeviations($deviceId);
        foreach ($this->net->deviceOnus($deviceId) as $o) {
            $id = (int) $o['id']; $pon = (int) $o['pon_index'];
            $downs = array_values(array_filter($onuEvents[$id] ?? [], static fn ($e) => $e['event_type'] === 'down' && $e['occurred_at'] >= $unstableSince));
            $unstable = $byKey['onu_unstable:'.$id] ?? null;
            if ($o['removed_at'] === null && count($downs) >= $t['net.alert.unstable_downs']) {
                $emit('onu_unstable', 'onu', $id, 'atencao', ['downs' => count($downs), 'hours' => $t['net.alert.unstable_hours']], $pon);
            } elseif ($unstable && ($downs === [] || $o['removed_at'] !== null)) { $close[] = (int) $unstable['id']; }

            $eligible = $o['removed_at'] === null && ($o['oper_status'] ?? '') === 'online' && !(int) ($o['stale'] ?? 0);
            $p = $power[$id] ?? [];
            $range = $byKey['rx_range:'.$id] ?? null;
            $deviation = $byKey['rx_deviation:'.$id] ?? null;
            if (!$eligible) {
                if ($range) { $close[] = (int) $range['id']; }
                if ($deviation) { $close[] = (int) $deviation['id']; }
                continue;
            }
            if ((int) ($p['bad_streak'] ?? 0) >= $t['net.alert.band_cycles'] && in_array($p['rx_band'] ?? '', ['atencao','critico','saturado'], true)) {
                $emit('rx_range', 'onu', $id, in_array($p['rx_band'], ['critico','saturado'], true) ? 'critico' : 'atencao', ['rx_band' => $p['rx_band'], 'rx_dbm' => $p['onu_rx_dbm'] ?? null, 'bad_streak' => (int) $p['bad_streak']], $pon);
            } elseif ($range && (int) ($p['ok_streak'] ?? 0) >= $t['net.alert.band_cycles']) { $close[] = (int) $range['id']; }
            $e = $deviations[$id] ?? null;
            $referenceValid = isset($p['baseline_rx_dbm'], $p['baseline_set_at']) && $e && $e['occurred_at'] >= $p['baseline_set_at'] && $e['occurred_at'] <= $now;
            if ($referenceValid && ($e['details']['state'] ?? '') === 'enter') {
                $emit('rx_deviation', 'onu', $id, 'atencao', $e['details'], $pon);
            } elseif ($deviation && (!isset($p['baseline_rx_dbm'], $p['baseline_set_at']) || ($e && (!$referenceValid || ($e['details']['state'] ?? '') === 'exit')))) { $close[] = (int) $deviation['id']; }
        }
        return $finish(false);
    }
}
