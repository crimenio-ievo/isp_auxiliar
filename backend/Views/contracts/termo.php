<?php

declare(strict_types=1);

use App\Core\Url;

$context = is_array($context ?? null) ? $context : [];
$contract = is_array($context['contract'] ?? null) ? $context['contract'] : [];
$acceptance = is_array($context['acceptance'] ?? null) ? $context['acceptance'] : [];
$publicDetails = is_array($context['publicDetails'] ?? null) ? $context['publicDetails'] : [];
$customerDetails = is_array($publicDetails['cliente'] ?? null) ? $publicDetails['cliente'] : [];
$installationDetails = is_array($publicDetails['instalacao'] ?? null) ? $publicDetails['instalacao'] : [];
$planDetails = is_array($publicDetails['plano'] ?? null) ? $publicDetails['plano'] : [];
$contractDetails = is_array($publicDetails['contrato'] ?? null) ? $publicDetails['contrato'] : [];
$providerLegal = is_array($providerLegal ?? null) ? $providerLegal : [];
$providerName = trim((string) ($providerName ?? $appName ?? 'nossa equipe'));
$providerLabel = $providerName !== '' ? $providerName : 'nossa equipe';
$centralAssinanteUrl = trim((string) ($centralAssinanteUrl ?? ($context['centralAssinanteUrl'] ?? 'https://sistema.ievo.com.br/central')));
$centralAssinanteUrl = $centralAssinanteUrl !== '' ? $centralAssinanteUrl : 'https://sistema.ievo.com.br/central';
$contractTitle = $providerLabel === 'nossa equipe'
    ? 'TERMO DE ACEITE DIGITAL E CONTRATAÇÃO SCM'
    : 'TERMO DE ACEITE DIGITAL E CONTRATAÇÃO SCM';
$signaturePath = trim((string) ($signaturePath ?? ($publicDetails['assinatura_path'] ?? '')));
$signatureRef = trim((string) ($signatureRef ?? ''));
if ($signatureRef === '' && $signaturePath !== '') {
    $signatureRef = basename(dirname($signaturePath));
}
$termValidated = !empty($termValidated);
$termLocked = !empty($termLocked);
$termValidationError = trim((string) ($termValidationError ?? ''));
$termAttemptsRemaining = max(0, (int) ($termAttemptsRemaining ?? 5));
$termValidationDigits = max(3, (int) ($termValidationDigits ?? 3));
$termUrl = (string) ($termUrl ?? ($context['termUrl'] ?? ''));
$acceptanceUrl = (string) ($acceptanceUrl ?? ($context['acceptanceUrl'] ?? ''));
$acceptedAt = trim((string) ($acceptance['accepted_at'] ?? ''));
$protocol = trim((string) ($acceptance['token_hash'] ?? ''));
$protocol = $protocol !== '' ? strtoupper(substr($protocol, 0, 12)) : ('ACEITE-' . str_pad((string) ((int) ($acceptance['id'] ?? 0)), 6, '0', STR_PAD_LEFT));
$status = (string) ($acceptance['status'] ?? '');
$acceptanceId = (int) ($acceptance['id'] ?? 0);
$contractId = (int) ($contract['id'] ?? 0);
$maskedDocument = trim((string) ($context['maskedDocument'] ?? '-'));
$clientName = trim((string) ($customerDetails['nome'] ?? $contract['nome_cliente'] ?? '-'));
$clientLogin = trim((string) ($customerDetails['login'] ?? $contract['mkauth_login'] ?? '-'));
$clientPhone = trim((string) ($customerDetails['telefone'] ?? $contract['telefone_cliente'] ?? '-'));
$clientEmail = trim((string) ($customerDetails['email'] ?? $contract['email_cliente'] ?? '-'));
$addressParts = array_filter([
    trim((string) ($installationDetails['endereco'] ?? '')),
    trim((string) ($installationDetails['numero'] ?? '')),
    trim((string) ($installationDetails['bairro'] ?? '')),
    trim((string) ($installationDetails['cidade'] ?? '')),
    trim((string) ($installationDetails['estado'] ?? '')),
], static fn (string $value): bool => $value !== '');
$addressLine = $addressParts !== [] ? implode(', ', $addressParts) : '-';
$planName = trim((string) ($planDetails['nome'] ?? '-'));
$monthlyValue = $planDetails['valor_mensal'] ?? null;
$monthlyValueFormatted = $monthlyValue !== null ? 'R$ ' . number_format((float) $monthlyValue, 2, ',', '.') : '-';
$dueDate = trim((string) ($installationDetails['vencimento'] ?? $contract['vencimento_primeira_parcela'] ?? '-'));
$adherenceType = trim((string) ($contractDetails['tipo_adesao'] ?? $contract['tipo_adesao'] ?? '-'));
$adherenceValue = 'R$ ' . number_format((float) ($contractDetails['valor_adesao'] ?? ($contract['valor_adesao'] ?? 0)), 2, ',', '.');
$installments = (int) ($contractDetails['parcelas_adesao'] ?? $contract['parcelas_adesao'] ?? 1);
$installmentValue = 'R$ ' . number_format((float) ($contractDetails['valor_parcela_adesao'] ?? ($contract['valor_parcela_adesao'] ?? 0)), 2, ',', '.');
$firstInstallmentDue = trim((string) ($contractDetails['vencimento_primeira_parcela'] ?? $contract['vencimento_primeira_parcela'] ?? '-'));
$fidelityMonths = (int) ($contractDetails['fidelidade_meses'] ?? $contract['fidelidade_meses'] ?? 12);
$authorizedBy = trim((string) ($contractDetails['beneficio_concedido_por'] ?? $contract['beneficio_concedido_por'] ?? ''));
$observations = trim(implode(' · ', array_filter([
    trim((string) ($contractDetails['observacao_adesao'] ?? '')),
    trim((string) ($installationDetails['observacao'] ?? '')),
], static fn (string $value): bool => $value !== '')));
$providerLegalName = trim((string) ($providerLegal['legal_name'] ?? '')) !== ''
    ? trim((string) ($providerLegal['legal_name'] ?? ''))
    : $providerLabel;
