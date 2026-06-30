<?php

declare(strict_types=1);

use App\Core\Url;

$query = trim((string) ($query ?? ''));
$results = is_array($results ?? null) ? $results : [];
$recentRegistrations = is_array($recentRegistrations ?? null) ? $recentRegistrations : [];
$canCreateClient = !empty($canCreateClient);
$canSearchClients = !empty($canSearchClients);
$searchMode = (string) ($searchMode ?? 'none');
$searchModeLabel = match ($searchMode) {
    'document' => 'CPF/CNPJ',
    'phone_or_document' => 'Telefone / documento',
    'name' => 'Nome / login',
    'login' => 'Login',
    default => 'Busca livre',
};

ob_start();
?>
<section class="page-header">
    <div>
        <p class="section-heading__eyebrow">Clientes</p>
        <h1>Clientes</h1>
        <p class="page-description">Busque clientes no MkAuth em modo somente leitura e abra um resumo operacional com dados completos, contratos, aceite e histórico local.</p>
    </div>
    <div class="hero-actions">
        <?php if ($canCreateClient): ?>
            <a class="button" href="<?= htmlspecialchars(Url::to('/clientes/novo'), ENT_QUOTES, 'UTF-8'); ?>">Novo cliente</a>
        <?php endif; ?>
        <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/contratos/aceites/pendentes'), ENT_QUOTES, 'UTF-8'); ?>">Aceites pendentes</a>
    </div>
</section>

<?php if (!empty($flash)): ?>
    <section class="alert alert--<?= htmlspecialchars((string) ($flash['type'] ?? 'success'), ENT_QUOTES, 'UTF-8'); ?>" style="margin-bottom: 20px;">
        <?= htmlspecialchars((string) ($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
    </section>
<?php endif; ?>

<section class="card">
    <div class="section-heading">
        <p class="section-heading__eyebrow">Busca MkAuth</p>
        <h2>Pesquisar cliente</h2>
    </div>

    <form method="get" action="<?= htmlspecialchars(Url::to('/clientes'), ENT_QUOTES, 'UTF-8'); ?>" class="form-grid" data-client-search-form>
        <label class="field field--span-2">
            <span>Nome, login, CPF/CNPJ ou telefone</span>
            <input type="search" name="q" value="<?= htmlspecialchars($query, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Ex.: joao, cliente_01, 123.456.789-00" autocomplete="off" data-client-search-input>
            <small class="field-help">A busca é automática. Aceita nome, login, CPF, telefone, e-mail, endereco, contrato, ONU, MAC, bairro, cidade e plano.</small>
        </label>
        <div class="form-actions field--span-2">
            <button class="button" type="submit">Buscar</button>
            <span class="muted">Modo detectado: <?= htmlspecialchars($searchModeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    </form>

    <div class="client-search-panel" data-client-search-panel hidden>
        <div class="client-search-panel__status" data-client-search-status>Digite ao menos 3 caracteres para pesquisar clientes.</div>
        <div class="client-search-panel__results" data-client-search-results></div>
    </div>

    <?php if (!$canSearchClients): ?>
        <div class="alert alert--info" style="margin-top: 16px;">
            Seu acesso atual nao traz permissao explicita de busca ampla. A pagina continua disponivel para navegacao operacional.
        </div>
    <?php endif; ?>
</section>

<?php if ($query !== '' || $results !== []): ?>
    <section class="card">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Resultados</p>
            <h2>Clientes encontrados</h2>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Login</th>
                        <th>Documento</th>
                        <th>Telefone</th>
                        <th>Plano</th>
                        <th>Status</th>
                        <th>Bairro / Cidade</th>
                        <th>Ação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $result): ?>
                        <tr>
                            <td data-label="Cliente"><?= htmlspecialchars((string) ($result['name'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td data-label="Login"><?= htmlspecialchars((string) ($result['login'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td data-label="Documento"><?= htmlspecialchars((string) ($result['document'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td data-label="Telefone"><?= htmlspecialchars((string) ($result['phone'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td data-label="Plano"><?= htmlspecialchars((string) ($result['plan'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td data-label="Status"><span class="pill"><?= htmlspecialchars((string) ($result['status'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td data-label="Bairro / Cidade">
                                <?php
                                $locationParts = array_values(array_filter([
                                    trim((string) ($result['neighborhood'] ?? '')),
                                    trim((string) ($result['city'] ?? '')),
                                ], static fn (string $value): bool => $value !== ''));
                                ?>
                                <?= htmlspecialchars($locationParts !== [] ? implode(' / ', $locationParts) : '-', ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td data-label="Ação">
                                <a class="button button--ghost button--small" href="<?= htmlspecialchars((string) ($result['detail_url'] ?? '#'), ENT_QUOTES, 'UTF-8'); ?>">Ver cliente</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($results === []): ?>
                        <tr>
                            <td colspan="8">Nenhum cliente encontrado. Tente outro nome, login, documento ou telefone.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<section class="card">
    <div class="section-heading">
        <p class="section-heading__eyebrow">Historico local</p>
        <h2>Clientes movimentados recentemente</h2>
    </div>

    <?php if ($recentRegistrations !== []): ?>
        <div class="log-list">
            <?php foreach ($recentRegistrations as $recent): ?>
                <article class="log-list__item">
                    <div class="log-list__meta">
                        <span class="pill pill--muted"><?= htmlspecialchars((string) ($recent['status'] ?? 'registrado'), ENT_QUOTES, 'UTF-8'); ?></span>
                        <time><?= htmlspecialchars((string) ($recent['updated_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></time>
                    </div>
                    <p>
                        <strong><?= htmlspecialchars((string) ($recent['name'] ?? 'Cliente'), ENT_QUOTES, 'UTF-8'); ?></strong>
                        · Login <?= htmlspecialchars((string) ($recent['login'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>
                        · Plano <?= htmlspecialchars((string) ($recent['plan'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <?php if (!empty($recent['detail_url'])): ?>
                        <a class="button button--ghost button--small" href="<?= htmlspecialchars((string) $recent['detail_url'], ENT_QUOTES, 'UTF-8'); ?>">Ver cliente</a>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="page-description">Ainda nao ha movimentos locais recentes para exibir.</p>
    <?php endif; ?>
</section>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
