<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

use App\Infrastructure\Net\Snmp\SnmpTimeoutException;
use App\Infrastructure\Net\Snmp\SnmpTransport;
use App\Infrastructure\Net\Snmp\SnmpUnreachableException;
use App\Infrastructure\Net\Snmp\SnmpValue;
use App\Infrastructure\Net\Store\NetStore;
use RuntimeException;

/**
 * Ciclo de status (inventário + online/offline) de UM equipamento, guiado pelo perfil. Determinístico, somente leitura no equipamento.
 *
 * Ordem: identidade -> walk das colunas do perfil -> rechecagem de identidade -> só então grava.
 *  - sem resposta na identidade: ONUs ficam stale (nunca viram offline, sem eventos);
 *  - identidade diferente da referência: nada de ONU é gravado;
 *  - walk interrompido/incoerente: ciclo descartado (partial);
 *  - uptime menor que o anterior: reinício da OLT registrado no estado de coleta.
 * Primeiro ciclo bem-sucedido do equipamento = baseline (sem eventos 'new').
 */
final class NetStatusCollector
{
    private const MAX_RESCUE_PER_CYCLE = 20;

    /** @var callable(array, DeviceProfile): SnmpTransport */
    private $transportFactory;
    /** @var callable(): string */
    private $clock;

    private ?SnmpTransport $transport = null;
    private ?DeviceProfile $profile = null;
    private int $requests = 0;
    private int $consecutiveTimeouts = 0;
    private int $requestsAtLastCheck = 0;
    /** @var array<string,?string> */
    private array $startIdentity = [];

    public function __construct(private NetStore $store, callable $transportFactory, ?callable $clock = null)
    {
        $this->transportFactory = $transportFactory;
        $this->clock = $clock ?? static fn (): string => date('Y-m-d H:i:s');
    }

