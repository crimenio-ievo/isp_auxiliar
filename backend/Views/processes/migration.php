<?php

declare(strict_types=1);

use App\Core\Url;
use App\Core\Csrf;

$process = is_array($process ?? null) ? $process : [];
$contract = is_array($contract ?? null) ? $contract : [];
$acceptance = is_array($acceptance ?? null) ? $acceptance : [];
$activeStep = is_array($activeStep ?? null) ? $activeStep : [];
$previousStep = is_array($previousStep ?? null) ? $previousStep : null;
$nextStep = is_array($nextStep ?? null) ? $nextStep : null;
$connection = is_array($connection ?? null) ? $connection : [];
$dryRun = is_array($dryRun ?? null) ? $dryRun : null;
$steps = array_values((array) ($process['steps'] ?? []));
$processId = (int) ($process['id'] ?? 0);
$stepKey = (string) ($activeStep['step_key'] ?? '');
$stepOrder = max(1, (int) ($activeStep['step_order'] ?? 1));
$migration = is_array($process['metadata']['migration'] ?? null) ? $process['metadata']['migration'] : [];
$client = is_array($process['metadata']['client'] ?? null) ? $process['metadata']['client'] : [];
$isClosed = in_array((string) ($process['status'] ?? ''), ['completed', 'cancelled'], true);
$isCompleted = in_array((string) ($activeStep['status'] ?? ''), ['completed', 'not_applicable'], true);
$acceptanceAccepted = (string) ($acceptance['status'] ?? '') === 'aceito'
    && trim((string) ($acceptance['revoked_at'] ?? '')) === '';
$acceptancePending = in_array((string) ($acceptance['status'] ?? ''), ['criado', 'enviado', 'assinatura_pendente'], true)
    && trim((string) ($acceptance['revoked_at'] ?? '')) === '';
$phone = trim((string) ($acceptance['telefone_enviado'] ?? $client['phone'] ?? $contract['telefone_cliente'] ?? ''));
$email = trim((string) ($client['email'] ?? ''));
$h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = static fn (mixed $value): string => is_numeric($value) ? 'R$ ' . number_format((float) $value, 2, ',', '.') : '-';
$phaseFor = static fn (string $key): int => match ($key) {
    'migration_data' => 1,
    'prepare_document', 'send_acceptance', 'confirm_acceptance' => 2,
    'technical_execution', 'confirm_equipment', 'validate_connection' => 3,
    default => 4,
};
$phases = [1 => 'Condição', 2 => 'Aceite', 3 => 'Execução', 4 => 'Plano e financeiro'];
$currentPhase = $phaseFor($stepKey);
$statusClass = (string) ($activeStep['status_class'] ?? 'muted');
$statusLabel = (string) ($activeStep['status_label'] ?? 'Não iniciada');
$nextUrl = is_array($nextStep) ? (string) ($nextStep['url'] ?? '') : '';
$previousUrl = is_array($previousStep) ? (string) ($previousStep['url'] ?? '') : '';
$renderCondition = static function () use ($migration, $money, $h): void { ?>
    <div class="migration-condition-summary">
        <div class="summary-grid">
            <div class="summary-item"><span>Operação</span><strong><?= $h(match ((string) ($migration['operation_type'] ?? '')) { 'migration' => 'Migração', 'upgrade' => 'Upgrade', 'downgrade' => 'Downgrade', default => '-' }); ?></strong></div>
            <div class="summary-item"><span>Plano atual</span><strong><?= $h($migration['current_plan_name'] ?? '-'); ?></strong></div>
            <div class="summary-item"><span>Nova condição</span><strong><?= $h($migration['new_plan_name'] ?? '-'); ?> · <?= $money($migration['new_monthly_value'] ?? null); ?></strong></div>
            <div class="summary-item"><span>Tecnologia</span><strong><?= $h($migration['current_technology'] ?? '-'); ?> → <?= $h($migration['new_technology'] ?? '-'); ?></strong></div>
            <div class="summary-item"><span>Adesão padrão</span><strong><?= $money($migration['adhesion_default_value'] ?? null); ?></strong></div>
            <div class="summary-item"><span>Valor cobrado</span><strong><?= $money($migration['adhesion_charged_value'] ?? null); ?></strong></div>
            <div class="summary-item"><span>Benefício</span><strong><?= $money($migration['benefit_value'] ?? 0); ?></strong></div>
            <div class="summary-item"><span>Fidelidade</span><strong><?= (int) ($migration['fidelity_months'] ?? 0) > 0 ? (int) $migration['fidelity_months'] . ' meses' : 'Não aplicada'; ?></strong></div>
        </div>
    </div>
<?php };

