<?php

declare(strict_types=1);

use App\Core\Url;

ob_start();
?>
<section class="auth-panel">
    <div class="auth-panel__intro">
        <span class="pill">Operação ISP</span>
        <h1>Cadastro integrado de novos clientes</h1>
        <p>
            Sistema auxiliar para cadastro padronizado, coleta de evidências,
            aceite do cliente e integração operacional com o MkAuth.
        </p>

        <div class="info-grid">
            <article class="card soft-card">
                <h2>Cadastro completo</h2>
                <p>Dados do cliente, endereço, plano, fotos, assinatura e validação Radius.</p>
            </article>
            <article class="card soft-card">
                <h2>Integração MkAuth</h2>
                <p>Operadores, planos, vencimentos, duplicidade e envio via API.</p>
            </article>
        </div>
    </div>

    <div class="auth-panel__form card">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Acesso</p>
            <h2>Entrar no sistema</h2>
        </div>

        <?php if (!empty($errorMessage)): ?>
            <div class="alert alert--error"><?= htmlspecialchars((string) $errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form method="post" action="<?= htmlspecialchars(Url::to('/login'), ENT_QUOTES, 'UTF-8'); ?>" class="form-grid">
            <label class="field">
                <span>Usuario</span>
                <input type="text" name="username" placeholder="usuario do MkAuth ou e-mail do gestor" autocomplete="username" data-system-login-input>
                <small class="field-help" data-live-feedback="system-login">
                    <?= !empty($mkauthConfigured) ? 'Gestores locais e técnicos do MkAuth podem acessar.' : 'Gestores locais podem configurar a integração inicial.'; ?>
                </small>
            </label>

            <label class="field">
                <span>Senha</span>
                <input type="password" name="password" placeholder="Senha do MkAuth" autocomplete="current-password">
            </label>

            <button class="button" type="submit">Entrar</button>
        </form>

        <p class="helper-text">
            <?= !empty($mkauthConfigured)
                ? 'Técnicos usam o login do MkAuth. Gestores usam o login local do ISP Auxiliar.'
                : 'Entre como gestor local para configurar a integração MkAuth.'; ?>
        </p>
    </div>
</section>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
