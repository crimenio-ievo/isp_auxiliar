<?php

declare(strict_types=1);

namespace App\Infrastructure\Net\Store;

/** Quedas de ONU (tabela net_onu_outages, migration 034). */
interface NetOutageStore
{
    /**
     * @param list<string> $keys event_key
     * @return array<string,array<string,mixed>> event_key => linha existente (mesmo equipamento e source)
     */
    public function existing(int $deviceId, string $source, array $keys): array;

    /**
     * Grava tudo ou nada: inserts, atualizações (por device_id+source+event_key) e o registro de auditoria.
     *
     * @param list<array<string,mixed>> $inserts
     * @param list<array<string,mixed>> $updates
     * @param array<string,mixed> $audit
     */
    public function save(array $inserts, array $updates, array $audit): void;

    /**
     * Quedas que se sobrepõem ao período [from, to] (início <= to e (fim >= from ou em aberto)).
     *
     * @return list<array<string,mixed>>
     */
    public function outagesOverlapping(int $deviceId, string $from, string $to): array;

    /**
     * Eventos up/down do poll (net_onu_events) do equipamento entre from e to, em ordem de ONU, hora e id.
     *
     * @return list<array{onu_id:int,event_type:string,occurred_at:string}>
     */
    public function pollTransitions(int $deviceId, string $from, string $to): array;
}
