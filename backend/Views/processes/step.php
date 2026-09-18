<?php

declare(strict_types=1);

use App\Core\Url;

$process = is_array($process ?? null) ? $process : [];
$step = is_array($step ?? null) ? $step : [];
$dryRun = is_array($dryRun ?? null) ? $dryRun : null;
$processId = (int) ($process['id'] ?? 0);
$stepKey = (string) ($step['step_key'] ?? '');
$isCompleted = (string) ($step['status'] ?? '') === 'completed';
$isFinalStep = in_array($stepKey, ['complete_installation', 'complete_migration', 'complete_signature_request'], true);
$migration = is_array($process['metadata']['migration'] ?? null) ? $process['metadata']['migration'] : [];
ob_start();
?>
<section class="process-step-page">
    <nav class="process-step-nav" aria-label="Navegação da etapa">
        <?php if (is_array($previousStep ?? null)): ?>
            <a href="<?= htmlspecialchars(Url::to((string) ($previousStep['url'] ?? '#')), ENT_QUOTES, 'UTF-8'); ?>">‹ Etapa anterior</a>
        <?php else: ?><span></span><?php endif; ?>
        <a href="<?= htmlspecialchars(Url::to('/processos/detalhe?id=' . $processId), ENT_QUOTES, 'UTF-8'); ?>">Ver checklist</a>
        <?php if (is_array($nextStep ?? null)): ?>
            <a href="<?= htmlspecialchars(Url::to((string) ($nextStep['url'] ?? '#')), ENT_QUOTES, 'UTF-8'); ?>">Próxima etapa ›</a>
        <?php else: ?><span></span><?php endif; ?>
    </nav>

    <?php if (!empty($flash)): ?>
        <section class="alert alert--<?= htmlspecialchars((string) ($flash['type'] ?? 'success'), ENT_QUOTES, 'UTF-8'); ?>">
            <?= htmlspecialchars((string) ($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
        </section>
    <?php endif; ?>

    <section class="card process-step-focus">
        <p class="section-heading__eyebrow"><?= htmlspecialchars((string) ($process['type_label'] ?? 'Processo'), ENT_QUOTES, 'UTF-8'); ?> · etapa <?= (int) ($step['step_order'] ?? 0); ?> de <?= count((array) ($process['steps'] ?? [])); ?></p>
        <h1><?= htmlspecialchars((string) ($step['label'] ?? 'Etapa'), ENT_QUOTES, 'UTF-8'); ?></h1>
        <span class="process-status process-status--<?= htmlspecialchars((string) ($step['status_class'] ?? 'muted'), ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars((string) ($step['status_label'] ?? 'Não iniciada'), ENT_QUOTES, 'UTF-8'); ?></span>
        <p class="page-description">Objetivo: registre somente as informações e evidências necessárias para esta etapa.</p>

        <?php if (trim((string) ($step['pending_reason'] ?? '')) !== ''): ?>
            <div class="alert <?= (string) ($step['status'] ?? '') === 'attention' ? 'alert--error' : 'alert--warning'; ?>">
                <strong>Pendência:</strong> <?= htmlspecialchars((string) $step['pending_reason'], ENT_QUOTES, 'UTF-8'); ?>
                <?php if (trim((string) ($step['next_action'] ?? '')) !== ''): ?>
                    <br><strong>Próxima ação:</strong> <?= htmlspecialchars((string) $step['next_action'], ENT_QUOTES, 'UTF-8'); ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($migration !== [] && in_array($stepKey, ['migration_data', 'prepare_document', 'technical_execution', 'change_plan'], true)): ?>
            <div class="summary-grid process-essential-data">
                <div class="summary-item"><span>Plano anterior</span><strong><?= htmlspecialchars((string) ($migration['current_plan_name'] ?? $migration['plano_atual'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="summary-item"><span>Plano novo</span><strong><?= htmlspecialchars((string) ($migration['new_plan_name'] ?? $migration['novo_plano'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="summary-item"><span>Tecnologia anterior</span><strong><?= htmlspecialchars((string) ($migration['current_technology'] ?? $migration['tecnologia_atual'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="summary-item"><span>Tecnologia nova</span><strong><?= htmlspecialchars((string) ($migration['new_technology'] ?? $migration['nova_tecnologia'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="summary-item"><span>Valor anterior</span><strong><?= htmlspecialchars((string) ($migration['current_monthly_value'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="summary-item"><span>Valor novo</span><strong><?= htmlspecialchars((string) ($migration['new_monthly_value'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            </div>
        <?php endif; ?>

        <?php if ($stepKey === 'change_plan'): ?>
            <section class="process-dry-run">
                <h2>Alteração nativa preparada</h2>
                <?php if (is_array($dryRun)): ?>
                    <p><?= htmlspecialchars((string) ($dryRun['message'] ?? 'Dry-run disponível.'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php if (is_array($dryRun['payload'] ?? null)): ?>
                        <div class="summary-grid">
                            <div class="summary-item"><span>Endpoint</span><strong><?= htmlspecialchars((string) ($dryRun['endpoint'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                            <div class="summary-item"><span>Escrita</span><strong><?= !empty($dryRun['write_enabled']) ? 'Habilitada por configuração' : 'Bloqueada'; ?></strong></div>
                            <div class="summary-item"><span>De</span><strong><?= htmlspecialchars((string) ($dryRun['before']['plan'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                            <div class="summary-item"><span>Para</span><strong><?= htmlspecialchars((string) ($dryRun['after']['plan'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        </div>
                    <?php endif; ?>
                    <details>
                        <summary>Ver detalhes técnicos</summary>
                        <pre><?= htmlspecialchars((string) json_encode($dryRun, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?></pre>
                    </details>
                <?php else: ?>
                    <p class="page-description">O plano de destino não está disponível no snapshot.</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($isCompleted): ?>
            <div class="alert alert--success">Esta etapa já foi concluída. O registro e a evidência foram preservados.</div>
        <?php elseif ($isFinalStep): ?>
            <div class="alert alert--warning">A conclusão geral valida todas as etapas obrigatórias e mostra as pendências que ainda bloqueiam o processo.</div>
            <a class="button" href="<?= htmlspecialchars(Url::to('/processos/detalhe?id=' . $processId), ENT_QUOTES, 'UTF-8'); ?>">Voltar ao checklist e validar conclusão</a>
        <?php else: ?>
            <form method="post" action="<?= htmlspecialchars(Url::to('/processos/etapa'), ENT_QUOTES, 'UTF-8'); ?>" class="process-step-form" data-single-submit-form>
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) ($csrfToken ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="process_id" value="<?= $processId; ?>">
                <input type="hidden" name="step_key" value="<?= htmlspecialchars($stepKey, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="save" data-process-action-input>
                <label class="field">
                    <span>Observação ou evidência manual</span>
                    <textarea name="observation" rows="4" placeholder="O que foi conferido ou executado?"><?= htmlspecialchars((string) ($step['observation'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </label>
                <label class="field">
                    <span>Referência externa, equipamento ou protocolo</span>
                    <input name="external_reference" value="<?= htmlspecialchars((string) ($step['external_reference'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Opcional">
                </label>
                <details>
                    <summary>Registrar pendência para retomar depois</summary>
                    <label class="field">
                        <span>Motivo da pendência</span>
                        <textarea name="pending_reason" rows="3"><?= htmlspecialchars((string) ($step['pending_reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </label>
                    <label class="field">
                        <span>Próxima ação</span>
                        <input name="next_action" value="<?= htmlspecialchars((string) ($step['next_action'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="O que precisa acontecer para retomar?">
                    </label>
                    <label class="field">
                        <span>Responsável esperado</span>
                        <input name="responsible_login" value="<?= htmlspecialchars((string) ($step['responsible_login'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Login ou equipe">
                    </label>
                </details>
                <div class="process-step-actions">
                    <?php if ($stepKey === 'change_plan' && is_array($dryRun) && is_array($dryRun['payload'] ?? null)): ?>
                        <button class="button button--ghost" type="submit" data-process-action="simulate_plan">Registrar dry-run</button>
                    <?php endif; ?>
                    <?php if (in_array($stepKey, ['confirm_acceptance', 'open_financial_ticket', 'follow_financial_ticket'], true)): ?>
                        <button class="button button--ghost" type="submit" data-process-action="refresh_external">Atualizar situação</button>
                    <?php endif; ?>
                    <button class="button button--ghost" type="submit" data-process-action="defer">Pular por enquanto</button>
                    <button class="button button--ghost" type="submit" data-process-action="save">Salvar e voltar depois</button>
                    <button class="button" type="submit" data-process-action="complete">Salvar e continuar</button>
                    <input type="hidden" name="continue_to" value="">
                </div>
            </form>
        <?php endif; ?>
    </section>
</section>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/../layouts/app.php';