    /**
     * @return array<string,mixed> resumo do ciclo (sem segredos)
     */
    public function run(int $deviceId, bool $approveIdentity = false, bool $dryRun = false): array
    {
        $t0 = microtime(true);
        $now = ($this->clock)();
        $this->requests = 0;
        $this->consecutiveTimeouts = 0;
        $this->requestsAtLastCheck = 0;
        $this->startIdentity = [];
        $summary = ['device_id' => $deviceId, 'dry_run' => $dryRun, 'started_at' => $now, 'warnings' => []];

        $device = $this->store->findDevice($deviceId);
        if ($device === null) {
            return $summary + ['result' => 'error', 'error' => 'equipamento não encontrado', 'requests' => 0];
        }
        if ((int) ($device['enabled'] ?? 0) !== 1) {
            return $summary + ['result' => 'error', 'error' => 'equipamento desabilitado', 'requests' => 0];
        }
        $prevPoll = $this->store->pollState($deviceId) ?? [];
        $prevFailures = (int) ($prevPoll['consecutive_failures'] ?? 0);

        $fail = function (string $result, string $error, ?array $identity = null, bool $stale = false) use ($deviceId, $now, $t0, $dryRun, $prevFailures, $prevPoll, &$summary): array {
            $poll = [
                'last_attempt_at' => $now,
                'last_result' => $result,
                'consecutive_failures' => $prevFailures + 1,
                'requests_last_cycle' => $this->requests,
                'cycle_ms' => (int) round((microtime(true) - $t0) * 1000),
                'last_error' => mb_substr($error, 0, 500),
                'identity_seen_json' => $identity !== null ? json_encode($identity, JSON_UNESCAPED_SLASHES) : ($prevPoll['identity_seen_json'] ?? null),
            ];
            if ($identity !== null && isset($identity['uptime']) && $result !== 'identity_mismatch') {
                $poll['last_uptime_ticks'] = (int) $identity['uptime'];
            }
            $staleCount = 0;
            if (!$dryRun) {
                $staleCount = $stale ? $this->store->markDeviceStale($deviceId, $now, $poll) : 0;
                if (!$stale) {
                    $this->store->savePollState($deviceId, $poll);
                }
            }

            return $summary + ['result' => $result, 'error' => $error, 'requests' => $this->requests, 'cycle_ms' => $poll['cycle_ms'], 'stale_marked' => $staleCount];
        };

        try {
            $this->profile = DeviceProfile::fromJson((string) $device['profile_code'], (string) $device['profile_json']);
            $this->transport = ($this->transportFactory)($device, $this->profile);
        } catch (\Throwable $e) {
            return $fail('error', 'configuração: ' . $e->getMessage());
        }

        try {
            // 1) Identidade
            try {
                $identity = $this->readIdentity();
            } catch (AbortCycle $e) {
                return $fail('timeout', 'equipamento sem resposta na identidade: ' . $e->getMessage(), null, true);
            }
            $this->startIdentity = $identity;
            $summary['identity'] = $identity;

            $expectedJson = $device['expected_identity_json'] ?? null;
            $expected = is_string($expectedJson) && $expectedJson !== '' ? json_decode($expectedJson, true) : null;
            $toApprove = null;
            if (!is_array($expected)) {
                if (!$approveIdentity) {
                    return $fail('identity_mismatch', 'sem identidade de referência aprovada; rode com --approve-identity', $identity);
                }
                foreach ($this->profile->identityCompare() as $k) {
                    if (($identity[$k] ?? null) === null || $identity[$k] === '') {
                        return $fail('identity_mismatch', "identidade incompleta: campo {$k} vazio", $identity);
                    }
                }
                $toApprove = array_intersect_key($identity, array_flip($this->profile->identityCompare()));
                $summary['identity_approved'] = true;
            } else {
                $diff = $this->identityDiff($expected, $identity);
                if ($diff !== []) {
                    return $fail('identity_mismatch', 'identidade diferente da referência: ' . implode(', ', $diff), $identity);
                }
            }

            $restart = false;
            $prevUptime = isset($prevPoll['last_uptime_ticks']) ? (int) $prevPoll['last_uptime_ticks'] : null;
            if ($prevUptime !== null && isset($identity['uptime']) && (int) $identity['uptime'] < $prevUptime) {
                $restart = true;
                $summary['warnings'][] = "reinício da OLT detectado (uptime {$identity['uptime']} < anterior {$prevUptime})";
            }
            $summary['restart_detected'] = $restart;

            // 2) Walk completo das colunas do perfil, observações e conferência por GET das ONUs ausentes do walk
            try {
                $columns = [];
                foreach ($this->profile->statusWalk() as $metricName) {
                    $columns[$metricName] = $this->walkColumn((string) $this->profile->metric($metricName)['oid']);
                }
                $observed = $this->buildObservations($columns, $summary['warnings']);
                if (is_string($observed)) {
                    return $fail('partial', $observed, $identity);
                }
                $summary['rescued_by_get'] = $this->rescueMissing($device, $observed, $summary['warnings']);
                // 3) Rechecagem final de identidade
                $this->recheckIdentity();
            } catch (IdentityChanged $e) {
                return $fail('identity_mismatch', 'identidade mudou durante o ciclo: ' . $e->getMessage(), $identity);
            } catch (AbortCycle $e) {
                return $fail('partial', 'walk interrompido: ' . $e->getMessage(), $identity);
            }

            // 4) Plano de gravação
            $plan = $this->plan($device, $observed, $prevPoll, $now);
            $cycleMs = (int) round((microtime(true) - $t0) * 1000);
            $identitySeen = $identity + ['restart_detected' => $restart];
            if ($restart) {
                $identitySeen['previous_uptime'] = $prevUptime;
            }
            $plan['poll_state'] = [
                'last_attempt_at' => $now,
                'last_success_at' => $now,
                'last_result' => 'ok',
                'consecutive_failures' => 0,
                'identity_seen_json' => json_encode($identitySeen, JSON_UNESCAPED_SLASHES),
                'requests_last_cycle' => $this->requests,
                'cycle_ms' => $cycleMs,
                'last_uptime_ticks' => isset($identity['uptime']) ? (int) $identity['uptime'] : null,
                'last_error' => $restart ? 'reinício da OLT detectado neste ciclo' : null,
            ];
            $plan['expected_identity'] = $toApprove;

            if (!$dryRun) {
                $this->store->applyCycle($deviceId, $plan, $now);
            }

            return $summary + [
                'result' => 'ok',
                'requests' => $this->requests,
                'cycle_ms' => $cycleMs,
                'baseline' => $plan['baseline'],
                'status_validated' => $this->profile->hasStatus(),
                'onus_seen' => count($observed),
                'per_pon' => $plan['per_pon'],
                'events' => $plan['event_counts'],
                'inserted' => count($plan['insert']),
                'updated' => count($plan['update']),
            ];
        } finally {
            $this->transport?->close();
        }
    }

