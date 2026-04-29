<?php

declare(strict_types=1);

ob_start();
?>
<section class="page-header">
    <div>
        <p class="section-heading__eyebrow">Auditoria</p>
        <h1>Logs</h1>
        <p class="page-description">Eventos operacionais registrados a partir do fluxo de cadastro e validação de instalação.</p>
    </div>
</section>

<section class="card">
    <div class="section-heading">
        <p class="section-heading__eyebrow">Eventos recentes</p>
        <h2>Linha do tempo operacional</h2>
    </div>

    <div class="log-list">
        <?php foreach ($entries as $entry): ?>
            <article class="log-list__item">
                <div class="log-list__meta">
                    <span class="pill pill--muted"><?= htmlspecialchars((string) $entry['level'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <time><?= htmlspecialchars((string) $entry['time'], ENT_QUOTES, 'UTF-8'); ?></time>
                </div>
                <p><?= htmlspecialchars((string) $entry['message'], ENT_QUOTES, 'UTF-8'); ?></p>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
