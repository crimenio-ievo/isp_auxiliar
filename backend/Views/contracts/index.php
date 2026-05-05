<?php

declare(strict_types=1);

use App\Core\Url;

$summaryCards = is_array($summaryCards ?? null) ? $summaryCards : [];
$recentContracts = is_array($recentContracts ?? null) ? $recentContracts : [];
$pendingAcceptances = is_array($pendingAcceptances ?? null) ? $pendingAcceptances : [];

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
                        <th>Tipo</th>
                        <th>Financeiro</th>
                        <th>Criado em</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentContracts as $contract): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($contract['nome_cliente'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars((string) ($contract['mkauth_login'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars((string) ($contract['tipo_adesao'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="pill"><?= htmlspecialchars((string) ($contract['status_financeiro'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td><?= htmlspecialchars((string) ($contract['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($recentContracts === []): ?>
                        <tr>
                            <td colspan="5">Nenhum contrato encontrado neste momento.</td>
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
                        <th>Expira em</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingAcceptances as $acceptance): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($acceptance['nome_cliente'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars((string) ($acceptance['mkauth_login'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="pill pill--muted"><?= htmlspecialchars((string) ($acceptance['status'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td><?= htmlspecialchars((string) ($acceptance['token_expires_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($pendingAcceptances === []): ?>
                        <tr>
                            <td colspan="4">Nenhum aceite pendente encontrado neste momento.</td>
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
