<?php

declare(strict_types=1);

use App\Core\AccessControl;
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
$access = is_array($user['access'] ?? null) ? $user['access'] : [];
// Perfil desconhecido (sem 'access') mostra a busca; a rota /clientes ainda valida a permissão.
$canSearchClients = $layoutMode !== 'guest' && ($access === [] || AccessControl::can($access, 'clients.view'));
$globalSearchQuery = trim((string) ($_GET['q'] ?? ''));
?>
<header class="topbar<?= $layoutMode === 'guest' ? ' topbar--guest' : ''; ?>">
    <div class="topbar__brand">
        <p class="topbar__eyebrow">Central operacional</p>
        <strong><?= htmlspecialchars($appName ?? 'ISP Auxiliar', ENT_QUOTES, 'UTF-8'); ?></strong>
    </div>

    <?php if ($canSearchClients): ?>
        <form class="topbar__search" method="get" action="<?= htmlspecialchars(Url::to('/clientes'), ENT_QUOTES, 'UTF-8'); ?>" role="search" data-global-search>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input
                type="search"
                name="q"
                value="<?= htmlspecialchars($globalSearchQuery, ENT_QUOTES, 'UTF-8'); ?>"
                placeholder="Buscar cliente, login, CPF/CNPJ, contrato ou equipamento..."
                aria-label="Buscar cliente, login, CPF/CNPJ, contrato ou equipamento"
                autocomplete="off"
            >
        </form>
    <?php endif; ?>

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
