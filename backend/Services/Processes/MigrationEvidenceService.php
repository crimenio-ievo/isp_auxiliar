<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Core\Config;

final class MigrationEvidenceService
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    public function __construct(private Config $config, private bool $allowLocalFilesForTesting = false)
    {
    }

    public function store(int $processId, string $stepKey, array $files, array $operator): array
    {
        if ($processId <= 0 || !preg_match('/^[a-z0-9_]{1,80}$/', $stepKey)) {
            throw new \InvalidArgumentException('Processo ou etapa inválida para anexar evidências.');
        }
        $normalized = $this->normalizeFiles($files);
        if ($normalized === []) {
            return [];
        }

        $maxBytes = max(1024, (int) $this->config->get('app.migration_evidence.max_bytes', 8 * 1024 * 1024));
        $maxFiles = max(1, min(12, (int) $this->config->get('app.migration_evidence.max_files', 8)));
        if (count($normalized) > $maxFiles) {
            throw new \RuntimeException('Envie no máximo ' . $maxFiles . ' evidências por vez.');
        }

        $storageRoot = rtrim((string) $this->config->get('paths.storage', dirname(__DIR__, 3) . '/storage'), '/');
        $directory = $storageRoot . '/uploads/processes/' . $processId . '/' . $stepKey;
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Não foi possível preparar o armazenamento protegido das evidências.');
        }

        $stored = [];
        foreach ($normalized as $file) {
            $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_OK) {
                throw new \RuntimeException('Uma evidência não pôde ser recebida (código ' . $error . ').');
            }
            $temporaryPath = (string) ($file['tmp_name'] ?? '');
            $size = (int) ($file['size'] ?? 0);
            if ($size <= 0 || $size > $maxBytes || !is_file($temporaryPath)) {
                throw new \RuntimeException('Evidência vazia ou acima do limite configurado.');
            }
            if (!$this->allowLocalFilesForTesting && !is_uploaded_file($temporaryPath)) {
                throw new \RuntimeException('Origem do upload de evidência inválida.');
            }

            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporaryPath) ?: '';
            if (!isset(self::MIME_EXTENSIONS[$mime])) {
                throw new \RuntimeException('Formato de evidência não permitido. Use JPG, PNG, WebP ou PDF.');
            }
            $id = bin2hex(random_bytes(12));
            $filename = $id . '.' . self::MIME_EXTENSIONS[$mime];
            $destination = $directory . '/' . $filename;
            $moved = $this->allowLocalFilesForTesting
                ? rename($temporaryPath, $destination)
                : move_uploaded_file($temporaryPath, $destination);
            if (!$moved) {
                throw new \RuntimeException('Não foi possível armazenar a evidência.');
            }
            @chmod($destination, 0660);
            $stored[] = [
                'id' => $id,
                'original_name' => $this->safeOriginalName((string) ($file['name'] ?? 'evidencia')),
                'storage_path' => 'storage/uploads/processes/' . $processId . '/' . $stepKey . '/' . $filename,
                'mime_type' => $mime,
                'size_bytes' => $size,
                'sha256' => hash_file('sha256', $destination) ?: '',
                'process_id' => $processId,
                'step_key' => $stepKey,
                'created_by' => (string) ($operator['login'] ?? ''),
                'created_at' => date('Y-m-d H:i:s'),
            ];
        }

        return $stored;
    }

    public function resolve(array $evidence, string $evidenceId): ?array
    {
        foreach ((array) ($evidence['files'] ?? []) as $file) {
            if (is_array($file) && hash_equals((string) ($file['id'] ?? ''), $evidenceId)) {
                $storageRoot = rtrim((string) $this->config->get('paths.storage', dirname(__DIR__, 3) . '/storage'), '/');
                $root = realpath($storageRoot . '/uploads/processes');
                $relativePath = preg_replace('#^storage/#', '', ltrim((string) ($file['storage_path'] ?? ''), '/')) ?? '';
                $path = realpath($storageRoot . '/' . $relativePath);
                if ($root !== false && $path !== false && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path)) {
                    return array_merge($file, ['absolute_path' => $path]);
                }
            }
        }

        return null;
    }

    public function remove(array $evidence, string $evidenceId): array
    {
        $remaining = [];
        foreach ((array) ($evidence['files'] ?? []) as $file) {
            if (!is_array($file) || !hash_equals((string) ($file['id'] ?? ''), $evidenceId)) {
                $remaining[] = $file;
                continue;
            }
            $resolved = $this->resolve(['files' => [$file]], $evidenceId);
            if (is_array($resolved)) {
                @unlink((string) $resolved['absolute_path']);
            }
        }
        $evidence['files'] = $remaining;

        return $evidence;
    }

    private function normalizeFiles(array $files): array
    {
        $names = $files['name'] ?? [];
        if (!is_array($names)) {
            return trim((string) $names) === '' ? [] : [$files];
        }
        $normalized = [];
        foreach ($names as $index => $name) {
            if (trim((string) $name) === '') {
                continue;
            }
            $normalized[] = [
                'name' => $name,
                'tmp_name' => $files['tmp_name'][$index] ?? '',
                'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$index] ?? 0,
            ];
        }

        return $normalized;
    }

    private function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name) ?? 'evidencia';

        return mb_substr(trim($name), 0, 180) ?: 'evidencia';
    }
}
