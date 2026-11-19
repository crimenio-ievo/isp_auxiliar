<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Flags por provedor para os modulos de rede: `map` (ISP Map: /mapa, /api/mapa*) e `net` (telemetria e cadastro de rede:
 * /rede-*, /api/rede-*). Ordem de decisao para cada flag:
 *   1. provider_settings `feature.map` / `feature.net` do provedor ("1/0", "true/false", "on/off", "sim/nao");
 *   2. variavel de ambiente FEATURE_MAP / FEATURE_NET (mesmos valores);
 *   3. padrao a prova de falha: LIGADO em ambiente de teste e DESLIGADO em producao (APP_ENVIRONMENT=production, ver Environment).
 * Desligado: menu oculto, paginas 404, APIs 403 (ver Application). Os dados e as tabelas nao sao tocados.
 */
final class Features
{
    public const NAMES = ['map', 'net'];

    /** @var array<string, string> */
    private static array $settings = [];
    /** @var array<string, bool>|null */
    private static ?array $overrides = null;

    /** @param array<string, mixed> $providerSettings provider_settings do provedor atual (chave => valor) */
    public static function configure(array $providerSettings): void
    {
        self::$settings = [];
        foreach (self::NAMES as $name) {
            if (isset($providerSettings['feature.' . $name])) {
                self::$settings[$name] = (string) $providerSettings['feature.' . $name];
            }
        }
    }

    /** So para testes: forca o estado das flags (null desfaz). @param array<string,bool>|null $map */
    public static function override(?array $map): void
    {
        self::$overrides = $map;
    }

    public static function enabled(string $name): bool
    {
        if (self::$overrides !== null && array_key_exists($name, self::$overrides)) {
            return self::$overrides[$name];
        }

        return self::resolve(
            self::$settings[$name] ?? null,
            Env::get('FEATURE_' . strtoupper($name)),
            Environment::isProduction()
        );
    }

    public static function resolve(?string $setting, ?string $env, bool $production): bool
    {
        foreach ([$setting, $env] as $raw) {
            $value = self::parse($raw);
            if ($value !== null) {
                return $value;
            }
        }

        return !$production;
    }

    private static function parse(?string $raw): ?bool
    {
        $v = strtolower(trim((string) $raw));
        if (in_array($v, ['1', 'true', 'on', 'yes', 'sim', 'ligado'], true)) {
            return true;
        }
        if (in_array($v, ['0', 'false', 'off', 'no', 'nao', 'não', 'desligado'], true)) {
            return false;
        }

        return null;
    }

    /** Qual flag governa o caminho (ja sem o prefixo da instalacao)? null = nenhuma. */
    public static function featureForPath(string $path): ?string
    {
        if ($path === '/mapa' || str_starts_with($path, '/mapa/') || $path === '/api/mapa' || str_starts_with($path, '/api/mapa/')) {
            return 'map';
        }
        if (str_starts_with($path, '/rede-') || str_starts_with($path, '/api/rede-')) {
            return 'net';
        }

        return null;
    }
}
