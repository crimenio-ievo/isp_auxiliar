<?php

declare(strict_types=1);

use App\Core\Url;

$accessUsers = is_array($accessUsers ?? null) ? $accessUsers : [];
$managerLogin = (string) ($managerLogin ?? '');

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
        <p class="section-heading__eyebrow">Gestão</p>
        <h2>Usuário gestor do provedor</h2>
    </div>

    <form method="post" action="<?= htmlspecialchars(Url::to('/usuarios/gestor'), ENT_QUOTES, 'UTF-8'); ?>" class="form-grid">
        <label class="field field--span-2">
            <span>Selecione o usuário MkAuth com perfil de gestão</span>
            <?php if ($accessUsers !== []): ?>
                <select name="mkauth_manager_login">
                    <option value="">Nenhum usuário gestor definido</option>
                    <?php foreach ($accessUsers as $accessUser): ?>
                        <?php $login = (string) ($accessUser['login'] ?? ''); ?>
                        <option value="<?= htmlspecialchars($login, ENT_QUOTES, 'UTF-8'); ?>" <?= $managerLogin === $login ? 'selected' : ''; ?>>
                            <?= htmlspecialchars(trim((string) ($accessUser['nome'] ?? $accessUser['name'] ?? $login)) . ' · @' . $login, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="field-help">O login escolhido passa a entrar no ISP Auxiliar com perfil de gestor.</small>
            <?php else: ?>
                <input type="text" name="mkauth_manager_login" value="<?= htmlspecialchars($managerLogin, ENT_QUOTES, 'UTF-8'); ?>" placeholder="login do usuário gestor">
                <small class="field-help">A lista do MkAuth não está disponível. Informe o login manualmente.</small>
            <?php endif; ?>
        </label>

        <div class="form-actions field--span-2">
            <button class="button" type="submit">Salvar gestor</button>
        </div>
    </form>
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
