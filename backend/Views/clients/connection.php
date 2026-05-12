<?php

declare(strict_types=1);

use App\Core\Url;

$record = is_array($record ?? null) ? $record : [];
$connection = is_array($connection ?? null) ? $connection : [];
$session = is_array($connection['session'] ?? null) ? $connection['session'] : [];
$lastAuth = is_array($connection['last_auth'] ?? null) ? $connection['last_auth'] : [];
$acceptanceStatus = is_array($acceptanceStatus ?? null) ? $acceptanceStatus : [];
$acceptanceContract = is_array($acceptanceStatus['contract'] ?? null) ? $acceptanceStatus['contract'] : [];
$acceptanceRecord = is_array($acceptanceStatus['acceptance'] ?? null) ? $acceptanceStatus['acceptance'] : [];
$formData = is_array($record['form_data'] ?? null) ? $record['form_data'] : [];
$contactCorrections = array_values(array_filter(
    is_array($record['contact_corrections'] ?? null) ? $record['contact_corrections'] : [],
    static fn (mixed $item): bool => is_array($item)
));
$latestContactCorrection = $contactCorrections !== [] ? $contactCorrections[array_key_last($contactCorrections)] : null;
$online = (bool) ($connection['online'] ?? false);
$completed = (string) ($record['status'] ?? '') === 'completed';
$acceptanceAccepted = !empty($acceptanceStatus['accepted']);
$acceptanceLabel = (string) ($acceptanceStatus['label'] ?? 'aceite pendente');
$fallbackEmail = 'cliente@ievo.com.br';
$resolveEmailContext = static function (array $source) use ($fallbackEmail): array {
    $original = strtolower(trim((string) ($source['email_original'] ?? '')));
    $current = strtolower(trim((string) ($source['email_cliente'] ?? $source['email'] ?? '')));

    if ($current === '' && $original !== '') {
        $current = $original;
    }

    if (($current === '' || $current === $fallbackEmail) && $original !== '' && $original !== $fallbackEmail) {
        $current = $original;
    }

    if ($original === '' && $current !== '' && $current !== $fallbackEmail) {
        $original = $current;
    }

    if ($current === '') {
        $current = $fallbackEmail;
    }

    return [
        'email_original' => $original,
        'email_cliente' => $current,
        'has_real_email' => $current !== '' && $current !== $fallbackEmail,
    ];
};
$emailContext = $resolveEmailContext(array_merge($acceptanceContract, $formData));
$contractEmail = (string) ($emailContext['email_cliente'] ?? '');
$contractEmailOriginal = (string) ($emailContext['email_original'] ?? '');
$hasRealEmail = (bool) ($emailContext['has_real_email'] ?? false);
$displayEmail = $hasRealEmail ? $contractEmail : 'Cliente não informou e-mail';
$displayPhone = trim((string) ($acceptanceRecord['telefone_enviado'] ?? $acceptanceContract['telefone_cliente'] ?? ($formData['celular'] ?? '') ?? ''));
$currentPhone = trim((string) ($acceptanceContract['telefone_cliente'] ?? $formData['telefone_cliente'] ?? $formData['celular'] ?? ''));
$originalPhone = trim((string) ($record['telefone_original'] ?? $formData['telefone_original'] ?? $currentPhone));
$acceptanceContractId = (int) ($acceptanceContract['id'] ?? 0);
$acceptanceToken = trim((string) ($acceptanceRecord['token_hash'] ?? ''));
$acceptanceProtocol = $acceptanceRecord !== [] ? 'AC-' . str_pad((string) ((int) ($acceptanceRecord['id'] ?? 0)), 6, '0', STR_PAD_LEFT) : '';
$acceptedAt = trim((string) ($acceptanceRecord['accepted_at'] ?? ''));
$acceptedAtLabel = $acceptedAt !== '' ? date('d/m/Y H:i', strtotime($acceptedAt) ?: time()) : '';
$reopenToken = trim((string) ($record['token'] ?? ''));
$resumeUrl = $reopenToken !== '' ? Url::to('/clientes/conexao?token=' . rawurlencode($reopenToken)) : Url::to('/clientes/conexao');
$acceptanceLink = !$acceptanceAccepted && $acceptanceToken !== '' ? Url::absolute('/aceite/' . rawurlencode($acceptanceToken)) : '';
$connectionHeading = $completed
    ? 'Instalação finalizada'
    : ($online ? 'Conexão confirmada' : 'Aguardando conexão Radius');
