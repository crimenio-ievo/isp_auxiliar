<?php

declare(strict_types=1);

namespace App\Infrastructure\Net\Snmp;

/**
 * Transporte falso para testes: uma "MIB" em memória (oid => [tipo, valor]) com GET/GETNEXT
 * em ordem numérica de OID, e um gancho opcional para simular timeout/unreachable ou alterar dados
 * no meio do ciclo. Nunca acessa rede.
 */
final class FakeSnmpTransport implements SnmpTransport
{
    /** @var array<string, array{0:string,1:string}> */
    private array $data = [];
    /** @var list<string> */
    private array $sorted = [];
    /** @var array<string,true> OIDs que existem (GET) mas o GETNEXT pula, como o agente real faz com algumas linhas */
    private array $hidden = [];
    public int $requests = 0;
    /** @var list<string> */
    public array $operations = [];
    /** @var (callable(int,string,string,self):?string)|null */
    private $hook;

    /**
     * @param array<string, array{0:string,1:string}> $data
     * @param (callable(int,string,string,self):?string)|null $hook devolve 'timeout', 'unreachable' ou null
     */
    public function __construct(array $data, ?callable $hook = null)
    {
        foreach ($data as $oid => $v) {
            $this->data[ltrim((string) $oid, '.')] = $v;
        }
        $this->hook = $hook;
        $this->resort();
    }

    public function set(string $oid, string $type, string $value): void
    {
        $this->data[ltrim($oid, '.')] = [$type, $value];
        $this->resort();
    }

    public function hideFromWalk(string $oid): void
    {
        $this->hidden[ltrim($oid, '.')] = true;
    }

    public function remove(string $oid): void
    {
        unset($this->data[ltrim($oid, '.')]);
        $this->resort();
    }

    public function get(string $oid): SnmpValue
    {
        $oid = ltrim($oid, '.');
        $this->before('get', $oid);
        if (!isset($this->data[$oid])) {
            return new SnmpValue($oid, 'NOSUCH', '');
        }

        return new SnmpValue($oid, $this->data[$oid][0], $this->data[$oid][1]);
    }

    public function getNext(string $oid): SnmpValue
    {
        $oid = ltrim($oid, '.');
        $this->before('getnext', $oid);
        foreach ($this->sorted as $candidate) {
            if (SnmpValue::compareOids($candidate, $oid) > 0 && !isset($this->hidden[$candidate])) {
                return new SnmpValue($candidate, $this->data[$candidate][0], $this->data[$candidate][1]);
            }
        }

        return new SnmpValue($oid, 'END', '');
    }

    public function close(): void
    {
    }

    private function before(string $op, string $oid): void
    {
        $this->requests++;
        $this->operations[] = $op;
        if ($this->hook !== null) {
            $r = ($this->hook)($this->requests, $op, $oid, $this);
            if ($r === 'timeout') {
                throw new SnmpTimeoutException('timeout simulado');
            }
            if ($r === 'unreachable') {
                throw new SnmpUnreachableException('unreachable simulado');
            }
        }
    }

    private function resort(): void
    {
        $this->sorted = array_keys($this->data);
        usort($this->sorted, [SnmpValue::class, 'compareOids']);
    }
}
