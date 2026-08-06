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
$editContact = !empty($editContact);
$notificationDryRun = is_array($notificationDryRun ?? null) ? $notificationDryRun : ['whatsapp' => true, 'email' => true];
$journey = is_array($journey ?? null) ? $journey : [];
$visibleStages = array_values((array) ($journey['visible_steps'] ?? []));
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
$acceptanceSent = trim((string) ($acceptance['sent_at'] ?? '')) !== '';
$phone = trim((string) ($acceptance['telefone_enviado'] ?? $client['phone'] ?? $contract['telefone_cliente'] ?? ''));
$email = trim((string) ($client['email'] ?? ''));
$h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = static fn (mixed $value): string => is_numeric($value) ? 'R$ ' . number_format((float) $value, 2, ',', '.') : '-';
$dateTime = static function (mixed $value): string {
    $timestamp = strtotime((string) $value);
    return $timestamp !== false ? date('d/m/Y \à\s H:i', $timestamp) : '-';
};
$operationalTechnology = static function (mixed $value): string {
    $value = trim((string) $value);
    $normalized = strtolower($value);
    if (str_contains($normalized, 'radio') || str_contains($normalized, 'rádio') || $normalized === 'd') {
        return 'Rádio';
    }
    if (str_contains($normalized, 'fibra') || str_contains($normalized, 'ftth') || $normalized === 'h') {
        return 'Fibra';
    }
    return $value !== '' ? $value : '-';
};
$phaseFor = static fn (string $key): int => match ($key) {
    'migration_data' => 1,
    'prepare_document', 'send_acceptance', 'confirm_acceptance' => 2,
    'technical_execution', 'confirm_equipment', 'validate_connection' => 3,
    default => 4,
};
$phases = [1 => 'Nova condição', 2 => 'Aceite', 3 => 'Execução técnica', 4 => 'Finalização'];
$currentPhase = (int) ($journey['active_stage'] ?? $phaseFor($stepKey));
$currentStage = is_array($journey['active'] ?? null) ? $journey['active'] : [];
$clientUrl = Url::to('/clientes/detalhe?login=' . rawurlencode((string) ($process['mkauth_login'] ?? '')));
$migrationMoreActions = !$isClosed ? [[
    'tag' => 'button',
    'type' => 'button',
    'label' => 'Cancelar processo',
    'class' => 'button--danger',
    'attrs' => ['data-open-migration-cancel' => true],
]] : [];
$renderMigrationActions = static function (array $configuration) use ($migrationMoreActions): void {
    $migrationActionBar = $configuration;
    $migrationActionBar['more'] = array_values(array_merge(
        (array) ($configuration['more'] ?? []),
        $migrationMoreActions
    ));
    require __DIR__ . '/../components/migration_actions.php';
};
$technicalExecutionStep = [];
$stepsByKey = [];
foreach ($steps as $candidateStep) {
    if (is_array($candidateStep)) {
        $stepsByKey[(string) ($candidateStep['step_key'] ?? '')] = $candidateStep;
        if ((string) ($candidateStep['step_key'] ?? '') === 'technical_execution') {
            $technicalExecutionStep = $candidateStep;
        }
    }
}
$technicalEvidence = is_array($technicalExecutionStep['evidence'] ?? null) ? $technicalExecutionStep['evidence'] : [];
$acceptanceCheckStep = $stepsByKey['confirm_acceptance'] ?? [];
$acceptanceLastCheckedAt = (string) ($acceptanceCheckStep['last_checked_at'] ?? $acceptance['updated_at'] ?? '');
$finalizationItems = [];
$finalizationLastCheckedAt = '';
foreach ([
    ['key' => 'confirm_acceptance', 'label' => 'Aceite confirmado'],
    ['key' => 'technical_execution', 'label' => 'Execução técnica concluída'],
    ['key' => 'change_plan', 'label' => 'Plano aplicado'],
    ['key' => 'change_plan', 'label' => 'Plano confirmado por releitura', 'confirmation' => true],
    ['key' => 'validate_connection', 'label' => 'PPPoE verificado'],
    ['key' => 'open_financial_ticket', 'label' => 'Chamado financeiro aberto'],
    ['key' => 'follow_financial_ticket', 'label' => 'Fechamento financeiro confirmado'],
] as $definition) {
    $itemStep = is_array($stepsByKey[(string) $definition['key']] ?? null)
        ? $stepsByKey[(string) $definition['key']]
        : [];
    $itemStatus = (string) ($itemStep['status'] ?? 'not_started');
    if (!empty($definition['confirmation'])) {
        $confirmed = !empty($itemStep['evidence']['plan_confirmation']['confirmed']);
        $itemStatus = $confirmed ? 'completed' : ($itemStatus === 'attention' ? 'attention' : 'not_started');
    }
    $checkedAt = trim((string) ($itemStep['last_checked_at'] ?? $itemStep['updated_at'] ?? ''));
    if ($checkedAt !== '' && ($finalizationLastCheckedAt === '' || strtotime($checkedAt) > strtotime($finalizationLastCheckedAt))) {
        $finalizationLastCheckedAt = $checkedAt;
    }
    $finalizationItems[] = [
        'label' => (string) $definition['label'],
        'status' => $itemStatus,
        'status_label' => !empty($definition['confirmation']) && empty($confirmed)
            ? 'Confirmação pendente'
            : (string) ($itemStep['status_label'] ?? 'Não iniciada'),
        'pending_reason' => trim((string) ($itemStep['pending_reason'] ?? '')),
        'next_action' => trim((string) ($itemStep['next_action'] ?? '')),
    ];
}
$finalizationPending = array_values(array_filter(
    $finalizationItems,
    static fn (array $item): bool => !in_array((string) $item['status'], ['completed', 'not_applicable'], true)
));
$externalActionDone = false;
foreach (['change_plan', 'open_financial_ticket'] as $externalStepKey) {
    $externalStep = $stepsByKey[$externalStepKey] ?? [];
    $externalStepEvidence = is_array($externalStep['evidence'] ?? null) ? $externalStep['evidence'] : [];
    if ((string) ($externalStep['status'] ?? '') === 'completed' && empty($externalStepEvidence['dry_run'])) {
        $externalActionDone = true;
    }
}
$statusClass = (string) ($activeStep['status_class'] ?? 'muted');
$statusLabel = (string) ($activeStep['status_label'] ?? 'Não iniciada');
$nextUrl = is_array($nextStep) ? (string) ($nextStep['url'] ?? '') : '';
$previousUrl = is_array($previousStep) ? (string) ($previousStep['url'] ?? '') : '';
$renderCondition = static function () use ($migration, $money, $h, $operationalTechnology): void { ?>
    <div class="migration-condition-summary">
        <div class="summary-grid">
            <div class="summary-item"><span>Operação</span><strong><?= $h(match ((string) ($migration['operation_type'] ?? '')) { 'migration' => 'Migração', 'upgrade' => 'Upgrade', 'downgrade' => 'Downgrade', default => '-' }); ?></strong></div>
            <div class="summary-item"><span>Plano atual</span><strong><?= $h($migration['current_plan_name'] ?? '-'); ?></strong></div>
            <div class="summary-item"><span>Nova condição</span><strong><?= $h($migration['new_plan_name'] ?? '-'); ?> · <?= $money($migration['new_monthly_value'] ?? null); ?></strong></div>
            <div class="summary-item"><span>Tecnologia</span><strong><?= $h($operationalTechnology($migration['current_technology'] ?? '-')); ?> → <?= $h($operationalTechnology($migration['new_technology'] ?? '-')); ?></strong></div>
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
            <a class="client-hub__back" href="<?= $h($clientUrl); ?>">← Voltar ao cliente</a>
            <p class="section-heading__eyebrow">Migração #<?= $processId; ?> · Etapa <?= $currentPhase; ?> de 4</p>
            <h1><?= $h($phases[$currentPhase] ?? 'Processo de migração'); ?></h1>
            <p class="page-description"><?= $h($process['client_name'] ?? $process['mkauth_login'] ?? 'Cliente'); ?> · <?= $h($process['mkauth_login'] ?? ''); ?></p>
        </div>
        <div class="migration-workspace__progress" aria-label="Progresso do processo">
            <strong><?= (int) ($journey['completed'] ?? 0); ?>/4</strong>
            <div class="process-progress"><span style="width: <?= max(0, min(100, (int) floor(((int) ($journey['completed'] ?? 0) * 100) / 4))); ?>%"></span></div>
            <button class="button button--ghost button--small" type="button" data-open-process-steps aria-controls="migration-checklist" aria-expanded="false">Ver as 4 etapas</button>
        </div>
    </header>

    <?php if (!empty($flash)): ?>
        <section class="alert alert--<?= $h($flash['type'] ?? 'success'); ?>" aria-live="polite" tabindex="-1" data-focus-on-load><?= $h($flash['message'] ?? ''); ?></section>
    <?php endif; ?>

    <nav class="migration-phase-legend" aria-label="Etapas da migração">
        <?php foreach ($visibleStages as $stage): ?><?php $visualState = (string) ($stage['visual_state'] ?? 'not_started'); ?><a href="<?= $h(Url::to((string) ($stage['url'] ?? '#'))); ?>" class="is-<?= $h($visualState); ?>" <?= $visualState === 'current' ? 'aria-current="step"' : ''; ?>><b><?= $visualState === 'completed' ? '✓' : (int) ($stage['number'] ?? 0); ?></b><?= $h($stage['label'] ?? 'Etapa'); ?></a><?php endforeach; ?>
    </nav>

    <div class="migration-workspace__grid">
        <section class="card migration-workspace__active" aria-labelledby="active-step-title">
            <header class="migration-active-heading">
                <div><p class="section-heading__eyebrow">Etapa <?= $currentPhase; ?> de 4</p><h2 id="active-step-title"><?= $h($phases[$currentPhase] ?? 'Etapa'); ?></h2></div>
                <span class="process-status process-status--<?= $h($currentStage['status_class'] ?? $statusClass); ?>"><?= $h($currentStage['status_label'] ?? $statusLabel); ?></span>
            </header>

            <?php if ($isClosed && (string) ($process['status'] ?? '') === 'cancelled'): ?>
                <div class="alert alert--warning"><strong>Processo cancelado.</strong> As evidências permanecem no histórico e não há pendência ativa.</div>
                <a class="button" href="<?= $h(Url::to('/clientes/upgrade?login=' . rawurlencode((string) ($process['mkauth_login'] ?? '')))); ?>">Iniciar nova migração</a>
            <?php elseif ($stepKey === 'migration_data'): ?>
                <?php $renderCondition(); ?>
                <?php if ($acceptancePending): ?>
                    <div class="alert alert--warning"><strong>Aceite ainda pendente.</strong> A condição pode ser corrigida no mesmo processo; o token atual será revogado.</div>
                <?php elseif ($acceptanceAccepted): ?>
                    <div class="alert alert--warning"><strong>Esta condição já foi aceita.</strong> Para alterá-la, é necessário substituir o documento e solicitar novo aceite.</div>
                    <form method="post" action="<?= $h(Url::to('/clientes/upgrade/corrigir')); ?>" class="form-grid" data-prevent-double-submit>
                        <?= Csrf::field('client_upgrade_correct:' . (int) ($contract['id'] ?? 0)); ?>
                        <input type="hidden" name="contract_id" value="<?= (int) ($contract['id'] ?? 0); ?>">
                        <input type="hidden" name="process_id" value="<?= $processId; ?>">
                        <label class="field field--span-2"><span>Motivo da substituição</span><textarea name="correction_reason" rows="2" required></textarea></label>
                        <div class="field--span-2"><?php $renderMigrationActions([
                            'primary' => ['tag' => 'button', 'type' => 'submit', 'label' => 'Corrigir nova condição', 'attrs' => ['data-submit-label' => 'Preparando...']],
                            'navigation' => [
                                ['label' => 'Voltar ao cliente', 'href' => $clientUrl, 'class' => 'button--ghost', 'align' => 'start'],
                                ['label' => 'Salvar e sair', 'href' => $clientUrl, 'class' => 'button--ghost'],
                                ['label' => 'Continuar para o aceite', 'href' => Url::to('/processos/migracao?id=' . $processId . '&step=confirm_acceptance')],
                            ],
                        ]); ?></div>
                    </form>
                <?php else: ?>
                    <p class="page-description">A condição está salva. Você pode corrigi-la no mesmo processo antes de avançar.</p>
                <?php endif; ?>
                <?php if (!$acceptanceAccepted): ?>
                    <?php $renderMigrationActions([
                        'primary' => [
                            'label' => 'Corrigir nova condição',
                            'href' => Url::to('/clientes/upgrade?login=' . rawurlencode((string) ($process['mkauth_login'] ?? '')) . '&correction_of=' . (int) ($contract['id'] ?? 0) . '&process_id=' . $processId),
                        ],
                        'navigation' => [
                            ['label' => 'Voltar ao cliente', 'href' => $clientUrl, 'class' => 'button--ghost', 'align' => 'start'],
                            ['label' => 'Salvar e sair', 'href' => $clientUrl, 'class' => 'button--ghost'],
                            ['label' => 'Continuar para o aceite', 'href' => Url::to('/processos/migracao?id=' . $processId . '&step=confirm_acceptance')],
                        ],
                    ]); ?>
                <?php endif; ?>
            <?php elseif (in_array($stepKey, ['prepare_document', 'send_acceptance', 'confirm_acceptance'], true)): ?>
                <?php $renderCondition(); ?>
                <section class="acceptance-state-card">
                    <span>Aceite do titular</span>
                    <strong><?= $acceptanceAccepted ? 'Confirmação recebida' : ($acceptanceSent ? 'Aguardando confirmação do cliente' : 'Pronto para enviar'); ?></strong>
                    <small><?php if ($acceptanceAccepted): ?>Confirmado em <?= $h($dateTime($acceptance['accepted_at'] ?? '')); ?>.<?php elseif ($acceptanceSent): ?>Última verificação: <?= $h($dateTime($acceptanceLastCheckedAt)); ?>.<?php else: ?>Prepare a assinatura e escolha ao menos um canal cadastrado.<?php endif; ?></small>
                </section>
                <?php if (!$acceptanceAccepted): ?>
                    <?php if ($editContact): ?>
                        <form class="form-grid migration-contact-correction" method="post" action="<?= $h(Url::to('/processos/migracao/corrigir-contato')); ?>" data-prevent-double-submit>
                            <input type="hidden" name="_csrf" value="<?= $h($csrfToken ?? ''); ?>">
                            <input type="hidden" name="process_id" value="<?= $processId; ?>">
                            <label class="field"><span>Novo WhatsApp</span><input name="phone" value="<?= $h($phone); ?>" inputmode="tel" required></label>
                            <label class="field"><span>Novo e-mail</span><input name="email" value="<?= $h($email); ?>" inputmode="email"></label>
                            <label class="field field--span-2"><span>Motivo da correção</span><textarea name="reason" rows="2" required></textarea></label>
                            <p class="field-help field--span-2">A correção atualiza o cadastro local do processo e o mesmo aceite. Nenhum contato do MkAuth é alterado por esta ação.</p>
                            <div class="field--span-2"><?php $renderMigrationActions([
                                'primary' => ['tag' => 'button', 'type' => 'submit', 'label' => 'Salvar e voltar ao aceite', 'attrs' => ['data-submit-label' => 'Salvando...']],
                                'navigation' => [
                                    ['label' => 'Voltar sem alterar', 'href' => Url::to('/processos/migracao?id=' . $processId . '&step=confirm_acceptance'), 'class' => 'button--ghost', 'align' => 'start'],
                                    ['label' => 'Salvar e sair', 'href' => $clientUrl, 'class' => 'button--ghost'],
                                    ['tag' => 'button', 'type' => 'button', 'label' => 'Continuar para execução', 'disabled' => true, 'help' => 'A confirmação do titular ainda está pendente.'],
                                ],
                            ]); ?></div>
                        </form>
                    <?php else: ?>
                    <form class="migration-acceptance-compact" method="post" action="<?= $h(Url::to('/clientes/migracao/aceite-preparar')); ?>" data-migration-acceptance-form data-prevent-double-submit>
                        <input type="hidden" name="_csrf" value="<?= $h($csrfToken ?? ''); ?>">
                        <input type="hidden" name="process_id" value="<?= $processId; ?>">
                        <?php if ($acceptanceSent): ?>
                            <input type="hidden" name="resend_only" value="1">
                            <p class="field-help">O reenvio reutiliza o mesmo aceite e o mesmo token. Nenhuma nova assinatura será criada.</p>
                        <?php else: ?>
                            <label class="checkbox-field"><input type="checkbox" name="client_absent" value="1" data-client-absent <?= trim((string) ($acceptance['remote_signature_reason'] ?? '')) !== '' ? 'checked' : ''; ?>><span><strong>Solicitar assinatura remota</strong><small>O titular assinará e confirmará o aceite no próprio aparelho.</small></span></label>
                            <label class="field" data-remote-reason hidden><span>Motivo da assinatura remota</span><select name="remote_signature_reason"><option value="">Selecione o motivo</option><?php foreach (['Titular indisponível', 'Atendimento acompanhado por terceiro', 'Solicitação do cliente', 'Assinatura posterior', 'Outro motivo'] as $remoteReasonOption): ?><option value="<?= $h($remoteReasonOption); ?>" <?= strcasecmp(trim((string) ($acceptance['remote_signature_reason'] ?? '')), $remoteReasonOption) === 0 ? 'selected' : ''; ?>><?= $h($remoteReasonOption); ?></option><?php endforeach; ?></select></label>
                            <div data-local-signature>
                                <strong>Coletar assinatura local</strong>
                                <div class="signature-pad" data-signature-pad><canvas data-signature-canvas aria-label="Área para assinatura local"></canvas><input type="hidden" name="assinatura_cliente" data-signature-input><div class="signature-actions"><button class="button button--ghost" type="button" data-signature-clear>Limpar assinatura</button></div><p class="field-help" data-signature-help>Peça ao titular para assinar no aparelho do técnico.</p></div>
                            </div>
                        <?php endif; ?>
                        <div class="acceptance-channels-grid">
                            <span class="field--span-2">Contatos usados no aceite (somente leitura)</span>
                            <label class="checkbox-field"><input type="checkbox" name="channel_whatsapp" value="1" checked><span><strong>WhatsApp</strong><small><?= $h($phone !== '' ? $phone : 'Não cadastrado'); ?></small></span></label>
                            <label class="checkbox-field"><input type="checkbox" name="channel_email" value="1" <?= filter_var($email, FILTER_VALIDATE_EMAIL) ? 'checked' : ''; ?>><span><strong>E-mail</strong><small><?= $h($email !== '' ? $email : 'Não cadastrado'); ?></small></span></label>
                            <input type="hidden" name="phone" value="<?= $h($phone); ?>">
                            <input type="hidden" name="email" value="<?= $h($email); ?>">
                        </div>
                        <a class="button button--ghost" href="<?= $h(Url::to('/processos/migracao?id=' . $processId . '&step=confirm_acceptance&edit_contact=1')); ?>">Corrigir contato no cadastro</a>
                        <?php if (!empty($notificationDryRun['whatsapp']) && !empty($notificationDryRun['email'])): ?>
                            <p class="alert alert--warning">Homologação: os canais permanecem em dry-run; nenhum envio real será feito.</p>
                        <?php else: ?>
                            <p class="alert alert--warning">Atenção: ao continuar, os canais habilitados fora de dry-run poderão enviar uma mensagem real.</p>
                        <?php endif; ?>
                        <?php $renderMigrationActions([
                            'primary' => $acceptanceSent
                                ? ['label' => 'Atualizar confirmação', 'href' => Url::to('/processos/migracao?id=' . $processId . '&step=confirm_acceptance')]
                                : ['tag' => 'button', 'type' => 'submit', 'label' => 'Enviar confirmação', 'attrs' => ['data-submit-label' => 'Enviando...']],
                            'navigation' => array_values(array_filter([
                                ['label' => 'Voltar para nova condição', 'href' => Url::to('/processos/migracao?id=' . $processId . '&step=migration_data'), 'class' => 'button--ghost', 'align' => 'start'],
                                ['label' => 'Salvar e sair', 'href' => $clientUrl, 'class' => 'button--ghost'],
                                $acceptanceSent ? ['tag' => 'button', 'type' => 'submit', 'label' => 'Reenviar confirmação', 'class' => 'button--ghost', 'attrs' => ['data-submit-label' => 'Reenviando...']] : null,
                                [
                                    'tag' => 'button',
                                    'type' => 'button',
                                    'label' => 'Continuar para execução',
                                    'disabled' => true,
                                    'help' => 'A confirmação do titular ainda está pendente.',
                                ],
                            ], 'is_array')),
                        ]); ?>
                    </form>
                    <?php endif; ?>
                <?php else: ?>
                    <?php $renderMigrationActions([
                        'primary' => ['label' => 'Continuar para execução', 'href' => Url::to('/processos/migracao?id=' . $processId . '&step=technical_execution')],
                        'navigation' => array_values(array_filter([
                            $canOverride ? ['label' => 'Voltar para nova condição', 'href' => Url::to('/processos/migracao?id=' . $processId . '&step=migration_data'), 'class' => 'button--ghost', 'align' => 'start'] : null,
                            ['label' => 'Salvar e sair', 'href' => $clientUrl, 'class' => 'button--ghost'],
                        ], 'is_array')),
                    ]); ?>
                <?php endif; ?>
            <?php elseif ($currentPhase === 4): ?>
                <?php $renderCondition(); ?>
                <section class="migration-finalization-progress" aria-live="polite">
                    <h3>Finalização automática</h3>
                    <p class="page-description">Última atualização: <?= $h($dateTime($finalizationLastCheckedAt)); ?></p>
                    <?php foreach ($finalizationItems as $finalItem): ?>
                        <?php $finalStatus = (string) $finalItem['status']; ?>
                        <div class="migration-finalization-progress__item migration-finalization-progress__item--<?= $h($finalStatus); ?>"><span><?= in_array($finalStatus, ['completed', 'not_applicable'], true) ? '✓' : ($finalStatus === 'attention' ? '!' : ($finalStatus === 'waiting' ? '⟳' : '○')); ?></span><strong><?= $h($finalItem['label']); ?></strong><small><?= $h($finalItem['status_label']); ?></small></div>
                    <?php endforeach; ?>
                </section>
                <?php if ($finalizationPending !== []): ?>
                    <section class="alert alert--warning migration-finalization-pending">
                        <strong>Pendências que continuam abertas:</strong>
                        <ul><?php foreach ($finalizationPending as $pendingItem): ?><li><b><?= $h($pendingItem['label']); ?>.</b> <?= $h($pendingItem['pending_reason'] !== '' ? $pendingItem['pending_reason'] : $pendingItem['status_label']); ?><?php if ($pendingItem['next_action'] !== ''): ?> Próxima ação: <?= $h($pendingItem['next_action']); ?><?php endif; ?></li><?php endforeach; ?></ul>
                    </section>
                <?php endif; ?>
                <?php if (is_array($dryRun)): ?><section class="process-dry-run"><p><?= $h($dryRun['message'] ?? ''); ?></p><div class="summary-grid"><div class="summary-item"><span>Antes</span><strong><?= $h($dryRun['before']['plan'] ?? '-'); ?></strong></div><div class="summary-item"><span>Depois</span><strong><?= $h($dryRun['after']['plan'] ?? '-'); ?></strong></div><div class="summary-item"><span>Escrita externa</span><strong><?= !empty($dryRun['write_enabled']) ? 'Habilitada' : 'Bloqueada'; ?></strong></div></div></section><?php endif; ?>
                <form method="post" action="<?= $h(Url::to('/processos/migracao/finalizar-tecnico')); ?>" class="migration-active-form" data-prevent-double-submit>
                    <input type="hidden" name="_csrf" value="<?= $h($csrfToken ?? ''); ?>"><input type="hidden" name="process_id" value="<?= $processId; ?>"><input type="hidden" name="request_id" value="<?= $h(bin2hex(random_bytes(16))); ?>">
                    <p class="alert alert--warning">Ambiente de testes: escritas externas, notificações e chamado real permanecem bloqueados. Dry-runs não serão registrados como execução real.</p>
                    <p class="page-description">A tentativa retoma da primeira subetapa pendente. Ações já concluídas não são repetidas.</p>
                    <?php $renderMigrationActions([
                        'primary' => [
                            'tag' => 'button',
                            'type' => 'submit',
                            'label' => $finalizationPending === [] ? 'Finalizar atendimento técnico' : 'Atualizar verificações',
                            'attrs' => ['data-submit-label' => $finalizationPending === [] ? 'Finalizando atendimento...' : 'Atualizando verificações...'],
                        ],
                        'navigation' => [
                            ['label' => 'Voltar para execução técnica', 'href' => Url::to('/processos/migracao?id=' . $processId . '&step=technical_execution'), 'class' => 'button--ghost', 'align' => 'start'],
                            ['label' => 'Salvar e sair', 'href' => $clientUrl, 'class' => 'button--ghost'],
                        ],
                    ]); ?>
                </form>
            <?php elseif ($currentPhase === 3): ?>
                <section class="connection-status-compact" data-connection-checker data-connection-initial-online="<?= !empty($connection['online']) ? '1' : '0'; ?>" data-connection-url="<?= $h(Url::to('/api/cliente/conexao?login=' . rawurlencode((string) ($process['mkauth_login'] ?? '')))); ?>">
                    <span>PPPoE · consulta somente leitura</span>
                    <strong data-connection-status><?= !empty($connection['online']) ? 'PPPoE online' : 'PPPoE offline ou indisponível'; ?></strong>
                    <small data-connection-ip <?= empty($connection['online']) ? 'hidden' : ''; ?>>IP: <?= $h($connection['session']['framedipaddress'] ?? '-'); ?></small>
                    <small data-connection-checked><?= !empty($connection['online']) ? 'Verificado às ' : 'Última verificação às '; ?><?= $h($dateTime($connection['checked_at'] ?? '')); ?> · origem: Radius MkAuth</small>
                </section>
                <form method="post" action="<?= $h(Url::to('/processos/migracao/execucao')); ?>" enctype="multipart/form-data" class="migration-active-form" data-prevent-double-submit>
                    <input type="hidden" name="_csrf" value="<?= $h($csrfToken ?? ''); ?>">
                    <input type="hidden" name="process_id" value="<?= $processId; ?>">
                    <div class="form-grid">
                        <label class="field"><span>Equipamento instalado</span><input name="equipment_installed" required value="<?= $h($technicalEvidence['equipment_installed'] ?? ''); ?>"></label>
                        <label class="field"><span>Equipamento retirado</span><input name="equipment_removed" value="<?= $h($technicalEvidence['equipment_removed'] ?? ''); ?>"></label>
                        <label class="field"><span>Serial, MAC ou referência</span><input name="equipment_reference" value="<?= $h($technicalEvidence['equipment_reference'] ?? $technicalExecutionStep['external_reference'] ?? ''); ?>"></label>
                        <div class="field field--span-2 migration-evidence-uploader" data-migration-evidence-uploader>
                            <span>Anexos e evidências</span>
                            <div class="migration-evidence-uploader__choices">
                                <label class="button button--ghost"><input type="file" name="evidence_files[]" accept="image/jpeg,image/png,image/webp,application/pdf" multiple data-migration-evidence-input><span>Selecionar arquivos</span></label>
                                <label class="button button--ghost"><input type="file" name="evidence_files[]" accept="image/*" capture="environment" multiple data-migration-evidence-input><span>Abrir câmera</span></label>
                            </div>
                            <div class="migration-evidence-preview" data-migration-evidence-preview aria-live="polite"></div>
                            <small class="field-help">JPG, PNG, WebP ou PDF. A validação usa o MIME real; os arquivos ficam em armazenamento protegido e vinculados ao processo.</small>
                        </div>
                        <label class="field field--span-2"><span>Observação técnica (opcional)</span><textarea name="observation" rows="3"><?= $h($technicalExecutionStep['observation'] ?? ''); ?></textarea></label>
                        <?php if (!empty($canOverride)): ?>
                            <div class="field--span-2" data-offline-exception <?= !empty($connection['online']) ? 'hidden' : ''; ?>>
                                <label class="checkbox-field"><input type="checkbox" name="offline_override" value="1"><span><strong>Continuar com PPPoE offline</strong><small>Exceção gerencial; exige justificativa e permanece visível na Finalização.</small></span></label>
                                <label class="field"><span>Justificativa da exceção</span><textarea name="offline_justification" rows="2"></textarea></label>
                            </div>
                        <?php endif; ?>
                    </div>
                    <details class="migration-pending-details"><summary>Ficou algo pendente?</summary><div class="form-grid"><label class="field field--span-2"><span>Motivo</span><textarea name="pending_reason" rows="2"></textarea></label><label class="field"><span>Próxima ação</span><input name="next_action"></label><label class="field"><span>Responsável</span><input name="responsible_login"></label><label class="field"><span>Prazo</span><input type="date" name="pending_due_date"></label></div></details>
                    <?php $renderMigrationActions([
                        'primary' => ['tag' => 'button', 'type' => 'button', 'label' => 'Verificar conexão agora', 'attrs' => ['data-check-connection' => true]],
                        'navigation' => [
                            ['label' => 'Voltar para o aceite', 'href' => Url::to('/processos/migracao?id=' . $processId . '&step=confirm_acceptance'), 'class' => 'button--ghost', 'align' => 'start'],
                            ['tag' => 'button', 'type' => 'submit', 'name' => 'continue_to', 'value' => 'exit', 'label' => 'Salvar e sair', 'class' => 'button--ghost', 'attrs' => ['formnovalidate' => true, 'data-submit-label' => 'Salvando...']],
                            [
                                'tag' => 'button',
                                'type' => 'submit',
                                'name' => 'continue_to',
                                'value' => 'finalization',
                                'label' => 'Continuar para finalização',
                                'disabled' => empty($connection['online']),
                                'attrs' => ['data-continue-finalization' => true, 'data-submit-label' => 'Concluindo...'],
                                'help' => empty($connection['online']) ? 'Verifique a conexão ou registre uma exceção autorizada com justificativa.' : '',
                            ],
                        ],
                    ]); ?>
                </form>
                <?php if (!empty($technicalEvidence['files'])): ?>
                    <section class="migration-evidence-list"><h3>Evidências anexadas</h3>
                        <?php foreach ((array) $technicalEvidence['files'] as $file): ?><?php $fileUrl = Url::to('/processos/migracao/evidencia?process_id=' . $processId . '&evidence_id=' . rawurlencode((string) ($file['id'] ?? ''))); ?><article><?php if (str_starts_with((string) ($file['mime_type'] ?? ''), 'image/')): ?><img class="migration-evidence-thumb" src="<?= $h($fileUrl); ?>" alt="Prévia da evidência"><?php endif; ?><a href="<?= $h($fileUrl); ?>" target="_blank" rel="noopener"><?= $h($file['original_name'] ?? 'Evidência'); ?></a><small><?= $h($file['mime_type'] ?? ''); ?></small><?php if (!in_array((string) ($technicalExecutionStep['status'] ?? ''), ['completed', 'not_applicable'], true) && !$isClosed): ?><form method="post" action="<?= $h(Url::to('/processos/migracao/evidencia/remover')); ?>" data-prevent-double-submit><input type="hidden" name="_csrf" value="<?= $h($csrfToken ?? ''); ?>"><input type="hidden" name="process_id" value="<?= $processId; ?>"><input type="hidden" name="evidence_id" value="<?= $h($file['id'] ?? ''); ?>"><button class="button button--ghost button--small" type="submit" data-submit-label="Removendo...">Remover</button></form><?php endif; ?></article><?php endforeach; ?>
                    </section>
                <?php endif; ?>
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
            <header><div><p class="section-heading__eyebrow">Jornada da migração</p><h2>4 etapas</h2></div><button type="button" data-close-process-steps aria-label="Fechar etapas">×</button></header>
            <div class="migration-checklist__items">
                <?php foreach ($visibleStages as $stage): ?>
                    <?php $visualState = (string) ($stage['visual_state'] ?? 'not_started'); ?>
                    <a class="migration-checklist__step is-<?= $h($visualState); ?>" href="<?= $h(Url::to((string) ($stage['url'] ?? '#'))); ?>" <?= $visualState === 'current' ? 'aria-current="step"' : ''; ?>>
                        <span><?= $visualState === 'completed' ? '✓' : (int) ($stage['number'] ?? 0); ?></span><span><strong><?= $h($stage['label'] ?? 'Etapa'); ?></strong><small><?= $h($stage['status_label'] ?? 'Não iniciada'); ?></small></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <details class="migration-technical-details">
                <summary>Ver detalhes técnicos do processo</summary>
                <ol>
                    <?php foreach ($steps as $technicalStep): ?>
                        <li><span><?= $h($technicalStep['label'] ?? 'Etapa técnica'); ?></span><small><?= $h($technicalStep['status_label'] ?? 'Não iniciada'); ?></small></li>
                    <?php endforeach; ?>
                </ol>
            </details>
        </aside>
        <button class="migration-checklist__backdrop" type="button" data-close-process-steps aria-label="Fechar lista de etapas" hidden></button>
    </div>
</main>
<?php if (!$isClosed): ?>
<dialog class="migration-cancel-dialog" data-migration-cancel-dialog aria-labelledby="migration-cancel-title">
    <form method="post" action="<?= $h(Url::to('/processos/cancelar')); ?>" class="form-grid" data-prevent-double-submit>
        <input type="hidden" name="_csrf" value="<?= $h($csrfToken ?? ''); ?>"><input type="hidden" name="process_id" value="<?= $processId; ?>">
        <header class="field--span-2"><p class="section-heading__eyebrow">Cancelar migração</p><h2 id="migration-cancel-title">O histórico será preservado</h2></header>
        <label class="field field--span-2"><span>Motivo</span><textarea name="reason" rows="3" required></textarea></label>
        <?php if ($acceptanceAccepted): ?><label class="checkbox-field field--span-2"><input type="checkbox" name="confirm_accepted" value="1" required><span><strong>Confirmo o cancelamento após aceite</strong><small>Este documento já foi aceito. Ele será preservado como cancelado ou substituído, e uma nova migração exigirá novo aceite.</small></span></label><?php endif; ?>
        <?php if ($externalActionDone): ?><div class="alert alert--warning field--span-2">Há ação externa registrada. Não existe rollback automático comprovado.</div><label class="checkbox-field field--span-2"><input type="checkbox" name="confirm_external" value="1" required><span><strong>Confirmo a pendência de correção/reversão</strong><small>Apenas gestores podem continuar.</small></span></label><label class="field field--span-2"><span>Justificativa e ação de correção</span><textarea name="reversal_justification" rows="3" required></textarea></label><?php endif; ?>
        <label class="checkbox-field field--span-2"><input type="radio" name="after_cancel" value="client" checked><span><strong>Cancelar e voltar ao cliente</strong></span></label>
        <label class="checkbox-field field--span-2"><input type="radio" name="after_cancel" value="restart"><span><strong>Cancelar e iniciar nova migração</strong><small>Será criado outro processo em rascunho, sem reutilizar contrato, token ou aceite.</small></span></label>
        <footer class="migration-workspace__actions field--span-2"><button class="button button--ghost" type="button" data-close-migration-cancel>Voltar sem cancelar</button><span></span><button class="button button--danger" type="submit" data-submit-label="Cancelando...">Confirmar cancelamento</button></footer>
    </form>
</dialog>
<?php endif; ?>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/../layouts/app.php';
