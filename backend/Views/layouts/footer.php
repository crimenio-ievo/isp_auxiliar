<?php

declare(strict_types=1);

if (($layoutMode ?? 'app') === 'guest') {
    return;
}

$releaseInfo = defined('APP_RELEASE_INFO') && is_array(APP_RELEASE_INFO) ? APP_RELEASE_INFO : [];
$environment = trim((string) ($releaseInfo['label'] ?? 'Ambiente local'));
$releaseId = trim((string) ($releaseInfo['release_id'] ?? '')) ?: 'versão não informada';
$commit = trim((string) ($releaseInfo['commit_short'] ?? ''));
$buildDate = trim((string) ($releaseInfo['build_date'] ?? ''));
$metadata = array_filter([$environment, $releaseId, $commit, $buildDate], static fn (string $value): bool => $value !== '');
?>
<footer class="footer">
    <p>ISP Auxiliar · suporte ao cadastro, aceite e acompanhamento operacional.</p>
    <p class="footer__version">
        <?= htmlspecialchars(implode(' · ', $metadata), ENT_QUOTES, 'UTF-8'); ?>
    </p>
</footer>
