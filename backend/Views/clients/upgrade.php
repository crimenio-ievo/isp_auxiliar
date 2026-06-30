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
$currentMonthlyValue = $context['current_monthly_value'] ?? null;
$newPlan = (string) ($context['new_plan'] ?? $currentPlan);
$newTechnology = (string) ($context['new_technology'] ?? $currentTechnology);
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

<form class="content-grid content-grid--form" method="post" action="<?= htmlspecialchars(Url::to('/clientes/upgrade'), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="login" value="<?= htmlspecialchars($login, ENT_QUOTES, 'UTF-8'); ?>">

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
                <select name="novo_plano" required>
                    <option value="">Selecione o novo plano</option>
                    <?php foreach ($planOptions as $plan): ?>
                        <?php $planLabel = (string) ($plan['label'] ?? $plan['id'] ?? ''); ?>
                        <option value="<?= htmlspecialchars((string) ($plan['id'] ?? $planLabel), ENT_QUOTES, 'UTF-8'); ?>" <?= $selected((string) ($plan['id'] ?? $planLabel), $newPlan); ?>><?= htmlspecialchars($planLabel !== '' ? $planLabel : '-', ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field">
                <span>Tecnologia atual</span>
                <input type="text" name="tecnologia_atual" value="<?= htmlspecialchars($currentTechnology, ENT_QUOTES, 'UTF-8'); ?>" readonly>
            </label>

            <label class="field">
                <span>Nova tecnologia</span>
                <select name="nova_tecnologia" required>
                    <option value="">Selecione</option>
                    <option value="fibra" <?= $selected('fibra', $newTechnology); ?>>Fibra</option>
                    <option value="radio" <?= $selected('radio', $newTechnology); ?>>Rádio</option>
                    <option value="outra" <?= $selected('outra', $newTechnology); ?>>Outra</option>
                </select>
            </label>

            <label class="field field--span-2">
                <span>Benefício concedido</span>
                <input type="text" name="beneficio_concedido" value="<?= htmlspecialchars($benefitDescription, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Ex.: fidelidade renovada, desconto comercial, troca autorizada">
            </label>

            <label class="field">
                <span>Valor do benefício</span>
                <input type="text" name="valor_beneficio" value="<?= htmlspecialchars($benefitValue, ENT_QUOTES, 'UTF-8'); ?>" inputmode="decimal" required>
            </label>

            <label class="field">
                <span>Novo valor mensal</span>
                <input type="text" name="novo_valor_mensal" value="<?= htmlspecialchars($newMonthlyValue, ENT_QUOTES, 'UTF-8'); ?>" inputmode="decimal" required>
            </label>

            <label class="field">
                <span>Prazo de fidelidade</span>
                <input type="number" name="fidelidade_meses" min="1" value="<?= htmlspecialchars((string) $fidelityMonths, ENT_QUOTES, 'UTF-8'); ?>" required>
            </label>

            <label class="field field--span-2">
                <span>Observação</span>
                <textarea rows="4" name="observacao" placeholder="Descreva a justificativa comercial e qualquer observação operacional."><?= htmlspecialchars($observation, ENT_QUOTES, 'UTF-8'); ?></textarea>
            </label>

            <div class="form-actions field--span-2">
                <button class="button" type="submit">Gerar aceite remoto</button>
                <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/clientes/detalhe?login=' . rawurlencode($login)), ENT_QUOTES, 'UTF-8'); ?>">Cancelar</a>
            </div>
        </div>
    </section>
</form>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
