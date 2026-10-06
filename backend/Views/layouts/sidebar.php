<?php

declare(strict_types=1);

use App\Core\Url;
use App\Core\AccessControl;

$currentPath = $currentPath ?? '/';
$access = is_array($user['access'] ?? null) ? $user['access'] : [];
$canManageSettings = AccessControl::can($access, 'configuracoes');
$canAccessContracts = AccessControl::can($access, 'contratos');
$canViewLogs = AccessControl::isGestor($access);
$userName = trim((string) ($user['name'] ?? '')) ?: 'Convidado';
$userRole = strtolower((string) ($user['role'] ?? ''));
$userRoleLabel = match ($userRole) {
    'admin', 'platform_admin' => 'Administrador',
    'manager' => 'Gestor',
    'technician' => 'Tecnico',
    default => (string) ($user['role'] ?? ''),
};
$logoutHref = Url::to('/logout');

/**
 * Ícones outline (estilo Feather, stroke, sem preenchimento).
 */
$navIcons = [
    'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9.5 21v-6h5v6"/>',
    'clientes' => '<circle cx="9" cy="8" r="3.3"/><path d="M2.5 20c0-3.6 2.9-6.2 6.5-6.2s6.5 2.6 6.5 6.2"/><path d="M15.8 8.3a3 3 0 1 1 3 3"/><path d="M15.2 13.9c2.6.5 4.3 2.4 4.3 6.1"/>',
    'contratos' => '<path d="M7 3h7l4 4v14H7z"/><path d="M14 3v4h4"/><path d="M9.3 13h5.4M9.3 16.5h5.4M9.3 9.5h2.4"/>',
    'processos' => '<path d="M12 3 3 8l9 5 9-5-9-5z"/><path d="M3 12l9 5 9-5"/><path d="M3 16l9 5 9-5"/>',
    'mais' => '<circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>',
    'mapa' => '<path d="M9 4 3 6.5V20l6-2.5 6 2.5 6-2.5V4l-6 2.5z"/><path d="M9 4v13.5M15 6.5V20"/>',
    'sair' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
    'logs' => '<path d="M5 4h14v16H5z"/><path d="M8.5 9h7M8.5 12.5h7M8.5 16h4"/>',
    'configuracoes' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
];

$navigationItems = [
    ['href' => '/dashboard', 'label' => 'Home', 'icon' => 'home', 'match' => ['/dashboard', '/']],
    ['href' => '/clientes', 'label' => 'Clientes', 'icon' => 'clientes', 'match' => ['/clientes']],
];

if ($canAccessContracts) {
    $navigationItems[] = ['href' => '/contratos', 'label' => 'Contratos', 'icon' => 'contratos', 'match' => ['/contratos']];
    $navigationItems[] = ['href' => '/processos', 'label' => 'Processos', 'icon' => 'processos', 'match' => ['/processos']];
}

if ($canManageSettings) {
    $navigationItems[] = ['href' => '/configuracoes', 'label' => 'Configurações', 'icon' => 'configuracoes', 'match' => ['/configuracoes']];
}

if ($canViewLogs) {
    $navigationItems[] = ['href' => '/logs', 'label' => 'Logs', 'icon' => 'logs', 'match' => ['/logs']];
}

$isItemActive = static function (array $item) use ($currentPath): bool {
    foreach ($item['match'] as $prefix) {
        if ($currentPath === $prefix || ($prefix !== '/' && str_starts_with($currentPath, $prefix))) {
            return true;
        }
    }

    return false;
};

$renderNavLink = function (array $item, bool $mobileOverflow = false) use ($isItemActive, $navIcons): void {
    $isActive = $isItemActive($item);
    ?>
    <a
        class="app-nav__link<?= $isActive ? ' is-active' : ''; ?><?= $mobileOverflow ? ' app-nav__link--mobile-hidden' : ''; ?>"
        href="<?= htmlspecialchars(Url::to($item['href']), ENT_QUOTES, 'UTF-8'); ?>"
        aria-current="<?= $isActive ? 'page' : 'false'; ?>"
        title="<?= htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8'); ?>"
        data-tooltip="<?= htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8'); ?>"
    >
        <span class="app-nav__link-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $navIcons[$item['icon']] ?? ''; ?></svg>
        </span>
        <span class="app-nav__link-label"><?= htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8'); ?></span>
    </a>
    <?php
};

