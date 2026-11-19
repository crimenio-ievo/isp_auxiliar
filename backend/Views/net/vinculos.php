<?php

declare(strict_types=1);

use App\Core\Url;

$assetDir = dirname(__DIR__, 3) . '/public/assets';
$v = static fn (string $f): string => (string) max(1, (int) @filemtime($assetDir . '/' . $f));
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$netTab = 'vinculos';

ob_start();
?>
<link rel="stylesheet" href="<?= $e(Url::asset('css/rede-fibra.css?v=' . $v('css/rede-fibra.css'))); ?>">
<link rel="stylesheet" href="<?= $e(Url::asset('css/rede-cadastro.css?v=' . $v('css/rede-cadastro.css'))); ?>">
<section class="page-header">
    <div>
        <p class="section-heading__eyebrow">Rede</p>
        <h1>Vínculos de rede</h1>
        <p class="page-description">ONUs ainda sem cliente, com sugestão pelo MAC do PPPoE. Nada é vinculado sem a sua confirmação; o MkAuth só é lido.</p>
    </div>
</section>
<?php require __DIR__ . '/_tabs.php'; ?>

<div class="rf rc" id="vc-app"
     data-api="<?= $e(Url::to('/api/rede-vinculos/sugestoes')); ?>"
     data-api-onu="<?= $e(Url::to('/api/rede-vinculos/onu')); ?>"
     data-api-onus="<?= $e(Url::to('/api/rede-vinculos/onus')); ?>"
     data-api-modo="<?= $e(Url::to('/api/rede-vinculos/modo')); ?>"
     data-api-remove="<?= $e(Url::to('/api/rede-vinculos/remover')); ?>"
     data-api-search="<?= $e(Url::to('/api/rede-vinculos/clientes')); ?>"
     data-client-url="<?= $e(Url::to('/clientes/detalhe')); ?>"
     data-onu-url="<?= $e(Url::to('/rede-fibra')); ?>"
     data-csrf="<?= $e((string) ($csrf ?? '')); ?>">
    <div class="rc-toolbar">
        <p class="rf-summary" id="vc-summary" aria-live="polite">Carregando…</p>
        <button type="button" class="button button--secondary" id="vc-reload">Atualizar sugestões</button>
    </div>
    <p class="rc-msg" id="vc-msg" role="status" aria-live="polite" hidden></p>
    <p class="rc-planned" id="vc-base" role="status" hidden></p>
    <p class="rc-planned" id="vc-note" hidden></p>
    <section class="card rc-list" aria-label="ONUs sem vínculo">
        <div class="rf-tablewrap rc-tablewrap">
            <table class="rf-table rc-table" id="vc-table">
                <thead><tr><th>ONU</th><th>OLT · PON</th><th>MAC</th><th>Sugestões (cliente · diferença no MAC · confiança)</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
        <p class="rf-empty" id="vc-empty" hidden>Nenhuma ONU sem vínculo com sugestão.</p>
    </section>

    <section class="card rc-list" aria-label="ONUs já vinculadas" id="vb-section">
        <h2>ONUs já vinculadas</h2>
        <p class="rc-planned">Uma ONU/ONT com várias portas LAN pode atender mais de um cliente (cada um disca PPPoE no próprio roteador). Adicione outro cliente a uma ONU e registre o modo; sem escolher, o modo fica “não verificado”.</p>
        <div class="rf-tablewrap rc-tablewrap">
            <table class="rf-table rc-table" id="vb-table">
                <thead><tr><th>ONU</th><th>CTO · porta</th><th>Clientes (modo PPPoE)</th><th>Adicionar cliente</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
        <p class="rf-empty" id="vb-empty" hidden>Nenhuma ONU vinculada ainda.</p>
    </section>
</div>
<script src="<?= $e(Url::asset('js/rede-vinculos.js?v=' . $v('js/rede-vinculos.js'))); ?>" defer></script>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