$renderManualForm = static function (array $options = []) use ($activeStep, $processId, $csrfToken, $previousUrl, $h): void {
    $simulate = !empty($options['simulate']);
    $refresh = !empty($options['refresh']);
    ?>
    <form method="post" action="<?= $h(Url::to('/processos/etapa')); ?>" class="migration-active-form" data-single-submit-form>
        <input type="hidden" name="_csrf" value="<?= $h($csrfToken ?? ''); ?>">
        <input type="hidden" name="process_id" value="<?= $processId; ?>">
        <input type="hidden" name="step_key" value="<?= $h($activeStep['step_key'] ?? ''); ?>">
        <input type="hidden" name="action" value="save" data-process-action-input>
        <input type="hidden" name="continue_to" value="migration_workspace" data-process-continue-input>
        <label class="field" for="migration-observation">
            <span><?= $h($options['label'] ?? 'Observação ou evidência'); ?></span>
            <textarea id="migration-observation" name="observation" rows="4" placeholder="<?= $h($options['placeholder'] ?? 'Registre o que foi conferido ou executado.'); ?>"><?= $h($activeStep['observation'] ?? ''); ?></textarea>
        </label>
        <?php if (!empty($options['external'])): ?>
            <label class="field" for="migration-external"><span><?= $h($options['external']); ?></span><input id="migration-external" name="external_reference" value="<?= $h($activeStep['external_reference'] ?? ''); ?>"></label>
        <?php endif; ?>
        <details class="migration-pending-details">
            <summary>Registrar uma pendência</summary>
            <label class="field"><span>Motivo</span><textarea name="pending_reason" rows="2"><?= $h($activeStep['pending_reason'] ?? ''); ?></textarea></label>
            <label class="field"><span>Próxima ação</span><input name="next_action" value="<?= $h($activeStep['next_action'] ?? ''); ?>"></label>
        </details>
        <footer class="migration-workspace__actions">
            <?php if ($previousUrl !== ''): ?><a class="button button--ghost" href="<?= $h(Url::to($previousUrl)); ?>">Voltar</a><?php else: ?><span></span><?php endif; ?>
            <button class="button button--ghost" type="submit" data-process-action="save" data-process-continue="migration_exit">Salvar e sair</button>
            <?php if ($refresh): ?><button class="button button--ghost" type="submit" data-process-action="refresh_external">Atualizar situação</button><?php endif; ?>
            <?php if ($simulate): ?>
                <button class="button" type="submit" data-process-action="simulate_plan">Registrar dry-run</button>
            <?php else: ?>
                <button class="button" type="submit" data-process-action="complete" data-process-continue="migration_workspace">Continuar</button>
            <?php endif; ?>
        </footer>
    </form>
<?php };

