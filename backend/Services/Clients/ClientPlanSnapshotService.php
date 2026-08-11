<?php

declare(strict_types=1);

namespace App\Services\Clients;

/**
 * Mantém a identidade técnica e a apresentação comercial do plano no mesmo
 * snapshot, sem exigir que as views interpretem UUIDs ou códigos do MkAuth.
 */
final class ClientPlanSnapshotService
{
    private const SNAPSHOT_KEYS = [
        'plan_uuid',
        'plan_code',
        'plan_name',
        'plan_value',
        'plan_technology',
        'plan_download',
        'plan_upload',
    ];

    public function capture(array $data, array $catalog): array
    {
        $selected = trim((string) (
            $data['plano']
            ?? $data['plan_uuid']
            ?? $data['plan_code']
            ?? $data['plan_name']
            ?? ''
        ));

        if ($selected === '') {
            return array_replace($data, $this->emptySnapshot(), [
                'plan_resolution_status' => 'missing',
                'plan_resolution_warning' => '',
            ]);
        }

        foreach ($catalog as $plan) {
            if (!is_array($plan)) {
                continue;
            }

            $candidate = $this->normalizeCatalogPlan($plan);
            if (!$this->matches($selected, $candidate)) {
                continue;
            }

            $technicalIdentifier = $candidate['uuid'] !== ''
                ? $candidate['uuid']
                : ($candidate['code'] !== '' ? $candidate['code'] : ($candidate['id'] !== '' ? $candidate['id'] : $candidate['name']));

            return array_replace($data, [
                'plano' => $technicalIdentifier,
                'plan_uuid' => $candidate['uuid'],
                'plan_code' => $candidate['code'],
                'plan_name' => $candidate['name'],
                'plan_value' => $candidate['value'],
                'plan_technology' => $candidate['technology'],
                'plan_download' => $candidate['download'],
                'plan_upload' => $candidate['upload'],
                'plan_resolution_status' => 'resolved',
                'plan_resolution_warning' => '',
            ]);
        }

        if ($catalog === [] && $this->hasCoherentSnapshot($data, $selected)) {
            return array_replace($data, [
                'plan_resolution_status' => 'snapshot_preserved',
                'plan_resolution_warning' => 'O catálogo de planos está indisponível. O snapshot comercial já salvo foi preservado para conferência.',
            ]);
        }

        return array_replace($data, $this->emptySnapshot(), [
            'plano' => $selected,
            'plan_resolution_status' => 'unresolved',
            'plan_resolution_warning' => 'O plano selecionado não foi localizado no catálogo atual. Volte ao cadastro e selecione um plano oficial antes de concluir.',
        ]);
    }

    public function snapshot(array $data): array
    {
        $snapshot = [];
        foreach (self::SNAPSHOT_KEYS as $key) {
            $snapshot[$key] = trim((string) ($data[$key] ?? ''));
        }

        $snapshot['technical_identifier'] = trim((string) ($data['plano'] ?? ''));
        $snapshot['resolution_status'] = trim((string) ($data['plan_resolution_status'] ?? ''));

        return $snapshot;
    }

    private function normalizeCatalogPlan(array $plan): array
    {
        $name = trim((string) ($plan['nome'] ?? $plan['name'] ?? $plan['plan_name'] ?? ''));
        $uuid = trim((string) ($plan['uuid_plano'] ?? $plan['plan_uuid'] ?? $plan['uuid'] ?? ''));
        $code = trim((string) ($plan['codigo'] ?? $plan['code'] ?? $plan['plan_code'] ?? ''));
        $id = trim((string) ($plan['id'] ?? ''));

        if ($uuid === '' && $id !== '' && ($name === '' || strcasecmp($id, $name) !== 0)) {
            $uuid = $id;
        }

        return [
            'id' => $id,
            'uuid' => $uuid,
            'code' => $code,
            'name' => $name,
            'value' => trim((string) ($plan['valor'] ?? $plan['value'] ?? $plan['plan_value'] ?? '')),
            'technology' => trim((string) ($plan['tecnologia'] ?? $plan['technology'] ?? $plan['plan_technology'] ?? '')),
            'download' => trim((string) ($plan['veldown'] ?? $plan['speed_down'] ?? $plan['plan_download'] ?? '')),
            'upload' => trim((string) ($plan['velup'] ?? $plan['speed_up'] ?? $plan['plan_upload'] ?? '')),
        ];
    }

    private function matches(string $selected, array $candidate): bool
    {
        foreach (['uuid', 'code', 'name', 'id'] as $key) {
            $value = trim((string) ($candidate[$key] ?? ''));
            if ($value !== '' && strcasecmp($value, $selected) === 0) {
                return true;
            }
        }

        return false;
    }

    private function hasCoherentSnapshot(array $data, string $selected): bool
    {
        $name = trim((string) ($data['plan_name'] ?? ''));
        if ($name === '') {
            return false;
        }

        foreach (['plan_uuid', 'plan_code', 'plan_name'] as $key) {
            $value = trim((string) ($data[$key] ?? ''));
            if ($value !== '' && strcasecmp($value, $selected) === 0) {
                return true;
            }
        }

        return false;
    }

    private function emptySnapshot(): array
    {
        return array_fill_keys(self::SNAPSHOT_KEYS, '');
    }
}
