<?php
declare(strict_types=1);
namespace App\Infrastructure\Net;
use App\Infrastructure\Net\Store\NetStore;
use App\Infrastructure\Net\Store\NetMaintenanceStore;

final class NetIntervention
{
    private $clock;
    public function __construct(private NetStore $net, private NetMaintenanceStore $store, ?callable $clock = null)
    { $this->clock = $clock ?? static fn () => date('Y-m-d H:i:s'); }

    public function run(int $deviceId, ?int $pon, ?int $onuId, ?int $hours = null, string $reason = '', bool $dryRun = false): array
    {
        $device = $this->net->findDevice($deviceId);
        if (!$device || (($pon === null) === ($onuId === null)) || ($pon !== null && ($pon < 1 || $pon > 999)) || ($onuId !== null && $onuId < 1)) {
            throw new \InvalidArgumentException('Informe equipamento existente e exatamente um escopo PON (1..999) ou ONU.');
        }
        $hours ??= NetSettings::resolve($this->net->providerSettings((int) $device['provider_id']))['net.alert.mute_default_hours'];
        if ($hours < 1 || $hours > 65535 || mb_strlen($reason) > 500) { throw new \InvalidArgumentException('Horas (1..65535) ou motivo (max. 500 caracteres) invalidos.'); }
        $ids = [];
        foreach ($this->net->deviceOnus($deviceId) as $o) {
            if (($pon !== null && (int) $o['pon_index'] === $pon) || ($onuId !== null && (int) $o['id'] === $onuId)) { $ids[] = (int) $o['id']; }
        }
        if ($ids === []) { throw new \InvalidArgumentException('Escopo sem ONU deste equipamento.'); }
        $now = ($this->clock)();
        $i = ['provider_id' => (int) $device['provider_id'], 'device_id' => $deviceId, 'scope_type' => $pon !== null ? 'pon' : 'onu',
            'scope_id' => $pon !== null ? $deviceId * 1000 + $pon : $onuId, 'created_at' => $now,
            'silenced_until' => date('Y-m-d H:i:s', strtotime($now) + $hours * 3600), 'reason' => $reason];
        if (!$dryRun) { $this->store->intervene($i, $ids); }
        return ['dry_run' => $dryRun, 'intervention' => $i, 'onu_ids' => $ids];
    }
}
