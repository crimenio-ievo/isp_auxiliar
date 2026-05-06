<?php

declare(strict_types=1);

use App\Core\Url;

$detail = is_array($detail ?? null) ? $detail : [];
$contract = is_array($detail['contract'] ?? null) ? $detail['contract'] : [];
$acceptance = is_array($detail['acceptance'] ?? null) ? $detail['acceptance'] : [];
$financialTask = is_array($detail['financialTask'] ?? null) ? $detail['financialTask'] : [];
$notificationLogs = is_array($detail['notificationLogs'] ?? null) ? $detail['notificationLogs'] : [];
$auditLogs = is_array($detail['auditLogs'] ?? null) ? $detail['auditLogs'] : [];
$contractId = (int) ($contract['id'] ?? 0);
$canManageContracts = !empty($canManageContracts);
$simulatedAcceptanceLink = (string) ($simulatedAcceptanceLink ?? '');
$returnTo = Url::to('/contratos/detalhe?id=' . $contractId);

$formatMoney = static fn (mixed $value): string => number_format((float) $value, 2, ',', '.');
$formatDate = static fn (?string $value): string => trim((string) $value) !== '' ? (string) $value : '-';
$shortHash = static fn (string $value): string => $value !== '' ? substr($value, 0, 16) . '…' : '-';

ob_start();
?>
<section class="page-header">
    <div>
        <p class="section-heading__eyebrow">Contratos &amp; Aceites</p>
        <h1>Detalhe do Contrato</h1>
        <p class="page-description">Visão consolidada do contrato, aceite, pendência financeira e trilha de auditoria.</p>
    </div>
    <div class="hero-actions">
        <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/contratos/novos'), ENT_QUOTES, 'UTF-8'); ?>">Voltar aos contratos</a>
        <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/contratos/aceites/pendentes'), ENT_QUOTES, 'UTF-8'); ?>">Ver aceites pendentes</a>
    </div>
</section>

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
        <button type="button" class="button button--ghost" data-copy-text="<?= htmlspecialchars($simulatedAcceptanceLink, ENT_QUOTES, 'UTF-8'); ?>" data-copy-label="Copiar link futuro">Copiar link futuro</button>
        <a class="button button--ghost" href="#financeiro">Ver pendência financeira</a>
        <a class="button button--ghost" href="#logs">Ver logs relacionados</a>
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

            <?php if ($canManageContracts): ?>
                <div class="hero-actions" style="margin-top: 18px;">
                    <form method="post" action="<?= htmlspecialchars(Url::to('/contratos/financeiro/concluir'), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="contract_id" value="<?= htmlspecialchars((string) $contractId, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" class="button">Marcar como concluído</button>
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
                    <article class="log-list__item">
                        <strong><?= htmlspecialchars((string) ($log['status'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
                        <p><?= htmlspecialchars((string) ($log['channel'] ?? 'whatsapp'), ENT_QUOTES, 'UTF-8'); ?> · <?= htmlspecialchars((string) ($log['provider'] ?? 'evotrix'), ENT_QUOTES, 'UTF-8'); ?> · <?= htmlspecialchars((string) ($log['recipient'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></p>
                        <p><?= htmlspecialchars((string) ($log['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php if (!empty($log['provider_response'])): ?>
                            <p><strong>Resposta:</strong> <?= htmlspecialchars((string) ($log['provider_response'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>
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
