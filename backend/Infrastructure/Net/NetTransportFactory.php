<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

use App\Core\Env;
use App\Infrastructure\Net\Snmp\NetSnmpCliTransport;
use App\Infrastructure\Net\Snmp\SnmpTransport;
use RuntimeException;

/**
 * Cria o transporte real de um equipamento. A credencial é resolvida por net_devices.credential_id (net_credentials,
 * cifrada) quando houver; senão pelo NOME em net_devices.credential_ref (variável NET_CRED_* do .env).
 * O segredo nunca é registrado em log nem devolvido.
 */
final class NetTransportFactory
{
    public function __construct(private ?NetCredentialRepository $credentials = null)
    {
    }

    public static function isValidCredentialRef(string $ref): bool
    {
        return preg_match('/^NET_CRED_[A-Z0-9_]{1,100}$/', $ref) === 1;
    }

    /**
     * Texto claro da credencial do equipamento: credential_id (banco) tem preferência; credential_ref (.env) é a alternativa.
     * Lança RuntimeException SEM o segredo.
     *
     * @param array<string,mixed> $device
     */
    public static function resolveSecret(array $device, ?NetCredentialRepository $credentials): string
    {
        $id = (int) ($device['credential_id'] ?? 0);
        if ($id > 0) {
            if ($credentials === null) {
                throw new RuntimeException('credencial do banco indisponível neste processo.');
            }
            $secret = $credentials->secretFor((int) ($device['provider_id'] ?? 0), $id);
            if ($secret === null || $secret === '') {
                throw new RuntimeException('credencial do banco ausente.');
            }

            return $secret;
        }
        $ref = (string) ($device['credential_ref'] ?? '');
        if (!self::isValidCredentialRef($ref)) {
            throw new RuntimeException('credential_ref inválida (esperado NET_CRED_*).');
        }
        $secret = (string) (Env::get($ref, '') ?? '');
        if ($secret === '') {
            throw new RuntimeException("credencial {$ref} ausente no .env.");
        }

        return $secret;
    }

    public function __invoke(array $device, DeviceProfile $profile): SnmpTransport
    {
        if (($device['snmp_version'] ?? '') !== '2c') {
            throw new RuntimeException('somente SNMP v2c (leitura) é suportado nesta etapa.');
        }
        $secret = self::resolveSecret($device, $this->credentials);

        return new NetSnmpCliTransport((string) $device['mgmt_host'], $secret, $profile->intervalMs(), $profile->timeoutMs());
    }
}
