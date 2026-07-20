<?php

declare(strict_types=1);

use App\Core\Url;

$detail = is_array($detail ?? null) ? $detail : [];
$profile = is_array($detail['profile'] ?? null) ? $detail['profile'] : [];
$contracts = is_array($detail['contracts'] ?? null) ? $detail['contracts'] : [];
$digitalContract = is_array($detail['digitalContract'] ?? null) ? $detail['digitalContract'] : [];
$upgradeProcess = is_array($detail['upgradeProcess'] ?? null) ? $detail['upgradeProcess'] : [];
$acceptanceHistory = is_array($detail['acceptanceHistory'] ?? null) ? $detail['acceptanceHistory'] : [];
$financialTask = is_array($detail['financialTask'] ?? null) ? $detail['financialTask'] : [];
$registration = is_array($detail['registration'] ?? null) ? $detail['registration'] : [];
$checkpoints = is_array($detail['checkpoints'] ?? null) ? $detail['checkpoints'] : [];
$timeline = is_array($detail['timeline'] ?? null) ? $detail['timeline'] : [];
$auditLogs = is_array($detail['auditLogs'] ?? null) ? $detail['auditLogs'] : [];
$source = is_array($detail['source'] ?? null) ? $detail['source'] : [];
$login = (string) ($detail['login'] ?? $profile['login'] ?? '');
$canCorrectCurrent = !empty($canCorrectUpgrade)
    && !empty($upgradeProcess['active'])
    && (empty($upgradeProcess['accepted']) || !empty($canSupersedeContract));
$canCorrectCompleted = !empty($canSupersedeContract) && !empty($upgradeProcess['completed']);
$canCancelCurrent = !empty($canCancelPendingContract)
    && !empty($upgradeProcess['active'])
    && empty($upgradeProcess['accepted'])
    && empty($upgradeProcess['completed']);
