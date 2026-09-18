<?php

declare(strict_types=1);

use App\Services\Contracts\AcceptanceEvidenceService;

require_once dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';

/**
 * Regressão: AcceptanceController::saveSignatureFile duplicava
 * AcceptanceEvidenceService::saveSignature (já usado por ClientController) e
 * sempre gravava a assinatura com extensão .png, mesmo quando o formato
 * detectado era JPEG. Este teste nunca escreve em storage/contracts/
 * acceptances real: usa a propriedade de override adicionada só para testes,
 * apontando para um diretório temporário.
 */

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

$temporaryRoot = sys_get_temp_dir() . '/isp_auxiliar_signature_' . bin2hex(random_bytes(6));

$cleanup = static function () use ($temporaryRoot): void {
    if (!is_dir($temporaryRoot)) {
        return;
    }
    foreach (glob($temporaryRoot . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($temporaryRoot);
};

$buildDataUrl = static function (string $mime, string $binary): string {
    return 'data:' . $mime . ';base64,' . base64_encode($binary);
};

try {
    $assert(!is_dir($temporaryRoot), 'Pré-condição inválida: diretório temporário já existia.');
    $assert(!str_contains($temporaryRoot, '/storage/contracts/acceptances'), 'Diretório de teste aponta acidentalmente para o storage real.');

    $service = new AcceptanceEvidenceService();
    $serviceReflection = new ReflectionClass(AcceptanceEvidenceService::class);
    $serviceReflection->getProperty('storageDirectoryOverride')->setValue($service, $temporaryRoot);

    // 1. PNG válido continua sendo salvo como .png.
    $pngImage = imagecreatetruecolor(4, 4);
    ob_start();
    imagepng($pngImage);
    $pngBinary = (string) ob_get_clean();
    imagedestroy($pngImage);

    $pngPath = $service->saveSignature(101, $buildDataUrl('image/png', $pngBinary), 'remote');
    $assert(str_ends_with($pngPath, '.png'), 'Assinatura PNG não foi salva com extensão .png.');
    $absolutePngPath = $temporaryRoot . '/' . basename($pngPath);
    $assert(is_file($absolutePngPath), 'Arquivo PNG não foi gravado no diretório de teste.');
    $assert(file_get_contents($absolutePngPath) === $pngBinary, 'Conteúdo do PNG salvo não corresponde ao original.');

    // 2. JPEG válido precisa ser salvo como .jpg — este é o bug confirmado:
    //    antes da correção, o código duplicado gravava SEMPRE com .png.
    $jpegImage = imagecreatetruecolor(4, 4);
    ob_start();
    imagejpeg($jpegImage, null, 90);
    $jpegBinary = (string) ob_get_clean();
    imagedestroy($jpegImage);

    $jpegPath = $service->saveSignature(102, $buildDataUrl('image/jpeg', $jpegBinary), 'remote');
    $assert(str_ends_with($jpegPath, '.jpg'), 'Assinatura JPEG foi salva com a extensão errada (bug da duplicação antiga: sempre .png).');
    $assert(!str_ends_with($jpegPath, '.png'), 'Assinatura JPEG não pode terminar em .png.');
    $absoluteJpegPath = $temporaryRoot . '/' . basename($jpegPath);
    $assert(is_file($absoluteJpegPath), 'Arquivo JPEG não foi gravado no diretório de teste.');

    // 3. Formato inválido/rejeitado: bytes que não formam PNG/JPEG válido,
    //    mesmo com prefixo MIME de imagem, devem ser rejeitados com exceção,
    //    e nenhum arquivo deve ser criado para essa tentativa.
    $filesBefore = glob($temporaryRoot . '/*') ?: [];
    $rejected = false;
    try {
        $service->saveSignature(103, $buildDataUrl('image/png', 'isto-nao-e-uma-imagem'), 'remote');
    } catch (\RuntimeException $exception) {
        $rejected = true;
    }
    $assert($rejected, 'Conteúdo inválido não foi rejeitado com RuntimeException.');
    $filesAfter = glob($temporaryRoot . '/*') ?: [];
    $assert(count($filesAfter) === count($filesBefore), 'Tentativa inválida gravou arquivo mesmo tendo sido rejeitada.');

    // 4. Formato de imagem não suportado (GIF) também deve ser rejeitado.
    $gifImage = imagecreatetruecolor(4, 4);
    ob_start();
    imagegif($gifImage);
    $gifBinary = (string) ob_get_clean();
    imagedestroy($gifImage);
    $rejectedGif = false;
    try {
        $service->saveSignature(104, $buildDataUrl('image/gif', $gifBinary), 'remote');
    } catch (\RuntimeException $exception) {
        $rejectedGif = true;
    }
    $assert($rejectedGif, 'Imagem GIF (formato não suportado) não foi rejeitada.');

    // 5. saveEvidence continua funcionando pelo mesmo serviço (usado pelo
    //    mesmo fluxo de aceite público para gravar o JSON de evidência).
    $evidencePath = $service->saveEvidence(101, ['event' => 'teste_regressao'], 'acceptance');
    $assert(str_ends_with($evidencePath, '.json'), 'Evidência não foi salva como JSON.');
    $assert(str_starts_with(basename($evidencePath), 'acceptance_101_'), 'Nome do arquivo de evidência não preserva o prefixo esperado.');

    // 6. Confere que AcceptanceController realmente delega ao serviço
    //    compartilhado, e que a duplicação antiga (com o bug de extensão)
    //    foi removida do controller.
    $controllerSource = (string) file_get_contents(dirname(__DIR__, 2) . '/backend/Controllers/AcceptanceController.php');
    $assert(str_contains($controllerSource, '$this->acceptanceEvidenceService->saveSignature('), 'AcceptanceController não delega mais para AcceptanceEvidenceService::saveSignature.');
    $assert(str_contains($controllerSource, '$this->acceptanceEvidenceService->saveEvidence('), 'AcceptanceController não delega mais para AcceptanceEvidenceService::saveEvidence.');
    $assert(!str_contains($controllerSource, 'private function saveSignatureFile'), 'Duplicação antiga saveSignatureFile ainda existe no controller.');
    $assert(!str_contains($controllerSource, 'private function decodeDataUrl'), 'Duplicação antiga decodeDataUrl ainda existe no controller.');
    $assert(!str_contains($controllerSource, 'private function saveEvidenceJson'), 'Duplicação antiga saveEvidenceJson ainda existe no controller.');
    $assert(!str_contains($controllerSource, "'.png';"), 'Controller ainda concatena extensão .png fixa (possível resíduo do bug antigo).');

    echo "AcceptanceSignatureFormatRegression: {$checks} checks passed\n";
} finally {
    $cleanup();
}
