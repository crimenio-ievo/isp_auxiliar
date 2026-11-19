<?php

declare(strict_types=1);

use App\Core\Url;

$assetDir = dirname(__DIR__, 3) . '/public/assets';
$v = static fn (string $f): string => (string) max(1, (int) @filemtime($assetDir . '/' . $f));
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$netTab = 'equipamentos';

ob_start();
?>
<link rel="stylesheet" href="<?= $e(Url::asset('css/rede-fibra.css?v=' . $v('css/rede-fibra.css'))); ?>">
<link rel="stylesheet" href="<?= $e(Url::asset('css/rede-cadastro.css?v=' . $v('css/rede-cadastro.css'))); ?>">
<section class="page-header">
    <div>
        <p class="section-heading__eyebrow">Rede</p>
        <h1>Equipamentos de rede</h1>
        <p class="page-description">OLTs que alimentam a telemetria. O equipamento é sempre o elemento do mapa; aqui ficam só os dados de coleta. A coleta nasce desativada.</p>
    </div>
</section>
<?php require __DIR__ . '/_tabs.php'; ?>

<div class="rf rc" id="rc-app"
     data-api="<?= $e(Url::to('/api/rede-equipamentos')); ?>"
     data-api-toggle="<?= $e(Url::to('/api/rede-equipamentos/coleta')); ?>"
     data-api-test="<?= $e(Url::to('/api/rede-equipamentos/testar')); ?>"
     data-map-url="<?= $e(\App\Core\Features::enabled('map') ? Url::to('/mapa') : ''); ?>"
     data-fiber-url="<?= $e(Url::to('/rede-fibra')); ?>"
     data-csrf="<?= $e((string) ($csrf ?? '')); ?>">

    <div class="rc-toolbar">
        <p class="rf-summary" id="rc-summary" aria-live="polite">Carregando…</p>
        <button type="button" class="button" id="rc-new">Nova OLT / configurar coleta</button>
    </div>
    <p class="rc-msg" id="rc-msg" role="status" aria-live="polite" hidden></p>
    <p class="rc-planned" id="rc-server-off" role="status" hidden><strong>Coleta desativada neste servidor</strong> (NET_COLLECTOR_ENABLED). Os equipamentos abaixo guardam a configuração, mas este servidor não contata nenhum deles e "Testar conexão" fica bloqueado.</p>

    <section class="card rc-form" id="rc-form-card" aria-label="Cadastro de equipamento" hidden>
        <form id="rc-form" novalidate>
            <h2 id="rc-form-title">Equipamento</h2>
            <input type="hidden" name="device_id" id="rc-device-id">
            <fieldset class="rc-fieldset" id="rc-node-fs">
                <legend>Elemento do mapa</legend>
                <label class="rc-radio"><input type="radio" name="node_mode" value="existing" checked> OLT que já está no mapa</label>
                <label class="rc-radio"><input type="radio" name="node_mode" value="new"> Criar a OLT agora</label>
                <div class="rc-grid">
                    <label class="rf-field" data-mode="existing"><span>OLT do mapa</span><select name="node_id" id="rc-node-id"></select></label>
                    <label class="rf-field" data-mode="new"><span>Nome da OLT</span><input name="name" maxlength="120" autocomplete="off"></label>
                    <label class="rf-field" data-mode="new"><span>Site (POP/torre)</span><select name="site_id" id="rc-site-id"></select></label>
                    <label class="rf-field" data-mode="new"><span>Portas PON</span><input name="ports_pon" type="number" min="1" max="32" value="4"></label>
                </div>
            </fieldset>
            <div class="rc-grid">
                <label class="rf-field"><span>Modelo do catálogo</span><select name="modelo_id" id="rc-model"></select></label>
                <label class="rf-field"><span>Fabricante (texto livre)</span><input name="fabricante" maxlength="80" autocomplete="off"></label>
                <label class="rf-field"><span>Modelo (texto livre)</span><input name="modelo" maxlength="80" autocomplete="off"></label>
                <label class="rf-field"><span>Série (opcional)</span><input name="serie" maxlength="80" autocomplete="off"></label>
                <label class="rf-field"><span>MAC (opcional)</span><input name="mac" maxlength="17" autocomplete="off"></label>
                <label class="rf-field"><span>Patrimônio (opcional)</span><input name="patrimonio" maxlength="60" autocomplete="off"></label>
                <label class="rf-field"><span>Perfil de coleta</span><select name="perfil" id="rc-perfil"></select></label>
            </div>
            <p class="rc-planned" id="rc-planned" hidden>Perfil ainda não suportado: coleta desativada. A OLT fica registrada no mapa e a coleta só poderá ser configurada quando o perfil existir.</p>
            <div class="rc-grid" id="rc-collect-fields">
                <label class="rf-field"><span>IP de gerência</span><input name="mgmt_host" inputmode="decimal" autocomplete="off" placeholder="192.168.0.10"></label>
                <label class="rf-field"><span>Credencial</span><select name="credential_ref" id="rc-cred"></select></label>
                <label class="rf-field"><span>Intervalo de status (s)</span><input name="intervalo_status_s" type="number" id="rc-i-status"></label>
                <label class="rf-field"><span>Intervalo de potência (s)</span><input name="intervalo_potencia_s" type="number" id="rc-i-power"></label>
            </div>
            <p class="rf-muted" id="rc-cred-note">Só os nomes das credenciais aparecem; o valor nunca é exibido. O catálogo e as credenciais cifradas ficam nas abas Modelos e Credenciais.</p>
            <div class="rc-actions">
                <button type="submit" class="button" id="rc-save">Salvar</button>
                <button type="button" class="button button--secondary" id="rc-cancel">Cancelar</button>
            </div>
        </form>
    </section>

    <section class="card rc-list" aria-label="Lista de equipamentos">
        <div class="rf-tablewrap rc-tablewrap">
            <table class="rf-table rc-table" id="rc-table">
                <thead><tr>
                    <th>Nome</th><th>Site</th><th>Modelo</th><th>IP de gerência</th><th>Perfil</th><th>Coleta</th><th>Última leitura OK</th><th>Saúde</th><th>Ações</th>
                </tr></thead>
                <tbody></tbody>
            </table>
        </div>
        <p class="rf-empty" id="rc-empty" hidden>Nenhuma OLT cadastrada no mapa ainda. Use “Nova OLT / configurar coleta”.</p>
    </section>

    <div class="rf-modal" id="rc-confirm" hidden role="dialog" aria-modal="true" aria-labelledby="rc-confirm-title">
        <div class="rf-modal__box rc-confirm__box">
            <h2 id="rc-confirm-title">Ativar a coleta?</h2>
            <p id="rc-confirm-text"></p>
            <div class="rc-actions">
                <button type="button" class="button" id="rc-confirm-ok">Sim, ativar</button>
                <button type="button" class="button button--secondary" id="rc-confirm-no">Cancelar</button>
            </div>
        </div>
    </div>
</div>
<script src="<?= $e(Url::asset('js/rede-equipamentos.js?v=' . $v('js/rede-equipamentos.js'))); ?>" defer></script>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
