<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

use App\Infrastructure\Net\Snmp\SnmpValue;

/**
 * Conversões genéricas de valores SNMP declaradas nos perfis (tipos, não fabricantes).
 */
final class ValueDecoder
{
    /**
     * Normaliza um identificador de ONU. epon_mac => 12 hex maiúsculos sem separador.
     * Aceita STRING "006d61aabbcc", "00:6d:61:aa:bb:cc", "00-6D-..." ou Hex-STRING "00 6D 61 AA BB CC".
     * Devolve null para valor ausente, malformado, todo zero ou broadcast.
     */
    public static function identifier(string $identifierType, SnmpValue $v): ?string
    {
        if ($v->isEnd() || $v->isNoSuch()) {
            return null;
        }
        if ($identifierType === 'epon_mac') {
            return self::mac($v->value);
        }
        $sn = strtoupper(trim($v->value));

        return preg_match('/^[A-Z0-9]{8,32}$/', $sn) === 1 ? $sn : null;
    }

    public static function mac(string $raw): ?string
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $raw) ?? '');
        if (strlen($hex) !== 12 || $hex === '000000000000' || $hex === 'FFFFFFFFFFFF') {
            return null;
        }
        // Só aceita separadores comuns: evita interpretar texto qualquer como MAC.
        if (preg_match('/[^0-9A-Fa-f:\-. ]/', trim($raw))) {
            return null;
        }

        return $hex;
    }

    /** Mascara os 3 últimos octetos: 006D61AABBCC => 00:6D:61:xx:xx:xx */
    public static function maskMac(string $mac12): string
    {
        $h = strtoupper($mac12);
        if (strlen($h) !== 12) {
            return 'xx:xx:xx:xx:xx:xx';
        }

        return substr($h, 0, 2) . ':' . substr($h, 2, 2) . ':' . substr($h, 4, 2) . ':xx:xx:xx';
    }

    /**
     * Status operacional pelo mapa do perfil (int_enum). Código fora do mapa => default ('unknown'), com o código bruto preservado.
     *
     * @param array<string,mixed> $metric
     * @return array{0:string,1:?string} [status lógico, código bruto]
     */
    public static function status(array $metric, SnmpValue $v): array
    {
        if ($v->isEnd() || $v->isNoSuch()) {
            return ['unknown', null];
        }
        $raw = trim($v->value);
        if (($metric['type'] ?? null) === 'int_threshold') {
            if (!preg_match('/^-?[0-9]+$/', $raw)) {
                return ['unknown', substr($raw, 0, 32)];
            }

            return [(int) $raw > (int) $metric['online_if_gt'] ? 'online' : 'offline', substr($raw, 0, 32)];
        }
        $map = is_array($metric['map'] ?? null) ? $metric['map'] : [];
        $default = (string) ($metric['default'] ?? 'unknown');
        $status = isset($map[$raw]) ? (string) $map[$raw] : $default;
        if (!in_array($status, ['online', 'offline', 'unknown'], true)) {
            $status = 'unknown';
        }

        return [$status, substr($raw, 0, 32)];
    }

    /** Número decimal em texto ("-23.19"). "na", vazio, texto ou valor absurdo (fora de -100..100) => null. */
    public static function float(SnmpValue $v): ?float
    {
        if ($v->isEnd() || $v->isNoSuch()) {
            return null;
        }
        $t = trim($v->value);
        if (!preg_match('/^-?[0-9]{1,3}(\.[0-9]{1,4})?$/', $t)) {
            return null;
        }
        $f = (float) $t;

        return abs($f) > 100.0 ? null : $f;
    }

    /** Normaliza um campo de identidade para comparação estável. */
    public static function identityField(string $name, SnmpValue $v): ?string
    {
        if ($v->isEnd() || $v->isNoSuch()) {
            return null;
        }
        return match ($name) {
            'sys_object_id' => ltrim(trim($v->value), '.'),
            'engine_id' => strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $v->value) ?? ''),
            'uptime' => ctype_digit(trim($v->value)) ? trim($v->value) : null,
            default => trim($v->value),
        };
    }
}
