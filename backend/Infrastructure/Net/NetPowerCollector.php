<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

use App\Infrastructure\Net\Snmp\SnmpTransport;
use App\Infrastructure\Net\Store\NetStore;

/**
 * Ciclo de potência (rx, tx, temperatura da ONU) de UM equipamento: só GET por índice conhecido das ONUs ONLINE,
 * sem walk. Mesmas regras de segurança do ciclo de status (identidade, teto, timeouts). Nada é apagado por falha da OLT.
 */
final class NetPowerCollector
{
    use SnmpCycleSupport;

    public const DEFAULTS = [
        'net.rx.warn_dbm' => -25.0,
        'net.rx.crit_dbm' => -27.0,
        'net.rx.sat_dbm' => -8.0,
        'net.rx.deviation_db' => 3.0,
        'net.sample.deadband_db' => 1.0,
        'net.baseline.samples' => 5,
    ];

    /** @var callable(array, DeviceProfile): SnmpTransport */
    private $transportFactory;
    /** @var callable(): string */
    private $clock;

    public function __construct(private NetStore $store, callable $transportFactory, ?callable $clock = null)
    {
        $this->transportFactory = $transportFactory;
        $this->clock = $clock ?? static fn (): string => date('Y-m-d H:i:s');
    }

    /** @return array<string,float> */
    public static function thresholds(array $settings): array
    {
        $t = self::DEFAULTS;
        foreach (self::DEFAULTS as $k => $default) {
            if (isset($settings[$k]) && is_numeric($settings[$k])) {
                $t[$k] = (float) $settings[$k];
            }
        }
        $t['net.baseline.samples'] = max(1, min(50, (int) $t['net.baseline.samples']));

        return $t;
    }

    public static function band(float $rx, array $t): string
    {
        if ($rx > $t['net.rx.sat_dbm']) {
            return 'saturado';
        }
        if ($rx >= $t['net.rx.warn_dbm']) {
            return 'ok';
        }

        return $rx >= $t['net.rx.crit_dbm'] ? 'atencao' : 'critico';
    }

