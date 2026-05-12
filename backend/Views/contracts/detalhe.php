<?php

declare(strict_types=1);

use App\Core\Url;

$detail = is_array($detail ?? null) ? $detail : [];
$contract = is_array($detail['contract'] ?? null) ? $detail['contract'] : [];
$acceptance = is_array($detail['acceptance'] ?? null) ? $detail['acceptance'] : [];
$financialTask = is_array($detail['financialTask'] ?? null) ? $detail['financialTask'] : [];
$notificationLogs = is_array($detail['notificationLogs'] ?? null) ? $detail['notificationLogs'] : [];
$auditLogs = is_array($detail['auditLogs'] ?? null) ? $detail['auditLogs'] : [];
$checkpoint = is_array($detail['checkpoint'] ?? null) ? $detail['checkpoint'] : [];
$contactCorrections = array_values(array_filter(
    is_array($detail['contactCorrections'] ?? null) ? $detail['contactCorrections'] : [],
    static fn (mixed $item): bool => is_array($item)
));
$contractId = (int) ($contract['id'] ?? 0);
$canManageContracts = !empty($canManageContracts);
$canManageFinancial = !empty($canManageFinancial);
$canManageSettings = !empty($canManageSettings);
$simulatedAcceptanceLink = (string) ($simulatedAcceptanceLink ?? '');
$integrationStatus = is_array($integrationStatus ?? null) ? $integrationStatus : [];
$evotrixStatus = is_array($integrationStatus['evotrix'] ?? null) ? $integrationStatus['evotrix'] : [];
$emailStatus = is_array($integrationStatus['email'] ?? null) ? $integrationStatus['email'] : [];
$mkAuthTicketStatus = is_array($integrationStatus['mkauth_ticket'] ?? null) ? $integrationStatus['mkauth_ticket'] : [];
$returnTo = '/contratos/detalhe?id=' . $contractId;
$evotrixLastLog = is_array($evotrixStatus['last'] ?? null) ? $evotrixStatus['last'] : [];
$emailLastLog = is_array($emailStatus['last'] ?? null) ? $emailStatus['last'] : [];
$mkAuthLastLog = is_array($mkAuthTicketStatus['last'] ?? null) ? $mkAuthTicketStatus['last'] : [];
$whatsappRequestId = bin2hex(random_bytes(16));
$emailRequestId = bin2hex(random_bytes(16));
$financialRequestId = bin2hex(random_bytes(16));
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
$emailContext = $resolveEmailContext($contract);
$contractEmail = (string) ($emailContext['email_cliente'] ?? '');
$hasRealEmail = (bool) ($emailContext['has_real_email'] ?? false);
$displayEmail = $hasRealEmail ? $contractEmail : 'Cliente não informou e-mail';
$displayPhone = trim((string) ($acceptance['telefone_enviado'] ?? $contract['telefone_cliente'] ?? ''));
$currentPhone = trim((string) ($contract['telefone_cliente'] ?? $checkpoint['telefone_cliente'] ?? $acceptance['telefone_enviado'] ?? ''));
$originalPhone = trim((string) ($checkpoint['telefone_original'] ?? $currentPhone));
$contractEmailOriginal = (string) ($emailContext['email_original'] ?? '');

