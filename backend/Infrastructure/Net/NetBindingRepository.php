<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

use PDO;

/**
 * Vinculos cliente (login do MkAuth) <-> CTO/porta e ONU (client_network_bindings). Uma linha por cliente ativo.
 * Migration 048: VARIOS clientes ativos podem estar na mesma ONU (ONT com varias portas LAN, cada cliente disca no proprio
 * roteador). A porta da CTO e ocupacao FISICA DA ONU: todas as linhas ativas da mesma ONU repetem a mesma CTO/porta, e uma
 * porta so aceita linhas ativas de UMA unica ONU (ou de um unico cliente sem ONU). Essa regra e da aplicacao (o indice
 * cnb_porta_ativa deixou de ser unico), aplicada em transacao com a porta travada. O login ativo segue unico por provedor.
 * Nunca escreve no MkAuth.
 */
final class NetBindingRepository
{
    public const MODOS = ['pppoe_na_onu', 'atras_da_onu'];

    private ?bool $available = null;

    public function __construct(private PDO $pdo)
    {
    }

    public function available(): bool
    {
        if ($this->available === null) {
            try {
                $this->pdo->query('SELECT id FROM client_network_bindings LIMIT 0');
                $this->available = true;
            } catch (\Throwable) {
                $this->available = false;
            }
        }

        return $this->available;
    }

    private const SELECT = 'SELECT b.*, n.name AS cto_nome, p.label AS porta_rotulo, p.port_num AS porta_num,
                                   o.pon_index, o.onu_index, o.onu_identifier, o.removed_at AS onu_removida
                              FROM client_network_bindings b
                              LEFT JOIN map_nodes n ON n.id = b.cto_node_id
                              LEFT JOIN map_ports p ON p.id = b.cto_port_id
                              LEFT JOIN net_onus o ON o.id = b.onu_id';

    /** @return array<string,mixed>|null */
    public function active(int $providerId, string $login): ?array
    {
        return $this->one(' WHERE b.provider_id = ? AND b.ativo = 1 AND b.mkauth_login = ?', [$providerId, $login]);
    }

    /** Primeiro vinculo ativo da porta (compatibilidade). @return array<string,mixed>|null */
    public function activeByPort(int $providerId, int $portId): ?array
    {
        return $this->activeListByPort($providerId, $portId)[0] ?? null;
    }

    /** Primeiro vinculo ativo da ONU (compatibilidade). Use activeListByOnu para todos. @return array<string,mixed>|null */
    public function activeByOnu(int $providerId, int $onuId): ?array
    {
        return $this->activeListByOnu($providerId, $onuId)[0] ?? null;
    }