    /**
     * @return array<string,mixed> resumo (sem segredos)
     */
    public function run(int $deviceId, ?int $pon = null, ?int $onuId = null, bool $dryRun = false): array
    {
        $t0 = microtime(true);
        $now = ($this->clock)();
        $this->requests = 0;
        $this->consecutiveTimeouts = 0;
        $this->requestsAtLastCheck = 0;
        $this->startIdentity = [];
        $summary = ['device_id' => $deviceId, 'dry_run' => $dryRun, 'started_at' => $now, 'scope' => ['pon' => $pon, 'onu' => $onuId], 'warnings' => []];
        $done = function (string $result, string $error = '') use (&$summary, &$t0): array { return $summary + ['result' => $result, 'error' => $error, 'requests' => $this->requests, 'cycle_ms' => (int) round((microtime(true) - $t0) * 1000)]; };

        $device = $this->store->findDevice($deviceId);
        if ($device === null || (int) ($device['enabled'] ?? 0) !== 1) {
            return $done('error', 'equipamento não encontrado ou desabilitado');
        }
        try {
            $this->profile = DeviceProfile::fromJson((string) $device['profile_code'], (string) $device['profile_json']);
            if ($this->profile->powerGet() === []) {
                return $done('error', 'perfil sem power_get');
            }
            $expected = json_decode((string) ($device['expected_identity_json'] ?? ''), true);
            if (!is_array($expected)) {
                return $done('identity_mismatch', 'sem identidade de referência aprovada (rode antes o ciclo de status)');
            }
            $this->transport = ($this->transportFactory)($device, $this->profile);
        } catch (\Throwable $e) {
            return $done('error', 'configuração: ' . $e->getMessage());
        }

        try {
            try {
                $identity = $this->readIdentity();
            } catch (AbortCycle $e) {
                return $done('timeout', 'equipamento sem resposta na identidade (nada alterado): ' . $e->getMessage());
            }
            $this->startIdentity = $identity;
            $diff = $this->identityDiff($expected, $identity);
            if ($diff !== []) {
                return $done('identity_mismatch', 'identidade diferente da referência: ' . implode(', ', $diff));
            }

            // Escopo: só ONUs online, não removidas, sem stale, do equipamento.
            $targets = [];
            $skipped = 0;
            foreach ($this->store->deviceOnus($deviceId) as $o) {
                if ($onuId !== null && (int) $o['id'] !== $onuId) {
                    continue;
                }
                if ($pon !== null && (int) $o['pon_index'] !== $pon) {
                    continue;
                }
                if ($o['removed_at'] !== null) {
                    continue;
                }
                if (($o['oper_status'] ?? 'unknown') !== 'online' || (int) ($o['stale'] ?? 0) === 1) {
                    $skipped++;
                    continue;
                }
                $targets[] = $o;
            }
            usort($targets, static fn ($a, $b) => [$a['pon_index'], $a['onu_index']] <=> [$b['pon_index'], $b['onu_index']]);

            $readings = [];
            $noReading = [];
            $metrics = $this->profile->powerGet();
            try {
                foreach ($targets as $o) {
                    $vals = [];
                    foreach (['onu_rx_dbm', 'onu_tx_dbm', 'onu_temp_c'] as $name) {
                        if (!isset($metrics[$name])) {
                            $vals[$name] = null;
                            continue;
                        }
                        $v = $this->getRow((string) $metrics[$name]['oid'] . '.' . $o['snmp_index']);
                        $vals[$name] = $v === null ? null : ValueDecoder::float($v);
                    }
                    if ($vals['onu_rx_dbm'] === null) {
                        $noReading[] = (string) $o['snmp_index'];
                        continue;
                    }
                    $readings[(int) $o['id']] = $vals;
                }
                $this->recheckIdentity();
            } catch (IdentityChanged $e) {
                return $done('identity_mismatch', 'identidade mudou durante o ciclo: ' . $e->getMessage());
            } catch (AbortCycle $e) {
                return $done('partial', 'ciclo interrompido, nada gravado: ' . $e->getMessage());
            }

            $settings = self::thresholds($this->store->providerSettings((int) $device['provider_id']));
            $plan = $this->plan($deviceId, $readings, $settings, $now);
            if (!$dryRun) {
                $this->store->applyPower($plan, $now);
            }
            $bands = ['ok' => 0, 'atencao' => 0, 'critico' => 0, 'saturado' => 0];
            $rxs = [];
            foreach ($plan['power'] as $p) {
                $bands[$p['rx_band']]++;
                $rxs[] = (float) $p['onu_rx_dbm'];
            }
            $evCount = [];
            foreach ($plan['events'] as [, $type]) {
                $evCount[$type] = ($evCount[$type] ?? 0) + 1;
            }
            if ($noReading !== []) {
                $summary['warnings'][] = count($noReading) . ' ONU(s) online sem leitura válida de rx (na/vazio): ' . implode(', ', $noReading);
            }

            return $done('ok') + [
                'onus_lidas' => count($readings),
                'puladas' => $skipped,
                'sem_leitura' => $noReading,
                'faixas' => $bands,
                'rx_min' => $rxs === [] ? null : min($rxs),
                'rx_max' => $rxs === [] ? null : max($rxs),
                'amostras' => count($plan['samples']),
                'eventos' => $evCount,
            ];
        } finally {
            $this->transport?->close();
        }
    }

