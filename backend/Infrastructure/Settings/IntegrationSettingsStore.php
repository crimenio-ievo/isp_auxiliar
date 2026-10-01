<?php

declare(strict_types=1);

namespace App\Infrastructure\Settings;

use App\Infrastructure\Local\LocalRepository;

/**
 * Guarda a configuração de integrações (Email, Evotrix) em provider_settings,
 * a mesma tabela por provedor já usada por MkAuth/Provedor, em vez do arquivo
 * global storage/contracts/config.json.
 *
 * Cada campo vira uma linha `<seção>.<campo>` (ex.: `email.smtp_host`,
 * `evotrix.token`). Segredos (password/token) são marcados is_secret pelo
 * próprio LocalRepository::saveProviderSettings().
 */
class IntegrationSettingsStore
{
    public const SECTIONS = ['email', 'evotrix'];

    public function __construct(private ?LocalRepository $localRepository = null)
    {
    }

    /**
     * Valores salvos para a seção, somente campos que existem em $defaults,
     * convertidos para o mesmo tipo do valor padrão (bool/int/string).
     *
     * @param array<string, string> $providerSettings linhas de provider_settings (chave => valor)
     * @param array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    public static function extract(array $providerSettings, string $section, array $defaults): array
    {
        $values = [];
        foreach ($defaults as $field => $default) {
            $key = $section . '.' . $field;
            if (!array_key_exists($key, $providerSettings)) {
                continue;
            }
            $raw = (string) $providerSettings[$key];
            $values[$field] = match (true) {
                is_bool($default) => in_array(strtolower(trim($raw)), ['1', 'true', 'on', 'yes', 'sim'], true),
                is_int($default) => (int) $raw,
                default => $raw,
            };
        }

        return $values;
    }

    /**
     * Aplica sobre a configuração base (env + arquivo legado) os valores de
     * provider_settings e reaplica a trava de ambientes não produtivos.
     *
     * @param array<string, mixed> $base
     * @param array<string, string> $providerSettings
     * @return array<string, mixed>
     */
    public static function overlay(string $section, array $base, array $providerSettings, string $appEnv): array
    {
        $merged = array_replace($base, self::extract($providerSettings, $section, $base));

        if (strtolower(trim($appEnv)) !== 'production') {
            $merged['dry_run'] = true;
            if ($section === 'email') {
                $merged['allow_only_test_email'] = true;
            }
            if ($section === 'evotrix') {
                $merged['allow_only_test_phone'] = true;
            }
        }

        return $merged;
    }

    /** @param array<string, mixed> $values */
    public function save(string $section, array $values): void
    {
        if (!in_array($section, self::SECTIONS, true)) {
            throw new \InvalidArgumentException('Seção de integração desconhecida: ' . $section);
        }

        $rows = [];
        foreach ($values as $field => $value) {
            $rows[$section . '.' . $field] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }
        $this->persist($rows);
    }

    /** @param array<string, string> $rows */
    protected function persist(array $rows): void
    {
        if ($this->localRepository === null || $this->localRepository->currentProviderId() === null) {
            throw new \RuntimeException('Banco local indisponível: configuração de integração não pôde ser salva.');
        }
        $this->localRepository->saveProviderSettings($rows);
    }
}
