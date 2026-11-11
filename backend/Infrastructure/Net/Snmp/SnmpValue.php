<?php

declare(strict_types=1);

namespace App\Infrastructure\Net\Snmp;

/**
 * Uma variável SNMP devolvida por GET/GETNEXT. OID numérico sem ponto inicial.
 * type: INTEGER, STRING, Hex-STRING, OID, IpAddress, Timeticks, Counter32, Gauge32...
 * ou os marcadores END (fim da MIB) e NOSUCH (objeto/instância inexistente).
 */
final class SnmpValue
{
    public function __construct(
        public readonly string $oid,
        public readonly string $type,
        public readonly string $value
    ) {
    }

    public function isEnd(): bool
    {
        return $this->type === 'END';
    }

    public function isNoSuch(): bool
    {
        return $this->type === 'NOSUCH';
    }

    /** Ordem lexicográfica numérica de OIDs (a ordem do GETNEXT). */
    public static function compareOids(string $a, string $b): int
    {
        $pa = array_map('intval', explode('.', ltrim($a, '.')));
        $pb = array_map('intval', explode('.', ltrim($b, '.')));
        $n = min(count($pa), count($pb));
        for ($i = 0; $i < $n; $i++) {
            if ($pa[$i] !== $pb[$i]) {
                return $pa[$i] <=> $pb[$i];
            }
        }

        return count($pa) <=> count($pb);
    }
}
