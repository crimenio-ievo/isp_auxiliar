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
$retention = !empty($form) ? !empty($form['retention_condition']) : !empty($context['retention_condition']);
$applyFidelity = !empty($form) ? !empty($form['apply_fidelity']) : !empty($context['apply_fidelity']);
$fidelityMonths = $applyFidelity && array_key_exists('fidelidade_meses', $form)
    ? (int) $form['fidelidade_meses']
    : max(1, min(12, (int) ($context['fidelity_months'] ?? 12)));
$fidelityDescription = trim((string) ($form['fidelity_benefit_description'] ?? $context['fidelity_benefit_description'] ?? ''));
$otherBenefit = trim((string) ($form['beneficio_outro_text'] ?? ''));
$observation = trim((string) ($form['observacao'] ?? $context['observacao'] ?? ''));
$correctionOf = (int) ($correctionOf ?? 0);
$correctionReason = trim((string) ($correctionReason ?? ''));
$processId = (int) ($processId ?? 0);
$revisionMode = trim((string) ($revisionMode ?? ''));
$adhesionDefault = (float) ($context['adhesion_default_value'] ?? 0);
$waiverMode = (string) ($context['adhesion_waiver_mode'] ?? 'disabled');
$errorFor = static fn (string $field): string => trim((string) ($errors[$field] ?? ''));
$firstErrorField = $errors !== [] ? (string) array_key_first($errors) : '';
$operationLabels = ['migration' => 'Migração', 'upgrade' => 'Upgrade', 'downgrade' => 'Downgrade'];
$operation = (string) ($form['operation_type'] ?? '');
$currentPlanOption = [];
foreach ($plans as $plan) {
    if (strcasecmp((string) ($plan['id'] ?? ''), $currentPlan) === 0 || strcasecmp((string) ($plan['name'] ?? ''), $currentPlan) === 0) {
        $currentPlanOption = $plan;
        break;
    }
}

ob_start();
?>
<section class="page-header">
    <div>
        <p class="section-heading__eyebrow">Tela 1 de 4</p>
        <h1>Nova condição</h1>
        <p class="page-description">Escolha o novo plano. Tecnologia, valor e tipo da operação são derivados dos dados oficiais do plano.</p>
    </div>
    <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/clientes/detalhe?login=' . rawurlencode($login)), ENT_QUOTES, 'UTF-8'); ?>">Voltar ao cliente</a>
</section>

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
        <div class="summary-item"><span>Tecnologia atual</span><strong><?= htmlspecialchars($currentTechnology !== '' ? $currentTechnology : 'Tecnologia não identificada', ENT_QUOTES, 'UTF-8'); ?></strong></div>
    </div>
</section>

