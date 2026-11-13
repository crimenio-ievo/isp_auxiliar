<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

use App\Infrastructure\Net\Store\NetOutageStore;
use App\Infrastructure\Net\Store\NetStore;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Leitura de quedas por período, para ONU e para PON (sem UI). Une duas fontes sem contar a mesma queda duas vezes:
 * - 'log': net_onu_outages (hora exata e motivo, vindas do syslog);
 * - 'poll': pares down->up de net_onu_events (hora aproximada, vale o ciclo de status, sem motivo).
 * Quando há queda do log da mesma ONU cobrindo a do poll (início do poll entre o início do log e o fim do log, com folga
 * de um ciclo de status), vale a do log. Por padrão só 'queda'; manutenção/boot só com $includeMaintenance.
 * O fuso é o do banco/aplicativo (o importador já converte o relógio da OLT).
 */
final class NetOutageReader
{
    public const POLL_LOOKBACK_DAYS = 30;
    private const REASONS = [
        'energia' => 'Queda de energia (Dying Gasp)',
        'sem_aviso_energia' => 'Sem aviso de energia',
        'desconhecida' => 'Detectada pelo poll, motivo não informado',
    ];

    /** @var callable():string */
    private $clock;

    public function __construct(private NetStore $net, private NetOutageStore $outages, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): string => date('Y-m-d H:i:s');
    }

    /**
     * @return array{kind:string,from:string,to:string}
     */
    public function period(string $kind, ?string $from = null, ?string $to = null): array
    {
        $now = ($this->clock)();
        $nowTs = self::ts($now);
        $span = ['1h' => 3600, '24h' => 86400, '7d' => 604800][$kind] ?? null;
        if ($span !== null) {
            return ['kind' => $kind, 'from' => gmdate('Y-m-d H:i:s', $nowTs - $span), 'to' => $now];
        }
        if ($kind !== 'custom') {
            throw new InvalidArgumentException('Período inválido (use 1h, 24h, 7d ou custom).');
        }
        $f = $from === null ? null : self::ts($from);
        $t = $to === null ? $nowTs : self::ts($to);
        if ($f === null || $t === null || $f > $t || $t - $f > 366 * 86400) {
            throw new InvalidArgumentException('Período personalizado inválido (início <= fim, no máximo 366 dias).');
        }

        return ['kind' => 'custom', 'from' => gmdate('Y-m-d H:i:s', $f), 'to' => gmdate('Y-m-d H:i:s', $t)];
    }

    /** @return array<string,mixed> */
    public function forOnu(int $deviceId, int $onuId, array $period, bool $includeMaintenance = false): array
    {
        return $this->report($deviceId, $period, $includeMaintenance, static fn (array $onu): bool => (int) $onu['id'] === $onuId);
    }

    /** @return array<string,mixed> */
    public function forPon(int $deviceId, int $ponIndex, array $period, bool $includeMaintenance = false): array
    {
        return $this->report($deviceId, $period, $includeMaintenance, static fn (array $onu): bool => (int) $onu['pon_index'] === $ponIndex);
    }

    /** @return array<string,mixed> */
    public function forDevice(int $deviceId, array $period, bool $includeMaintenance = false): array
    {
        return $this->report($deviceId, $period, $includeMaintenance, static fn (array $onu): bool => true);
    }

    /** @param callable(array):bool $selected */
    private function report(int $deviceId, array $period, bool $includeMaintenance, callable $selected): array
    {
        $device = $this->net->findDevice($deviceId);
        if ($device === null) {
            throw new InvalidArgumentException('Equipamento inexistente.');
        }
        $from = (string) $period['from'];
        $to = (string) $period['to'];
        $now = ($this->clock)();
        $edge = min($to, $now); // queda em aberto conta até agora (ou até o fim do período, se for passado)
        $tol = max(60, (int) ($device['status_interval_s'] ?? 600));

        $onus = [];
        $byMac = [];
        foreach ($this->net->deviceOnus($deviceId) as $o) {
            $onus[(int) $o['id']] = $o;
            $byMac[(string) $o['onu_identifier']] = (int) $o['id'];
        }

        // Log: por onu_id ou, se ele ainda for NULL, pelo MAC.
        $log = [];
        $unattributed = 0;
        foreach ($this->outages->outagesOverlapping($deviceId, $from, $to) as $r) {
            $id = $r['onu_id'] !== null ? (int) $r['onu_id'] : ($byMac[(string) $r['onu_identifier']] ?? null);
            $log[] = ['row' => $r, 'onu_id' => $id];
        }
        // Poll: pares down->up por ONU (olha 30 dias antes do período para pegar quedas que começaram antes).
        $poll = $this->pollOutages($deviceId, gmdate('Y-m-d H:i:s', self::ts($from) - self::POLL_LOOKBACK_DAYS * 86400), $to, $from);

        $items = [];
        foreach ($log as $l) {
            $r = $l['row'];
            $category = (string) $r['category'];
            if ($l['onu_id'] === null) {
                $unattributed++;
                continue;
            }
            if (!$selected($onus[$l['onu_id']] ?? ['id' => $l['onu_id'], 'pon_index' => null])) {
                continue;
            }
            if ($category !== 'queda' && !$includeMaintenance) {
                continue;
            }
            $items[] = $this->item($l['onu_id'], (string) $r['onu_identifier'], 'log', $category, (string) $r['cause'], $r['maintenance_reason'] ?? null, (string) $r['started_at'], $r['ended_at'] ?? null, $from, $edge);
        }
        foreach ($poll as $p) {
            $onu = $onus[$p['onu_id']] ?? null;
            if ($onu === null || !$selected($onu)) {
                continue;
            }
            if ($this->coveredByLog($p, $log, $tol)) {
                continue;
            }
            $items[] = $this->item($p['onu_id'], (string) $onu['onu_identifier'], 'poll', 'queda', 'desconhecida', null, $p['started_at'], $p['ended_at'], $from, $edge);
        }
        usort($items, static fn (array $a, array $b): int => [$a['started_at'], $a['onu_id']] <=> [$b['started_at'], $b['onu_id']]);

        $byCause = [];
        $downtime = 0;
        $open = 0;
        foreach ($items as $i) {
            if ($i['category'] !== 'queda') {
                continue;
            }
            $byCause[$i['cause']] = ($byCause[$i['cause']] ?? 0) + 1;
            $downtime += $i['downtime_in_period_s'];
            $open += $i['open'] ? 1 : 0;
        }

        return [
            'device_id' => $deviceId,
            'period' => $period,
            'outages' => $items,
            'totals' => [
                'quedas' => count(array_filter($items, static fn (array $i): bool => $i['category'] === 'queda')),
                'em_aberto' => $open,
                'downtime_no_periodo_s' => $downtime,
                'por_motivo' => $byCause,
                'do_log' => count(array_filter($items, static fn (array $i): bool => $i['source'] === 'log')),
                'do_poll' => count(array_filter($items, static fn (array $i): bool => $i['source'] === 'poll')),
                'log_sem_onu_cadastrada' => $unattributed,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function item(int $onuId, string $mac, string $source, string $category, string $cause, ?string $maintenance, string $start, ?string $end, string $from, string $edge): array
    {
        $s = self::ts($start);
        $e = $end === null ? null : self::ts($end);
        $clipStart = max($s, self::ts($from));
        $clipEnd = min($e ?? self::ts($edge), self::ts($edge));

        return [
            'onu_id' => $onuId,
            'onu_identifier_mascarado' => ValueDecoder::maskMac($mac),
            'source' => $source,
            'precisao' => $source === 'log' ? 'exata' : 'aproximada (ciclo de status)',
            'category' => $category,
            'maintenance_reason' => $maintenance,
            'started_at' => $start,
            'ended_at' => $end,
            'open' => $end === null,
            'duration_s' => $e === null ? null : $e - $s,
            'downtime_in_period_s' => max(0, $clipEnd - $clipStart),
            'cause' => $cause,
            'reason' => self::REASONS[$cause] ?? $cause,
        ];
    }

    /**
     * @return list<array{onu_id:int,started_at:string,ended_at:?string}> quedas do poll que tocam o período [from, to]
     */
    private function pollOutages(int $deviceId, string $fetchFrom, string $to, string $from): array
    {
        $open = [];
        $out = [];
        foreach ($this->outages->pollTransitions($deviceId, $fetchFrom, $to) as $e) {
            $id = (int) $e['onu_id'];
            if ($e['event_type'] === 'down' && !isset($open[$id])) {
                $open[$id] = $e['occurred_at'];
            } elseif ($e['event_type'] === 'up' && isset($open[$id])) {
                $out[] = ['onu_id' => $id, 'started_at' => $open[$id], 'ended_at' => $e['occurred_at']];
                unset($open[$id]);
            }
        }
        foreach ($open as $id => $start) {
            $out[] = ['onu_id' => $id, 'started_at' => $start, 'ended_at' => null];
        }

        return array_values(array_filter($out, static fn (array $p): bool => $p['started_at'] <= $to && ($p['ended_at'] === null || $p['ended_at'] >= $from)));
    }

    /**
     * @param array{onu_id:int,started_at:string,ended_at:?string} $p
     * @param list<array{row:array<string,mixed>,onu_id:?int}> $log
     */
    private function coveredByLog(array $p, array $log, int $tol): bool
    {
        $t = self::ts($p['started_at']);
        foreach ($log as $l) {
            if ($l['onu_id'] !== $p['onu_id']) {
                continue;
            }
            $s = self::ts((string) $l['row']['started_at']);
            $e = $l['row']['ended_at'] === null ? PHP_INT_MAX : self::ts((string) $l['row']['ended_at']);
            if ($t >= $s - $tol && $t <= $e + $tol) {
                return true;
            }
        }

        return false;
    }

    private static function ts(string $s): ?int
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $s, new \DateTimeZone('UTC'));

        return $d === false ? null : $d->getTimestamp();
    }
}
