<?php

declare(strict_types=1);

// Cabeçalho das páginas públicas (layout guest). O layout autenticado não tem barra superior:
// a busca vive em layouts/content_top.php e usuário/sair na base da trilha (layouts/sidebar.php).
?>
<header class="topbar topbar--guest">
    <div class="topbar__brand">
        <p class="topbar__eyebrow">Central operacional</p>
        <strong><?= htmlspecialchars($appName ?? 'ISP Auxiliar', ENT_QUOTES, 'UTF-8'); ?></strong>
    </div>
</header>
