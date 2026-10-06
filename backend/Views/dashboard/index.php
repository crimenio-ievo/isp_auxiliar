<?php

declare(strict_types=1);

$homeHero = true;

ob_start();
?>
<section class="stats-grid alert-tiles home-tiles">
    <?php foreach ($alertTiles as $alertTile): ?>
        <?php require __DIR__ . '/../components/alert_tile.php'; ?>
    <?php endforeach; ?>
</section>

<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
