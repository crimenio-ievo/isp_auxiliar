<?php

declare(strict_types=1);

use App\Core\Url;

ob_start();
?>
<section class="empty-state card">
    <span class="pill pill--muted">404</span>
    <h1>Pagina nao encontrada</h1>
    <p>A rota <strong><?= htmlspecialchars((string) $currentPath, ENT_QUOTES, 'UTF-8'); ?></strong> ainda nao existe nesta base inicial.</p>
    <div class="form-actions">
        <a class="button" href="<?= htmlspecialchars(Url::to('/dashboard'), ENT_QUOTES, 'UTF-8'); ?>">Ir para dashboard</a>
        <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/login'), ENT_QUOTES, 'UTF-8'); ?>">Voltar ao login</a>
    </div>
</section>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
