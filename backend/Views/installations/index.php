<?php

declare(strict_types=1);

use App\Core\Url;

ob_start();
?>
<section class="page-header">
    <div>
        <p class="section-heading__eyebrow">Operacao</p>
        <h1>Instalacoes</h1>
        <p class="page-description">Acompanhamento dos cadastros que aguardam validação Radius ou já foram finalizados.</p>
    </div>
</section>

<section class="card">
    <div class="section-heading">
        <p class="section-heading__eyebrow">Cadastro de clientes</p>
        <h2>Validação de instalação</h2>
    </div>

    <?php if (!empty($canManageRecords)): ?>
        <p class="page-description">Como gestor, você pode remover registros locais duplicados ou pendentes que ficaram incorretos no fluxo.</p>
    <?php endif; ?>

    <form method="get" action="<?= htmlspecialchars(Url::to('/clientes/retomar'), ENT_QUOTES, 'UTF-8'); ?>" class="form-grid" style="margin-bottom: 18px;">
        <label class="field field--span-2">
            <span>Retomar cadastro pendente</span>
            <input type="text" name="login" placeholder="Login do cliente ou tecnico" autocomplete="off">
            <small class="field-help">Use o login para reabrir a assinatura ou a validação de conexão após timeout.</small>
        </label>
        <div class="form-actions field--span-2">
            <button class="button" type="submit">Retomar pendência</button>
        </div>
    </form>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                        <th>Cliente</th>
                        <th>Login</th>
                        <th>Plano</th>
                        <th>Status</th>
                        <th>Pendência</th>
                        <th>Conexão</th>
                        <?php if (!empty($canManageRecords)): ?>
                            <th>Ações</th>
                        <?php endif; ?>
                        <th>Atualizado em</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($installations as $installation): ?>
                        <?php
                            $status = (string) ($installation['status'] ?? 'awaiting_connection');
                            $token = trim((string) ($installation['token'] ?? ''));
                            $draftId = trim((string) ($installation['draft_id'] ?? ''));
                            $editable = !empty($installation['editable']);
                            $resumeLink = $token !== ''
                                ? Url::to('/clientes/retomar?token=' . rawurlencode($token))
                                : Url::to('/clientes/retomar?login=' . rawurlencode((string) ($installation['login'] ?? '')));
                            $editLink = '';
                            if ($editable) {
                                if (($installation['origin'] ?? '') === 'draft' && $draftId !== '') {
                                    $editLink = Url::to('/clientes/novo?draft=' . rawurlencode($draftId));
                                } elseif ($token !== '') {
                                    $editLink = Url::to('/clientes/novo?token=' . rawurlencode($token));
                                }
                            }
                            $lastCheck = trim((string) ($installation['last_check'] ?? ''));
                            $lastConnection = is_array($installation['last_connection'] ?? null) ? $installation['last_connection'] : [];
                            $missing = trim((string) ($installation['missing'] ?? ''));
                            $statusLabel = match ($status) {
                                'completed' => 'concluida',
                                'pending_photos' => 'fotos pendentes',
                                'pending_signature' => 'assinatura pendente',
                                default => 'awaiting_connection',
                            };
                        ?>
                        <tr>
                            <td data-label="Cliente"><?= htmlspecialchars((string) $installation['client'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td data-label="Login"><?= htmlspecialchars((string) $installation['login'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td data-label="Plano"><?= htmlspecialchars((string) $installation['plan'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td data-label="Status">
                                <span class="pill <?= $status === 'completed' ? 'pill--success' : 'pill--muted'; ?>">
                                    <?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td data-label="Pendência">
                                <?php if ($missing !== ''): ?>
                                    <span class="muted"><?= htmlspecialchars($missing, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php else: ?>
                                    <span class="muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Conexão">
                                <?php if ($status === 'completed'): ?>
                                    <div class="table-action-stack">
                                        <span>Conexão confirmada</span>
                                        <?php if ($lastCheck !== ''): ?>
                                            <small>Última verificação: <?= htmlspecialchars($lastCheck, ENT_QUOTES, 'UTF-8'); ?></small>
                                        <?php endif; ?>
                                        <?php if (is_array($lastConnection) && !empty($lastConnection['online'])): ?>
                                            <small>Sessão ativa em radacct.</small>
                                        <?php endif; ?>
                                    </div>
                                <?php elseif ($resumeLink !== ''): ?>
                                    <a class="button button--ghost button--small" href="<?= htmlspecialchars($resumeLink, ENT_QUOTES, 'UTF-8'); ?>">
                                        Retomar validação
                                    </a>
                                <?php else: ?>
                                    <span class="muted">Pendente sem token</span>
                                <?php endif; ?>
                            </td>
                            <?php if (!empty($canManageRecords)): ?>
                                <td data-label="Ações">
                                    <?php if ($editLink !== ''): ?>
                                        <a class="button button--ghost button--small" href="<?= htmlspecialchars($editLink, ENT_QUOTES, 'UTF-8'); ?>">Editar</a>
                                    <?php endif; ?>
                                    <?php if (($installation['duplicate'] ?? false) || ($installation['origin'] ?? '') === 'draft' || $status !== 'completed'): ?>
                                        <form method="post" action="<?= htmlspecialchars(Url::to('/instalacoes/excluir'), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="token" value="<?= htmlspecialchars((string) ($installation['token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="login" value="<?= htmlspecialchars((string) ($installation['login'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="origin" value="<?= htmlspecialchars((string) ($installation['origin'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            <button class="button button--ghost button--small" type="submit">Excluir</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted">-</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td data-label="Atualizado em"><?= htmlspecialchars((string) $installation['date'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($installations === []): ?>
                        <tr>
                            <td colspan="<?= !empty($canManageRecords) ? '8' : '7'; ?>">Nenhuma instalação registrada.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
</section>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
