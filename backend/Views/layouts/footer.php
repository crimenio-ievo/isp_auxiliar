<?php

declare(strict_types=1);

$releaseInfo = defined('APP_RELEASE_INFO') && is_array(APP_RELEASE_INFO) ? APP_RELEASE_INFO : [];
$channel = (string) ($releaseInfo['channel'] ?? 'stable') === 'beta' ? 'Beta' : 'Stable';
$releaseId = trim((string) ($releaseInfo['id'] ?? '')) ?: 'release não informada';
$commit = trim((string) ($releaseInfo['commit'] ?? ''));
$commit = $commit !== '' ? substr($commit, 0, 7) : 'sem-hash';
?>
<footer class="footer">
    <p>ISP Auxiliar · suporte ao cadastro, aceite e acompanhamento operacional.</p>
    <p class="footer__version">
        <?= htmlspecialchars($channel, ENT_QUOTES, 'UTF-8'); ?> · <?= htmlspecialchars($releaseId, ENT_QUOTES, 'UTF-8'); ?> · <?= htmlspecialchars($commit, ENT_QUOTES, 'UTF-8'); ?>
    </p>
</footer>
