<?php

declare(strict_types=1);

namespace App\Services\Contracts;

/**
 * Armazena evidências do aceite fora da pasta pública, com validação estrita.
 */
final class AcceptanceEvidenceService
{
    public function saveSignature(int $acceptanceId, string $dataUrl, string $origin = 'local'): string
    {
        if ($acceptanceId <= 0 || preg_match('#^data:image/(png|jpeg);base64,(.+)$#i', trim($dataUrl), $matches) !== 1) {
            throw new \RuntimeException('A assinatura informada não é válida.');
        }

        $binary = base64_decode($matches[2], true);
        if ($binary === false || strlen($binary) > 5 * 1024 * 1024) {
            throw new \RuntimeException('A assinatura excede o limite ou não pôde ser decodificada.');
        }
        $image = @getimagesizefromstring($binary);
        if (!is_array($image)
            || !in_array((int) ($image[2] ?? 0), [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)
            || (int) ($image[0] ?? 0) < 1 || (int) ($image[1] ?? 0) < 1
            || (int) ($image[0] ?? 0) > 8000 || (int) ($image[1] ?? 0) > 8000
        ) {
            throw new \RuntimeException('A assinatura deve ser uma imagem PNG ou JPEG válida.');
        }

        $directory = $this->storageDirectory();
        $extension = (int) ($image[2] ?? 0) === IMAGETYPE_JPEG ? 'jpg' : 'png';
        $fileName = sprintf(
            'signature_%d_%s_%s_%s.%s',
            $acceptanceId,
            preg_replace('/[^a-z0-9_-]/i', '', $origin) ?: 'local',
            date('Ymd_His'),
            bin2hex(random_bytes(6)),
            $extension
        );
        $path = $directory . '/' . $fileName;
        if (file_put_contents($path, $binary, LOCK_EX) === false) {
            throw new \RuntimeException('Não foi possível armazenar a assinatura.');
        }

        return 'storage/contracts/acceptances/' . $fileName;
    }

    public function saveEvidence(int $acceptanceId, array $evidence, string $kind = 'local_signature'): string
    {
        if ($acceptanceId <= 0) {
            throw new \RuntimeException('Aceite inválido para armazenar evidência.');
        }
        $safeKind = preg_replace('/[^a-z0-9_-]/i', '', $kind) ?: 'evidence';
        $fileName = sprintf('%s_%d_%s_%s.json', $safeKind, $acceptanceId, date('Ymd_His'), bin2hex(random_bytes(6)));
        $encoded = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->storageDirectory() . '/' . $fileName, $encoded, LOCK_EX) === false) {
            throw new \RuntimeException('Não foi possível armazenar a evidência do aceite.');
        }

        return 'storage/contracts/acceptances/' . $fileName;
    }

    private function storageDirectory(): string
    {
        $directory = dirname(__DIR__, 3) . '/storage/contracts/acceptances';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Storage de aceites indisponível.');
        }

        return $directory;
    }
}
