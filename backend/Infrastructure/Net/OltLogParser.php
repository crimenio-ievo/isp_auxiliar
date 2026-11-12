<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Parser puro (sem banco, sem rede) do syslog da OLT exportado pela web.
 *
 * Regras (conferidas no arquivo de exemplo):
 * - a data vem do timestamp entre colchetes [AAAA-MM-DD hh:mm:ss] (hora do relógio da OLT, sem fuso);
 *   linhas sem colchetes (login web/CLI etc.) são ignoradas;
 * - a chave da ONU é o MAC entre colchetes, nunca o índice 0/x:y (índices mudam de PON);
 * - Dying Gasp e linkdown da mesma ONU em até 5 s = queda por energia; linkdown sem Dying Gasp
 *   = "sem aviso de energia" (não afirma fibra);
 * - dedupe: Dying Gasp repetido no mesmo segundo = 1; linkup repetido sem linkdown entre eles = 1;
 * - queda = linkdown até o próximo linkup do mesmo MAC; sem linkup = em aberto;
 * - "first time linkup" não é queda;
 * - manutenção/boot (não conta como queda): linkdown até 2 min após "EPON System Start" e linkdown
 *   dentro de janelas de manutenção informadas (padrão: troca de PON de 2026-11-09 20:23 a 20:26).
 *
 * Decisão documentada: "first time linkup" fecha uma queda aberta do mesmo MAC (a ONU voltou, ainda
 * que registrada de novo), em vez de deixá-la "em aberto" para sempre. Não cria queda por si só.
 */
final class OltLogParser
{
    public const DYING_GASP_WINDOW_S = 5;
    public const BOOT_GRACE_S = 120;

    private const ONU_LINE = '/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+ONU\s+(\d+\/\d+:\d+)\s*\[\s*([0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2}){5})\s*\]\s*\[[^\]]*\]\s*(.+?)\s*$/';
    private const START_LINE = '/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+EPON System Start/';

    /** @param list<array{from:string,to:string,reason:string}> $maintenanceWindows */
    public function __construct(private array $maintenanceWindows = self::DEFAULT_WINDOWS)
    {
    }

    /** @var list<array{from:string,to:string,reason:string}> */
    public const DEFAULT_WINDOWS = [
        ['from' => '2026-11-09 20:23:00', 'to' => '2026-11-09 20:26:59', 'reason' => 'troca_pon'],
    ];

    /**
     * @return array{outages:list<array<string,mixed>>, summary:array<string,int|string|null>}
     */
    public function parse(string $content): array
    {
        $summary = [
            'linhas' => 0, 'linhas_ignoradas' => 0, 'eventos_onu' => 0, 'onu_linhas_desconhecidas' => 0, 'linhas_invalidas' => 0,
            'dying_gasp_duplicados' => 0, 'linkup_duplicados' => 0, 'linkdown_duplicados' => 0, 'first_linkup' => 0,
            'linkup_sem_queda' => 0, 'dying_gasp_sem_linkdown' => 0, 'system_start' => 0,
            'primeiro_evento' => null, 'ultimo_evento' => null,
        ];
        $events = [];
        $starts = [];
        $idx = 0;
        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $summary['linhas']++;
            if (preg_match(self::START_LINE, $line, $m) === 1) {
                $t = self::ts($m[1]);
                if ($t === null) {
                    $summary['linhas_invalidas']++;
                    continue;
                }
                $starts[] = $t;
                $summary['system_start']++;
                continue;
            }
            if (preg_match(self::ONU_LINE, $line, $m) !== 1) {
                $summary['linhas_ignoradas']++;
                continue;
            }
            $t = self::ts($m[1]);
            if ($t === null) {
                $summary['linhas_invalidas']++;
                continue;
            }
            $text = strtolower(preg_replace('/\s+/', ' ', $m[4]) ?? '');
            $type = match (true) {
                $text === 'linkdown' => 'linkdown',
                $text === 'linkup' => 'linkup',
                $text === 'first time linkup' => 'first_linkup',
                str_starts_with($text, 'dying gasp') => 'dying_gasp',
                default => null,
            };
            if ($type === null) {
                $summary['onu_linhas_desconhecidas']++;
                continue;
            }
            $events[] = ['t' => $t, 'mac' => strtoupper($m[3]), 'type' => $type, 'index' => $m[2], 'n' => $idx++];
        }
        usort($events, static fn (array $a, array $b): int => [$a['t'], $a['n']] <=> [$b['t'], $b['n']]);
        $summary['eventos_onu'] = count($events);
        if ($events !== []) {
            $summary['primeiro_evento'] = self::fmt($events[0]['t']);
            $summary['ultimo_evento'] = self::fmt($events[count($events) - 1]['t']);
        }

