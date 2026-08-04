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
$releasePreference = (string) ($_SESSION['release_channel_preference'] ?? $releaseChannel);
$releasePreference = in_array($releasePreference, ['stable', 'beta'], true) ? $releasePreference : 'stable';
$access = is_array($user['access'] ?? null) ? $user['access'] : [];
$canUseBeta = !empty($access['can_use_beta'])
    || in_array($role, ['manager', 'gestor', 'admin', 'platform_admin', 'administrador'], true);
$stableDestination = rtrim(trim((string) ($releaseInfo['stable_base_url'] ?? '')), '/');
$betaDestination = rtrim(trim((string) ($releaseInfo['beta_base_url'] ?? '')), '/');
$separateReleaseTargets = $stableDestination !== '' && $betaDestination !== '' && $stableDestination !== $betaDestination;
$selectedReleaseLabel = $releasePreference === 'beta' ? 'Beta' : 'Stable';
$releaseId = trim((string) ($releaseInfo['id'] ?? ''));
$releaseCommit = trim((string) ($releaseInfo['commit'] ?? ''));
$releaseBuildLabel = $releaseId !== '' ? $releaseId : ($releaseCommit !== '' ? substr($releaseCommit, 0, 12) : 'não informado');
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
                    <label for="release-channel">Canal</label>
                    <select id="release-channel" name="release_channel" aria-label="Canal de versão">
                        <option value="stable"<?= $releasePreference === 'stable' ? ' selected' : ''; ?>>Stable</option>
                        <?php if ($canUseBeta): ?>
                            <option value="beta"<?= $releasePreference === 'beta' ? ' selected' : ''; ?>>Beta</option>
                        <?php endif; ?>
                    </select>
                    <button class="button button--ghost button--small" type="submit">Alternar</button>
                </form>
                <small class="release-channel-state" data-release-build-state data-shared-build="<?= $separateReleaseTargets ? '0' : '1'; ?>">
                    Canal selecionado: <?= htmlspecialchars($selectedReleaseLabel, ENT_QUOTES, 'UTF-8'); ?>.
                    Build: <?= htmlspecialchars($releaseBuildLabel, ENT_QUOTES, 'UTF-8'); ?>.
                    <?= $separateReleaseTargets ? 'A troca usa o destino configurado para cada canal.' : 'Beta ainda utiliza a mesma build da Stable neste ambiente.'; ?>
                </small>
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
