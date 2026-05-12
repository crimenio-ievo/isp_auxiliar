<?php

declare(strict_types=1);

use App\Core\Url;

$summaryCards = is_array($summaryCards ?? null) ? $summaryCards : [];
$recentContracts = is_array($recentContracts ?? null) ? $recentContracts : [];
$pendingAcceptances = is_array($pendingAcceptances ?? null) ? $pendingAcceptances : [];
$commercialConfig = is_array($commercialConfig ?? null) ? $commercialConfig : [];
$emailConfig = is_array($emailConfig ?? null) ? $emailConfig : [];
$currentTab = (string) ($currentTab ?? 'resumo');
$canManageContracts = !empty($canManageContracts);
$moneyValue = static fn (mixed $value): string => number_format((float) $value, 2, ',', '.');
$emailPasswordConfigured = trim((string) ($emailConfig['smtp_password'] ?? '')) !== '';
$tabLink = static function (string $tab) use ($currentTab): string {
    $active = $currentTab === $tab ? ' is-active' : '';
    return $active;
};

ob_start();
?>
<section class="page-header">
    <div>
        <p class="section-heading__eyebrow">Operação</p>
        <h1>Contratos e Aceites</h1>
        <p class="page-description">Painel inicial do módulo de contratos, aceite digital e pendências vinculadas ao atendimento.</p>
    </div>
</section>

<section class="card" style="margin-bottom: 20px;">
    <div class="section-heading">
        <p class="section-heading__eyebrow">Navegação</p>
        <h2>Áreas do módulo</h2>
    </div>

    <div class="hero-actions">
        <a class="button button--ghost<?= $currentTab === 'resumo' ? ' is-active' : ''; ?>" href="<?= htmlspecialchars(Url::to('/contratos'), ENT_QUOTES, 'UTF-8'); ?>">Resumo</a>
        <a class="button button--ghost<?= $currentTab === 'novos' ? ' is-active' : ''; ?>" href="<?= htmlspecialchars(Url::to('/contratos/novos'), ENT_QUOTES, 'UTF-8'); ?>">Novos Contratos</a>
        <a class="button button--ghost<?= $currentTab === 'aceites-pendentes' ? ' is-active' : ''; ?>" href="<?= htmlspecialchars(Url::to('/contratos/aceites/pendentes'), ENT_QUOTES, 'UTF-8'); ?>">Aceites Pendentes</a>
        <a class="button button--ghost<?= $currentTab === 'configuracoes' ? ' is-active' : ''; ?>" href="<?= htmlspecialchars(Url::to('/contratos?tab=configuracoes'), ENT_QUOTES, 'UTF-8'); ?>">Configurações</a>
    </div>
</section>

<?php if (!empty($moduleMessage)): ?>
    <section class="card" style="margin-bottom: 20px;">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Estrutura</p>
            <h2>Módulo em preparação</h2>
        </div>
        <p class="page-description"><?= htmlspecialchars((string) $moduleMessage, ENT_QUOTES, 'UTF-8'); ?></p>
    </section>
<?php endif; ?>