ob_start();
?>
<section class="page-header">
    <div>
        <p class="section-heading__eyebrow">Clientes</p>
        <h1><?= htmlspecialchars((string) ($profile['name'] ?? 'Cliente'), ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="page-description">Resumo operacional do cliente no MkAuth e no histórico local.</p>
    </div>
    <div class="hero-actions">
        <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/clientes'), ENT_QUOTES, 'UTF-8'); ?>">Voltar para clientes</a>
        <?php if (!empty($canRequestUpgrade)): ?>
            <?php $hasRecordedUpgrade = !empty($upgradeProcess['open']) || !empty($upgradeProcess['completed']); ?>
            <a class="button button--ghost" href="<?= $hasRecordedUpgrade ? '#upgrade-process' : htmlspecialchars(Url::to('/clientes/upgrade?login=' . rawurlencode($login)), ENT_QUOTES, 'UTF-8'); ?>"><?= !empty($upgradeProcess['open']) ? 'Retomar Upgrade / Migração' : (!empty($upgradeProcess['completed']) ? 'Ver Upgrade / Migração' : 'Upgrade / Migração'); ?></a>
        <?php endif; ?>
        <?php if (!empty($canCreateClient)): ?>
            <a class="button" href="<?= htmlspecialchars(Url::to('/clientes/novo'), ENT_QUOTES, 'UTF-8'); ?>">Novo cliente</a>
        <?php endif; ?>
    </div>
</section>

<?php if (!empty($flash)): ?>
    <section class="alert alert--<?= htmlspecialchars((string) ($flash['type'] ?? 'success'), ENT_QUOTES, 'UTF-8'); ?>" style="margin-bottom: 20px;">
        <?= htmlspecialchars((string) ($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
    </section>
<?php endif; ?>

<section class="card">
    <div class="section-heading">
        <p class="section-heading__eyebrow">Resumo</p>
        <h2>Perfil do cliente</h2>
    </div>
    <div class="summary-grid">
        <div class="summary-item">
            <span>Nome</span>
            <strong><?= htmlspecialchars((string) ($profile['name'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
        <div class="summary-item">
            <span>Status</span>
            <?php $statusVisual = is_array($profile['status_visual'] ?? null) ? $profile['status_visual'] : []; ?>
            <strong>
                <span class="client-status-badge <?= htmlspecialchars((string) ($statusVisual['class'] ?? 'client-status-other'), ENT_QUOTES, 'UTF-8'); ?>">
                    <?= htmlspecialchars((string) ($statusVisual['label'] ?? $profile['status'] ?? 'Outro'), ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </strong>
        </div>
        <div class="summary-item">
            <span>Documento</span>
            <strong><?= htmlspecialchars((string) ($profile['document'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
        <div class="summary-item">
            <span>Telefone</span>
            <strong><?= htmlspecialchars((string) ($profile['phone'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
        <div class="summary-item">
            <span>E-mail</span>
            <strong><?= htmlspecialchars((string) ($profile['email'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
        <div class="summary-item">
            <span>Plano</span>
            <strong><?= htmlspecialchars((string) ($profile['plan'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
        <div class="summary-item">
            <span>Vencimento</span>
            <strong><?= htmlspecialchars((string) ($profile['due_day'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
        <div class="summary-item">
            <span>Tecnologia</span>
            <strong><?= htmlspecialchars((string) ($profile['technology'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
        <div class="summary-item">
            <span>Fontes</span>
            <strong>
                <?= !empty($source['mkauth']) ? 'MkAuth' : 'MkAuth indisponivel'; ?>
                <?= !empty($source['local']) ? ' + local' : ''; ?>
            </strong>
        </div>
        <div class="summary-item summary-item--span-2">
            <span>Endereco</span>
            <strong><?= htmlspecialchars((string) ($profile['address'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
    </div>

    <?php if (($upgradeProcess['priority'] ?? 'normal') !== 'normal'): ?>
        <div class="alert alert--<?= ($upgradeProcess['priority'] ?? '') === 'urgent' ? 'error' : 'warning'; ?>" style="margin-top: 14px;">
            <?= htmlspecialchars((string) ($upgradeProcess['pending_label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>
</section>

<?php if ($canCorrectCurrent || $canCorrectCompleted): ?>
    <div class="contract-send-modal" id="client-upgrade-correct-modal" data-upgrade-action-modal hidden>
        <div class="contract-send-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="client-upgrade-correct-title">
            <div class="contract-send-modal__header"><div><p class="section-heading__eyebrow">Correção controlada</p><h2 id="client-upgrade-correct-title"><?= $canCorrectCompleted ? 'Abrir processo corretivo' : 'Corrigir e reenviar'; ?></h2></div><button class="button button--ghost button--small" type="button" data-upgrade-action-close>Cancelar</button></div>
            <form method="post" action="<?= htmlspecialchars(Url::to('/clientes/upgrade/corrigir'), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="contract-send-modal__body">
                    <input type="hidden" name="contract_id" value="<?= htmlspecialchars((string) ($upgradeProcess['contract_id'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="login" value="<?= htmlspecialchars($login, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="summary-grid">
                        <div class="summary-item"><span>Cliente</span><strong><?= htmlspecialchars((string) ($profile['name'] ?? $login), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div class="summary-item"><span>Contrato</span><strong>#<?= htmlspecialchars((string) ($upgradeProcess['contract_id'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div class="summary-item"><span>Plano atual</span><strong><?= htmlspecialchars((string) ($upgradeProcess['current_plan'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div class="summary-item"><span>Plano informado</span><strong><?= htmlspecialchars((string) ($upgradeProcess['new_plan'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div class="summary-item"><span>Tecnologia atual</span><strong><?= htmlspecialchars((string) ($upgradeProcess['current_technology'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div class="summary-item"><span>Tecnologia informada</span><strong><?= htmlspecialchars((string) ($upgradeProcess['new_technology'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    </div>
                    <label class="field" style="margin-top: 16px;"><span>Motivo da correção</span><textarea name="correction_reason" rows="3" required data-upgrade-action-reason></textarea></label>
                    <p class="field-help">O link anterior será invalidado imediatamente. A correção ficará pendente até a nova versão ser confirmada e gerada.</p>
                </div>
                <div class="contract-send-modal__footer"><button class="button button--ghost" type="button" data-upgrade-action-close>Cancelar</button><button class="button" type="submit">Continuar para correção</button></div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if ($canCancelCurrent): ?>
    <div class="contract-send-modal" id="client-upgrade-cancel-modal" data-upgrade-action-modal hidden>
        <div class="contract-send-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="client-upgrade-cancel-title">
            <div class="contract-send-modal__header"><div><p class="section-heading__eyebrow">Confirmação obrigatória</p><h2 id="client-upgrade-cancel-title">Cancelar solicitação</h2></div><button class="button button--ghost button--small" type="button" data-upgrade-action-close>Cancelar</button></div>
            <form method="post" action="<?= htmlspecialchars(Url::to('/clientes/upgrade/cancelar'), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="contract-send-modal__body">
                    <input type="hidden" name="contract_id" value="<?= htmlspecialchars((string) ($upgradeProcess['contract_id'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="login" value="<?= htmlspecialchars($login, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="summary-grid">
                        <div class="summary-item"><span>Cliente</span><strong><?= htmlspecialchars((string) ($profile['name'] ?? $login), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div class="summary-item"><span>Contrato</span><strong>#<?= htmlspecialchars((string) ($upgradeProcess['contract_id'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div class="summary-item"><span>Plano atual</span><strong><?= htmlspecialchars((string) ($upgradeProcess['current_plan'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div class="summary-item"><span>Plano informado</span><strong><?= htmlspecialchars((string) ($upgradeProcess['new_plan'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div class="summary-item"><span>Tecnologia atual</span><strong><?= htmlspecialchars((string) ($upgradeProcess['current_technology'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div class="summary-item"><span>Tecnologia informada</span><strong><?= htmlspecialchars((string) ($upgradeProcess['new_technology'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    </div>
                    <label class="field" style="margin-top: 16px;"><span>Motivo do cancelamento</span><textarea name="cancellation_reason" rows="3" required data-upgrade-action-reason></textarea></label>
                    <div class="alert alert--warning" style="margin-top: 16px;">O link antigo será invalidado imediatamente. Contrato, aceite, assinatura e evidências não serão apagados.</div>
                </div>
                <div class="contract-send-modal__footer"><button class="button button--ghost" type="button" data-upgrade-action-close>Voltar</button><button class="button" type="submit">Confirmar cancelamento</button></div>
            </form>
        </div>
    </div>
<?php endif; ?>

<section class="card">
    <div class="section-heading">
        <p class="section-heading__eyebrow">Contrato Digital</p>
        <h2>Assinatura remota do contrato</h2>
    </div>
    <div class="summary-grid">
        <div class="summary-item summary-item--span-2">
            <span>Status</span>
            <strong><?= htmlspecialchars((string) ($digitalContract['label'] ?? 'Nao possui contrato digital'), ENT_QUOTES, 'UTF-8'); ?></strong>
            <small class="field-help"><?= htmlspecialchars((string) ($digitalContract['description'] ?? 'Solicite a assinatura remota do contrato atual do cliente.'), ENT_QUOTES, 'UTF-8'); ?></small>
        </div>
        <div class="summary-item">
            <span>Ultima assinatura</span>
            <strong><?= htmlspecialchars((string) ($digitalContract['accepted_at'] ?? '') !== '' ? (string) $digitalContract['accepted_at'] : '-', ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
        <div class="summary-item">
            <span>Contrato local</span>
            <strong><?= ((int) ($digitalContract['contract_id'] ?? 0) > 0) ? '#' . htmlspecialchars((string) $digitalContract['contract_id'], ENT_QUOTES, 'UTF-8') : '-'; ?></strong>
        </div>
    </div>
    <?php if (!empty($canRequestContractSignature)): ?>
        <div class="hero-actions" style="margin-top: 16px;">
            <form
                method="post"
                action="<?= htmlspecialchars(Url::to((string) ($digitalContract['request_url'] ?? '/clientes/contrato/solicitar')), ENT_QUOTES, 'UTF-8'); ?>"
                data-digital-contract-form
            >
                <input type="hidden" name="login" value="<?= htmlspecialchars($login, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="confirm_send" value="0" data-digital-contract-confirm-input>
                <input type="hidden" name="signature_mode" value="remote" data-digital-contract-signature-mode-input>
                <input type="hidden" name="remote_signature_reason" value="" data-digital-contract-reason-input>
                <button
                    class="button button--small"
                    type="button"
                    data-digital-contract-open
                    data-client-name="<?= htmlspecialchars((string) ($profile['name'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>"
                    data-client-document="<?= htmlspecialchars((string) ($profile['document'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>"
                    data-client-phone="<?= htmlspecialchars((string) ($profile['phone'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>"
                    data-client-email="<?= htmlspecialchars((string) ($profile['email'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>"
                    data-contract-action="<?= !empty($digitalContract['pending']) ? 'reutilizado' : 'gerado'; ?>"
                    data-contract-pending="<?= !empty($digitalContract['pending']) ? '1' : '0'; ?>"
                >
                    <?= !empty($digitalContract['pending']) ? 'Reenviar aceite' : 'Solicitar assinatura'; ?>
                </button>
            </form>
            <?php if (trim((string) ($digitalContract['detail_url'] ?? '')) !== ''): ?>
                <a class="button button--ghost button--small" href="<?= htmlspecialchars((string) $digitalContract['detail_url'], ENT_QUOTES, 'UTF-8'); ?>">Ver contrato</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<div class="contract-send-modal" data-digital-contract-modal hidden>
    <div class="contract-send-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="digital-contract-modal-title">
        <div class="contract-send-modal__header">
            <div>
                <p class="section-heading__eyebrow">Confirmação obrigatória</p>
                <h2 id="digital-contract-modal-title">Enviar contrato digital</h2>
            </div>
            <button class="button button--ghost button--small" type="button" data-digital-contract-cancel>Cancelar</button>
        </div>
        <div class="contract-send-modal__body">
            <p class="page-description">
                Você está prestes a enviar o contrato digital para este cliente. Confira os dados antes de continuar.
                O link de aceite será enviado pelos canais disponíveis do cadastro.
            </p>
            <div class="status-card status-card--warning" data-digital-contract-pending-message hidden>
                <strong>Já existe um contrato digital pendente para este cliente.</strong>
                <small>O mesmo link será reenviado.</small>
            </div>
            <div class="summary-grid">
                <div class="summary-item summary-item--span-2">
                    <span>Cliente</span>
                    <strong data-digital-contract-client-name>-</strong>
                </div>
                <div class="summary-item">
                    <span>CPF/CNPJ</span>
                    <strong data-digital-contract-client-document>-</strong>
                </div>
                <div class="summary-item">
                    <span>WhatsApp</span>
                    <strong data-digital-contract-client-phone>-</strong>
                </div>
                <div class="summary-item summary-item--span-2">
                    <span>E-mail</span>
                    <strong data-digital-contract-client-email>-</strong>
                </div>
                <div class="summary-item summary-item--span-2">
                    <span>Ação</span>
                    <strong data-digital-contract-action>Será gerado ou reutilizado um link de aceite digital.</strong>
                </div>
            </div>
            <label class="field" style="margin-top: 16px;">
                <span>Forma de assinatura</span>
                <select data-digital-contract-signature-mode>
                    <option value="remote">Titular não está no local / assinatura remota</option>
                    <option value="local">Assinatura colhida no local</option>
                </select>
            </label>
            <label class="field" style="margin-top: 16px;" data-digital-contract-reason-wrapper>
                <span>Motivo da assinatura remota</span>
                <textarea rows="3" required data-digital-contract-reason placeholder="Ex.: titular não está no local"></textarea>
                <small class="field-help" data-digital-contract-reason-error hidden>Informe o motivo antes de enviar.</small>
            </label>
        </div>
        <div class="contract-send-modal__footer">
            <button class="button button--ghost" type="button" data-digital-contract-cancel>Cancelar</button>
            <button class="button" type="button" data-digital-contract-confirm>Confirmar envio do contrato</button>
        </div>
    </div>
</div>

<section class="card" id="upgrade-process">
    <div class="section-heading">
        <p class="section-heading__eyebrow">Upgrade / Migração</p>
        <h2>Aceite e execução técnica</h2>
    </div>
    <div class="summary-grid">
        <div class="summary-item">
            <span>Status do processo</span>
            <strong><?= htmlspecialchars((string) ($upgradeProcess['status_label'] ?? 'Não iniciado'), ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
        <div class="summary-item">
            <span>Status contratual</span>
            <strong><?= htmlspecialchars((string) ($upgradeProcess['contract_status_label'] ?? 'Novo aceite obrigatório'), ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
        <div class="summary-item">
            <span>Status técnico</span>
            <strong><?= htmlspecialchars((string) ($upgradeProcess['technical_status_label'] ?? 'Não iniciado'), ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
        <div class="summary-item">
            <span>Pendência atual</span>
            <strong><?= htmlspecialchars((string) ($upgradeProcess['pending_label'] ?? 'Iniciar processo'), ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
    </div>

    <div class="hero-actions" style="margin-top: 16px;">
        <?php if (!empty($canRequestUpgrade) && empty($upgradeProcess['open']) && empty($upgradeProcess['completed'])): ?>
            <a class="button button--small" href="<?= htmlspecialchars(Url::to('/clientes/upgrade?login=' . rawurlencode($login)), ENT_QUOTES, 'UTF-8'); ?>">Iniciar Upgrade / Migração</a>
        <?php elseif (!empty($upgradeProcess['open'])): ?>
            <a class="button button--ghost button--small" href="<?= htmlspecialchars((string) ($upgradeProcess['resume_url'] ?? '#upgrade-checklist'), ENT_QUOTES, 'UTF-8'); ?>">Retomar upgrade</a>
            <?php if (trim((string) ($upgradeProcess['detail_url'] ?? '')) !== ''): ?>
                <a class="button button--ghost button--small" href="<?= htmlspecialchars((string) $upgradeProcess['detail_url'], ENT_QUOTES, 'UTF-8'); ?>">Ver contrato</a>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!empty($upgradeProcess['active']) && empty($upgradeProcess['acceptance_revoked']) && empty($upgradeProcess['completed']) && !empty($canRequestContractSignature)): ?>
            <form method="post" action="<?= htmlspecialchars(Url::to('/contratos/aceite/enviar'), ENT_QUOTES, 'UTF-8'); ?>" onsubmit="return confirm('Deseja reenviar o aceite ativo ao cliente?');">
                <input type="hidden" name="contract_id" value="<?= htmlspecialchars((string) ($upgradeProcess['contract_id'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="return_to" value="<?= htmlspecialchars('/clientes/detalhe?login=' . rawurlencode($login) . '#upgrade-process', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="send_request_id" value="<?= htmlspecialchars(bin2hex(random_bytes(16)), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="force_resend" value="1">
                <input type="hidden" name="send_whatsapp" value="1">
                <input type="hidden" name="send_email" value="0">
                <button class="button button--ghost button--small" type="submit">Reenviar aceite</button>
            </form>
        <?php endif; ?>
        <?php if ($canCorrectCurrent || $canCorrectCompleted): ?>
            <button class="button button--ghost button--small" type="button" data-upgrade-action-open="client-upgrade-correct-modal"><?= $canCorrectCompleted ? 'Abrir processo corretivo' : 'Corrigir e reenviar'; ?></button>
        <?php endif; ?>
        <?php if ($canCancelCurrent): ?>
            <button class="button button--ghost button--small" type="button" data-upgrade-action-open="client-upgrade-cancel-modal">Cancelar solicitação</button>
        <?php endif; ?>
    </div>

    <?php if (!empty($upgradeProcess['active']) && !empty($canCompleteUpgradeTechnical)): ?>
        <?php $technicalChecklist = is_array($upgradeProcess['checklist'] ?? null) ? $upgradeProcess['checklist'] : []; ?>
        <form id="upgrade-checklist" method="post" action="<?= htmlspecialchars(Url::to('/clientes/upgrade/execucao-tecnica'), ENT_QUOTES, 'UTF-8'); ?>" style="margin-top: 18px;">
            <input type="hidden" name="login" value="<?= htmlspecialchars($login, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="contract_id" value="<?= htmlspecialchars((string) ($upgradeProcess['contract_id'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-grid">
                <?php
                $technicalItems = [
                    'plan_checked' => 'Novo plano conferido no MkAuth',
                    'technology_changed' => 'Tecnologia/equipamento alterado',
                    'pppoe_validated' => 'Login PPPoE validado',
                    'client_connected' => 'Cliente conectado',
                    'speed_checked' => 'Velocidade/plano conferidos',
                    'monthly_value_checked' => 'Valor mensal conferido',
                ];
                ?>
                <?php foreach ($technicalItems as $key => $label): ?>
                    <label class="field">
                        <span><input type="checkbox" name="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>" value="1" <?= !empty($technicalChecklist[$key]) ? 'checked' : ''; ?>> <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
                    </label>
                <?php endforeach; ?>
                <label class="field">
                    <span><input type="checkbox" checked disabled> Aceite do cliente concluído</span>
                    <small class="field-help"><?= !empty($upgradeProcess['accepted']) ? 'Aceite válido confirmado.' : 'Bloqueado: aguarde a assinatura digital.'; ?></small>
                </label>
                <label class="field field--span-2">
                    <span>Observação técnica</span>
                    <textarea name="technical_observation" rows="3" placeholder="Registre ocorrências, equipamento e validações realizadas."><?= htmlspecialchars((string) ($upgradeProcess['technical_observation'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </label>
                <div class="form-actions field--span-2">
                    <button class="button button--ghost" type="submit" name="action" value="save" <?= empty($upgradeProcess['accepted']) ? 'disabled' : ''; ?>>Salvar parcial</button>
                    <button class="button" type="submit" name="action" value="complete" <?= empty($upgradeProcess['accepted']) ? 'disabled' : ''; ?>>Confirmar execução técnica</button>
                </div>
            </div>
        </form>
    <?php endif; ?>
</section>

<section class="card">
    <div class="section-heading">
        <p class="section-heading__eyebrow">Contratos</p>
        <h2>Contratos locais</h2>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Cliente</th>
                    <th>Adesao</th>
                    <th>Status financeiro</th>
                    <th>Atualizado em</th>
                    <th>Ação</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($contracts as $contract): ?>
                    <tr>
                        <td data-label="ID"><?= htmlspecialchars((string) ($contract['id'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td data-label="Cliente"><?= htmlspecialchars((string) ($contract['name'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td data-label="Adesao"><?= htmlspecialchars((string) ($contract['tipo_adesao'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td data-label="Status"><span class="pill"><?= htmlspecialchars((string) ($contract['status_financeiro'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td data-label="Atualizado em"><?= htmlspecialchars((string) ($contract['updated_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td data-label="Ação">
                            <?php if ((int) ($contract['id'] ?? 0) > 0): ?>
                                <a class="button button--ghost button--small" href="<?= htmlspecialchars(Url::to('/contratos/detalhe?id=' . rawurlencode((string) $contract['id'])), ENT_QUOTES, 'UTF-8'); ?>">Ver contrato</a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($contracts === []): ?>
                    <tr><td colspan="6">Nenhum contrato local localizado para este login.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <div class="section-heading">
        <p class="section-heading__eyebrow">Aceite e instalacao</p>
        <h2>Historico vinculado</h2>
    </div>
    <div class="summary-grid">
        <div class="summary-item">
            <span>Registro local</span>
            <strong><?= htmlspecialchars((string) ($registration['status'] ?? 'nao localizado'), ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
        <div class="summary-item">
            <span>Tarefa financeira</span>
            <strong><?= htmlspecialchars((string) ($financialTask['status'] ?? 'nao localizada'), ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
        <div class="summary-item">
            <span>Aceites</span>
            <strong><?= count($acceptanceHistory); ?></strong>
        </div>
        <div class="summary-item">
            <span>Checkpoints</span>
            <strong><?= count($checkpoints); ?></strong>
        </div>
    </div>

    <?php if ($acceptanceHistory !== []): ?>
        <div class="table-wrap" style="margin-top: 18px;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Protocolo</th>
                        <th>Aceito em</th>
                        <th>Criado em</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($acceptanceHistory as $acceptance): ?>
                        <tr>
                            <td data-label="Status"><span class="pill"><?= htmlspecialchars((string) ($acceptance['status'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td data-label="Protocolo"><?= htmlspecialchars((string) ($acceptance['protocolo'] ?? $acceptance['id'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td data-label="Aceito em"><?= htmlspecialchars((string) ($acceptance['accepted_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td data-label="Criado em"><?= htmlspecialchars((string) ($acceptance['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="section-heading">
        <p class="section-heading__eyebrow">Linha do tempo</p>
        <h2>Eventos recentes</h2>
    </div>
    <?php if ($timeline !== []): ?>
        <div class="log-list">
            <?php foreach ($timeline as $event): ?>
                <article class="log-list__item">
                    <div class="log-list__meta">
                        <span class="pill pill--muted"><?= htmlspecialchars((string) ($event['group'] ?? 'Evento'), ENT_QUOTES, 'UTF-8'); ?></span>
                        <time><?= htmlspecialchars((string) ($event['time'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></time>
                    </div>
                    <p>
                        <strong><?= htmlspecialchars((string) ($event['label'] ?? 'Evento'), ENT_QUOTES, 'UTF-8'); ?></strong>
                        <?php if (trim((string) ($event['description'] ?? '')) !== ''): ?>
                            · <?= htmlspecialchars((string) $event['description'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </p>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="page-description">Nenhum evento local encontrado para este cliente.</p>
    <?php endif; ?>
</section>

<?php if ($auditLogs !== []): ?>
    <section class="card">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Auditoria</p>
            <h2>Logs vinculados</h2>
        </div>
        <div class="log-list">
            <?php foreach ($auditLogs as $log): ?>
                <article class="log-list__item">
                    <div class="log-list__meta">
                        <span class="pill pill--muted"><?= htmlspecialchars((string) ($log['action'] ?? 'evento'), ENT_QUOTES, 'UTF-8'); ?></span>
                        <time><?= htmlspecialchars((string) ($log['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></time>
                    </div>
                    <p><?= htmlspecialchars(trim((string) ($log['entity_type'] ?? '')) . ' #' . trim((string) ($log['entity_id'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
