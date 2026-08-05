<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Url;

$layoutMode = $layoutMode ?? 'app';
$user = $user ?? ['name' => 'Convidado', 'role' => 'Sem sessao'];
$role = strtolower((string) ($user['role'] ?? ''));
$roleLabel = match ($role) {
    'platform_admin' => 'Administrador da plataforma',
    'manager' => 'Gestor',
    'technician' => 'Tecnico',
    default => (string) ($user['role'] ?? 'Sem sessao'),
};
$releaseInfo = defined('APP_RELEASE_INFO') && is_array(APP_RELEASE_INFO) ? APP_RELEASE_INFO : [];
$releaseChannel = (string) ($releaseInfo['channel'] ?? 'stable') === 'beta' ? 'beta' : 'stable';
$access = is_array($user['access'] ?? null) ? $user['access'] : [];
$canUseBeta = !empty($access['can_use_beta'])
    || in_array($role, ['manager', 'gestor', 'admin', 'platform_admin', 'administrador'], true);
$stableDestination = rtrim(trim((string) ($releaseInfo['stable_base_url'] ?? '')), '/');
$betaDestination = rtrim(trim((string) ($releaseInfo['beta_base_url'] ?? '')), '/');
$releaseLabel = $releaseChannel === 'beta' ? 'Beta' : 'Stable';
$targetChannel = $releaseChannel === 'beta' ? 'stable' : 'beta';
$targetLabel = $targetChannel === 'beta' ? 'Beta' : 'Stable';
$targetDestination = $targetChannel === 'beta' ? $betaDestination : $stableDestination;
$canOpenTarget = $targetDestination !== '' && ($targetChannel !== 'beta' || $canUseBeta);
$releaseId = trim((string) ($releaseInfo['id'] ?? ''));
$releaseCommit = trim((string) ($releaseInfo['commit'] ?? ''));
$releaseCommitShort = $releaseCommit !== '' ? substr($releaseCommit, 0, 7) : 'sem-hash';
?>
<header class="topbar<?= $layoutMode === 'guest' ? ' topbar--guest' : ''; ?>">
    <?php if ($layoutMode !== 'guest'): ?>
        <button class="menu-toggle" type="button" aria-label="Abrir menu" data-sidebar-toggle>
            <span></span>
            <span></span>
            <span></span>
        </button>
    <?php endif; ?>

    <div class="topbar__brand">
        <p class="topbar__eyebrow">Central operacional</p>
        <strong><?= htmlspecialchars($appName ?? 'ISP Auxiliar', ENT_QUOTES, 'UTF-8'); ?></strong>
    </div>

    <div class="topbar__actions">
        <?php if ($layoutMode !== 'guest'): ?>
            <div class="release-channel-control">
                <form class="release-channel-selector" method="post" action="<?= htmlspecialchars(Url::to('/canal-versao'), ENT_QUOTES, 'UTF-8'); ?>">
                    <?= Csrf::field('release_channel'); ?>
                    <span class="release-channel-current">Canal: <strong><?= htmlspecialchars($releaseLabel, ENT_QUOTES, 'UTF-8'); ?></strong></span>
                    <input type="hidden" name="release_channel" value="<?= htmlspecialchars($targetChannel, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if ($canOpenTarget): ?>
                        <button class="button button--ghost button--small" type="submit">Abrir <?= htmlspecialchars($targetLabel, ENT_QUOTES, 'UTF-8'); ?></button>
                    <?php endif; ?>
                </form>
                <small class="release-channel-state"><?= htmlspecialchars($releaseLabel, ENT_QUOTES, 'UTF-8'); ?> · <?= htmlspecialchars($releaseId !== '' ? $releaseId : 'release não informada', ENT_QUOTES, 'UTF-8'); ?> · <?= htmlspecialchars($releaseCommitShort, ENT_QUOTES, 'UTF-8'); ?></small>
            </div>
            <div class="topbar__user">
                <span class="avatar"><?= htmlspecialchars(substr((string) $user['name'], 0, 1), ENT_QUOTES, 'UTF-8'); ?></span>
                <div>
                    <strong><?= htmlspecialchars((string) $user['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <small><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?></small>
                </div>
            </div>
        <?php endif; ?>
    </div>
</header>
