<?php

declare(strict_types=1);

use App\Core\Url;

/** Páginas /rede-credenciais, /rede-modelos e /rede-inventario (mesmo molde; o JS monta tabela e formulário pela seção). */
$assetDir = dirname(__DIR__, 3) . '/public/assets';
$v = static fn (string $f): string => (string) max(1, (int) @filemtime($assetDir . '/' . $f));
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$section = in_array($section ?? '', ['credenciais', 'modelos', 'inventario'], true) ? $section : 'modelos';
$netTab = $section;
$heads = [
    'credenciais' => ['Credenciais de equipamentos', 'Segredos cifrados no banco (libsodium). O valor nunca é exibido de novo: só "definida em DATA por USUÁRIO" e "substituir".', '/api/rede-credenciais', 'Nova credencial'],
    'modelos' => ['Catálogo de modelos', 'Fabricante e modelo têm uma única origem: este catálogo. Portas por família e perfil de telemetria padrão ficam aqui.', '/api/rede-modelos', 'Novo modelo'],
    'inventario' => ['Inventário de equipamentos', 'Equipamento físico: modelo, série, MAC, patrimônio, situação e local atual (elemento do mapa).', '/api/rede-inventario', 'Novo equipamento'],
][$section];

ob_start();
?>
<link rel="stylesheet" href="<?= $e(Url::asset('css/rede-fibra.css?v=' . $v('css/rede-fibra.css'))); ?>">
<link rel="stylesheet" href="<?= $e(Url::asset('css/rede-cadastro.css?v=' . $v('css/rede-cadastro.css'))); ?>">
<section class="page-header">
    <div>
        <p class="section-heading__eyebrow">Rede</p>
        <h1><?= $e($heads[0]); ?></h1>
        <p class="page-description"><?= $e($heads[1]); ?></p>
    </div>
</section>
<?php require __DIR__ . '/_tabs.php'; ?>

<div class="rf rc" id="cg-app" data-section="<?= $e($section); ?>" data-api="<?= $e(Url::to($heads[2])); ?>"
     data-api-delete="<?= $e(Url::to($section === 'credenciais' ? '/api/rede-credenciais/excluir' : '/api/rede-modelos/excluir')); ?>"
     data-api-replace="<?= $e(Url::to('/api/rede-credenciais/substituir')); ?>"
     data-csrf="<?= $e((string) ($csrf ?? '')); ?>">
    <div class="rc-toolbar">
        <p class="rf-summary" id="cg-summary" aria-live="polite">Carregando…</p>
        <button type="button" class="button" id="cg-new"><?= $e($heads[3]); ?></button>
    </div>
    <p class="rc-planned" id="cg-warn" role="status" hidden></p>
    <p class="rc-msg" id="cg-msg" role="status" aria-live="polite" hidden></p>
    <section class="card rc-form" id="cg-form-card" hidden>
        <form id="cg-form" novalidate autocomplete="off">
            <h2 id="cg-form-title"></h2>
            <input type="hidden" name="id" id="cg-id">
            <div class="rc-grid" id="cg-fields"></div>
            <div class="rc-actions">
                <button type="submit" class="button" id="cg-save">Salvar</button>
                <button type="button" class="button button--secondary" id="cg-cancel">Cancelar</button>
            </div>
        </form>
    </section>
    <section class="card rc-list" aria-label="Lista">
        <div class="rf-tablewrap rc-tablewrap">
            <table class="rf-table rc-table" id="cg-table"><thead><tr></tr></thead><tbody></tbody></table>
        </div>
        <p class="rf-empty" id="cg-empty" hidden>Nada cadastrado ainda.</p>
    </section>
    <section class="card rc-list" id="cg-hist" hidden aria-label="Histórico"><h2>Histórico de movimentação</h2><ul id="cg-hist-list"></ul></section>
</div>
<script src="<?= $e(Url::asset('js/rede-catalogo.js?v=' . $v('js/rede-catalogo.js'))); ?>" defer></script>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