$formatMoney = static fn (mixed $value): string => number_format((float) $value, 2, ',', '.');
$formatDate = static fn (?string $value): string => trim((string) $value) !== '' ? (string) $value : '-';
$shortHash = static fn (string $value): string => $value !== '' ? substr($value, 0, 16) . '…' : '-';
$summarizeProviderResponse = static function (mixed $value): string {
    if (is_array($value)) {
        $parts = [];

        foreach (['status', 'message', 'detail', 'http_status', 'duration_ms', 'attempts', 'message_id', 'ticket_id'] as $key) {
            if (!array_key_exists($key, $value) || $value[$key] === null || $value[$key] === '') {
                continue;
            }

            $label = match ($key) {
                'http_status' => 'HTTP ' . $value[$key],
                'duration_ms' => $value[$key] . 'ms',
                default => (string) $value[$key],
            };
            $parts[] = $label;
        }

        if (!empty($value['message_count'])) {
            $partsCount = (int) $value['message_count'];
            $partsLabel = $partsCount > 1 ? $partsCount . ' mensagens' : '1 mensagem';
            $parts[] = $partsLabel;
        }

        if (!empty($value['repeated_attempt'])) {
            $parts[] = 'tentativa repetida';
        }

        if (!empty($value['force_resend'])) {
            $parts[] = 'reenvio autorizado';
        }

        return trim(implode(' · ', array_filter($parts, static fn (string $part): bool => trim($part) !== '')));
    }

    if (is_string($value)) {
        $text = trim($value);

        return mb_strlen($text) > 220 ? mb_substr($text, 0, 220) . '…' : $text;
    }

    return '-';
};
$flash = is_array($flash ?? null) ? $flash : null;
$flashType = strtolower((string) ($flash['type'] ?? ''));
$flashClass = match ($flashType) {
    'error', 'danger' => 'alert--error',
    'warning' => 'alert--warning',
    'info', 'simulado', 'blocked', 'bloqueado' => 'alert--info',
    default => 'alert--success',
};
$flashLabel = match ($flashType) {
    'error', 'danger' => 'Erro',
    'warning' => 'Atenção',
    'info', 'simulado' => 'Informação',
    'blocked', 'bloqueado' => 'Bloqueado',
    default => 'Sucesso',
};

ob_start();
?>
<section class="page-header">
    <div>
        <p class="section-heading__eyebrow">Contratos e Aceites</p>
        <h1>Detalhe do Contrato</h1>
        <p class="page-description">Visão consolidada do contrato, aceite, pendência financeira e trilha de auditoria.</p>
    </div>
    <div class="hero-actions">
        <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/contratos/novos'), ENT_QUOTES, 'UTF-8'); ?>">Voltar aos contratos</a>
        <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/contratos/aceites/pendentes'), ENT_QUOTES, 'UTF-8'); ?>">Ver aceites pendentes</a>
    </div>
</section>

