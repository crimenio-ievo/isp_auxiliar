<?php

declare(strict_types=1);

use App\Core\Url;

$draft = is_array($draft ?? null) ? $draft : [];
$clientName = (string) ($draft['nome_completo'] ?? '');
$clientLogin = (string) ($draft['login'] ?? '');
$clientCpf = (string) ($draft['cpf_cnpj'] ?? '');
$clientCity = (string) ($draft['cidade'] ?? '');
$clientState = (string) ($draft['estado'] ?? '');
$clientPlan = (string) ($draft['plano'] ?? '');
$clientAddress = trim((string) ($draft['endereco'] ?? '') . ' ' . (string) ($draft['numero'] ?? ''));
$clientNeighborhood = (string) ($draft['bairro'] ?? '');
$clientCep = (string) ($draft['cep'] ?? '');
$acceptanceStamp = (string) ($acceptanceDateTime ?? date('d/m/Y H:i'));
$checkpointToken = (string) ($checkpointToken ?? '');

ob_start();
?>
<section class="page-header acceptance-header">
    <div>
        <p class="section-heading__eyebrow">Aceite do cliente</p>
        <h1>Confirmação e evidências da instalação</h1>
        <p class="page-description">Revise os dados abaixo, posicione o celular na horizontal se preferir e conclua a assinatura com fotos obrigatórias da instalação.</p>
    </div>
    <div class="acceptance-header__meta">
        <span class="pill">Rascunho salvo</span>
        <span class="pill pill--muted"><?= htmlspecialchars($acceptanceStamp, ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
</section>

<?php if (!empty($flash)): ?>
    <section class="alert <?= htmlspecialchars(($flash['type'] ?? 'success') === 'error' ? 'alert--error' : 'alert--success', ENT_QUOTES, 'UTF-8'); ?>">
        <?= htmlspecialchars((string) ($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
    </section>
<?php endif; ?>

<section class="acceptance-layout" data-acceptance-layout>
    <article class="card acceptance-summary">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Resumo</p>
            <h2>Dados do cadastro inicial</h2>
        </div>

        <div class="summary-grid">
            <div class="summary-item">
                <span>Cliente</span>
                <strong><?= htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div class="summary-item">
                <span>Login</span>
                <strong><?= htmlspecialchars($clientLogin, ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div class="summary-item">
                <span>CPF/CNPJ</span>
                <strong><?= htmlspecialchars($clientCpf, ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div class="summary-item">
                <span>Plano</span>
                <strong><?= htmlspecialchars($clientPlan, ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div class="summary-item">
                <span>CEP</span>
                <strong><?= htmlspecialchars($clientCep, ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div class="summary-item">
                <span>Cidade / UF</span>
                <strong><?= htmlspecialchars(trim($clientCity . ' / ' . $clientState), ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div class="summary-item summary-item--span-2">
                <span>Endereço</span>
                <strong><?= htmlspecialchars(trim($clientAddress . ' - ' . $clientNeighborhood), ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
        </div>

        <div class="acceptance-term">
            <p class="section-heading__eyebrow">Termo de aceite</p>
            <p>Declaro que recebi a instalação no endereço informado, que conferi os dados do cadastro acima e que autorizo a conclusão do registro com a evidência de assinatura e imagens da instalação já informadas no cadastro.</p>
            <p>Também declaro que as informações foram conferidas no momento do atendimento e que a operação fica registrada com data, hora, operador, IP e arquivos anexados para rastreabilidade.</p>
        </div>

    </article>

    <form
        class="card acceptance-form"
        method="post"
        action="<?= htmlspecialchars(Url::to('/clientes/novo/aceite'), ENT_QUOTES, 'UTF-8'); ?>"
        enctype="multipart/form-data"
        data-acceptance-form
        data-autosave-form
        data-draft-key="client-acceptance-<?= htmlspecialchars($draftId ?? '', ENT_QUOTES, 'UTF-8'); ?>"
        data-draft-json="<?= htmlspecialchars((string) ($draftJson ?? '{}'), ENT_QUOTES, 'UTF-8'); ?>"
    >
        <input type="hidden" name="draft_id" value="<?= htmlspecialchars($draftId ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="checkpoint_token" value="<?= htmlspecialchars($checkpointToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="assinatura_cliente" data-signature-input value="">

        <div class="section-heading">
            <p class="section-heading__eyebrow">Conferência</p>
            <h2>Assinatura de aceite</h2>
        </div>

        <div class="form-grid form-grid--compact">
            <div class="rotate-tip field--span-2">
                <strong>Se estiver no celular, vire para a horizontal.</strong>
                <span>A assinatura fica mais precisa e confortável nesse formato.</span>
            </div>

            <label class="field field--span-2">
                <span>Cliente confirma o aceite?</span>
                <div class="check-inline">
                    <input type="checkbox" name="aceite_cliente" value="sim" data-acceptance-select>
                    <span>Li e aceito o termo acima</span>
                </div>
                <small class="field-help">Esse registro confirma a concordância com os dados, instalação e evidências anexadas.</small>
            </label>

            <label class="field field--span-2">
                <span>Assinatura na tela</span>
                <div class="signature-pad" data-signature-pad>
                    <canvas class="signature-pad__canvas" data-signature-canvas width="960" height="300"></canvas>
                    <div class="signature-pad__actions">
                        <button class="button button--ghost" type="button" data-signature-clear>Limpar assinatura</button>
                        <small class="field-help">Desenhe com toque, mouse ou caneta. A assinatura será salva junto com o aceite.</small>
                    </div>
                    <small class="field-help" data-signature-help>Quando o aceite for marcado, a assinatura se torna obrigatória.</small>
                </div>
            </label>

            <label class="field field--span-2">
                <span>Observação da instalação</span>
                <textarea rows="4" name="observacao_aceite" placeholder="Detalhes do aceite, condição do local, pontos de observação e conferência final."></textarea>
            </label>

            <div class="form-actions field--span-2">
                <button class="button" type="submit">Finalizar cadastro e enviar ao MkAuth</button>
                <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/clientes/novo'), ENT_QUOTES, 'UTF-8'); ?>">Voltar ao cadastro</a>
            </div>
        </div>
    </form>
</section>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
