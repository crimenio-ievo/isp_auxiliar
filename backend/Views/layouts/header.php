<?php

declare(strict_types=1);

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
?>
<header class="topbar<?= $layoutMode === 'guest' ? ' topbar--guest' : ''; ?>">
    <div class="topbar__brand">
        <p class="topbar__eyebrow">Central operacional</p>
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
            <a class="topbar__logout" href="<?= htmlspecialchars(Url::to('/logout'), ENT_QUOTES, 'UTF-8'); ?>" title="Sair" aria-label="Sair">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </a>
        <?php endif; ?>
    </div>
</header>
