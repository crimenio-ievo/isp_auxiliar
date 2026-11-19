<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

use App\Core\Env;
use RuntimeException;

/**
 * Cifra segredos de equipamentos com libsodium (secretbox). A chave vem de NET_SECRET_KEY (.env, base64 de 32 bytes) e
 * NET_SECRET_KEY_VERSION (opcional, padrao 1). As excecoes nunca trazem o segredo nem a chave. Perder a chave = perder as credenciais.
 */
final class NetSecretBox
{
    public function __construct(private ?string $key = null, private int $version = 1)
    {
    }

    public static function fromEnv(): self
    {
        $raw = trim((string) (Env::get('NET_SECRET_KEY', '') ?? ''));
        $key = $raw === '' ? false : base64_decode($raw, true);
        $version = (int) (Env::get('NET_SECRET_KEY_VERSION', '1') ?? '1');

        return new self(is_string($key) && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES ? $key : null, $version >= 1 ? $version : 1);
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function available(): bool
    {
        return $this->key !== null && extension_loaded('sodium');
    }

    public function version(): int
    {
        return $this->version;
    }

    /** @return array{cipher:string,nonce:string,version:int} */
    public function encrypt(string $plain): array
    {
        if (!$this->available()) {
            throw new RuntimeException('Chave de cifragem (NET_SECRET_KEY) ausente ou inválida.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return ['cipher' => sodium_crypto_secretbox($plain, $nonce, (string) $this->key), 'nonce' => $nonce, 'version' => $this->version];
    }

    public function decrypt(string $cipher, string $nonce): string
    {
        if (!$this->available()) {
            throw new RuntimeException('Chave de cifragem (NET_SECRET_KEY) ausente ou inválida.');
        }
        if (strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Credencial armazenada corrompida.');
        }
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, (string) $this->key);
        if ($plain === false) {
            throw new RuntimeException('Não foi possível decifrar a credencial (chave diferente da usada ao gravar?).');
        }

        return $plain;
    }
}
