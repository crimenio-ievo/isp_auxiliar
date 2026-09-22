<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';

use App\Services\Releases\ReleaseMetadata;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

$root = sys_get_temp_dir() . '/isp-release-metadata-' . bin2hex(random_bytes(6));
if (!mkdir($root, 0700, true) && !is_dir($root)) {
    throw new RuntimeException('Não foi possível criar sandbox de metadados.');
}

try {
    file_put_contents($root . '/.release-manifest', implode("\n", [
        'tag=pre-maps-v0-2026-09-22-r3',
        'commit=1234567890abcdef1234567890abcdef12345678',
        'built_at=2026-09-22T12:00:00+00:00',
        'environment=production',
        '',
    ]));
    $production = ReleaseMetadata::resolve($root, ['environment' => 'production'], []);
    $assert($production['label'] === 'Produção', 'Produção não foi identificada.');
    $assert($production['release_id'] === 'pre-maps-v0-2026-09-22-r3', 'Manifesto válido não forneceu tag.');
    $assert($production['commit_short'] === '12345678', 'Commit válido não foi abreviado.');
    $assert($production['build_date'] === '2026-09-22', 'Data UTC do manifesto não foi normalizada.');

    $homologation = ReleaseMetadata::resolve($root, ['environment' => 'homologation'], []);
    $assert($homologation['label'] === 'Homologação', 'Homologação não foi identificada.');

    @unlink($root . '/.release-manifest');
    $legacy = ReleaseMetadata::resolve($root, ['environment' => 'local'], ['app_version' => '0.1.0', 'build_date' => '2026-04-30']);
    $assert($legacy['release_id'] === '0.1.0' && $legacy['build_date'] === '2026-04-30', 'Fallback legado falhou sem manifesto.');

    file_put_contents($root . '/.release-manifest', "tag=../../invalida\ncommit=short\nbuilt_at=ontem\n");
    $invalid = ReleaseMetadata::resolve($root, [], []);
    $assert($invalid['release_id'] === 'versão não informada', 'Manifesto inválido foi aceito.');
    $assert($invalid['commit'] === '' && $invalid['build_date'] === '', 'Metadados inválidos foram expostos.');

    $source = file_get_contents(dirname(__DIR__, 2) . '/backend/Services/Releases/ReleaseMetadata.php');
    $assert(is_string($source) && !preg_match('/\\b(shell_exec|exec|system|passthru|proc_open)\\s*\\(/', $source), 'Resolver executa shell.');
    $header = (string) file_get_contents(dirname(__DIR__, 2) . '/backend/Views/layouts/header.php');
    $footer = (string) file_get_contents(dirname(__DIR__, 2) . '/backend/Views/layouts/footer.php');
    $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/backend/routes.php');
    $assert(!str_contains($header, 'canal-versao') && !str_contains($header, 'Stable') && !str_contains($header, 'Beta'), 'Cabeçalho ainda expõe canais.');
    $assert(!str_contains($footer, 'sem-hash') && str_contains($footer, 'htmlspecialchars'), 'Rodapé não usa fallback seguro.');
    $assert(!str_contains($routes, '/canal-versao'), 'Rota de canal continua exposta.');
} finally {
    @unlink($root . '/.release-manifest');
    @rmdir($root);
}

echo "ReleaseMetadataSmoke: {$checks} checks passed; configuração hermética.\n";
