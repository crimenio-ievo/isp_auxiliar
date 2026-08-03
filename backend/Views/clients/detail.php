<?php

declare(strict_types=1);

use App\Core\Url;

$detail = is_array($detail ?? null) ? $detail : [];
$profile = is_array($detail['profile'] ?? null) ? $detail['profile'] : [];
$client = is_array($detail['clientProfile'] ?? null) ? $detail['clientProfile'] : [];
$contracts = is_array($detail['contracts'] ?? null) ? $detail['contracts'] : [];
$digital = is_array($detail['digitalContract'] ?? null) ? $detail['digitalContract'] : [];
$processes = is_array($detail['operationalProcesses'] ?? null) ? $detail['operationalProcesses'] : [];
$migrationAction = is_array($detail['migrationAction'] ?? null) ? $detail['migrationAction'] : [];
$timeline = is_array($detail['timeline'] ?? null) ? $detail['timeline'] : [];
$acceptances = is_array($detail['acceptanceHistory'] ?? null) ? $detail['acceptanceHistory'] : [];
$financialTask = is_array($detail['financialTask'] ?? null) ? $detail['financialTask'] : [];
$scannedDocuments = is_array($detail['scannedDocuments'] ?? null) ? $detail['scannedDocuments'] : [];
$activeProcess = null;
foreach ($processes as $processCandidate) {
    if (is_array($processCandidate) && !in_array((string) ($processCandidate['status'] ?? ''), ['completed', 'cancelled'], true)) {
        $activeProcess = $processCandidate;
        break;
    }
}
$login = (string) ($detail['login'] ?? '');
$h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$present = static fn (mixed $value): bool => trim((string) $value) !== '';
$formatEventTime = static function (mixed $value): string {
    $value = trim((string) $value);
    if ($value === '') { return ''; }
    try { return (new DateTimeImmutable($value))->format('d/m H:i'); } catch (Throwable) { return $value; }
};
$formatDocument = static function (string $document): string {
    $digits = preg_replace('/\D+/', '', $document) ?? '';
    if (strlen($digits) === 11) {
        return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $digits) ?: $document;
    }
    if (strlen($digits) === 14) {
        return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $digits) ?: $document;
    }
    return $document;
};
$statusVisual = is_array($profile['status_visual'] ?? null) ? $profile['status_visual'] : [];
$technology = is_array($profile['technology_detail'] ?? null) ? $profile['technology_detail'] : [];
$shortName = trim((string) ($profile['short_name'] ?? ''));
$mainName = trim((string) ($profile['name'] ?? 'Cliente'));
$cards = [
    ['key' => 'client', 'eyebrow' => 'Cliente', 'title' => $mainName, 'summary' => $formatDocument((string) ($profile['document'] ?? ''))],
    ['key' => 'connection', 'eyebrow' => 'Conexão', 'title' => (string) ($profile['plan'] ?? 'Plano não informado'), 'summary' => (string) ($profile['technology'] ?? '')],
    ['key' => 'address', 'eyebrow' => 'Endereço', 'title' => (string) ($profile['address'] ?? 'Endereço não informado'), 'summary' => trim((string) ($client['cidade'] ?? '') . ((string) ($client['estado'] ?? '') !== '' ? '/' . (string) $client['estado'] : ''))],
    ['key' => 'financial', 'eyebrow' => 'Financeiro', 'title' => $present($profile['monthly_value'] ?? '') ? 'R$ ' . number_format((float) $profile['monthly_value'], 2, ',', '.') . '/mês' : 'Valor não informado', 'summary' => $present($profile['due_day'] ?? '') ? 'Vencimento dia ' . (string) $profile['due_day'] : 'Vencimento não informado'],
];

