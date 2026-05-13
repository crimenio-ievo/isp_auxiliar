<?php

declare(strict_types=1);

use App\Core\Url;

$provider = is_array($provider ?? null) ? $provider : [];
$providerSettings = is_array($providerSettings ?? null) ? $providerSettings : [];
$moduleConfig = is_array($moduleConfig ?? null) ? $moduleConfig : [];
$storedConfig = is_array($storedConfig ?? null) ? $storedConfig : [];
$permissionsConfig = is_array($permissionsConfig ?? null) ? $permissionsConfig : [];
$configSource = is_array($configSource ?? null) ? $configSource : [];
$currentTab = (string) ($currentTab ?? 'geral');

$commercial = is_array($moduleConfig['commercial'] ?? null) ? $moduleConfig['commercial'] : [];
$email = is_array($moduleConfig['email'] ?? null) ? $moduleConfig['email'] : [];
$evotrix = is_array($moduleConfig['evotrix'] ?? null) ? $moduleConfig['evotrix'] : [];
$mkauthTicket = is_array($moduleConfig['mkauth_ticket'] ?? null) ? $moduleConfig['mkauth_ticket'] : [];
$system = is_array($moduleConfig['system'] ?? null) ? $moduleConfig['system'] : [];

$storedEmail = is_array($storedConfig['email'] ?? null) ? $storedConfig['email'] : [];
$storedEvotrix = is_array($storedConfig['evotrix'] ?? null) ? $storedConfig['evotrix'] : [];
$permissionRows = is_array($permissionsConfig['rows'] ?? null) ? $permissionsConfig['rows'] : [];
$operationalUrlInfo = is_array($operationalUrlInfo ?? null) ? $operationalUrlInfo : [];
$operationalBaseUrl = (string) ($operationalUrlInfo['base_url'] ?? '');
$operationalIsLocal = !empty($operationalUrlInfo['is_local']);
$operationalIsIp = !empty($operationalUrlInfo['is_ip']);
$operationalSource = (string) ($operationalUrlInfo['source'] ?? 'internal');
$publicAcceptanceLink = (string) ($operationalUrlInfo['public_acceptance_link'] ?? '');
$sampleAcceptanceLink = (string) ($operationalUrlInfo['sample_acceptance_link'] ?? '');
if ($permissionRows === []) {
    $permissionRows[] = [
        'login' => '',
        'gestor_admin' => false,
        'contratos' => false,
        'financeiro' => false,
        'tecnico' => true,
        'configuracoes' => false,
    ];
}

$moneyValue = static fn (mixed $value): string => number_format((float) $value, 2, ',', '.');
$providerValue = static fn (string $key, string $default = ''): string => (string) ($providerSettings[$key] ?? $default);
$providerAnatelDefault = strtolower(trim((string) ($provider['slug'] ?? ''))) === 'ievo'
    ? 'Processo nº 53500.292642/2022-7'
    : '';
$selected = static fn (bool $state): string => $state ? 'selected' : '';
$checked = static fn (bool $state): string => $state ? 'checked' : '';
$emailPasswordConfigured = trim((string) ($storedEmail['smtp_password'] ?? $email['smtp_password'] ?? '')) !== '';
$evotrixTokenConfigured = trim((string) ($storedEvotrix['token'] ?? $evotrix['token'] ?? '')) !== '';
$tabs = [
    'geral' => 'Geral / Provedor',
    'mkauth' => 'MkAuth',
    'contratos' => 'Contratos e Aceites',
    'email' => 'E-mail / SMTP',
    'evotrix' => 'Evotrix / WhatsApp',
    'sistema' => 'Sistema',
];

ob_start();
?>
<section class="page-header">
    <div>
        <p class="section-heading__eyebrow">Administracao</p>
        <h1>Configurações</h1>
        <p class="page-description">Central única para conexões, contratos, mensagens, permissões simples e testes controlados.</p>
    </div>
    <div class="hero-actions">
        <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/contratos?tab=configuracoes'), ENT_QUOTES, 'UTF-8'); ?>">Atalho em Contratos e Aceites</a>
    </div>
</section>

