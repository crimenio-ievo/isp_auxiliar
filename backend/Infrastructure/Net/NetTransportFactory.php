<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

use App\Core\Env;
use App\Infrastructure\Net\Snmp\NetSnmpCliTransport;
use App\Infrastructure\Net\Snmp\SnmpTransport;
use RuntimeException;

/**
 * Cria o transporte real de um equipamento. A credencial é resolvida pelo NOME guardado em
 * net_devices.credential_ref (variável NET_CRED_* do .env) e nunca é registrada em log nem devolvida.
 */
final class NetTransportFactory
{
    public static function isValidCredentialRef(string $ref): bool
    {
        return preg_match('/^NET_CRED_[A-Z0-9_]{1,100}$/', $ref) === 1;
    }

    public function __invoke(array $device, DeviceProfile $profile): SnmpTransport
    {
        if (($device['snmp_version'] ?? '') !== '2c') {
            throw new RuntimeException('somente SNMP v2c (leitura) é suportado nesta etapa.');
        }
        $ref = (string) ($device['credential_ref'] ?? '');
        if (!self::isValidCredentialRef($ref)) {
            throw new RuntimeException('credential_ref inválida (esperado NET_CRED_*).');
        }
        $secret = (string) (Env::get($ref, '') ?? '');
        if ($secret === '') {
            throw new RuntimeException("credencial {$ref} ausente no .env.");
        }

        return new NetSnmpCliTransport((string) $device['mgmt_host'], $secret, $profile->intervalMs(), $profile->timeoutMs());
    }
}
