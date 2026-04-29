<?php

declare(strict_types=1);

$title = 'Estrutura inicial';

ob_start();
?>
<section class="card">
    <h2>Projeto pronto para a primeira fase</h2>
    <p>
        O esqueleto inicial foi preparado para manter interface, backend, infraestrutura e banco em camadas separadas.
    </p>
</section>

<section class="grid">
    <article class="card">
        <h3>Ambiente</h3>
        <p><?= htmlspecialchars((string) $environment, ENT_QUOTES, 'UTF-8'); ?></p>
    </article>

    <article class="card">
        <h3>Rota atual</h3>
        <p><?= htmlspecialchars((string) $requestPath, ENT_QUOTES, 'UTF-8'); ?></p>
    </article>

    <article class="card">
        <h3>Proxima etapa</h3>
        <p>Adicionar persistencia, validacao e modulos de negocio de forma incremental.</p>
    </article>
</section>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
