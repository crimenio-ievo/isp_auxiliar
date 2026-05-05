<?php

declare(strict_types=1);

use App\Core\Url;

$contracts = is_array($contracts ?? null) ? $contracts : [];

ob_start();
?>
<section class="page-header">
    <div>
        <p class="section-heading__eyebrow">Contratos &amp; Aceites</p>
        <h1>Novos Contratos</h1>
        <p class="page-description">Visualização inicial dos contratos cadastrados para acompanhamento operacional e financeiro.</p>
    </div>
</section>

<?php if (!empty($moduleMessage)): ?>
    <section class="card" style="margin-bottom: 20px;">
        <p class="page-description"><?= htmlspecialchars((string) $moduleMessage, ENT_QUOTES, 'UTF-8'); ?></p>
    </section>
<?php endif; ?>

<section class="card">
    <div class="section-heading">
        <p class="section-heading__eyebrow">Lista</p>
        <h2>Contratos recentes</h2>
    </div>

    <div class="hero-actions" style="margin-bottom: 18px;">
        <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/contratos'), ENT_QUOTES, 'UTF-8'); ?>">Voltar ao resumo</a>
        <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/contratos/aceites/pendentes'), ENT_QUOTES, 'UTF-8'); ?>">Ver aceites pendentes</a>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th>Login</th>
                    <th>Adesão</th>
                    <th>Parcelas</th>
                    <th>Valor</th>
                    <th>Financeiro</th>
                    <th>Criado em</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($contracts as $contract): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($contract['nome_cliente'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars((string) ($contract['mkauth_login'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars((string) ($contract['tipo_adesao'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars((string) ($contract['parcelas_adesao'] ?? '1'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars((string) ($contract['valor_adesao'] ?? '0.00'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><span class="pill"><?= htmlspecialchars((string) ($contract['status_financeiro'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td><?= htmlspecialchars((string) ($contract['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <button type="button" class="button button--ghost button--small" disabled>Visualizar</button>
                            <button type="button" class="button button--ghost button--small" disabled>Gerar aceite</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($contracts === []): ?>
                    <tr>
                        <td colspan="8">Nenhum contrato encontrado neste momento.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