<?php if (!empty($flash)): ?>
    <div class="alert alert--<?= htmlspecialchars((string) ($flash['type'] ?? 'success'), ENT_QUOTES, 'UTF-8'); ?>">
        <?= htmlspecialchars((string) ($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<?php if ($operationalIsLocal || $operationalIsIp): ?>
    <div class="alert alert--warning" style="margin-bottom: 18px;">
        <?php if ($operationalIsLocal): ?>
            A URL operacional atual ainda aponta para ambiente local. Ajuste `APP_URL` ou o domínio em Geral / Provedor antes de marcar este ambiente como piloto real.
        <?php else: ?>
            A URL operacional atual usa endereço por IP. Para melhor compatibilidade com WhatsApp e e-mail, prefira um domínio público em vez de IP antes de marcar este ambiente como piloto real.
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (empty($email['allow_only_test_email']) && empty($evotrix['allow_only_test_phone']) && !empty($mkauthTicket['enabled']) && empty($mkauthTicket['dry_run'])): ?>
    <div class="alert alert--success" style="margin-bottom: 18px;">
        Produção real ativa: e-mail, WhatsApp/Evotrix e chamado MkAuth estão liberados para dados reais do cliente.
    </div>
<?php endif; ?>

<?php if (empty($databaseAvailable)): ?>
    <section class="card">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Banco complementar</p>
            <h2>Execute as migrations para liberar esta tela</h2>
        </div>
        <p class="page-description">Depois de criar o banco local, execute <strong>php scripts/console.php migrate</strong> no servidor.</p>
    </section>
<?php else: ?>
    <section class="card">
        <nav class="settings-tabs" aria-label="Abas de configuração">
            <?php foreach ($tabs as $tabKey => $tabLabel): ?>
                <a class="button button--ghost<?= $currentTab === $tabKey ? ' is-active' : ''; ?>" href="<?= htmlspecialchars(Url::to('/configuracoes?tab=' . $tabKey), ENT_QUOTES, 'UTF-8'); ?>">
                    <?= htmlspecialchars($tabLabel, ENT_QUOTES, 'UTF-8'); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <p class="page-description" style="margin-top: 16px;">
            Fonte de leitura: <strong><?= htmlspecialchars((string) ($configSource['panel_priority'] ?? 'Painel local > backend/config/*.php > fallback interno'), ENT_QUOTES, 'UTF-8'); ?></strong>
        </p>
    </section>

    <?php if ($currentTab === 'geral'): ?>
        <form method="post" action="<?= htmlspecialchars(Url::to('/configuracoes?tab=geral'), ENT_QUOTES, 'UTF-8'); ?>" class="content-grid content-grid--form">
            <input type="hidden" name="settings_section" value="geral">
            <section class="card">
                <div class="section-heading">
                    <p class="section-heading__eyebrow">Provedor</p>
                    <h2>Dados gerais</h2>
                </div>
                <div class="form-grid">
                    <label class="field">
                        <span>Nome do provedor</span>
                        <input type="text" name="provider_name" value="<?= htmlspecialchars((string) ($provider['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                    </label>
                    <label class="field">
                        <span>Identificador</span>
                        <input type="text" value="<?= htmlspecialchars((string) ($provider['slug'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" disabled>
                    </label>
                    <label class="field">
                        <span>CNPJ/CPF do provedor</span>
                        <input type="text" name="provider_document" value="<?= htmlspecialchars((string) ($provider['document'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>
                    <label class="field">
                        <span>Domínio principal</span>
                        <input type="text" name="provider_domain" value="<?= htmlspecialchars((string) ($provider['domain'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="provedor.com.br">
                        <small class="field-help">Usado como referência para links absolutos quando o ambiente tiver domínio próprio.</small>
                    </label>
                    <label class="field field--span-2">
                        <span>Caminho público do sistema</span>
                        <input type="text" name="provider_base_path" value="<?= htmlspecialchars((string) ($provider['base_path'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="/isp_auxiliar/public">
                        <small class="field-help">Se o sistema roda em subdiretório, informe o caminho público completo do ambiente.</small>
                    </label>
                </div>
            </section>
            <section class="card">
                <div class="section-heading">
                    <p class="section-heading__eyebrow">Dados jurídicos</p>
                    <h2>Contratada e contato oficial</h2>
                </div>
                <div class="form-grid">
                    <label class="field field--span-2">
                        <span>Razão social</span>
                        <input type="text" name="provider_legal_name" value="<?= htmlspecialchars($providerValue('provider_legal_name', (string) ($provider['name'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Razão social do provedor">
                    </label>
                    <label class="field">
                        <span>CNPJ</span>
                        <input type="text" name="provider_cnpj" value="<?= htmlspecialchars($providerValue('provider_cnpj', (string) ($provider['document'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" placeholder="00.000.000/0001-00">
                    </label>
                    <label class="field">
                        <span>Telefone / WhatsApp</span>
                        <input type="text" name="provider_phone" value="<?= htmlspecialchars($providerValue('provider_phone'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="(00) 00000-0000">
                    </label>
                    <label class="field field--span-2">
                        <span>Endereço</span>
                        <input type="text" name="provider_address" value="<?= htmlspecialchars($providerValue('provider_address'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Rua, número, complemento">
                    </label>
                    <label class="field">
                        <span>Bairro</span>
                        <input type="text" name="provider_neighborhood" value="<?= htmlspecialchars($providerValue('provider_neighborhood'), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>
                    <label class="field">
                        <span>Cidade</span>
                        <input type="text" name="provider_city" value="<?= htmlspecialchars($providerValue('provider_city'), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>
                    <label class="field">
                        <span>UF</span>
                        <input type="text" name="provider_state" value="<?= htmlspecialchars($providerValue('provider_state'), ENT_QUOTES, 'UTF-8'); ?>" maxlength="2">
                    </label>
                    <label class="field">
                        <span>CEP</span>
                        <input type="text" name="provider_zip" value="<?= htmlspecialchars($providerValue('provider_zip'), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>
                    <label class="field">
                        <span>Site</span>
                        <input type="url" name="provider_site" value="<?= htmlspecialchars($providerValue('provider_site'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://provedor.com.br">
                    </label>
                    <label class="field">
                        <span>E-mail</span>
                        <input type="email" name="provider_email" value="<?= htmlspecialchars($providerValue('provider_email'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="contato@provedor.com.br">
                    </label>
                    <label class="field field--span-2">
                        <span>Autorização ANATEL / Processo SCM</span>
                        <input type="text" name="provider_anatel_process" value="<?= htmlspecialchars($providerValue('provider_anatel_process', $providerAnatelDefault), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Processo / autorização SCM">
                    </label>
                    <label class="field field--span-2">
                        <span>Central do Assinante URL</span>
                        <input type="url" name="central_assinante_url" value="<?= htmlspecialchars($providerValue('central_assinante_url', 'https://sistema.ievo.com.br/central'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://sistema.ievo.com.br/central">
                        <small class="field-help">Usada no aceite, termo assinado e mensagens ao cliente.</small>
                    </label>
                </div>
            </section>
            <section class="card field--span-2">
                <button class="button button--full" type="submit">Salvar dados do provedor</button>
            </section>
        </form>
    <?php elseif ($currentTab === 'mkauth'): ?>
        <form method="post" action="<?= htmlspecialchars(Url::to('/configuracoes?tab=mkauth'), ENT_QUOTES, 'UTF-8'); ?>" class="content-grid content-grid--form">
            <input type="hidden" name="settings_section" value="mkauth">
            <section class="card">
                <div class="section-heading">
                    <p class="section-heading__eyebrow">MkAuth</p>
                    <h2>API, banco e ticket financeiro</h2>
                </div>
                <div class="form-grid">
                    <label class="field field--span-2">
                        <span>URL do MkAuth</span>
                        <input type="url" name="mkauth_base_url" value="<?= htmlspecialchars($providerValue('mkauth_base_url'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://seu-mkauth" required>
                        <small class="field-help">Base principal do servidor MkAuth usado para autenticação e APIs.</small>
                    </label>
                    <label class="field">
                        <span>Client ID</span>
                        <input type="text" name="mkauth_client_id" value="<?= htmlspecialchars($providerValue('mkauth_client_id'), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>
                    <label class="field">
                        <span>Client Secret</span>
                        <input type="password" name="mkauth_client_secret" value="" placeholder="<?= $providerValue('mkauth_client_secret') !== '' ? 'Mantido salvo' : 'Informe o secret'; ?>">
                    </label>
                    <label class="field field--span-2">
                        <span>Token de API</span>
                        <input type="password" name="mkauth_api_token" value="" placeholder="<?= $providerValue('mkauth_api_token') !== '' ? 'Token configurado' : 'Opcional conforme endpoint'; ?>">
                    </label>
                    <label class="field">
                        <span>Host MySQL</span>
                        <input type="text" name="mkauth_db_host" value="<?= htmlspecialchars($providerValue('mkauth_db_host'), ENT_QUOTES, 'UTF-8'); ?>" required>
                    </label>
                    <label class="field">
                        <span>Porta MySQL</span>
                        <input type="text" name="mkauth_db_port" value="<?= htmlspecialchars($providerValue('mkauth_db_port', '3306'), ENT_QUOTES, 'UTF-8'); ?>" required>
                    </label>
                    <label class="field">
                        <span>Banco</span>
                        <input type="text" name="mkauth_db_name" value="<?= htmlspecialchars($providerValue('mkauth_db_name', 'mkradius'), ENT_QUOTES, 'UTF-8'); ?>" required>
                    </label>
                    <label class="field">
                        <span>Usuário MySQL</span>
                        <input type="text" name="mkauth_db_user" value="<?= htmlspecialchars($providerValue('mkauth_db_user'), ENT_QUOTES, 'UTF-8'); ?>" required>
                    </label>
                    <label class="field">
                        <span>Senha MySQL</span>
                        <input type="password" name="mkauth_db_password" value="" placeholder="<?= $providerValue('mkauth_db_password') !== '' ? 'Senha configurada' : 'Informe a senha'; ?>">
                    </label>
                    <label class="field">
                        <span>Charset</span>
                        <input type="text" name="mkauth_db_charset" value="<?= htmlspecialchars($providerValue('mkauth_db_charset', 'utf8mb4'), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>
                    <label class="field field--span-2">
                        <span>Algoritmos de senha</span>
                        <input type="text" name="mkauth_db_hash_algos" value="<?= htmlspecialchars($providerValue('mkauth_db_hash_algos', 'sha256,sha1'), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>
                </div>
            </section>
            <section class="card">
                <div class="section-heading">
                    <p class="section-heading__eyebrow">Chamado financeiro</p>
                    <h2>Teste real controlado</h2>
                </div>
                <div class="form-grid">
                    <label class="field">
                        <span>Habilitado</span>
                        <select name="mkauth_ticket_enabled">
                            <option value="0" <?= $selected(empty($mkauthTicket['enabled'])); ?>>Nao</option>
                            <option value="1" <?= $selected(!empty($mkauthTicket['enabled'])); ?>>Sim</option>
                        </select>
                    </label>
                    <label class="field">
                        <span>DRY_RUN</span>
                        <select name="mkauth_ticket_dry_run">
                            <option value="1" <?= $selected(!empty($mkauthTicket['dry_run'])); ?>>Sim</option>
                            <option value="0" <?= $selected(empty($mkauthTicket['dry_run'])); ?>>Nao</option>
                        </select>
                    </label>
                    <label class="field field--span-2">
                        <span>Endpoint</span>
                        <input type="text" name="mkauth_ticket_endpoint" value="<?= htmlspecialchars((string) ($mkauthTicket['endpoint'] ?? '/api/chamado/inserir'), ENT_QUOTES, 'UTF-8'); ?>">
                        <small class="field-help">Endpoint usado apenas para abertura manual de chamado, nunca para cobrança.</small>
                    </label>
                    <label class="field">
                        <span>Assunto padrão</span>
                        <input type="text" name="mkauth_ticket_subject" value="<?= htmlspecialchars((string) ($mkauthTicket['subject'] ?? 'Financeiro - Boleto / Carne'), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>
                    <label class="field">
                        <span>Prioridade</span>
                        <input type="text" name="mkauth_ticket_priority" value="<?= htmlspecialchars((string) ($mkauthTicket['priority'] ?? 'normal'), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>
                    <label class="field">
                        <span>Timeout (s)</span>
                        <input type="number" min="5" name="mkauth_ticket_timeout_seconds" value="<?= htmlspecialchars((string) ($mkauthTicket['timeout_seconds'] ?? 15), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>
                    <label class="field field--span-2">
                        <span>Fallback de mensagem em sis_msg</span>
                        <select name="mkauth_ticket_message_fallback">
                            <option value="1" <?= $selected(!isset($mkauthTicket['message_fallback']) || !empty($mkauthTicket['message_fallback'])); ?>>Sim</option>
                            <option value="0" <?= $selected(!empty($mkauthTicket['message_fallback']) === false && isset($mkauthTicket['message_fallback']) && empty($mkauthTicket['message_fallback'])); ?>>Nao</option>
                        </select>
                        <small class="field-help">Se a API do MkAuth nao preencher a primeira mensagem do chamado, o ISP Auxiliar grava a mensagem inicial em sis_msg para exibir o chamado completo no painel.</small>
                    </label>
                    <label class="field field--span-2">
                        <span>AUTO_CREATE_FINANCIAL_TICKET</span>
                        <select name="mkauth_ticket_auto_create">
                            <option value="0" <?= $selected(empty($mkauthTicket['auto_create'])); ?>>Nao</option>
                            <option value="1" <?= $selected(!empty($mkauthTicket['auto_create'])); ?>>Sim</option>
                        </select>
                        <small class="field-help">Quando ativo, o chamado operacional do financeiro é aberto automaticamente após criar a pendência de adesão, sem gerar cobrança.</small>
                    </label>
                </div>
                <div class="hero-actions" style="margin-top: 18px;">
                    <button class="button" type="submit">Salvar MkAuth</button>
                    <button class="button button--ghost" type="submit" name="test_action" value="test_mkauth">Testar conexão MkAuth</button>
                </div>
            </section>
        </form>
    <?php elseif ($currentTab === 'contratos'): ?>
        <form method="post" action="<?= htmlspecialchars(Url::to('/configuracoes?tab=contratos'), ENT_QUOTES, 'UTF-8'); ?>" class="content-grid content-grid--form">
            <input type="hidden" name="settings_section" value="contratos">
            <section class="card">
                <div class="section-heading">
                    <p class="section-heading__eyebrow">Contratos</p>
                    <h2>Contratos e Aceites</h2>
                </div>
                <div class="form-grid">
                    <label class="field">
                        <span>Valor adesão padrão</span>
                        <input type="text" name="valor_adesao_padrao" inputmode="decimal" value="<?= htmlspecialchars($moneyValue($commercial['valor_adesao_padrao'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>
                    <label class="field">
                        <span>Valor adesão promocional</span>
                        <input type="text" name="valor_adesao_promocional" inputmode="decimal" value="<?= htmlspecialchars($moneyValue($commercial['valor_adesao_promocional'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>
                    <label class="field">
                        <span>Desconto promocional (%)</span>
                        <input type="text" name="percentual_desconto_promocional" inputmode="decimal" value="<?= htmlspecialchars($moneyValue($commercial['percentual_desconto_promocional'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>
                    <label class="field">
                        <span>Parcelas máximas</span>
                        <input type="number" min="1" name="parcelas_maximas_adesao" value="<?= htmlspecialchars((string) ($commercial['parcelas_maximas_adesao'] ?? 3), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>
                    <label class="field">
                        <span>Fidelidade padrão</span>
                        <input type="number" min="1" name="fidelidade_meses_padrao" value="<?= htmlspecialchars((string) ($commercial['fidelidade_meses_padrao'] ?? 12), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>
                    <label class="field">
                        <span>Validade link aceite (h)</span>
                        <input type="number" min="1" name="validade_link_aceite_horas" value="<?= htmlspecialchars((string) ($commercial['validade_link_aceite_horas'] ?? 48), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>
                    <label class="field field--span-2">
                        <span>Central do Assinante URL</span>
                        <input type="url" name="central_assinante_url" value="<?= htmlspecialchars((string) ($commercial['central_assinante_url'] ?? 'https://sistema.ievo.com.br/central'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://sistema.ievo.com.br/central">
                        <small class="field-help">Usada nas mensagens e na tela pós-aceite. Se vazia, o sistema usa a URL padrão acima.</small>
                    </label>
                    <label class="field">
                        <span>Exigir validação CPF/CNPJ</span>
                        <select name="exigir_validacao_cpf_aceite">
                            <option value="1" <?= $selected(!empty($commercial['exigir_validacao_cpf_aceite'])); ?>>Sim</option>
                            <option value="0" <?= $selected(empty($commercial['exigir_validacao_cpf_aceite'])); ?>>Nao</option>
                        </select>
                    </label>
                    <label class="field">
                        <span>Quantidade de dígitos</span>
                        <input type="number" min="1" name="quantidade_digitos_validacao_cpf" value="<?= htmlspecialchars((string) ($commercial['quantidade_digitos_validacao_cpf'] ?? 3), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>
                    <label class="field">
                        <span>Multa padrão</span>
                        <input type="text" name="multa_padrao" inputmode="decimal" value="<?= htmlspecialchars($moneyValue($commercial['multa_padrao'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>
                </div>
            </section>
            <section class="card field--span-2">
                <button class="button button--full" type="submit">Salvar Contratos e Aceites</button>
            </section>
        </form>
    <?php elseif ($currentTab === 'email'): ?>
        <form method="post" action="<?= htmlspecialchars(Url::to('/configuracoes?tab=email'), ENT_QUOTES, 'UTF-8'); ?>" class="content-grid content-grid--form">
            <input type="hidden" name="settings_section" value="email">
            <section class="card">
                <div class="section-heading">
                    <p class="section-heading__eyebrow">SMTP</p>
                    <h2>E-mail autenticado</h2>
                </div>
                <div class="form-grid">
                    <label class="field">
                        <span>EMAIL_ENABLED</span>
                        <select name="email_enabled">
                            <option value="0" <?= $selected(empty($email['enabled'])); ?>>Nao</option>
                            <option value="1" <?= $selected(!empty($email['enabled'])); ?>>Sim</option>
                        </select>
                    </label>
                    <label class="field">
                        <span>EMAIL_DRY_RUN</span>
                        <select name="email_dry_run">
                            <option value="1" <?= $selected(!empty($email['dry_run'])); ?>>Sim</option>
                            <option value="0" <?= $selected(empty($email['dry_run'])); ?>>Nao</option>
                        </select>
                    </label>
                    <label class="field">
                        <span>SMTP_HOST</span>
                        <input type="text" name="smtp_host" value="<?= htmlspecialchars((string) ($email['smtp_host'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        <small class="field-help">Servidor SMTP do provedor de e-mail ou serviço transacional.</small>
                    </label>
                    <label class="field">
                        <span>SMTP_PORT</span>
                        <input type="number" min="1" name="smtp_port" value="<?= htmlspecialchars((string) ($email['smtp_port'] ?? 587), ENT_QUOTES, 'UTF-8'); ?>">
                        <small class="field-help">Normalmente 587 com TLS ou 465 com SSL.</small>
                    </label>
                    <label class="field">
                        <span>SMTP_USERNAME</span>
                        <input type="text" name="smtp_username" value="<?= htmlspecialchars((string) ($email['smtp_username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        <small class="field-help">Usuário/autenticação do remetente.</small>
                    </label>
                    <label class="field">
                        <span>SMTP_PASSWORD</span>
                        <input type="password" name="smtp_password" value="" placeholder="<?= $emailPasswordConfigured ? 'Senha configurada' : 'Informe a senha SMTP'; ?>">
                        <small class="field-help">Pode ser senha SMTP ou senha de aplicativo.</small>
                    </label>
                    <label class="field">
                        <span>SMTP_ENCRYPTION</span>
                        <?php $currentEncryption = (string) ($email['smtp_encryption'] ?? 'tls'); ?>
                        <select name="smtp_encryption">
                            <option value="tls" <?= $currentEncryption === 'tls' ? 'selected' : ''; ?>>TLS</option>
                            <option value="ssl" <?= $currentEncryption === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                            <option value="none" <?= $currentEncryption === 'none' ? 'selected' : ''; ?>>Sem criptografia</option>
                        </select>
                        <small class="field-help">Escolha conforme a orientação do provedor de e-mail.</small>
                    </label>
                    <label class="field">
                        <span>SMTP_FROM</span>
                        <input type="email" name="smtp_from" value="<?= htmlspecialchars((string) ($email['smtp_from'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        <small class="field-help">E-mail remetente visível para o cliente.</small>
                    </label>
                    <label class="field">
                        <span>SMTP_FROM_NAME</span>
                        <input type="text" name="smtp_from_name" value="<?= htmlspecialchars((string) ($email['smtp_from_name'] ?? 'nossa equipe'), ENT_QUOTES, 'UTF-8'); ?>">
                        <small class="field-help">Nome exibido no campo remetente.</small>
                    </label>
                </div>
            </section>
            <section class="card test-mode-panel">
                <div class="section-heading">
                    <p class="section-heading__eyebrow">Modo de teste</p>
                    <h2>Envio controlado</h2>
                </div>
                <details class="settings-test-details">
                    <summary>Abrir opções de teste</summary>
                    <div class="form-grid">
                        <label class="field">
                            <span>EMAIL_ALLOW_ONLY_TEST_EMAIL</span>
                            <select name="email_allow_only_test_email">
                                <option value="1" <?= $selected(!empty($email['allow_only_test_email'])); ?>>Sim</option>
                                <option value="0" <?= $selected(empty($email['allow_only_test_email'])); ?>>Nao</option>
                            </select>
                        </label>
                        <label class="field">
                            <span>EMAIL_TEST_TO</span>
                            <input type="email" name="email_test_to" value="<?= htmlspecialchars((string) ($email['test_to'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <small class="field-help">Quando o modo de teste estiver ativo, o envio real só sai para este endereço.</small>
                        </label>
                    </div>
                </details>
                <div class="hero-actions" style="margin-top: 18px;">
                    <button class="button" type="submit">Salvar SMTP</button>
                    <button class="button button--ghost" type="submit" name="test_action" value="test_smtp">Testar SMTP</button>
                </div>
            </section>
        </form>
    <?php elseif ($currentTab === 'evotrix'): ?>
        <form method="post" action="<?= htmlspecialchars(Url::to('/configuracoes?tab=evotrix'), ENT_QUOTES, 'UTF-8'); ?>" class="content-grid content-grid--form">
            <input type="hidden" name="settings_section" value="evotrix">
            <section class="card">
                <div class="section-heading">
                    <p class="section-heading__eyebrow">WhatsApp</p>
                    <h2>Evotrix</h2>
                </div>
                <div class="form-grid">
                    <label class="field">
                        <span>EVOTRIX_ENABLED</span>
                        <select name="evotrix_enabled">
                            <option value="0" <?= $selected(empty($evotrix['enabled'])); ?>>Nao</option>
                            <option value="1" <?= $selected(!empty($evotrix['enabled'])); ?>>Sim</option>
                        </select>
                    </label>
                    <label class="field">
                        <span>EVOTRIX_DRY_RUN</span>
                        <select name="evotrix_dry_run">
                            <option value="1" <?= $selected(!empty($evotrix['dry_run'])); ?>>Sim</option>
                            <option value="0" <?= $selected(empty($evotrix['dry_run'])); ?>>Nao</option>
                        </select>
                    </label>
                    <label class="field field--span-2">
                        <span>EVOTRIX_API_BASE</span>
                        <input type="url" name="evotrix_api_base" value="<?= htmlspecialchars((string) ($evotrix['base_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        <small class="field-help">URL base da API do Evotrix usada no envio manual do aceite.</small>
                    </label>
                    <label class="field">
                        <span>EVOTRIX_ENDPOINT</span>
                        <input type="text" name="evotrix_endpoint" value="<?= htmlspecialchars((string) ($evotrix['endpoint'] ?? '/v1/services/whatsapp/notifications/text'), ENT_QUOTES, 'UTF-8'); ?>">
                        <small class="field-help">Normalmente /v1/services/whatsapp/notifications/text. Altere somente se o provedor exigir outro caminho.</small>
                    </label>
                    <label class="field">
                        <span>EVOTRIX_API_KEY</span>
                        <input type="password" name="evotrix_api_key" value="" placeholder="<?= $evotrixTokenConfigured ? 'Token configurado' : 'Informe a API key'; ?>">
                        <small class="field-help">Chave/token da API. Nunca será exibida depois de salva.</small>
                    </label>
                    <label class="field">
                        <span>EVOTRIX_CHANNEL_ID</span>
                        <input type="text" name="evotrix_channel_id" value="<?= htmlspecialchars((string) ($evotrix['channel_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        <small class="field-help">Canal ou instância quando exigido pelo provedor.</small>
                    </label>
                    <label class="field">
                        <span>Timeout (s)</span>
                        <input type="number" min="5" name="evotrix_timeout_seconds" value="<?= htmlspecialchars((string) ($evotrix['timeout_seconds'] ?? 15), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>
                    <label class="field">
                        <span>Retry adicional</span>
                        <input type="number" min="0" name="evotrix_retry_attempts" value="<?= htmlspecialchars((string) ($evotrix['retry_attempts'] ?? 1), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>
                </div>
            </section>
            <section class="card test-mode-panel">
                <div class="section-heading">
                    <p class="section-heading__eyebrow">Modo de teste</p>
                    <h2>Envio controlado</h2>
                </div>
                <details class="settings-test-details">
                    <summary>Abrir opções de teste</summary>
                    <div class="form-grid">
                        <label class="field">
                            <span>EVOTRIX_ALLOW_ONLY_TEST_PHONE</span>
                            <select name="evotrix_allow_only_test_phone">
                                <option value="1" <?= $selected(!empty($evotrix['allow_only_test_phone'])); ?>>Sim</option>
                                <option value="0" <?= $selected(empty($evotrix['allow_only_test_phone'])); ?>>Nao</option>
                            </select>
                        </label>
                        <label class="field">
                            <span>EVOTRIX_TEST_PHONE</span>
                            <input type="text" name="evotrix_test_phone" value="<?= htmlspecialchars((string) ($evotrix['test_phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <small class="field-help">Se o modo de teste estiver ativo, o envio real só sai para esse número.</small>
                        </label>
                    </div>
                </details>
                <div class="hero-actions" style="margin-top: 18px;">
                    <button class="button" type="submit">Salvar Evotrix</button>
                    <button class="button button--ghost" type="submit" name="test_action" value="test_evotrix">Testar Evotrix</button>
                </div>
            </section>
        </form>
    <?php else: ?>
        <form method="post" action="<?= htmlspecialchars(Url::to('/configuracoes?tab=sistema'), ENT_QUOTES, 'UTF-8'); ?>" class="content-grid content-grid--form">
            <input type="hidden" name="settings_section" value="sistema">
            <section class="card">
                <div class="section-heading">
                    <p class="section-heading__eyebrow">Permissões simples</p>
                    <h2>Permissões por login MkAuth</h2>
                </div>
                <p class="page-description">Sem RBAC completo nesta etapa. As permissões abaixo controlam acesso por login MkAuth no ambiente de testes.</p>

                <div class="permissions-editor" data-permission-editor>
                    <div class="permissions-editor__header">
                        <strong>Login</strong>
                        <strong>Gestor/Admin</strong>
                        <strong>Contratos</strong>
                        <strong>Financeiro</strong>
                        <strong>Técnico</strong>
                        <strong>Configurações</strong>
                        <span></span>
                    </div>
                    <div data-permission-rows>
                        <?php foreach ($permissionRows as $rowIndex => $row): ?>
                            <div class="permissions-row">
                                <input type="text" name="permission_login[<?= htmlspecialchars((string) $rowIndex, ENT_QUOTES, 'UTF-8'); ?>]" value="<?= htmlspecialchars((string) ($row['login'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="login_mkauth">
                                <label><input type="checkbox" name="permission_gestor_admin[<?= htmlspecialchars((string) $rowIndex, ENT_QUOTES, 'UTF-8'); ?>]" value="1" <?= $checked(!empty($row['gestor_admin'])); ?>><span></span></label>
                                <label><input type="checkbox" name="permission_contratos[<?= htmlspecialchars((string) $rowIndex, ENT_QUOTES, 'UTF-8'); ?>]" value="1" <?= $checked(!empty($row['contratos'])); ?>><span></span></label>
                                <label><input type="checkbox" name="permission_financeiro[<?= htmlspecialchars((string) $rowIndex, ENT_QUOTES, 'UTF-8'); ?>]" value="1" <?= $checked(!empty($row['financeiro'])); ?>><span></span></label>
                                <label><input type="checkbox" name="permission_tecnico[<?= htmlspecialchars((string) $rowIndex, ENT_QUOTES, 'UTF-8'); ?>]" value="1" <?= $checked(!empty($row['tecnico'])); ?>><span></span></label>
                                <label><input type="checkbox" name="permission_configuracoes[<?= htmlspecialchars((string) $rowIndex, ENT_QUOTES, 'UTF-8'); ?>]" value="1" <?= $checked(!empty($row['configuracoes'])); ?>><span></span></label>
                                <button type="button" class="button button--ghost button--small" data-permission-remove>Linha</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <template data-permission-row-template>
                        <div class="permissions-row">
                            <input type="text" name="permission_login[__INDEX__]" value="" placeholder="login_mkauth">
                            <label><input type="checkbox" name="permission_gestor_admin[__INDEX__]" value="1"><span></span></label>
                            <label><input type="checkbox" name="permission_contratos[__INDEX__]" value="1"><span></span></label>
                            <label><input type="checkbox" name="permission_financeiro[__INDEX__]" value="1"><span></span></label>
                            <label><input type="checkbox" name="permission_tecnico[__INDEX__]" value="1" checked><span></span></label>
                            <label><input type="checkbox" name="permission_configuracoes[__INDEX__]" value="1"><span></span></label>
                            <button type="button" class="button button--ghost button--small" data-permission-remove>Linha</button>
                        </div>
                    </template>
                    <div class="hero-actions" style="margin-top: 16px;">
                        <button type="button" class="button button--ghost" data-permission-add>Adicionar login</button>
                    </div>
                </div>
            </section>
            <section class="card">
                <div class="section-heading">
                    <p class="section-heading__eyebrow">Sistema</p>
                    <h2>Origem das configurações</h2>
                </div>
                <div class="summary-grid">
                    <div class="summary-item summary-item--span-2">
                        <span>Arquivo local do painel</span>
                        <strong><?= htmlspecialchars((string) ($configSource['panel_file'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                    <div class="summary-item">
                        <span>Último salvamento</span>
                        <strong><?= htmlspecialchars((string) ($storedConfig['saved_at'] ?? $system['settings_saved_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                    <div class="summary-item">
                        <span>Último responsável</span>
                        <strong><?= htmlspecialchars((string) ($storedConfig['saved_by'] ?? $system['settings_saved_by'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                </div>
            </section>
            <section class="card field--span-2">
                <div class="section-heading">
                    <p class="section-heading__eyebrow">URL operacional</p>
                    <h2>Base pública do aceite</h2>
                </div>
                <div class="summary-grid">
                    <div class="summary-item summary-item--span-2">
                        <span>URL base operacional</span>
                        <strong><?= htmlspecialchars($operationalBaseUrl !== '' ? $operationalBaseUrl : '-', ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                    <div class="summary-item summary-item--span-2">
                        <span>URL pública do aceite</span>
                        <strong><?= htmlspecialchars($publicAcceptanceLink !== '' ? $publicAcceptanceLink : '-', ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                    <div class="summary-item summary-item--span-2">
                        <span>Exemplo final do link</span>
                        <strong><?= htmlspecialchars($sampleAcceptanceLink !== '' ? $sampleAcceptanceLink : '-', ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                    <div class="summary-item">
                        <span>Fonte</span>
                        <strong><?= htmlspecialchars($operationalSource !== '' ? $operationalSource : '-', ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                </div>
                <p class="field-help" style="margin-top: 14px;">
                    O link final enviado por WhatsApp, e-mail e aceite público usa esta base e o mesmo token do aceite já existente.
                </p>
            </section>
            <section class="card field--span-2">
                <div class="section-heading">
                    <p class="section-heading__eyebrow">Piloto</p>
                    <h2>Checklist de liberação</h2>
                </div>
                <ul class="checklist-list">
                    <li>Cadastro novo</li>
                    <li>Adesão cheia</li>
                    <li>Adesão promocional</li>
                    <li>Adesão isenta</li>
                    <li>Aceite público</li>
                    <li>Bloqueio sem aceite</li>
                    <li>Radius</li>
                    <li>E-mail</li>
                    <li>WhatsApp / Evotrix</li>
                    <li>Chamado financeiro MkAuth</li>
                    <li>Permissões</li>
                    <li>Logs e histórico</li>
                    <li>Configurações sensíveis</li>
                </ul>
            </section>
            <section class="card field--span-2">
                <button class="button button--full" type="submit">Salvar permissões simples</button>
            </section>
        </form>
    <?php endif; ?>
<?php endif; ?>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