// Barra mobile: até 4 itens fixos (Início, Clientes, Processos e Contratos, nessa ordem de prioridade,
// somente os que o perfil enxerga) + "Mais" com todo o resto (Logs, Configurações...). Desktop não muda.
$mobileFixedPriority = ['/dashboard', '/clientes', '/processos', '/contratos'];
$mobileFixedHrefs = [];
foreach ($mobileFixedPriority as $href) {
    foreach ($navigationItems as $item) {
        if ($item['href'] === $href && count($mobileFixedHrefs) < 4) {
            $mobileFixedHrefs[] = $href;
        }
    }
}
$mobileOverflowItems = array_values(array_filter($navigationItems, static fn (array $item): bool => !in_array($item['href'], $mobileFixedHrefs, true)));
$isMobileOverflow = static fn (array $item): bool => !in_array($item['href'], $mobileFixedHrefs, true);
$moreIsActive = false;
foreach ($mobileOverflowItems as $item) {
    $moreIsActive = $moreIsActive || $isItemActive($item);
}

$primaryItems = array_values(array_filter($navigationItems, static fn (array $item): bool => $item['icon'] !== 'configuracoes'));
$settingsItem = null;
foreach ($navigationItems as $item) {
    if ($item['icon'] === 'configuracoes') {
        $settingsItem = $item;
    }
}
?>
<nav class="app-nav" data-app-nav aria-label="Navegação principal">
    <button type="button" class="app-nav__brand" data-ai-open aria-haspopup="dialog" aria-controls="ai-panel" title="Assistente" aria-label="Abrir assistente">
        <span class="sidebar__logo">IA</span>
    </button>

    <div class="app-nav__list">
        <?php foreach ($primaryItems as $item): ?>
            <?php $renderNavLink($item, $isMobileOverflow($item)); ?>
        <?php endforeach; ?>
        <button
            type="button"
            class="app-nav__link app-nav__more<?= $moreIsActive ? ' is-active' : ''; ?>"
            data-nav-more-toggle
            aria-expanded="false"
            aria-controls="app-nav-more-panel"
        >
            <span class="app-nav__link-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="currentColor" stroke="none"><?= $navIcons['mais']; ?></svg>
            </span>
            <span class="app-nav__link-label">Mais</span>
        </button>
    </div>

    <div class="app-nav__settings">
        <span class="app-nav__user" title="<?= htmlspecialchars($userName . ($userRoleLabel !== '' ? ' · ' . $userRoleLabel : ''), ENT_QUOTES, 'UTF-8'); ?>">
            <?= htmlspecialchars(mb_strtoupper(mb_substr($userName, 0, 1)), ENT_QUOTES, 'UTF-8'); ?>
        </span>
        <a class="app-nav__link app-nav__link--mobile-hidden" href="<?= htmlspecialchars($logoutHref, ENT_QUOTES, 'UTF-8'); ?>" title="Sair" aria-label="Sair" data-tooltip="Sair">
            <span class="app-nav__link-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $navIcons['sair']; ?></svg>
            </span>
        </a>
        <?php if ($settingsItem !== null): ?>
            <?php $renderNavLink($settingsItem, true); ?>
        <?php endif; ?>
    </div>

    <div class="app-nav__more-panel" id="app-nav-more-panel" data-nav-more-panel role="dialog" aria-label="Mais opções" hidden>
        <?php foreach ($mobileOverflowItems as $item): ?>
            <?php $panelActive = $isItemActive($item); ?>
            <a class="app-nav__more-item<?= $panelActive ? ' is-active' : ''; ?>" href="<?= htmlspecialchars(Url::to($item['href']), ENT_QUOTES, 'UTF-8'); ?>" aria-current="<?= $panelActive ? 'page' : 'false'; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $navIcons[$item['icon']] ?? ''; ?></svg>
                <span><?= htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
        <?php endforeach; ?>
        <a class="app-nav__more-item app-nav__more-item--logout" href="<?= htmlspecialchars($logoutHref, ENT_QUOTES, 'UTF-8'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $navIcons['sair']; ?></svg>
            <span>Sair</span>
        </a>
    </div>
</nav>
