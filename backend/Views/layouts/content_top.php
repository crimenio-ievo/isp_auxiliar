<?php

declare(strict_types=1);

use App\Core\AccessControl;
use App\Core\Features;
use App\Core\Url;

/**
 * Topo da área de conteúdo (layout autenticado): marca pequena no mobile, título da Home e a busca
 * global (/clientes?q=). Na Home ($homeHero) a busca é grande e centralizada; nas demais, compacta.
 */
$user = $user ?? ['name' => 'Convidado', 'role' => 'Sem sessao'];
$homeHero = !empty($homeHero);
$access = is_array($user['access'] ?? null) ? $user['access'] : [];
// Perfil desconhecido (sem 'access') mostra a busca; a rota /clientes ainda valida a permissão.
$canSearchClients = $access === [] || AccessControl::can($access, 'clients.view');
$globalSearchQuery = trim((string) ($_GET['q'] ?? ''));
?>
<div class="content-top<?= $homeHero ? ' content-top--home' : ''; ?>">
    <div class="mobile-brand">
        <span class="mobile-brand__logo">IA</span>
        <strong><?= htmlspecialchars($appName ?? 'ISP Auxiliar', ENT_QUOTES, 'UTF-8'); ?></strong>
    </div>

    <?php if ($homeHero): ?>
        <h1 class="home-title">O que você precisa fazer agora?</h1>
    <?php endif; ?>

    <?php if ($canSearchClients): ?>
        <form class="global-search<?= $homeHero ? ' global-search--hero' : ''; ?>" method="get" action="<?= htmlspecialchars(Url::to('/clientes'), ENT_QUOTES, 'UTF-8'); ?>" role="search" data-global-search<?php if ((AccessControl::isGestor($access) || AccessControl::can($access, 'clients.view')) && Features::enabled('net')): ?> data-network-search="<?= htmlspecialchars(Url::to('/api/rede-fibra/buscar'), ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>>
            <label class="visually-hidden" for="global-search-input<?= $homeHero ? '-hero' : ''; ?>">Buscar</label>
            <input
                id="global-search-input<?= $homeHero ? '-hero' : ''; ?>"
                type="search"
                name="q"
                value="<?= htmlspecialchars($globalSearchQuery, ENT_QUOTES, 'UTF-8'); ?>"
                placeholder="Buscar cliente, login, CPF/CNPJ, contrato ou equipamento..."
                aria-label="Buscar cliente, login, CPF/CNPJ, contrato ou equipamento"
                autocomplete="off"
            >
            <kbd class="global-search__hint" aria-hidden="true">Ctrl K</kbd>
        </form>
    <?php endif; ?>
</div>