    /**
     * @param array<int,array<string,?float>> $readings
     * @return array{power:array,samples:list<array>,events:list<array>}
     */
    private function plan(int $deviceId, array $readings, array $t, string $now): array
    {
        $prev = $this->store->powerStates($deviceId);
        $first = $this->store->firstSamples(array_keys($readings));
        $nBase = (int) $t['net.baseline.samples'];
        $today = substr($now, 0, 10);
        $plan = ['power' => [], 'samples' => [], 'events' => []];

        foreach ($readings as $id => $r) {
            $rx = $r['onu_rx_dbm'];
            $p = $prev[$id] ?? null;
            $band = self::band($rx, $t);
            $baselineN = (int) ($p['baseline_n'] ?? 0);
            $baseline = isset($p['baseline_rx_dbm']) ? (float) $p['baseline_rx_dbm'] : null;
            $baselineSetAt = $p['baseline_set_at'] ?? null;

            // Amostra: primeiras leituras (alimentam a referência), deadband ou primeira do dia.
            $reason = null;
            if ($p === null || $baselineN < $nBase) {
                $reason = 'first';
            } elseif (!isset($p['last_sample_rx_dbm']) || abs($rx - (float) $p['last_sample_rx_dbm']) >= $t['net.sample.deadband_db']) {
                $reason = 'deadband';
            } elseif (empty($p['last_sample_at']) || substr((string) $p['last_sample_at'], 0, 10) !== $today) {
                $reason = 'daily';
            }
            $lastSampleRx = isset($p['last_sample_rx_dbm']) ? (float) $p['last_sample_rx_dbm'] : null;
            $lastSampleAt = $p['last_sample_at'] ?? null;
            if ($reason !== null) {
                $plan['samples'][] = ['onu_id' => $id, 'sampled_at' => $now, 'onu_rx_dbm' => $rx, 'onu_tx_dbm' => $r['onu_tx_dbm'], 'onu_temp_c' => $r['onu_temp_c'], 'reason' => $reason];
                $lastSampleRx = $rx;
                $lastSampleAt = $now;
            }

            // Referência: mediana das primeiras N leituras válidas.
            $prevDeviated = $baseline !== null && isset($p['onu_rx_dbm']) && abs((float) $p['onu_rx_dbm'] - $baseline) >= $t['net.rx.deviation_db'];
            if ($baselineN < $nBase) {
                $baselineN++;
                if ($baselineN >= $nBase) {
                    $vals = array_merge($first[$id] ?? [], [$rx]);
                    $vals = array_slice($vals, 0, $nBase);
                    sort($vals);
                    $c = count($vals);
                    $baseline = round($c % 2 === 1 ? $vals[intdiv($c, 2)] : ($vals[$c / 2 - 1] + $vals[$c / 2]) / 2, 2);
                    $baselineSetAt = $now;
                    $prevDeviated = false;
                }
            }

            // Eventos.
            $prevBand = $p['rx_band'] ?? null;
            if ($prevBand !== null && $prevBand !== 'unknown' && $prevBand !== $band) {
                $plan['events'][] = [$id, 'band_change', ['from' => $prevBand, 'to' => $band, 'rx_dbm' => $rx]];
            }
            if ($baseline !== null && $baselineN >= $nBase) {
                $deviated = abs($rx - $baseline) >= $t['net.rx.deviation_db'];
                if ($deviated !== $prevDeviated) {
                    $plan['events'][] = [$id, 'rx_deviation', ['state' => $deviated ? 'enter' : 'exit', 'rx_dbm' => $rx, 'baseline_dbm' => $baseline, 'delta_db' => round($rx - $baseline, 2)]];
                }
            }

            $plan['power'][$id] = [
                'onu_rx_dbm' => $rx, 'onu_tx_dbm' => $r['onu_tx_dbm'], 'onu_temp_c' => $r['onu_temp_c'], 'measured_at' => $now, 'rx_band' => $band,
                'baseline_rx_dbm' => $baseline, 'baseline_set_at' => $baselineSetAt, 'baseline_n' => $baselineN,
                'last_sample_rx_dbm' => $lastSampleRx, 'last_sample_at' => $lastSampleAt,
            ];
        }

        return $plan;
    }
}
