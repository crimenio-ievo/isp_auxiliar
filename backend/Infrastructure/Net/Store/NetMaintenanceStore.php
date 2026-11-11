<?php
declare(strict_types=1);
namespace App\Infrastructure\Net\Store;

interface NetMaintenanceStore
{
    /** Atomico: registra intervencao/auditoria, silencia abertos e zera referencia/streaks. */
    public function intervene(array $intervention, array $onuIds): void;
    /** IDs dos provedores com dados de telemetria. */
    public function providers(): array;
    /** kinds: samples, events, alerts (apenas fechados). */
    public function countExpired(string $kind, int $providerId, string $before): int;
    /** Remove no maximo 5000, retorna quantidade. */
    public function purgeBatch(string $kind, int $providerId, string $before): int;
}
