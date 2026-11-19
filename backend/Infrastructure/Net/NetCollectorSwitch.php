<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

use App\Core\Env;
use App\Core\Environment;

/**
 * Chave POR SERVIDOR da coleta de telemetria (NET_COLLECTOR_ENABLED no .env).
 *
 * Por que .env e não provider_settings: provider_settings vive no BANCO, e o banco da produção será copiado para o teste;
 * uma chave no banco ligaria (ou desligaria) a coleta no servidor errado depois de um restore. O .env é do servidor e não
 * entra em dump nem em deploy de código.
 *
 * Valores: 1/true/on/yes/sim/ligado = ligada; 0/false/off/no/nao/desligado = desligada. Ausente ou inválido:
 * ligada fora da produção (o teste continua coletando sem mexer no .env) e DESLIGADA em produção
 * (APP_ENVIRONMENT=production), que precisa ligar de propósito, para nunca haver duas coletas por acidente.
 * Quando desligada, nenhum script contata equipamento (net_tick, net_collect) nem "Testar conexão".
 */
final class NetCollectorSwitch
{
    public const MESSAGE = 'coleta desativada neste servidor';

    public static function enabled(?string $value = null, ?bool $production = null): bool
    {
        $v = strtolower(trim((string) ($value ?? Env::get('NET_COLLECTOR_ENABLED'))));
        if (in_array($v, ['1', 'true', 'on', 'yes', 'sim', 'ligado'], true)) {
            return true;
        }
        if (in_array($v, ['0', 'false', 'off', 'no', 'nao', 'não', 'desligado'], true)) {
            return false;
        }

        return !($production ?? Environment::isProduction());
    }

    /**
     * Registra "coleta desativada neste servidor" no log do dia (net_tick-AAAA-MM-DD.log), no máximo uma linha por hora
     * (o cron roda a cada minuto). Nunca lança: log indisponível não pode quebrar o cron.
     *
     * @return bool true se gravou uma linha
     */
    public static function logDisabled(string $logDirectory, ?string $now = null): bool
    {
        $now ??= date('Y-m-d H:i:s');
        try {
            if (!is_dir($logDirectory) && !@mkdir($logDirectory, 0750, true) && !is_dir($logDirectory)) {
                return false;
            }
            $path = $logDirectory . '/net_tick-' . substr($now, 0, 10) . '.log';
            if (is_file($path)) {
                $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                for ($i = count($lines) - 1; $i >= 0 && $i >= count($lines) - 5; $i--) {
                    $row = json_decode($lines[$i], true);
                    if (is_array($row) && ($row['result'] ?? '') === 'collector_disabled' && isset($row['started_at']) && strtotime($now) - strtotime((string) $row['started_at']) < 3600) {
                        return false;
                    }
                }
            }
            $line = json_encode(['result' => 'collector_disabled', 'message' => self::MESSAGE, 'started_at' => $now], JSON_UNESCAPED_UNICODE);

            return $line !== false && @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX) !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
