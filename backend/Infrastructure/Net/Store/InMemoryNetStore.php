<?php

declare(strict_types=1);

namespace App\Infrastructure\Net\Store;

/**
 * Implementação em memória de NetStore, usada nos testes do coletor.
 */
final class InMemoryNetStore implements NetStore
{
    /** @var array<int,array> */
    public array $devices = [];
    /** @var array<int,array> */
    public array $pollStates = [];
    /** @var array<int,array> id => onu + estado */
    public array $onus = [];
    /** @var list<array{onu_id:int,event_type:string,details:array,occurred_at:string}> */
    public array $events = [];
    private int $nextOnuId = 1;

    public function findDevice(int $deviceId): ?array
    {
        return $this->devices[$deviceId] ?? null;
    }

    public function pollState(int $deviceId): ?array
    {
        return $this->pollStates[$deviceId] ?? null;
    }

    public function savePollState(int $deviceId, array $fields): void
    {
        $this->pollStates[$deviceId] = array_merge($this->pollStates[$deviceId] ?? [], $fields);
    }

    public function deviceOnus(int $deviceId): array
    {
        return array_values(array_filter($this->onus, static fn (array $o): bool => (int) $o['device_id'] === $deviceId));
    }

    public function onusByIdentifiers(int $providerId, string $identifierType, array $identifiers): array
    {
        $out = [];
        foreach ($this->onus as $o) {
            if ((int) $o['provider_id'] === $providerId && $o['identifier_type'] === $identifierType && in_array($o['onu_identifier'], $identifiers, true)) {
                $out[$o['onu_identifier']] = $o;
            }
        }

        return $out;
    }

    public function markDeviceStale(int $deviceId, string $now, array $pollState): int
    {
        $n = 0;
        foreach ($this->onus as $id => $o) {
            if ((int) $o['device_id'] === $deviceId && $o['removed_at'] === null) {
                $this->onus[$id]['stale'] = 1;
                $this->onus[$id]['updated_at'] = $now;
                $n++;
            }
        }
        $this->savePollState($deviceId, $pollState);

        return $n;
    }

    public function applyCycle(int $deviceId, array $plan, string $now): void
    {
        foreach ($plan['insert'] as $item) {
            $id = $this->nextOnuId++;
            $this->onus[$id] = array_merge(['id' => $id], $item['onu'], $item['state']);
            $this->addEvents($id, $item['events'], $now);
        }
        foreach ($plan['update'] as $id => $item) {
            $this->onus[$id] = array_merge($this->onus[$id], $item['onu'], $item['state']);
            $this->addEvents($id, $item['events'], $now);
        }
        if (($plan['expected_identity'] ?? null) !== null) {
            $this->devices[$deviceId]['expected_identity_json'] = json_encode($plan['expected_identity']);
        }
        $this->savePollState($deviceId, $plan['poll_state']);
    }

    /** @return list<string> tipos de evento, em ordem */
    public function eventTypes(): array
    {
        return array_map(static fn (array $e): string => $e['event_type'], $this->events);
    }

    public function onuByIdentifier(string $identifier): ?array
    {
        foreach ($this->onus as $o) {
            if ($o['onu_identifier'] === $identifier) {
                return $o;
            }
        }

        return null;
    }

    private function addEvents(int $onuId, array $events, string $now): void
    {
        foreach ($events as [$type, $details]) {
            $this->events[] = ['onu_id' => $onuId, 'event_type' => $type, 'details' => $details, 'occurred_at' => $now];
        }
    }
}