ob_start();
?>
<main class="migration-workspace" data-migration-workspace>
    <header class="migration-workspace__header">
        <div>
            <a class="client-hub__back" href="<?= $h(Url::to('/clientes/detalhe?login=' . rawurlencode((string) ($process['mkauth_login'] ?? '')))); ?>">← Voltar ao cliente</a>
            <p class="section-heading__eyebrow">Migração #<?= $processId; ?> · etapa <?= $stepOrder; ?> de <?= count($steps); ?></p>
            <h1><?= $h($activeStep['label'] ?? 'Processo de migração'); ?></h1>
            <p class="page-description"><?= $h($process['client_name'] ?? $process['mkauth_login'] ?? 'Cliente'); ?> · <?= $h($process['mkauth_login'] ?? ''); ?></p>
        </div>
        <div class="migration-workspace__progress" aria-label="Progresso do processo">
            <strong><?= (int) ($process['progress_completed'] ?? 0); ?>/<?= (int) ($process['progress_total'] ?? count($steps)); ?></strong>
            <div class="process-progress"><span style="width: <?= max(0, min(100, (int) ($process['progress_percent'] ?? 0))); ?>%"></span></div>
            <button class="button button--ghost button--small" type="button" data-open-process-steps aria-controls="migration-checklist" aria-expanded="false">Ver etapas</button>
        </div>
    </header>

    <?php if (!empty($flash)): ?>
        <section class="alert alert--<?= $h($flash['type'] ?? 'success'); ?>" aria-live="polite" tabindex="-1" data-focus-on-load><?= $h($flash['message'] ?? ''); ?></section>
    <?php endif; ?>

    <nav class="migration-phase-legend" aria-label="Fases da migração">
        <?php foreach ($phases as $number => $label): ?><span class="<?= $number === $currentPhase ? 'is-current' : ($number < $currentPhase ? 'is-past' : ''); ?>"><b><?= $number; ?></b><?= $h($label); ?></span><?php endforeach; ?>
    </nav>

    <div class="migration-workspace__grid">
        <section class="card migration-workspace__active" aria-labelledby="active-step-title">
            <header class="migration-active-heading">
                <div><p class="section-heading__eyebrow"><?= $h($phases[$currentPhase]); ?></p><h2 id="active-step-title"><?= $h($activeStep['label'] ?? 'Etapa'); ?></h2></div>
                <span class="process-status process-status--<?= $h($statusClass); ?>"><?= $h($statusLabel); ?></span>
            </header>

            <?php if ($isClosed && (string) ($process['status'] ?? '') === 'cancelled'): ?>
                <div class="alert alert--warning"><strong>Processo cancelado.</strong> As evidências permanecem no histórico e não há pendência ativa.</div>
                <a class="button" href="<?= $h(Url::to('/clientes/upgrade?login=' . rawurlencode((string) ($process['mkauth_login'] ?? '')))); ?>">Iniciar nova migração</a>
            <?php elseif ($stepKey === 'migration_data'): ?>
                <?php $renderCondition(); ?>
                <?php if ($acceptancePending): ?>
                    <div class="alert alert--warning"><strong>Aceite ainda pendente.</strong> A condição pode ser corrigida no mesmo processo; o token atual será revogado.</div>
                    <a class="button" href="<?= $h(Url::to('/clientes/upgrade?login=' . rawurlencode((string) ($process['mkauth_login'] ?? '')) . '&correction_of=' . (int) ($contract['id'] ?? 0) . '&process_id=' . $processId)); ?>">Corrigir nova condição</a>
                <?php elseif ($acceptanceAccepted): ?>
                    <div class="alert alert--warning"><strong>Esta condição já foi aceita.</strong> Para alterá-la, é necessário substituir o documento e solicitar novo aceite.</div>
                    <form method="post" action="<?= $h(Url::to('/clientes/upgrade/corrigir')); ?>" class="form-grid" data-prevent-double-submit>
                        <?= Csrf::field('client_upgrade_correct:' . (int) ($contract['id'] ?? 0)); ?>
                        <input type="hidden" name="contract_id" value="<?= (int) ($contract['id'] ?? 0); ?>">
                        <input type="hidden" name="process_id" value="<?= $processId; ?>">
                        <label class="field field--span-2"><span>Motivo da substituição</span><textarea name="correction_reason" rows="2" required></textarea></label>
                        <button class="button field--span-2" type="submit" data-submit-label="Preparando...">Substituir condição e solicitar novo aceite</button>
                    </form>
                <?php endif; ?>
                <?php if ($nextUrl !== ''): ?><footer class="migration-workspace__actions"><span></span><span></span><a class="button" href="<?= $h(Url::to($nextUrl)); ?>">Continuar</a></footer><?php endif; ?>
            <?php elseif (in_array($stepKey, ['prepare_document', 'send_acceptance', 'confirm_acceptance'], true)): ?>
                <?php $renderCondition(); ?>
                <section class="acceptance-state-card">
                    <span>Aceite do titular</span><strong><?= $acceptanceAccepted ? 'Confirmado' : 'Aguardando confirmação'; ?></strong>
                    <small><?= $acceptanceAccepted ? 'Documento, abertura/envio e confirmação foram reconciliados.' : 'A assinatura local não conclui a confirmação remota do titular.'; ?></small>
                </section>
                <?php if (!$acceptanceAccepted): ?>
                    <form class="migration-acceptance-compact" method="post" action="<?= $h(Url::to('/clientes/migracao/aceite-preparar')); ?>" data-migration-acceptance-form data-prevent-double-submit>
                        <input type="hidden" name="_csrf" value="<?= $h($csrfToken ?? ''); ?>">
                        <input type="hidden" name="process_id" value="<?= $processId; ?>">
                        <label class="checkbox-field"><input type="checkbox" name="client_absent" value="1" data-client-absent><span><strong>Cliente não está presente</strong><small>O titular assinará e confirmará no próprio aparelho.</small></span></label>
                        <label class="field" data-remote-reason hidden><span>Motivo da assinatura remota</span><input name="remote_signature_reason" maxlength="500"></label>
                        <div data-local-signature>
                            <div class="signature-pad" data-signature-pad><canvas data-signature-canvas aria-label="Área para assinatura local"></canvas><input type="hidden" name="assinatura_cliente" data-signature-input><div class="signature-actions"><button class="button button--ghost" type="button" data-signature-clear>Limpar assinatura</button></div><p class="field-help" data-signature-help>Peça ao titular para assinar no aparelho do técnico.</p></div>
                        </div>
                        <div class="acceptance-channels-grid">
                            <label class="checkbox-field"><input type="checkbox" name="channel_whatsapp" value="1" checked><span><strong>WhatsApp</strong><small><?= $h($phone !== '' ? $phone : 'Não cadastrado'); ?></small></span></label>
                            <label class="field"><span>Telefone</span><input name="phone" inputmode="tel" value="<?= $h($phone); ?>"></label>
                            <label class="checkbox-field"><input type="checkbox" name="channel_email" value="1" <?= filter_var($email, FILTER_VALIDATE_EMAIL) ? 'checked' : ''; ?>><span><strong>E-mail</strong><small><?= $h($email !== '' ? $email : 'Não cadastrado'); ?></small></span></label>
                            <label class="field"><span>E-mail</span><input name="email" type="email" value="<?= $h($email); ?>"></label>
                        </div>
                        <p class="alert alert--warning">Homologação: os canais permanecem em dry-run; nenhum envio real será feito.</p>
                        <footer class="migration-workspace__actions"><?php if ($previousUrl !== ''): ?><a class="button button--ghost" href="<?= $h(Url::to($previousUrl)); ?>">Voltar</a><?php else: ?><span></span><?php endif; ?><a class="button button--ghost" href="<?= $h(Url::to('/clientes/detalhe?login=' . rawurlencode((string) ($process['mkauth_login'] ?? '')))); ?>">Salvar e sair</a><button class="button" type="submit" data-submit-label="Preparando...">Preparar e continuar</button></footer>
                    </form>
                <?php elseif ($nextUrl !== ''): ?>
                    <footer class="migration-workspace__actions"><?php if ($previousUrl !== ''): ?><a class="button button--ghost" href="<?= $h(Url::to($previousUrl)); ?>">Voltar</a><?php else: ?><span></span><?php endif; ?><span></span><a class="button" href="<?= $h(Url::to($nextUrl)); ?>">Continuar</a></footer>
                <?php endif; ?>
            <?php elseif ($stepKey === 'change_plan'): ?>
                <?php $renderCondition(); ?>
                <section class="process-dry-run"><h3>Alteração de plano em dry-run</h3><p><?= $h($dryRun['message'] ?? 'Simulação indisponível.'); ?></p><?php if (is_array($dryRun)): ?><div class="summary-grid"><div class="summary-item"><span>Antes</span><strong><?= $h($dryRun['before']['plan'] ?? '-'); ?></strong></div><div class="summary-item"><span>Depois</span><strong><?= $h($dryRun['after']['plan'] ?? '-'); ?></strong></div><div class="summary-item"><span>Escrita</span><strong><?= !empty($dryRun['write_enabled']) ? 'Configurada' : 'Bloqueada'; ?></strong></div></div><?php endif; ?></section>
                <?php $renderManualForm(['simulate' => true, 'label' => 'Observação da simulação']); ?>
            <?php elseif (in_array($stepKey, ['technical_execution', 'confirm_equipment', 'validate_connection'], true)): ?>
                <section class="connection-status-compact"><span>PPPoE · consulta somente leitura</span><strong><?= !empty($connection['online']) ? 'Online' : 'Offline ou indisponível'; ?></strong></section>
                <?php $renderManualForm(['label' => match ($stepKey) { 'technical_execution' => 'Serviço executado e equipamentos', 'confirm_equipment' => 'Confirmação do equipamento', default => 'Evidência da conexão' }, 'external' => $stepKey === 'technical_execution' ? 'Equipamento ou referência' : '']); ?>
            <?php elseif (in_array($stepKey, ['open_financial_ticket', 'follow_financial_ticket'], true)): ?>
                <p class="page-description">Use apenas a tarefa financeira compartilhada. Abertura real continua bloqueada nesta homologação.</p>
                <?php $renderManualForm(['refresh' => true, 'external' => 'Identificador do chamado', 'label' => $stepKey === 'open_financial_ticket' ? 'Abertura ou evidência do chamado' : 'Fechamento, data e responsável confirmado']); ?>
            <?php elseif ($stepKey === 'complete_migration'): ?>
                <p class="page-description">A finalização valida todas as etapas obrigatórias. Pendências continuam bloqueando a conclusão normal.</p>
                <form method="post" action="<?= $h(Url::to('/processos/concluir')); ?>" class="form-grid" data-prevent-double-submit><input type="hidden" name="_csrf" value="<?= $h($csrfToken ?? ''); ?>"><input type="hidden" name="process_id" value="<?= $processId; ?>"><?php if (!empty($canOverride)): ?><label class="checkbox-field field--span-2"><input type="checkbox" name="override" value="1"><span><strong>Exceção administrativa autorizada</strong><small>Todas as pendências e a justificativa serão auditadas.</small></span></label><label class="field field--span-2"><span>Justificativa</span><textarea name="justification" rows="3"></textarea></label><?php endif; ?><footer class="migration-workspace__actions field--span-2"><?php if ($previousUrl !== ''): ?><a class="button button--ghost" href="<?= $h(Url::to($previousUrl)); ?>">Voltar</a><?php else: ?><span></span><?php endif; ?><a class="button button--ghost" href="<?= $h(Url::to('/clientes/detalhe?login=' . rawurlencode((string) ($process['mkauth_login'] ?? '')))); ?>">Salvar e sair</a><button class="button" type="submit" data-submit-label="Finalizando...">Finalizar migração</button></footer></form>
            <?php else: ?>
                <?php if ($isCompleted && $nextUrl !== ''): ?><p class="alert alert--success">Etapa concluída; a evidência foi preservada.</p><footer class="migration-workspace__actions"><?php if ($previousUrl !== ''): ?><a class="button button--ghost" href="<?= $h(Url::to($previousUrl)); ?>">Voltar</a><?php else: ?><span></span><?php endif; ?><span></span><a class="button" href="<?= $h(Url::to($nextUrl)); ?>">Continuar</a></footer><?php else: ?><?php $renderManualForm(); ?><?php endif; ?>
            <?php endif; ?>
        </section>

        <aside class="migration-checklist" id="migration-checklist" aria-label="Checklist da migração" data-process-steps>
            <header><div><p class="section-heading__eyebrow">Fonte da verdade</p><h2>11 etapas</h2></div><button type="button" data-close-process-steps aria-label="Fechar etapas">×</button></header>
            <div class="migration-checklist__items">
                <?php foreach ($steps as $step): ?>
                    <?php $current = (int) ($step['id'] ?? 0) === (int) ($activeStep['id'] ?? 0); ?>
                    <a class="migration-checklist__step migration-checklist__step--<?= $h($step['status_class'] ?? 'muted'); ?> <?= $current ? 'is-current' : ''; ?>" href="<?= $h(Url::to((string) ($step['url'] ?? '#'))); ?>" <?= $current ? 'aria-current="step"' : ''; ?>>
                        <span><?= (int) ($step['step_order'] ?? 0); ?></span><span><strong><?= $h($step['label'] ?? 'Etapa'); ?></strong><small><?= $h($step['status_label'] ?? 'Não iniciada'); ?></small></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </aside>
        <button class="migration-checklist__backdrop" type="button" data-close-process-steps aria-label="Fechar lista de etapas" hidden></button>
    </div>
</main>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/../layouts/app.php';
