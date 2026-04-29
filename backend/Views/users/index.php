<?php

declare(strict_types=1);

ob_start();
?>
<section class="page-header">
    <div>
        <p class="section-heading__eyebrow">Acesso</p>
        <h1>Usuarios</h1>
        <p class="page-description">Usuários operacionais consultados no MkAuth para acesso ao ISP Auxiliar.</p>
    </div>
</section>

<section class="card">
    <div class="section-heading">
        <p class="section-heading__eyebrow">Equipe</p>
        <h2>Usuarios cadastrados</h2>
    </div>

    <p class="page-description">
        <?= !empty($usersSource) && $usersSource === 'mkauth'
            ? 'Listagem carregada diretamente do MkAuth.'
            : 'Configure a conexão com o MkAuth para listar usuários reais.'; ?>
    </p>

    <div class="user-list">
        <?php foreach ($users as $item): ?>
            <article class="user-list__item">
                <div>
                    <strong><?= htmlspecialchars((string) $item['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <p>
                        <?= htmlspecialchars((string) $item['role'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php if (!empty($item['login'])): ?>
                            · @<?= htmlspecialchars((string) $item['login'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </p>
                </div>
                <span class="pill"><?= htmlspecialchars((string) $item['status'], ENT_QUOTES, 'UTF-8'); ?></span>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
