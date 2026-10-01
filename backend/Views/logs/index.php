<?php

declare(strict_types=1);

use App\Core\Url;

$h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$categories = is_array($categories ?? null) ? $categories : [];
$categoryCounts = is_array($categoryCounts ?? null) ? $categoryCounts : [];
$selectedCategory = (string) ($selectedCategory ?? '');

ob_start();
?>
<section class="page-header">
    <div>
        <p class="section-heading__eyebrow">Auditoria</p>
        <h1>Logs</h1>
        <p class="page-description">Quem fez o quê, mensagens enviadas ao cliente e alarmes técnicos, agrupados pelo que cada registro significa.</p>
    </div>
</section>

<section class="card">
    <nav class="hero-actions" aria-label="Categorias de log">
        <a class="button <?= $selectedCategory === '' ? '' : 'button--ghost'; ?>" href="<?= $h(Url::to('/logs')); ?>">Todos (<?= (int) array_sum($categoryCounts); ?>)</a>
        <?php foreach ($categories as $key => $category): ?>
            <a class="button <?= $selectedCategory === $key ? '' : 'button--ghost'; ?>" href="<?= $h(Url::to('/logs?categoria=' . rawurlencode((string) $key))); ?>"><?= $h($category['label']); ?> (<?= (int) ($categoryCounts[$key] ?? 0); ?>)</a>
        <?php endforeach; ?>
    </nav>
    <?php if ($selectedCategory !== '' && isset($categories[$selectedCategory])): ?>
        <p class="page-description"><?= $h($categories[$selectedCategory]['hint']); ?></p>
    <?php endif; ?>

    <div class="log-list">
        <?php if ($entries === []): ?>
            <p class="page-description">Nenhum registro nesta categoria.</p>
        <?php endif; ?>
        <?php foreach ($entries as $entry): ?>
            <article class="log-list__item">
                <div class="log-list__meta">
                    <span class="pill <?= !empty($entry['failure']) ? 'pill--danger' : 'pill--muted'; ?>"><?= $h(!empty($entry['failure']) ? 'Falha' : ($categories[$entry['category']]['label'] ?? '')); ?></span>
                    <time><?= $h($entry['time']); ?></time>
                </div>
                <p><?= $h($entry['message']); ?></p>
                <?php if (trim((string) $entry['detail']) !== ''): ?><p><small><?= $h($entry['detail']); ?> · <?= $h($entry['source']); ?></small></p><?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