    /** Todos os vinculos ativos da ONU, na ordem em que foram criados. @return list<array<string,mixed>> */
    public function activeListByOnu(int $providerId, int $onuId): array
    {
        $st = $this->pdo->prepare(self::SELECT . ' WHERE b.provider_id = ? AND b.ativo = 1 AND b.onu_id = ? ORDER BY b.id');
        $st->execute([$providerId, $onuId]);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Todos os vinculos ativos que usam a porta (varios so se forem da mesma ONU). @return list<array<string,mixed>> */
    public function activeListByPort(int $providerId, int $portId): array
    {
        $st = $this->pdo->prepare(self::SELECT . ' WHERE b.provider_id = ? AND b.ativo = 1 AND b.cto_port_id = ? ORDER BY b.id');
        $st->execute([$providerId, $portId]);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function find(int $providerId, int $id): ?array
    {
        return $this->one(' WHERE b.provider_id = ? AND b.id = ?', [$providerId, $id]);
    }

    /** @return list<array<string,mixed>> */
    public function forCto(int $providerId, int $ctoId): array
    {
        $st = $this->pdo->prepare(self::SELECT . ' WHERE b.provider_id = ? AND b.ativo = 1 AND b.cto_node_id = ? ORDER BY p.port_num, b.id');
        $st->execute([$providerId, $ctoId]);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Vinculos ativos das ONUs informadas: LISTA por onu_id (varios clientes por ONU), na ordem de criacao.
     *
     * @param list<int> $onuIds
     * @return array<int,list<array<string,mixed>>>
     */
    public function forOnus(int $providerId, array $onuIds): array
    {
        if ($onuIds === [] || !$this->available()) {
            return [];
        }
        $out = [];
        foreach (array_chunk($onuIds, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $st = $this->pdo->prepare(self::SELECT . " WHERE b.provider_id = ? AND b.ativo = 1 AND b.onu_id IN ({$marks}) ORDER BY b.id");
            $st->execute(array_merge([$providerId], $chunk));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[(int) $row['onu_id']][] = $row;
            }
        }

        return $out;
    }

    /** ONUs ja vinculadas (onu_id => quantos clientes ativos), logins ja vinculados e logins com ONU, para sugestoes. @return array{onus: array<int,int>, logins: array<string,true>, logins_onu: array<string,true>} */
    public function boundSets(int $providerId): array
    {
        $out = ['onus' => [], 'logins' => [], 'logins_onu' => []];
        if (!$this->available()) {
            return $out;
        }
        $st = $this->pdo->prepare('SELECT onu_id, mkauth_login FROM client_network_bindings WHERE provider_id = ? AND ativo = 1');
        $st->execute([$providerId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['onu_id'] !== null) {
                $out['onus'][(int) $r['onu_id']] = ($out['onus'][(int) $r['onu_id']] ?? 0) + 1;
                $out['logins_onu'][strtolower((string) $r['mkauth_login'])] = true;
            }
            $out['logins'][strtolower((string) $r['mkauth_login'])] = true;
        }

        return $out;
    }

    /**
     * Liga o cliente a uma porta de CTO. Se ele ja tem vinculo ativo, MOVE (mantem a ONU). Como a porta e da ONU, mover um
     * cliente que tem ONU leva TODAS as linhas ativas dessa ONU junto. A porta so aceita a mesma ONU (ou o mesmo cliente).
     *
     * @return array{id:int,antes:?array<string,mixed>,movidos:int}
     * @throws BindingConflict porta ou cliente em conflito
     */
    public function bindCto(int $providerId, string $login, ?string $nome, int $ctoId, int $portId, string $actor, ?string $note = null): array
    {
        return $this->transaction(function () use ($providerId, $login, $nome, $ctoId, $portId, $actor, $note): array {
            $this->lockPort($portId);
            $before = $this->active($providerId, $login);
            $myOnu = $before === null || $before['onu_id'] === null ? null : (int) $before['onu_id'];
            foreach ($this->activeListByPort($providerId, $portId) as $o) {
                if (strcasecmp((string) $o['mkauth_login'], $login) === 0) {
                    continue;
                }
                if ($myOnu === null || $o['onu_id'] === null || (int) $o['onu_id'] !== $myOnu) {
                    throw new BindingConflict($o['onu_id'] === null
                        ? 'Essa porta já tem o cliente ' . ($o['cliente_nome'] ?: $o['mkauth_login']) . ' vinculado.'
                        : 'Essa porta já está ocupada por outra ONU (cliente ' . ($o['cliente_nome'] ?: $o['mkauth_login']) . ').');
                }
            }
            $moved = 1;
            try {
                if ($before === null) {
                    $this->pdo->prepare(
                        "INSERT INTO client_network_bindings (provider_id, mkauth_login, cliente_nome, cto_node_id, cto_port_id, origem, confirmado_por, confirmado_em, ativo, observacao, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, 'manual', ?, NOW(), 1, ?, NOW(), NOW())"
                    )->execute([$providerId, $login, $nome, $ctoId, $portId, $actor, $note]);
                    $id = (int) $this->pdo->lastInsertId();
                } else {
                    $this->pdo->prepare(
                        'UPDATE client_network_bindings SET cto_node_id = ?, cto_port_id = ?, cliente_nome = COALESCE(?, cliente_nome), confirmado_por = ?, confirmado_em = NOW(), observacao = COALESCE(?, observacao), updated_at = NOW() WHERE id = ? AND provider_id = ?'
                    )->execute([$ctoId, $portId, $nome, $actor, $note, $before['id'], $providerId]);
                    $id = (int) $before['id'];
                    if ($myOnu !== null) {   // a ONU inteira muda de porta
                        $st = $this->pdo->prepare('UPDATE client_network_bindings SET cto_node_id = ?, cto_port_id = ?, updated_at = NOW() WHERE provider_id = ? AND ativo = 1 AND onu_id = ? AND id <> ?');
                        $st->execute([$ctoId, $portId, $providerId, $myOnu, $id]);
                        $moved += $st->rowCount();
                    }
                }
            } catch (\PDOException $e) {
                throw $this->conflict($e);
            }

            return ['id' => $id, 'antes' => $before, 'movidos' => $moved];
        });
    }

    /**
     * Liga o cliente a uma ONU (confirmacao de sugestao ou manual) e, opcionalmente, registra o modo. A ONU pode ja ter outros
     * clientes: o novo herda a CTO/porta da ONU; se so o cliente tem porta, a porta passa a ser da ONU (todas as linhas).
     *
     * @return array{id:int,antes:?array<string,mixed>}
     * @throws BindingConflict
     */
    public function bindOnu(int $providerId, string $login, ?string $nome, int $onuId, string $origem, ?string $confianca, string $actor, ?string $modo = null): array
    {
        if ($modo !== null && !in_array($modo, self::MODOS, true)) {
            throw new BindingConflict('Modo inválido.');
        }

        return $this->transaction(function () use ($providerId, $login, $nome, $onuId, $origem, $confianca, $actor, $modo): array {
            $this->pdo->prepare('SELECT id FROM net_onus WHERE id = ? AND provider_id = ? FOR UPDATE')->execute([$onuId, $providerId]);
            $before = $this->active($providerId, $login);
            if ($before !== null && $before['onu_id'] !== null && (int) $before['onu_id'] !== $onuId) {
                throw new BindingConflict('Esse cliente já tem outra ONU vinculada.');
            }
            $sibling = null;
            foreach ($this->activeListByOnu($providerId, $onuId) as $o) {
                if (strcasecmp((string) $o['mkauth_login'], $login) !== 0 && ($o['cto_port_id'] !== null || $o['cto_node_id'] !== null)) {
                    $sibling = $o;
                    break;
                }
            }
            $mine = $before !== null && ($before['cto_port_id'] !== null || $before['cto_node_id'] !== null);
            if ($sibling !== null && $mine && ((int) $sibling['cto_port_id'] !== (int) $before['cto_port_id'] || (int) $sibling['cto_node_id'] !== (int) $before['cto_node_id'])) {
                throw new BindingConflict('A ONU já está em ' . $sibling['cto_nome'] . ($sibling['porta_rotulo'] ? ', ' . $sibling['porta_rotulo'] : '') . ' e o cliente em ' . $before['cto_nome'] . ($before['porta_rotulo'] ? ', ' . $before['porta_rotulo'] : '') . ': remova a CTO de um dos dois antes de juntar.');
            }
            try {
                if ($before === null) {
                    $this->pdo->prepare(
                        'INSERT INTO client_network_bindings (provider_id, mkauth_login, cliente_nome, cto_node_id, cto_port_id, onu_id, origem, confianca, modo_pppoe, confirmado_por, confirmado_em, ativo, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1, NOW(), NOW())'
                    )->execute([$providerId, $login, $nome, $sibling['cto_node_id'] ?? null, $sibling['cto_port_id'] ?? null, $onuId, $origem, $confianca, $modo, $actor]);
                    $id = (int) $this->pdo->lastInsertId();
                } else {
                    $this->pdo->prepare(
                        'UPDATE client_network_bindings SET onu_id = ?, origem = ?, confianca = ?, modo_pppoe = COALESCE(?, modo_pppoe), cliente_nome = COALESCE(?, cliente_nome),
                                cto_node_id = COALESCE(cto_node_id, ?), cto_port_id = COALESCE(cto_port_id, ?), confirmado_por = ?, confirmado_em = NOW(), updated_at = NOW() WHERE id = ? AND provider_id = ?'
                    )->execute([$onuId, $origem, $confianca, $modo, $nome, $sibling['cto_node_id'] ?? null, $sibling['cto_port_id'] ?? null, $actor, $before['id'], $providerId]);
                    $id = (int) $before['id'];
                    if ($mine && $sibling === null) {   // so o cliente tinha porta: ela passa a ser da ONU
                        $this->pdo->prepare('UPDATE client_network_bindings SET cto_node_id = ?, cto_port_id = ?, updated_at = NOW() WHERE provider_id = ? AND ativo = 1 AND onu_id = ? AND id <> ?')
                            ->execute([$before['cto_node_id'], $before['cto_port_id'], $providerId, $onuId, $id]);
                    }
                }
            } catch (\PDOException $e) {
                throw $this->conflict($e);
            }

            return ['id' => $id, 'antes' => $before];
        });
    }

    /** Define (ou limpa, com null) o modo PPPoE de um vinculo ativo. @return array<string,mixed>|null vinculo antes da mudanca */
    public function setMode(int $providerId, int $id, ?string $modo): ?array
    {
        if ($modo !== null && !in_array($modo, self::MODOS, true)) {
            throw new BindingConflict('Modo inválido.');
        }
        $b = $this->find($providerId, $id);
        if ($b === null || (int) $b['ativo'] !== 1) {
            return null;
        }
        $this->pdo->prepare('UPDATE client_network_bindings SET modo_pppoe = ?, updated_at = NOW() WHERE id = ? AND provider_id = ?')->execute([$modo, $id, $providerId]);

        return $b;
    }

    /**
     * Remove uma parte do vinculo: 'cto' (CTO e porta), 'onu' ou 'tudo'. Sem nada restante o vinculo fica inativo (historico).
     * Devolve o vinculo antes da mudanca, ou null se nao existe/ja inativo.
     *
     * @return array<string,mixed>|null
     */
    public function remove(int $providerId, int $id, string $part, string $actor): ?array
    {
        $b = $this->find($providerId, $id);
        if ($b === null || (int) $b['ativo'] !== 1) {
            return null;
        }
        $onuId = $b['onu_id'] === null ? null : (int) $b['onu_id'];
        $others = 0;
        if ($onuId !== null) {
            foreach ($this->activeListByOnu($providerId, $onuId) as $o) {
                $others += (int) $o['id'] !== $id ? 1 : 0;
            }
        }
        if ($part === 'cto' && $onuId !== null) {
            // A CTO/porta e da ONU: tirar a CTO tira de todos os clientes dessa ONU.
            $this->pdo->prepare('UPDATE client_network_bindings SET cto_node_id = NULL, cto_port_id = NULL, updated_at = NOW() WHERE provider_id = ? AND ativo = 1 AND onu_id = ?')->execute([$providerId, $onuId]);

            return $b;
        }
        // Tirar a ONU de um cliente quando OUTROS ainda usam a ONU: ele nao pode ficar com a porta da ONU, que e fisica e fica com os demais.
        $keepCto = $part === 'onu' && $others === 0;
        $cto = $keepCto ? $b['cto_node_id'] : null;
        $port = $keepCto ? $b['cto_port_id'] : null;
        $onu = $part === 'cto' ? $b['onu_id'] : null;
        $keep = $part !== 'tudo' && ($cto !== null || $onu !== null);
        if ($keep) {
            $this->pdo->prepare('UPDATE client_network_bindings SET cto_node_id = ?, cto_port_id = ?, onu_id = ?, modo_pppoe = IF(? IS NULL, NULL, modo_pppoe), updated_at = NOW() WHERE id = ? AND provider_id = ?')
                ->execute([$cto, $port, $onu, $onu, $id, $providerId]);
        } else {
            $this->pdo->prepare('UPDATE client_network_bindings SET ativo = 0, desativado_por = ?, desativado_em = NOW(), updated_at = NOW() WHERE id = ? AND provider_id = ?')
                ->execute([$actor, $id, $providerId]);
        }

        return $b;
    }

    public function reject(int $providerId, int $onuId, string $login, string $actor): void
    {
        $this->pdo->prepare('INSERT IGNORE INTO client_network_binding_rejections (provider_id, onu_id, mkauth_login, rejected_by, rejected_at) VALUES (?, ?, ?, ?, NOW())')
            ->execute([$providerId, $onuId, $login, $actor]);
    }

    /** @return array<string,true> chaves "onu_id|login" (login em minusculas) */
    public function rejections(int $providerId): array
    {
        if (!$this->available()) {
            return [];
        }
        $st = $this->pdo->prepare('SELECT onu_id, mkauth_login FROM client_network_binding_rejections WHERE provider_id = ?');
        $st->execute([$providerId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['onu_id'] . '|' . strtolower((string) $r['mkauth_login'])] = true;
        }

        return $out;
    }

    /** Vinculo ativo que usa a porta (para impedir reducao/remocao de portas no mapa). @return array<string,mixed>|null */
    public function portInUse(int $providerId, int $portId): ?array
    {
        return $this->available() ? $this->activeByPort($providerId, $portId) : null;
    }

    private function lockPort(int $portId): void
    {
        $this->pdo->prepare('SELECT id FROM map_ports WHERE id = ? FOR UPDATE')->execute([$portId]);
    }

    /**
     * Executa em transacao (reaproveita a do chamador, se houver). Em erro desfaz e repassa.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function transaction(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn();
        }
        $this->pdo->beginTransaction();
        try {
            $out = $fn();
            $this->pdo->commit();

            return $out;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @param list<mixed> $params @return array<string,mixed>|null */
    private function one(string $where, array $params): ?array
    {
        $st = $this->pdo->prepare(self::SELECT . $where . ' LIMIT 1');
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function conflict(\PDOException $e): \Throwable
    {
        $msg = (string) $e->getMessage();
        if ($e->getCode() === '23000' || str_contains($msg, 'Duplicate entry')) {
            return new BindingConflict(match (true) {
                str_contains($msg, 'cnb_porta_ativa') => 'Essa porta já tem cliente vinculado.',
                str_contains($msg, 'cnb_par_ativo') => 'Esse cliente já está vinculado a essa ONU.',
                str_contains($msg, 'cnb_login_ativo') => 'Esse cliente já tem vínculo ativo.',
                default => 'Conflito com outro vínculo.',
            });
        }

        return $e;
    }
}
