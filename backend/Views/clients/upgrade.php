<?php

declare(strict_types=1);

use App\Core\Url;

$context = is_array($context ?? null) ? $context : [];
$form = is_array($form ?? null) ? $form : [];
$errors = is_array($errors ?? null) ? $errors : [];
$client = is_array($context['clientProfile'] ?? null) ? $context['clientProfile'] : [];
$plans = is_array($context['planOptions'] ?? null) ? $context['planOptions'] : [];
$login = (string) ($context['login'] ?? $currentLogin ?? '');
$currentPlan = trim((string) ($context['current_plan'] ?? ''));
$currentTechnology = trim((string) ($context['current_technology'] ?? ''));
$currentValue = $context['current_monthly_value'] ?? null;
$selectedPlan = trim((string) ($form['new_plan_id'] ?? $form['novo_plano'] ?? $context['new_plan'] ?? ''));
$benefitValue = number_format((float) ($form['valor_beneficio'] ?? $context['benefit_value'] ?? 0), 2, ',', '.');
$benefitFlags = is_array($form['benefit_flags'] ?? null)
    ? $form['benefit_flags']
    : (is_array($context['benefit_flags'] ?? null) ? $context['benefit_flags'] : []);
$automaticBenefitFlags = is_array($form['benefit_automatic_flags'] ?? null)
    ? $form['benefit_automatic_flags']
    : (is_array($context['benefit_automatic_flags'] ?? null) ? $context['benefit_automatic_flags'] : []);
$retention = !empty($benefitFlags['retention']);
$applyFidelity = !empty($form) ? !empty($form['apply_fidelity']) : !empty($context['apply_fidelity']);
$fidelityMonths = $applyFidelity && array_key_exists('fidelidade_meses', $form)
    ? (int) $form['fidelidade_meses']
    : max(1, min(12, (int) ($context['fidelity_months'] ?? 12)));
$fidelityDescription = trim((string) ($form['fidelity_benefit_description'] ?? $context['fidelity_benefit_description'] ?? ''));
$otherBenefit = trim((string) ($form['beneficio_outro_text'] ?? $context['benefit_other_text'] ?? ''));
$otherBenefitValue = number_format((float) ($form['beneficio_outro_valor'] ?? $context['benefit_other_value'] ?? 0), 2, ',', '.');
$benefitAdjustmentReason = trim((string) ($form['benefit_adjustment_reason'] ?? ''));
$observation = trim((string) ($form['observacao'] ?? $context['observacao'] ?? ''));
$correctionOf = (int) ($correctionOf ?? 0);
$correctionReason = trim((string) ($correctionReason ?? ''));
$processId = (int) ($processId ?? 0);
$revisionMode = trim((string) ($revisionMode ?? ''));
$adhesionDefault = (float) ($context['adhesion_default_value'] ?? 0);
$waiverMode = (string) ($context['adhesion_waiver_mode'] ?? 'disabled');
$automaticBenefitValue = (float) ($context['benefit_automatic_value'] ?? 0);
$canAdjustCommercial = !empty($context['can_adjust_commercial']);
$errorFor = static fn (string $field): string => trim((string) ($errors[$field] ?? ''));
$firstErrorField = $errors !== [] ? (string) array_key_first($errors) : '';
$operationLabels = ['migration' => 'Migração', 'upgrade' => 'Upgrade', 'downgrade' => 'Downgrade'];
$operation = (string) ($form['operation_type'] ?? '');
$operationalTechnology = static function (string $value): string {
    $normalized = strtolower(trim($value));
    if (str_contains($normalized, 'radio') || str_contains($normalized, 'rádio') || $normalized === 'd') {
        return 'Rádio';
    }
    if (str_contains($normalized, 'fibra') || str_contains($normalized, 'ftth') || $normalized === 'h') {
        return 'Fibra';
    }

    return $value !== '' ? $value : 'Não identificada';
};
$currentPlanOption = [];
foreach ($plans as $plan) {
    if (strcasecmp((string) ($plan['id'] ?? ''), $currentPlan) === 0 || strcasecmp((string) ($plan['name'] ?? ''), $currentPlan) === 0) {
        $currentPlanOption = $plan;
        break;
    }
}

