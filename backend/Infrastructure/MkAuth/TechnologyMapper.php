<?php

declare(strict_types=1);

namespace App\Infrastructure\MkAuth;

/**
 * Traduz apenas códigos de tecnologia cuja origem foi confirmada.
 *
 * D e H seguem a codificação histórica da coleta SCM/SICI usada pelo MkAuth:
 * D = FWA e H = FTTH. Códigos sem fonte conhecida nunca são inferidos pelo
 * nome comercial do plano.
 */
final class TechnologyMapper
{
    private const MAP = [
        'D' => [
            'label' => 'Rádio fixo (FWA)',
            'family' => 'radio',
            'standard' => 'FWA',
            'verified' => true,
            'source' => 'SCM/SICI - alínea D',
        ],
        'H' => [
            'label' => 'Fibra até o imóvel (FTTH)',
            'family' => 'fibra',
            'standard' => 'FTTH',
            'verified' => true,
            'source' => 'SCM/SICI - alínea H',
        ],
        'FWA' => [
            'label' => 'Rádio fixo (FWA)',
            'family' => 'radio',
            'standard' => 'FWA',
            'verified' => true,
            'source' => 'Descrição explícita',
        ],
        'FTTH' => [
            'label' => 'Fibra até o imóvel (FTTH)',
            'family' => 'fibra',
            'standard' => 'FTTH',
            'verified' => true,
            'source' => 'Descrição explícita',
        ],
    ];

    public function describe(?string $rawCode): array
    {
        $rawCode = trim((string) $rawCode);
        $key = strtoupper($rawCode);

        if ($key !== '' && isset(self::MAP[$key])) {
            return ['code' => $rawCode] + self::MAP[$key];
        }

        return [
            'code' => $rawCode,
            'label' => 'Tecnologia não identificada',
            'family' => '',
            'standard' => '',
            'verified' => false,
            'source' => '',
        ];
    }

    public function label(?string $rawCode): string
    {
        return (string) $this->describe($rawCode)['label'];
    }

    public function family(?string $rawCode): string
    {
        return (string) $this->describe($rawCode)['family'];
    }
}
