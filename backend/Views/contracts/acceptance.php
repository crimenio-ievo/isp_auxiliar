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
$signaturePath = trim((string) ($publicDetails['assinatura_path'] ?? ''));
$signatureRef = '';
if ($signaturePath !== '') {
    $signatureRef = basename(dirname($signaturePath));
}
$documentValidationRequired = (bool) ($documentValidationRequired ?? ($context['documentValidationRequired'] ?? false));
$documentValidationDigits = max(1, (int) ($documentValidationDigits ?? ($context['documentValidationDigits'] ?? 3)));
$documentValidationPossible = (bool) ($documentValidationPossible ?? ($context['documentValidationPossible'] ?? false));
$termBody = (string) ($context['termBody'] ?? '');
$maskedDocument = (string) ($context['maskedDocument'] ?? '-');
$status = (string) ($acceptance['status'] ?? '');
$isAccepted = $status === 'aceito';
$isExpired = str_contains((string) ($context['error'] ?? ''), 'expirou');
$hasError = !empty($context['error']) || !empty($errorMessage);
$formatMoney = static fn (mixed $value): string => number_format((float) $value, 2, ',', '.');
$formatDate = static fn (?string $value): string => trim((string) $value) !== '' ? (string) $value : '-';

ob_start();
?>
<section class="acceptance-layout">
    <article class="acceptance-summary">
        <div class="card acceptance-header">
            <p class="section-heading__eyebrow">Aceite digital</p>
            <h1>Termo de contrato</h1>
            <p class="page-description">Revise os dados abaixo e confirme o aceite eletrônico do contrato.</p>

            <?php if (!empty($successMessage)): ?>
                <div class="status-card status-card--success" style="margin-top: 16px;">
                    <strong><?= htmlspecialchars((string) $successMessage, ENT_QUOTES, 'UTF-8'); ?></strong>
                    <small>O aceite foi concluído ou já está registrado como finalizado.</small>
                </div>
            <?php elseif ($isAccepted): ?>
                <div class="status-card status-card--success" style="margin-top: 16px;">
                    <strong>Este aceite já foi concluído.</strong>
                    <small>O link permanece registrado, mas não pode ser reutilizado.</small>
                </div>
            <?php elseif ($isExpired): ?>
                <div class="status-card status-card--warning" style="margin-top: 16px;">
                    <strong>Este link expirou e não pode ser concluído.</strong>
                    <small>Solicite um novo envio ao atendimento.</small>
                </div>
            <?php elseif (!empty($errorMessage)): ?>
                <div class="status-card status-card--warning" style="margin-top: 16px;">
                    <strong><?= htmlspecialchars((string) $errorMessage, ENT_QUOTES, 'UTF-8'); ?></strong>
                    <small>Se necessário, solicite um novo link de aceite ao atendimento.</small>
                </div>
            <?php elseif ($hasError): ?>
                <div class="status-card status-card--warning" style="margin-top: 16px;">
                    <strong><?= htmlspecialchars((string) ($context['error'] ?? 'Não foi possível validar o aceite.'), ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="section-heading">
                <p class="section-heading__eyebrow">Dados do contrato</p>
                <h2>Resumo conferido</h2>
            </div>

            <div class="summary-grid">
                <div class="summary-item"><span>Cliente</span><strong><?= htmlspecialchars((string) ($customerDetails['nome'] ?? $contract['nome_cliente'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="summary-item"><span>Login</span><strong><?= htmlspecialchars((string) ($customerDetails['login'] ?? $contract['mkauth_login'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="summary-item"><span>CPF/CNPJ</span><strong><?= htmlspecialchars((string) ($customerDetails['cpf_cnpj'] ?? $maskedDocument), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="summary-item"><span>Telefone</span><strong><?= htmlspecialchars((string) ($customerDetails['telefone'] ?? $contract['telefone_cliente'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="summary-item summary-item--span-2"><span>Endereço de instalação</span><strong><?= htmlspecialchars(trim((string) ($installationDetails['endereco'] ?? '') . ' ' . (string) ($installationDetails['bairro'] ?? '') . ' ' . (string) ($installationDetails['cidade'] ?? '') . ' / ' . (string) ($installationDetails['estado'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="summary-item"><span>CEP</span><strong><?= htmlspecialchars((string) ($installationDetails['cep'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="summary-item"><span>Coordenadas</span><strong><?= htmlspecialchars((string) ($installationDetails['coordenadas'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="summary-item"><span>Plano contratado</span><strong><?= htmlspecialchars((string) ($planDetails['nome'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="summary-item"><span>Valor mensal</span><strong><?= htmlspecialchars(($planDetails['valor_mensal'] ?? null) !== null ? 'R$ ' . $formatMoney($planDetails['valor_mensal']) : '-', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="summary-item"><span>Vencimento</span><strong><?= htmlspecialchars((string) ($installationDetails['vencimento'] ?? $contract['vencimento_primeira_parcela'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="summary-item"><span>Tipo de adesão</span><strong><?= htmlspecialchars((string) ($contractDetails['tipo_adesao'] ?? $contract['tipo_adesao'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="summary-item"><span>Valor da adesão</span><strong><?= htmlspecialchars('R$ ' . $formatMoney($contractDetails['valor_adesao'] ?? ($contract['valor_adesao'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="summary-item"><span>Parcelas</span><strong><?= htmlspecialchars((string) ($contractDetails['parcelas_adesao'] ?? $contract['parcelas_adesao'] ?? 1), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="summary-item"><span>Valor parcela</span><strong><?= htmlspecialchars('R$ ' . $formatMoney($contractDetails['valor_parcela_adesao'] ?? ($contract['valor_parcela_adesao'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="summary-item"><span>Vencimento 1ª parcela</span><strong><?= htmlspecialchars($formatDate((string) ($contractDetails['vencimento_primeira_parcela'] ?? $contract['vencimento_primeira_parcela'] ?? null)), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="summary-item"><span>Fidelidade</span><strong><?= htmlspecialchars((string) ($contractDetails['fidelidade_meses'] ?? $contract['fidelidade_meses'] ?? 12), ENT_QUOTES, 'UTF-8'); ?> meses</strong></div>
                <div class="summary-item"><span>Autorizado por</span><strong><?= htmlspecialchars((string) ($contractDetails['beneficio_concedido_por'] ?? $contract['beneficio_concedido_por'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="summary-item summary-item--span-2"><span>Equipamentos / comodato</span><strong><?= htmlspecialchars((string) ($contractDetails['equipamentos_comodato'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="summary-item summary-item--span-2"><span>Observações</span><strong><?= htmlspecialchars(trim((string) ($contractDetails['observacao_adesao'] ?? '-') . ' · ' . (string) ($installationDetails['observacao'] ?? '-')), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="summary-item summary-item--span-2"><span>Versão do termo</span><strong><?= htmlspecialchars((string) ($publicDetails['termo_versao'] ?? ($acceptance['termo_versao'] ?? '2026.1')), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            </div>
        </div>
    </article>

    <aside class="acceptance-form">
        <div class="card">
            <div class="section-heading">
                <p class="section-heading__eyebrow">Termo</p>
                <h2>Leia antes de confirmar</h2>
            </div>

            <div class="rotate-tip" style="margin-bottom: 16px;">
                <strong>Pode virar o celular para facilitar a leitura.</strong>
                <span>O aceite continua registrado mesmo em modo paisagem.</span>
            </div>

            <div class="acceptance-term">
                <p><strong>Versão do termo:</strong> <?= htmlspecialchars((string) ($acceptance['termo_versao'] ?? '2026.1'), ENT_QUOTES, 'UTF-8'); ?></p>
                <p><strong>Validade:</strong> link de uso único com expiração controlada.</p>
                <p><?= nl2br(htmlspecialchars($termBody !== '' ? $termBody : 'Termo indisponível.', ENT_QUOTES, 'UTF-8')); ?></p>
            </div>

            <?php if ($signaturePath !== '' && $signatureRef !== ''): ?>
                <div class="contract-signature-preview" style="margin-top: 18px;">
                    <p class="section-heading__eyebrow">Assinatura registrada</p>
                    <img
                        src="<?= htmlspecialchars(Url::to('/clientes/evidencias/arquivo?ref=' . rawurlencode($signatureRef) . '&file=assinatura.png'), ENT_QUOTES, 'UTF-8'); ?>"
                        alt="Assinatura já registrada na instalação"
                    >
                    <small class="field-help">Esta assinatura foi coletada no momento da instalação e será registrada como evidência do aceite.</small>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="section-heading">
                <p class="section-heading__eyebrow">Confirmação</p>
                <h2>Finalizar aceite</h2>
            </div>

            <?php if ($isAccepted): ?>
                <p class="page-description">Este termo já foi aceito e não pode ser reutilizado.</p>
            <?php elseif ($isExpired): ?>
                <p class="page-description">Este link expirou e não pode mais ser concluído.</p>
            <?php elseif ($hasError): ?>
                <p class="page-description">O aceite não está disponível neste momento.</p>
            <?php else: ?>
                <form
                    method="post"
                    action="<?= htmlspecialchars(Url::to('/aceite/' . rawurlencode((string) $token) . '/confirmar'), ENT_QUOTES, 'UTF-8'); ?>"
                    data-acceptance-form
                    data-document-validation-required="<?= $documentValidationRequired ? '1' : '0'; ?>"
                    data-document-validation-digits="<?= htmlspecialchars((string) $documentValidationDigits, ENT_QUOTES, 'UTF-8'); ?>"
                >
                    <p class="page-description">Ao confirmar, você concorda com os dados e condições exibidos acima.</p>

                    <label class="field">
                        <span>Cliente confirma o aceite?</span>
                        <div class="check-inline">
                            <input type="checkbox" name="aceite_cliente" value="sim" data-acceptance-select required>
                            <span>Li e aceito o termo acima</span>
                        </div>
                        <small class="field-help">Esse registro confirma a concordância com os dados, instalação e evidências anexadas.</small>
                    </label>

                    <?php if ($documentValidationRequired && $documentValidationPossible): ?>
                        <label class="field">
                            <span>Confirme os primeiros <?= htmlspecialchars((string) $documentValidationDigits, ENT_QUOTES, 'UTF-8'); ?> dígitos do CPF/CNPJ</span>
                            <input type="password" name="document_prefix" inputmode="numeric" maxlength="<?= htmlspecialchars((string) $documentValidationDigits, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off" data-acceptance-document-input required>
                            <small class="field-help" data-acceptance-document-help>Digite apenas os primeiros dígitos para confirmar a identidade documentada.</small>
                        </label>
                    <?php elseif ($documentValidationRequired && !$documentValidationPossible): ?>
                        <div class="rotate-tip" style="margin-bottom: 16px;">
                            <strong>CPF/CNPJ não disponível para validação parcial.</strong>
                            <span>O aceite ainda poderá ser concluído, mas a evidência registrará essa limitação.</span>
                        </div>
                    <?php endif; ?>

                    <div class="rotate-tip" style="margin-top: 12px;">
                        <strong>Assinatura já registrada na instalação.</strong>
                        <span>O aceite público usa a assinatura existente, sem solicitar novo desenho.</span>
                    </div>

                    <button type="submit" class="button button--full">ACEITO OS TERMOS</button>
                </form>
            <?php endif; ?>
        </div>
    </aside>
</section>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
