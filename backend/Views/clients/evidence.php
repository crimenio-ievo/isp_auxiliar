<?php

declare(strict_types=1);

use App\Core\Url;

$metadata = is_array($metadata ?? null) ? $metadata : [];
$client = is_array($metadata['client'] ?? null) ? $metadata['client'] : [];
$files = is_array($files ?? null) ? $files : [];

$fileUrl = static fn (string $file): string => Url::to('/clientes/evidencias/arquivo?ref=' . rawurlencode((string) $ref) . '&file=' . rawurlencode($file));

ob_start();
?>
<section class="auth-panel auth-panel--evidence">
    <div class="auth-panel__intro">
        <p class="topbar__eyebrow">Evidências</p>
        <h1>Cadastro e instalação</h1>
        <p>Registro complementar gerado pelo ISP Auxiliar com aceite, assinatura e fotos da instalação.</p>
    </div>

    <article class="card auth-panel__form evidence-view">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Cliente</p>
            <h2><?= htmlspecialchars((string) ($client['nome'] ?? 'Cliente'), ENT_QUOTES, 'UTF-8'); ?></h2>
        </div>

        <div class="summary-grid">
            <div class="summary-item">
                <span>Login</span>
                <strong><?= htmlspecialchars((string) ($client['login'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div class="summary-item">
                <span>Documento</span>
                <strong><?= htmlspecialchars((string) ($client['cpf_cnpj'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div class="summary-item">
                <span>Cidade</span>
                <strong><?= htmlspecialchars(trim((string) ($client['cidade'] ?? '') . '/' . (string) ($client['estado'] ?? ''), '/'), ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div class="summary-item">
                <span>Aceite</span>
                <strong><?= htmlspecialchars((string) ($metadata['accepted_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
        </div>

        <?php if ($files !== []): ?>
            <div class="evidence-gallery">
                <?php foreach ($files as $file): ?>
                    <?php $file = (string) $file; ?>
                    <a href="<?= htmlspecialchars($fileUrl($file), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                        <img src="<?= htmlspecialchars($fileUrl($file), ENT_QUOTES, 'UTF-8'); ?>" alt="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8'); ?>">
                        <span><?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8'); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="helper-text">Nenhum arquivo de evidência encontrado.</p>
        <?php endif; ?>
    </article>
</section>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
