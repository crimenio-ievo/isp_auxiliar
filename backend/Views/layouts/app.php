<?php

declare(strict_types=1);

use App\Core\Url;

$pageTitle = $pageTitle ?? 'ISP Auxiliar';
$layoutMode = $layoutMode ?? 'app';
$content = $content ?? '';
$hideFooter = !empty($hideFooter);
$bodyClass = $layoutMode === 'guest' ? 'layout-guest' : 'layout-app';
$basePath = Url::basePath();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?> | <?= htmlspecialchars($appName ?? 'ISP Auxiliar', ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(Url::asset('css/app.css?v=20260429b'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body class="<?= htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8'); ?>" data-base-path="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8'); ?>">
    <?php require __DIR__ . '/header.php'; ?>

    <div class="shell">
        <?php if ($layoutMode !== 'guest'): ?>
            <?php require __DIR__ . '/sidebar.php'; ?>
        <?php endif; ?>

        <main class="main-content">
            <?= $content; ?>
            <?php if (!$hideFooter): ?>
                <?php require __DIR__ . '/footer.php'; ?>
            <?php endif; ?>
        </main>
    </div>

    <script src="<?= htmlspecialchars(Url::asset('js/app.js?v=20260429a'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
</body>
</html>
