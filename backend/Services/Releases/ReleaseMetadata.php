<?php

declare(strict_types=1);

namespace App\Services\Releases;

final class ReleaseMetadata
{
    private const MANIFEST = '.release-manifest';
    private const MAX_MANIFEST_BYTES = 8192;

    public static function resolve(string $rootPath, array $environment, array $legacy = []): array
    {
        $manifest = self::manifest($rootPath);
        $releaseId = self::releaseId($environment['release_id'] ?? '')
            ?? self::releaseId($manifest['tag'] ?? '')
            ?? self::releaseId($legacy['app_version'] ?? '')
            ?? 'versão não informada';
        $commit = self::commit($environment['commit'] ?? '') ?? self::commit($manifest['commit'] ?? '');
        $buildDate = self::date($environment['build_date'] ?? '')
            ?? self::date($manifest['built_at'] ?? '')
            ?? self::legacyDate($legacy['build_date'] ?? '');
        [$name, $label] = self::environment($environment['environment'] ?? ($manifest['environment'] ?? ''));

        return [
            'environment' => $name,
            'label' => $label,
            'release_id' => $releaseId,
            'commit' => $commit ?? '',
            'commit_short' => $commit === null ? '' : substr($commit, 0, 8),
            'build_date' => $buildDate ?? '',
        ];
    }

    private static function manifest(string $rootPath): array
    {
        $path = rtrim($rootPath, '/') . '/' . self::MANIFEST;
        if (!is_file($path) || !is_readable($path) || (int) @filesize($path) > self::MAX_MANIFEST_BYTES) {
            return [];
        }

        $content = @file_get_contents($path);
        if (!is_string($content)) {
            return [];
        }

        $allowed = ['tag' => true, 'commit' => true, 'built_at' => true, 'environment' => true];
        $values = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if (!isset($allowed[$key]) || isset($values[$key])) {
                continue;
            }
            $values[$key] = trim($value);
        }

        return $values;
    }

    private static function releaseId(mixed $value): ?string
    {
        $value = trim((string) $value);
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $value) === 1 ? $value : null;
    }

    private static function commit(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));
        return preg_match('/^[a-f0-9]{40}$/', $value) === 1 ? $value : null;
    }

    private static function date(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
        return $date->format('c') === $value || $date->format('Y-m-d\\TH:i:sP') === $value
            ? $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d')
            : null;
    }

    private static function legacyDate(mixed $value): ?string
    {
        $value = trim((string) $value);
        return preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value) === 1 ? $value : null;
    }

    private static function environment(mixed $value): array
    {
        $value = strtolower(trim((string) $value));
        return match ($value) {
            'production', 'prod' => ['production', 'Produção'],
            'homologation', 'homologacao', 'staging', 'test', 'testing' => ['homologation', 'Homologação'],
            'local', 'dev', 'development' => ['local', 'Ambiente local'],
            default => preg_match('/^[a-z0-9_-]{1,32}$/', $value) === 1
                ? [$value, ucfirst(str_replace(['_', '-'], ' ', $value))]
                : ['local', 'Ambiente local'],
        };
    }
}
