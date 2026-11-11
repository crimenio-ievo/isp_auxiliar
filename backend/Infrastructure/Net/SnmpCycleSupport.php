<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

use App\Infrastructure\Net\Snmp\SnmpTimeoutException;
use App\Infrastructure\Net\Snmp\SnmpTransport;
use App\Infrastructure\Net\Snmp\SnmpUnreachableException;
use App\Infrastructure\Net\Snmp\SnmpValue;

/**
 * Pedidos SNMP com contagem, teto por ciclo, aborto após timeouts seguidos e conferência de identidade.
 * Compartilhado pelos coletores de status e de potência (mesmas regras de segurança na OLT).
 */
trait SnmpCycleSupport
{
    private ?SnmpTransport $transport = null;
    private ?DeviceProfile $profile = null;
    private int $requests = 0;
    private int $consecutiveTimeouts = 0;
    private int $requestsAtLastCheck = 0;
    /** @var array<string,?string> */
    private array $startIdentity = [];

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
     * GET de um índice; noSuchInstance pode ser intermitente no agente, então ausência = duas leituras sem valor.
     */
    private function getRow(string $oid): ?SnmpValue
    {
        $this->maybeRecheckIdentity();
        for ($try = 0; $try < 2; $try++) {
            $v = $this->request('get', $oid);
            if (!$v->isNoSuch() && !$v->isEnd()) {
                return $v;
            }
        }

        return null;
    }
    private function maybeRecheckIdentity(): void
    {
        $every = $this->profile->identityRecheckEvery();
        if ($every > 0 && $this->requests - $this->requestsAtLastCheck >= $every) {
            $this->recheckIdentity();
        }
    }
}
