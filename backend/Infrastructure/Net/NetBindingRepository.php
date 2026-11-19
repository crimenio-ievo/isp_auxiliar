<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

use PDO;

/**
 * Vinculos cliente (login do MkAuth) <-> CTO/porta e ONU (client_network_bindings). Uma linha por cliente ativo;
 * porta e ONU so podem ter um vinculo ativo (indices unicos por coluna gerada). Nunca escreve no MkAuth.
 */
final class NetBindingRepository
{
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

    /** @return array<string,mixed>|null */
    public function activeByPort(int $providerId, int $portId): ?array
    {
        return $this->one(' WHERE b.provider_id = ? AND b.ativo = 1 AND b.cto_port_id = ?', [$providerId, $portId]);
    }

    /** @return array<string,mixed>|null */
    public function activeByOnu(int $providerId, int $onuId): ?array
    {
        return $this->one(' WHERE b.provider_id = ? AND b.ativo = 1 AND b.onu_id = ?', [$providerId, $onuId]);
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
     * Vinculos ativos das ONUs informadas, indexados por onu_id.
     *
     * @param list<int> $onuIds
     * @return array<int,array<string,mixed>>
     */
    public function forOnus(int $providerId, array $onuIds): array
    {
        if ($onuIds === [] || !$this->available()) {
            return [];
        }
        $out = [];
        foreach (array_chunk($onuIds, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $st = $this->pdo->prepare(self::SELECT . " WHERE b.provider_id = ? AND b.ativo = 1 AND b.onu_id IN ({$marks})");
            $st->execute(array_merge([$providerId], $chunk));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[(int) $row['onu_id']] = $row;
            }
        }

        return $out;
    }

    /** Login de ONUs ja vinculadas / de logins ja vinculados (ativos), para sugestoes. @return array{onus: array<int,true>, logins: array<string,true>, logins_onu: array<string,true>} */
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
                $out['onus'][(int) $r['onu_id']] = true;
                $out['logins_onu'][strtolower((string) $r['mkauth_login'])] = true;
            }
            $out['logins'][strtolower((string) $r['mkauth_login'])] = true;
        }

        return $out;
    }

    /**
     * Liga o cliente a uma porta de CTO. Se ele ja tem vinculo ativo, MOVE (mantem a ONU). Devolve o vinculo e o anterior.
     *
     * @return array{id:int,antes:?array<string,mixed>}
     * @throws BindingConflict porta ou cliente em conflito
     */
    public function bindCto(int $providerId, string $login, ?string $nome, int $ctoId, int $portId, string $actor, ?string $note = null): array
    {
        $before = $this->active($providerId, $login);
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
            }
        } catch (\PDOException $e) {
            throw $this->conflict($e);
        }

        return ['id' => $id, 'antes' => $before];
    }

    /**
     * Liga o cliente a uma ONU (confirmacao de sugestao ou manual). Cria o vinculo se o cliente ainda nao tem.
     *
     * @return array{id:int,antes:?array<string,mixed>}
     * @throws BindingConflict
     */
    public function bindOnu(int $providerId, string $login, ?string $nome, int $onuId, string $origem, ?string $confianca, string $actor): array
    {
        $before = $this->active($providerId, $login);
        try {
            if ($before === null) {
                $this->pdo->prepare(
                    'INSERT INTO client_network_bindings (provider_id, mkauth_login, cliente_nome, onu_id, origem, confianca, confirmado_por, confirmado_em, ativo, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 1, NOW(), NOW())'
                )->execute([$providerId, $login, $nome, $onuId, $origem, $confianca, $actor]);
                $id = (int) $this->pdo->lastInsertId();
            } else {
                $this->pdo->prepare(
                    'UPDATE client_network_bindings SET onu_id = ?, origem = ?, confianca = ?, cliente_nome = COALESCE(?, cliente_nome), confirmado_por = ?, confirmado_em = NOW(), updated_at = NOW() WHERE id = ? AND provider_id = ?'
                )->execute([$onuId, $origem, $confianca, $nome, $actor, $before['id'], $providerId]);
                $id = (int) $before['id'];
            }
        } catch (\PDOException $e) {
            throw $this->conflict($e);
        }

        return ['id' => $id, 'antes' => $before];
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
        $cto = $part === 'onu' ? $b['cto_node_id'] : null;
        $port = $part === 'onu' ? $b['cto_port_id'] : null;
        $onu = $part === 'cto' ? $b['onu_id'] : null;
        $keep = $part !== 'tudo' && ($cto !== null || $onu !== null);
        if ($keep) {
            $this->pdo->prepare('UPDATE client_network_bindings SET cto_node_id = ?, cto_port_id = ?, onu_id = ?, updated_at = NOW() WHERE id = ? AND provider_id = ?')
                ->execute([$cto, $port, $onu, $id, $providerId]);
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
                str_contains($msg, 'cnb_onu_ativa') => 'Essa ONU já está vinculada a outro cliente.',
                str_contains($msg, 'cnb_login_ativo') => 'Esse cliente já tem vínculo ativo.',
                default => 'Conflito com outro vínculo.',
            });
        }

        return $e;
    }
}