$providerCnpj = trim((string) ($providerLegal['document'] ?? '')) !== ''
    ? trim((string) ($providerLegal['document'] ?? ''))
    : 'Não informado';
$providerAddressLine = trim(implode(', ', array_filter([
    trim((string) ($providerLegal['address'] ?? '')),
    trim((string) ($providerLegal['neighborhood'] ?? '')),
    trim((string) (($providerLegal['city'] ?? '') . '/' . ($providerLegal['state'] ?? ''))),
    trim((string) ($providerLegal['zip'] ?? '')) !== '' ? 'CEP ' . trim((string) ($providerLegal['zip'] ?? '')) : '',
], static fn (string $value): bool => trim($value) !== '')));
$providerAddressLine = $providerAddressLine !== '' ? $providerAddressLine : 'Não informado';
$providerPhone = trim((string) ($providerLegal['phone'] ?? '')) !== ''
    ? trim((string) ($providerLegal['phone'] ?? ''))
    : 'Não informado';
$providerSite = trim((string) ($providerLegal['site'] ?? '')) !== ''
    ? trim((string) ($providerLegal['site'] ?? ''))
    : 'Não informado';
$providerEmail = trim((string) ($providerLegal['email'] ?? '')) !== ''
    ? trim((string) ($providerLegal['email'] ?? ''))
    : 'Não informado';
$providerAnatel = trim((string) ($providerLegal['anatel_process'] ?? '')) !== ''
    ? trim((string) ($providerLegal['anatel_process'] ?? ''))
    : 'Não informado';
$technicianName = trim((string) ($contractDetails['technician_name'] ?? $contract['technician_name'] ?? ''));
$technicianLogin = trim((string) ($contractDetails['technician_login'] ?? $contract['technician_login'] ?? ''));
$technicianDisplay = $technicianName !== '' ? $technicianName : ($technicianLogin !== '' ? $technicianLogin : 'Equipe técnica');
$ipAddress = trim((string) ($acceptance['ip_address'] ?? ''));
$userAgent = trim((string) ($acceptance['user_agent'] ?? ''));
$signatureUrl = '';
if ($signatureRef !== '') {
    $signatureUrl = Url::to('/clientes/evidencias/arquivo?ref=' . rawurlencode($signatureRef) . '&file=assinatura.png');
}

