<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

/**
 * Compara MAC de ONU (EPON) com o MAC de origem do PPPoE (caller-id) de cada login. Puro, sem I/O.
 * Regras: igual; ou igual nos 5 primeiros octetos com diferença no último octeto (distância = caller - onu).
 * Só produz relatório: não grava vínculo.
 */
final class MacMatcher
{
    /**
     * @param list<array{id:int,mac:string,pon:int,onu:int}> $onus MACs normalizados (12 hex)
     * @param array<string,string> $callers login => MAC normalizado
     * @return array<string,mixed>
     */
    public static function match(array $onus, array $callers, int $maxDistance = 255): array
    {
        $byPrefix = [];
        foreach ($callers as $login => $mac) {
            $byPrefix[substr($mac, 0, 10)][] = [(string) $login, $mac];
        }
        $result = ['unique' => 0, 'ambiguous' => 0, 'none' => 0, 'exact' => 0, 'near' => 0, 'distances' => [], 'items' => [], 'login_conflicts' => 0];
        $loginHits = [];
        foreach ($onus as $o) {
            $cands = [];
            foreach ($byPrefix[substr($o['mac'], 0, 10)] ?? [] as [$login, $mac]) {
                $d = hexdec(substr($mac, 10, 2)) - hexdec(substr($o['mac'], 10, 2));
                if (abs($d) > $maxDistance) {
                    continue;
                }
                $cands[] = ['login' => $login, 'caller' => $mac, 'distance' => $d];
            }
            $kind = count($cands) === 0 ? 'none' : (count($cands) === 1 ? 'unique' : 'ambiguous');
            $result[$kind]++;
            foreach ($cands as $c) {
                $c['distance'] === 0 ? $result['exact']++ : $result['near']++;
                $result['distances'][$c['distance']] = ($result['distances'][$c['distance']] ?? 0) + 1;
                $loginHits[$c['login']] = ($loginHits[$c['login']] ?? 0) + 1;
            }
            $result['items'][] = ['onu' => $o, 'kind' => $kind, 'candidates' => $cands];
        }
        ksort($result['distances']);
        $result['login_conflicts'] = count(array_filter($loginHits, static fn (int $n): bool => $n > 1));

        return $result;
    }

    public static function maskLogin(string $login): string
    {
        return mb_substr($login, 0, 3) . '***';
    }
}
