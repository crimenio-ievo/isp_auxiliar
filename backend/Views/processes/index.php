<?php

declare(strict_types=1);

use App\Core\Url;

$processes = is_array($processes ?? null) ? $processes : [];
$filters = is_array($filters ?? null) ? $filters : [];
$statusOptions = [
    '' => 'Todos os estados',
    'in_progress' => 'Em andamento',
    'waiting_client' => 'Aguardando cliente',
    'waiting_technician' => 'Aguardando técnico',
    'waiting_mkauth' => 'Aguardando MkAuth',
    'waiting_financial' => 'Aguardando financeiro',
    'attention' => 'Requer atenção',
    'completed' => 'Concluídos',
    'cancelled' => 'Cancelados',
];
$typeOptions = [
    '' => 'Todos os tipos',
    'migration' => 'Migração',
    'installation' => 'Nova instalação',
    'standalone_signature' => 'Assinatura avulsa',
];
ob_start();
?>
<section class="page-header">
    <div>
        <p class="section-heading__eyebrow">Operação compartilhada</p>
        <h1>Processos operacionais</h1>
        <p class="page-description">Pendências retomáveis de instalação, migração e assinatura avulsa.</p>
    </div>
    <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/clientes'), ENT_QUOTES, 'UTF-8'); ?>">Voltar para clientes</a>
</section>

<?php if (!empty($flash)): ?>
    <section class="alert alert--<?= htmlspecialchars((string) ($flash['type'] ?? 'success'), ENT_QUOTES, 'UTF-8'); ?>">
        <?= htmlspecialchars((string) ($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
    </section>
<?php endif; ?>

<section class="card process-filter-card">
    <form method="get" action="<?= htmlspecialchars(Url::to('/processos'), ENT_QUOTES, 'UTF-8'); ?>" class="form-grid">
        <label class="field">
            <span>Situação</span>
            <select name="status">
                <?php foreach ($statusOptions as $value => $label): ?>
                    <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?= (string) ($filters['status'] ?? '') === $value ? 'selected' : ''; ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span>Tipo</span>
            <select name="type">
                <?php foreach ($typeOptions as $value => $label): ?>
                    <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?= (string) ($filters['type'] ?? '') === $value ? 'selected' : ''; ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span>Login</span>
            <input name="login" value="<?= htmlspecialchars((string) ($filters['login'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Login exato do cliente">
        </label>
        <div class="form-actions">
            <button class="button" type="submit">Filtrar</button>
            <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/processos'), ENT_QUOTES, 'UTF-8'); ?>">Limpar</a>
        </div>
    </form>
</section>

<section class="process-card-grid">
    <?php foreach ($processes as $process): ?>
        <?php
        $status = (string) ($process['status'] ?? 'in_progress');
        $tone = $status === 'attention' ? 'danger' : (str_starts_with($status, 'waiting_') ? 'warning' : ($status === 'completed' ? 'success' : 'info'));
        ?>
        <article class="card process-summary-card process-summary-card--<?= htmlspecialchars($tone, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="process-summary-card__header">
                <div>
                    <span class="pill"><?= htmlspecialchars((string) ($process['type_label'] ?? 'Processo'), ENT_QUOTES, 'UTF-8'); ?></span>
                    <h2><?= htmlspecialchars((string) ($process['client_name'] ?? $process['mkauth_login'] ?? 'Cliente'), ENT_QUOTES, 'UTF-8'); ?></h2>
                    <small>Login: <?= htmlspecialchars((string) ($process['mkauth_login'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></small>
                </div>
                <span class="process-status process-status--<?= htmlspecialchars($tone, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars((string) ($process['status_label'] ?? 'Em andamento'), ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="process-progress" aria-label="Progresso do processo">
                <span style="width: <?= max(0, min(100, (int) ($process['progress_percent'] ?? 0))); ?>%"></span>
            </div>
            <p><strong><?= (int) ($process['progress_completed'] ?? 0); ?> de <?= (int) ($process['progress_total'] ?? 0); ?> etapas obrigatórias concluídas</strong></p>
            <p class="page-description">Próxima pendência: <?= htmlspecialchars((string) ($process['next_pending_label'] ?? 'Nenhuma'), ENT_QUOTES, 'UTF-8'); ?></p>
            <div class="process-summary-card__meta">
                <small>Responsável: <?= htmlspecialchars((string) ($process['responsible_name'] ?? $process['responsible_login'] ?? 'Não definido'), ENT_QUOTES, 'UTF-8'); ?></small>
                <small>Atualizado em: <?= htmlspecialchars((string) ($process['updated_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></small>
            </div>
            <div class="hero-actions">
                <?php if (!in_array($status, ['completed', 'cancelled'], true)): ?>
                    <a class="button" href="<?= htmlspecialchars(Url::to((string) ($process['resume_url'] ?? '/processos/detalhe?id=' . (int) $process['id'])), ENT_QUOTES, 'UTF-8'); ?>">Continuar próxima pendência</a>
                <?php endif; ?>
                <a class="button button--ghost" href="<?= htmlspecialchars(Url::to((string) ($process['detail_url'] ?? '/processos/detalhe?id=' . (int) $process['id'])), ENT_QUOTES, 'UTF-8'); ?>">Ver todas as etapas</a>
            </div>
        </article>
    <?php endforeach; ?>
    <?php if ($processes === []): ?>
        <section class="card">
            <h2>Nenhum processo encontrado</h2>
            <p class="page-description">Altere os filtros ou inicie o fluxo pelo detalhe do cliente.</p>
        </section>
    <?php endif; ?>
</section>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/../layouts/app.php';