ob_start();
?>
<main class="migration-workspace migration-workspace--condition" data-migration-workspace>
<header class="migration-workspace__header">
    <div>
        <p class="section-heading__eyebrow">Etapa 1 de 4</p>
        <h1>Nova condição</h1>
        <p class="page-description">Escolha o novo plano. Tecnologia, valor e tipo da operação são derivados dos dados oficiais do plano.</p>
    </div>
    <div class="migration-workspace__progress">
        <strong>1/4</strong>
        <div class="process-progress"><span style="width: 25%"></span></div>
        <button class="button button--ghost button--small" type="button" data-open-process-steps aria-controls="migration-checklist" aria-expanded="false">Ver as 4 etapas</button>
    </div>
</header>

<nav class="migration-phase-legend" aria-label="Etapas da migração">
    <?php foreach ([1 => 'Nova condição', 2 => 'Aceite', 3 => 'Execução técnica', 4 => 'Finalização'] as $stageNumber => $stageLabel): ?>
        <span class="<?= $stageNumber === 1 ? 'is-current' : ''; ?>"><b><?= $stageNumber; ?></b><?= htmlspecialchars($stageLabel, ENT_QUOTES, 'UTF-8'); ?></span>
    <?php endforeach; ?>
</nav>

<div class="migration-workspace__grid">
<div class="migration-workspace__condition-column">

