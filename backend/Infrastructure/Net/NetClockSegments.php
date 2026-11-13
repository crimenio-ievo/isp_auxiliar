<?php
declare(strict_types=1);
namespace App\Infrastructure\Net;

final class NetClockSegments
{
    public static function parse(array $specs): array
    {
        if (count($specs) > 5) { throw new \InvalidArgumentException('Máximo de 5 segmentos.'); }
        $out = [];
        foreach ($specs as $spec) {
            if (!preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d),\+(\d{1,6})$/D', $spec, $m)) { throw new \InvalidArgumentException('Segmento inválido: use AAAA-MM-DD hh:mm:ss,+SEGUNDOS.'); }
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $m[1], new \DateTimeZone('UTC'));
            if (!$d || $d->format('Y-m-d H:i:s') !== $m[1] || (int)$m[2] > 172800 || ($out && $m[1] <= end($out)['ate'])) { throw new \InvalidArgumentException('Segmentos inválidos ou fora de ordem.'); }
            $out[] = ['ate' => $m[1], 'segundos' => (int)$m[2]];
        }
        return $out;
    }

    public static function convert(?string $value, string $oltTz, string $destTz, array $segments): ?string
    {
        if ($value === null) { return null; }
        foreach ($segments as $segment) {
            if ($value <= $segment['ate']) {
                return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->modify('+'.$segment['segundos'].' seconds')->setTimezone(new \DateTimeZone($destTz))->format('Y-m-d H:i:s');
            }
        }
        return (new \DateTimeImmutable($value, new \DateTimeZone($oltTz)))->setTimezone(new \DateTimeZone($destTz))->format('Y-m-d H:i:s');
    }
}
