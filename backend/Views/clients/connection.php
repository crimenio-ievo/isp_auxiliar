<?php

declare(strict_types=1);

use App\Core\Url;

$record = is_array($record ?? null) ? $record : [];
$connection = is_array($connection ?? null) ? $connection : [];
$session = is_array($connection['session'] ?? null) ? $connection['session'] : [];
$lastAuth = is_array($connection['last_auth'] ?? null) ? $connection['last_auth'] : [];
$acceptanceStatus = is_array($acceptanceStatus ?? null) ? $acceptanceStatus : [];
$online = (bool) ($connection['online'] ?? false);
$completed = (string) ($record['status'] ?? '') === 'completed';
$acceptanceAccepted = !empty($acceptanceStatus['accepted']);
$acceptanceLabel = (string) ($acceptanceStatus['label'] ?? 'aceite pendente');
$connectionHeading = $completed
    ? 'Instalação finalizada'
    : ($online ? 'Conexão confirmada' : 'Aguardando conexão Radius');
$connectionDescription = $completed
    ? 'O cadastro foi concluído, o aceite está registrado e o login já apareceu conectado no Radius.'
    : ($acceptanceAccepted
        ? 'O aceite do cliente já foi concluído. Falta apenas o login aparecer conectado no Radius.'
        : 'Configure o equipamento, acompanhe o status do aceite e finalize somente quando o Radius confirmar conexão ativa.');

ob_start();
?>
<section class="page-header">
    <div>
        <p class="section-heading__eyebrow">Instalação</p>
        <h1><?= htmlspecialchars($connectionHeading, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="page-description"><?= htmlspecialchars($connectionDescription, ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
</section>

<?php if (!empty($flash)): ?>
    <section class="alert <?= htmlspecialchars(($flash['type'] ?? 'success') === 'error' ? 'alert--error' : 'alert--success', ENT_QUOTES, 'UTF-8'); ?>">
        <?= htmlspecialchars((string) ($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
    </section>
<?php endif; ?>

<section class="content-grid connection-screen">
    <article class="card">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Próximo passo</p>
            <h2><?= $completed ? 'Tudo concluído' : 'Configurar equipamento do cliente'; ?></h2>
        </div>

        <div class="status-card <?= $completed ? 'status-card--success' : ($acceptanceAccepted ? 'status-card--success' : 'status-card--warning'); ?> connection-state-card">
            <span><?= $completed ? 'Fluxo concluído' : ($acceptanceAccepted ? 'Aceite já concluído' : 'Aceite ainda pendente'); ?></span>
            <strong><?= $completed ? 'Instalação e validação finalizadas.' : ($acceptanceAccepted ? 'Falta apenas a conexão ativa no Radius.' : 'O cliente ainda precisa concluir o aceite para liberar a finalização padrão.'); ?></strong>
            <small>Status do aceite: <?= htmlspecialchars($acceptanceLabel, ENT_QUOTES, 'UTF-8'); ?>.</small>
        </div>

        <div class="connection-login">
            <span>Login PPPoE</span>
            <strong><?= htmlspecialchars((string) ($record['login'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>

        <div class="summary-grid">
            <div class="summary-item">
                <span>Cliente</span>
                <strong><?= htmlspecialchars((string) ($record['client_name'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div class="summary-item">
                <span>Plano</span>
                <strong><?= htmlspecialchars((string) ($record['plan'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div class="summary-item">
                <span>Status</span>
                <strong><?= $completed ? 'Instalação finalizada' : 'Aguardando conexão'; ?></strong>
            </div>
            <div class="summary-item">
                <span>Aceite</span>
                <strong><?= htmlspecialchars($acceptanceLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div class="summary-item">
                <span>Evidências</span>
                <strong><?= htmlspecialchars((string) ($record['evidence_ref'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
        </div>

        <?php if (!$completed): ?>
            <form method="post" action="<?= htmlspecialchars(Url::to('/clientes/conexao/finalizar'), ENT_QUOTES, 'UTF-8'); ?>" class="form-actions">
                <input type="hidden" name="token" value="<?= htmlspecialchars((string) $token, ENT_QUOTES, 'UTF-8'); ?>">
                <button class="button" type="submit">Verificar conexão e finalizar</button>
                <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/clientes/novo'), ENT_QUOTES, 'UTF-8'); ?>">Cadastrar outro cliente</a>
            </form>
        <?php else: ?>
            <div class="form-actions">
                <a class="button" href="<?= htmlspecialchars(Url::to('/clientes/novo'), ENT_QUOTES, 'UTF-8'); ?>">Cadastrar outro cliente</a>
                <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/instalacoes'), ENT_QUOTES, 'UTF-8'); ?>">Ver instalações</a>
            </div>
        <?php endif; ?>
    </article>

    <aside class="card soft-card">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Radius</p>
            <h2><?= $online ? 'Conectado agora' : 'Ainda não conectado'; ?></h2>
        </div>

        <?php if ($online): ?>
            <div class="status-card status-card--success">
                <span>Sessão ativa encontrada</span>
                <strong><?= htmlspecialchars((string) ($session['framedipaddress'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
                <small>NAS: <?= htmlspecialchars((string) ($session['nasipaddress'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?> · Início: <?= htmlspecialchars((string) ($session['acctstarttime'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></small>
            </div>
        <?php else: ?>
            <div class="status-card status-card--warning">
                <span>Nenhuma sessão ativa em radacct</span>
                <strong>Configure o equipamento e tente novamente</strong>
                <small><?= htmlspecialchars((string) ($connection['message'] ?? 'A consulta procura sessão sem acctstoptime para este login.'), ENT_QUOTES, 'UTF-8'); ?></small>
            </div>
        <?php endif; ?>

        <?php if ($lastAuth !== []): ?>
            <div class="status-card">
                <span>Última autenticação registrada</span>
                <strong><?= htmlspecialchars((string) ($lastAuth['reply'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
                <small><?= htmlspecialchars((string) ($lastAuth['authdate'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?> · IP: <?= htmlspecialchars((string) ($lastAuth['ip'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></small>
            </div>
        <?php endif; ?>
    </aside>
</section>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
