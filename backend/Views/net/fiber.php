<?php

declare(strict_types=1);

use App\Core\Url;

$assetDir = dirname(__DIR__, 3) . '/public/assets';
$v = static fn (string $f): string => (string) max(1, (int) @filemtime($assetDir . '/' . $f));
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

ob_start();
?>
<link rel="stylesheet" href="<?= $e(Url::asset('css/rede-fibra.css?v=' . $v('css/rede-fibra.css'))); ?>">
<link rel="stylesheet" href="<?= $e(Url::asset('css/rede-cadastro.css?v=' . $v('css/rede-cadastro.css'))); ?>">
<section class="page-header">
    <div>
        <p class="section-heading__eyebrow">Telemetria</p>
        <h1>Rede de fibra</h1>
        <p class="page-description">OLT, PONs e ONUs lidas pela coleta automática. Somente leitura, exceto "Definir CTO" na ficha da ONU (liga a ONU a uma porta de CTO).</p>
    </div>
</section>
<?php $netTab = 'fibra'; require __DIR__ . '/_tabs.php'; ?>

<div class="rf" id="rf-app"
     data-api-tree="<?= $e(Url::to('/api/rede-fibra')); ?>"
     data-api-onu="<?= $e(Url::to('/api/rede-fibra/onu')); ?>"
     data-api-events="<?= $e(Url::to('/api/rede-fibra/eventos')); ?>"
     data-client-url="<?= $e(Url::to('/clientes/detalhe')); ?>"
     data-cto-url="<?= $e(Url::to('/rede-ctos/ficha')); ?>"
     data-api-ficha="<?= $e(Url::to('/api/rede-vinculos/onu-ficha')); ?>"
     data-api-resumo="<?= $e(Url::to('/api/rede-vinculos/clientes-resumo')); ?>"
     data-api-ctos="<?= $e(Url::to('/api/rede-vinculos/ctos-proximas')); ?>"
     data-api-bind="<?= $e(Url::to('/api/rede-vinculos/onu-cto')); ?>"
     data-map-url="<?= $e(Url::to('/mapa')); ?>"
     data-csrf="<?= $e((string) ($csrf ?? '')); ?>">

    <section class="card rf-filters" aria-label="Filtros">
        <label class="rf-field"><span>Site</span><select id="rf-f-site"><option value="">Todos</option></select></label>
        <label class="rf-field"><span>Equipamento</span><select id="rf-f-olt"><option value="">Todos</option></select></label>
        <label class="rf-field"><span>PON</span><select id="rf-f-pon"><option value="">Todas</option></select></label>
        <label class="rf-field"><span>Estado</span>
            <select id="rf-f-status">
                <option value="">Todos</option><option value="online">Online</option><option value="offline">Offline</option><option value="unknown">Desconhecido</option><option value="sem_resposta">Sem resposta (desatualizado)</option>
            </select>
        </label>
        <label class="rf-check"><input id="rf-f-down24" type="checkbox"> Caíram em 24 h</label>
        <label class="rf-check"><input id="rf-f-novinc" type="checkbox"> Sem vínculo</label>
        <details class="rf-cols" id="rf-cols"><summary>Colunas</summary><div class="rf-cols__list" id="rf-cols-list"></div></details>
        <button type="button" class="button" id="rf-refresh">Atualizar</button>
    </section>

    <div class="rf-health" id="rf-health" role="alert" hidden></div>
    <p class="rf-summary" id="rf-summary" aria-live="polite">Carregando…</p>
    <p class="rf-note" id="rf-note" hidden></p>

    <div class="rf-layout">
        <section class="card rf-tree" aria-label="Árvore site, equipamento, PON e ONU">
            <div class="rf-tablewrap"><table class="rf-table" id="rf-table"><thead></thead><tbody></tbody></table></div>
            <p class="rf-empty" id="rf-empty" hidden>Nenhuma ONU com esses filtros.</p>
        </section>

        <aside class="card rf-panel" id="rf-panel" aria-label="Ficha da ONU" hidden>
            <div class="rf-panel__head">
                <div><h2 id="rf-p-title">ONU</h2><p id="rf-p-sub" class="rf-muted"></p></div>
                <button type="button" class="rf-x" id="rf-p-close" aria-label="Fechar ficha">×</button>
            </div>
            <div id="rf-p-body"></div>
        </aside>
    </div>

    <section class="card rf-events" id="rf-events" aria-label="Eventos recentes">
        <div class="rf-events__head">
            <h2>Eventos recentes</h2>
            <div class="rf-periods" id="rf-ev-periods"></div>
        </div>
        <p class="rf-legend" id="rf-ev-info">Carregando…</p>
        <ul class="rf-events__list" id="rf-ev-list"></ul>
    </section>

    <div class="rf-modal" id="rf-modal" hidden role="dialog" aria-modal="true" aria-label="Gráfico de sinal ampliado">
        <div class="rf-modal__box">
            <div class="rf-panel__head"><h2>Sinal da ONU no período</h2><button type="button" class="rf-x" id="rf-m-close" aria-label="Fechar">×</button></div>
            <div id="rf-m-chart" class="rf-chart rf-chart--big"></div>
        </div>
    </div>
</div>
<script src="<?= $e(Url::asset('js/rede-cto-picker.js?v=' . $v('js/rede-cto-picker.js'))); ?>" defer></script>
<script src="<?= $e(Url::asset('js/rede-fibra.js?v=' . $v('js/rede-fibra.js'))); ?>" defer></script>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