<?php if (!empty($flash)): ?>
    <section class="alert <?= htmlspecialchars($flashClass, ENT_QUOTES, 'UTF-8'); ?>" style="margin-bottom: 20px;">
        <strong><?= htmlspecialchars($flashLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
        <p><?= htmlspecialchars((string) ($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
    </section>
<?php endif; ?>

<?php if (!empty($moduleMessage)): ?>
    <section class="card" style="margin-bottom: 20px;">
        <p class="page-description"><?= htmlspecialchars((string) $moduleMessage, ENT_QUOTES, 'UTF-8'); ?></p>
    </section>
<?php endif; ?>

<section class="card">
    <div class="section-heading">
        <p class="section-heading__eyebrow">Resumo</p>
        <h2><?= htmlspecialchars((string) ($contract['nome_cliente'] ?? 'Contrato'), ENT_QUOTES, 'UTF-8'); ?></h2>
    </div>

    <div class="summary-grid">
        <div class="summary-item">
            <span>Cliente</span>
            <strong><?= htmlspecialchars((string) ($contract['nome_cliente'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
        <div class="summary-item">
            <span>Login</span>
            <strong><?= htmlspecialchars((string) ($contract['mkauth_login'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
        <div class="summary-item">
            <span>Status financeiro</span>
            <strong><span class="pill"><?= htmlspecialchars((string) ($contract['status_financeiro'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></strong>
        </div>
        <div class="summary-item">
            <span>Status do aceite</span>
            <strong><span class="pill pill--muted"><?= htmlspecialchars((string) ($acceptance['status'] ?? $acceptance['acceptance_status'] ?? 'criado'), ENT_QUOTES, 'UTF-8'); ?></span></strong>
        </div>
        <div class="summary-item">
            <span>Tipo de adesão</span>
            <strong><?= htmlspecialchars((string) ($contract['tipo_adesao'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
        <div class="summary-item">
            <span>Tipo de aceite</span>
            <strong><?= htmlspecialchars((string) ($contract['tipo_aceite'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
    </div>

    <div class="hero-actions" style="margin-top: 18px;">
        <a class="button button--ghost" href="#financeiro">Ver pendência financeira</a>
        <a class="button button--ghost" href="#logs">Ver logs relacionados</a>
        <?php if ($canManageSettings): ?>
            <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/configuracoes?tab=contratos'), ENT_QUOTES, 'UTF-8'); ?>">Abrir configurações</a>
        <?php endif; ?>
        <?php if ($canManageContracts && $acceptance !== []): ?>
            <section class="integration-send-panel card" style="width: 100%; margin-top: 8px;">
                <div class="section-heading">
                    <p class="section-heading__eyebrow">Envio do aceite ao cliente</p>
                    <h2>Reenviar com os contatos atuais</h2>
                </div>

                <div class="summary-grid" style="margin-bottom: 0;">
                    <div class="summary-item">
                        <span>Contatos de envio do aceite</span>
                        <strong>WhatsApp: <?= htmlspecialchars($currentPhone !== '' ? $currentPhone : '-', ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                    <div class="summary-item">
                        <span>E-mail</span>
                        <strong><?= htmlspecialchars($hasRealEmail ? $displayEmail : 'Cliente não informou e-mail', ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                </div>

                <div class="hero-actions" style="margin-top: 14px;">
                    <form method="post" action="<?= htmlspecialchars(Url::to('/contratos/aceite/enviar'), ENT_QUOTES, 'UTF-8'); ?>" onsubmit="return confirm('Deseja reenviar o aceite por WhatsApp?');" class="integration-send-form" data-integration-send-form="detail-whatsapp">
                        <input type="hidden" name="contract_id" value="<?= htmlspecialchars((string) $contractId, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="send_request_id" value="<?= htmlspecialchars($whatsappRequestId, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="force_resend" value="1">
                        <input type="hidden" name="send_whatsapp" value="1">
                        <input type="hidden" name="send_email" value="0">
                        <button type="submit" class="button">Reenviar por WhatsApp</button>
                    </form>

                    <form method="post" action="<?= htmlspecialchars(Url::to('/contratos/aceite/email'), ENT_QUOTES, 'UTF-8'); ?>" onsubmit="return confirm('Deseja reenviar o aceite por e-mail?');" class="integration-send-form" data-integration-send-form="detail-email">
                        <input type="hidden" name="contract_id" value="<?= htmlspecialchars((string) $contractId, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="send_request_id" value="<?= htmlspecialchars($emailRequestId, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="force_resend" value="1">
                        <input type="hidden" name="send_whatsapp" value="0">
                        <input type="hidden" name="send_email" value="1">
                        <button type="submit" class="button button--ghost" <?= $hasRealEmail ? '' : 'disabled'; ?>>Reenviar por e-mail</button>
                    </form>

                </div>

                <?php if ($contactCorrections !== []): ?>
                    <details style="margin-top: 18px;" class="soft-card">
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
    </div>
</section>

<section class="card" style="margin-top: 20px;">
    <div class="section-heading">
        <p class="section-heading__eyebrow">Integrações</p>
        <h2>Histórico de integrações</h2>
        <p class="page-description">Aqui você acompanha se a ação foi apenas simulada ou realmente disparada.</p>
    </div>

    <div class="summary-grid">
        <div class="summary-item">
            <span>Evotrix</span>
            <strong><?= !empty($evotrixStatus['enabled']) ? 'Habilitado' : 'Desabilitado'; ?></strong>
        </div>
        <div class="summary-item">
            <span>Evotrix DRY_RUN</span>
            <strong><?= !empty($evotrixStatus['dry_run']) ? 'Sim' : 'Não'; ?></strong>
        </div>
        <div class="summary-item">
            <span>MkAuth Ticket</span>
            <strong><?= !empty($mkAuthTicketStatus['enabled']) ? 'Habilitado' : 'Desabilitado'; ?></strong>
        </div>
        <div class="summary-item">
            <span>MkAuth Ticket DRY_RUN</span>
            <strong><?= !empty($mkAuthTicketStatus['dry_run']) ? 'Sim' : 'Não'; ?></strong>
        </div>
        <div class="summary-item">
            <span>E-mail</span>
            <strong><?= !empty($emailStatus['enabled']) ? 'Habilitado' : 'Desabilitado'; ?></strong>
        </div>
        <div class="summary-item">
            <span>E-mail DRY_RUN</span>
            <strong><?= !empty($emailStatus['dry_run']) ? 'Sim' : 'Não'; ?></strong>
        </div>
    </div>

    <div class="integration-timeline" style="margin-top: 18px;">
        <article class="soft-card integration-timeline__item">
            <div class="section-heading">
                <p class="section-heading__eyebrow">WhatsApp</p>
                <h2>Evotrix</h2>
            </div>
            <ul class="integration-list">
                <li><span>Modo</span><strong><?= !empty($evotrixStatus['dry_run']) ? 'Simulado' : 'Real'; ?></strong></li>
                <li><span>Status</span><strong><?= !empty($evotrixStatus['enabled']) ? 'Habilitado' : 'Desabilitado'; ?></strong></li>
                <li><span>Somente número teste</span><strong><?= !empty($evotrixStatus['allow_only_test_phone']) ? 'Sim' : 'Não'; ?></strong></li>
                <li><span>Número teste</span><strong><?= htmlspecialchars((string) ($evotrixStatus['test_phone'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></li>
                <li><span>Telefone</span><strong><?= htmlspecialchars((string) ($evotrixLastLog['recipient'] ?? ($acceptance['telefone_enviado'] ?? $contract['telefone_cliente'] ?? '-')), ENT_QUOTES, 'UTF-8'); ?></strong></li>
                <li><span>Última tentativa</span><strong><?= htmlspecialchars((string) ($evotrixLastLog['created_at'] ?? 'Ainda não houve tentativa'), ENT_QUOTES, 'UTF-8'); ?></strong></li>
                <li><span>Resultado</span><strong><?= htmlspecialchars((string) ($evotrixLastLog['status'] ?? 'pendente'), ENT_QUOTES, 'UTF-8'); ?></strong></li>
            </ul>
            <p class="page-description integration-timeline__response"><?= htmlspecialchars((string) ($evotrixLastLog['provider_response'] ?? 'Ainda não houve tentativa de envio por WhatsApp para este contrato.'), ENT_QUOTES, 'UTF-8'); ?></p>
        </article>

        <article class="soft-card integration-timeline__item">
            <div class="section-heading">
                <p class="section-heading__eyebrow">E-mail</p>
                <h2>SMTP autenticado</h2>
            </div>
            <ul class="integration-list">
                <li><span>Modo</span><strong><?= !empty($emailStatus['dry_run']) ? 'Simulado' : 'Real'; ?></strong></li>
                <li><span>Status</span><strong><?= !empty($emailStatus['enabled']) ? 'Habilitado' : 'Desabilitado'; ?></strong></li>
                <li><span>Destino</span><strong><?= htmlspecialchars((string) ($emailLastLog['recipient'] ?? $contract['email_cliente'] ?? $contract['email'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></li>
                <li><span>Teste controlado</span><strong><?= !empty($emailStatus['allow_only_test_email']) ? 'Sim' : 'Não'; ?></strong></li>
                <li><span>E-mail de teste</span><strong><?= htmlspecialchars((string) ($emailStatus['test_to'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></li>
                <li><span>Última tentativa</span><strong><?= htmlspecialchars((string) ($emailLastLog['created_at'] ?? 'Ainda não houve tentativa'), ENT_QUOTES, 'UTF-8'); ?></strong></li>
                <li><span>Resultado</span><strong><?= htmlspecialchars((string) ($emailLastLog['status'] ?? 'pendente'), ENT_QUOTES, 'UTF-8'); ?></strong></li>
            </ul>
            <p class="page-description integration-timeline__response"><?= htmlspecialchars((string) ($emailLastLog['provider_response'] ?? 'Ainda não houve tentativa de envio por e-mail para este contrato.'), ENT_QUOTES, 'UTF-8'); ?></p>
        </article>

        <article class="soft-card integration-timeline__item">
            <div class="section-heading">
                <p class="section-heading__eyebrow">Financeiro</p>
                <h2>MkAuth chamado</h2>
            </div>
            <?php
                $mkAuthContext = json_decode((string) ($mkAuthLastLog['context_json'] ?? ''), true);
                $mkAuthContextText = is_array($mkAuthContext)
                    ? json_encode($mkAuthContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : (string) ($mkAuthLastLog['context_json'] ?? '');
            ?>
            <ul class="integration-list">
                <li><span>Modo</span><strong><?= !empty($mkAuthTicketStatus['dry_run']) ? 'Simulado' : 'Real'; ?></strong></li>
                <li><span>Status</span><strong><?= !empty($mkAuthTicketStatus['enabled']) ? 'Habilitado' : 'Desabilitado'; ?></strong></li>
                <li><span>Endpoint</span><strong><?= htmlspecialchars((string) ($mkAuthTicketStatus['endpoint'] ?? '/api/chamado/inserir'), ENT_QUOTES, 'UTF-8'); ?></strong></li>
                <li><span>Última tentativa</span><strong><?= htmlspecialchars((string) ($mkAuthLastLog['created_at'] ?? 'Ainda não houve tentativa'), ENT_QUOTES, 'UTF-8'); ?></strong></li>
                <li><span>Resultado</span><strong><?= htmlspecialchars((string) ($mkAuthLastLog['action'] ?? 'pendente'), ENT_QUOTES, 'UTF-8'); ?></strong></li>
                <li><span>Timeout</span><strong><?= htmlspecialchars((string) ($mkAuthTicketStatus['timeout_seconds'] ?? 15), ENT_QUOTES, 'UTF-8'); ?>s</strong></li>
            </ul>
            <p class="page-description integration-timeline__response"><?= htmlspecialchars($mkAuthContextText !== '' ? $mkAuthContextText : 'Ainda não houve tentativa de abertura de chamado financeiro para este contrato.', ENT_QUOTES, 'UTF-8'); ?></p>
        </article>
    </div>
</section>

<section class="content-grid" style="margin-top: 20px;">
    <article class="card">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Dados do cliente</p>
            <h2>Contrato</h2>
        </div>

        <div class="summary-grid">
            <div class="summary-item"><span>Telefone</span><strong><?= htmlspecialchars((string) ($contract['telefone_cliente'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="summary-item"><span>Adesão</span><strong><?= htmlspecialchars((string) ($contract['tipo_adesao'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="summary-item"><span>Valor da adesão</span><strong>R$ <?= htmlspecialchars($formatMoney($contract['valor_adesao'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="summary-item"><span>Parcelas</span><strong><?= htmlspecialchars((string) ($contract['parcelas_adesao'] ?? 1), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="summary-item"><span>Valor da parcela</span><strong>R$ <?= htmlspecialchars($formatMoney($contract['valor_parcela_adesao'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="summary-item"><span>Vencimento 1ª parcela</span><strong><?= htmlspecialchars($formatDate($contract['vencimento_primeira_parcela'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="summary-item"><span>Fidelidade</span><strong><?= htmlspecialchars((string) ($contract['fidelidade_meses'] ?? 12), ENT_QUOTES, 'UTF-8'); ?> meses</strong></div>
            <div class="summary-item"><span>Benefício</span><strong>R$ <?= htmlspecialchars($formatMoney($contract['beneficio_valor'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="summary-item summary-item--span-2"><span>Multa total</span><strong>R$ <?= htmlspecialchars($formatMoney($contract['multa_total'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="summary-item summary-item--span-2"><span>Observação da adesão</span><strong><?= htmlspecialchars((string) ($contract['observacao_adesao'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
        </div>
    </article>

    <article class="card">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Aceite</p>
            <h2>Status digital</h2>
        </div>

        <div class="summary-grid">
            <div class="summary-item"><span>Identificador</span><strong>#<?= htmlspecialchars((string) ($acceptance['id'] ?? $acceptance['acceptance_id'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="summary-item"><span>Expira em</span><strong><?= htmlspecialchars($formatDate($acceptance['token_expires_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="summary-item"><span>Telefone enviado</span><strong><?= htmlspecialchars((string) ($acceptance['telefone_enviado'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="summary-item"><span>Enviado em</span><strong><?= htmlspecialchars($formatDate($acceptance['sent_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="summary-item"><span>Aceito em</span><strong><?= htmlspecialchars($formatDate($acceptance['accepted_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="summary-item"><span>Versão do termo</span><strong><?= htmlspecialchars((string) ($acceptance['termo_versao'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="summary-item summary-item--span-2"><span>Hash do termo</span><strong><?= htmlspecialchars($shortHash((string) ($acceptance['termo_hash'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="summary-item summary-item--span-2"><span>Hash do token</span><strong><?= htmlspecialchars($shortHash((string) ($acceptance['token_hash'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="summary-item summary-item--span-2"><span>IP / usuário</span><strong><?= htmlspecialchars(trim((string) ($acceptance['ip_address'] ?? '-')) . ' · ' . trim((string) ($acceptance['user_agent'] ?? '-')), ENT_QUOTES, 'UTF-8'); ?></strong></div>
        </div>
    </article>
</section>

<section class="content-grid" id="financeiro" style="margin-top: 20px;">
    <article class="card">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Financeiro</p>
            <h2>Pendência vinculada</h2>
        </div>

        <?php if (!empty($financialTask)): ?>
            <div class="summary-grid">
                <div class="summary-item"><span>Título</span><strong><?= htmlspecialchars((string) ($financialTask['titulo'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="summary-item"><span>Status</span><strong><span class="pill"><?= htmlspecialchars((string) ($financialTask['status'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></strong></div>
                <div class="summary-item summary-item--span-2"><span>Descrição</span><strong><?= nl2br(htmlspecialchars((string) ($financialTask['descricao'] ?? '-'), ENT_QUOTES, 'UTF-8')); ?></strong></div>
            </div>

            <?php if ($canManageFinancial): ?>
                <div class="hero-actions" style="margin-top: 18px;">
                    <form method="post" action="<?= htmlspecialchars(Url::to('/contratos/financeiro/concluir'), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="contract_id" value="<?= htmlspecialchars((string) $contractId, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" class="button">Marcar como concluído</button>
                    </form>
                    <form method="post" action="<?= htmlspecialchars(Url::to('/contratos/financeiro/chamado'), ENT_QUOTES, 'UTF-8'); ?>" onsubmit="return confirm('Deseja abrir chamado financeiro no MkAuth?');" class="integration-send-form" data-integration-send-form="mkauth_ticket">
                        <input type="hidden" name="contract_id" value="<?= htmlspecialchars((string) $contractId, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="send_request_id" value="<?= htmlspecialchars($financialRequestId, ENT_QUOTES, 'UTF-8'); ?>">
                        <label class="field integration-send-form__field integration-resend-option">
                            <span>
                                <input type="checkbox" name="force_resend" value="1">
                                <strong>Abrir mesmo assim</strong>
                            </span>
                            <small class="field-help">Use quando já existir um chamado preparado e você quiser reabrir manualmente.</small>
                        </label>
                        <button type="submit" class="button button--ghost">Abrir chamado financeiro no MkAuth</button>
                    </form>
                    <form method="post" action="<?= htmlspecialchars(Url::to('/contratos/financeiro/cancelar'), ENT_QUOTES, 'UTF-8'); ?>" onsubmit="return confirm('Cancelar esta pendencia financeira?');">
                        <input type="hidden" name="contract_id" value="<?= htmlspecialchars((string) $contractId, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" class="button button--ghost">Cancelar pendência</button>
                    </form>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <p class="page-description">Nenhuma tarefa financeira vinculada ao contrato foi encontrada neste momento.</p>
        <?php endif; ?>
    </article>

    <article class="card" id="logs">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Logs</p>
            <h2>Notificações e auditoria</h2>
        </div>

        <div class="summary-grid" style="margin-bottom: 18px;">
            <div class="summary-item">
                <span>Link futuro</span>
                <strong><?= htmlspecialchars($simulatedAcceptanceLink, ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div class="summary-item">
                <span>Aceite</span>
                <strong><span class="pill pill--muted"><?= htmlspecialchars((string) ($acceptance['status'] ?? $acceptance['acceptance_status'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></strong>
            </div>
        </div>

        <div class="log-list">
            <?php if ($notificationLogs !== []): ?>
                <?php foreach ($notificationLogs as $log): ?>
                    <?php
                        $providerResponse = $log['provider_response'] ?? '';
                        $providerResponseData = is_string($providerResponse) ? json_decode($providerResponse, true) : (is_array($providerResponse) ? $providerResponse : null);
                        $providerMode = is_array($providerResponseData) && array_key_exists('dry_run', $providerResponseData)
                            ? ((bool) $providerResponseData['dry_run'] ? 'Simulado' : 'Real')
                            : ($log['status'] === 'simulado' ? 'Simulado' : 'Real');
                        $providerStatus = trim((string) ($log['status'] ?? ''));
                        $providerResponseSummary = $summarizeProviderResponse($providerResponseData ?? $providerResponse);
                    ?>
                    <article class="log-list__item">
                        <strong><?= htmlspecialchars((string) ($log['channel'] ?? 'whatsapp'), ENT_QUOTES, 'UTF-8'); ?> · <?= htmlspecialchars((string) ($log['provider'] ?? 'evotrix'), ENT_QUOTES, 'UTF-8'); ?></strong>
                        <p>
                            <span class="pill"><?= htmlspecialchars($providerMode, ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="pill pill--muted" style="margin-left: 8px;"><?= htmlspecialchars($providerStatus !== '' ? $providerStatus : '-', ENT_QUOTES, 'UTF-8'); ?></span>
                        </p>
                        <p><?= htmlspecialchars((string) ($log['recipient'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?> · <?= htmlspecialchars((string) ($log['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></p>
                        <p><?= htmlspecialchars((string) ($log['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                        <p class="page-description integration-timeline__response"><?= htmlspecialchars($providerResponseSummary !== '' ? $providerResponseSummary : '-', ENT_QUOTES, 'UTF-8'); ?></p>
                    </article>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="page-description">Nenhum log de notificação encontrado.</p>
            <?php endif; ?>
        </div>

        <div class="section-heading" style="margin-top: 18px;">
            <p class="section-heading__eyebrow">Auditoria</p>
            <h2>Registros locais</h2>
        </div>

        <div class="log-list">
            <?php if ($auditLogs !== []): ?>
                <?php foreach ($auditLogs as $log): ?>
                    <?php
                        $context = json_decode((string) ($log['context_json'] ?? ''), true);
                        $contextText = is_array($context) ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) ($log['context_json'] ?? '');
                    ?>
                    <article class="log-list__item">
                        <strong><?= htmlspecialchars((string) ($log['action'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
                        <p><?= htmlspecialchars((string) ($log['entity_type'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?> #<?= htmlspecialchars((string) ($log['entity_id'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?> · <?= htmlspecialchars((string) ($log['actor_login'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></p>
                        <p><?= htmlspecialchars((string) ($log['ip_address'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?> · <?= htmlspecialchars((string) ($log['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php if ($contextText !== ''): ?>
                            <p><?= htmlspecialchars($contextText, ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="page-description">Nenhum log administrativo relacionado foi encontrado.</p>
            <?php endif; ?>
        </div>
    </article>
</section>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
