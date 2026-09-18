<?php

declare(strict_types=1);

namespace App\Services\Commercial;

use App\Core\Config;

/**
 * Regra única de cálculo e normalização dos benefícios de mudança de plano.
 * A view pode sugerir a seleção, mas este serviço sempre recalcula no servidor.
 */
final class UpgradeBenefitService
{
    public const FLAG_KEYS = [
        'radio_to_fiber',
        'adhesion_waiver',
        'plan_upgrade',
        'retention',
        'other_benefit',
    ];

    public function __construct(private Config $config)
    {
    }

    public function calculate(
        string $currentTechnology,
        string $newTechnology,
        string $currentPlan = '',
        string $newPlan = '',
        float $currentMonthlyValue = 0.0,
        float $newMonthlyValue = 0.0
    ): array {
        $currentFamily = $this->technologyFamily($currentTechnology . ' ' . $currentPlan);
        $newFamily = $this->technologyFamily($newTechnology . ' ' . $newPlan);
        $radioToFiber = $currentFamily === 'radio' && $newFamily === 'fibra';
        $planChanged = $newPlan !== '' && strcasecmp(trim($currentPlan), trim($newPlan)) !== 0;
        $conditionIncreased = $planChanged && (
            ($currentMonthlyValue > 0.0 && $newMonthlyValue > $currentMonthlyValue + 0.009)
            || $this->planSpeed($newPlan) > $this->planSpeed($currentPlan)
        );
        $waiver = $radioToFiber && $this->waiverMode() === 'automatic';
        $flags = [
            'radio_to_fiber' => $radioToFiber,
            'adhesion_waiver' => $waiver,
            'plan_upgrade' => $conditionIncreased,
            'retention' => false,
            'other_benefit' => false,
        ];
        $value = $waiver
            ? max(0.0, (float) $this->config->get('contracts.commercial.valor_adesao_padrao', 0))
            : 0.0;
        $description = $this->description($flags);
        $automaticFidelity = $value > 0.0
            && $description !== ''
            && (($radioToFiber && (bool) $this->config->get('contracts.commercial.fidelidade_automatica_migracao', true))
                || ($conditionIncreased && (bool) $this->config->get('contracts.commercial.fidelidade_automatica_upgrade', false)));

        return [
            'flags' => $flags,
            'description' => $description,
            'value' => $value,
            'automatic_fidelity' => $automaticFidelity,
            'waiver_mode' => $this->waiverMode(),
        ];
    }

    public function normalizeFlags(mixed $rawFlags): array
    {
        if (is_string($rawFlags) && trim($rawFlags) !== '') {
            $decoded = json_decode($rawFlags, true);
            $rawFlags = is_array($decoded) ? $decoded : [$rawFlags];
        }
        if (!is_array($rawFlags)) {
            return [];
        }

        $isList = array_is_list($rawFlags);
        $normalized = array_fill_keys(self::FLAG_KEYS, false);
        foreach (self::FLAG_KEYS as $key) {
            if ($isList) {
                $normalized[$key] = in_array($key, array_map('strval', $rawFlags), true)
                    || in_array(str_replace('_', '-', $key), array_map('strval', $rawFlags), true);
                continue;
            }
            $value = $rawFlags[$key] ?? $rawFlags[str_replace('_', '-', $key)] ?? null;
            $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            $normalized[$key] = $parsed ?? in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
        }

        return $normalized;
    }

    public function description(array $flags, string $otherText = ''): string
    {
        $flags = array_replace(array_fill_keys(self::FLAG_KEYS, false), $this->normalizeFlags($flags));
        $parts = [];
        if ($flags['radio_to_fiber']) {
            $parts[] = 'migração de tecnologia de rádio para fibra óptica';
        }
        if ($flags['adhesion_waiver']) {
            $parts[] = 'isenção da taxa de adesão/instalação';
        }
        if ($flags['plan_upgrade']) {
            $parts[] = 'upgrade de plano';
        }
        if ($flags['retention']) {
            $parts[] = 'condição comercial especial para retenção do cliente';
        }
        if ($flags['other_benefit']) {
            $parts[] = trim($otherText) !== '' ? trim($otherText) : 'outro benefício';
        }

        if (count($parts) < 2) {
            return $parts[0] ?? '';
        }
        $last = array_pop($parts);
        return implode(', ', $parts) . ' e ' . $last;
    }

    public function waiverMode(): string
    {
        $mode = strtolower(trim((string) $this->config->get(
            'contracts.commercial.modo_isencao_adesao_migracao_radio_fibra',
            ''
        )));
        if (in_array($mode, ['automatic', 'disabled', 'manual'], true)) {
            return $mode;
        }

        return (bool) $this->config->get('contracts.commercial.isentar_adesao_migracao_radio_fibra', false)
            ? 'automatic'
            : 'disabled';
    }

    private function technologyFamily(string $value): string
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        if (str_contains($normalized, 'rádio') || str_contains($normalized, 'radio') || preg_match('/(^|\s)d($|\s)/', $normalized)) {
            return 'radio';
        }
        if (str_contains($normalized, 'fibra') || str_contains($normalized, 'ftth') || preg_match('/(^|\s)h($|\s)/', $normalized)) {
            return 'fibra';
        }
        return '';
    }

    private function planSpeed(string $plan): float
    {
        if (!preg_match('/([0-9]+(?:[.,][0-9]+)?)\s*([kmg])?/i', $plan, $matches)) {
            return 0.0;
        }
        $value = (float) str_replace(',', '.', $matches[1]);
        return match (strtolower((string) ($matches[2] ?? ''))) {
            'g' => $value * 1000,
            'k' => $value / 1000,
            default => $value,
        };
    }
}
