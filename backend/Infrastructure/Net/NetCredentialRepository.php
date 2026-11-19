<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

use PDO;

/**
 * net_credentials. Os metodos publicos de listagem NUNCA devolvem segredo, nonce nem cifra: so metadados
 * ("definida em DATA por USUARIO"). O unico caminho para o texto claro e secretFor(), usado pelo coletor.
 */
final class NetCredentialRepository
{
    public const KINDS = ['snmp_v2c', 'snmp_v3', 'ssh', 'telnet', 'http'];

    /** @param PDO|\Closure():PDO $pdo Closure = conexão aberta só quando for preciso (web não conecta à toa) */
    public function __construct(private PDO|\Closure $pdo, private NetSecretBox $box)
    {
    }

    private function pdo(): PDO
    {
        if ($this->pdo instanceof \Closure) {
            $this->pdo = ($this->pdo)();
        }

        return $this->pdo;
    }

    public function box(): NetSecretBox
    {
        return $this->box;
    }

    public function available(): bool
    {
        try {
            $this->pdo()->query('SELECT id FROM net_credentials LIMIT 0');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<array<string,mixed>> sem segredo */
    public function list(int $providerId): array
    {
        $st = $this->pdo()->prepare(
            'SELECT c.id, c.name, c.kind, c.username, c.key_version, c.created_by, c.created_at, c.updated_by, c.updated_at,
                    (SELECT COUNT(*) FROM net_devices d WHERE d.credential_id = c.id) AS equipamentos
               FROM net_credentials c WHERE c.provider_id = ? ORDER BY c.name'
        );
        $st->execute([$providerId]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'nome' => (string) $r['name'], 'tipo' => (string) $r['kind'],
            'usuario' => $r['username'] === null ? null : (string) $r['username'],
            'definida_em' => (string) $r['updated_at'], 'definida_por' => (string) ($r['updated_by'] ?? $r['created_by'] ?? ''),
            'criada_em' => (string) $r['created_at'], 'versao_chave' => (int) $r['key_version'], 'equipamentos' => (int) $r['equipamentos'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null metadados sem segredo */
    public function find(int $providerId, int $id): ?array
    {
        foreach ($this->list($providerId) as $c) {
            if ($c['id'] === $id) {
                return $c;
            }
        }

        return null;
    }

    public function create(int $providerId, string $name, string $kind, ?string $username, string $secret, string $actor): int
    {
        $enc = $this->box->encrypt($secret);
        $this->pdo()->prepare(
            'INSERT INTO net_credentials (provider_id, name, kind, username, secret_cipher, nonce, key_version, created_by, created_at, updated_by, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW())'
        )->execute([$providerId, $name, $kind, $username, $enc['cipher'], $enc['nonce'], $enc['version'], $actor, $actor]);

        return (int) $this->pdo()->lastInsertId();
    }

    public function replaceSecret(int $providerId, int $id, string $secret, ?string $username, string $actor): bool
    {
        $enc = $this->box->encrypt($secret);
        $st = $this->pdo()->prepare(
            'UPDATE net_credentials SET secret_cipher = ?, nonce = ?, key_version = ?, username = ?, updated_by = ?, updated_at = NOW() WHERE id = ? AND provider_id = ?'
        );
        $st->execute([$enc['cipher'], $enc['nonce'], $enc['version'], $username, $actor, $id, $providerId]);

        return $st->rowCount() > 0;
    }

    public function nameExists(int $providerId, string $name): bool
    {
        $st = $this->pdo()->prepare('SELECT id FROM net_credentials WHERE provider_id = ? AND name = ?');
        $st->execute([$providerId, $name]);

        return $st->fetchColumn() !== false;
    }

    public function delete(int $providerId, int $id): bool
    {
        $st = $this->pdo()->prepare('DELETE FROM net_credentials WHERE id = ? AND provider_id = ?');
        $st->execute([$id, $providerId]);

        return $st->rowCount() > 0;
    }

    /** Uso interno do coletor: texto claro. Nunca chamar a partir de controller/tela. */
    public function secretFor(int $providerId, int $id): ?string
    {
        $st = $this->pdo()->prepare('SELECT secret_cipher, nonce FROM net_credentials WHERE id = ? AND provider_id = ?');
        $st->execute([$id, $providerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->box->decrypt((string) $row['secret_cipher'], (string) $row['nonce']);
    }
}
