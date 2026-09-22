<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

$header = (string) file_get_contents($root . '/backend/Views/layouts/header.php');
$layout = (string) file_get_contents($root . '/backend/Views/layouts/app.php');
$css = (string) file_get_contents($root . '/public/assets/css/app.css');

$assert(str_contains($header, "releaseInfo['label']"), 'Cabeçalho não usa o ambiente resolvido.');
$assert(!str_contains($header, 'canal-versao'), 'Cabeçalho ainda contém troca de canal.');
$assert(!str_contains($header, 'Stable') && !str_contains($header, 'Beta'), 'Cabeçalho ainda expõe nomenclatura de canais.');
$assert(!str_contains($layout, 'beta-environment-banner'), 'Layout ainda exibe banner Beta.');
$assert(!str_contains($css, '.release-channel-selector'), 'CSS ainda contém seletor de canais.');

echo "ReleaseChannelHeaderSmoke: {$checks} checks passed; interface sem canais concorrentes.\n";
