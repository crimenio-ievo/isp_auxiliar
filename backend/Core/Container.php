<?php

declare(strict_types=1);

namespace App\Core;

use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Container enxuto para resolver dependencias por tipo.
 *
 * Ele ja atende bem esta etapa e pode ser substituido por algo mais robusto
 * se o projeto crescer muito.
 */
final class Container
{
    private array $entries = [];

    public function set(string $id, mixed $value): void
    {
        $this->entries[$id] = $value;
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->entries)) {
            return $this->entries[$id];
        }

        if (!class_exists($id)) {
            throw new RuntimeException("Entrada nao encontrada no container: {$id}");
        }

        $reflection = new ReflectionClass($id);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            $instance = new $id();
            $this->entries[$id] = $instance;

            return $instance;
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                throw new RuntimeException(
                    "Nao foi possivel resolver a dependencia {$parameter->getName()} em {$id}"
                );
            }

            $arguments[] = $this->get($type->getName());
        }

        $instance = $reflection->newInstanceArgs($arguments);
        $this->entries[$id] = $instance;

        return $instance;
    }
}
