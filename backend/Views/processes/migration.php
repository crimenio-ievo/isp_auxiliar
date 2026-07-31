<?php

declare(strict_types=1);

use App\Core\Url;

$process = is_array($process ?? null) ? $process : [];
$contract = is_array($contract ?? null) ? $contract : [];
$acceptance = is_array($acceptance ?? null) ? $acceptance : [];
$financialTask = is_array($financialTask ?? null) ? $financialTask : [];
$connection = is_array($connection ?? null) ? $connection : [];
$dryRun = is_array($dryRun ?? null) ? $dryRun : null;
$screen = max(1, min(4, (int) ($screen ?? 1)));
$processId = (int) ($process['id'] ?? 0);
$migration = is_array($process['metadata']['migration'] ?? null) ? $process['metadata']['migration'] : [];
$client = is_array($process['metadata']['client'] ?? null) ? $process['metadata']['client'] : [];
$steps = is_array($process['steps'] ?? null) ? $process['steps'] : [];
$stepByKey = [];
foreach ($steps as $item) {
    if (is_array($item)) $stepByKey[(string) ($item['step_key'] ?? '')] = $item;
}
$screenTitles = [1 => 'Nova condição', 2 => 'Conferência, assinatura e envio', 3 => 'Execução técnica e conexão', 4 => 'Plano, financeiro e conclusão'];
$acceptanceStatus = (string) ($acceptance['status'] ?? 'criado');
$acceptanceAccepted = $acceptanceStatus === 'aceito' && trim((string) ($acceptance['revoked_at'] ?? '')) === '';
$acceptanceToken = trim((string) ($acceptance['token_hash'] ?? ''));
$phone = trim((string) ($acceptance['telefone_enviado'] ?? $client['phone'] ?? $contract['telefone_cliente'] ?? ''));
$email = trim((string) ($client['email'] ?? ''));
$formatMoney = static fn (mixed $value): string => is_numeric($value) ? 'R$ ' . number_format((float) $value, 2, ',', '.') : '-';
$statusComplete = static fn (array $step): bool => in_array((string) ($step['status'] ?? ''), ['completed', 'not_applicable'], true);
$renderStatus = static function (array $step): void {
    $class = htmlspecialchars((string) ($step['status_class'] ?? 'muted'), ENT_QUOTES, 'UTF-8');
    $label = htmlspecialchars((string) ($step['status_label'] ?? 'Não iniciada'), ENT_QUOTES, 'UTF-8');
    echo '<span class="process-status process-status--' . $class . '">' . $label . '</span>';
};
$renderStepForm = static function (array $step, int $continueScreen, string $csrfToken, int $processId, array $options = []) use ($renderStatus): void {
    $key = (string) ($step['step_key'] ?? '');
    $completed = (string) ($step['status'] ?? '') === 'completed';
    ?>
    <section class="migration-task">
        <div class="migration-task__heading"><h3><?= htmlspecialchars((string) ($step['label'] ?? $key), ENT_QUOTES, 'UTF-8'); ?></h3><?php $renderStatus($step); ?></div>
        <?php if (trim((string) ($step['pending_reason'] ?? '')) !== ''): ?><p class="alert alert--warning"><?= htmlspecialchars((string) $step['pending_reason'], ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
        <?php if ($completed): ?>
            <p class="field-help"><?= htmlspecialchars((string) ($step['observation'] ?? 'Evidência preservada no checklist.'), ENT_QUOTES, 'UTF-8'); ?></p>
        <?php else: ?>
            <form method="post" action="<?= htmlspecialchars(Url::to('/processos/etapa'), ENT_QUOTES, 'UTF-8'); ?>" data-single-submit-form>
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="process_id" value="<?= $processId; ?>">
                <input type="hidden" name="step_key" value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="save" data-process-action-input>
                <input type="hidden" name="continue_to" value="migration:<?= $continueScreen; ?>">
                <label class="field">
                    <span><?= htmlspecialchars((string) ($options['label'] ?? 'Observação ou evidência'), ENT_QUOTES, 'UTF-8'); ?></span>
                    <textarea name="observation" rows="3" placeholder="<?= htmlspecialchars((string) ($options['placeholder'] ?? 'Registre o que foi conferido ou executado.'), ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars((string) ($step['observation'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </label>
                <?php if (!empty($options['external'])): ?>
                    <label class="field"><span><?= htmlspecialchars((string) $options['external'], ENT_QUOTES, 'UTF-8'); ?></span><input name="external_reference" value="<?= htmlspecialchars((string) ($step['external_reference'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></label>
                <?php endif; ?>
                <details><summary>Salvar pendência para retomar depois</summary>
                    <label class="field"><span>Motivo da pendência</span><textarea name="pending_reason" rows="2"><?= htmlspecialchars((string) ($step['pending_reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea></label>
                    <label class="field"><span>Próxima ação</span><input name="next_action" value="<?= htmlspecialchars((string) ($step['next_action'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></label>
                </details>
                <div class="form-actions">
                    <?php if (!empty($options['refresh'])): ?><button class="button button--ghost" type="submit" data-process-action="refresh_external">Atualizar situação</button><?php endif; ?>
                    <?php if (!empty($options['simulate'])): ?><button class="button button--ghost" type="submit" data-process-action="simulate_plan">Registrar dry-run</button><?php endif; ?>
                    <button class="button button--ghost" type="submit" data-process-action="defer">Salvar e voltar depois</button>
                    <?php if (empty($options['simulate_only'])): ?><button class="button" type="submit" data-process-action="complete">Confirmar</button><?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
    </section>
    <?php
};

ob_start();
?>
<section class="page-header">
    <div>
        <p class="section-heading__eyebrow">Upgrade / Migração · processo #<?= $processId; ?></p>
        <h1><?= htmlspecialchars($screenTitles[$screen], ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="page-description"><?= htmlspecialchars((string) ($process['client_name'] ?? $process['mkauth_login'] ?? 'Cliente'), ENT_QUOTES, 'UTF-8'); ?> · <?= htmlspecialchars((string) ($process['mkauth_login'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
    <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/processos/detalhe?id=' . $processId), ENT_QUOTES, 'UTF-8'); ?>">Ver checklist de 11 etapas</a>
</section>

<?php if (!empty($flash)): ?>
    <section class="alert alert--<?= htmlspecialchars((string) ($flash['type'] ?? 'success'), ENT_QUOTES, 'UTF-8'); ?>" aria-live="polite"><?= htmlspecialchars((string) ($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></section>
<?php endif; ?>

<nav class="migration-screen-nav" aria-label="Etapas agrupadas da migração">
    <?php foreach ($screenTitles as $number => $title): ?>
        <a href="<?= htmlspecialchars(Url::to('/processos/migracao?id=' . $processId . '&screen=' . $number), ENT_QUOTES, 'UTF-8'); ?>" class="<?= $number === $screen ? 'is-current' : ''; ?>" <?= $number === $screen ? 'aria-current="step"' : ''; ?>>
            <span><?= $number; ?></span><strong><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></strong>
        </a>
    <?php endforeach; ?>
</nav>

<?php if ($screen === 1): ?>
    <section class="card">
        <div class="section-heading"><p class="section-heading__eyebrow">Condição salva</p><h2>Antes e depois</h2></div>
        <div class="summary-grid">
            <div class="summary-item"><span>Operação</span><strong><?= htmlspecialchars(ucfirst((string) ($migration['operation_type'] ?? '-')), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="summary-item"><span>Condição comercial</span><strong><?= !empty($migration['retention_condition']) ? 'Retenção' : 'Alteração padrão'; ?></strong></div>
            <div class="summary-item"><span>Plano anterior</span><strong><?= htmlspecialchars((string) ($migration['current_plan_name'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="summary-item"><span>Novo plano</span><strong><?= htmlspecialchars((string) ($migration['new_plan_name'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="summary-item"><span>Valor anterior</span><strong><?= $formatMoney($migration['current_monthly_value'] ?? null); ?></strong></div>
            <div class="summary-item"><span>Novo valor</span><strong><?= $formatMoney($migration['new_monthly_value'] ?? null); ?></strong></div>
            <div class="summary-item"><span>Tecnologia anterior</span><strong><?= htmlspecialchars((string) ($migration['current_technology'] ?? 'Tecnologia não identificada'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="summary-item"><span>Nova tecnologia</span><strong><?= htmlspecialchars((string) ($migration['new_technology'] ?? 'Tecnologia não identificada'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
        </div>
        <div class="form-actions"><a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/clientes/upgrade?login=' . rawurlencode((string) ($process['mkauth_login'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?>">Corrigir nova condição</a><a class="button" href="<?= htmlspecialchars(Url::to('/processos/migracao?id=' . $processId . '&screen=2'), ENT_QUOTES, 'UTF-8'); ?>">Continuar</a></div>
    </section>
<?php elseif ($screen === 2): ?>
    <section class="card">
        <div class="section-heading"><p class="section-heading__eyebrow">Documento e aceite compartilhado</p><h2>Confira as condições</h2></div>
        <div class="summary-grid">
            <div class="summary-item"><span>Plano</span><strong><?= htmlspecialchars((string) ($migration['current_plan_name'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?> → <?= htmlspecialchars((string) ($migration['new_plan_name'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="summary-item"><span>Valor</span><strong><?= $formatMoney($migration['current_monthly_value'] ?? null); ?> → <?= $formatMoney($migration['new_monthly_value'] ?? null); ?></strong></div>
            <div class="summary-item"><span>Benefício</span><strong><?= htmlspecialchars((string) ($migration['benefit_description'] ?? 'Sem benefício adicional'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="summary-item"><span>Fidelidade</span><strong><?= (int) ($migration['fidelity_months'] ?? 0) > 0 ? (int) $migration['fidelity_months'] . ' meses' : 'Não aplicada'; ?></strong></div>
            <div class="summary-item"><span>Aceite</span><strong><?= htmlspecialchars($acceptanceAccepted ? 'Confirmado' : 'Aguardando cliente', ENT_QUOTES, 'UTF-8'); ?></strong></div>
        </div>
        <?php if ($acceptanceToken !== ''): ?><a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/aceite/' . rawurlencode($acceptanceToken)), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Ler contrato e condições completas</a><?php endif; ?>
    </section>

    <?php if (!$acceptanceAccepted): ?>
        <form class="card" method="post" action="<?= htmlspecialchars(Url::to('/clientes/migracao/aceite-preparar'), ENT_QUOTES, 'UTF-8'); ?>" data-migration-acceptance-form data-prevent-double-submit>
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) ($csrfToken ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="process_id" value="<?= $processId; ?>">
            <div class="section-heading"><p class="section-heading__eyebrow">Cliente presente por padrão</p><h2>Assinatura e canais de confirmação</h2></div>
            <label class="checkbox-field"><input type="checkbox" name="client_absent" value="1" data-client-absent><span><strong>Cliente não está presente — assinatura remota</strong><small>Exige motivo; o cliente assina no próprio aparelho.</small></span></label>
            <label class="field" data-remote-reason hidden><span>Motivo da assinatura remota</span><input name="remote_signature_reason" maxlength="500"></label>
            <div data-local-signature>
                <div class="signature-pad" data-signature-pad>
                    <canvas data-signature-canvas aria-label="Área para assinatura local"></canvas>
                    <input type="hidden" name="assinatura_cliente" data-signature-input>
                    <div class="signature-actions"><button class="button button--ghost" type="button" data-signature-clear>Limpar assinatura</button></div>
                    <p class="field-help" data-signature-help>Peça ao titular para assinar no aparelho do técnico.</p>
                </div>
            </div>
            <fieldset class="field"><legend>Canais</legend>
                <label class="checkbox-field"><input type="checkbox" name="channel_whatsapp" value="1" checked><span>WhatsApp — <?= htmlspecialchars($phone !== '' ? $phone : 'não cadastrado', ENT_QUOTES, 'UTF-8'); ?></span></label>
                <label class="field"><span>Telefone</span><input name="phone" inputmode="tel" value="<?= htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'); ?>"></label>
                <label class="checkbox-field"><input type="checkbox" name="channel_email" value="1" <?= filter_var($email, FILTER_VALIDATE_EMAIL) ? 'checked' : ''; ?>><span>E-mail — <?= htmlspecialchars($email !== '' ? $email : 'não cadastrado', ENT_QUOTES, 'UTF-8'); ?></span></label>
                <label class="field"><span>E-mail</span><input name="email" type="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>"></label>
            </fieldset>
            <p class="alert alert--warning">Homologação: WhatsApp e e-mail são registrados em dry-run; nenhum envio real será feito.</p>
            <div class="form-actions"><button class="button" type="submit" data-submit-label="Preparando...">Registrar assinatura e preparar envio</button></div>
        </form>
    <?php else: ?>
        <section class="alert alert--success"><strong>Confirmação remota válida recebida.</strong> A assinatura e as evidências técnicas permanecem armazenadas no backend.</section>
    <?php endif; ?>
<?php elseif ($screen === 3): ?>
    <section class="card">
        <div class="section-heading"><p class="section-heading__eyebrow">Consulta somente leitura</p><h2>Status PPPoE</h2></div>
        <div class="summary-grid">
            <div class="summary-item"><span>Login</span><strong><?= htmlspecialchars((string) ($process['mkauth_login'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="summary-item"><span>Sessão</span><strong><?= !empty($connection['online']) ? 'Online' : 'Offline'; ?></strong></div>
            <?php $session = is_array($connection['session'] ?? null) ? $connection['session'] : []; ?>
            <?php if (!empty($session['framedipaddress'])): ?><div class="summary-item"><span>IP</span><strong><?= htmlspecialchars((string) $session['framedipaddress'], ENT_QUOTES, 'UTF-8'); ?></strong></div><?php endif; ?>
            <?php if (!empty($session['nasipaddress'])): ?><div class="summary-item"><span>NAS</span><strong><?= htmlspecialchars((string) $session['nasipaddress'], ENT_QUOTES, 'UTF-8'); ?></strong></div><?php endif; ?>
            <?php if (!empty($session['acctstarttime'])): ?><div class="summary-item"><span>Início</span><strong><?= htmlspecialchars((string) $session['acctstarttime'], ENT_QUOTES, 'UTF-8'); ?></strong></div><?php endif; ?>
        </div>
        <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/processos/migracao?id=' . $processId . '&screen=3'), ENT_QUOTES, 'UTF-8'); ?>">Atualizar conexão</a>
    </section>
    <section class="card migration-task-list">
        <?php $renderStepForm($stepByKey['technical_execution'] ?? [], 3, (string) ($csrfToken ?? ''), $processId, ['label' => 'Serviço executado, equipamento instalado/retirado e observações', 'external' => 'Equipamento ou evidência']); ?>
        <?php $renderStepForm($stepByKey['confirm_equipment'] ?? [], 3, (string) ($csrfToken ?? ''), $processId, ['label' => 'Confirmação do equipamento']); ?>
        <?php $renderStepForm($stepByKey['validate_connection'] ?? [], 4, (string) ($csrfToken ?? ''), $processId, ['label' => !empty($connection['online']) ? 'Evidência da sessão PPPoE ativa' : 'Justificativa e evidência da confirmação manual autorizada', 'placeholder' => !empty($connection['online']) ? 'Sessão PPPoE ativa confirmada na consulta somente leitura.' : 'Informe a justificativa, o usuário autorizador e a evidência manual.']); ?>
    </section>
<?php else: ?>
    <section class="card">
        <div class="section-heading"><p class="section-heading__eyebrow">Pré-requisitos</p><h2>Plano, financeiro e conclusão</h2></div>
        <div class="migration-prerequisites">
            <?php foreach (['prepare_document' => 'Documento preparado', 'confirm_acceptance' => 'Aceite confirmado', 'technical_execution' => 'Execução técnica', 'validate_connection' => 'PPPoE validado'] as $key => $label): ?>
                <div class="<?= $statusComplete($stepByKey[$key] ?? []) ? 'is-ready' : ''; ?>"><span aria-hidden="true"><?= $statusComplete($stepByKey[$key] ?? []) ? '✓' : '○'; ?></span><strong><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php if (is_array($dryRun)): ?>
        <section class="card process-dry-run"><h2>Alteração de plano em dry-run</h2><p><?= htmlspecialchars((string) ($dryRun['message'] ?? 'Simulação preparada.'), ENT_QUOTES, 'UTF-8'); ?></p><div class="summary-grid"><div class="summary-item"><span>Antes</span><strong><?= htmlspecialchars((string) ($dryRun['before']['plan'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div><div class="summary-item"><span>Depois</span><strong><?= htmlspecialchars((string) ($dryRun['after']['plan'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div><div class="summary-item"><span>Escrita</span><strong><?= !empty($dryRun['write_enabled']) ? 'Configurada' : 'Bloqueada'; ?></strong></div></div></section>
    <?php endif; ?>
    <section class="card migration-task-list">
        <?php $renderStepForm($stepByKey['change_plan'] ?? [], 4, (string) ($csrfToken ?? ''), $processId, ['simulate' => true, 'simulate_only' => true, 'label' => 'Observação da simulação']); ?>
        <?php $renderStepForm($stepByKey['open_financial_ticket'] ?? [], 4, (string) ($csrfToken ?? ''), $processId, ['refresh' => true, 'label' => 'Chamado financeiro único', 'external' => 'Identificador do chamado']); ?>
        <?php $renderStepForm($stepByKey['follow_financial_ticket'] ?? [], 4, (string) ($csrfToken ?? ''), $processId, ['refresh' => true, 'label' => 'Fechamento, data e responsável confiável']); ?>
    </section>
    <section class="card">
        <p class="page-description">A conclusão normal continua bloqueada enquanto plano ou financeiro estiverem pendentes. O dry-run não marca o plano como aplicado.</p>
        <form method="post" action="<?= htmlspecialchars(Url::to('/processos/concluir'), ENT_QUOTES, 'UTF-8'); ?>" data-prevent-double-submit>
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) ($csrfToken ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="process_id" value="<?= $processId; ?>">
            <?php if (!empty($canOverride)): ?><label class="checkbox-field"><input type="checkbox" name="override" value="1"><span>Exceção administrativa autorizada</span></label><label class="field"><span>Justificativa da exceção</span><textarea name="justification" rows="3"></textarea></label><?php endif; ?>
            <button class="button" type="submit" data-submit-label="Validando...">Validar e concluir</button>
        </form>
    </section>
<?php endif; ?>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/../layouts/app.php';
