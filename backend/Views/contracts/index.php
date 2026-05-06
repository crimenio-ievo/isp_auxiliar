<?php

declare(strict_types=1);

use App\Core\Url;

$summaryCards = is_array($summaryCards ?? null) ? $summaryCards : [];
$recentContracts = is_array($recentContracts ?? null) ? $recentContracts : [];
$pendingAcceptances = is_array($pendingAcceptances ?? null) ? $pendingAcceptances : [];
$canManageContracts = !empty($canManageContracts);

ob_start();
?>
<section class="page-header">
    <div>
        <p class="section-heading__eyebrow">Operação</p>
        <h1>Contratos &amp; Aceites</h1>
        <p class="page-description">Painel inicial do módulo de contratos, aceite digital e pendências vinculadas ao atendimento.</p>
    </div>
</section>

<?php if (!empty($moduleMessage)): ?>
    <section class="card" style="margin-bottom: 20px;">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Estrutura</p>
            <h2>Módulo em preparação</h2>
        </div>
        <p class="page-description"><?= htmlspecialchars((string) $moduleMessage, ENT_QUOTES, 'UTF-8'); ?></p>
    </section>
<?php endif; ?>

<section class="stats-grid">
    <?php foreach ($summaryCards as $card): ?>
        <article class="card stat-card">
            <p class="stat-card__label"><?= htmlspecialchars((string) ($card['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
            <strong class="stat-card__value"><?= htmlspecialchars((string) ($card['value'] ?? '0'), ENT_QUOTES, 'UTF-8'); ?></strong>
            <span class="stat-card__hint"><?= htmlspecialchars((string) ($card['hint'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
        </article>
    <?php endforeach; ?>
</section>

<section class="content-grid" style="margin-top: 20px;">
    <article class="card">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Atalhos</p>
            <h2>Acesso rápido</h2>
        </div>

        <div class="hero-actions">
            <a class="button" href="<?= htmlspecialchars(Url::to('/contratos/novos'), ENT_QUOTES, 'UTF-8'); ?>">Novos Contratos</a>
            <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/contratos/aceites/pendentes'), ENT_QUOTES, 'UTF-8'); ?>">Aceites Pendentes</a>
        </div>
    </article>

    <article class="card">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Status</p>
            <h2>Resumo da operação</h2>
        </div>

        <ul class="check-list">
            <li>Contratos e aceites ficam centralizados neste módulo.</li>
            <li>O aceite público ainda não foi exposto nesta fase.</li>
            <li>Os botões desta etapa são apenas visuais e de navegação.</li>
        </ul>
    </article>
</section>

<section class="content-grid" style="margin-top: 20px;">
    <article class="card">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Novos contratos</p>
            <h2>Últimos registros</h2>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Login</th>
                        <th>Adesão</th>
                        <th>Financeiro</th>
                        <th>Aceite</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentContracts as $contract): ?>
                        <?php
                            $contractId = (int) ($contract['id'] ?? 0);
                            $acceptanceId = (int) ($contract['acceptance_id'] ?? 0);
                            $simulatedLink = Url::to('/aceite/' . rawurlencode((string) ($acceptanceId > 0 ? $acceptanceId : $contractId)));
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($contract['nome_cliente'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars((string) ($contract['mkauth_login'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars((string) ($contract['tipo_adesao'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="pill"><?= htmlspecialchars((string) ($contract['status_financeiro'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td><span class="pill pill--muted"><?= htmlspecialchars((string) ($contract['acceptance_status'] ?? 'criado'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td>
                                <div class="inline-actions">
                                    <a class="button button--ghost button--small" href="<?= htmlspecialchars(Url::to('/contratos/detalhe?id=' . $contractId), ENT_QUOTES, 'UTF-8'); ?>">Visualizar contrato</a>
                                    <button type="button" class="button button--ghost button--small" data-copy-text="<?= htmlspecialchars($simulatedLink, ENT_QUOTES, 'UTF-8'); ?>" data-copy-label="Copiar link futuro">Copiar link</button>
                                    <a class="button button--ghost button--small" href="<?= htmlspecialchars(Url::to('/contratos/detalhe?id=' . $contractId . '#financeiro'), ENT_QUOTES, 'UTF-8'); ?>">Ver pendência financeira</a>
                                    <a class="button button--ghost button--small" href="<?= htmlspecialchars(Url::to('/contratos/detalhe?id=' . $contractId . '#logs'), ENT_QUOTES, 'UTF-8'); ?>">Ver logs relacionados</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($recentContracts === []): ?>
                        <tr>
                            <td colspan="6">Nenhum contrato encontrado neste momento.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="card">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Aceites</p>
            <h2>Pendências recentes</h2>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Login</th>
                        <th>Status</th>
                        <th>Financeiro</th>
                        <th>Expira em</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingAcceptances as $acceptance): ?>
                        <?php
                            $contractId = (int) ($acceptance['contract_id'] ?? 0);
                            $acceptanceId = (int) ($acceptance['acceptance_id'] ?? 0);
                            $simulatedLink = Url::to('/aceite/' . rawurlencode((string) ($acceptanceId > 0 ? $acceptanceId : $contractId)));
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($acceptance['nome_cliente'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars((string) ($acceptance['mkauth_login'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="pill pill--muted"><?= htmlspecialchars((string) ($acceptance['acceptance_status'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td><span class="pill"><?= htmlspecialchars((string) ($acceptance['status_financeiro'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td><?= htmlspecialchars((string) ($acceptance['token_expires_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <div class="inline-actions">
                                    <a class="button button--ghost button--small" href="<?= htmlspecialchars(Url::to('/contratos/detalhe?id=' . $contractId), ENT_QUOTES, 'UTF-8'); ?>">Visualizar contrato</a>
                                    <button type="button" class="button button--ghost button--small" data-copy-text="<?= htmlspecialchars($simulatedLink, ENT_QUOTES, 'UTF-8'); ?>" data-copy-label="Copiar link futuro">Copiar link</button>
                                    <a class="button button--ghost button--small" href="<?= htmlspecialchars(Url::to('/contratos/detalhe?id=' . $contractId . '#financeiro'), ENT_QUOTES, 'UTF-8'); ?>">Ver pendência financeira</a>
                                    <a class="button button--ghost button--small" href="<?= htmlspecialchars(Url::to('/contratos/detalhe?id=' . $contractId . '#logs'), ENT_QUOTES, 'UTF-8'); ?>">Ver logs relacionados</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($pendingAcceptances === []): ?>
                        <tr>
                            <td colspan="6">Nenhum aceite pendente encontrado neste momento.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
