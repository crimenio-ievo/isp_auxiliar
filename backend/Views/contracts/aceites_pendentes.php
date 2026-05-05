<?php

declare(strict_types=1);

use App\Core\Url;

$acceptances = is_array($acceptances ?? null) ? $acceptances : [];

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
                    <th>Telefone</th>
                    <th>Status</th>
                    <th>Token</th>
                    <th>Expira em</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($acceptances as $acceptance): ?>
                    <?php
                        $token = trim((string) ($acceptance['token_hash'] ?? ''));
                        $tokenLabel = $token !== '' ? substr($token, 0, 10) . '…' : '-';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($acceptance['nome_cliente'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars((string) ($acceptance['mkauth_login'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars((string) ($acceptance['telefone_enviado'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><span class="pill pill--muted"><?= htmlspecialchars((string) ($acceptance['status'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td><?= htmlspecialchars($tokenLabel, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars((string) ($acceptance['token_expires_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <button type="button" class="button button--ghost button--small" disabled>Abrir</button>
                            <button type="button" class="button button--ghost button--small" disabled>Reenviar</button>
                            <button type="button" class="button button--ghost button--small" disabled>Cancelar</button>
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
