<?php

declare(strict_types=1);

namespace App\Infrastructure\Net\Store;

/**
 * Persistência da telemetria. O coletor só fala com esta interface, para ser testável sem banco.
 *
 * Linha de ONU (deviceOnus/onusByIdentifiers): id, provider_id, device_id, slot, pon_index, onu_index, snmp_index,
 * onu_identifier, identifier_type, first_seen_at, last_seen_at, removed_at, oper_status, raw_status_code,
 * status_since, stale, missing_cycles.
 *
 * Plano de applyCycle():
 *  - insert: list<array{onu: array, state: array, events: list<array{0:string,1:array}>}>
 *  - update: array<int onu_id, array{onu: array, state: array, events: list<array{0:string,1:array}>}>
 *  - poll_state: array (campos de net_device_poll_state)
 *  - expected_identity: ?array (grava a referência aprovada)
 */
interface NetStore
{
    /** Equipamento + perfil (profile_code, profile_json, profile_status). */
    public function findDevice(int $deviceId): ?array;

    public function pollState(int $deviceId): ?array;

    /** Grava só o estado de coleta (ciclos que não gravam dados de ONU). */
    public function savePollState(int $deviceId, array $fields): void;

    /** @return list<array> */
    public function deviceOnus(int $deviceId): array;

    /**
     * @param list<string> $identifiers
     * @return array<string, array> identificador => linha
     */
    public function onusByIdentifiers(int $providerId, string $identifierType, array $identifiers): array;

    /** OLT sem resposta: marca as ONUs do equipamento como stale, sem mexer em status nem gerar eventos. */
    public function markDeviceStale(int $deviceId, string $now, array $pollState): int;

    /** Aplica o ciclo inteiro de forma atômica. */
    public function applyCycle(int $deviceId, array $plan, string $now): void;
}