        /** @var array<string,list<int>> $gasps mac => segundos dos Dying Gasp (já deduplicados) */
        $gasps = [];
        /** @var array<string,array<string,mixed>> $open */
        $open = [];
        /** @var array<string,string> $last */
        $last = [];
        $outages = [];
        foreach ($events as $e) {
            $mac = $e['mac'];
            switch ($e['type']) {
                case 'dying_gasp':
                    if (in_array($e['t'], $gasps[$mac] ?? [], true)) {
                        $summary['dying_gasp_duplicados']++;
                        break;
                    }
                    $gasps[$mac][] = $e['t'];
                    break;
                case 'linkdown':
                    if (isset($open[$mac])) {
                        $summary['linkdown_duplicados']++;
                        break;
                    }
                    $open[$mac] = ['mac' => $mac, 't0' => $e['t'], 'index_start' => $e['index']];
                    $last[$mac] = 'linkdown';
                    break;
                case 'linkup':
                case 'first_linkup':
                    if ($e['type'] === 'first_linkup') {
                        $summary['first_linkup']++;
                    }
                    if (isset($open[$mac])) {
                        $o = $open[$mac];
                        unset($open[$mac]);
                        $o['t1'] = $e['t'];
                        $o['index_end'] = $e['index'];
                        $o['returned_via'] = $e['type'];
                        $outages[] = $o;
                    } elseif ($e['type'] === 'linkup') {
                        $summary[($last[$mac] ?? null) === 'linkup' ? 'linkup_duplicados' : 'linkup_sem_queda']++;
                    }
                    $last[$mac] = 'linkup';
                    break;
            }
        }
        foreach ($open as $o) {
            $o['t1'] = null;
            $o['index_end'] = null;
            $o['returned_via'] = null;
            $outages[] = $o;
        }
        usort($outages, static fn (array $a, array $b): int => [$a['t0'], $a['mac']] <=> [$b['t0'], $b['mac']]);

        $used = [];
        $result = [];
        foreach ($outages as $o) {
            $gasp = null;
            foreach ($gasps[$o['mac']] ?? [] as $g) {
                if (abs($g - $o['t0']) <= self::DYING_GASP_WINDOW_S) {
                    $gasp = $g;
                    $used[$o['mac'] . '|' . $g] = true;
                    break;
                }
            }
            $maintenance = $this->maintenanceReason($o['t0'], $starts);
            $result[] = [
                'event_key' => hash('sha256', $o['mac'] . '|' . self::fmt($o['t0'])),
                'mac' => $o['mac'],
                'started_at' => self::fmt($o['t0']),
                'ended_at' => $o['t1'] === null ? null : self::fmt($o['t1']),
                'duration_s' => $o['t1'] === null ? null : $o['t1'] - $o['t0'],
                'open' => $o['t1'] === null,
                'cause' => $gasp !== null ? 'energia' : 'sem_aviso_energia',
                'dying_gasp_at' => $gasp === null ? null : self::fmt($gasp),
                'maintenance' => $maintenance,
                'counts_as_outage' => $maintenance === null,
                'returned_via' => $o['returned_via'],
                'log_index_start' => $o['index_start'],
                'log_index_end' => $o['index_end'],
            ];
        }
        foreach ($gasps as $mac => $list) {
            foreach ($list as $g) {
                if (!isset($used[$mac . '|' . $g])) {
                    $summary['dying_gasp_sem_linkdown']++;
                }
            }
        }

        return ['outages' => $result, 'summary' => $summary];
    }

    /** @param list<int> $starts */
    private function maintenanceReason(int $t, array $starts): ?string
    {
        foreach ($starts as $s) {
            if ($t >= $s && $t <= $s + self::BOOT_GRACE_S) {
                return 'boot';
            }
        }
        foreach ($this->maintenanceWindows as $w) {
            $from = self::ts($w['from']);
            $to = self::ts($w['to']);
            if ($from !== null && $to !== null && $t >= $from && $t <= $to) {
                return $w['reason'];
            }
        }

        return null;
    }

    private static function ts(string $s): ?int
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $s, new DateTimeZone('UTC'));
        $err = DateTimeImmutable::getLastErrors();
        if ($d === false || ($err !== false && ($err['warning_count'] > 0 || $err['error_count'] > 0))) {
            return null;
        }

        return $d->getTimestamp();
    }

    private static function fmt(int $t): string
    {
        return gmdate('Y-m-d H:i:s', $t);
    }
}
