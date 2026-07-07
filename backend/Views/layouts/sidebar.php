<?php

declare(strict_types=1);

use App\Core\Url;
use App\Core\AccessControl;

$currentPath = $currentPath ?? '/';
$access = is_array($user['access'] ?? null) ? $user['access'] : [];
$canManageSettings = AccessControl::can($access, 'configuracoes');
$canAccessContracts = AccessControl::can($access, 'contratos');
$navigationItems = [
    ['/dashboard', 'Home', 'HM'],
    ['/clientes', 'Clientes', 'CL'],
];

if ($canAccessContracts) {
    $navigationItems[] = ['/contratos', 'Contratos', 'CT'];
}

$navigationItems[] = ['/logs', 'Logs', 'LG'];

if ($canManageSettings) {
    $navigationItems[] = ['/configuracoes', 'Configurações', 'CF'];
}
?>
<aside class="sidebar" data-sidebar>
    <div class="sidebar__brand">
        <span class="sidebar__logo">IA</span>
        <div>
            <strong><?= htmlspecialchars($appName ?? 'ISP Auxiliar', ENT_QUOTES, 'UTF-8'); ?></strong>
            <small>Cadastro integrado</small>
        </div>
    </div>

    <nav class="sidebar__nav" aria-label="Menu principal">
        <p class="sidebar__section-label">Navegação</p>
        <?php foreach ($navigationItems as [$href, $label, $icon]): ?>
            <?php
            $isActive = $currentPath === $href
                || ($href === '/clientes' && str_starts_with($currentPath, '/clientes'))
                || ($href === '/contratos' && str_starts_with($currentPath, '/contratos'))
                || ($href === '/logs' && str_starts_with($currentPath, '/logs'))
                || ($href === '/configuracoes' && str_starts_with($currentPath, '/configuracoes'));
            ?>
            <a class="nav-link<?= $isActive ? ' is-active' : ''; ?>" href="<?= htmlspecialchars(Url::to($href), ENT_QUOTES, 'UTF-8'); ?>">
                <span class="nav-link__icon" aria-hidden="true"><?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'); ?></span>
                <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
        <?php endforeach; ?>
        <a class="nav-link nav-link--logout" href="<?= htmlspecialchars(Url::to('/logout'), ENT_QUOTES, 'UTF-8'); ?>">
            <span class="nav-link__icon" aria-hidden="true">SR</span>
            <span>Sair</span>
        </a>
    </nav>

    <div class="sidebar__footer">
        <strong>Ambiente operacional</strong>
        <p>MkAuth e evidências locais conectados ao fluxo de atendimento.</p>
    </div>
</aside>

<div class="sidebar-backdrop" hidden data-sidebar-backdrop></div>