<?php if ($currentTab !== 'configuracoes'): ?>
    <section class="stats-grid">
        <?php foreach ($summaryCards as $card): ?>
            <article class="card stat-card">
                <p class="stat-card__label"><?= htmlspecialchars((string) ($card['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                <strong class="stat-card__value"><?= htmlspecialchars((string) ($card['value'] ?? '0'), ENT_QUOTES, 'UTF-8'); ?></strong>
                <span class="stat-card__hint"><?= htmlspecialchars((string) ($card['hint'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<?php if ($currentTab === 'configuracoes'): ?>
    <section class="content-grid" style="margin-top: 20px;">
        <article class="card card--span-2">
            <div class="section-heading">
                <p class="section-heading__eyebrow">Configuração</p>
                <h2>Parâmetros comerciais</h2>
            </div>

            <?php if (!empty($moduleMessage)): ?>
                <div class="status-card status-card--warning" style="margin-bottom: 18px;">
                    <strong><?= htmlspecialchars((string) $moduleMessage, ENT_QUOTES, 'UTF-8'); ?></strong>
                    <small>As configurações podem ser salvas antes da base completa existir.</small>
                </div>
            <?php endif; ?>

            <?php if ($canManageContracts): ?>
                <form method="post" action="<?= htmlspecialchars(Url::to('/contratos'), ENT_QUOTES, 'UTF-8'); ?>" class="content-grid content-grid--form">
                    <input type="hidden" name="tab" value="configuracoes">
                    <input type="hidden" name="save_config" value="1">

                    <label class="field">
                        <span>Valor adesão padrão</span>
                        <input type="text" name="valor_adesao_padrao" inputmode="decimal" value="<?= htmlspecialchars($moneyValue($commercialConfig['valor_adesao_padrao'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>

                    <label class="field">
                        <span>Valor adesão promocional</span>
                        <input type="text" name="valor_adesao_promocional" inputmode="decimal" value="<?= htmlspecialchars($moneyValue($commercialConfig['valor_adesao_promocional'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>

                    <label class="field">
                        <span>Percentual desconto promocional</span>
                        <input type="text" name="percentual_desconto_promocional" inputmode="decimal" value="<?= htmlspecialchars($moneyValue($commercialConfig['percentual_desconto_promocional'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>

                    <label class="field">
                        <span>Parcelas máximas da adesão</span>
                        <input type="number" name="parcelas_maximas_adesao" min="1" value="<?= htmlspecialchars((string) ($commercialConfig['parcelas_maximas_adesao'] ?? 3), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>

                    <label class="field">
                        <span>Fidelidade padrão</span>
                        <input type="number" name="fidelidade_meses_padrao" min="1" value="<?= htmlspecialchars((string) ($commercialConfig['fidelidade_meses_padrao'] ?? 12), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>

                    <label class="field">
                        <span>Validade do link do aceite (horas)</span>
                        <input type="number" name="validade_link_aceite_horas" min="1" value="<?= htmlspecialchars((string) ($commercialConfig['validade_link_aceite_horas'] ?? 48), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>

                    <label class="field">
                        <span>Exigir validação parcial do CPF/CNPJ</span>
                        <select name="exigir_validacao_cpf_aceite">
                            <option value="1" <?= !empty($commercialConfig['exigir_validacao_cpf_aceite']) ? 'selected' : ''; ?>>Sim</option>
                            <option value="0" <?= empty($commercialConfig['exigir_validacao_cpf_aceite']) ? 'selected' : ''; ?>>Não</option>
                        </select>
                    </label>

                    <label class="field">
                        <span>Quantidade de dígitos da validação</span>
                        <input type="number" name="quantidade_digitos_validacao_cpf" min="1" value="<?= htmlspecialchars((string) ($commercialConfig['quantidade_digitos_validacao_cpf'] ?? 3), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>

                    <div class="section-heading field--span-2" style="margin-top: 12px;">
                        <p class="section-heading__eyebrow">E-mail</p>
                        <h2>SMTP autenticado</h2>
                    </div>

                    <label class="field">
                        <span>E-mail habilitado</span>
                        <select name="email_enabled">
                            <option value="0" <?= empty($emailConfig['enabled']) ? 'selected' : ''; ?>>Não</option>
                            <option value="1" <?= !empty($emailConfig['enabled']) ? 'selected' : ''; ?>>Sim</option>
                        </select>
                    </label>

                    <label class="field">
                        <span>E-mail DRY_RUN</span>
                        <select name="email_dry_run">
                            <option value="1" <?= !empty($emailConfig['dry_run']) ? 'selected' : ''; ?>>Sim</option>
                            <option value="0" <?= empty($emailConfig['dry_run']) ? 'selected' : ''; ?>>Não</option>
                        </select>
                    </label>

                    <label class="field">
                        <span>Permitir apenas e-mail de teste</span>
                        <select name="email_allow_only_test_email">
                            <option value="1" <?= !empty($emailConfig['allow_only_test_email']) ? 'selected' : ''; ?>>Sim</option>
                            <option value="0" <?= empty($emailConfig['allow_only_test_email']) ? 'selected' : ''; ?>>Não</option>
                        </select>
                    </label>

                    <label class="field">
                        <span>E-mail de teste</span>
                        <input type="email" name="email_test_to" value="<?= htmlspecialchars((string) ($emailConfig['test_to'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>

                    <label class="field">
                        <span>SMTP host</span>
                        <input type="text" name="smtp_host" value="<?= htmlspecialchars((string) ($emailConfig['smtp_host'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>

                    <label class="field">
                        <span>SMTP porta</span>
                        <input type="number" name="smtp_port" min="1" value="<?= htmlspecialchars((string) ($emailConfig['smtp_port'] ?? 587), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>

                    <label class="field">
                        <span>SMTP usuário</span>
                        <input type="text" name="smtp_username" value="<?= htmlspecialchars((string) ($emailConfig['smtp_username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>

                    <label class="field">
                        <span>SMTP senha</span>
                        <input type="password" name="smtp_password" value="" placeholder="<?= $emailPasswordConfigured ? 'Senha configurada' : 'Informe a senha SMTP'; ?>">
                        <small class="field-help"><?= $emailPasswordConfigured ? 'Senha configurada e mantida salva se este campo ficar vazio.' : 'A senha fica salva apenas na configuração local do módulo.'; ?></small>
                    </label>

                    <label class="field">
                        <span>Criptografia</span>
                        <select name="smtp_encryption">
                            <?php $currentEncryption = (string) ($emailConfig['smtp_encryption'] ?? 'tls'); ?>
                            <option value="tls" <?= $currentEncryption === 'tls' ? 'selected' : ''; ?>>TLS</option>
                            <option value="ssl" <?= $currentEncryption === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                            <option value="none" <?= $currentEncryption === 'none' ? 'selected' : ''; ?>>Nenhuma</option>
                        </select>
                    </label>

                    <label class="field">
                        <span>Remetente</span>
                        <input type="email" name="smtp_from" value="<?= htmlspecialchars((string) ($emailConfig['smtp_from'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>

                    <label class="field field--span-2">
                        <span>Nome do remetente</span>
                        <input type="text" name="smtp_from_name" value="<?= htmlspecialchars((string) ($emailConfig['smtp_from_name'] ?? 'nossa equipe'), ENT_QUOTES, 'UTF-8'); ?>">
                    </label>

                    <div class="form-actions field--span-2">
                        <button type="submit" class="button">Salvar configurações</button>
                        <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/contratos'), ENT_QUOTES, 'UTF-8'); ?>">Voltar ao resumo</a>
                    </div>
                </form>
            <?php else: ?>
                <p class="page-description">Apenas o gestor pode ajustar os parâmetros comerciais do módulo.</p>
            <?php endif; ?>
        </article>
    </section>
<?php else: ?>
<section class="content-grid" style="margin-top: 20px;">
    <article class="card">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Atalhos</p>
            <h2>Acesso rápido</h2>
        </div>

        <div class="hero-actions">
            <a class="button" href="<?= htmlspecialchars(Url::to('/contratos/novos'), ENT_QUOTES, 'UTF-8'); ?>">Novos Contratos</a>
            <a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/contratos/aceites/pendentes'), ENT_QUOTES, 'UTF-8'); ?>">Aceites Pendentes</a>
        </div>
    </article>

    <article class="card">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Status</p>
            <h2>Resumo da operação</h2>
        </div>

        <ul class="check-list">
            <li>Contratos e aceites ficam centralizados neste módulo.</li>
            <li>O aceite público ainda não foi exposto nesta fase.</li>
            <li>Os botões desta etapa são apenas visuais e de navegação.</li>
        </ul>
    </article>
</section>

<section class="content-grid" style="margin-top: 20px;">
    <article class="card">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Novos contratos</p>
            <h2>Últimos registros</h2>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Login</th>
                        <th>Adesão</th>
                        <th>Financeiro</th>
                        <th>Aceite</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentContracts as $contract): ?>
                        <?php
                            $contractId = (int) ($contract['id'] ?? 0);
                            $acceptanceId = (int) ($contract['acceptance_id'] ?? 0);
                            $tokenHash = trim((string) ($contract['token_hash'] ?? ''));
                            $linkToken = $tokenHash !== '' ? $tokenHash : (string) ($acceptanceId > 0 ? $acceptanceId : $contractId);
                            $simulatedLink = Url::to('/aceite/' . rawurlencode($linkToken));
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($contract['nome_cliente'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars((string) ($contract['mkauth_login'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars((string) ($contract['tipo_adesao'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="pill"><?= htmlspecialchars((string) ($contract['status_financeiro'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td><span class="pill pill--muted"><?= htmlspecialchars((string) ($contract['acceptance_status'] ?? 'criado'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td>
                                <div class="inline-actions">
                                    <a class="button button--ghost button--small" href="<?= htmlspecialchars(Url::to('/contratos/detalhe?id=' . $contractId), ENT_QUOTES, 'UTF-8'); ?>">Visualizar contrato</a>
                                    <a class="button button--ghost button--small" href="<?= htmlspecialchars(Url::to('/contratos/detalhe?id=' . $contractId . '#financeiro'), ENT_QUOTES, 'UTF-8'); ?>">Ver pendência financeira</a>
                                    <a class="button button--ghost button--small" href="<?= htmlspecialchars(Url::to('/contratos/detalhe?id=' . $contractId . '#logs'), ENT_QUOTES, 'UTF-8'); ?>">Ver logs relacionados</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($recentContracts === []): ?>
                        <tr>
                            <td colspan="6">Nenhum contrato encontrado neste momento.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="card">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Aceites</p>
            <h2>Pendências recentes</h2>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Login</th>
                        <th>Status</th>
                        <th>Financeiro</th>
                        <th>Expira em</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingAcceptances as $acceptance): ?>
                        <?php
                            $contractId = (int) ($acceptance['contract_id'] ?? 0);
                            $acceptanceId = (int) ($acceptance['acceptance_id'] ?? 0);
                            $tokenHash = trim((string) ($acceptance['token_hash'] ?? ''));
                            $linkToken = $tokenHash !== '' ? $tokenHash : (string) ($acceptanceId > 0 ? $acceptanceId : $contractId);
                            $simulatedLink = Url::to('/aceite/' . rawurlencode($linkToken));
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($acceptance['nome_cliente'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars((string) ($acceptance['mkauth_login'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="pill pill--muted"><?= htmlspecialchars((string) ($acceptance['acceptance_status'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td><span class="pill"><?= htmlspecialchars((string) ($acceptance['status_financeiro'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td><?= htmlspecialchars((string) ($acceptance['token_expires_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <div class="inline-actions">
                                    <a class="button button--ghost button--small" href="<?= htmlspecialchars(Url::to('/contratos/detalhe?id=' . $contractId), ENT_QUOTES, 'UTF-8'); ?>">Visualizar contrato</a>
                                    <a class="button button--ghost button--small" href="<?= htmlspecialchars(Url::to('/contratos/detalhe?id=' . $contractId . '#financeiro'), ENT_QUOTES, 'UTF-8'); ?>">Ver pendência financeira</a>
                                    <a class="button button--ghost button--small" href="<?= htmlspecialchars(Url::to('/contratos/detalhe?id=' . $contractId . '#logs'), ENT_QUOTES, 'UTF-8'); ?>">Ver logs relacionados</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($pendingAcceptances === []): ?>
                        <tr>
                            <td colspan="6">Nenhum aceite pendente encontrado neste momento.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>
<?php endif; ?>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
