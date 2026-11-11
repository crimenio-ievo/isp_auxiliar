<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

use InvalidArgumentException;

/**
 * Perfil de equipamento (net_device_profiles.profile_json) validado.
 * Todas as OIDs, escalas, mapas de código e o ritmo vêm daqui: o coletor não conhece fabricante nem modelo.
 */
final class DeviceProfile
{
    private const IDENTITY_KEYS = ['sys_object_id', 'self_ip', 'engine_id', 'uptime'];
    private const DECODERS = ['split_dots'];
    private const TYPES = ['hex12_string', 'int_enum', 'string', 'int'];

    /** @param array<string,mixed> $data */
    private function __construct(public readonly string $code, private array $data)
    {
    }

    public static function fromJson(string $code, string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new InvalidArgumentException("Perfil {$code}: JSON inválido.");
        }
        $p = new self($code, $data);
        $p->validate();

        return $p;
    }

    /** @return array<string,string> nome lógico => OID */
    public function identityOids(): array
    {
        return $this->data['identity']['oids'];
    }

    /** @return list<string> campos de identidade que precisam bater com a referência */
    public function identityCompare(): array
    {
        return array_values($this->data['identity']['compare']);
    }

    public function identityRecheckEvery(): int
    {
        return max(0, (int) ($this->data['identity']['recheck_every_requests'] ?? 0));
    }

    public function intervalMs(): int
    {
        return max(300, (int) ($this->data['pacing']['interval_ms'] ?? 500));
    }

    public function timeoutMs(): int
    {
        return max(500, (int) ($this->data['pacing']['timeout_ms'] ?? 3000));
    }

    public function abortAfterConsecutiveTimeouts(): int
    {
        return max(1, (int) ($this->data['pacing']['abort_after_consecutive_timeouts'] ?? 2));
    }

    public function maxRequestsPerCycle(): int
    {
        return max(10, (int) ($this->data['pacing']['max_requests_per_cycle'] ?? 300));
    }

    /** @return list<string> */
    public function indexParts(): array
    {
        return array_values($this->data['index']['parts']);
    }

    /** @return list<string> métricas lógicas lidas no ciclo de status */
    public function statusWalk(): array
    {
        return array_values($this->data['status_walk']);
    }

    /** @return array<string,mixed> */
    public function metric(string $name): array
    {
        if (!isset($this->data['metrics'][$name]) || !is_array($this->data['metrics'][$name])) {
            throw new InvalidArgumentException("Perfil {$this->code}: métrica '{$name}' ausente.");
        }

        return $this->data['metrics'][$name];
    }

    /** O perfil só tem status quando oper_status foi validado e entrou na status_walk (com mapa de códigos). */
    public function hasStatus(): bool
    {
        return in_array('oper_status', $this->data['status_walk'], true);
    }

    public function identifierType(): string
    {
        return (string) $this->metric('onu_identifier')['identifier_type'];
    }

    public function removalAfterMissingCycles(): int
    {
        return max(1, (int) ($this->data['removal_after_missing_cycles'] ?? 3));
    }

    /**
     * Decodifica o sufixo de índice SNMP nas partes declaradas (ex.: "1.2.17" => slot 1, pon 2, onu 17).
     *
     * @return array<string,int>|null null quando o índice não tem o formato esperado
     */
    public function decodeIndex(string $suffix): ?array
    {
        $raw = explode('.', $suffix);
        $parts = $this->indexParts();
        if (count($raw) !== count($parts)) {
            return null;
        }
        $out = [];
        foreach ($parts as $i => $name) {
            if (!ctype_digit($raw[$i])) {
                return null;
            }
            $out[$name] = (int) $raw[$i];
        }

        return $out;
    }

    private function validate(): void
    {
        $d = $this->data;
        $fail = fn (string $m) => throw new InvalidArgumentException("Perfil {$this->code}: {$m}");
        foreach (self::IDENTITY_KEYS as $k) {
            if (!self::isOid($d['identity']['oids'][$k] ?? null)) {
                $fail("identity.oids.{$k} ausente ou inválida.");
            }
        }
        $compare = $d['identity']['compare'] ?? null;
        if (!is_array($compare) || $compare === []) {
            $fail('identity.compare vazio.');
        }
        foreach ($compare as $k) {
            if (!isset($d['identity']['oids'][$k])) {
                $fail("identity.compare usa '{$k}' sem OID.");
            }
        }
        if (!in_array($d['index']['decoder'] ?? null, self::DECODERS, true) || !is_array($d['index']['parts'] ?? null)) {
            $fail('index.decoder/parts inválidos.');
        }
        foreach (['pon', 'onu'] as $required) {
            if (!in_array($required, $d['index']['parts'], true)) {
                $fail("index.parts precisa conter '{$required}'.");
            }
        }
        $walk = $d['status_walk'] ?? null;
        if (!is_array($walk) || !in_array('onu_identifier', $walk, true)) {
            $fail('status_walk precisa de onu_identifier.');
        }
        foreach ($walk as $m) {
            $metric = $d['metrics'][$m] ?? null;
            if (!is_array($metric) || !self::isOid($metric['oid'] ?? null) || !in_array($metric['type'] ?? null, self::TYPES, true)) {
                $fail("métrica '{$m}' sem OID ou tipo válido.");
            }
        }
        if (in_array('oper_status', $walk, true)) {
            // Status só entra no ciclo quando validado: precisa de mapa de códigos explícito.
            $map = $d['metrics']['oper_status']['map'] ?? null;
            if (!is_array($map) || $map === [] || array_diff(array_values($map), ['online', 'offline']) !== []) {
                $fail('oper_status sem mapa de códigos (somente online/offline): tire-o da status_walk enquanto não for validado.');
            }
        }
        if (!in_array($d['metrics']['onu_identifier']['identifier_type'] ?? null, ['epon_mac', 'gpon_sn'], true)) {
            $fail('onu_identifier.identifier_type inválido.');
        }
        $ops = $d['pacing']['operations'] ?? ['get', 'getnext'];
        if (array_diff($ops, ['get', 'getnext']) !== []) {
            $fail('pacing.operations só pode conter get e getnext.');
        }
    }

    private static function isOid(mixed $v): bool
    {
        return is_string($v) && preg_match('/^[0-9]+(\.[0-9]+)+$/', $v) === 1;
    }
}
