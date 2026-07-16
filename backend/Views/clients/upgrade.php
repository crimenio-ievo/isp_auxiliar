<?php

declare(strict_types=1);

use App\Core\Url;

$context = is_array($context ?? null) ? $context : [];
$clientProfile = is_array($context['clientProfile'] ?? null) ? $context['clientProfile'] : [];
$contract = is_array($context['contract'] ?? null) ? $context['contract'] : [];
$planOptions = is_array($context['planOptions'] ?? null) ? $context['planOptions'] : [];
$login = (string) ($context['login'] ?? $currentLogin ?? '');
$currentPlan = (string) ($context['current_plan'] ?? '');
$currentTechnology = (string) ($context['current_technology'] ?? '');
$currentTechnologyFamily = (string) ($context['current_technology_family'] ?? '');
$currentMonthlyValue = $context['current_monthly_value'] ?? null;
$newPlan = (string) ($context['new_plan'] ?? $currentPlan);
$newTechnology = (string) ($context['new_technology'] ?? $currentTechnology);
$newTechnologyFamily = (string) ($context['new_technology_family'] ?? '');
$benefitDescription = (string) ($context['benefit_description'] ?? '');
$benefitValue = number_format((float) ($context['benefit_value'] ?? 0), 2, ',', '.');
$newMonthlyValueRaw = $context['new_monthly_value'] ?? null;
$newMonthlyValue = $newMonthlyValueRaw !== null && $newMonthlyValueRaw !== ''
    ? number_format((float) $newMonthlyValueRaw, 2, ',', '.')
    : '';
$fidelityMonths = max(1, (int) ($context['fidelity_months'] ?? 12));
$observation = (string) ($context['observacao'] ?? '');
$customerName = trim((string) ($clientProfile['nome'] ?? $contract['nome_cliente'] ?? '-'));
$customerPhone = trim((string) ($clientProfile['celular'] ?? $clientProfile['fone'] ?? $contract['telefone_cliente'] ?? '-'));
$customerDocument = trim((string) ($clientProfile['cpf_cnpj'] ?? '-'));

$technologyLabelForInstallType = static function (string $installType): string {
    return match (strtolower(trim($installType))) {
        'fibra' => 'Fibra',
        'radio' => 'Rádio',
        default => '',
    };
};

$selected = static function (string $value, string $current): string {
    return strcasecmp(trim($value), trim($current)) === 0 ? 'selected' : '';
};

ob_start();
?>
<section class="page-header">
    <div>
        <p class="section-heading__eyebrow">Clientes</p>
        <h1>Upgrade / Migração</h1>
        <p class="page-description">Crie um novo contrato de alteração comercial sem mexer automaticamente no MkAuth. O ajuste operacional continua manual após o aceite.</p>
    </div>
    <div class="hero-actions">
        <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/clientes/detalhe?login=' . rawurlencode($login)), ENT_QUOTES, 'UTF-8'); ?>">Voltar ao detalhe</a>
    </div>
</section>

