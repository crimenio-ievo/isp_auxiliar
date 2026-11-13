<?php
declare(strict_types=1);
namespace App\Infrastructure\Net;

/** Opcoes longas estritas. Aceita --nome=valor ou --nome valor, rejeita ambiguidades. */
final class NetCliOptions
{
    public static function parse(array $args, array $values, array $flags): array
    {
        $out = [];
        for ($i=0; $i<count($args); $i++) {
            $pair = explode('=', $args[$i], 2);
            $key = substr($pair[0], 2);
            if (!str_starts_with($pair[0], '--') || isset($out[$key])) { throw new \InvalidArgumentException('Opcao invalida ou duplicada.'); }
            if (in_array($key, $flags, true) && count($pair)===1) { $out[$key] = true; continue; }
            if (!in_array($key, $values, true)) { throw new \InvalidArgumentException('Opcao desconhecida.'); }
            $value = $pair[1] ?? ($args[++$i] ?? null);
            if ($value === null || str_starts_with($value, '--')) { throw new \InvalidArgumentException('Opcao sem valor.'); }
            $out[$key] = $value;
        }
        return $out;
    }
    /**
     * Extrai (e remove de $args) todas as ocorrências de --nome=valor ou --nome valor, para opções repetíveis.
     *
     * @param list<string> $args
     * @return list<string>
     */
    public static function extractRepeatable(array &$args, string $name): array
    {
        $out = [];
        $rest = [];
        for ($i = 0; $i < count($args); $i++) {
            $pair = explode('=', $args[$i], 2);
            if ($pair[0] !== '--' . $name) {
                $rest[] = $args[$i];
                continue;
            }
            $value = $pair[1] ?? ($args[++$i] ?? null);
            if ($value === null || $value === '' || str_starts_with($value, '--')) {
                throw new \InvalidArgumentException('Opcao sem valor: ' . $name);
            }
            $out[] = $value;
        }
        $args = $rest;

        return $out;
    }

    public static function positive(array $opts, string $key, bool $required = false): ?int
    {
        if (!isset($opts[$key]) && !$required) { return null; }
        $value = $opts[$key] ?? '';
        if (!is_string($value) || !ctype_digit($value) || strlen($value)>15 || (int)$value < 1) { throw new \InvalidArgumentException('Inteiro positivo obrigatorio: '.$key); }
        return (int)$value;
    }
}
