<?php

declare(strict_types=1);

ob_start();
?>
<section class="page-header">
    <div>
        <p class="section-heading__eyebrow">Operacao</p>
        <h1>Instalacoes</h1>
        <p class="page-description">Acompanhamento dos cadastros que aguardam validação Radius ou já foram finalizados.</p>
    </div>
</section>

<section class="card">
    <div class="section-heading">
        <p class="section-heading__eyebrow">Cadastro de clientes</p>
        <h2>Validação de instalação</h2>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th>Login</th>
                    <th>Plano</th>
                    <th>Status</th>
                    <th>Atualizado em</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($installations as $installation): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $installation['client'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars((string) $installation['login'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars((string) $installation['plan'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><span class="pill pill--muted"><?= htmlspecialchars((string) $installation['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td><?= htmlspecialchars((string) $installation['date'], ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($installations === []): ?>
                    <tr>
                        <td colspan="5">Nenhuma instalação registrada.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