<?php if ($correctionOf > 0): ?>
    <section class="alert alert--warning" aria-live="polite">
        <strong>Correção do contrato #<?= $correctionOf; ?></strong>
        <p><?= $revisionMode === 'pending'
            ? 'A condição pendente será revisada no mesmo processo. O token anterior será invalidado sem apagar o histórico.'
            : 'A condição já aceita será substituída por nova versão e novo aceite, sem apagar o histórico anterior.'; ?></p>
    </section>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <section class="alert alert--error" role="alert" tabindex="-1" data-focus-on-load>
        <strong>Revise os campos indicados:</strong>
        <ul>
            <?php foreach ($errors as $message): ?>
                <li><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8'); ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<section class="card upgrade-current-summary" aria-labelledby="current-condition-title">
    <div class="section-heading">
        <p class="section-heading__eyebrow">Conferência</p>
        <h2 id="current-condition-title">Condição atual</h2>
    </div>
    <div class="summary-grid">
        <div class="summary-item"><span>Cliente</span><strong><?= htmlspecialchars((string) ($client['nome'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
        <div class="summary-item"><span>Login</span><strong><?= htmlspecialchars($login, ENT_QUOTES, 'UTF-8'); ?></strong></div>
        <div class="summary-item"><span>Plano atual</span><strong><?= htmlspecialchars($currentPlan !== '' ? $currentPlan : '-', ENT_QUOTES, 'UTF-8'); ?></strong></div>
        <div class="summary-item"><span>Valor atual</span><strong><?= $currentValue !== null ? 'R$ ' . number_format((float) $currentValue, 2, ',', '.') : '-'; ?></strong></div>
        <div class="summary-item"><span>Tecnologia atual</span><strong><?= htmlspecialchars($operationalTechnology($currentTechnology), ENT_QUOTES, 'UTF-8'); ?></strong></div>
    </div>
</section>

<form class="card" method="post" action="<?= htmlspecialchars(Url::to('/clientes/upgrade'), ENT_QUOTES, 'UTF-8'); ?>" data-upgrade-simple-form data-prevent-double-submit
    data-current-plan="<?= htmlspecialchars((string) ($currentPlanOption['id'] ?? $currentPlan), ENT_QUOTES, 'UTF-8'); ?>"
    data-current-family="<?= htmlspecialchars((string) ($currentPlanOption['install_type'] ?? $context['current_technology_family'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
    data-current-speed="<?= htmlspecialchars((string) ($currentPlanOption['speed_down'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
    data-current-value="<?= htmlspecialchars((string) ($currentValue ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
    data-auto-fidelity-migration="<?= !empty($context['auto_fidelity_migration']) ? '1' : '0'; ?>"
    data-auto-fidelity-upgrade="<?= !empty($context['auto_fidelity_upgrade']) ? '1' : '0'; ?>"
    data-suggest-retention-downgrade="<?= !empty($context['suggest_retention_downgrade']) ? '1' : '0'; ?>">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) ($csrfToken ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="login" value="<?= htmlspecialchars($login, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="process_id" value="<?= $processId; ?>">
    <?php if ($correctionOf > 0): ?>
        <input type="hidden" name="correction_of" value="<?= $correctionOf; ?>">
        <input type="hidden" name="revision_mode" value="<?= htmlspecialchars($revisionMode, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>

    <div class="section-heading">
        <p class="section-heading__eyebrow">Alteração</p>
        <h2>Nova condição contratada</h2>
    </div>

    <div class="form-grid">
        <?php if ($correctionOf > 0): ?>
            <label class="field field--span-2" for="correction-reason">
                <span>Motivo da correção</span>
                <textarea id="correction-reason" name="correction_reason" rows="2" required aria-describedby="correction-reason-error" <?= $firstErrorField === 'correction_reason' ? 'data-focus-field' : ''; ?>><?= htmlspecialchars($correctionReason, ENT_QUOTES, 'UTF-8'); ?></textarea>
                <small class="field-help">O motivo ficará no histórico da revisão e da revogação do aceite anterior.</small>
                <?php if ($errorFor('correction_reason') !== ''): ?><small id="correction-reason-error" class="field-error"><?= htmlspecialchars($errorFor('correction_reason'), ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
            </label>
        <?php endif; ?>
        <?php if (count($plans) > 10): ?>
            <label class="field field--span-2" for="plan-search">
                <span>Buscar plano</span>
                <input id="plan-search" type="search" data-simple-plan-search placeholder="Digite nome, velocidade ou valor" autocomplete="off">
            </label>
        <?php endif; ?>
        <label class="field field--span-2" for="novo-plano">
            <span>Novo plano</span>
            <select id="novo-plano" name="novo_plano" required data-simple-plan-select aria-describedby="novo-plano-help novo-plano-error" <?= $firstErrorField === 'novo_plano' ? 'data-focus-field' : ''; ?>>
                <option value="">Selecione o novo plano</option>
                <?php foreach ($plans as $plan): ?>
                    <?php
                    $id = (string) ($plan['id'] ?? '');
                    $name = trim((string) ($plan['name'] ?? $id));
                    $speed = trim((string) ($plan['speed_down'] ?? ''));
                    $value = trim((string) ($plan['value'] ?? ''));
                    $technology = trim((string) ($plan['technology_label'] ?? 'Tecnologia não identificada'));
                    $label = trim((string) ($plan['label'] ?? $name));
                    ?>
                    <option value="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>"
                        data-plan-name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>"
                        data-plan-speed="<?= htmlspecialchars($speed, ENT_QUOTES, 'UTF-8'); ?>"
                        data-plan-value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"
                        data-plan-technology="<?= htmlspecialchars($technology, ENT_QUOTES, 'UTF-8'); ?>"
                        data-plan-family="<?= htmlspecialchars((string) ($plan['install_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                        <?= strcasecmp($id, $selectedPlan) === 0 ? 'selected' : ''; ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <small id="novo-plano-help" class="field-help">A lista mostra somente a condição comercial. Código, tecnologia e velocidades originais permanecem no snapshot interno.</small>
            <?php if ($errorFor('novo_plano') !== ''): ?><small id="novo-plano-error" class="field-error"><?= htmlspecialchars($errorFor('novo_plano'), ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
        </label>

        <div class="field field--span-2" aria-live="polite">
            <span>Operação calculada</span>
            <strong class="upgrade-operation-result" data-simple-operation><?= htmlspecialchars($operationLabels[$operation] ?? 'Selecione um plano', ENT_QUOTES, 'UTF-8'); ?></strong>
            <small class="field-help">Troca de tecnologia = migração; condição superior = upgrade; condição inferior = downgrade.</small>
        </div>

        <fieldset class="field field--span-2 benefit-conditions" data-benefit-conditions
            data-adhesion-default="<?= htmlspecialchars((string) $adhesionDefault, ENT_QUOTES, 'UTF-8'); ?>"
            data-waiver-mode="<?= htmlspecialchars($waiverMode, ENT_QUOTES, 'UTF-8'); ?>"
            data-automatic-flags="<?= htmlspecialchars(json_encode($automaticBenefitFlags, JSON_UNESCAPED_UNICODE) ?: '{}', ENT_QUOTES, 'UTF-8'); ?>">
            <legend>Benefícios e condições</legend>
            <input type="hidden" name="benefit_choices_present" value="1">
            <div class="benefit-checkbox-grid">
                <?php foreach ([
                    'radio_to_fiber' => ['Migração Rádio → Fibra', 'Mudança de tecnologia confirmada pelo catálogo.'],
                    'adhesion_waiver' => ['Isenção de adesão/instalação', 'Usa o valor configurado, sem herdar isenção de outra operação.'],
                    'plan_upgrade' => ['Upgrade de plano', 'Aumento de velocidade ou condição comercial.'],
                    'retention' => ['Condição de retenção', 'Exige vantagem real e justificativa.'],
                    'other_benefit' => ['Outro benefício', 'Exige descrição e pode possuir valor.'],
                ] as $flag => [$label, $help]): ?>
                    <label class="checkbox-field">
                        <input type="checkbox" name="benefit_flags[]" value="<?= htmlspecialchars($flag, ENT_QUOTES, 'UTF-8'); ?>" data-benefit-flag="<?= htmlspecialchars($flag, ENT_QUOTES, 'UTF-8'); ?>" <?= !empty($benefitFlags[$flag]) ? 'checked' : ''; ?> <?= !$canAdjustCommercial ? 'disabled' : ''; ?>>
                        <span><strong><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></strong><small><?= htmlspecialchars($help, ENT_QUOTES, 'UTF-8'); ?></small></span>
                    </label>
                <?php endforeach; ?>
                <label class="checkbox-field" for="apply-fidelity">
                    <input type="hidden" name="fidelity_choice_present" value="1">
                    <input id="apply-fidelity" type="checkbox" name="apply_fidelity" value="1" data-fidelity-toggle <?= $applyFidelity ? 'checked' : ''; ?> <?= !$canAdjustCommercial ? 'disabled' : ''; ?>>
                    <span><strong>Aplicar nova fidelidade</strong><small>Somente com benefício real, valor, descrição e prazo válido.</small></span>
                </label>
            </div>
            <?php if ($canAdjustCommercial): ?><small class="field-help">As marcações são preenchidas automaticamente. Qualquer divergência exige justificativa e fica auditada.</small><?php else: ?><small class="field-help">Seleção automática somente leitura para este perfil.</small><?php endif; ?>
            <ul class="automatic-condition-list" aria-live="polite">
                <li data-adhesion-charged>Valor cobrado: R$ 0,00</li>
                <li data-adhesion-benefit>Benefício concedido: R$ 0,00</li>
            </ul>
        </fieldset>

        <details class="field field--span-2 commercial-adjustments" <?= ($retention || $applyFidelity || !empty($benefitFlags['other_benefit']) || $benefitAdjustmentReason !== '') ? 'open' : ''; ?>>
            <summary>Ajustar benefício</summary>
            <div class="form-grid">
                <label class="field" for="automatic-benefit-value">
                    <span>Valor automático</span>
                    <input id="automatic-benefit-value" value="<?= number_format($automaticBenefitValue, 2, ',', '.'); ?>" readonly data-automatic-benefit-value>
                </label>
                <label class="field" for="benefit-value">
                    <span>Valor final</span>
                    <input id="benefit-value" name="valor_beneficio" value="<?= htmlspecialchars($benefitValue, ENT_QUOTES, 'UTF-8'); ?>" inputmode="decimal" aria-describedby="benefit-value-error" <?= !$canAdjustCommercial ? 'readonly' : ''; ?> <?= $firstErrorField === 'valor_beneficio' ? 'data-focus-field' : ''; ?>>
                    <?php if ($errorFor('valor_beneficio') !== ''): ?><small id="benefit-value-error" class="field-error"><?= htmlspecialchars($errorFor('valor_beneficio'), ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                </label>
                <label class="field field--span-2" for="benefit-adjustment-reason">
                    <span>Justificativa do ajuste</span>
                    <input id="benefit-adjustment-reason" name="benefit_adjustment_reason" value="<?= htmlspecialchars($benefitAdjustmentReason, ENT_QUOTES, 'UTF-8'); ?>" maxlength="500" aria-describedby="benefit-adjustment-reason-error" <?= !$canAdjustCommercial ? 'readonly' : ''; ?>>
                    <small class="field-help">Obrigatória somente quando seleção, valor ou fidelidade diferirem do cálculo automático.</small>
                    <?php if ($errorFor('benefit_adjustment_reason') !== ''): ?><small id="benefit-adjustment-reason-error" class="field-error"><?= htmlspecialchars($errorFor('benefit_adjustment_reason'), ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                </label>
                <div class="form-grid field--span-2" data-other-benefit-fields <?= empty($benefitFlags['other_benefit']) ? 'hidden' : ''; ?>>
                    <label class="field" for="other-benefit">
                        <span>Descrição do outro benefício</span>
                        <input id="other-benefit" name="beneficio_outro_text" value="<?= htmlspecialchars($otherBenefit, ENT_QUOTES, 'UTF-8'); ?>" maxlength="500" aria-describedby="other-benefit-error" <?= empty($benefitFlags['other_benefit']) ? 'disabled' : ''; ?>>
                        <?php if ($errorFor('beneficio_outro_text') !== ''): ?><small id="other-benefit-error" class="field-error"><?= htmlspecialchars($errorFor('beneficio_outro_text'), ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                    </label>
                    <label class="field" for="other-benefit-value"><span>Valor do outro benefício</span><input id="other-benefit-value" name="beneficio_outro_valor" value="<?= htmlspecialchars($otherBenefitValue, ENT_QUOTES, 'UTF-8'); ?>" inputmode="decimal" <?= empty($benefitFlags['other_benefit']) ? 'disabled' : ''; ?>></label>
                </div>
                <fieldset class="field field--span-2 fidelity-fieldset" data-fidelity-fields <?= !$applyFidelity ? 'hidden' : ''; ?>>
                    <legend>Fidelidade</legend>
                    <div class="form-grid">
                        <label class="field field--span-2" for="fidelity-description">
                            <span>Benefício que justifica a fidelidade</span>
                            <input id="fidelity-description" name="fidelity_benefit_description" value="<?= htmlspecialchars($fidelityDescription, ENT_QUOTES, 'UTF-8'); ?>" maxlength="500" aria-describedby="fidelity-description-error" <?= !$applyFidelity ? 'disabled' : ''; ?>>
                            <?php if ($errorFor('fidelity_benefit_description') !== ''): ?><small id="fidelity-description-error" class="field-error"><?= htmlspecialchars($errorFor('fidelity_benefit_description'), ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                        </label>
                        <label class="field" for="fidelity-months"><span>Prazo de permanência</span><input id="fidelity-months" type="number" name="fidelidade_meses" min="1" max="12" value="<?= $fidelityMonths; ?>" aria-describedby="fidelity-months-error" <?= !$applyFidelity ? 'disabled' : ''; ?>><?php if ($errorFor('fidelidade_meses') !== ''): ?><small id="fidelity-months-error" class="field-error"><?= htmlspecialchars($errorFor('fidelidade_meses'), ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?></label>
                    </div>
                </fieldset>
            </div>
        </details>

        <label class="field field--span-2" for="upgrade-observation">
            <span>Observação</span>
            <textarea id="upgrade-observation" name="observacao" rows="4" maxlength="2000" aria-describedby="upgrade-observation-error" <?= $firstErrorField === 'observacao' ? 'data-focus-field' : ''; ?>><?= htmlspecialchars($observation, ENT_QUOTES, 'UTF-8'); ?></textarea>
            <?php if ($errorFor('observacao') !== ''): ?><small id="upgrade-observation-error" class="field-error"><?= htmlspecialchars($errorFor('observacao'), ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
        </label>

        <div class="field--span-2">
            <?php
            $migrationActionBar = [
                'back' => [
                    'label' => 'Voltar ao cliente',
                    'href' => Url::to('/clientes/detalhe?login=' . rawurlencode($login)),
                ],
                'primary' => [
                    'tag' => 'button',
                    'type' => 'submit',
                    'name' => 'next_action',
                    'value' => 'continue',
                    'label' => 'Continuar para o aceite',
                    'attrs' => ['data-submit-label' => 'Salvando...'],
                ],
                'more' => array_values(array_filter([
                    $correctionOf > 0 ? [
                        'tag' => 'button',
                        'type' => 'submit',
                        'name' => 'next_action',
                        'value' => 'stay',
                        'label' => 'Corrigir nova condição',
                        'attrs' => ['data-submit-label' => 'Salvando...'],
                    ] : null,
                    ['tag' => 'button', 'type' => 'submit', 'name' => 'next_action', 'value' => 'later', 'label' => 'Salvar e sair', 'class' => 'button--ghost', 'attrs' => ['data-submit-label' => 'Salvando...']],
                    $processId > 0 ? [
                    'tag' => 'button',
                    'type' => 'button',
                    'label' => 'Cancelar processo',
                    'class' => 'button--danger',
                    'attrs' => ['data-open-migration-cancel' => true],
                    ] : null,
                ], 'is_array')),
            ];
            require __DIR__ . '/../components/migration_actions.php';
            ?>
        </div>
    </div>
</form>
</div>

<aside class="migration-checklist" id="migration-checklist" aria-label="Jornada da migração" data-process-steps>
    <header><div><p class="section-heading__eyebrow">Jornada da migração</p><h2>4 etapas</h2></div><button type="button" data-close-process-steps aria-label="Fechar etapas">×</button></header>
    <div class="migration-checklist__items">
        <?php foreach ([1 => 'Nova condição', 2 => 'Aceite', 3 => 'Execução técnica', 4 => 'Finalização'] as $stageNumber => $stageLabel): ?>
            <div class="migration-checklist__step <?= $stageNumber === 1 ? 'is-current migration-checklist__step--info' : 'migration-checklist__step--muted'; ?>" <?= $stageNumber === 1 ? 'aria-current="step"' : ''; ?>><span><?= $stageNumber; ?></span><span><strong><?= htmlspecialchars($stageLabel, ENT_QUOTES, 'UTF-8'); ?></strong><small><?= $stageNumber === 1 ? 'Em andamento' : 'Não iniciada'; ?></small></span></div>
        <?php endforeach; ?>
    </div>
    <details class="migration-technical-details">
        <summary>Ver detalhes técnicos do processo</summary>
        <ol><?php foreach (['Dados da migração', 'Preparar documento', 'Enviar ou abrir aceite', 'Confirmar aceite', 'Executar instalação ou troca', 'Confirmar equipamento', 'Alterar plano no MkAuth', 'Validar conexão', 'Abrir chamado financeiro', 'Acompanhar chamado financeiro', 'Concluir migração'] as $technicalLabel): ?><li><span><?= htmlspecialchars($technicalLabel, ENT_QUOTES, 'UTF-8'); ?></span><small>Não iniciada</small></li><?php endforeach; ?></ol>
    </details>
</aside>
<button class="migration-checklist__backdrop" type="button" data-close-process-steps aria-label="Fechar lista de etapas" hidden></button>
</div>
</main>

<?php if ($processId > 0): ?>
<dialog class="migration-cancel-dialog" data-migration-cancel-dialog aria-labelledby="migration-condition-cancel-title">
    <form method="post" action="<?= htmlspecialchars(Url::to('/processos/cancelar'), ENT_QUOTES, 'UTF-8'); ?>" class="form-grid" data-prevent-double-submit>
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) ($processCsrfToken ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="process_id" value="<?= $processId; ?>">
        <header class="field--span-2"><p class="section-heading__eyebrow">Cancelar migração</p><h2 id="migration-condition-cancel-title">O histórico será preservado</h2></header>
        <label class="field field--span-2"><span>Motivo do cancelamento</span><textarea name="reason" rows="3" required></textarea></label>
        <label class="checkbox-field field--span-2"><input type="radio" name="after_cancel" value="client" checked><span><strong>Cancelar e voltar ao cliente</strong></span></label>
        <label class="checkbox-field field--span-2"><input type="radio" name="after_cancel" value="restart"><span><strong>Cancelar e iniciar nova migração</strong></span></label>
        <footer class="migration-workspace__actions field--span-2"><button class="button button--ghost" type="button" data-close-migration-cancel>Não cancelar</button><span></span><button class="button button--danger" type="submit" data-submit-label="Cancelando...">Confirmar cancelamento</button></footer>
    </form>
</dialog>
<?php endif; ?>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/../layouts/app.php';
