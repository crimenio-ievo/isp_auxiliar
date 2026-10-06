<?php

declare(strict_types=1);

use App\Core\Url;

$hideHeader = true;

ob_start();
?>
<section class="login">
    <div class="login__card">
        <div class="login__brand">
            <span class="login__logo">IA</span>
            <strong><?= htmlspecialchars((string) ($appName ?? 'ISP Auxiliar'), ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>

        <?php if (!empty($errorMessage)): ?>
            <div class="alert alert--error" role="alert"><?= htmlspecialchars((string) $errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form method="post" action="<?= htmlspecialchars(Url::to('/login'), ENT_QUOTES, 'UTF-8'); ?>" class="login__form">
            <label class="field">
                <span>Login</span>
                <input type="text" name="username" autocomplete="username" autocapitalize="none" autofocus data-system-login-input>
                <small class="field-help" data-live-feedback="system-login"></small>
            </label>

            <label class="field">
                <span>Senha</span>
                <input type="password" name="password" autocomplete="current-password">
            </label>

            <button class="button" type="submit">Entrar</button>
        </form>
    </div>
</section>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
