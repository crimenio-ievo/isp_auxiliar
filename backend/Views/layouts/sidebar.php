<?php

declare(strict_types=1);

use App\Core\Url;

$currentPath = $currentPath ?? '/';
$userRole = strtolower((string) ($user['role'] ?? ''));
$canManageSettings = in_array($userRole, ['platform_admin', 'manager', 'admin', 'administrador'], true);
$navigationItems = [
    ['/dashboard', 'Dashboard'],
    ['/clientes/novo', 'Novo Cliente'],
    ['/instalacoes', 'Instalacoes'],
    ['/usuarios', 'Usuarios'],
    ['/logs', 'Logs'],
];

if ($canManageSettings) {
    $navigationItems[] = ['/configuracoes', 'Configuracoes'];
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
        <?php foreach ($navigationItems as [$href, $label]): ?>
            <?php $isActive = $currentPath === $href; ?>
            <a class="nav-link<?= $isActive ? ' is-active' : ''; ?>" href="<?= htmlspecialchars(Url::to($href), ENT_QUOTES, 'UTF-8'); ?>">
                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar__footer">
        <p>Operação vinculada ao MkAuth e às evidências locais do ISP Auxiliar.</p>
        <a class="button button--ghost button--full" href="<?= htmlspecialchars(Url::to('/logout'), ENT_QUOTES, 'UTF-8'); ?>">Sair</a>
    </div>
</aside>

<div class="sidebar-backdrop" hidden data-sidebar-backdrop></div>
