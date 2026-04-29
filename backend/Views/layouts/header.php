<?php

declare(strict_types=1);

$layoutMode = $layoutMode ?? 'app';
$user = $user ?? ['name' => 'Convidado', 'role' => 'Sem sessao'];
$role = strtolower((string) ($user['role'] ?? ''));
$roleLabel = match ($role) {
    'platform_admin' => 'Administrador da plataforma',
    'manager' => 'Gestor',
    'technician' => 'Tecnico',
    default => (string) ($user['role'] ?? 'Sem sessao'),
};
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
        <p class="topbar__eyebrow">Painel administrativo</p>
        <strong><?= htmlspecialchars($appName ?? 'ISP Auxiliar', ENT_QUOTES, 'UTF-8'); ?></strong>
    </div>

    <div class="topbar__actions">
        <?php if ($layoutMode !== 'guest'): ?>
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