    /** @return array<string,?string> */
    private function readIdentity(): array
    {
        $out = [];
        foreach ($this->profile->identityOids() as $name => $oid) {
            $out[$name] = ValueDecoder::identityField($name, $this->request('get', $oid));
        }
        $this->requestsAtLastCheck = $this->requests;

        return $out;
    }

    private function recheckIdentity(): void
    {
        $now = $this->readIdentity();
        $diff = $this->identityDiff($this->startIdentity, $now);
        if (isset($now['sys_name'], $this->startIdentity['sys_name']) && $now['sys_name'] !== $this->startIdentity['sys_name']) {
            $diff[] = 'sys_name';
        }
        if (isset($now['uptime'], $this->startIdentity['uptime']) && (int) $now['uptime'] < (int) $this->startIdentity['uptime']) {
            $diff[] = 'uptime diminuiu (reinício durante o ciclo)';
        }
        if ($diff !== []) {
            throw new IdentityChanged(implode(', ', $diff));
        }
    }

    /** @return list<string> campos divergentes */
    private function identityDiff(array $expected, array $seen): array
    {
        $diff = [];
        foreach ($this->profile->identityCompare() as $k) {
            if (($expected[$k] ?? null) !== ($seen[$k] ?? null)) {
                $diff[] = $k;
            }
        }

        return $diff;
    }

    /** @return array<string,SnmpValue> sufixo do índice => valor */
    private function walkColumn(string $columnOid): array
    {
        $rows = [];
        $current = $columnOid;
        $prefix = $columnOid . '.';
        while (true) {
            $v = $this->request('getnext', $current);
            if ($v->isEnd() || !str_starts_with($v->oid, $prefix)) {
                break;
            }
            if (SnmpValue::compareOids($v->oid, $current) <= 0) {
                throw new AbortCycle('OID não crescente no walk (agente incoerente)');
            }
            $rows[substr($v->oid, strlen($prefix))] = $v;
            $current = $v->oid;
            $every = $this->profile->identityRecheckEvery();
            if ($every > 0 && $this->requests - $this->requestsAtLastCheck >= $every) {
                $this->recheckIdentity();
            }
        }

        return $rows;
    }

    private function request(string $op, string $oid): SnmpValue
    {
        while (true) {
            if ($this->requests >= $this->profile->maxRequestsPerCycle()) {
                throw new AbortCycle('teto de requisições por ciclo atingido (' . $this->profile->maxRequestsPerCycle() . ')');
            }
            $this->requests++;
            try {
                $v = $op === 'get' ? $this->transport->get($oid) : $this->transport->getNext($oid);
                $this->consecutiveTimeouts = 0;

                return $v;
            } catch (SnmpTimeoutException $e) {
                $this->consecutiveTimeouts++;
                if ($this->consecutiveTimeouts >= $this->profile->abortAfterConsecutiveTimeouts()) {
                    throw new AbortCycle("{$this->consecutiveTimeouts} timeouts seguidos");
                }
            } catch (SnmpUnreachableException $e) {
                throw new AbortCycle('rede devolveu unreachable/prohibited');
            }
        }
    }

    /**
     * @param array<string, array<string,SnmpValue>> $columns
     * @param list<string> $warnings
     * @return array<string, array>|string identificador => observação, ou motivo de descarte
     */
    private function buildObservations(array $columns, array &$warnings): array|string
    {
        $ids = $columns['onu_identifier'];
        $statuses = $columns['oper_status'] ?? null;
        if ($statuses !== null) {
            $a = array_keys($ids);
            $b = array_keys($statuses);
            sort($a);
            sort($b);
            if ($a !== $b) {
                return 'colunas de identificador e status com índices diferentes (' . count($a) . ' x ' . count($b) . ')';
            }
        }
        $type = $this->profile->identifierType();
        $out = [];
        $invalid = 0;
        foreach ($ids as $suffix => $value) {
            if ($this->profile->decodeIndex((string) $suffix) === null) {
                return "índice fora do formato do perfil: {$suffix}";
            }
            $identifier = ValueDecoder::identifier($type, $value);
            if ($identifier === null) {
                $invalid++;
                continue;
            }
            if (isset($out[$identifier])) {
                $warnings[] = 'identificador repetido em ' . $out[$identifier]['snmp_index'] . ' e ' . $suffix . ' (' . ValueDecoder::maskMac($identifier) . '); mantido o primeiro';
                continue;
            }
            $out[$identifier] = $this->observation((string) $suffix, $statuses[$suffix] ?? null, $warnings);
        }
        if ($invalid > 0) {
            $warnings[] = "{$invalid} linha(s) com identificador inválido/vazio ignorada(s)";
        }

        return $out;
    }

