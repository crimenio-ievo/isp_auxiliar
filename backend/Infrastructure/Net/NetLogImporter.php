<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

use App\Infrastructure\Net\Store\NetOutageStore;
use App\Infrastructure\Net\Store\NetStore;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Importa o syslog da OLT para net_onu_outages (source 'log'). Simulação por padrão; idempotente
 * (event_key = sha256 MAC|início no relógio da OLT). Não acessa a OLT. Só imprime MAC mascarado.
 */
final class NetLogImporter
{
    /** @var callable():string */
    private $clock;

    public function __construct(private NetStore $net, private NetOutageStore $outages, private OltLogParser $parser, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): string => date('Y-m-d H:i:s');
    }

    /** @return array<string,mixed> */
    public function run(int $deviceId, string $content, string $oltTz, string $appTz, bool $apply): array
    {
        $device = $this->net->findDevice($deviceId);
        if ($device === null) {
            throw new \InvalidArgumentException('Equipamento inexistente.');
        }
        $providerId = (int) $device['provider_id'];
        $from = new DateTimeZone($oltTz);
        $to = new DateTimeZone($appTz);
        $conv = static function (?string $s) use ($from, $to): ?string {
            if ($s === null) {
                return null;
            }
            $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $s, $from);

            return $d === false ? null : $d->setTimezone($to)->format('Y-m-d H:i:s');
        };

        $parsed = $this->parser->parse($content);
        $outages = $parsed['outages'];
        $macs = array_values(array_unique(array_map(static fn (array $o): string => str_replace(':', '', $o['mac']), $outages)));
        $onus = $macs === [] ? [] : $this->net->onusByIdentifiers($providerId, 'epon_mac', $macs);
        $existing = $this->outages->existing($deviceId, 'log', array_map(static fn (array $o): string => $o['event_key'], $outages));
        $now = ($this->clock)();

        $inserts = [];
        $updates = [];
        $unchanged = 0;
        $kept = 0;
        $unmatched = [];
        $byCategory = ['queda' => 0, 'manutencao' => 0, 'aberta' => 0];
        $examples = [];
        foreach ($outages as $o) {
            $mac12 = str_replace(':', '', $o['mac']);
            $onuId = isset($onus[$mac12]) ? (int) $onus[$mac12]['id'] : null;
            if ($onuId === null) {
                $unmatched[$mac12] = true;
            }
            $row = [
                'provider_id' => $providerId, 'device_id' => $deviceId, 'onu_id' => $onuId, 'onu_identifier' => $mac12, 'source' => 'log',
                'event_key' => $o['event_key'], 'started_at' => $conv($o['started_at']), 'ended_at' => $conv($o['ended_at']),
                'duration_s' => $o['duration_s'], 'cause' => $o['cause'], 'category' => $o['counts_as_outage'] ? 'queda' : 'manutencao',
                'maintenance_reason' => $o['maintenance'], 'dying_gasp_at' => $conv($o['dying_gasp_at']), 'returned_via' => $o['returned_via'],
                'imported_at' => $now, 'updated_at' => $now,
            ];
            $byCategory[$row['category']]++;
            if ($o['open'] && $o['counts_as_outage']) {
                $byCategory['aberta']++;
            }
            $old = $existing[$o['event_key']] ?? null;
            if ($old === null) {
                $inserts[] = $row;
                if (count($examples) < 5 && $o['counts_as_outage']) {
                    $examples[] = ['mac' => ValueDecoder::maskMac($mac12), 'inicio' => $row['started_at'], 'fim' => $row['ended_at'], 'duracao_s' => $row['duration_s'], 'motivo' => $row['cause']];
                }
            } elseif ($old['ended_at'] === null && $row['ended_at'] !== null) {
                $updates[] = $row; // era em aberto e agora há retorno
            } elseif ($old['ended_at'] !== null && $row['ended_at'] === null) {
                $kept++; // arquivo mais antigo/truncado: nunca reabre uma queda já fechada
            } else {
                $unchanged++;
            }
        }
        $summary = [
            'modo' => $apply ? 'apply' : 'simulacao',
            'device_id' => $deviceId,
            'fuso_olt' => $oltTz, 'fuso_destino' => $appTz,
            'arquivo' => $parsed['summary'],
            'eventos_linkdown' => count($outages),
            'quedas' => $byCategory['queda'], 'manutencao_boot_ou_troca_pon' => $byCategory['manutencao'], 'quedas_em_aberto' => $byCategory['aberta'],
            'novas' => count($inserts), 'atualizadas_com_retorno' => count($updates), 'inalteradas' => $unchanged, 'mantidas_fechadas' => $kept,
            'macs_sem_onu_cadastrada' => count($unmatched),
            'exemplos' => $examples,
        ];
        if ($apply && ($inserts !== [] || $updates !== [])) {
            $this->outages->save($inserts, $updates, [
                'provider_id' => $providerId, 'device_id' => $deviceId, 'created_at' => $now,
                'context' => ['novas' => count($inserts), 'atualizadas' => count($updates), 'primeiro_evento' => $parsed['summary']['primeiro_evento'], 'ultimo_evento' => $parsed['summary']['ultimo_evento'], 'fuso_olt' => $oltTz, 'conteudo_sha256' => hash('sha256', $content)],
            ]);
        }

        return $summary;
    }
}
