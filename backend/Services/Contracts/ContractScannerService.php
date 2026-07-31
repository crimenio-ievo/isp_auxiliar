<?php

declare(strict_types=1);

namespace App\Services\Contracts;

final class ContractScannerService
{
    private const MAX_PAGES = 20;
    private const MAX_FILE_BYTES = 12_582_912;
    private const MAX_TOTAL_BYTES = 41_943_040;

    public function process(array $files, array $order, array $rotations, string $destination): array
    {
        $uploads = $this->normalizeUploads($files);
        if ($uploads === [] || count($uploads) > self::MAX_PAGES) {
            throw new \RuntimeException('Selecione entre 1 e ' . self::MAX_PAGES . ' páginas.');
        }
        $total = array_sum(array_column($uploads, 'size'));
        if ($total > self::MAX_TOTAL_BYTES) throw new \RuntimeException('O conjunto excede o limite de 40 MB.');

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        foreach ($uploads as &$upload) {
            if ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) throw new \RuntimeException('Upload incompleto ou inválido.');
            if ($upload['size'] < 1 || $upload['size'] > self::MAX_FILE_BYTES) throw new \RuntimeException('Uma página excede o limite de 12 MB.');
            $upload['mime'] = (string) $finfo->file($upload['tmp_name']);
            if (!in_array($upload['mime'], ['image/jpeg', 'image/png', 'application/pdf'], true)) throw new \RuntimeException('Apenas JPEG, PNG ou PDF verdadeiro são aceitos.');
        }
        unset($upload);

        $pdfUploads = array_values(array_filter($uploads, static fn (array $item): bool => $item['mime'] === 'application/pdf'));
        if ($pdfUploads !== []) {
            $pdfBytes = (string) file_get_contents($pdfUploads[0]['tmp_name']);
            if (count($uploads) !== 1 || !str_starts_with($pdfBytes, '%PDF-')) {
                throw new \RuntimeException('Envie um PDF isolado e válido, ou somente imagens.');
            }
            preg_match_all('/\/Type\s*\/Page\b/', $pdfBytes, $pageMatches);
            $pageCount = max(1, count($pageMatches[0] ?? []));
            if ($pageCount > self::MAX_PAGES) {
                throw new \RuntimeException('O PDF excede o limite de ' . self::MAX_PAGES . ' páginas.');
            }
            $this->ensureDirectory(dirname($destination));
            if (!move_uploaded_file($pdfUploads[0]['tmp_name'], $destination)) throw new \RuntimeException('Não foi possível armazenar o PDF.');
            return ['page_count' => $pageCount, 'original_name' => $pdfUploads[0]['name'], 'source_mimes' => ['application/pdf']];
        }

        $ordered = $this->applyOrder($uploads, $order);
        $temporaryJpegs = [];
        try {
            foreach ($ordered as $position => $upload) {
                $temporaryJpegs[] = $this->normalizeImage($upload, (int) ($rotations[$upload['index']] ?? 0), $position);
            }
            $this->ensureDirectory(dirname($destination));
            $this->writePdf($temporaryJpegs, $destination);
        } finally {
            foreach ($temporaryJpegs as $page) if (is_file($page['path'])) @unlink($page['path']);
        }