    /**
     * Sem status validado no perfil o estado é sempre 'unknown' (sem código bruto): o inventário vale, up/down não.
     *
     * @param list<string> $warnings
     * @return array<string,mixed>
     */
    private function observation(string $suffix, ?SnmpValue $statusValue, array &$warnings): array
    {
        $idx = $this->profile->decodeIndex($suffix) ?? [];
        $status = 'unknown';
        $raw = null;
        if ($this->profile->hasStatus() && $statusValue !== null) {
            [$status, $raw] = ValueDecoder::status($this->profile->metric('oper_status'), $statusValue);
            if ($status === 'unknown') {
                $warnings[] = "status desconhecido no índice {$suffix} (código bruto " . ($raw ?? 'nulo') . ')';
            }
        }

        return [
            'snmp_index' => $suffix,
            'slot' => $idx['slot'] ?? null,
            'pon_index' => $idx['pon'] ?? 0,
            'onu_index' => $idx['onu'] ?? 0,
            'oper_status' => $status,
            'raw_status_code' => $raw,
        ];
    }

    /**
     * O agente pode pular linhas no GETNEXT e devolver noSuchInstance de forma intermitente. Uma ONU conhecida que não veio
     * no walk é conferida por GET direto no índice já conhecido antes de contar como ausente (e, depois de 3 ciclos, removida).
     *
     * @param array<string,array> $observed
     * @param list<string> $warnings
     */
    private function rescueMissing(array $device, array &$observed, array &$warnings): int
    {
        $idOid = (string) $this->profile->metric('onu_identifier')['oid'];
        $stOid = $this->profile->hasStatus() ? (string) $this->profile->metric('oper_status')['oid'] : null;
        $type = $this->profile->identifierType();
        $tried = 0;
        $rescued = 0;
        foreach ($this->store->deviceOnus((int) $device['id']) as $row) {
            if ($row['removed_at'] !== null || isset($observed[$row['onu_identifier']])) {
                continue;
            }
            if ($tried >= self::MAX_RESCUE_PER_CYCLE) {
                $warnings[] = 'mais de ' . self::MAX_RESCUE_PER_CYCLE . ' ONUs ausentes do walk: conferência por GET limitada';
                break;
            }
            $tried++;
            $suffix = (string) $row['snmp_index'];
            if (ValueDecoder::identifier($type, $this->request('get', $idOid . '.' . $suffix)) !== $row['onu_identifier']) {
                continue;
            }
            $statusValue = $stOid !== null ? $this->request('get', $stOid . '.' . $suffix) : null;
            $observed[$row['onu_identifier']] = $this->observation($suffix, $statusValue, $warnings);
            $rescued++;
        }
        if ($rescued > 0) {
            $warnings[] = "{$rescued} ONU(s) ausente(s) do walk confirmada(s) por GET direto";
        }

        return $rescued;
    }

