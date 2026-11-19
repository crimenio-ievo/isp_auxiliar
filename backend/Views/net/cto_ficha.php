<?php

declare(strict_types=1);

use App\Core\Url;

$assetDir = dirname(__DIR__, 3) . '/public/assets';
$v = static fn (string $f): string => (string) max(1, (int) @filemtime($assetDir . '/' . $f));
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$netTab = 'ctos';
$canEdit = !empty($canEdit);
$netTabsOnly = $canEdit ? null : ['ctos'];

ob_start();
?>
<link rel="stylesheet" href="<?= $e(Url::asset('css/rede-fibra.css?v=' . $v('css/rede-fibra.css'))); ?>">
<link rel="stylesheet" href="<?= $e(Url::asset('css/rede-cadastro.css?v=' . $v('css/rede-cadastro.css'))); ?>">
<section class="page-header">
    <div>
        <p class="section-heading__eyebrow">Rede · CTO</p>
        <h1 id="cf-title">Ficha da CTO</h1>
        <p class="page-description" id="cf-sub">Portas da caixa e clientes vinculados.<?= $canEdit ? '' : ' Seu perfil é somente leitura.'; ?></p>
    </div>
</section>
<?php require __DIR__ . '/_tabs.php'; ?>

<div class="rf rc" id="cf-app"
     data-api="<?= $e(Url::to('/api/rede-ctos/ficha')); ?>"
     data-api-search="<?= $e(Url::to('/api/rede-vinculos/clientes')); ?>"
     data-api-bind="<?= $e(Url::to('/api/rede-vinculos/cto')); ?>"
     data-api-remove="<?= $e(Url::to('/api/rede-vinculos/remover')); ?>"
     data-list-url="<?= $e(Url::to('/rede-ctos')); ?>"
     data-map-url="<?= $e(Url::to('/mapa')); ?>"
     data-client-url="<?= $e(Url::to('/clientes/detalhe')); ?>"
     data-onu-url="<?= $e(Url::to('/rede-fibra')); ?>"
     data-cto="<?= (int) ($ctoId ?? 0); ?>"
     data-can-edit="<?= $canEdit ? '1' : '0'; ?>"
     data-csrf="<?= $e((string) ($csrf ?? '')); ?>">

    <div class="rc-toolbar">
        <p class="rf-summary" id="cf-summary" aria-live="polite">Carregando…</p>
        <div class="rc-actions rc-actions--inline">
            <a class="button button--secondary" href="<?= $e(Url::to('/rede-ctos')); ?>">Voltar às CTOs</a>
            <a class="button" id="cf-map" href="<?= $e(Url::to('/mapa')); ?>">Ver no mapa</a>
        </div>
    </div>
    <p class="rc-msg" id="cf-msg" role="status" aria-live="polite" hidden></p>

    <section class="card rc-form" id="cf-picker" aria-label="Escolher cliente" hidden>
        <h2 id="cf-picker-title">Vincular cliente</h2>
        <label class="rf-field"><span>Buscar no MkAuth por nome, login ou CPF/CNPJ (somente leitura)</span><input type="search" id="cf-q" autocomplete="off" minlength="3" placeholder="Digite ao menos 3 caracteres"></label>
        <ul class="rc-results" id="cf-results" aria-live="polite"></ul>
        <div class="rc-actions"><button type="button" class="button button--secondary" id="cf-picker-close">Fechar</button></div>
    </section>

    <section class="card rc-list" aria-label="Portas da CTO">
        <div class="rf-tablewrap rc-tablewrap">
            <table class="rf-table rc-table" id="cf-table">
                <thead><tr><th>Porta</th><th>Situação</th><th>Cliente</th><th>ONU</th><th>Ações</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
        <p class="rf-empty" id="cf-empty" hidden>Esta CTO ainda não tem portas.</p>
    </section>

    <div class="rf-modal" id="cf-confirm-box" hidden role="dialog" aria-modal="true" aria-labelledby="cf-confirm-title">
        <div class="rf-modal__box rc-confirm__box">
            <h2 id="cf-confirm-title">Confirmar</h2>
            <p id="cf-confirm-text"></p>
            <div class="rc-actions">
                <button type="button" class="button" id="cf-confirm-ok">Confirmar</button>
                <button type="button" class="button button--secondary" id="cf-confirm-no">Cancelar</button>
            </div>
        </div>
    </div>
</div>
<script src="<?= $e(Url::asset('js/rede-cto-ficha.js?v=' . $v('js/rede-cto-ficha.js'))); ?>" defer></script>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