        return ['page_count' => count($ordered), 'original_name' => implode(', ', array_column($ordered, 'name')), 'source_mimes' => array_values(array_unique(array_column($ordered, 'mime')))];
    }

    private function normalizeUploads(array $files): array
    {
        $names = is_array($files['name'] ?? null) ? $files['name'] : [];
        $result = [];
        foreach ($names as $index => $name) {
            $result[] = ['index' => (int) $index, 'name' => basename((string) $name), 'tmp_name' => (string) ($files['tmp_name'][$index] ?? ''), 'size' => (int) ($files['size'][$index] ?? 0), 'error' => (int) ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE)];
        }
        return $result;
    }

    private function applyOrder(array $uploads, array $order): array
    {
        if ($order === []) return $uploads;
        $byIndex = [];
        foreach ($uploads as $upload) $byIndex[$upload['index']] = $upload;
        $result = [];
        foreach ($order as $index) if (isset($byIndex[(int) $index])) { $result[] = $byIndex[(int) $index]; unset($byIndex[(int) $index]); }
        if ($result === []) throw new \RuntimeException('Nenhuma página permaneceu após a revisão.');
        return $result;
    }

    private function normalizeImage(array $upload, int $rotation, int $position): array
    {
        $image = $upload['mime'] === 'image/png' ? @imagecreatefrompng($upload['tmp_name']) : @imagecreatefromjpeg($upload['tmp_name']);
        if (!$image) throw new \RuntimeException('Uma das páginas não é uma imagem legível.');
        $rotation = (($rotation % 360) + 360) % 360;
        if (in_array($rotation, [90, 180, 270], true)) {
            $rotated = imagerotate($image, 360 - $rotation, 0xffffff);
            imagedestroy($image);
            if (!$rotated) throw new \RuntimeException('Não foi possível girar uma página.');
            $image = $rotated;
        }
        $width = imagesx($image); $height = imagesy($image);
        $scale = min(1, 1800 / max(1, $width), 2400 / max(1, $height));
        if ($scale < 1) {
            $resized = imagecreatetruecolor((int) round($width * $scale), (int) round($height * $scale));
            imagefill($resized, 0, 0, imagecolorallocate($resized, 255, 255, 255));
            imagecopyresampled($resized, $image, 0, 0, 0, 0, imagesx($resized), imagesy($resized), $width, $height);
            imagedestroy($image); $image = $resized;
        }
        $path = tempnam(sys_get_temp_dir(), 'isp_scan_');
        if ($path === false || !imagejpeg($image, $path, 80)) { imagedestroy($image); throw new \RuntimeException('Falha ao padronizar uma página.'); }
        $page = ['path' => $path, 'width' => imagesx($image), 'height' => imagesy($image), 'position' => $position];
        imagedestroy($image);
        return $page;
    }

    private function writePdf(array $pages, string $destination): void
    {
        $objects = [];
        $pageIds = [];
        $nextId = 3;
        foreach ($pages as $page) { $pageIds[] = ['page' => $nextId++, 'content' => $nextId++, 'image' => $nextId++, 'data' => $page]; }
        $kids = implode(' ', array_map(static fn (array $ids): string => $ids['page'] . ' 0 R', $pageIds));
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($pageIds) . ' >>';
        foreach ($pageIds as $index => $ids) {
            $page = $ids['data']; $jpeg = (string) file_get_contents($page['path']);
            $pageWidth = 595.28; $pageHeight = 841.89; $scale = min(($pageWidth - 40) / $page['width'], ($pageHeight - 40) / $page['height']);
            $w = $page['width'] * $scale; $h = $page['height'] * $scale; $x = ($pageWidth - $w) / 2; $y = ($pageHeight - $h) / 2;
            $stream = sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im%d Do Q", $w, $h, $x, $y, $index + 1);
            $objects[$ids['page']] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /XObject << /Im%d %d 0 R >> >> /Contents %d 0 R >>', $pageWidth, $pageHeight, $index + 1, $ids['image'], $ids['content']);
            $objects[$ids['content']] = '<< /Length ' . strlen($stream) . ">>\nstream\n" . $stream . "\nendstream";
            $objects[$ids['image']] = sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n", $page['width'], $page['height'], strlen($jpeg)) . $jpeg . "\nendstream";
        }
        ksort($objects); $pdf = "%PDF-1.4\n"; $offsets = [0];
        foreach ($objects as $id => $body) { $offsets[$id] = strlen($pdf); $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n"; }
        $xref = strlen($pdf); $count = max(array_keys($objects)) + 1; $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        for ($id = 1; $id < $count; $id++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        if (file_put_contents($destination, $pdf, LOCK_EX) === false) throw new \RuntimeException('Não foi possível gerar o PDF final.');
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new \RuntimeException('Storage de contratos indisponível.');
    }
}
