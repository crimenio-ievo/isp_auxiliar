<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Acessa configuracoes em formato de arvore usando notacao por ponto.
 */
final class Config
{
    public function __construct(private array $items)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function all(): array
    {
        return $this->items;
    }
}
