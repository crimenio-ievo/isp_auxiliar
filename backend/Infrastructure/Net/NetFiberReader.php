<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

use App\Infrastructure\Net\Store\PdoNetOutageStore;
use App\Infrastructure\Net\Store\PdoNetStore;
use App\Infrastructure\Net\Store\PollOnlyOutageStore;
use PDO;

/**
 * Leitura (somente SELECT) para a tela "Rede de fibra": árvore OLT > PON > ONU e ficha da ONU.
 * Não acessa a OLT nem o MkAuth. O que ainda não existe (cliente, CTO, sinal recebido na OLT) volta como null.
 * Distância é ESTIMATIVA pelo RTT (hipótese validada em 7 ONUs, erro máx. 0,4 m), nunca coluna SNMP.
 */
final class NetFiberReader
{
    public const DIST_SLOPE_M = 1.6395;
    public const DIST_OFFSET = 193.2;
    public const SERIES_DAYS = 7;

    /** @var callable():string */
    private $clock;

    public function __construct(private PDO $pdo, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): string => date('Y-m-d H:i:s');
    }

    /** @return array<string,mixed> */
    public function tree(int $providerId, ?int $deviceId = null): array
    {
        $now = ($this->clock)();
        $store = new PdoNetStore($this->pdo);
        $limits = $this->limits($providerId, $store);
        $reader = $this->outageReader($logAvailable);
        $period = $reader->period('24h');
        $bindings = new NetBindingRepository($this->pdo);

        $sql = 'SELECT d.id, d.map_node_id, d.enabled, d.status_interval_s, n.name, n.node_type, n.parent_node_id, n.attrs_json, d.mgmt_host,
                       st.name AS site_name, st.node_type AS site_type, pr.technology, ps.last_attempt_at, ps.last_success_at, ps.last_result
                  FROM net_devices d JOIN map_nodes n ON n.id = d.map_node_id
                  LEFT JOIN map_nodes st ON st.id = n.parent_node_id AND st.node_type IN (\'pop\', \'tower\')
                  LEFT JOIN net_device_profiles pr ON pr.id = d.profile_id
                  LEFT JOIN net_device_poll_state ps ON ps.device_id = d.id
                 WHERE d.provider_id = ?' . ($deviceId !== null ? ' AND d.id = ?' : '') . ' ORDER BY st.name IS NULL, st.name, n.name, d.id';
        $st = $this->pdo->prepare($sql);
        $st->execute($deviceId !== null ? [$providerId, $deviceId] : [$providerId]);
        $devices = [];
        $sum = ['onus' => 0, 'online' => 0, 'offline' => 0, 'desconhecido' => 0, 'atencao' => 0, 'critico' => 0, 'saturado' => 0, 'sem_sinal' => 0, 'quedas_24h' => 0, 'sem_vinculo' => 0, 'sem_resposta' => 0];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $id = (int) $d['id'];
            $down = [];
            foreach ($reader->forDevice($id, $period)['outages'] as $o) {
                $k = (int) $o['onu_id'];
                $down[$k]['n'] = ($down[$k]['n'] ?? 0) + 1;
                $down[$k]['last'] = max($down[$k]['last'] ?? '', $o['started_at']);
            }
            $pons = [];
            $p = $this->pdo->prepare("SELECT port_num, label FROM map_ports WHERE node_id = ? AND port_role = 'pon' ORDER BY port_num");
            $p->execute([(int) $d['map_node_id']]);
            foreach ($p->fetchAll(PDO::FETCH_ASSOC) as $port) {
                $pons[(int) $port['port_num']] = ['pon_index' => (int) $port['port_num'], 'label' => (string) ($port['label'] ?: 'PON ' . $port['port_num']), 'onus' => []];
            }
            $deviceRows = $this->onuRows($id);
            $links = $bindings->forOnus($providerId, array_map(static fn (array $r): int => (int) $r['id'], $deviceRows));
            foreach ($deviceRows as $r) {
                $onu = $this->onuItem($r, $limits, $now);
                $this->applyBinding($onu, $links[$onu['id']] ?? []);
                $onu['quedas_24h'] = $down[$onu['id']]['n'] ?? 0;
                $onu['ultima_queda_em'] = $down[$onu['id']]['last'] ?? null;
                $pi = (int) $r['pon_index'];
                $pons[$pi] ??= ['pon_index' => $pi, 'label' => 'PON ' . $pi, 'onus' => []];
                $pons[$pi]['onus'][] = $onu;
                $sum['onus']++;
                $sum[['online' => 'online', 'offline' => 'offline'][$onu['status']] ?? 'desconhecido']++;
                if (in_array($onu['rx_band'], ['atencao', 'critico', 'saturado'], true)) {
                    $sum[$onu['rx_band']]++;
                }
                $sum['sem_sinal'] += $onu['rx_dbm'] === null ? 1 : 0;
                $sum['quedas_24h'] += $onu['quedas_24h'] > 0 ? 1 : 0;
                $sum['sem_vinculo'] += $onu['cliente'] === null ? 1 : 0;
                $sum['sem_resposta'] += $onu['desatualizado'] ? 1 : 0;
            }
            ksort($pons);
            foreach ($pons as &$pon) {
                $pon['total'] = count($pon['onus']);
                $pon['online'] = count(array_filter($pon['onus'], static fn (array $o): bool => $o['status'] === 'online'));
            }
            unset($pon);
            $devices[] = [
                'id' => $id, 'nome' => (string) $d['name'], 'ativo' => (bool) $d['enabled'], 'intervalo_status_s' => (int) $d['status_interval_s'],
                'ultima_tentativa' => $d['last_attempt_at'], 'ultima_leitura_ok' => $d['last_success_at'], 'ultimo_resultado' => $d['last_result'],
                'pons' => array_values($pons),
                'saude' => $this->health((int) $d['status_interval_s'], $d['last_success_at'], $d['last_result'], $now),
                'tecnologia' => $d['technology'] === null ? null : (string) $d['technology'],
                'tipo_no' => (string) $d['node_type'],
                'modelo' => array_intersect_key(json_decode((string) $d['attrs_json'], true) ?: [], array_flip(['fabricante','modelo'])), 'ip' => (string) $d['mgmt_host'],
                'site' => $d['site_name'] === null ? null : ['id' => (int) $d['parent_node_id'], 'nome' => (string) $d['site_name'], 'tipo' => (string) $d['site_type']],
            ];
        }

        return [
            'gerado_em' => $now,
            'dispositivos' => $devices,
            'resumo' => $sum,
            'limiares' => $limits,
            'fontes' => ['quedas_poll' => true, 'quedas_log' => $logAvailable, 'vinculo_cliente' => $bindings->available(), 'cto' => $bindings->available(), 'sinal_na_olt' => false],
        ];
    }

    /**
     * Saúde da coleta do equipamento (sem afirmar causa): 'ok' | 'atrasada' (última leitura ok mais velha que 2 intervalos de
     * status) | 'falha' (último resultado diferente de ok) | 'sem_leitura' (nunca houve leitura ok).
     *
     * @return array{estado:string,minutos:?int,limite_s:int}
     */
    private function health(int $interval, ?string $lastOk, ?string $lastResult, string $now): array
    {
        $limit = 2 * max(60, $interval);
        $age = $lastOk === null ? null : max(0, (int) strtotime($now . ' UTC') - (int) strtotime($lastOk . ' UTC'));
        $estado = match (true) {
            $age === null => 'sem_leitura',
            $lastResult !== null && $lastResult !== 'ok' => 'falha',
            $age > $limit => 'atrasada',
            default => 'ok',
        };

        return ['estado' => $estado, 'minutos' => $age === null ? null : intdiv($age, 60), 'limite_s' => $limit];
    }

    /**
     * Eventos recentes: quedas correlacionadas por PON (até 10 s entre inícios), sem manutenção/boot.
     *
     * @return array<string,mixed>
     */
    public function events(int $providerId, string $kind = '24h', ?string $from = null, ?string $to = null, ?int $deviceId = null): array
    {
        $now = ($this->clock)();
        $reader = $this->outageReader($logAvailable);
        $period = $reader->period($kind, $from, $to);
        $st = $this->pdo->prepare('SELECT d.id, n.name FROM net_devices d JOIN map_nodes n ON n.id = d.map_node_id WHERE d.provider_id = ?' . ($deviceId !== null ? ' AND d.id = ?' : '') . ' ORDER BY d.id');
        $st->execute($deviceId !== null ? [$providerId, $deviceId] : [$providerId]);
        $groups = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $id = (int) $d['id'];
            $onus = [];
            foreach ($this->onuRows($id) as $r) {
                $onus[(int) $r['id']] = $r;
            }
            $labels = [];
            $p = $this->pdo->prepare("SELECT p.port_num, p.label FROM map_ports p JOIN net_devices d ON d.map_node_id = p.node_id WHERE d.id = ? AND p.port_role = 'pon'");
            $p->execute([$id]);
            foreach ($p->fetchAll(PDO::FETCH_ASSOC) as $port) {
                $labels[(int) $port['port_num']] = (string) ($port['label'] ?: 'PON ' . $port['port_num']);
            }
            foreach ($reader->groupedByPon($id, $period) as $g) {
                $g['dispositivo_id'] = $id;
                $g['dispositivo_nome'] = (string) $d['name'];
                $g['pon_label'] = $labels[$g['pon_index']] ?? 'PON ' . $g['pon_index'];
                $g['onus'] = array_map(static function (array $o) use ($onus): array {
                    $row = $onus[$o['onu_id']] ?? null;
                    $mac = $row === null ? '' : (string) $row['onu_identifier'];

                    return [
                        'onu_id' => $o['onu_id'], 'onu_index' => $row === null ? null : (int) $row['onu_index'],
                        'mac' => strlen($mac) === 12 ? implode(':', str_split($mac, 2)) : $mac,
                        'started_at' => $o['started_at'], 'ended_at' => $o['ended_at'], 'open' => $o['open'], 'duration_s' => $o['duration_s'],
                        'cause' => $o['cause'], 'source' => $o['source'],
                    ];
                }, $g['quedas']);
                unset($g['quedas'], $g['device_id']);
                $groups[] = $g;
            }
        }
        usort($groups, static fn (array $a, array $b): int => [$b['inicio'], $a['dispositivo_id'], $a['pon_index']] <=> [$a['inicio'], $b['dispositivo_id'], $b['pon_index']]);

        return ['gerado_em' => $now, 'periodo' => $period, 'grupos' => $groups, 'fontes' => ['quedas_poll' => true, 'quedas_log' => $logAvailable]];
    }

    /** @return array<string,mixed>|null null se a ONU não existe neste provedor */
    public function sheet(int $providerId, int $onuId, string $kind = '24h', ?string $from = null, ?string $to = null): ?array
    {
        $now = ($this->clock)();
        $row = $this->onuRows(null, $onuId, $providerId)[0] ?? null;
        if ($row === null) {
            return null;
        }
        $store = new PdoNetStore($this->pdo);
        $limits = $this->limits($providerId, $store);
        $onu = $this->onuItem($row, $limits, $now);
        $bindings = new NetBindingRepository($this->pdo);
        $this->applyBinding($onu, $bindings->forOnus($providerId, [$onuId])[$onuId] ?? []);
        $deviceId = (int) $row['device_id'];

        // Mediana dos vizinhos da PON (outras ONUs online com leitura), sem a própria ONU.
        $neigh = [];
        foreach ($this->onuRows($deviceId) as $r) {
            if ((int) $r['id'] !== $onuId && (int) $r['pon_index'] === (int) $row['pon_index'] && $r['oper_status'] === 'online' && $r['onu_rx_dbm'] !== null) {
                $neigh[] = (float) $r['onu_rx_dbm'];
            }
        }
        sort($neigh);
        $c = count($neigh);
        $median = $c === 0 ? null : ($c % 2 === 1 ? $neigh[intdiv($c, 2)] : ($neigh[$c / 2 - 1] + $neigh[$c / 2]) / 2);

        $reader = $this->outageReader($logAvailable);
        $period = $reader->period($kind, $from, $to);
        $rep = $reader->forOnu($deviceId, $onuId, $period);
        $recent = $reader->forOnu($deviceId, $onuId, $reader->period('custom', gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') - 30 * 86400), $now));
        $st = $this->pdo->prepare('SELECT sampled_at, onu_rx_dbm FROM net_onu_power_samples WHERE onu_id = ? AND sampled_at < ? AND onu_rx_dbm IS NOT NULL ORDER BY sampled_at DESC, id DESC LIMIT 1');
        $st->execute([$onuId, $period['from']]);
        $prior = $st->fetch(\PDO::FETCH_ASSOC);
        $series = $prior ? [['t' => $period['from'], 'rx' => (float) $prior['onu_rx_dbm'], 'anterior' => true]] : [];
        $st = $this->pdo->prepare('SELECT sampled_at, onu_rx_dbm FROM net_onu_power_samples WHERE onu_id = ? AND sampled_at >= ? AND sampled_at <= ? AND onu_rx_dbm IS NOT NULL ORDER BY sampled_at, id');
        $st->execute([$onuId, $period['from'], $period['to']]);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $sample) { $series[] = ['t' => $sample['sampled_at'], 'rx' => (float) $sample['onu_rx_dbm']]; }
        if ($onu['rx_dbm'] !== null && $onu['rx_medido_em'] !== null && $onu['rx_medido_em'] >= $period['from'] && $onu['rx_medido_em'] <= $period['to'] && ($series === [] || $series[count($series)-1]['t'] < $onu['rx_medido_em'])) {
            $series[] = ['t' => $onu['rx_medido_em'], 'rx' => $onu['rx_dbm']];
        }
        $last = array_slice(array_reverse($recent['outages']), 0, 5);

        return [
            'onu' => $onu + [
                'baseline_dbm' => $row['baseline_rx_dbm'] === null ? null : (float) $row['baseline_rx_dbm'],
                'baseline_desde' => $row['baseline_set_at'],
                'baseline_amostras' => (int) ($row['baseline_n'] ?? 0),
                'olt_rx_dbm' => null, 'olt_rx_texto' => 'ainda não lido',
            ],
            'vizinhos' => ['mediana_dbm' => $median === null ? null : round($median, 2), 'quantidade' => $c, 'diferenca_db' => ($median === null || $onu['rx_dbm'] === null) ? null : round($onu['rx_dbm'] - $median, 2)],
            'serie' => ['pontos' => $series, 'de' => $period['from'], 'ate' => $period['to']],
            'periodo' => $rep['period'],
            'quedas' => $rep['outages'],
            'quedas_total' => $rep['totals'],
            'quedas_serie' => $rep['outages'],
            'ultimas_quedas' => $last,
            'estavel' => $rep['totals']['quedas'] === 0,
            'estavel_texto' => $rep['totals']['quedas'] === 0 ? 'Sem queda registrada no período (estável).' : null,
            'limiares' => $limits,
            'fontes' => ['quedas_poll' => true, 'quedas_log' => $logAvailable],
            'cliente' => $onu['cliente'],
            'clientes' => $onu['clientes'],
            'n_clientes' => $onu['n_clientes'],
            'cliente_texto' => $onu['cliente'] === null ? 'sem vínculo' : ($onu['cliente']['nome'] ?? $onu['cliente']['login']) . ($onu['n_clientes'] > 1 ? ' +' . ($onu['n_clientes'] - 1) : ''),
            'cto' => $onu['cto'],
            'cto_texto' => $onu['cto'] === null ? ($bindings->available() ? 'sem CTO' : 'ainda não lido') : $onu['cto']['nome'] . ($onu['cto']['porta'] !== null ? ' · ' . $onu['cto']['porta'] : ''),
        ];
    }

    /**
     * Preenche clientes/CTO da ONU a partir dos vinculos ativos (client_network_bindings). Varios clientes por ONU:
     * `clientes` e a lista (ordem de criacao), `cliente` e o primeiro (compatibilidade) e `n_clientes` a contagem.
     * A CTO/porta e da ONU: vem do primeiro vinculo que a tem. Sem vinculo: cliente null, lista vazia.
     *
     * @param array<string,mixed> $onu
     * @param list<array<string,mixed>> $rows
     */
    private function applyBinding(array &$onu, array $rows): void
    {
        $onu['clientes'] = array_map(static fn (array $b): array => [
            'login' => (string) $b['mkauth_login'],
            'nome' => $b['cliente_nome'] === null ? null : (string) $b['cliente_nome'],
            'modo_pppoe' => $b['modo_pppoe'] ?? null,
        ], $rows);
        $onu['n_clientes'] = count($rows);
        $onu['cliente'] = $onu['clientes'] === [] ? null : ['login' => $onu['clientes'][0]['login'], 'nome' => $onu['clientes'][0]['nome']];   // forma antiga, so o primeiro
        $onu['cto'] = null;
        foreach ($rows as $b) {
            if ($b['cto_node_id'] !== null) {
                $onu['cto'] = ['id' => (int) $b['cto_node_id'], 'nome' => (string) $b['cto_nome'], 'porta' => $b['porta_rotulo'] === null ? null : (string) $b['porta_rotulo']];
                break;
            }
        }
    }

    /** @return array<string,mixed> */
    private function limits(int $providerId, PdoNetStore $store): array
    {
        $t = NetPowerCollector::thresholds($store->providerSettings($providerId));

        return ['saturado_dbm' => $t['net.rx.sat_dbm'], 'atencao_dbm' => $t['net.rx.warn_dbm'], 'critico_dbm' => $t['net.rx.crit_dbm'], 'desvio_db' => $t['net.rx.deviation_db']];
    }

    private function outageReader(?bool &$logAvailable = null): NetOutageReader
    {
        $logs = new PdoNetOutageStore($this->pdo);
        $has = (int) $this->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'net_onu_outages'")->fetchColumn() === 1;
        $logAvailable = $has;

        return new NetOutageReader(new PdoNetStore($this->pdo), $has ? $logs : new PollOnlyOutageStore($logs), $this->clock);
    }

    /** @return list<array<string,mixed>> */
    private function onuRows(?int $deviceId, ?int $onuId = null, ?int $providerId = null): array
    {
        $sql = 'SELECT o.id, o.provider_id, o.device_id, o.slot, o.pon_index, o.onu_index, o.snmp_index, o.onu_identifier, o.first_seen_at,
                       s.oper_status, s.raw_status_code, s.status_since, s.stale,
                       p.onu_rx_dbm, p.onu_tx_dbm, p.onu_temp_c, p.measured_at, p.rx_band, p.baseline_rx_dbm, p.baseline_set_at, p.baseline_n
                  FROM net_onus o LEFT JOIN net_onu_state s ON s.onu_id = o.id LEFT JOIN net_onu_power p ON p.onu_id = o.id
                 WHERE o.removed_at IS NULL AND ' . ($onuId !== null ? 'o.id = ? AND o.provider_id = ?' : 'o.device_id = ?') . ' ORDER BY o.pon_index, o.onu_index, o.id';
        $st = $this->pdo->prepare($sql);
        $st->execute($onuId !== null ? [$onuId, $providerId] : [$deviceId]);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $r @param array<string,mixed> $limits @return array<string,mixed> */
    private function onuItem(array $r, array $limits, string $now): array
    {
        $mac12 = (string) $r['onu_identifier'];
        $status = in_array($r['oper_status'], ['online', 'offline'], true) ? $r['oper_status'] : 'unknown';
        $rtt = is_numeric($r['raw_status_code'] ?? null) ? (int) $r['raw_status_code'] : null;
        $dist = ($status === 'online' && $rtt !== null && $rtt > 0) ? (int) max(0, round(($rtt - self::DIST_OFFSET) * self::DIST_SLOPE_M)) : null;
        $rx = $r['onu_rx_dbm'] === null ? null : (float) $r['onu_rx_dbm'];

        return [
            'id' => (int) $r['id'],
            'pon_index' => (int) $r['pon_index'],
            'onu_index' => (int) $r['onu_index'],
            'mac' => strlen($mac12) === 12 ? implode(':', str_split($mac12, 2)) : $mac12,
            'status' => $status,
            'status_desde' => $r['status_since'],
            'desatualizado' => (bool) ($r['stale'] ?? false),
            'rx_dbm' => $rx,
            'rx_band' => $rx === null ? 'unknown' : (string) $r['rx_band'],
            'rx_medido_em' => $r['measured_at'],
            'tx_dbm' => $r['onu_tx_dbm'] === null ? null : (float) $r['onu_tx_dbm'],
            'temp_c' => $r['onu_temp_c'] === null ? null : (float) $r['onu_temp_c'],
            'distancia_m' => $dist,
            'distancia_origem' => $dist === null ? null : 'estimada pelo RTT',
            'cliente' => null,
            'cto' => null,
        ];
    }
}