<?php if (!empty($flash)): ?>
    <section class="alert <?= htmlspecialchars(($flash['type'] ?? 'success') === 'error' ? 'alert--error' : 'alert--success', ENT_QUOTES, 'UTF-8'); ?>" style="margin-bottom: 20px;">
        <?= htmlspecialchars((string) ($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
    </section>
<?php endif; ?>

<section class="card">
    <div class="section-heading">
        <p class="section-heading__eyebrow">Resumo atual</p>
        <h2>Cliente e contrato</h2>
    </div>

    <div class="summary-grid">
        <div class="summary-item"><span>Nome</span><strong><?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?></strong></div>
        <div class="summary-item"><span>Login</span><strong><?= htmlspecialchars($login !== '' ? $login : '-', ENT_QUOTES, 'UTF-8'); ?></strong></div>
        <div class="summary-item"><span>Documento</span><strong><?= htmlspecialchars($customerDocument !== '' ? $customerDocument : '-', ENT_QUOTES, 'UTF-8'); ?></strong></div>
        <div class="summary-item"><span>Telefone</span><strong><?= htmlspecialchars($customerPhone !== '' ? $customerPhone : '-', ENT_QUOTES, 'UTF-8'); ?></strong></div>
        <div class="summary-item"><span>Plano atual</span><strong><?= htmlspecialchars($currentPlan !== '' ? $currentPlan : '-', ENT_QUOTES, 'UTF-8'); ?></strong></div>
        <div class="summary-item"><span>Tecnologia atual</span><strong><?= htmlspecialchars($currentTechnology !== '' ? $currentTechnology : '-', ENT_QUOTES, 'UTF-8'); ?></strong></div>
        <div class="summary-item"><span>Valor mensal atual</span><strong><?= htmlspecialchars($currentMonthlyValue !== null ? 'R$ ' . number_format((float) $currentMonthlyValue, 2, ',', '.') : '-', ENT_QUOTES, 'UTF-8'); ?></strong></div>
    </div>
</section>

<form class="content-grid content-grid--form" method="post" action="<?= htmlspecialchars(Url::to('/clientes/upgrade'), ENT_QUOTES, 'UTF-8'); ?>" data-upgrade-form="1">
    <input type="hidden" name="login" value="<?= htmlspecialchars($login, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="valor_mensal_atual" value="<?= htmlspecialchars($currentMonthlyValue !== null ? number_format((float) $currentMonthlyValue, 2, '.', '') : '', ENT_QUOTES, 'UTF-8'); ?>" data-upgrade-current-monthly-value>
    <input type="hidden" name="nova_tecnologia" value="<?= htmlspecialchars($newTechnology, ENT_QUOTES, 'UTF-8'); ?>" data-upgrade-new-technology>
    <input type="hidden" name="novo_valor_mensal" value="<?= htmlspecialchars($newMonthlyValueRaw !== null ? number_format((float) $newMonthlyValueRaw, 2, '.', '') : '', ENT_QUOTES, 'UTF-8'); ?>" data-upgrade-monthly-value>
    <input type="hidden" name="beneficio_concedido" value="<?= htmlspecialchars($benefitDescription, ENT_QUOTES, 'UTF-8'); ?>" data-upgrade-benefit-description>
    <input type="hidden" name="benefit_flags" value="<?= htmlspecialchars((string) json_encode($context['benefit_flags'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>" data-upgrade-benefit-flags>
    <input type="hidden" name="beneficio_outro_text" value="" data-upgrade-benefit-other-text-value>

    <section class="card">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Alteração comercial</p>
            <h2>Dados do upgrade</h2>
        </div>

        <div class="form-grid">
            <label class="field">
                <span>Plano atual</span>
                <input type="text" name="plano_atual" value="<?= htmlspecialchars($currentPlan, ENT_QUOTES, 'UTF-8'); ?>" readonly>
            </label>

            <label class="field">
                <span>Novo plano</span>
                <select name="novo_plano" required data-upgrade-plan-select>
                    <option value="">Selecione o novo plano</option>
                    <?php foreach ($planOptions as $plan): ?>
                        <?php $planLabel = (string) ($plan['label'] ?? $plan['id'] ?? ''); ?>
                        <?php $planInstallType = (string) ($plan['install_type'] ?? ''); ?>
                        <?php $planTechnology = (string) ($plan['technology'] ?? $plan['tecnologia'] ?? ''); ?>
                        <?php $planTechnologyLabel = $planTechnology !== '' ? $planTechnology : $technologyLabelForInstallType($planInstallType); ?>
                        <option
                            value="<?= htmlspecialchars((string) ($plan['id'] ?? $planLabel), ENT_QUOTES, 'UTF-8'); ?>"
                            data-upgrade-install-type="<?= htmlspecialchars($planInstallType, ENT_QUOTES, 'UTF-8'); ?>"
                            data-upgrade-technology="<?= htmlspecialchars($planTechnologyLabel, ENT_QUOTES, 'UTF-8'); ?>"
                            data-upgrade-monthly-value="<?= htmlspecialchars((string) ($plan['value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                            data-monthly-value="<?= htmlspecialchars((string) ($plan['value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                            <?= $selected((string) ($plan['id'] ?? $planLabel), $newPlan); ?>
                        ><?= htmlspecialchars($planLabel !== '' ? $planLabel : '-', ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field">
                <span>Tecnologia atual</span>
                <input type="text" name="tecnologia_atual" value="<?= htmlspecialchars($currentTechnology, ENT_QUOTES, 'UTF-8'); ?>" readonly data-upgrade-current-technology data-upgrade-current-technology-family="<?= htmlspecialchars($currentTechnologyFamily, ENT_QUOTES, 'UTF-8'); ?>">
            </label>

            <label class="field">
                <span>Nova tecnologia</span>
                <input type="text" value="<?= htmlspecialchars($newTechnology, ENT_QUOTES, 'UTF-8'); ?>" readonly data-upgrade-new-technology-display>
            </label>

            <label class="field">
                <span>Valor do benefício</span>
                <input type="text" name="valor_beneficio" value="<?= htmlspecialchars($benefitValue, ENT_QUOTES, 'UTF-8'); ?>" inputmode="decimal" required data-upgrade-benefit-value <?= empty($canUpgradeCommercial) ? 'readonly' : ''; ?>>
            </label>

            <div class="field">
                <span>Novo valor mensal</span>
                <div class="upgrade-summary-value" data-upgrade-monthly-display>
                    <?= htmlspecialchars($newMonthlyValue !== '' ? 'R$ ' . $newMonthlyValue : 'R$ 0,00', ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <p class="field-help">Valor apenas para conferência. O snapshot salva o valor do novo plano selecionado.</p>
            </div>

            <div class="field field--span-2">
                <span>Benefícios</span>
                <div class="upgrade-benefit-grid" data-upgrade-benefit-group>
                    <label class="upgrade-benefit-card">
                        <span class="upgrade-benefit-card__checkbox">
                            <input type="checkbox" data-upgrade-benefit-checkbox="radio_to_fiber" <?= empty($canUpgradeCommercial) ? 'disabled' : ''; ?>>
                        </span>
                        <span class="upgrade-benefit-card__body">
                            <strong>Migração rádio → fibra</strong>
                            <small>Formaliza a troca de tecnologia do acesso.</small>
                        </span>
                    </label>
                    <label class="upgrade-benefit-card">
                        <span class="upgrade-benefit-card__checkbox">
                            <input type="checkbox" data-upgrade-benefit-checkbox="adhesion_waiver" <?= empty($canUpgradeCommercial) ? 'disabled' : ''; ?>>
                        </span>
                        <span class="upgrade-benefit-card__body">
                            <strong>Isenção de adesão/instalação</strong>
                            <small>Registra benefício financeiro concedido ao cliente.</small>
                        </span>
                    </label>
                    <label class="upgrade-benefit-card">
                        <span class="upgrade-benefit-card__checkbox">
                            <input type="checkbox" data-upgrade-benefit-checkbox="plan_upgrade" <?= empty($canUpgradeCommercial) ? 'disabled' : ''; ?>>
                        </span>
                        <span class="upgrade-benefit-card__body">
                            <strong>Upgrade de plano</strong>
                            <small>Formaliza aumento de velocidade ou melhoria comercial.</small>
                        </span>
                    </label>
                    <label class="upgrade-benefit-card">
                        <span class="upgrade-benefit-card__checkbox">
                            <input type="checkbox" data-upgrade-benefit-checkbox="retention" <?= empty($canUpgradeCommercial) ? 'disabled' : ''; ?>>
                        </span>
                        <span class="upgrade-benefit-card__body">
                            <strong>Condição de retenção</strong>
                            <small>Registra condição especial para manter o cliente.</small>
                        </span>
                    </label>
                    <label class="upgrade-benefit-card upgrade-benefit-card--wide">
                        <span class="upgrade-benefit-card__checkbox">
                            <input type="checkbox" data-upgrade-benefit-checkbox="other_benefit" <?= empty($canUpgradeCommercial) ? 'disabled' : ''; ?>>
                        </span>
                        <span class="upgrade-benefit-card__body">
                            <strong>Outro benefício</strong>
                            <small>Permite informar um benefício personalizado.</small>
                        </span>
                    </label>
                </div>
                <p class="field-help" data-upgrade-benefit-summary>Os benefícios marcados serão salvos no snapshot e usados no termo público.</p>
            </div>

            <label class="field field--span-2" data-upgrade-other-benefit-wrapper hidden>
                <span>Detalhe do outro benefício</span>
                <input type="text" placeholder="Descreva o outro benefício" data-upgrade-benefit-other-text>
            </label>

            <label class="field">
                <span>Prazo de fidelidade</span>
                <input type="number" name="fidelidade_meses" min="1" value="<?= htmlspecialchars((string) $fidelityMonths, ENT_QUOTES, 'UTF-8'); ?>" required data-upgrade-fidelity <?= empty($canUpgradeCommercial) ? 'readonly' : ''; ?>>
            </label>

            <label class="field field--span-2">
                <span>Observação</span>
                <textarea rows="4" name="observacao" placeholder="Descreva a justificativa comercial e qualquer observação operacional."><?= htmlspecialchars($observation, ENT_QUOTES, 'UTF-8'); ?></textarea>
            </label>

            <label class="field">
                <span>Forma de assinatura</span>
                <select name="signature_mode" required>
                    <option value="remote">Titular não está no local / assinatura remota</option>
                    <option value="local">Assinatura colhida no local</option>
                </select>
                <small class="field-help">O processo continuará pendente até existir aceite digital válido.</small>
            </label>

            <label class="field">
                <span>Motivo da assinatura remota</span>
                <input type="text" name="remote_signature_reason" placeholder="Ex.: titular não está no local">
                <small class="field-help">Obrigatório quando a assinatura for remota.</small>
            </label>

            <div class="form-actions field--span-2">
                <button class="button" type="submit">Gerar aceite obrigatório</button>
                <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/clientes/detalhe?login=' . rawurlencode($login)), ENT_QUOTES, 'UTF-8'); ?>">Cancelar</a>
            </div>
        </div>
    </section>
</form>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
