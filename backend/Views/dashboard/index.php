<?php

declare(strict_types=1);

use App\Core\Url;

$role = strtolower((string) ($user['role'] ?? ''));
$roleLabel = match ($role) {
    'platform_admin' => 'Administrador da plataforma',
    'manager' => 'Gestor do provedor',
    'technician' => 'Tecnico MkAuth',
    default => (string) ($user['role'] ?? 'Operacao'),
};

ob_start();
?>
<section class="hero-panel">
    <div class="hero-copy">
        <span class="pill">Operacao ISP</span>
        <h1>Dashboard de cadastro, instalações e histórico operacional.</h1>
        <p>
            Bem-vindo, <?= htmlspecialchars((string) $user['name'], ENT_QUOTES, 'UTF-8'); ?>.
            Acompanhe o cadastro de clientes, evidências coletadas e validação de conexão Radius.
        </p>

        <div class="hero-actions">
            <a class="button" href="<?= htmlspecialchars(Url::to('/clientes/novo'), ENT_QUOTES, 'UTF-8'); ?>">Novo Cliente</a>
            <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/instalacoes'), ENT_QUOTES, 'UTF-8'); ?>">Ver Instalacoes</a>
        </div>
    </div>

    <div class="hero-status">
        <article class="status-card">
            <span>Usuario atual</span>
            <strong><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
            <small><?= htmlspecialchars((string) ($user['login'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></small>
        </article>

        <article class="status-card">
            <span>Integração</span>
            <strong>MkAuth + evidências locais</strong>
            <small>Cadastro via API, validações por banco e armazenamento local de fotos e assinatura.</small>
        </article>
    </div>
</section>

<section class="stats-grid">
    <?php foreach ($stats as $stat): ?>
        <article class="card stat-card">
            <p class="stat-card__label"><?= htmlspecialchars((string) $stat['label'], ENT_QUOTES, 'UTF-8'); ?></p>
            <strong class="stat-card__value"><?= htmlspecialchars((string) $stat['value'], ENT_QUOTES, 'UTF-8'); ?></strong>
            <span class="stat-card__hint"><?= htmlspecialchars((string) $stat['hint'], ENT_QUOTES, 'UTF-8'); ?></span>
        </article>
    <?php endforeach; ?>
</section>

<section class="content-grid">
    <article class="card">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Atividades recentes</p>
            <h2>Fluxo do dia</h2>
        </div>

        <div class="timeline">
            <?php foreach ($recentActivities as $activity): ?>
                <div class="timeline__item">
                    <span class="timeline__time"><?= htmlspecialchars((string) $activity['time'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <div>
                        <strong><?= htmlspecialchars((string) $activity['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        <p><?= htmlspecialchars((string) $activity['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </article>

    <article class="card">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Fluxo operacional</p>
            <h2>Rotina recomendada</h2>
        </div>

        <ul class="check-list">
            <?php foreach ($pipeline as $item): ?>
                <li><?= htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8'); ?></li>
            <?php endforeach; ?>
        </ul>
    </article>
</section>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
