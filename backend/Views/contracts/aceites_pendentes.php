<?php

declare(strict_types=1);

use App\Core\Url;

$acceptances = is_array($acceptances ?? null) ? $acceptances : [];
$filters = is_array($filters ?? null) ? $filters : [];
$financeiro = (string) ($filters['financeiro'] ?? '');
$aceite = (string) ($filters['aceite'] ?? '');
$adesao = (string) ($filters['adesao'] ?? '');

ob_start();
?>
<section class="page-header">
    <div>
        <p class="section-heading__eyebrow">Contratos &amp; Aceites</p>
        <h1>Aceites Pendentes</h1>
        <p class="page-description">Fila de aceites aguardando confirmação do cliente ou revisão administrativa.</p>
    </div>
</section>

<?php if (!empty($moduleMessage)): ?>
    <section class="card" style="margin-bottom: 20px;">
        <p class="page-description"><?= htmlspecialchars((string) $moduleMessage, ENT_QUOTES, 'UTF-8'); ?></p>
    </section>
<?php endif; ?>

<section class="card">
    <div class="section-heading">
        <p class="section-heading__eyebrow">Filtros</p>
        <h2>Filtrar aceites</h2>
    </div>

    <form class="form-grid form-grid--compact" method="get" action="<?= htmlspecialchars(Url::to('/contratos/aceites/pendentes'), ENT_QUOTES, 'UTF-8'); ?>">
        <label class="field">
            <span>Status financeiro</span>
            <select name="financeiro">
                <option value="">Todos</option>
                <option value="pendente_lancamento" <?= $financeiro === 'pendente_lancamento' ? 'selected' : ''; ?>>Pendente lançamento</option>
                <option value="lancado" <?= $financeiro === 'lancado' ? 'selected' : ''; ?>>Lançado</option>
                <option value="dispensado" <?= $financeiro === 'dispensado' ? 'selected' : ''; ?>>Dispensado</option>
            </select>
        </label>

        <label class="field">
            <span>Status de aceite</span>
            <select name="aceite">
                <option value="">Todos</option>
                <option value="criado" <?= $aceite === 'criado' ? 'selected' : ''; ?>>Criado</option>
                <option value="enviado" <?= $aceite === 'enviado' ? 'selected' : ''; ?>>Enviado</option>
                <option value="aceito" <?= $aceite === 'aceito' ? 'selected' : ''; ?>>Aceito</option>
                <option value="expirado" <?= $aceite === 'expirado' ? 'selected' : ''; ?>>Expirado</option>
                <option value="cancelado" <?= $aceite === 'cancelado' ? 'selected' : ''; ?>>Cancelado</option>
            </select>
        </label>

        <label class="field">
            <span>Tipo de adesão</span>
            <select name="adesao">
                <option value="">Todos</option>
                <option value="cheia" <?= $adesao === 'cheia' ? 'selected' : ''; ?>>Cheia</option>
                <option value="promocional" <?= $adesao === 'promocional' ? 'selected' : ''; ?>>Promocional</option>
                <option value="isenta" <?= $adesao === 'isenta' ? 'selected' : ''; ?>>Isenta</option>
            </select>
        </label>

        <div class="form-actions">
            <button class="button" type="submit">Aplicar filtros</button>
            <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/contratos/aceites/pendentes'), ENT_QUOTES, 'UTF-8'); ?>">Limpar</a>
        </div>
    </form>
</section>

<section class="card" style="margin-top: 20px;">
    <div class="section-heading">
        <p class="section-heading__eyebrow">Fila</p>
        <h2>Pendências recentes</h2>
    </div>

    <div class="hero-actions" style="margin-bottom: 18px;">
        <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/contratos'), ENT_QUOTES, 'UTF-8'); ?>">Voltar ao resumo</a>
        <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/contratos/novos'), ENT_QUOTES, 'UTF-8'); ?>">Ver novos contratos</a>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th>Login</th>
                    <th>Status</th>
                    <th>Financeiro</th>
                    <th>Tipo</th>
                    <th>Expira em</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($acceptances as $acceptance): ?>
                    <?php
                        $contractId = (int) ($acceptance['contract_id'] ?? 0);
                        $acceptanceId = (int) ($acceptance['acceptance_id'] ?? 0);
                        $tokenHash = trim((string) ($acceptance['token_hash'] ?? ''));
                        $linkToken = $tokenHash !== '' ? $tokenHash : (string) ($acceptanceId > 0 ? $acceptanceId : $contractId);
                        $simulatedLink = Url::to('/aceite/' . rawurlencode($linkToken));
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($acceptance['nome_cliente'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars((string) ($acceptance['mkauth_login'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><span class="pill pill--muted"><?= htmlspecialchars((string) ($acceptance['acceptance_status'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td><span class="pill"><?= htmlspecialchars((string) ($acceptance['status_financeiro'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td><?= htmlspecialchars((string) ($acceptance['tipo_adesao'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
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
                <?php if ($acceptances === []): ?>
                    <tr>
                        <td colspan="7">Nenhum aceite pendente encontrado neste momento.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
