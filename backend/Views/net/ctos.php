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
        <p class="section-heading__eyebrow">Rede</p>
        <h1>CTOs</h1>
        <p class="page-description">Caixas de atendimento do mapa: localização, capacidade e ocupação. O que é cadastrado aqui aparece no mapa, e vice-versa.<?= $canEdit ? '' : ' Seu perfil é somente leitura.'; ?></p>
    </div>
</section>
<?php require __DIR__ . '/_tabs.php'; ?>

<div class="rf rc" id="ct-app"
     data-api="<?= $e(Url::to('/api/rede-ctos')); ?>"
     data-api-delete="<?= $e(Url::to('/api/rede-ctos/excluir')); ?>"
     data-api-import="<?= $e(Url::to('/api/rede-ctos/importar')); ?>"
     data-map-url="<?= $e(\App\Core\Features::enabled('map') ? Url::to('/mapa') : ''); ?>"
     data-ficha-url="<?= $e(Url::to('/rede-ctos/ficha')); ?>"
     data-can-edit="<?= $canEdit ? '1' : '0'; ?>"
     data-focus="<?= $e((string) ($focusCto ?? '')); ?>"
     data-csrf="<?= $e((string) ($csrf ?? '')); ?>">

    <div class="rc-toolbar">
        <p class="rf-summary" id="ct-summary" aria-live="polite">Carregando…</p>
        <div class="rc-actions rc-actions--inline">
            <label class="rf-field rc-search"><span class="visually-hidden">Filtrar</span><input type="search" id="ct-filter" placeholder="Filtrar por nome ou origem" autocomplete="off"></label>
            <?php if ($canEdit): ?>
                <button type="button" class="button button--secondary" id="ct-import-open">Importar CSV / KML / KMZ</button>
                <button type="button" class="button" id="ct-new">Nova CTO</button>
            <?php endif; ?>
        </div>
    </div>
    <p class="rc-msg" id="ct-msg" role="status" aria-live="polite" hidden></p>

    <?php if ($canEdit): ?>
    <section class="card rc-form" id="ct-form-card" aria-label="Cadastro de CTO" hidden>
        <form id="ct-form" novalidate>
            <h2 id="ct-form-title">Nova CTO</h2>
            <input type="hidden" name="id" id="ct-id">
            <div class="rc-grid">
                <label class="rf-field"><span>Nome</span><input name="nome" maxlength="120" autocomplete="off"></label>
                <label class="rf-field"><span>Capacidade (portas)</span><input name="capacidade" type="number" min="1" max="144" value="16"></label>
                <label class="rf-field" id="ct-origin-field"><span>Origem (opcional)</span><select name="origem" id="ct-origem"></select></label>
            </div>
            <div class="rc-grid">
                <label class="rf-field rc-wide"><span>Coordenadas — cole “lat, lng” do Google Maps</span><input name="coordenadas" id="ct-coord" inputmode="decimal" autocomplete="off" placeholder="-20.845553, -42.802360"></label>
                <div class="rf-field rc-geo"><span>No campo, no celular</span><button type="button" class="rc-btn" id="ct-geo">Usar minha localização</button></div>
            </div>
            <p class="rc-hint" id="ct-coord-hint" aria-live="polite">Faixa válida: latitude -90 a 90, longitude -180 a 180.</p>
            <label class="rf-field"><span>Observação</span><textarea name="observacao" rows="2" maxlength="2000"></textarea></label>
            <div class="rc-actions">
                <button type="submit" class="button" id="ct-save">Salvar</button>
                <button type="button" class="button button--secondary" id="ct-cancel">Cancelar</button>
            </div>
        </form>
    </section>

    <section class="card rc-form" id="ct-import-card" aria-label="Importar CTOs por CSV, KML ou KMZ" hidden>
        <h2>Importar CTOs por CSV, KML ou KMZ</h2>
        <p class="rf-muted">CSV: colunas <strong>nome, lat, lng, capacidade, observacao</strong> (cabeçalho opcional; separador vírgula ou ponto e vírgula). KML/KMZ (Google My Maps ou Earth): cada ponto vira uma CTO (nome, coordenadas, descrição; capacidade se houver um dado "capacidade"); linhas e polígonos são ignorados. Nada é gravado antes de você confirmar a prévia; a gravação é tudo ou nada.</p>
        <div class="rc-actions">
            <input type="file" id="ct-file" accept=".csv,.kml,.kmz,text/csv,text/plain,application/vnd.google-earth.kml+xml,application/vnd.google-earth.kmz">
            <button type="button" class="button" id="ct-analyze" disabled>Analisar arquivo</button>
            <button type="button" class="button button--secondary" id="ct-import-cancel">Fechar</button>
        </div>
        <div id="ct-preview" hidden>
            <p class="rf-summary" id="ct-preview-summary"></p>
            <div class="rf-tablewrap rc-tablewrap"><table class="rf-table rc-table rc-table--static" id="ct-preview-table">
                <thead><tr><th>Linha</th><th>Nome</th><th>Lat</th><th>Lng</th><th>Cap.</th><th>Situação</th><th>Detalhe</th><th>Importar</th></tr></thead>
                <tbody></tbody>
            </table></div>
            <div class="rc-actions"><button type="button" class="button" id="ct-confirm" disabled>Confirmar importação</button></div>
        </div>
        <div id="ct-report" hidden></div>
    </section>
    <?php endif; ?>

    <section class="card rc-list" aria-label="Lista de CTOs">
        <div class="rf-tablewrap rc-tablewrap">
            <table class="rf-table rc-table" id="ct-table">
                <thead><tr><th>Nome</th><th>Origem</th><th>Coordenadas</th><th>Capacidade</th><th>Portas</th><th>Clientes</th><th>Ações</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
        <p class="rf-empty" id="ct-empty" hidden>Nenhuma CTO cadastrada.</p>
    </section>

    <div class="rf-modal" id="ct-confirm-box" hidden role="dialog" aria-modal="true" aria-labelledby="ct-confirm-title">
        <div class="rf-modal__box rc-confirm__box">
            <h2 id="ct-confirm-title">Confirmar</h2>
            <p id="ct-confirm-text"></p>
            <div class="rc-actions">
                <button type="button" class="button" id="ct-confirm-ok">Confirmar</button>
                <button type="button" class="button button--secondary" id="ct-confirm-no">Cancelar</button>
            </div>
        </div>
    </div>
</div>
<script src="<?= $e(Url::asset('js/rede-ctos.js?v=' . $v('js/rede-ctos.js'))); ?>" defer></script>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
