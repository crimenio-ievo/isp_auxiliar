<?php

declare(strict_types=1);

ob_start();
?>
<section class="stats-grid alert-tiles">
    <?php foreach ($alertTiles as $alertTile): ?>
        <?php require __DIR__ . '/../components/alert_tile.php'; ?>
    <?php endforeach; ?>
</section>

<article class="card">
    <div class="section-heading">
        <p class="section-heading__eyebrow">Atividades recentes</p>
        <h2>Fluxo do dia</h2>
    </div>

    <div class="timeline">
        <?php foreach ($recentActivities as $activity): ?>
            <div class="timeline__item">
                <span class="timeline__time"><?= htmlspecialchars((string) $activity['time'], ENT_QUOTES, 'UTF-8'); ?></span>
                <div>
                    <strong><?= htmlspecialchars((string) $activity['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <p><?= htmlspecialchars((string) $activity['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</article>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
