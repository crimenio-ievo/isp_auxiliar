<?php

declare(strict_types=1);

use App\Core\Url;

$process = is_array($process ?? null) ? $process : [];
$steps = is_array($process['steps'] ?? null) ? $process['steps'] : [];
$processId = (int) ($process['id'] ?? 0);
$isClosed = in_array((string) ($process['status'] ?? ''), ['completed', 'cancelled'], true);
ob_start();
?>
<section class="page-header">
    <div>
        <p class="section-heading__eyebrow"><?= htmlspecialchars((string) ($process['type_label'] ?? 'Processo operacional'), ENT_QUOTES, 'UTF-8'); ?></p>
        <h1><?= htmlspecialchars((string) ($process['client_name'] ?? $process['mkauth_login'] ?? 'Cliente'), ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="page-description">Login <?= htmlspecialchars((string) ($process['mkauth_login'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?> · Processo #<?= $processId; ?></p>
    </div>
    <div class="hero-actions">
        <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/clientes/detalhe?login=' . rawurlencode((string) ($process['mkauth_login'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?>">Detalhe do cliente</a>
        <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/processos'), ENT_QUOTES, 'UTF-8'); ?>">Todos os processos</a>
    </div>
</section>

<?php if (!empty($flash)): ?>
    <section class="alert alert--<?= htmlspecialchars((string) ($flash['type'] ?? 'success'), ENT_QUOTES, 'UTF-8'); ?>">
        <?= htmlspecialchars((string) ($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
    </section>
<?php endif; ?>

<section class="card process-overview">
    <div class="process-summary-card__header">
        <div>
            <span class="process-status"><?= htmlspecialchars((string) ($process['status_label'] ?? 'Em andamento'), ENT_QUOTES, 'UTF-8'); ?></span>
            <h2><?= (int) ($process['progress_completed'] ?? 0); ?> de <?= (int) ($process['progress_total'] ?? 0); ?> etapas concluídas</h2>
        </div>
        <strong><?= (int) ($process['progress_percent'] ?? 0); ?>%</strong>
    </div>
    <div class="process-progress process-progress--large"><span style="width: <?= max(0, min(100, (int) ($process['progress_percent'] ?? 0))); ?>%"></span></div>
    <?php if (!$isClosed): ?>
        <div class="process-next-pending">
            <span>Próxima pendência</span>
            <strong><?= htmlspecialchars((string) ($process['next_pending_label'] ?? 'Revisar checklist'), ENT_QUOTES, 'UTF-8'); ?></strong>
            <a class="button" href="<?= htmlspecialchars(Url::to((string) ($process['resume_url'] ?? '/processos/detalhe?id=' . $processId)), ENT_QUOTES, 'UTF-8'); ?>">Continuar próxima pendência</a>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="section-heading">
        <p class="section-heading__eyebrow">Retomada</p>
        <h2>Todas as etapas</h2>
    </div>
    <div class="process-checklist">
        <?php foreach ($steps as $step): ?>
            <a class="process-step-card process-step-card--<?= htmlspecialchars((string) ($step['status_class'] ?? 'muted'), ENT_QUOTES, 'UTF-8'); ?>" href="<?= htmlspecialchars(Url::to((string) ($step['url'] ?? '#')), ENT_QUOTES, 'UTF-8'); ?>">
                <span class="process-step-card__order"><?= (int) ($step['step_order'] ?? 0); ?></span>
                <span class="process-step-card__body">
                    <strong><?= htmlspecialchars((string) ($step['label'] ?? 'Etapa'), ENT_QUOTES, 'UTF-8'); ?></strong>
                    <small><?= htmlspecialchars((string) ($step['status_label'] ?? 'Não iniciada'), ENT_QUOTES, 'UTF-8'); ?><?= !empty($step['is_required']) ? ' · obrigatória' : ' · opcional'; ?></small>
                    <?php if (trim((string) ($step['pending_reason'] ?? '')) !== ''): ?>
                        <small><?= htmlspecialchars((string) $step['pending_reason'], ENT_QUOTES, 'UTF-8'); ?></small>
                    <?php endif; ?>
                </span>
                <span aria-hidden="true">›</span>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<?php if (!$isClosed): ?>
    <section class="card">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Conclusão controlada</p>
            <h2>Finalizar processo</h2>
        </div>
        <p class="page-description">A conclusão normal permanece bloqueada enquanto houver etapa obrigatória pendente.</p>
        <form method="post" action="<?= htmlspecialchars(Url::to('/processos/concluir'), ENT_QUOTES, 'UTF-8'); ?>" class="form-grid">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) ($csrfToken ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="process_id" value="<?= $processId; ?>">
            <?php if (!empty($canOverride)): ?>
                <label class="field field--span-2">
                    <span><input type="checkbox" name="override" value="1"> Exceção autorizada com pendências</span>
                    <small class="field-help">Somente gestor ou administrador. A justificativa e as pendências serão auditadas.</small>
                </label>
                <label class="field field--span-2">
                    <span>Justificativa da exceção</span>
                    <textarea name="justification" rows="3"></textarea>
                </label>
            <?php endif; ?>
            <div class="form-actions field--span-2">
                <button class="button" type="submit">Validar e concluir</button>
            </div>
        </form>
    </section>

    <details class="card process-danger-zone">
        <summary>Cancelar processo</summary>
        <form method="post" action="<?= htmlspecialchars(Url::to('/processos/cancelar'), ENT_QUOTES, 'UTF-8'); ?>" class="form-grid">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) ($csrfToken ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="process_id" value="<?= $processId; ?>">
            <label class="field field--span-2">
                <span>Motivo obrigatório</span>
                <textarea name="reason" rows="3" required></textarea>
            </label>
            <div class="form-actions field--span-2">
                <button class="button button--ghost" type="submit">Cancelar preservando histórico</button>
            </div>
        </form>
    </details>
<?php endif; ?>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/../layouts/app.php';