<form class="card" method="post" action="<?= htmlspecialchars(Url::to('/clientes/upgrade'), ENT_QUOTES, 'UTF-8'); ?>" data-upgrade-simple-form data-prevent-double-submit
    data-current-plan="<?= htmlspecialchars((string) ($currentPlanOption['id'] ?? $currentPlan), ENT_QUOTES, 'UTF-8'); ?>"
    data-current-family="<?= htmlspecialchars((string) ($currentPlanOption['install_type'] ?? $context['current_technology_family'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
    data-current-speed="<?= htmlspecialchars((string) ($currentPlanOption['speed_down'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
    data-current-value="<?= htmlspecialchars((string) ($currentValue ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) ($csrfToken ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="login" value="<?= htmlspecialchars($login, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($correctionOf > 0): ?>
        <input type="hidden" name="correction_of" value="<?= $correctionOf; ?>">
        <input type="hidden" name="process_id" value="<?= $processId; ?>">
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

        <section class="field field--span-2 adhesion-summary" aria-live="polite"
            data-adhesion-summary
            data-adhesion-default="<?= htmlspecialchars((string) $adhesionDefault, ENT_QUOTES, 'UTF-8'); ?>"
            data-waiver-mode="<?= htmlspecialchars($waiverMode, ENT_QUOTES, 'UTF-8'); ?>">
            <span>Adesão</span>
            <div class="summary-grid">
                <div class="summary-item"><span>Padrão configurado</span><strong>R$ <?= number_format($adhesionDefault, 2, ',', '.'); ?></strong></div>
                <div class="summary-item"><span>Valor cobrado</span><strong data-adhesion-charged>Definido após escolher o plano</strong></div>
                <div class="summary-item"><span>Benefício</span><strong data-adhesion-benefit>Sem isenção automática</strong></div>
            </div>
            <?php if ($waiverMode === 'manual'): ?>
                <label class="checkbox-field" data-manual-waiver hidden>
                    <input type="checkbox" name="manual_adhesion_waiver" value="1" <?= !empty($form['manual_adhesion_waiver']) ? 'checked' : ''; ?>>
                    <span><strong>Confirmar isenção da adesão</strong><small>Disponível somente para migração de rádio para fibra.</small></span>
                </label>
            <?php endif; ?>
        </section>

        <label class="field" for="benefit-value">
            <span>Valor do benefício</span>
            <input id="benefit-value" name="valor_beneficio" value="<?= htmlspecialchars($benefitValue, ENT_QUOTES, 'UTF-8'); ?>" inputmode="decimal" aria-describedby="benefit-value-error" <?= $firstErrorField === 'valor_beneficio' ? 'data-focus-field' : ''; ?>>
            <?php if ($errorFor('valor_beneficio') !== ''): ?><small id="benefit-value-error" class="field-error"><?= htmlspecialchars($errorFor('valor_beneficio'), ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
        </label>

        <label class="field" for="other-benefit">
            <span>Outro benefício</span>
            <input id="other-benefit" name="beneficio_outro_text" value="<?= htmlspecialchars($otherBenefit, ENT_QUOTES, 'UTF-8'); ?>" maxlength="500" placeholder="Descreva, se houver">
        </label>

        <label class="field field--span-2 checkbox-field" for="retention-condition">
            <input id="retention-condition" type="checkbox" name="retention_condition" value="1" <?= $retention ? 'checked' : ''; ?>>
            <span><strong>Condição comercial de retenção</strong><small>Não muda o tipo técnico da operação e exige justificativa na observação.</small></span>
        </label>

        <fieldset class="field field--span-2 fidelity-fieldset">
            <legend>Fidelidade</legend>
            <label class="checkbox-field" for="apply-fidelity">
                <input id="apply-fidelity" type="checkbox" name="apply_fidelity" value="1" data-fidelity-toggle <?= $applyFidelity ? 'checked' : ''; ?>>
                <span><strong>Aplicar nova fidelidade</strong><small>Somente com benefício real, aceite expresso e prazo máximo de 12 meses.</small></span>
            </label>
            <div class="form-grid" data-fidelity-fields <?= !$applyFidelity ? 'hidden' : ''; ?>>
                <label class="field field--span-2" for="fidelity-description">
                    <span>Benefício que justifica a fidelidade</span>
                    <input id="fidelity-description" name="fidelity_benefit_description" value="<?= htmlspecialchars($fidelityDescription, ENT_QUOTES, 'UTF-8'); ?>" maxlength="500" aria-describedby="fidelity-description-error" <?= !$applyFidelity ? 'disabled' : ''; ?>>
                    <?php if ($errorFor('fidelity_benefit_description') !== ''): ?><small id="fidelity-description-error" class="field-error"><?= htmlspecialchars($errorFor('fidelity_benefit_description'), ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                </label>
                <label class="field" for="fidelity-months">
                    <span>Prazo de permanência</span>
                    <input id="fidelity-months" type="number" name="fidelidade_meses" min="1" max="12" value="<?= $fidelityMonths; ?>" aria-describedby="fidelity-months-error" <?= !$applyFidelity ? 'disabled' : ''; ?>>
                    <?php if ($errorFor('fidelidade_meses') !== ''): ?><small id="fidelity-months-error" class="field-error"><?= htmlspecialchars($errorFor('fidelidade_meses'), ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                </label>
            </div>
        </fieldset>

        <label class="field field--span-2" for="upgrade-observation">
            <span>Observação</span>
            <textarea id="upgrade-observation" name="observacao" rows="4" maxlength="2000" aria-describedby="upgrade-observation-error" <?= $firstErrorField === 'observacao' ? 'data-focus-field' : ''; ?>><?= htmlspecialchars($observation, ENT_QUOTES, 'UTF-8'); ?></textarea>
            <?php if ($errorFor('observacao') !== ''): ?><small id="upgrade-observation-error" class="field-error"><?= htmlspecialchars($errorFor('observacao'), ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
        </label>

        <div class="form-actions field--span-2">
            <button class="button" type="submit" name="next_action" value="continue" data-submit-label="Salvando...">Salvar e continuar</button>
            <button class="button button--ghost" type="submit" name="next_action" value="later" data-submit-label="Salvando...">Salvar e voltar depois</button>
        </div>
    </div>
</form>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/../layouts/app.php';
