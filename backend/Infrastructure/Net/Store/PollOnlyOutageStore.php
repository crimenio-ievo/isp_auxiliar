<?php

declare(strict_types=1);

namespace App\Infrastructure\Net\Store;

/**
 * Leitura quando a tabela do log (migration 034) ainda não existe: só as quedas do poll (net_onu_events).
 * Nunca grava.
 */
final class PollOnlyOutageStore implements NetOutageStore
{
    public function __construct(private NetOutageStore $delegate)
    {
    }

    public function existing(int $deviceId, string $source, array $keys): array
    {
        return [];
    }

    public function save(array $inserts, array $updates, array $audit): void
    {
        throw new \LogicException('Somente leitura.');
    }

    public function outagesOverlapping(int $deviceId, string $from, string $to): array
    {
        return [];
    }

    public function pollTransitions(int $deviceId, string $from, string $to): array
    {
        return $this->delegate->pollTransitions($deviceId, $from, $to);
    }
}
