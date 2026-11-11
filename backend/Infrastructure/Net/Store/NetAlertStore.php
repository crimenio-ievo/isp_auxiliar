<?php
declare(strict_types=1);
namespace App\Infrastructure\Net\Store;

interface NetAlertStore
{
    /** Eventos em ordem (occurred_at,id), details decodificado. */
    public function eventsSince(int $deviceId, string $since): array;
    /** Ultimo evento rx_deviation por ONU, independente da janela de instabilidade. */
    public function latestDeviations(int $deviceId): array;
    public function openAlerts(int $deviceId): array;
    /** Maior prazo ativo de intervencao que abrange o alerta. */
    public function silencedUntil(int $deviceId, string $scopeType, int $scopeId, ?int $pon, string $now): ?string;
    /** Atomico: upsert de alertas abertos e fechamento dos IDs indicados. */
    public function applyAlerts(int $deviceId, array $upserts, array $closeIds, string $now): void;
}
