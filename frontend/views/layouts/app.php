<?php

declare(strict_types=1);

$pageTitle = $title ?? 'ISP Auxiliar';
$pageContent = $content ?? '';
$applicationName = $appName ?? 'ISP Auxiliar';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <div class="app-shell">
        <header class="app-header">
            <div>
                <p class="eyebrow">Base inicial</p>
                <h1><?= htmlspecialchars($applicationName, ENT_QUOTES, 'UTF-8'); ?></h1>
            </div>
            <span class="badge">PHP puro</span>
        </header>

        <main class="app-content">
            <?= $pageContent; ?>
        </main>
    </div>

    <script src="/assets/js/app.js" defer></script>
</body>
</html>