    /**
     * @param array<string,array> $observed
     * @return array<string,mixed>
     */
    private function plan(array $device, array $observed, array $prevPoll, string $now): array
    {
        $deviceId = (int) $device['id'];
        $providerId = (int) $device['provider_id'];
        $type = $this->profile->identifierType();
        $known = [];
        foreach ($this->store->deviceOnus($deviceId) as $row) {
            $known[$row['onu_identifier']] = $row;
        }
        $baseline = empty($prevPoll['last_success_at']) && $known === [];
        $missingIds = array_values(array_diff(array_keys($observed), array_keys($known)));
        $elsewhere = $missingIds === [] ? [] : $this->store->onusByIdentifiers($providerId, $type, $missingIds);

        $plan = ['insert' => [], 'update' => [], 'baseline' => $baseline, 'per_pon' => [], 'event_counts' => ['new' => 0, 'up' => 0, 'down' => 0, 'moved' => 0, 'removed' => 0]];
        $count = function (string $t) use (&$plan): void {
            $plan['event_counts'][$t]++;
        };

        foreach ($observed as $identifier => $o) {
            $pon = (int) $o['pon_index'];
            $plan['per_pon'][$pon] ??= ['total' => 0, 'online' => 0, 'offline' => 0, 'unknown' => 0];
            $plan['per_pon'][$pon]['total']++;
            $plan['per_pon'][$pon][$o['oper_status']]++;

            $where = ['device_id' => $deviceId, 'snmp_index' => $o['snmp_index'], 'pon' => $pon, 'onu' => (int) $o['onu_index']];
            $existing = $known[$identifier] ?? $elsewhere[$identifier] ?? null;
            if ($existing === null) {
                $events = $baseline ? [] : [['new', $where + ['status' => $o['oper_status']]]];
                if (!$baseline) {
                    $count('new');
                }
                $plan['insert'][] = [
                    'onu' => [
                        'provider_id' => $providerId, 'device_id' => $deviceId, 'slot' => $o['slot'], 'pon_index' => $pon,
                        'onu_index' => (int) $o['onu_index'], 'snmp_index' => $o['snmp_index'], 'onu_identifier' => $identifier,
                        'identifier_type' => $type, 'first_seen_at' => $now, 'last_seen_at' => $now, 'removed_at' => null,
                    ],
                    'state' => ['oper_status' => $o['oper_status'], 'raw_status_code' => $o['raw_status_code'], 'status_since' => $now, 'stale' => 0, 'missing_cycles' => 0],
                    'events' => $events,
                ];
                continue;
            }

            $events = [];
            if ((int) $existing['device_id'] !== $deviceId || (string) $existing['snmp_index'] !== $o['snmp_index']) {
                $events[] = ['moved', ['from' => ['device_id' => (int) $existing['device_id'], 'snmp_index' => (string) $existing['snmp_index']], 'to' => $where]];
                $count('moved');
            }
            if ($existing['removed_at'] !== null) {
                $events[] = ['new', $where + ['status' => $o['oper_status'], 'reappeared' => true]];
                $count('new');
            }
            $prev = (string) ($existing['oper_status'] ?? 'unknown');
            $changed = $prev !== $o['oper_status'];
            if ($changed && $prev === 'online' && $o['oper_status'] === 'offline') {
                $events[] = ['down', ['raw_status_code' => $o['raw_status_code'], 'after_stale' => (int) ($existing['stale'] ?? 0) === 1]];
                $count('down');
            } elseif ($changed && $prev === 'offline' && $o['oper_status'] === 'online') {
                $events[] = ['up', ['raw_status_code' => $o['raw_status_code'], 'after_stale' => (int) ($existing['stale'] ?? 0) === 1]];
                $count('up');
            }
            $plan['update'][(int) $existing['id']] = [
                'onu' => [
                    'device_id' => $deviceId, 'slot' => $o['slot'], 'pon_index' => $pon, 'onu_index' => (int) $o['onu_index'],
                    'snmp_index' => $o['snmp_index'], 'last_seen_at' => $now, 'removed_at' => null,
                ],
                'state' => [
                    'oper_status' => $o['oper_status'], 'raw_status_code' => $o['raw_status_code'],
                    'status_since' => $changed || empty($existing['status_since']) ? $now : $existing['status_since'],
                    'stale' => 0, 'missing_cycles' => 0,
                ],
                'events' => $events,
            ];
        }

        $limit = $this->profile->removalAfterMissingCycles();
        foreach ($known as $identifier => $row) {
            if (isset($observed[$identifier]) || $row['removed_at'] !== null) {
                continue;
            }
            $missing = (int) ($row['missing_cycles'] ?? 0) + 1;
            $removed = $missing >= $limit;
            $plan['update'][(int) $row['id']] = [
                'onu' => $removed ? ['removed_at' => $now] : [],
                'state' => ['missing_cycles' => $missing, 'stale' => 0],
                'events' => $removed ? [['removed', ['last_snmp_index' => (string) $row['snmp_index'], 'missing_cycles' => $missing]]] : [],
            ];
            if ($removed) {
                $count('removed');
            }
        }
        ksort($plan['per_pon']);

        return $plan;
    }
}
