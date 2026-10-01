<?php

declare(strict_types=1);

use App\Core\Url;
use App\Core\AccessControl;

$currentPath = $currentPath ?? '/';
$access = is_array($user['access'] ?? null) ? $user['access'] : [];
$canManageSettings = AccessControl::can($access, 'configuracoes');
$canAccessContracts = AccessControl::can($access, 'contratos');
$canViewLogs = AccessControl::isGestor($access);

/**
 * Ícones outline (estilo Feather, stroke, sem preenchimento).
 */
$navIcons = [
    'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9.5 21v-6h5v6"/>',
    'clientes' => '<circle cx="9" cy="8" r="3.3"/><path d="M2.5 20c0-3.6 2.9-6.2 6.5-6.2s6.5 2.6 6.5 6.2"/><path d="M15.8 8.3a3 3 0 1 1 3 3"/><path d="M15.2 13.9c2.6.5 4.3 2.4 4.3 6.1"/>',
    'contratos' => '<path d="M7 3h7l4 4v14H7z"/><path d="M14 3v4h4"/><path d="M9.3 13h5.4M9.3 16.5h5.4M9.3 9.5h2.4"/>',
    'processos' => '<path d="M12 3 3 8l9 5 9-5-9-5z"/><path d="M3 12l9 5 9-5"/><path d="M3 16l9 5 9-5"/>',
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

$renderNavLink = function (array $item) use ($isItemActive, $navIcons): void {
    $isActive = $isItemActive($item);
    ?>
    <a
        class="app-nav__link<?= $isActive ? ' is-active' : ''; ?>"
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

$primaryItems = array_values(array_filter($navigationItems, static fn (array $item): bool => $item['icon'] !== 'configuracoes'));
$settingsItem = null;
foreach ($navigationItems as $item) {
    if ($item['icon'] === 'configuracoes') {
        $settingsItem = $item;
    }
}
?>
<nav class="app-nav" data-app-nav aria-label="Navegação principal">
    <a class="app-nav__brand" href="<?= htmlspecialchars(Url::to('/dashboard'), ENT_QUOTES, 'UTF-8'); ?>" title="<?= htmlspecialchars($appName ?? 'ISP Auxiliar', ENT_QUOTES, 'UTF-8'); ?>">
        <span class="sidebar__logo">IA</span>
    </a>

    <div class="app-nav__list">
        <?php foreach ($primaryItems as $item): ?>
            <?php $renderNavLink($item); ?>
        <?php endforeach; ?>
    </div>

    <?php if ($settingsItem !== null): ?>
        <div class="app-nav__settings">
            <?php $renderNavLink($settingsItem); ?>
        </div>
    <?php endif; ?>
</nav>