ob_start();
?>
<main class="client-hub" data-client-hub data-client-login="<?= $h($login); ?>">
    <section class="client-hub__header">
        <div>
            <a class="client-hub__back" href="<?= $h(Url::to('/clientes')); ?>">← Voltar para clientes</a>
            <p class="section-heading__eyebrow">Central do cliente</p>
            <h1><?= $h($mainName); ?></h1>
            <?php if ($shortName !== '' && strcasecmp($shortName, $mainName) !== 0): ?>
                <p class="client-hub__short-name">Conhecido como <?= $h($shortName); ?></p>
            <?php endif; ?>
            <div class="client-hub__identity">
                <span class="client-status-badge <?= $h($statusVisual['class'] ?? 'client-status-other'); ?>"><?= $h($statusVisual['label'] ?? $profile['status'] ?? 'Status não identificado'); ?></span>
                <span>Login <?= $h($login); ?></span>
                <?php if ($present($profile['document'] ?? '')): ?><span><?= $h($formatDocument((string) $profile['document'])); ?></span><?php endif; ?>
            </div>
        </div>
        <?php if (!empty($canRequestUpgrade) && !is_array($activeProcess)): ?><a class="button" href="<?= $h(Url::to('/clientes/upgrade?login=' . rawurlencode($login))); ?>">Iniciar Upgrade / Migração</a><?php endif; ?>
    </section>

    <?php if (!empty($flash)): ?>
        <section class="alert alert--<?= $h($flash['type'] ?? 'success'); ?>" tabindex="-1" data-focus-on-load><?= $h($flash['message'] ?? ''); ?></section>
    <?php endif; ?>

    <?php if (is_array($activeProcess)): ?>
        <?php $active = $activeProcess; ?>
        <?php $tone = ($active['status'] ?? '') === 'attention' ? 'danger' : (str_starts_with((string) ($active['status'] ?? ''), 'waiting_') ? 'warning' : 'info'); ?>
        <section class="client-process-panel client-process-panel--<?= $h($tone); ?>" id="upgrade-process">
            <div class="client-process-panel__identity"><span><?= $h($active['type_label'] ?? 'Processo'); ?> #<?= (int) ($active['id'] ?? 0); ?></span><strong><?= (int) ($active['progress_completed'] ?? 0); ?> de <?= (int) ($active['progress_total'] ?? 0); ?> etapas concluídas</strong></div>
            <div><span>Situação</span><strong><?= $h($active['status_label'] ?? 'Em andamento'); ?></strong></div>
            <div><span>Próxima ação</span><strong><?= $h($active['next_pending_label'] ?? 'Revisar processo'); ?></strong></div>
            <a class="button button--small" href="<?= $h(Url::to((string) ($active['resume_url'] ?? '/processos/migracao?id=' . (int) ($active['id'] ?? 0)))); ?>">Continuar processo</a>
        </section>
    <?php endif; ?>

    <section class="quick-actions" aria-label="Ações rápidas">
        <?php foreach ((array) ($profile['actions'] ?? []) as $action): ?>
            <?php if (($action['type'] ?? '') === 'copy'): ?>
                <button class="quick-action" type="button" data-copy-value="<?= $h($action['value'] ?? ''); ?>"><span aria-hidden="true">⧉</span><?= $h($action['label'] ?? 'Copiar'); ?></button>
            <?php else: ?>
                <a class="quick-action" href="<?= $h($action['url'] ?? '#'); ?>" <?= in_array((string) ($action['type'] ?? ''), ['whatsapp', 'map', 'ip'], true) ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>>
                    <span aria-hidden="true"><?= match ($action['type'] ?? '') { 'phone' => '☎', 'whatsapp' => '◉', 'email' => '✉', 'map' => '⌖', 'ip' => '↗', default => '•' }; ?></span><?= $h($action['label'] ?? 'Abrir'); ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
        <a class="quick-action" href="#documents"><span aria-hidden="true">▤</span>Abrir documentos</a>
    </section>

    <section class="client-card-rail" aria-label="Resumo do cliente">
        <?php foreach ($cards as $card): ?>
            <button class="client-catalog-card client-catalog-card--<?= $h($card['key']); ?>" type="button" data-open-client-panel="<?= $h($card['key']); ?>" aria-haspopup="dialog">
                <span><?= $h($card['eyebrow']); ?></span>
                <strong><?= $h($card['title']); ?></strong>
                <?php if ($present($card['summary'])): ?><small><?= $h($card['summary']); ?></small><?php endif; ?>
                <em>Ver detalhes →</em>
            </button>
        <?php endforeach; ?>
    </section>

    <section class="card documents-center" id="documents">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Documentos e contratos</p>
            <h2>Histórico documental em um só lugar</h2>
        </div>
        <div class="document-status-grid">
            <div><span>Contrato digital</span><strong><?= !empty($digital['signed']) ? 'Assinado' : 'Não assinado'; ?></strong></div>
            <div><span>Contrato impresso</span><strong><?= $scannedDocuments !== [] ? 'Digitalizado' : 'Não digitalizado'; ?></strong></div>
            <div><span>Aceite pendente</span><strong><?= !empty($digital['pending']) ? 'Sim' : 'Não'; ?></strong></div>
            <div><span>Documentos locais</span><strong><?= count($contracts); ?></strong></div>
        </div>
        <div class="hero-actions">
            <?php if ($contracts !== [] && (int) ($contracts[0]['id'] ?? 0) > 0): ?><a class="button button--ghost" href="<?= $h(Url::to('/contratos/detalhe?id=' . (int) $contracts[0]['id'])); ?>">Ver documentos</a><?php endif; ?>
            <?php if (!empty($canRequestContractSignature)): ?>
                <form method="post" action="<?= $h(Url::to('/clientes/contrato/solicitar')); ?>" data-single-submit-form onsubmit="return confirm('Confirma a preparação e o envio controlado do contrato digital pelos canais cadastrados?');">
                    <input type="hidden" name="_csrf" value="<?= $h($contractSignatureCsrfToken ?? ''); ?>">
                    <input type="hidden" name="login" value="<?= $h($login); ?>">
                    <input type="hidden" name="confirm_send" value="1">
                    <input type="hidden" name="signature_mode" value="remote">
                    <input type="hidden" name="remote_signature_reason" value="Solicitação avulsa pelo perfil do cliente">
                    <button class="button" type="submit"><?= !empty($digital['pending']) ? 'Reenviar assinatura' : 'Solicitar assinatura'; ?></button>
                </form>
            <?php endif; ?>
            <a class="button button--ghost" href="<?= $h(Url::to('/clientes/documentos/digitalizar?login=' . rawurlencode($login))); ?>">Digitalizar contrato</a>
        </div>

        <?php if ($contracts !== []): ?>
            <div class="document-list">
                <?php foreach ($contracts as $contract): ?>
                    <a href="<?= $h(Url::to('/contratos/detalhe?id=' . (int) ($contract['id'] ?? 0))); ?>">
                        <span>Contrato #<?= (int) ($contract['id'] ?? 0); ?></span>
                        <strong><?= $h($contract['tipo_aceite'] ?? 'Documento'); ?></strong>
                        <small><?= $h($contract['updated_at'] ?? $contract['created_at'] ?? ''); ?></small>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($scannedDocuments !== []): ?>
            <div class="document-list">
                <?php foreach ($scannedDocuments as $document): ?>
                    <a href="<?= $h(Url::to('/clientes/documentos/arquivo?id=' . (int) ($document['id'] ?? 0))); ?>" target="_blank" rel="noopener">
                        <span>PDF digitalizado</span><strong><?= (int) ($document['page_count'] ?? 1); ?> página(s)</strong><small><?= $h($document['created_at'] ?? ''); ?></small>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card recent-events">
        <div class="section-heading"><p class="section-heading__eyebrow">Linha do tempo</p><h2>Eventos recentes</h2></div>
        <?php if ($timeline === []): ?><p class="page-description">Nenhum evento local encontrado.</p><?php endif; ?>
        <?php foreach (array_slice($timeline, 0, 12) as $event): ?>
            <article><time><?= $h($formatEventTime($event['time'] ?? '')); ?></time><div><strong><?= $h($event['label'] ?? 'Evento'); ?></strong><?php if ($present($event['description'] ?? '')): ?><p><?= $h($event['description']); ?></p><?php endif; ?></div></article>
        <?php endforeach; ?>
        <?php if ($processes !== []): ?><a class="button button--ghost button--small" href="<?= $h(Url::to('/processos/detalhe?id=' . (int) ($processes[0]['id'] ?? 0))); ?>">Ver checklist técnico completo</a><?php endif; ?>
    </section>

    <?php foreach (['client', 'connection', 'address', 'financial'] as $panelKey): ?>
        <div class="client-detail-panel" data-client-panel="<?= $h($panelKey); ?>" hidden>
            <button class="client-detail-panel__backdrop" type="button" data-close-client-panel aria-label="Fechar painel"></button>
            <section class="client-detail-panel__dialog" role="dialog" aria-modal="true" aria-labelledby="client-panel-title-<?= $h($panelKey); ?>" tabindex="-1">
                <header><p class="section-heading__eyebrow">Detalhes</p><h2 id="client-panel-title-<?= $h($panelKey); ?>"><?= $h(ucfirst($panelKey === 'address' ? 'endereço' : ($panelKey === 'financial' ? 'financeiro' : ($panelKey === 'connection' ? 'conexão' : 'cliente')))); ?></h2><button type="button" data-close-client-panel aria-label="Fechar">×</button></header>
                <?php if ($panelKey === 'client'): ?>
                    <dl class="detail-list">
                        <?php foreach (['Nome completo' => $mainName, 'Nome resumido' => $shortName, 'CPF/CNPJ' => $formatDocument((string) ($profile['document'] ?? '')), 'Responsável' => $client['responsavel'] ?? '', 'Nascimento' => $client['nascimento'] ?? '', 'RG' => $client['rg'] ?? '', 'Fonte' => $client['source'] ?? 'MkAuth', 'Última atualização' => $profile['last_update'] ?? ''] as $label => $value): ?>
                            <?php if ($present($value)): ?><div><dt><?= $h($label); ?></dt><dd><?= $h($value); ?></dd></div><?php endif; ?>
                        <?php endforeach; ?>
                    </dl>
                    <?php foreach ((array) ($profile['phone_contacts'] ?? []) as $contact): ?>
                        <?php $phone = (string) ($contact['value'] ?? ''); $digits = preg_replace('/\D+/', '', $phone) ?? ''; $validPhone = strlen($digits) >= 10 && strlen($digits) <= 13; $international = str_starts_with($digits, '55') ? $digits : '55' . $digits; ?>
                        <div class="contact-row"><span><small><?= $h($contact['label'] ?? 'Telefone'); ?></small><strong><?= $h($phone); ?></strong></span><span><?php if ($validPhone): ?><a href="tel:+<?= $h($international); ?>">Ligar</a><a href="https://wa.me/<?= $h($international); ?>" target="_blank" rel="noopener noreferrer">WhatsApp</a><?php endif; ?><button type="button" data-copy-value="<?= $h($phone); ?>">Copiar</button></span></div>
                    <?php endforeach; ?>
                    <?php foreach ((array) ($profile['emails'] ?? []) as $email): ?><div class="contact-row"><strong><?= $h($email); ?></strong><button type="button" data-copy-value="<?= $h($email); ?>">Copiar</button></div><?php endforeach; ?>
                <?php elseif ($panelKey === 'connection'): ?>
                    <div data-lazy-client-detail="connection" data-url="<?= $h(Url::to('/clientes/detalhe/conexao?login=' . rawurlencode($login))); ?>"><p class="page-description">Carregando detalhes de conexão…</p></div>
                    <?php if (!$technology['verified'] && $present($technology['code'] ?? '')): ?><details><summary>Detalhes técnicos</summary><p>Código informado pelo MkAuth: <?= $h($technology['code']); ?></p></details><?php endif; ?>
                <?php elseif ($panelKey === 'address'): ?>
                    <dl class="detail-list">
                        <?php foreach (['CEP' => $client['cep'] ?? '', 'Logradouro' => $client['endereco'] ?? '', 'Número' => $client['numero'] ?? '', 'Bairro' => $client['bairro'] ?? '', 'Complemento' => $client['complemento'] ?? '', 'Cidade' => $client['cidade'] ?? '', 'Estado' => $client['estado'] ?? '', 'Código IBGE' => $client['city_ibge'] ?? '', 'Ponto de referência' => $profile['reference'] ?? '', 'Coordenadas' => $profile['coordinates'] ?? ''] as $label => $value): ?>
                            <?php if ($present($value)): ?><div><dt><?= $h($label); ?></dt><dd><?= $h($value); ?></dd></div><?php endif; ?>
                        <?php endforeach; ?>
                    </dl>
                    <?php if ($present($profile['coordinates'] ?? '')): ?><button class="button button--ghost" type="button" data-copy-value="<?= $h($profile['coordinates']); ?>">Copiar coordenadas</button><?php endif; ?>
                <?php else: ?>
                    <?php if (!empty($canManageFinancial)): ?><div data-lazy-client-detail="financial" data-url="<?= $h(Url::to('/clientes/detalhe/financeiro?login=' . rawurlencode($login))); ?>"><p class="page-description">Carregando detalhes financeiros…</p></div><?php else: ?><div class="alert alert--warning">Detalhes financeiros disponíveis apenas para usuários autorizados.</div><?php endif; ?>
                    <?php if ($financialTask !== []): ?><dl class="detail-list"><div><dt>Pendência ligada ao processo</dt><dd><?= $h($financialTask['status'] ?? 'Pendente'); ?></dd></div><?php if ($present($financialTask['mkauth_ticket_id'] ?? '')): ?><div><dt>Chamado MkAuth</dt><dd><?= $h($financialTask['mkauth_ticket_id']); ?></dd></div><?php endif; ?></dl><?php endif; ?>
                <?php endif; ?>
            </section>
        </div>
    <?php endforeach; ?>
</main>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/../layouts/app.php';
