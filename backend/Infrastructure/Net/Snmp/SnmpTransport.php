<?php

declare(strict_types=1);

namespace App\Infrastructure\Net\Snmp;

/**
 * Transporte SNMP somente leitura: uma OID por requisição, apenas GET e GETNEXT.
 * Não existe SET nem GETBULK de propósito.
 *
 * @throws SnmpTimeoutException quando o equipamento não responde dentro do timeout
 * @throws SnmpUnreachableException quando a rede devolve unreachable/prohibited/refused
 */
interface SnmpTransport
{
    public function get(string $oid): SnmpValue;

    public function getNext(string $oid): SnmpValue;

    /** Libera recursos temporários (arquivo de credencial etc.). Idempotente. */
    public function close(): void;
}
