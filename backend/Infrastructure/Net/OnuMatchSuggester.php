<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

use PDO;

/**
 * Sugere vinculos ONU<->cliente pela regra do MAC (MacMatcher: MAC do PPPoE ~ MAC da ONU, variacao no ultimo octeto).
 * So SUGERE: nada e gravado aqui; o gestor confirma ou recusa. Sugestoes recusadas e ONUs/logins ja vinculados ficam de fora.
 */
final class OnuMatchSuggester
{
    public function __construct(private PDO $pdo, private NetBindingRepository $bindings)
    {
    }

    /**
     * Grau de confianca de um candidato: alta (MAC igual ou diferenca de 1 no ultimo octeto, candidato unico),
     * media (diferenca ate 16, candidato unico), baixa (qualquer outro caso, inclusive ambiguidade).
     */
    public static function confidence(int $distance, bool $unique, bool $loginInManyOnus): string
    {
        if (!$unique || $loginInManyOnus) {
            return 'baixa';
        }
        $d = abs($distance);
        if ($d <= 1) {
            return 'alta';
        }

        return $d <= 16 ? 'media' : 'baixa';
    }

    /**
     * @param array<string,string> $callers login => callingstationid cru (do radacct)
     * @param array<string,string> $names login em minusculas => nome
     * @return array{sugestoes:list<array<string,mixed>>,sem_candidato:int,sem_vinculo:int,logins_com_sessao:int,caller_invalido:int}
     */
    public function suggest(int $providerId, array $callers, array $names): array
    {
        $bound = $this->bindings->boundSets($providerId);
        $st = $this->pdo->prepare(
            "SELECT o.id, o.onu_identifier, o.pon_index, o.onu_index, d.id AS device_id, n.name AS olt
               FROM net_onus o JOIN net_devices d ON d.id = o.device_id JOIN map_nodes n ON n.id = d.map_node_id
              WHERE o.provider_id = ? AND d.provider_id = ? AND o.removed_at IS NULL AND o.identifier_type = 'epon_mac'
              ORDER BY n.name, o.pon_index, o.onu_index"
        );
        $st->execute([$providerId, $providerId]);
        $onus = [];
        $meta = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int) $r['id'];
            if (isset($bound['onus'][$id])) {
                continue;
            }
            $onus[] = ['id' => $id, 'mac' => (string) $r['onu_identifier'], 'pon' => (int) $r['pon_index'], 'onu' => (int) $r['onu_index']];
            $meta[$id] = ['olt' => (string) $r['olt'], 'device_id' => (int) $r['device_id']];
        }

        $macs = [];
        $invalid = 0;
        foreach ($callers as $login => $raw) {
            $mac = ValueDecoder::mac($raw);
            if ($mac === null) {
                $invalid++;
                continue;
            }
            // Login que ja tem ONU vinculada nao entra como candidato.
            if (isset($bound['logins_onu'][strtolower((string) $login)])) {
                continue;
            }
            $macs[(string) $login] = $mac;
        }
        $res = MacMatcher::match($onus, $macs);
        $rejected = $this->bindings->rejections($providerId);

        $hits = [];
        foreach ($res['items'] as $it) {
            foreach ($it['candidates'] as $c) {
                $hits[$c['login']] = ($hits[$c['login']] ?? 0) + 1;
            }
        }
        $out = [];
        $semCandidato = 0;
        foreach ($res['items'] as $it) {
            $cands = [];
            $unique = count($it['candidates']) === 1;
            foreach ($it['candidates'] as $c) {
                if (isset($rejected[$it['onu']['id'] . '|' . strtolower($c['login'])])) {
                    continue;
                }
                $cands[] = [
                    'login' => $c['login'],
                    'nome' => $names[strtolower($c['login'])] ?? null,
                    'distancia' => $c['distance'],
                    'confianca' => self::confidence($c['distance'], $unique, ($hits[$c['login']] ?? 0) > 1),
                    'vinculado' => isset($bound['logins'][strtolower($c['login'])]),
                ];
            }
            if ($cands === []) {
                $semCandidato++;
                continue;
            }
            $rank = ['alta' => 0, 'media' => 1, 'baixa' => 2];
            usort($cands, static fn (array $a, array $b): int => [$rank[$a['confianca']], abs($a['distancia'])] <=> [$rank[$b['confianca']], abs($b['distancia'])]);
            $out[] = [
                'onu' => ['id' => $it['onu']['id'], 'mac' => $it['onu']['mac'], 'pon' => $it['onu']['pon'], 'onu_index' => $it['onu']['onu'], 'olt' => $meta[$it['onu']['id']]['olt']],
                'candidatos' => $cands,
            ];
        }

        return ['sugestoes' => $out, 'sem_candidato' => $semCandidato, 'sem_vinculo' => count($onus), 'logins_com_sessao' => count($macs), 'caller_invalido' => $invalid];
    }
}