ob_start();
?>
<section class="term-page">
    <div class="term-toolbar no-print">
        <div class="page-header">
            <div>
                <p class="section-heading__eyebrow"><?= $termValidated ? 'Termo assinado' : 'Ver termo assinado'; ?></p>
                <h1><?= htmlspecialchars($termValidated ? 'Termo assinado' : 'Ver termo assinado', ENT_QUOTES, 'UTF-8'); ?></h1>
                <p class="page-description"><?= $termValidated ? 'Documento público validado por CPF/CNPJ parcial, com layout imprimível em A4.' : 'Acesse o documento com uma validação simples para sua segurança.'; ?></p>
            </div>
            <div class="hero-actions">
                <?php if ($termValidated): ?>
                    <button type="button" class="button button--ghost" onclick="window.print()">Imprimir / salvar PDF</button>
                    <a class="button button--ghost" href="<?= htmlspecialchars($centralAssinanteUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Acessar Central do Assinante</a>
                <?php endif; ?>
                <?php if ($acceptanceUrl !== ''): ?>
                    <a class="button button--ghost" href="<?= htmlspecialchars($acceptanceUrl, ENT_QUOTES, 'UTF-8'); ?>">Voltar ao aceite</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!$termValidated): ?>
        <section class="term-access-layout">
            <article class="card term-gate-card">
                <div class="section-heading">
                    <p class="section-heading__eyebrow">Acesso ao termo</p>
                    <h2>Validação simples para abrir o documento</h2>
                </div>

                <div class="summary-grid term-summary-grid">
                    <div class="summary-item">
                        <span>Protocolo</span>
                        <strong><?= htmlspecialchars($protocol, ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                    <div class="summary-item">
                        <span>Status do aceite</span>
                        <strong><?= htmlspecialchars($status !== '' ? $status : 'aceito', ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                </div>

                <?php if ($termValidationError !== ''): ?>
                    <div class="status-card status-card--warning" style="margin-bottom: 16px;">
                        <strong><?= htmlspecialchars($termValidationError, ENT_QUOTES, 'UTF-8'); ?></strong>
                <?php if ($termLocked): ?>
                            <small>O acesso ficou bloqueado nesta sessão após várias tentativas.</small>
                        <?php else: ?>
                            <small>Faltam <?= (int) $termAttemptsRemaining; ?> tentativa(s).</small>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (!$termLocked): ?>
                    <p class="page-description">
                        Para sua segurança, informe os <strong><?= htmlspecialchars((string) $termValidationDigits, ENT_QUOTES, 'UTF-8'); ?> primeiros dígitos do CPF/CNPJ</strong> do titular.
                    </p>

                    <form method="post" action="<?= htmlspecialchars(Url::to('/aceite/' . rawurlencode((string) ($token ?? '')) . '/termo'), ENT_QUOTES, 'UTF-8'); ?>" class="term-gate-form">
                        <label class="field">
                            <span>Primeiros <?= htmlspecialchars((string) $termValidationDigits, ENT_QUOTES, 'UTF-8'); ?> dígitos</span>
                            <input
                                type="password"
                                name="document_prefix"
                                inputmode="numeric"
                                autocomplete="off"
                                maxlength="<?= htmlspecialchars((string) $termValidationDigits, ENT_QUOTES, 'UTF-8'); ?>"
                                placeholder="<?= htmlspecialchars(str_repeat('•', $termValidationDigits), ENT_QUOTES, 'UTF-8'); ?>"
                                required
                            >
                            <small class="field-help">Use apenas números. O documento completo não é exibido nesta etapa.</small>
                        </label>

                        <div class="term-actions">
                            <button type="submit" class="button">Liberar termo</button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="status-card status-card--warning">
                        <strong>Limite de tentativas atingido.</strong>
                        <small>Solicite um novo acesso ao atendimento para liberar a visualização do termo.</small>
                    </div>
                <?php endif; ?>
            </article>
        </section>
    <?php else: ?>
        <article class="document-page">
            <div class="document-sheet">
                <header class="document-header avoid-break">
                    <p class="document-kicker">TERMO DE ACEITE DIGITAL E CONTRATAÇÃO SCM</p>
                    <h1><?= htmlspecialchars($providerLabel, ENT_QUOTES, 'UTF-8'); ?></h1>
                    <div class="document-meta">
                        <div><span>Protocolo</span><strong><?= htmlspecialchars($protocol, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>Data / hora</span><strong><?= htmlspecialchars($acceptedAt !== '' ? $acceptedAt : '-', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>Status</span><strong><?= htmlspecialchars($status !== '' ? $status : 'aceito', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    </div>
                </header>

                <section class="document-section avoid-break">
                    <h2>Contratada</h2>
                    <div class="document-grid document-grid--legal">
                        <div><span>Razão Social</span><strong><?= htmlspecialchars($providerLegalName, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>CNPJ</span><strong><?= htmlspecialchars($providerCnpj, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div class="document-grid__span-2"><span>Endereço</span><strong><?= htmlspecialchars($providerAddressLine, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>Telefone/WhatsApp</span><strong><?= htmlspecialchars($providerPhone, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>Site</span><strong><?= htmlspecialchars($providerSite, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>E-mail</span><strong><?= htmlspecialchars($providerEmail, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>Autorização ANATEL (SCM)</span><strong><?= htmlspecialchars($providerAnatel, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div class="document-grid__span-2"><span>Central do Assinante</span><strong><a href="<?= htmlspecialchars($centralAssinanteUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?= htmlspecialchars($centralAssinanteUrl, ENT_QUOTES, 'UTF-8'); ?></a></strong></div>
                    </div>
                </section>

                <section class="document-section avoid-break">
                    <h2>Contratante</h2>
                    <div class="document-grid">
                        <div><span>Nome</span><strong><?= htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>CPF/CNPJ</span><strong><?= htmlspecialchars($maskedDocument !== '' ? $maskedDocument : '-', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>Telefone</span><strong><?= htmlspecialchars($clientPhone !== '' ? $clientPhone : '-', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>E-mail</span><strong><?= htmlspecialchars($clientEmail !== '' ? $clientEmail : '-', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    </div>
                </section>

                <section class="document-section avoid-break">
                    <h2>Endereço de instalação</h2>
                    <p class="document-paragraph"><?= htmlspecialchars($addressLine, ENT_QUOTES, 'UTF-8'); ?></p>
                </section>

                <section class="document-section avoid-break">
                    <h2>Plano contratado</h2>
                    <div class="document-grid">
                        <div><span>Plano</span><strong><?= htmlspecialchars($planName !== '' ? $planName : '-', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>Valor mensal</span><strong><?= htmlspecialchars($monthlyValueFormatted, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>Vencimento</span><strong><?= htmlspecialchars($dueDate !== '' ? $dueDate : '-', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>Tecnologia</span><strong><?= htmlspecialchars((string) ($planDetails['tecnologia'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    </div>
                </section>

                <section class="document-section avoid-break">
                    <h2>Adesão e condições comerciais</h2>
                    <div class="document-grid">
                        <div><span>Tipo de adesão</span><strong><?= htmlspecialchars($adherenceType !== '' ? $adherenceType : '-', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>Valor total</span><strong><?= htmlspecialchars($adherenceValue, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>Parcelas</span><strong><?= htmlspecialchars((string) $installments, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>Valor da parcela</span><strong><?= htmlspecialchars($installmentValue, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>Vencimento 1ª parcela</span><strong><?= htmlspecialchars($firstInstallmentDue !== '' ? $firstInstallmentDue : '-', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>Fidelidade</span><strong><?= htmlspecialchars((string) $fidelityMonths, ENT_QUOTES, 'UTF-8'); ?> meses</strong></div>
                        <div><span>Autorizado por</span><strong><?= htmlspecialchars($authorizedBy !== '' ? $authorizedBy : '-', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div class="document-grid__span-2"><span>Observação comercial</span><strong><?= htmlspecialchars($observations !== '' ? $observations : '-', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    </div>
                </section>

                <section class="document-section avoid-break">
                    <h2>Declaração de aceite eletrônico</h2>
                    <p class="document-paragraph">
                        Ao confirmar eletronicamente este aceite, o CLIENTE declara que revisou os dados cadastrais, plano contratado, valores, vencimento, condições de adesão, fidelidade quando aplicável e condições do serviço, concordando com o Contrato de Prestação de Serviço de Comunicação Multimídia (SCM) disponibilizado pela CONTRATADA.
                    </p>
                    <p class="document-paragraph">
                        Boletos, faturas, notas, segunda via e informações do contrato podem ser consultados na Central do Assinante:
                        <a href="<?= htmlspecialchars($centralAssinanteUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?= htmlspecialchars($centralAssinanteUrl, ENT_QUOTES, 'UTF-8'); ?></a>
                    </p>
                </section>

                <section class="document-section avoid-break">
                    <h2>Registro técnico do aceite</h2>
                    <div class="document-grid">
                        <div><span>Técnico responsável</span><strong><?= htmlspecialchars($technicianName !== '' ? $technicianName : 'Equipe técnica', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>Login do técnico</span><strong><?= htmlspecialchars($technicianLogin !== '' ? $technicianLogin : '-', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>IP</span><strong><?= htmlspecialchars($ipAddress !== '' ? $ipAddress : '-', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>Navegador / dispositivo</span><strong><?= htmlspecialchars($userAgent !== '' ? $userAgent : '-', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>Contrato ID</span><strong><?= htmlspecialchars($contractId > 0 ? (string) $contractId : '-', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>Aceite ID</span><strong><?= htmlspecialchars($acceptanceId > 0 ? (string) $acceptanceId : '-', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    </div>
                </section>

                <section class="document-section avoid-break">
                    <h2>Assinatura registrada</h2>
                    <?php if ($signatureUrl !== ''): ?>
                        <div class="signature-box">
                            <img src="<?= htmlspecialchars($signatureUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Assinatura registrada no aceite">
                            <small>Assinatura coletada no momento da instalação e vinculada ao aceite eletrônico.</small>
                        </div>
                    <?php else: ?>
                        <p class="document-paragraph">Assinatura não localizada para este aceite.</p>
                    <?php endif; ?>
                </section>
            </div>
        </article>
    <?php endif; ?>
</section>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
