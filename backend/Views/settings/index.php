<?php

declare(strict_types=1);

use App\Core\Url;

$settings = is_array($settings ?? null) ? $settings : [];
$provider = is_array($provider ?? null) ? $provider : [];
$value = static fn (string $key, string $default = ''): string => (string) ($settings[$key] ?? $default);

ob_start();
?>
<section class="page-header">
    <div>
        <p class="section-heading__eyebrow">Administracao</p>
        <h1>Configuracoes do provedor</h1>
        <p class="page-description">
            Informe os dados de conexao com o MkAuth e mantenha a configuracao separada por empresa.
        </p>
    </div>
</section>

<?php if (!empty($flash)): ?>
    <div class="alert alert--<?= htmlspecialchars((string) ($flash['type'] ?? 'success'), ENT_QUOTES, 'UTF-8'); ?>">
        <?= htmlspecialchars((string) ($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<?php if (empty($databaseAvailable)): ?>
    <section class="card">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Banco complementar</p>
            <h2>Execute as migrations para liberar esta tela</h2>
        </div>
        <p class="page-description">
            O menu de configuracao depende do banco local do ISP Auxiliar. Depois de criar o banco,
            execute <strong>php scripts/console.php migrate</strong> no servidor.
        </p>
    </section>
<?php else: ?>
    <form method="post" action="<?= htmlspecialchars(Url::to('/configuracoes'), ENT_QUOTES, 'UTF-8'); ?>" class="content-grid content-grid--form">
        <section class="card">
            <div class="section-heading">
                <p class="section-heading__eyebrow">Empresa</p>
                <h2>Dados do provedor</h2>
            </div>

            <div class="form-grid">
                <label class="field">
                    <span>Nome do provedor</span>
                    <input type="text" name="provider_name" value="<?= htmlspecialchars((string) ($provider['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                </label>

                <label class="field">
                    <span>Identificador</span>
                    <input type="text" value="<?= htmlspecialchars((string) ($provider['slug'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" disabled>
                    <small class="field-help">Definido por APP_PROVIDER_KEY para separar cada empresa.</small>
                </label>

                <label class="field">
                    <span>CNPJ/CPF do provedor</span>
                    <input type="text" name="provider_document" value="<?= htmlspecialchars((string) ($provider['document'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </label>

                <label class="field">
                    <span>Dominio principal</span>
                    <input type="text" name="provider_domain" value="<?= htmlspecialchars((string) ($provider['domain'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="provedor.com.br">
                </label>

                <label class="field field--span-2">
                    <span>Caminho publico do sistema</span>
                    <input type="text" name="provider_base_path" value="<?= htmlspecialchars((string) ($provider['base_path'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="/isp_auxiliar/public">
                    <small class="field-help">Usado como referencia para links de evidencias e futuras rotas por empresa.</small>
                </label>
            </div>
        </section>

        <section class="card">
            <div class="section-heading">
                <p class="section-heading__eyebrow">MkAuth</p>
                <h2>API e banco de consulta</h2>
            </div>

            <div class="form-grid">
                <label class="field field--span-2">
                    <span>URL do MkAuth</span>
                    <input type="url" name="mkauth_base_url" value="<?= htmlspecialchars($value('mkauth_base_url'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://192.168.23.88" required>
                </label>

                <label class="field">
                    <span>Client ID</span>
                    <input type="text" name="mkauth_client_id" value="<?= htmlspecialchars($value('mkauth_client_id'), ENT_QUOTES, 'UTF-8'); ?>">
                </label>

                <label class="field">
                    <span>Client Secret</span>
                    <input type="password" name="mkauth_client_secret" placeholder="<?= $value('mkauth_client_secret') !== '' ? 'Mantido salvo' : 'Informe o secret'; ?>">
                    <small class="field-help">Preencha apenas quando quiser cadastrar ou trocar.</small>
                </label>

                <label class="field field--span-2">
                    <span>Token de API</span>
                    <input type="password" name="mkauth_api_token" placeholder="<?= $value('mkauth_api_token') !== '' ? 'Mantido salvo' : 'Opcional conforme endpoint'; ?>">
                </label>

                <label class="field">
                    <span>Host MySQL do MkAuth</span>
                    <input type="text" name="mkauth_db_host" value="<?= htmlspecialchars($value('mkauth_db_host'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="192.168.23.88" required>
                </label>

                <label class="field">
                    <span>Porta MySQL</span>
                    <input type="text" name="mkauth_db_port" value="<?= htmlspecialchars($value('mkauth_db_port', '3306'), ENT_QUOTES, 'UTF-8'); ?>" required>
                </label>

                <label class="field">
                    <span>Banco MkAuth</span>
                    <input type="text" name="mkauth_db_name" value="<?= htmlspecialchars($value('mkauth_db_name', 'mkradius'), ENT_QUOTES, 'UTF-8'); ?>" required>
                </label>

                <label class="field">
                    <span>Usuario MySQL</span>
                    <input type="text" name="mkauth_db_user" value="<?= htmlspecialchars($value('mkauth_db_user'), ENT_QUOTES, 'UTF-8'); ?>" required>
                </label>

                <label class="field">
                    <span>Senha MySQL</span>
                    <input type="password" name="mkauth_db_password" placeholder="<?= $value('mkauth_db_password') !== '' ? 'Mantida salva' : 'Informe a senha'; ?>">
                    <small class="field-help">Preencha apenas quando quiser cadastrar ou trocar.</small>
                </label>

                <label class="field">
                    <span>Charset</span>
                    <input type="text" name="mkauth_db_charset" value="<?= htmlspecialchars($value('mkauth_db_charset', 'utf8mb4'), ENT_QUOTES, 'UTF-8'); ?>">
                </label>

                <label class="field field--span-2">
                    <span>Algoritmos de senha</span>
                    <input type="text" name="mkauth_db_hash_algos" value="<?= htmlspecialchars($value('mkauth_db_hash_algos', 'sha256,sha1'), ENT_QUOTES, 'UTF-8'); ?>">
                    <small class="field-help">Mantido para compatibilidade com a forma como o MkAuth salva senha dos operadores.</small>
                </label>
            </div>
        </section>

        <section class="card field--span-2">
            <button class="button button--full" type="submit">Salvar configuracoes</button>
        </section>
    </form>
<?php endif; ?>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