$connectionDescription = $completed
    ? 'O cadastro foi concluído, o aceite está registrado e o login já apareceu conectado no Radius.'
    : ($acceptanceAccepted && !$online
        ? 'O aceite do cliente já foi concluído. Falta apenas o login aparecer conectado no Radius.'
        : (!$acceptanceAccepted
            ? 'Cliente ainda não concluiu o aceite. Mesmo com Radius conectado, a instalação não pode ser finalizada.'
            : 'Aceite concluído e Radius conectado. Você já pode finalizar a instalação.'));

ob_start();
?>
<section class="page-header">
    <div>
        <p class="section-heading__eyebrow">Instalação</p>
        <h1><?= htmlspecialchars($connectionHeading, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="page-description"><?= htmlspecialchars($connectionDescription, ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
</section>

<?php if (!empty($flash)): ?>
    <section class="alert <?= htmlspecialchars(($flash['type'] ?? 'success') === 'error' ? 'alert--error' : 'alert--success', ENT_QUOTES, 'UTF-8'); ?>">
        <?= htmlspecialchars((string) ($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
    </section>
<?php endif; ?>

<section class="content-grid connection-screen">
    <article class="card">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Próximo passo</p>
            <h2><?= $completed ? 'Tudo concluído' : 'Configurar equipamento do cliente'; ?></h2>
        </div>

        <div class="status-card <?= $completed ? 'status-card--success' : ($acceptanceAccepted ? 'status-card--success' : 'status-card--warning'); ?> connection-state-card">
            <span><?= $completed ? 'Fluxo concluído' : 'Próximo passo'; ?></span>
            <strong><?= $completed ? 'Instalação e validação finalizadas.' : ($acceptanceAccepted ? ($online ? 'Aceite concluído e Radius conectado. Você já pode finalizar.' : 'Aceite concluído. Falta apenas o login aparecer conectado no Radius.') : 'Cliente ainda não concluiu o aceite.'); ?></strong>
            <div class="connection-status-pair">
                <div class="connection-status-pair__item">
                    <small>Status do aceite</small>
                    <strong><?= htmlspecialchars($acceptanceLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
                <div class="connection-status-pair__item">
                    <small>Status Radius</small>
                    <strong><?= $online ? 'Conectado' : 'Pendente'; ?></strong>
                </div>
            </div>
        </div>

        <div class="connection-login connection-login--stacked">
            <div>
                <span>Login PPPoE</span>
                <strong><?= htmlspecialchars((string) ($record['login'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div class="pppoe-secret">
                <span>Senha PPPoE</span>
                <div class="pppoe-secret__value">
                    <input type="password" value="<?= htmlspecialchars((string) ($record['senha'] ?? '13v0'), ENT_QUOTES, 'UTF-8'); ?>" readonly data-pppoe-secret-input>
                    <button type="button" class="button button--ghost button--small" data-pppoe-secret-toggle data-pppoe-secret-label="Mostrar">Mostrar</button>
                </div>
            </div>
        </div>

        <div class="summary-grid">
            <div class="summary-item">
                <span>Cliente</span>
                <strong><?= htmlspecialchars((string) ($record['client_name'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div class="summary-item">
                <span>Plano</span>
                <strong><?= htmlspecialchars((string) ($record['plan'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div class="summary-item">
                <span>Status</span>
                <strong><?= $completed ? 'Instalação finalizada' : 'Aguardando conexão'; ?></strong>
            </div>
            <div class="summary-item">
                <span>Aceite</span>
                <strong><?= htmlspecialchars($acceptanceLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div class="summary-item">
                <span>Evidências</span>
                <strong><?= htmlspecialchars((string) ($record['evidence_ref'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
        </div>

        <?php if (!$completed): ?>
            <form method="post" action="<?= htmlspecialchars(Url::to('/clientes/conexao/finalizar'), ENT_QUOTES, 'UTF-8'); ?>" class="form-actions">
                <input type="hidden" name="token" value="<?= htmlspecialchars((string) $token, ENT_QUOTES, 'UTF-8'); ?>">
                <button class="button" type="submit">Verificar e finalizar</button>
                <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/clientes/novo'), ENT_QUOTES, 'UTF-8'); ?>">Cadastrar outro cliente</a>
            </form>
        <?php else: ?>
            <div class="form-actions">
                <a class="button" href="<?= htmlspecialchars(Url::to('/clientes/novo'), ENT_QUOTES, 'UTF-8'); ?>">Cadastrar outro cliente</a>
                <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/instalacoes'), ENT_QUOTES, 'UTF-8'); ?>">Ver instalações</a>
            </div>
        <?php endif; ?>

        <?php if ($acceptanceContractId > 0 && $acceptanceRecord !== []): ?>
            <section class="integration-send-panel soft-card" style="margin-top: 18px;">
                <div class="section-heading">
                    <p class="section-heading__eyebrow"><?= $acceptanceAccepted ? 'Aceite concluído' : 'Envio do aceite'; ?></p>
                    <h2><?= $acceptanceAccepted ? 'Reenvio e correções' : 'Corrigir contato e reenviar'; ?></h2>
                </div>

                <div class="summary-grid">
                    <div class="summary-item">
                        <span>Contatos de envio do aceite</span>
                        <strong>WhatsApp: <?= htmlspecialchars($currentPhone !== '' ? $currentPhone : '-', ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                    <div class="summary-item">
                        <span>E-mail</span>
                        <strong><?= htmlspecialchars($hasRealEmail ? $displayEmail : 'Cliente não informou e-mail', ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                </div>

                <?php if (!$acceptanceAccepted): ?>
                    <details class="soft-card" style="margin-top: 18px;">
                        <summary class="button button--ghost">Corrigir contato do cliente</summary>
                        <div style="padding-top: 16px;">
                            <p class="page-description">Use esta ação apenas antes do aceite concluir. A correção fica rastreável e não altera o MkAuth diretamente.</p>
                            <form method="post" action="<?= htmlspecialchars(Url::to('/clientes/conexao/corrigir-contato'), ENT_QUOTES, 'UTF-8'); ?>" class="integration-send-form" data-integration-send-form="contact-correction" onsubmit="return confirm('Corrigir contato e reenviar o aceite com o mesmo token?');">
                                <input type="hidden" name="token" value="<?= htmlspecialchars((string) $token, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="send_request_id" value="<?= htmlspecialchars(bin2hex(random_bytes(16)), ENT_QUOTES, 'UTF-8'); ?>">

                                <div class="form-grid form-grid--compact" style="margin-top: 12px;">
                                    <label class="field">
                                        <span>Novo WhatsApp</span>
                                        <input type="text" name="new_whatsapp" value="<?= htmlspecialchars($currentPhone, ENT_QUOTES, 'UTF-8'); ?>" placeholder="DDD + número">
                                    </label>
                                    <label class="field">
                                        <span>Novo e-mail</span>
                                        <input type="email" name="new_email" value="<?= htmlspecialchars($hasRealEmail ? $contractEmail : '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="cliente@exemplo.com">
                                    </label>
                                    <label class="field field--span-2">
                                        <span>Motivo da correção</span>
                                        <textarea name="correction_reason" rows="3" placeholder="Explique por que o contato precisou ser corrigido antes do envio." required></textarea>
                                    </label>
                                    <div class="integration-channel-choice field field--span-2" style="margin: 0;">
                                        <label class="integration-channel-choice__item">
                                            <input type="checkbox" name="send_whatsapp" value="1" checked>
                                            <span>
                                                <strong>Reenviar por WhatsApp</strong>
                                                <small>Usa o novo WhatsApp informado.</small>
                                            </span>
                                        </label>
                                        <label class="integration-channel-choice__item <?= $hasRealEmail ? '' : 'integration-channel-choice__item--disabled'; ?>">
                                            <input type="checkbox" name="send_email" value="1" <?= $hasRealEmail ? '' : 'disabled'; ?>>
                                            <span>
                                                <strong>Reenviar por e-mail</strong>
                                                <small><?= $hasRealEmail ? 'Usa o novo e-mail informado.' : 'Cliente não informou e-mail. Corrija o contato antes de reenviar por e-mail.'; ?></small>
                                            </span>
                                        </label>
                                    </div>
                                <p class="field-help field--span-2"><?= count($contactCorrections) >= 2 ? 'Limite de correções atingido. Solicite apoio de um gestor.' : 'A correção será registrada com o contato original, o contato corrigido, o motivo e o técnico responsável.'; ?></p>
                            </div>

                                <button type="submit" class="button">Corrigir contato e reenviar</button>
                            </form>
                        </div>
                    </details>
                <?php endif; ?>

                <div class="hero-actions" style="margin-top: 14px;">
                    <form method="post" action="<?= htmlspecialchars(Url::to('/contratos/aceite/enviar'), ENT_QUOTES, 'UTF-8'); ?>" class="integration-send-form" data-integration-send-form="connection-whatsapp" onsubmit="return confirm('Deseja reenviar o aceite por WhatsApp?');">
                        <input type="hidden" name="contract_id" value="<?= htmlspecialchars((string) $acceptanceContractId, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="return_to" value="<?= htmlspecialchars($resumeUrl, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="send_request_id" value="<?= htmlspecialchars(bin2hex(random_bytes(16)), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="force_resend" value="1">
                        <input type="hidden" name="send_whatsapp" value="1">
                        <input type="hidden" name="send_email" value="0">
                        <button type="submit" class="button">Reenviar por WhatsApp</button>
                    </form>

                    <form method="post" action="<?= htmlspecialchars(Url::to('/contratos/aceite/email'), ENT_QUOTES, 'UTF-8'); ?>" class="integration-send-form" data-integration-send-form="connection-email" onsubmit="return confirm('Deseja reenviar o aceite por e-mail?');">
                        <input type="hidden" name="contract_id" value="<?= htmlspecialchars((string) $acceptanceContractId, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="return_to" value="<?= htmlspecialchars($resumeUrl, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="send_request_id" value="<?= htmlspecialchars(bin2hex(random_bytes(16)), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="force_resend" value="1">
                        <input type="hidden" name="send_whatsapp" value="0">
                        <input type="hidden" name="send_email" value="1">
                        <button type="submit" class="button button--ghost" <?= $hasRealEmail ? '' : 'disabled'; ?>>Reenviar por e-mail</button>
                    </form>
                </div>

                <?php if (!$hasRealEmail): ?>
                    <p class="page-description" style="margin-top: 12px;">Cliente não informou e-mail real. O e-mail só fica disponível depois de uma correção controlada.</p>
                <?php endif; ?>

                <?php if ($contactCorrections !== []): ?>
                    <details class="soft-card" style="margin-top: 18px;">
                        <summary class="button button--ghost">Histórico de correções de contato</summary>
                        <div style="padding-top: 16px;">
                            <div class="log-list">
                                <?php foreach (array_reverse($contactCorrections) as $correction): ?>
                                    <article class="log-list__item">
                                        <strong><?= htmlspecialchars(trim((string) (($correction['technician'] ?? '-') . ' · ' . implode(', ', (array) ($correction['channels'] ?? [])))), ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <p>WhatsApp: <?= htmlspecialchars((string) ($correction['original_whatsapp'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?> → <?= htmlspecialchars((string) ($correction['corrected_whatsapp'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></p>
                                        <p>E-mail: <?= htmlspecialchars((string) ($correction['original_email'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?> → <?= htmlspecialchars((string) ($correction['corrected_email'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></p>
                                        <p>Motivo: <?= htmlspecialchars((string) ($correction['reason'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></p>
                                        <p>Login: <?= htmlspecialchars((string) ($correction['login'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?> · Contrato: <?= htmlspecialchars((string) ($correction['contract_id'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?> · Aceite: <?= htmlspecialchars((string) ($correction['acceptance_id'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></p>
                                        <p>IP: <?= htmlspecialchars((string) ($correction['ip_address'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?> · <?= htmlspecialchars((string) ($correction['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></p>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </details>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($acceptanceAccepted): ?>
            <section class="integration-send-panel soft-card" style="margin-top: 18px;">
                <div class="section-heading">
                    <p class="section-heading__eyebrow">Aceite</p>
                    <h2>Aceite concluído</h2>
                </div>
                <div class="status-card status-card--success">
                    <span>Registro confirmado</span>
                    <strong>Seu contrato foi registrado com segurança.</strong>
                    <small>Protocolo <?= htmlspecialchars($acceptanceProtocol !== '' ? $acceptanceProtocol : '-', ENT_QUOTES, 'UTF-8'); ?> · <?= htmlspecialchars($acceptedAtLabel !== '' ? $acceptedAtLabel : 'Data não disponível', ENT_QUOTES, 'UTF-8'); ?></small>
                </div>
            </section>
        <?php endif; ?>
    </article>

    <aside class="card soft-card">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Detalhes técnicos</p>
            <h2><?= $online ? 'Sessão Radius ativa' : 'Acompanhar autenticação'; ?></h2>
        </div>

        <?php if ($online): ?>
            <div class="status-card status-card--success">
                <span>Sessão ativa encontrada</span>
                <strong><?= htmlspecialchars((string) ($session['framedipaddress'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
                <small>NAS: <?= htmlspecialchars((string) ($session['nasipaddress'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?> · Início: <?= htmlspecialchars((string) ($session['acctstarttime'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></small>
            </div>
        <?php else: ?>
            <div class="status-card">
                <span>Nenhuma sessão ativa em radacct</span>
                <strong>Use o bloco principal para acompanhar o próximo passo</strong>
                <small>Assim que o login aparecer conectado, volte ao topo e use <strong>Verificar e finalizar</strong>.</small>
            </div>
        <?php endif; ?>

        <?php if ($lastAuth !== []): ?>
            <div class="status-card">
                <span>Última autenticação registrada</span>
                <strong><?= htmlspecialchars((string) ($lastAuth['reply'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
                <small><?= htmlspecialchars((string) ($lastAuth['authdate'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?> · IP: <?= htmlspecialchars((string) ($lastAuth['ip'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></small>
            </div>
        <?php endif; ?>
    </aside>
</section>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
