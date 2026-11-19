// jsdom: tela "Rede de fibra" (HTML renderizado pelo PHP + public/assets/js/rede-fibra.js). Fixtures de TESTE com o
// mesmo formato da API (as chaves sao conferidas em tests/Feature/NetFiberReaderSmoke.php).
const {execFileSync} = require('child_process');
const fs = require('fs');
const path = require('path');
const {JSDOM, VirtualConsole} = require('../map/node_modules/jsdom');
const root = path.resolve(__dirname, '../..');
const html = execFileSync('php', [path.join(__dirname, 'render_page.php')], {encoding: 'utf8'});
const js = fs.readFileSync(path.join(root, 'public/assets/js/rede-fibra.js'), 'utf8');
let falhas = 0, total = 0;
const ok = (c, m) => { total++; if (!c) { falhas++; console.log('[FALHA] ' + m); } };
const wait = ms => new Promise(r => setTimeout(r, ms));

const onu = (id, idx, pon, status, rx, band, extra) => Object.assign({id, pon_index: pon, onu_index: idx, mac: '00:2E:C7:00:00:0' + id, status, status_desde: '2026-11-13 08:00:00', desatualizado: false,
  rx_dbm: rx, rx_band: band, rx_medido_em: '2026-11-13 12:00:00', tx_dbm: rx === null ? null : 2.4, temp_c: rx === null ? null : 30.5, distancia_m: status === 'online' ? 22638 : null,
  distancia_origem: status === 'online' ? 'estimada pelo RTT' : null, cliente: null, clientes: [], n_clientes: 0, cto: null, quedas_24h: 0, ultima_queda_em: null}, extra || {});
const tree = {
  gerado_em: '2026-11-13 12:00:00',
  dispositivos: [{id: 1, nome: 'OLT-<b>X</b>', ativo: true, intervalo_status_s: 600, ultima_tentativa: '2026-11-13 12:00:00', ultima_leitura_ok: '2026-11-13 12:00:00', ultimo_resultado: 'ok', tecnologia: 'epon', modelo: {}, ip: '198.51.100.1', tipo_no: 'olt', site: {id: 8, nome: 'Site-A', tipo: 'pop'}, saude: {estado: 'ok', minutos: 0, limite_s: 1200}, pons: [
    {pon_index: 1, label: 'PON 1', total: 3, online: 2, onus: [onu(1, 1, 1, 'online', -20.0, 'ok', {quedas_24h: 1, ultima_queda_em: '2026-11-13 10:00:00'}), onu(2, 2, 1, 'online', -26.0, 'atencao'), onu(3, 3, 1, 'offline', -21.0, 'ok')]},
    {pon_index: 2, label: 'PON 2', total: 1, online: 1, onus: [onu(4, 1, 2, 'online', null, 'unknown')]},
    {pon_index: 3, label: 'PON 3', total: 0, online: 0, onus: []}]},
    {id: 2, nome: 'SW-1', ativo: true, intervalo_status_s: 600, ultima_tentativa: '2026-11-13 12:00:00', ultima_leitura_ok: '2026-11-13 12:00:00', ultimo_resultado: 'ok', tecnologia: null, modelo: {}, ip: '198.51.100.2', tipo_no: 'switch', site: null, saude: {estado: 'ok', minutos: 0, limite_s: 1200}, pons: []}],
  resumo: {onus: 4, online: 3, offline: 1, desconhecido: 0, atencao: 1, critico: 0, saturado: 0, sem_sinal: 1, quedas_24h: 1, sem_vinculo: 4, sem_resposta: 0},
  limiares: {saturado_dbm: -8, atencao_dbm: -25, critico_dbm: -27, desvio_db: 3},
  fontes: {quedas_poll: true, quedas_log: false, vinculo_cliente: false, cto: false, sinal_na_olt: false}
};
const outage = {onu_id: 1, onu_identifier_mascarado: '00:2E:C7:xx:xx:xx', source: 'log', precisao: 'exata', category: 'queda', maintenance_reason: null, started_at: '2026-11-13 10:00:00', ended_at: '2026-11-13 10:10:00', open: false, duration_s: 600, downtime_in_period_s: 600, cause: 'energia', reason: 'Queda de energia (Dying Gasp)'};
const sheetOnu = () => { const o = onu(1, 1, 1, 'online', -20.0, 'ok'); delete o.quedas_24h; delete o.ultima_queda_em; return o; };
const sheet = (over) => Object.assign({
  onu: Object.assign(sheetOnu(), {baseline_dbm: -20.5, baseline_desde: '2026-11-12 00:00:00', baseline_amostras: 5, olt_rx_dbm: null, olt_rx_texto: 'ainda não lido'}),
  vizinhos: {mediana_dbm: -26.0, quantidade: 3, diferenca_db: 6.0},
  serie: {pontos: [{t: '2026-11-07 12:00:00', rx: -20.0}, {t: '2026-11-10 12:00:00', rx: -22.5}, {t: '2026-11-13 11:00:00', rx: -20.0}], de: '2026-11-06 12:00:00', ate: '2026-11-13 12:00:00'},
  periodo: {kind: '24h', from: '2026-11-12 12:00:00', to: '2026-11-13 12:00:00'}, quedas: [outage], quedas_total: {quedas: 1, em_aberto: 0, downtime_no_periodo_s: 600, por_motivo: {energia: 1}, do_log: 1, do_poll: 0, log_sem_onu_cadastrada: 0},
  quedas_serie: [outage, Object.assign({}, outage, {started_at: '2026-11-11 08:00:00', ended_at: null, open: true, duration_s: null, reason: 'Sem aviso de energia', cause: 'sem_aviso_energia'})],
  ultimas_quedas: [outage], estavel: false, estavel_texto: null, limiares: tree.limiares, fontes: {quedas_poll: true, quedas_log: true},
  cliente: null, clientes: [], n_clientes: 0, cliente_texto: 'sem vínculo', cto: null, cto_texto: 'ainda não lido'}, over || {});

// Mesmas listas de chaves de tests/Feature/NetFiberReaderSmoke.php: se a API mudar, os dois testes devem mudar juntos.
const K = {
  tree: 'gerado_em,dispositivos,resumo,limiares,fontes', device: 'id,nome,ativo,intervalo_status_s,ultima_tentativa,ultima_leitura_ok,ultimo_resultado,pons,tecnologia,tipo_no,modelo,ip,site,saude',
  pon: 'pon_index,label,onus,total,online', onu: 'id,pon_index,onu_index,mac,status,status_desde,desatualizado,rx_dbm,rx_band,rx_medido_em,tx_dbm,temp_c,distancia_m,distancia_origem,cliente,clientes,n_clientes,cto,quedas_24h,ultima_queda_em',
  sheet: 'onu,vizinhos,serie,periodo,quedas,quedas_total,quedas_serie,ultimas_quedas,estavel,estavel_texto,limiares,fontes,cliente,clientes,n_clientes,cliente_texto,cto,cto_texto',
  sheetOnu: 'baseline_dbm,baseline_desde,baseline_amostras,olt_rx_dbm,olt_rx_texto',
  outage: 'onu_id,onu_identifier_mascarado,source,precisao,category,maintenance_reason,started_at,ended_at,open,duration_s,downtime_in_period_s,cause,reason'
};
const keys = o => Object.keys(o).sort().join(',');
const K2 = s => s.split(',').sort().join(',');
const evGroup = (over) => Object.assign({dispositivo_id: 1, dispositivo_nome: 'OLT-<b>X</b>', pon_index: 1, pon_label: 'PON 1', inicio: '2026-11-13 11:34:01', fim: '2026-11-13 11:35:05', quantidade: 2, em_aberto: 0, motivo: 'energia',
  todas_dying_gasp: true, correlacionada: true, precisao: 'exata', texto: 'queda de energia em 2 ONUs da PON 1',
  onus: [{onu_id: 1, onu_index: 6, mac: '00:2E:C7:00:00:01', started_at: '2026-11-13 11:34:01', ended_at: '2026-11-13 11:35:05', open: false, duration_s: 64, cause: 'energia', source: 'log'},
         {onu_id: 2, onu_index: 7, mac: '00:2E:C7:00:00:02', started_at: '2026-11-13 11:34:01', ended_at: '2026-11-13 11:35:05', open: false, duration_s: 64, cause: 'energia', source: 'log'}]}, over || {});
const events = (groups) => ({gerado_em: '2026-11-13 12:00:00', periodo: {kind: '24h', from: '2026-11-12 12:00:00', to: '2026-11-13 12:00:00'}, grupos: groups, fontes: {quedas_poll: true, quedas_log: true}});
const EVK = 'gerado_em,periodo,grupos,fontes', EVG = 'dispositivo_id,dispositivo_nome,pon_index,pon_label,inicio,fim,quantidade,em_aberto,motivo,todas_dying_gasp,correlacionada,precisao,texto,onus', EVO = 'onu_id,onu_index,mac,started_at,ended_at,open,duration_s,cause,source';

function document_activeIsSort(b) { const a = b.d.activeElement; return !!a && a.classList.contains('rf-sort'); }
async function boot(opts) {
  opts = opts || {};
  const errs = [], vc = new VirtualConsole(); vc.on('jsdomError', e => errs.push(String(e.detail && e.detail.message || e.message))); vc.on('error', e => errs.push(String(e)));
  const calls = [];
  const dom = new JSDOM(html, {runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole: vc, url: opts.url || 'http://localhost/rede-fibra'});
  const w = dom.window;
  const style = w.document.createElement('style'); style.textContent = fs.readFileSync(path.join(root, 'public/assets/css/rede-fibra.css'), 'utf8'); w.document.head.appendChild(style);
  const treeData = opts.tree ? opts.tree(JSON.parse(JSON.stringify(tree))) : tree;
  w.fetch = (u) => { calls.push(u); const status = opts.status && opts.status(u); const body = status ? {ok: false, message: 'Acesso negado.'} : {ok: true, data: u.indexOf('/api/rede-vinculos/onu-ficha') !== -1 ? (opts.ficha ? opts.ficha(u) : {clientes: [], mkauth_ok: true}) : u.indexOf('/api/rede-vinculos/clientes-resumo') !== -1 ? (opts.resumo ? opts.resumo(u) : {clientes: {}}) : u.indexOf('/eventos') !== -1 ? (opts.events ? opts.events(u) : events([evGroup()])) : u.indexOf('/onu') !== -1 ? (opts.sheet ? opts.sheet(u) : sheet()) : treeData};
    return Promise.resolve({ok: !status, status: status || 200, json: () => Promise.resolve(body)}); };
  if (opts.storage) { Object.keys(opts.storage).forEach(k => w.localStorage.setItem(k, opts.storage[k])); }
  w.eval(fs.readFileSync(path.join(root, 'public/assets/js/rede-cto-picker.js'), 'utf8')); w.eval(js); await wait(40);
  const d = w.document, q = s => d.querySelector(s), qa = s => [...d.querySelectorAll(s)];
  return {w, d, q, qa, calls, errs, ev: (el, type, init) => el.dispatchEvent(new w.Event(type, Object.assign({bubbles: true}, init || {})))};
}

(async () => {
  ok(keys(tree) === K2(K.tree) && keys(tree.dispositivos[0]) === K2(K.device) && keys(tree.dispositivos[0].pons[0]) === K2(K.pon) && keys(tree.dispositivos[0].pons[0].onus[0]) === K2(K.onu), 'fixture da arvore com as mesmas chaves da API');
  ok(keys(events([evGroup()])) === K2(EVK) && keys(evGroup()) === K2(EVG) && keys(evGroup().onus[0]) === K2(EVO), 'fixture de eventos com as mesmas chaves da API');
  ok(keys(sheet()) === K2(K.sheet) && keys(sheet().onu) === K2(K.onu.replace(',quedas_24h,ultima_queda_em', '') + ',' + K.sheetOnu) && keys(outage) === K2(K.outage), 'fixture da ficha com as mesmas chaves da API');
  let t = await boot();
  const sel0 = (id, v) => { t.q(id).value = v; t.ev(t.q(id), 'change'); };
  ok(t.q('#rf-summary').textContent.indexOf('4 ONUs · 3 online · 1 offline') === 0, 'resumo no topo');
  ok(t.qa('.rf-row--site').length === 2 && t.qa('.rf-row--eqp').length === 2 && t.qa('.rf-row--pon').length === 3 && t.qa('.rf-row--onu').length === 4, 'arvore: 2 sites (um "Sem site"), 2 equipamentos, 3 PONs (inclusive vazia), 4 ONUs');
  ok(t.qa('.rf-row--site').map(x => x.textContent.split('  ·')[0]).join('|') === 'POP Site-A|Sem site', 'rotulos de site: tipo do no pai ou "Sem site"');
  ok(t.qa('.rf-row--eqp')[0].textContent.indexOf('OLT OLT-<b>X</b>') === 0 && t.qa('.rf-row--eqp')[0].textContent.indexOf('EPON') !== -1, 'equipamento: rotulo do tipo do no (OLT) e tecnologia EPON');
  ok(t.qa('.rf-row--eqp')[1].textContent.indexOf('Switch SW-1') === 0 && t.qa('.rf-row--eqp')[1].textContent.indexOf('EPON') === -1, 'a tela nao assume que todo equipamento e OLT (Switch, sem tecnologia)');
  ok(t.q('.rf-row--site').nextElementSibling.classList.contains('rf-row--eqp') && t.qa('.rf-row--eqp')[0].nextElementSibling.classList.contains('rf-row--pon'), 'ordem: site > equipamento > PON > ONU');
  ok(t.q('.rf-row--eqp').textContent.indexOf('OLT-<b>X</b>') !== -1 && !t.q('.rf-row--eqp b'), 'nome do equipamento como texto, sem HTML');
  const heads = t.qa('#rf-table thead th').map(x => x.textContent);
  ok(heads.join('|') === 'ONU|Estado|Sinal|Distância|Cliente|CTO|Quedas 24 h|MAC', 'colunas padrao (Cliente e CTO visiveis): ' + heads.join('|'));
  const row1 = t.qa('.rf-row--onu')[0].textContent;
  ok(row1.indexOf('-20,00') !== -1 && row1.indexOf('≈ 22.64 km') !== -1 && row1.indexOf('00:2E:C7:00:00:01') !== -1, 'linha da ONU: sinal, distancia estimada e MAC');
  ok(t.qa('.rf-row--onu')[3].textContent.indexOf('sem leitura') !== -1, 'ONU sem leitura de sinal');
  ok(!/simulad|fict[ií]c|fake/i.test(t.d.body.textContent), 'nenhum texto de dado simulado');
  let linked = await boot({url:'http://localhost/rede-fibra?onu=2'});
  ok(linked.calls.some(u=>/\/onu\?id=2&periodo=7d$/.test(u)) && !linked.q('#rf-panel').hidden, 'deep link abre ficha');
  let invalidLink = await boot({url:'http://localhost/rede-fibra?onu=abc'});
  ok(invalidLink.q('#rf-note').textContent==='ONU inválida.' && !invalidLink.calls.some(u=>u.includes('/onu?')), 'deep link invalido nao consulta ficha');
  let missingLink = await boot({url:'http://localhost/rede-fibra?onu=999',status:u=>u.includes('/onu?')?404:0});
  ok(missingLink.q('#rf-p-body').textContent==='Acesso negado.' && missingLink.errs.length===0, 'deep link inexistente mostra erro sem quebrar script');
  ok(t.q('#rf-note').textContent.indexOf('Quedas do log da OLT ainda não importadas') !== -1, 'aviso quando o log da OLT ainda nao foi importado');

  // Filtros
  sel0('#rf-f-site', '0'); ok(t.qa('.rf-row--site').length === 1 && t.q('.rf-row--site').textContent.indexOf('Sem site') !== -1 && t.qa('.rf-row--onu').length === 0, 'filtro por site: "Sem site"');
  sel0('#rf-f-site', '8'); ok(t.qa('.rf-row--site').length === 1 && t.qa('.rf-row--eqp').length === 1 && t.qa('.rf-row--onu').length === 4, 'filtro por site: Site-A');
  ok(t.qa('#rf-f-pon option').length === 4, 'PONs do filtro acompanham o site'); sel0('#rf-f-site', '');
  ok(t.qa('#rf-f-site option').map(o => o.textContent).join('|') === 'Todos|Site-A|Sem site', 'seletor de site preenchido');
  t.q('.rf-row--site .rf-toggle').click(); ok(t.qa('.rf-row--eqp').length === 1 && t.qa('.rf-row--pon').length === 0, 'recolher o site esconde equipamentos e PONs'); t.q('.rf-row--site .rf-toggle').click();
  const sel = (id, v) => { t.q(id).value = v; t.ev(t.q(id), 'change'); };
  sel('#rf-f-status', 'offline'); ok(t.qa('.rf-row--onu').length === 1 && t.q('.rf-row--onu').textContent.indexOf('Offline') !== -1, 'filtro estado offline');
  sel('#rf-f-status', ''); t.q('#rf-f-down24').checked = true; t.ev(t.q('#rf-f-down24'), 'change');
  ok(t.qa('.rf-row--onu').length === 1 && t.qa('.rf-row--pon').length === 1, 'filtro "cairam em 24 h" (esconde PONs sem ONU)');
  t.q('#rf-f-down24').checked = false; t.ev(t.q('#rf-f-down24'), 'change');
  t.q('#rf-f-novinc').checked = true; t.ev(t.q('#rf-f-novinc'), 'change'); ok(t.qa('.rf-row--onu').length === 4, 'filtro "sem vinculo" (todas, pois nao ha vinculo)');
  t.q('#rf-f-novinc').checked = false; t.ev(t.q('#rf-f-novinc'), 'change');
  sel('#rf-f-pon', '2'); ok(t.qa('.rf-row--onu').length === 1 && t.qa('.rf-row--pon').length === 1, 'filtro PON');
  sel('#rf-f-pon', ''); ok(!t.q('#rf-f-q'), 'busca MAC local removida');
  ok(t.qa('#rf-f-olt option').length === 3 && t.qa('#rf-f-pon option').length === 4, 'seletores de equipamento e PON preenchidos');
  sel('#rf-f-status', 'unknown'); ok(t.qa('.rf-row--onu').length === 0 && !t.q('#rf-empty').hidden, 'sem resultado: mensagem de vazio'); sel('#rf-f-status', ''); ok(t.q('#rf-empty').hidden, 'mensagem de vazio some');

  // Colunas escolhiveis (persistem)
  const boxes = t.qa('#rf-cols-list input');
  ok(boxes.length === 11 && t.qa('#rf-cols-list label').map(x => x.textContent.trim()).join('|') === 'Estado|Sinal|Tx (dBm)|Temp. (°C)|Distância|Cliente|CTO|Login|Bairro/Localidade|Quedas 24 h|MAC', 'menu de colunas: Login e Bairro/Localidade opcionais; Cliente e CTO continuam; Sinal e Faixa unificados'); const macBox = boxes[10]; macBox.checked = false; t.ev(macBox, 'change');
  ok(t.qa('#rf-table thead th').map(x => x.textContent).indexOf('MAC') === -1 && t.w.localStorage.getItem('rf.cols').indexOf('mac') === -1, 'coluna MAC oculta e salva');
  boxes[2].checked = true; t.ev(boxes[2], 'change'); ok(t.qa('#rf-table thead th').map(x => x.textContent).indexOf('Tx (dBm)') !== -1, 'coluna Tx habilitada');

  // Recolher
  t.q('.rf-row--pon .rf-toggle').click(); ok(t.qa('.rf-row--onu').length === 1, 'recolher PON esconde as ONUs');
  t.q('.rf-row--pon .rf-toggle').click(); t.q('.rf-row--eqp .rf-toggle').click(); ok(t.qa('.rf-row--pon').length === 0, 'recolher OLT esconde as PONs'); t.q('.rf-row--eqp .rf-toggle').click();

  // Horarios: a API vem em UTC e a tela mostra America/Sao_Paulo (UTC-3).
  ok(t.q('.rf-row--eqp').textContent.indexOf('última leitura ok: 13/11 09:00') !== -1, 'arvore: ultima leitura 12:00 UTC = 09:00 em Brasilia');
  ok(t.q('#rf-summary').textContent.indexOf('gerado em 13/11 09:00') !== -1, 'resumo: gerado em horario de Brasilia');
  ok(t.qa('.rf-row--onu')[0].textContent.indexOf('13/11 07:00') !== -1, 'coluna Quedas 24 h: 10:00 UTC = 07:00');

  const groupRow=t.q('.rf-row--eqp');
  groupRow.querySelector('.rf-group-label').click();
  ok(!t.q('#rf-panel').hidden && t.q('#rf-p-body').textContent.includes('CPU: ainda não lido') && t.q('#rf-p-body a').target==='_blank', 'painel equipamento usa dados e link externo');
  groupRow.querySelector('.rf-group-label').click(); ok(t.q('#rf-panel').hidden, 'segundo clique fecha grupo');
  t.q('.rf-row--pon .rf-group-label').click();
  ok(t.q('#rf-p-body button[disabled]').title==='leitura sob demanda ainda não disponível' && t.q('#rf-p-body').textContent.includes('Mediana'), 'painel PON mediana e atualização desativada');
  t.q('#rf-p-close').click();
  ok(t.qa('.rf-signal').length===4 && t.qa('.rf-group-counts').length>0, 'barras e contagens na árvore');
  // Ficha
  t.q('.rf-row--onu').click(); await wait(30);
  ok(t.calls.some(u => /\/api\/rede-fibra\/onu\?id=1&periodo=7d$/.test(u)), 'abrir ficha chama a API com periodo 7d');
  ok(!t.q('#rf-panel').hidden && t.q('#rf-p-title').textContent === 'ONU 1 · PON 1 · ONU ou ONT: a identificar' && t.q('#rf-p-sub').textContent.indexOf('00:2E:C7:00:00:01') === 0, 'painel lateral com a ONU');
  const body = t.q('#rf-p-body').textContent;
  ok(body.indexOf('ainda não lido') !== -1 && body.indexOf('sem vínculo') !== -1 && body.indexOf('≈ 22,64 km (estimada pelo RTT)') !== -1 && body.indexOf('-26,00 dBm (3 ONUs, diferença +6,00 dB)') !== -1, 'ficha: sinal na OLT/CTO "ainda nao lido", cliente "sem vinculo", mediana dos vizinhos');
  ok(t.qa('.rf-ruler__z').length === 3 && t.qa('.rf-ruler__mark').length === 3 && t.q('.rf-ruler__scale').textContent.indexOf('-30') !== -1 && t.q('.rf-ruler__scale').textContent.indexOf('-12') !== -1, 'regua de -30 a -12 com 3 faixas e 3 marcas');
  ok(t.qa('.rf-ruler__z').map(z => z.textContent).join('|') === 'Crítico|Atenção|Normal' && t.q('.rf-ruler__mark--now') && t.q('.rf-ruler__mark--ref') && t.q('.rf-ruler__mark--med'), 'faixas e formas diferenciadas');
  ok(t.q('#rf-p-chart svg').getAttribute('viewBox') === '0 0 420 220' && t.q('#rf-p-chart').textContent.includes('Ref.') && t.q('#rf-p-chart').textContent.includes('Vizinhos'), 'grafico compacto com referencias rotuladas');
  ok(keys(sheet().serie) === K2('pontos,de,ate'), 'contrato da série contém pontos e limites');
  const zs = t.qa('.rf-ruler__z').map(z => parseFloat(z.style.width)); ok(Math.abs(zs[0] - 16.67) < 0.1 && Math.abs(zs[1] - 11.11) < 0.1 && Math.abs(zs[0] + zs[1] + zs[2] - 100) < 0.01, 'larguras das faixas seguem -27 e -25');
  const mk = t.q('.rf-ruler__mark:not(.rf-ruler__mark--ref):not(.rf-ruler__mark--med)'); ok(Math.abs(parseFloat(mk.style.left) - 55.56) < 0.1, 'marca do sinal atual em -20 dBm');
  ok(t.qa('#rf-p-chart svg').length === 1 && t.qa('#rf-p-chart .rf-outage').length === 2 && t.qa('#rf-p-chart path.rf-line').length === 1, 'grafico com linha e as quedas como faixas verticais');
  ok(/Queda de energia \(Dying Gasp\)/.test(t.q('#rf-p-body .rf-outages').textContent) && t.q('#rf-p-body .rf-outages').textContent.indexOf('13/11 07:00') !== -1 && t.q('#rf-p-body .rf-outages').textContent.indexOf('13/11 07:10') !== -1 && t.q('#rf-p-body .rf-outages').textContent.indexOf('10:00') === -1 && t.q('#rf-p-body .rf-outages').textContent.indexOf('10 min') !== -1, 'lista: inicio, retorno, duracao e motivo');
  ok(!/fibra/i.test(t.q('#rf-p-body').textContent), 'ficha nunca afirma fibra');
  ok(t.qa('#rf-p-body .rf-outages').length === 2, 'lista do periodo e lista das ultimas quedas');

  ok(t.q('#rf-p-sub').textContent.indexOf('desde 13/11 05:00') !== -1, 'ficha: status desde 08:00 UTC = 05:00 em Brasilia');
  ok(t.q('#rf-p-body').textContent.indexOf('Última leitura13/11 09:00') !== -1, 'ficha: ultima leitura em horario de Brasilia');
  ok([...t.qa('#rf-p-body .rf-legend')].some(x => x.textContent.indexOf('Período (horário de Brasília): 12/11 09:00 a 13/11 09:00') === 0), 'periodo mostrado em horario de Brasilia');
  const dayLabels = t.qa('#rf-p-chart svg text').map(x => x.textContent).filter(x => /^\d\d\/\d\d$/.test(x));
  ok(dayLabels[0] === '07/11' && dayLabels[dayLabels.length - 1] === '13/11' && dayLabels.length === 7, 'eixo: dias em Brasilia (meia-noite de Brasilia = 03:00 UTC): ' + dayLabels.join(','));
  ok(t.q('#rf-p-chart .rf-outage title').textContent.indexOf('Queda 13/11 07:00') === 0, 'faixa da queda com dica em horario de Brasilia');
  // Cursor do grafico
  const ov = t.q('#rf-p-chart .rf-overlay'), svg = t.q('#rf-p-chart svg'); svg.getBoundingClientRect = () => ({left: 0, width: 420, top: 0, height: 220});
  const move = x => ov.dispatchEvent(new t.w.MouseEvent('pointermove', {clientX: x, bubbles: true}));
  move(38 + ((420 - 38 - 104) * (parse('2026-11-10 20:00:00') - parse('2026-11-06 12:00:00')) / (parse('2026-11-13 12:00:00') - parse('2026-11-06 12:00:00'))));
  const tip = t.q('#rf-p-chart .rf-tip'); ok(!tip.hidden && /10\/11 17:00 · -22,50 dBm$/.test(tip.textContent), 'cursor em horario de Brasilia (UTC 20:00 = 17:00): ' + tip.textContent);
  move(38 + ((420 - 38 - 104) * (parse('2026-11-12 10:00:00') - parse('2026-11-06 12:00:00')) / (parse('2026-11-13 12:00:00') - parse('2026-11-06 12:00:00'))));
  ok(/em queda \(Sem aviso de energia\)/.test(tip.textContent), 'cursor dentro de uma queda em aberto mostra o motivo (' + tip.textContent + ')');
  move(38); ok(/sem leitura/.test(tip.textContent), 'cursor antes da primeira leitura: sem leitura'); ov.dispatchEvent(new t.w.MouseEvent('pointerleave', {bubbles: true})); ok(tip.hidden, 'cursor some ao sair');

  // Ampliar
  t.qa('#rf-p-body .rf-chartbar button')[0].click(); ok(!t.q('#rf-modal').hidden && t.qa('#rf-m-chart svg').length === 1 && t.q('#rf-m-chart svg').getAttribute('viewBox').indexOf('400') !== -1, 'ampliar abre o grafico grande');
  t.q('#rf-m-close').click(); ok(t.q('#rf-modal').hidden, 'fechar o grafico ampliado');
  t.q('#rf-modal').hidden = false; t.d.dispatchEvent(new t.w.KeyboardEvent('keydown', {key: 'Escape', bubbles: true})); ok(t.q('#rf-modal').hidden, 'Esc fecha o grafico ampliado');

  // Periodos
  const btn = txt => t.qa('#rf-p-body .rf-periods button').filter(b => b.textContent === txt)[0];
  btn('1 h').click(); await wait(30); ok(/periodo=1h$/.test(t.calls[t.calls.length - 1]), 'periodo 1 h');
  btn('1 semana').click(); await wait(30); ok(/periodo=7d$/.test(t.calls[t.calls.length - 1]), 'periodo 1 semana');
  const beforeCustom = t.calls.length;
  btn('Personalizado').click(); await wait(10); ok(!!t.q('#rf-c-from') && !!t.q('#rf-c-to') && t.calls.length === beforeCustom, 'personalizado só mostra campos, sem consulta');
  t.q('#rf-c-from').value = '2026-11-12T08:00'; t.q('#rf-c-to').value = '2026-11-13T09:30'; [...t.qa('.rf-custom button')][0].click(); await wait(30);
  ok(/periodo=custom&de=2026-11-12T11%3A00&ate=2026-11-13T12%3A30$/.test(t.calls[t.calls.length - 1]), 'personalizado: 08:00 e 09:30 de Brasilia enviados como 11:00 e 12:30 UTC (' + t.calls[t.calls.length - 1] + ')');
  t.q('#rf-c-from').value = '2026-11-12T23:30'; t.q('#rf-c-to').value = ''; [...t.qa('.rf-custom button')][0].click(); await wait(30);
  ok(/periodo=custom&de=2026-11-13T02%3A30$/.test(t.calls[t.calls.length - 1]), 'personalizado: 23:30 de Brasilia vira 02:30 UTC do dia seguinte, sem fim (' + t.calls[t.calls.length - 1] + ')');
  ok(t.q('#rf-c-from').value === '2026-11-12T23:30', 'campo mostra o que o usuario digitou (Brasilia)');
  function parse(s) { return new Date(s.replace(' ', 'T') + 'Z').getTime(); }
  let blankCustom = await boot(); blankCustom.q('.rf-row--onu').click(); await wait(30);
  blankCustom.qa('#rf-p-body .rf-periods button').find(b => b.textContent === 'Personalizado').click();
  blankCustom.qa('.rf-row--onu')[1].click(); await wait(30);
  ok(/id=2&periodo=7d$/.test(blankCustom.calls.at(-1)), 'nova ONU com custom vazio volta a semana');
  blankCustom.q('#rf-p-close').click();
  ok(blankCustom.w.getComputedStyle(blankCustom.q('#rf-panel')).display === 'none' && !blankCustom.q('.rf-layout').classList.contains('has-panel'), 'fechar remove painel do layout por estilo computado');

  // Estavel e em aberto
  let e = await boot({sheet: () => sheet({quedas: [], quedas_serie: [], ultimas_quedas: [], estavel: true, estavel_texto: 'Sem queda registrada no período (estável).', quedas_total: {quedas: 0, em_aberto: 0, downtime_no_periodo_s: 0, por_motivo: {}, do_log: 0, do_poll: 0, log_sem_onu_cadastrada: 0}, serie: {pontos: [], de: '2026-11-06 12:00:00', ate: '2026-11-13 12:00:00'}})});
  e.q('.rf-row--onu').click(); await wait(30);
  ok(e.q('#rf-p-body').textContent.indexOf('Sem queda registrada no período (estável).') !== -1 && e.qa('#rf-p-body .rf-outages').length === 0 && e.q('#rf-p-chart').textContent.indexOf('Sem leituras no período') !== -1, 'sem registro no periodo = estavel; grafico sem leituras avisa');
  let a = await boot({sheet: () => sheet({quedas: [Object.assign({}, outage, {open: true, ended_at: null, duration_s: null})]})});
  a.q('.rf-row--onu').click(); await wait(30); ok(a.q('#rf-p-body .rf-outages').textContent.indexOf('em aberto') !== -1, 'queda em aberto na lista');

  // Saude da coleta (CP2)
  ok(t.q('#rf-health').hidden && t.q('#rf-health').children.length === 0 && t.q('#rf-table').textContent.indexOf('Sem resposta') === -1, 'coleta saudavel: sem aviso e sem "Sem resposta"');
  let h1 = await boot({tree: d => { d.dispositivos[0].saude = {estado: 'atrasada', minutos: 25, limite_s: 1200}; d.dispositivos[0].ultima_leitura_ok = '2026-11-13 11:35:00'; return d; }});
  const hb = h1.q('#rf-health');
  ok(!hb.hidden && hb.getAttribute('role') === 'alert' && hb.children.length === 1 && hb.textContent.indexOf('OLT OLT-<b>X</b> sem resposta há 25 min: os estados abaixo podem estar desatualizados') === 0, 'aviso: "sem resposta ha 25 min" com o rotulo do equipamento (' + hb.textContent + ')');
  ok(hb.textContent.indexOf('última leitura ok: 13/11 08:35, horário de Brasília') !== -1, 'aviso: ultima leitura em horario de Brasilia (11:35 UTC = 08:35)');
  ok(!/causa|rede caiu|energia|fibra|cabo/i.test(hb.textContent), 'aviso nao afirma a causa');
  let h2 = await boot({tree: d => { d.dispositivos[0].saude = {estado: 'falha', minutos: 3, limite_s: 1200}; d.dispositivos[0].ultimo_resultado = 'timeout'; return d; }});
  ok(h2.q('#rf-health').textContent.indexOf('sem resposta há 3 min') !== -1 && h2.q('#rf-health').textContent.indexOf('resultado da última coleta: timeout') !== -1, 'aviso quando o ultimo resultado nao e ok (mesmo com leitura recente)');
  let h3 = await boot({tree: d => { d.dispositivos[1].saude = {estado: 'sem_leitura', minutos: null, limite_s: 1200}; d.dispositivos[1].ultima_leitura_ok = null; return d; }});
  ok(h3.q('#rf-health').textContent.indexOf('Switch SW-1: ainda sem nenhuma leitura registrada') === 0 && h3.q('#rf-health').children.length === 1, 'aviso para equipamento que nunca teve leitura (rotulo pelo tipo do no)');
  let h4 = await boot({tree: d => { d.dispositivos[0].saude = {estado: 'atrasada', minutos: 90, limite_s: 1200}; d.dispositivos[1].saude = {estado: 'falha', minutos: 1, limite_s: 1200}; return d; }});
  ok(h4.q('#rf-health').children.length === 2 && h4.q('#rf-health').textContent.indexOf('sem resposta há 1 h 30 min') !== -1, 'um aviso por equipamento; 90 min = 1 h 30 min');
  let st = await boot({tree: d => { const on = d.dispositivos[0].pons[0].onus; on[2].desatualizado = true; on[0].desatualizado = true; d.resumo.sem_resposta = 2; d.dispositivos[0].saude = {estado: 'atrasada', minutos: 25, limite_s: 1200}; return d; }});
  const rows = st.qa('.rf-row--onu');
  ok(rows[0].textContent.indexOf('Sem resposta') !== -1 && rows[0].textContent.indexOf('último: Online') !== -1 && rows[2].textContent.indexOf('Sem resposta') !== -1 && rows[2].textContent.indexOf('último: Offline') !== -1, 'ONU desatualizada aparece como "Sem resposta" (com o ultimo estado), nao como Online/Offline');
  ok(rows[1].textContent.indexOf('Online') !== -1 && rows[1].textContent.indexOf('Sem resposta') === -1, 'ONU nao desatualizada continua com o estado normal');
  ok(st.q('#rf-summary').textContent.indexOf('sem resposta: 2') !== -1, 'resumo mostra quantas ONUs estao sem resposta');
  const sel2 = (v) => { st.q('#rf-f-status').value = v; st.ev(st.q('#rf-f-status'), 'change'); };
  sel2('sem_resposta'); ok(st.qa('.rf-row--onu').length === 2, 'filtro "Sem resposta" mostra so as desatualizadas');
  sel2('offline'); ok(st.qa('.rf-row--onu').length === 0, 'filtro Offline nao inclui ONU desatualizada (offline so quando a OLT respondeu)');
  sel2('online'); ok(st.qa('.rf-row--onu').length === 2, 'filtro Online exclui as desatualizadas');

  // Eventos recentes (CP3)
  ok(t.calls.some(u => /\/api\/rede-fibra\/eventos\?periodo=24h$/.test(u)), 'a pagina pede os eventos de 24 h');
  const li = t.qa('#rf-ev-list li.rf-ev');
  ok(li.length === 1 && li[0].classList.contains('rf-ev--multi') && li[0].querySelector('.rf-ev__when').textContent === '13/11 08:34', 'evento correlacionado, hora 11:34 UTC = 08:34 em Brasilia');
  ok(li[0].querySelector('.rf-ev__text').textContent === 'Queda de energia em 2 ONUs da PON 1 · OLT-<b>X</b>' && !li[0].querySelector('.rf-ev__text b'), 'texto "queda de energia em N ONUs da PON X" (texto puro, sem HTML)');
  ok(li[0].querySelector('.rf-ev__meta').textContent === 'retorno 13/11 08:35 · precisão exata · todas com Dying Gasp', 'retorno, precisao e Dying Gasp: ' + li[0].querySelector('.rf-ev__meta').textContent);
  ok(t.qa('#rf-ev-list .rf-ev__onus button').map(b => b.textContent).join('|') === 'ONU 6|ONU 7' && t.q('#rf-ev-info').textContent.indexOf('1 evento(s) de queda no período') === 0 && t.q('#rf-ev-info').textContent.indexOf('manutenção e boot ficam de fora') !== -1, 'ONUs do evento e resumo');
  ok(!/fibra|cabo|rompimento/i.test(t.q('#rf-events').textContent), 'eventos nao afirmam a causa fisica');
  const callsBefore = t.calls.length; t.qa('#rf-ev-list .rf-ev__onus button')[1].click(); await wait(30);
  ok(/\/onu\?id=2&periodo=/.test(t.calls[t.calls.length - 1]) && t.calls.length === callsBefore + 1, 'clicar numa ONU do evento abre a ficha');
  t.qa('#rf-ev-periods button').filter(b => b.textContent === '1 h')[0].click(); await wait(30);
  ok(/\/eventos\?periodo=1h$/.test(t.calls[t.calls.length - 1]) && t.qa('#rf-ev-periods button.is-on')[0].textContent === '1 h', 'periodo 1 h dos eventos');
  t.qa('#rf-ev-periods button').filter(b => b.textContent === '1 semana')[0].click(); await wait(30);
  ok(/\/eventos\?periodo=7d$/.test(t.calls[t.calls.length - 1]), 'periodo 1 semana dos eventos');
  let ev0 = await boot({events: () => events([])});
  ok(ev0.qa('#rf-ev-list li').length === 0 && ev0.q('#rf-ev-info').textContent.indexOf('Nenhuma queda no período') === 0 && ev0.q('#rf-ev-info').textContent.indexOf('Sem registro = estável') !== -1 && ev0.q('#rf-ev-info').textContent.indexOf('(12/11 09:00 a 13/11 09:00, horário de Brasília)') !== -1, 'sem eventos: estavel, em horario de Brasilia');
  let ev1 = await boot({events: () => events([evGroup({quantidade: 1, correlacionada: false, todas_dying_gasp: false, motivo: 'sem_aviso_energia', texto: 'queda sem aviso de energia em 1 ONU da PON 2', em_aberto: 1, fim: null, precisao: 'aproximada (ciclo de status)', onus: [evGroup().onus[0]]})])});
  const m1 = ev1.q('#rf-ev-list li');
  ok(!m1.classList.contains('rf-ev--multi') && m1.querySelector('.rf-ev__text').textContent.indexOf('Queda sem aviso de energia em 1 ONU da PON 2') === 0 && m1.querySelector('.rf-ev__meta').textContent === '1 em aberto · precisão aproximada (ciclo de status)', 'evento unico em aberto, sem afirmar energia');
  const many = []; for (let i = 0; i < 25; i++) { many.push(evGroup({inicio: '2026-11-13 10:' + String(10 + i).padStart(2, '0') + ':00'})); }
  let ev2 = await boot({events: () => events(many)});
  ok(ev2.qa('#rf-ev-list li.rf-ev').length === 5 && !!ev2.qa('#rf-ev-list button').find(b => b.textContent === 'Mostrar mais'), 'eventos começam com cinco e botão');
  ev2.qa('#rf-ev-list button').find(b => b.textContent === 'Mostrar mais').click();
  ok(ev2.qa('#rf-ev-list li.rf-ev').length === 20 && ev2.q('#rf-ev-list').lastElementChild.textContent === 'Mostrando os 20 mais recentes de 25.', 'mostrar mais limita a 20 com aviso');
  let ev3 = await boot({events: () => events([evGroup()]), status: u => u.indexOf('/eventos') !== -1 ? 500 : 0});
  ok(ev3.q('#rf-ev-info').textContent === 'Acesso negado.' && ev3.qa('.rf-row--onu').length === 4, 'falha nos eventos nao quebra a arvore');

  // Cores/colunas (CP-1..CP-3): paleta única, barra de sinal, coluna Sinal, preferência migrada
  const cssText = fs.readFileSync(path.join(root, 'public/assets/css/rede-fibra.css'), 'utf8');
  const cssVars = {}; (cssText.match(/\.rf \{[^}]*\}/) || [''])[0].replace(/(--rf-[a-z-]+):\s*(#[0-9A-Fa-f]{6})/g, (m, k, v) => { cssVars[k] = v.toUpperCase(); });
  const fillOf = cls => { const m = cssText.match(new RegExp('(?:^|[\\s,])\\.' + cls + '(?=[\\s,{])[^{]*\\{[^}]*background:\\s*var\\((--rf-[a-z-]+)\\)', 'm')); return m ? cssVars[m[1]] : null; };
  ok(fillOf('rf-fill--ok') === '#3B82F6' && fillOf('rf-fill--atencao') === '#F2A33A' && fillOf('rf-fill--critico') === '#E5484D' && fillOf('rf-fill--offline') === '#B42318', 'CSS: preenchimento forte por faixa (normal azul, atencao ambar, critico vermelho, offline vermelho escuro)');
  ok(fillOf('rf-fill--saturado') === '#F2A33A' && fillOf('rf-fill--unknown') === cssVars['--rf-track'], 'CSS: saturado usa a cor de atencao; sem leitura usa o trilho');
  ok(!/#e2f3e8|#fbe4e2|#fff4cc|#e8a7a1|#f1d27a|#8cc9a0|#f6d8d5|#fbefc7|#e6f4ea|#d64545/i.test(cssText), 'CSS: nenhum tom antigo solto de faixa');
  const outsideVars = cssText.replace(/\.rf \{[^}]*\}/, ''); ok(!/#(E5484D|F2A33A|3B82F6|B42318|1D4ED8|9A4D00)/i.test(outsideVars), 'CSS: cores das faixas so existem no bloco de variaveis (uma paleta)');
  const lum = h => { const c = [1, 3, 5].map(i => parseInt(h.substr(i, 2), 16) / 255).map(x => x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4)); return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]; };
  const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
  const pairsOk = ['ok', 'warn', 'crit', 'off', 'none'].every(k => ratio(cssVars['--rf-' + k + '-ink'], cssVars['--rf-' + k + '-bg']) >= 4.5) && ratio(cssVars['--rf-online-ink'], cssVars['--rf-online-bg']) >= 4.5;
  ok(pairsOk, 'contraste texto/fundo de pilula >= 4,5:1 em todas as faixas e no estado Online');
  const tc = await boot({tree: tr => { tr.dispositivos[0].pons[0].onus[1].rx_dbm = -29; tr.dispositivos[0].pons[0].onus[1].rx_band = 'critico'; return tr; }});
  const fills = tc.qa('.rf-row--onu').map(r => { const f = r.querySelector('.rf-signal__fill'); return f ? f.className.replace('rf-signal__fill ', '') + ':' + Math.round(parseFloat(f.style.width)) : null; });
  ok(fills.join('|') === 'rf-fill--ok:' + fills[0].split(':')[1] + '|rf-fill--critico:' + fills[1].split(':')[1] + '|rf-fill--unknown:0|rf-fill--unknown:0', 'barra: preenchimento por faixa (normal, critico; offline e sem leitura ficam no trilho): ' + fills.join('|'));
  const w0 = fills.map(x => parseInt(x.split(':')[1], 10)); ok(w0[0] >= 67 && w0[0] <= 100 && w0[1] >= 4 && w0[1] < 33.4 && w0[3] === 0, 'barra: normal ocupa 67-100%, critico 0-33%, sem leitura vazia');
  const tcs = await boot(); const fa = tcs.qa('.rf-row--onu .rf-signal__fill'); ok(fa[1].classList.contains('rf-fill--atencao') && parseFloat(fa[1].style.width) > 33.3 && parseFloat(fa[1].style.width) < 66.7, 'barra: atencao no estagio do meio');
  const rows0 = tcs.qa('.rf-row--onu'), c0 = rows0[0].querySelectorAll('td')[2].querySelector('.rf-signal-cell');
  ok(c0 && [...c0.children].map(x => x.className.split(' ')[0]).join('|') === 'rf-signal|rf-signal__val|rf-pill' && c0.children[1].textContent === '-20,00 dBm' && c0.children[2].textContent === 'Normal', 'coluna Sinal: barra, valor em dBm e palavra da faixa, nesta ordem');
  const c3 = rows0[3].querySelectorAll('td')[2], f3 = c3.querySelector('.rf-signal__fill');
  ok(f3.style.width === '0%' && c3.textContent === 'sem leitura' && !c3.querySelector('.rf-pill') && !c3.hasAttribute('data-sort-value'), 'ONU sem leitura: barra vazia e "sem leitura", sem pilula nem valor de ordenacao');
  ok(rows0[0].querySelectorAll('td')[2].dataset.sortValue === '-20' && rows0[1].querySelectorAll('td')[2].dataset.sortValue === '-26' && tcs.q('#rf-table thead th[data-col="sinal"]').dataset.sortType === 'number', 'coluna Sinal pronta para ordenar pelo valor numerico (data-sort-value), nao pela palavra');
  ok(!tcs.qa('#rf-table thead th').some(x => x.textContent === 'Faixa' || x.textContent === 'Sinal (dBm)') && tcs.qa('.rf-row--onu').every(r => r.querySelectorAll('td').length === 8), 'sem colunas Faixa/Sinal (dBm) separadas');
  ok(tcs.qa('#rf-table thead th').filter(x => x.textContent === 'Cliente' || x.textContent === 'CTO').length === 2, 'Cliente e CTO visiveis por padrao');
  // CP-1 (offline) e CP-2 (ordenacao)
  const cOff = rows0[2].querySelectorAll('td')[2], fOff = cOff.querySelector('.rf-signal__fill');
  ok(cOff.textContent === 'último: -21,00 dBm' && !cOff.querySelector('.rf-pill') && fOff.className.indexOf('rf-fill--unknown') !== -1 && fOff.style.width === '0%' && cOff.querySelector('.rf-signal__val').classList.contains('rf-signal__val--stale') && !cOff.hasAttribute('data-sort-value'), 'ONU offline: barra cinza (sem preenchimento de faixa), "último: -21,00 dBm" apagado, sem pilula e sem valor de ordenacao');
  const stl = await boot({tree: tr => { tr.dispositivos[0].pons[0].onus[0].desatualizado = true; return tr; }});
  const cStl = stl.qa('.rf-row--onu')[0].querySelectorAll('td')[2];
  ok(cStl.textContent === 'último: -20,00 dBm' && !cStl.querySelector('.rf-pill') && cStl.querySelector('.rf-signal__fill').style.width === '0%', 'ONU "sem resposta" (desatualizado) segue a mesma regra do offline');
  const sortTree = tr => { const p = tr.dispositivos[0].pons[0]; p.onus = [onu(1, 1, 1, 'online', -20.0, 'ok', {quedas_24h: 1, ultima_queda_em: '2026-11-13 10:00:00'}), onu(2, 2, 1, 'online', -29.0, 'critico', {quedas_24h: 3, ultima_queda_em: '2026-11-13 10:00:00'}), onu(3, 3, 1, 'offline', -21.0, 'ok', {quedas_24h: 2, ultima_queda_em: '2026-11-13 10:00:00'}), onu(5, 4, 1, 'online', null, 'unknown'), onu(6, 5, 1, 'online', -15.0, 'ok', {distancia_m: 9000})]; p.total = 5; return tr; };
  const order = b => b.qa('.rf-row--onu').slice(0, 5).map(r => r.querySelector('td').textContent.replace('ONU ', '')).join('|');
  const hdr = (b, k) => b.q('#rf-table thead th[data-col="' + k + '"]'), clickH = (b, k) => hdr(b, k).querySelector('.rf-sort').click();
  const so = await boot({tree: sortTree}); const skeleton = () => so.qa('.rf-row--site,.rf-row--eqp,.rf-row--pon').map(r => r.textContent).join('~');
  const sk0 = skeleton(), pon2row0 = so.qa('.rf-row--onu')[5].textContent;
  ok(order(so) === '1|2|3|4|5' && hdr(so, 'sinal').getAttribute('aria-sort') === 'none' && hdr(so, 'status').getAttribute('aria-sort') === 'none' && !hdr(so, 'sinal').textContent.match(/[▲▼↕]/) && !hdr(so, 'sinal').hasAttribute('data-sorted'), 'ordenacao: ordem normal inicial, aria-sort="none" nas colunas ordenaveis, texto do cabecalho limpo');
  ok(!!hdr(so, 'sinal').querySelector('button') && !!hdr(so, 'status').querySelector('button') && !!hdr(so, 'dist').querySelector('button') && !!hdr(so, 'quedas').querySelector('button') && !!hdr(so, 'mac').querySelector('button'), 'cabecalhos Estado, Sinal, Distancia, Quedas e MAC sao botoes (teclado)');
  clickH(so, 'sinal'); ok(order(so) === '2|1|5|3|4' && hdr(so, 'sinal').getAttribute('aria-sort') === 'ascending' && so.w.localStorage.getItem('rf.sort') === '{"k":"sinal","d":"asc"}', 'Sinal crescente por dBm numerico; offline e sem leitura no fim: ' + order(so));
  ok(skeleton() === sk0 && so.qa('.rf-row--onu')[5].textContent === pon2row0, 'ordenacao so dentro de cada PON: site, equipamento e PON mantem a ordem; ONU da PON 2 continua na PON 2');
  clickH(so, 'sinal'); ok(order(so) === '5|1|2|3|4' && hdr(so, 'sinal').getAttribute('aria-sort') === 'descending', 'Sinal decrescente: offline e sem leitura continuam no fim: ' + order(so));
  ok(document_activeIsSort(so), 'foco volta ao botao do cabecalho depois de reordenar');
  clickH(so, 'sinal'); ok(order(so) === '1|2|3|4|5' && hdr(so, 'sinal').getAttribute('aria-sort') === 'none' && so.w.localStorage.getItem('rf.sort') === null, 'terceiro clique volta a ordem normal e limpa a preferencia');
  clickH(so, 'status'); ok(order(so) === '3|2|1|5|4', 'Estado: offline, critico, atencao, normal (depois sem leitura): ' + order(so));
  clickH(so, 'dist'); ok(order(so) === '5|1|2|4|3' && hdr(so, 'status').getAttribute('aria-sort') === 'none', 'Distancia crescente numerica, offline (sem distancia) no fim; so uma coluna ativa: ' + order(so));
  clickH(so, 'dist'); ok(order(so) === '1|2|4|5|3', 'Distancia decrescente: sem distancia continua no fim: ' + order(so));
  clickH(so, 'quedas'); clickH(so, 'quedas'); ok(order(so) === '2|3|1|4|5', 'Quedas decrescente numerica: ' + order(so));
  clickH(so, 'mac'); ok(order(so) === '1|2|3|4|5', 'MAC crescente (texto)'); clickH(so, 'mac'); ok(order(so) === '5|4|3|2|1', 'MAC decrescente (texto)');
  const sp = await boot({tree: sortTree, storage: {'rf.sort': '{"k":"sinal","d":"desc"}'}});
  ok(order(sp) === '5|1|2|3|4' && sp.q('#rf-table thead th[data-col="sinal"]').getAttribute('aria-sort') === 'descending', 'a ordenacao escolhida persiste no navegador');
  for (const bad of ['{xx', '{"k":"inexistente","d":"asc"}', '{"k":"sinal","d":"x"}', '42', 'null']) { const sb = await boot({tree: sortTree, storage: {'rf.sort': bad}}); ok(order(sb) === '1|2|3|4|5' && sb.errs.length === 0, 'preferencia de ordenacao invalida ignorada sem erro: ' + bad); }
  const sh = await boot({tree: sortTree, storage: {'rf.sort': '{"k":"sinal","d":"asc"}', 'rf.cols': JSON.stringify(['status', 'mac'])}});
  ok(order(sh) === '1|2|3|4|5', 'ordenacao por coluna oculta e ignorada');
  // Migracao da preferencia salva
  const mig = async (saved, extra) => { const b = await boot({storage: Object.assign({'rf.cols': saved, 'rf.cols.vinc': '1'}, extra || {})}); return {heads: b.qa('#rf-table thead th').map(x => x.textContent).join('|'), stored: b.w.localStorage.getItem('rf.cols'), errs: b.errs}; };
  const mg1 = await mig(JSON.stringify(['status', 'rx', 'band', 'dist', 'cliente', 'cto', 'quedas', 'mac']));
  ok(mg1.heads === 'ONU|Estado|Sinal|Distância|Cliente|CTO|Quedas 24 h|MAC' && mg1.stored === JSON.stringify(['status', 'sinal', 'dist', 'cliente', 'cto', 'quedas', 'mac']) && mg1.errs.length === 0, 'migracao: rx+band viram uma coluna Sinal (preferencia de Cliente/CTO mantida) e a preferencia e regravada: ' + mg1.heads);
  const mg2 = await mig(JSON.stringify(['status', 'band', 'mac']));
  ok(mg2.heads === 'ONU|Estado|Sinal|MAC', 'migracao: quem tinha so Faixa visivel passa a ver Sinal');
  const mg3 = await mig(JSON.stringify(['status', 'mac']));
  ok(mg3.heads === 'ONU|Estado|MAC', 'migracao: quem nao tinha Sinal nem Faixa continua sem Sinal');
  const mg4 = await mig('{isso nao e json'); ok(mg4.heads === 'ONU|Estado|Sinal|Distância|Cliente|CTO|Quedas 24 h|MAC' && mg4.errs.length === 0, 'preferencia corrompida: usa o padrao sem erro');
  const mg5 = await mig(JSON.stringify(['status', 'rx', 'xyz', 'sinal'])); ok(mg5.heads === 'ONU|Estado|Sinal', 'migracao: chave desconhecida descartada e sem Sinal duplicado');
  const mgnew = await mig(JSON.stringify(['status', 'sinal', 'cliente'])); ok(mgnew.heads === 'ONU|Estado|Sinal|Cliente', 'preferencia ja no formato novo permanece');
  // Migracao unica "mostrar Cliente e CTO" (decisao do usuario, 2026-11-19): preferencia salva sem a marca rf.cols.vinc
  const vm = await boot({storage: {'rf.cols': JSON.stringify(['status', 'sinal', 'mac'])}});
  ok(vm.qa('#rf-table thead th').map(x => x.textContent).join('|') === 'ONU|Estado|Sinal|Cliente|CTO|MAC' && vm.w.localStorage.getItem('rf.cols') === JSON.stringify(['status', 'sinal', 'mac', 'cliente', 'cto']) && vm.w.localStorage.getItem('rf.cols.vinc') === '1' && vm.errs.length === 0, 'migracao unica: preferencia antiga sem Cliente/CTO passa a mostra-las e grava a marca');
  const vmBoxes = vm.qa('#rf-cols-list input'), vmCli = vmBoxes[5]; vmCli.checked = false; vm.ev(vmCli, 'change');
  ok(JSON.parse(vm.w.localStorage.getItem('rf.cols')).indexOf('cliente') === -1, 'depois da migracao o usuario pode ocultar Cliente de novo');
  const vm2 = await boot({storage: {'rf.cols': vm.w.localStorage.getItem('rf.cols'), 'rf.cols.vinc': vm.w.localStorage.getItem('rf.cols.vinc')}});
  ok(vm2.qa('#rf-table thead th').map(x => x.textContent).join('|') === 'ONU|Estado|Sinal|CTO|MAC', 'escolha posterior respeitada: Cliente continua oculta na proxima visita');
  const vm3 = await boot({storage: {'rf.cols': JSON.stringify(['status', 'rx', 'cto'])}});
  ok(vm3.qa('#rf-table thead th').map(x => x.textContent).join('|') === 'ONU|Estado|Sinal|Cliente|CTO' && vm3.w.localStorage.getItem('rf.cols') === JSON.stringify(['status', 'sinal', 'cto', 'cliente']), 'migracao unica combinada com a antiga (rx vira Sinal) e sem CTO duplicada');
  const vm4 = await boot(); const vm4b = vm4.qa('#rf-cols-list input'); vm4b[6].checked = false; vm4.ev(vm4b[6], 'change');
  const vm5 = await boot({storage: {'rf.cols': vm4.w.localStorage.getItem('rf.cols'), 'rf.cols.vinc': vm4.w.localStorage.getItem('rf.cols.vinc')}});
  ok(vm4.w.localStorage.getItem('rf.cols.vinc') === '1' && vm5.qa('#rf-table thead th').every(x => x.textContent !== 'CTO'), 'sem preferencia anterior: ocultar CTO grava a marca e a escolha e respeitada (sem reaparecer)');

  // CP-3: escala da regua nas posicoes reais e rotulos sem sobreposicao
  const rulerOf = async (now, med, ref) => { const sh = sheet(); sh.onu.rx_dbm = now; sh.vizinhos.mediana_dbm = med; sh.onu.baseline_dbm = ref; const b = await boot({sheet: () => sh}); b.q('.rf-row--onu').click(); await wait(40); return b; };
  const boxesOf = b => b.qa('.rf-ruler__label').map(l => { const w = l.textContent.length * 6.6, c = parseFloat(l.style.left); return {t: l.textContent, lo: c - w / 2, hi: c + w / 2, top: l.classList.contains('rf-ruler__label--above') ? 'above' : l.style.top || '35px'}; });
  const collide = bs => bs.some((a, i) => bs.some((c, j) => j > i && a.top === c.top && a.lo < c.hi && c.lo < a.hi));
  const rr = await rulerOf(-20.0, -20.4, -21.0);
  const ticks = rr.qa('.rf-ruler__tick'), expectPct = v => (v + 30) / 18 * 100;
  ok(ticks.map(x => x.textContent).join('|') === '-30|-27|-25|-12' && ticks.every(x => Math.abs(parseFloat(x.style.left) - expectPct(Number(x.textContent))) < 0.01), 'escala: -30, -27, -25 e -12 nas posicoes reais (mesma funcao pct das marcas), nao espacadas igualmente: ' + ticks.map(x => x.style.left).join(' '));
  ok(Math.abs(parseFloat(ticks[1].style.left) - 16.67) < 0.01 && Math.abs(parseFloat(ticks[2].style.left) - 27.78) < 0.01 && ticks[0].classList.contains('rf-ruler__tick--first') && ticks[3].classList.contains('rf-ruler__tick--last'), 'escala: -27 em 16,67% e -25 em 27,78%; extremos alinhados as bordas');
  ok(rr.qa('.rf-ruler__label').length === 3 && !collide(boxesOf(rr)), 'rotulos Esta ONU / Vizinhos / Referencia com valores proximos (-20,0 / -20,4 / -21,0) nao se sobrepoem: ' + JSON.stringify(boxesOf(rr).map(x => [x.t, x.top, Math.round(x.lo), Math.round(x.hi)])));
  const rows2 = new Set(rr.qa('.rf-ruler__label--below').map(l => l.dataset.row)); ok(rows2.size === 2, 'rotulos que colidiam foram deslocados para linhas diferentes');
  const rEdge = await rulerOf(-12.4, -12.0, -12.9); ok(rEdge.qa('.rf-ruler__label').length === 3 && !collide(boxesOf(rEdge)) && boxesOf(rEdge).every(b => b.lo >= -0.5 && b.hi <= 380.5), 'rotulos perto da borda direita ficam dentro da regua e sem colisao');
  const rEq = await rulerOf(-20.0, -20.1, -20.2); ok(rEq.qa('.rf-ruler__label').length === 1 && rEq.q('.rf-ruler__label').textContent.indexOf('Esta ONU = Vizinhos = Referência') === 0, 'valores praticamente iguais continuam num rotulo so');
  const rFar = await rulerOf(-20.0, -27.0, -14.0); ok(!collide(boxesOf(rFar)) && rFar.qa('.rf-ruler__label--below').every(l => l.dataset.row === '0'), 'rotulos distantes ficam na primeira linha');
  rr.errs.length + rEdge.errs.length + rEq.errs.length + rFar.errs.length === 0 || ok(false, 'erros de console na regua');

  // CP-4: rotulos do grafico cabem no SVG (painel e modal ampliado) e rotulos proximos ficam separados na vertical
  const chartLabels = (b, sel) => b.qa(sel + ' svg .rf-chart-label').map(l => { const vb = l.ownerSVGElement.getAttribute('viewBox').split(' ').map(Number); return {t: l.textContent, x: parseFloat(l.getAttribute('x')), y: parseFloat(l.getAttribute('y')), end: parseFloat(l.getAttribute('x')) + l.textContent.length * 6.6, W: vb[2], H: vb[3]}; });
  const ch = await boot({sheet: () => { const sh = sheet(); sh.vizinhos.mediana_dbm = -20.4; sh.onu.baseline_dbm = -20.5; return sh; }}); ch.q('.rf-row--onu').click(); await wait(40);
  const cl = chartLabels(ch, '#rf-p-chart');
  ok(cl.length === 2 && cl.every(l => l.end <= l.W - 4) && cl.every(l => l.x < l.W), 'grafico do painel: rotulos "Ref." e "Vizinhos" cabem dentro do SVG: ' + JSON.stringify(cl.map(l => [l.t, Math.round(l.x), Math.round(l.end), l.W])));
  ok(Math.abs(cl[0].y - cl[1].y) >= 14, 'rotulos de valores proximos (-20,5 / -20,4) separados na vertical: ' + cl.map(l => l.y.toFixed(1)).join(' / '));
  ch.q('.rf-chartbar button').click(); await wait(20);
  const cm = chartLabels(ch, '#rf-modal');
  ok(cm.length === 2 && cm.every(l => l.end <= l.W - 4) && cm[0].W === 840, 'grafico ampliado (modal): rotulos cabem dentro do SVG: ' + JSON.stringify(cm.map(l => [l.t, Math.round(l.x), Math.round(l.end), l.W])));
  const cf = await boot({sheet: () => { const sh = sheet(); sh.vizinhos.mediana_dbm = -26.0; sh.onu.baseline_dbm = -13.0; return sh; }}); cf.q('.rf-row--onu').click(); await wait(40);
  const cfl = chartLabels(cf, '#rf-p-chart'); ok(cfl.every(l => l.end <= l.W - 4) && Math.abs(cfl[0].y - cfl[1].y) > 14, 'grafico: rotulos distantes continuam no lugar e dentro do SVG');

  // CP-5: o seletor de periodo da ficha chama-se "Periodo"; a secao de quedas continua "Quedas"
  const h3s = t.qa('#rf-p-body h3').map(x => x.textContent);
  ok(h3s.indexOf('Período') !== -1 && h3s.indexOf('Quedas') !== -1 && h3s.indexOf('Período') < h3s.indexOf('Sinal e quedas') && t.q('#rf-p-body .rf-periods').parentNode.querySelector('h3').textContent === 'Período', 'ficha: seletor de periodo rotulado "Periodo"; secao de quedas segue "Quedas": ' + h3s.join('|'));

  // Erro 403 / falha de carga
  let f = await boot({status: () => 403});
  ok(f.q('#rf-summary').textContent === 'Acesso negado.', 'erro 403 mostrado, sem quebrar a pagina');
  let g = await boot({status: u => u.indexOf('/onu') !== -1 ? 404 : 0}); g.q('.rf-row--onu').click(); await wait(30);
  ok(g.q('#rf-p-body .rf-note').textContent === 'Acesso negado.' && g.q('#rf-p-title').textContent === 'ONU', 'erro ao abrir a ficha mostra aviso');

  // Busca global -> /rede-fibra?dispositivo=1&pon=2 pre-seleciona a PON
  const pq = await boot({url: 'http://localhost/rede-fibra?dispositivo=1&pon=2'});
  ok(pq.q('#rf-f-pon').value === '2' && pq.qa('.rf-row--pon').length === 1 && pq.q('.rf-row--pon').textContent.indexOf('PON 2') !== -1, 'pon=2 na URL filtra a PON 2');
  const pbad = await boot({url: 'http://localhost/rede-fibra?pon=99'});
  ok(pbad.q('#rf-f-pon').value === '' && pbad.qa('.rf-row--pon').length === 3, 'pon inexistente na URL e ignorada');
  // Vinculos (migration 045): nivel CTO entre PON e ONU, Cliente e CTO reais com link.
  const vt = await boot({storage: {'rf.cols': JSON.stringify(['status', 'cliente', 'cto'])}, tree: (tr) => {
    tr.fontes.cto = true; tr.fontes.vinculo_cliente = true; tr.resumo.sem_vinculo = 2;
    const on = tr.dispositivos[0].pons[0].onus;
    on[0].cliente = {login: 'joao', nome: 'João <b>Teste</b>'}; on[0].cto = {id: 5, nome: 'CTO-5', porta: 'Porta 3'};
    on[1].cliente = {login: 'maria', nome: null}; on[1].cto = {id: 5, nome: 'CTO-5', porta: 'Porta 4'};
    return tr; }});
  const vrows = vt.qa('.rf-row--pon, .rf-row--cto, .rf-row--onu').map(x => x.className.replace(/rf-row |is-selected/g, '').trim().split(' ')[0]);
  ok(vrows.join(',') === 'rf-row--pon,rf-row--cto,rf-row--onu,rf-row--onu,rf-row--cto,rf-row--onu,rf-row--pon,rf-row--cto,rf-row--onu,rf-row--pon', 'arvore com nivel CTO: PON > CTO > ONU e "sem CTO" no fim de cada PON: ' + vrows.join(','));
  const vcto = vt.qa('.rf-row--cto');
  ok(vcto[0].textContent.indexOf('CTO CTO-5') === 0 && vcto[0].textContent.indexOf('2 ONU(s)') !== -1 && vcto[1].textContent.indexOf('sem CTO') === 0 && vcto[1].textContent.indexOf('1 ONU(s)') !== -1, 'linhas de CTO: nome e contagem; "sem CTO" para as demais');
  ok(vcto[0].querySelector('a').getAttribute('href') === '/rede-ctos/ficha?id=5', 'CTO com link para a ficha');
  ok(vt.qa('.rf-row--onu')[0].classList.contains('rf-row--onu-c'), 'ONU sob CTO recuada');
  const vo = vt.qa('.rf-row--onu')[0];
  ok(vo.querySelector('a[href="/clientes/detalhe?login=joao"]') && vo.querySelector('a[href="/clientes/detalhe?login=joao"]').textContent === 'João <b>Teste</b>' && !vo.querySelector('b'), 'Cliente: nome como texto, com link para o perfil');
  ok(vo.querySelector('a[href="/rede-ctos/ficha?id=5"]').textContent === 'CTO-5 · Porta 3', 'coluna CTO: nome e porta com link para a ficha');
  ok(vt.qa('.rf-row--onu')[1].querySelector('a[href="/clientes/detalhe?login=maria"]').textContent === 'maria', 'sem nome: mostra o login');
  ok(vt.qa('.rf-row--onu')[2].textContent.indexOf('sem vínculo') !== -1 && vt.qa('.rf-row--onu')[2].textContent.indexOf('sem CTO') !== -1, 'ONU sem vinculo: "sem vinculo" e "sem CTO"');
  // varios clientes na mesma ONU (migration 048): primeiro + "+N" na coluna, ficha lista todos
  const vn = await boot({storage: {'rf.cols': JSON.stringify(['status', 'cliente', 'cto'])}, tree: (tr) => {
    tr.fontes.cto = true; tr.fontes.vinculo_cliente = true;
    const on = tr.dispositivos[0].pons[0].onus;
    on[0].cliente = {login: 'joao', nome: 'João <b>T</b>'}; on[0].n_clientes = 3; on[0].cto = {id: 5, nome: 'CTO-5', porta: 'Porta 3'};
    on[0].clientes = [{login: 'joao', nome: 'João <b>T</b>', modo_pppoe: 'pppoe_na_onu'}, {login: 'maria', nome: null, modo_pppoe: null}, {login: 'pedro', nome: 'Pedro', modo_pppoe: 'atras_da_onu'}];
    return tr; }, sheet: () => sheet({cliente: {login: 'joao', nome: 'João <b>T</b>'}, n_clientes: 3, cliente_texto: 'João <b>T</b> +2', cto: {id: 5, nome: 'CTO-5', porta: 'Porta 3'}, cto_texto: 'CTO-5 · Porta 3',
      clientes: [{login: 'joao', nome: 'João <b>T</b>', modo_pppoe: 'pppoe_na_onu'}, {login: 'maria', nome: null, modo_pppoe: null}, {login: 'pedro', nome: 'Pedro', modo_pppoe: 'atras_da_onu'}]})});
  const vn0 = vn.qa('.rf-row--onu')[0], more = vn0.querySelector('.rf-muted');
  ok(vn0.querySelectorAll('a[href^="/clientes/detalhe"]').length === 1 && more && more.textContent.trim() === '+2' && more.title === 'João <b>T</b>, maria, Pedro' && !vn0.querySelector('b'), 'coluna Cliente: so o primeiro + "+2" (nomes completos no title, como texto)');
  ok(vn.qa('.rf-row--onu')[1].querySelectorAll('.rf-muted').length >= 0 && vn.qa('.rf-row--onu')[1].textContent.indexOf('+') === -1, 'ONU com 1 cliente ou sem vinculo nao mostra "+N"');
  vn.qa('.rf-row--onu')[0].click(); await wait(80);
  const vnb = vn.q('#rf-p-body').textContent;
  ok(vnb.indexOf('Clientes (3)') !== -1 && vn.q('#rf-p-body').querySelectorAll('a[href^="/clientes/detalhe"]').length === 3 && vnb.indexOf('PPPoE na ONU') !== -1 && vnb.indexOf('atrás da ONU') !== -1 && vnb.indexOf('modo não verificado') !== -1 && !vn.q('#rf-p-body b'), 'ficha da ONU: lista os 3 clientes, cada um com o modo (nao verificado quando NULL)');
  vcto[0].querySelector('.rf-toggle').click();
  ok(vt.qa('.rf-row--onu').length === 2 && vt.qa('.rf-row--cto')[0].querySelector('.rf-toggle').getAttribute('aria-expanded') === 'false', 'recolher a CTO esconde as suas ONUs');
  ok(vt.q('#rf-note').textContent.indexOf('ainda não são lidos') === -1, 'aviso de "vinculo ainda nao lido" some quando ha vinculos');
  ok(t.qa('.rf-row--cto').length === 0 && t.q('#rf-note').textContent.indexOf('ainda não são lidos') !== -1, 'sem a tabela de vinculos: arvore como antes e aviso mantido');
  const sv = sheet({cliente: {login: 'joao', nome: 'João'}, cliente_texto: 'João', cto: {id: 5, nome: 'CTO-5', porta: 'Porta 3'}, cto_texto: 'CTO-5 · Porta 3'});
  ok(keys(sv) === K2(K.sheet), 'ficha com vinculo mantem as mesmas chaves da API');

  // ---- ficha da ONU com mais dados, "Definir CTO", colunas Login e Bairro (ficha-onu) ----
  const fichaCli = {mkauth_ok: true, clientes: [
    {vinculo_id: 1, login: 'joao', nome: 'João <b>T</b>', modo_pppoe: 'pppoe_na_onu', endereco: 'Rua A, 10', bairro: 'Centro', cidade: 'Coimbra', coordenadas: {lat: -20.85, lng: -42.8}, plano: '100M', situacao_mkauth: 'ativo', encontrado_no_mkauth: true},
    {vinculo_id: 2, login: 'maria', nome: null, modo_pppoe: null, endereco: null, bairro: null, cidade: null, coordenadas: null, plano: null, situacao_mkauth: null, encontrado_no_mkauth: false}]};
  const tv = (tr) => { tr.fontes.cto = true; tr.fontes.vinculo_cliente = true; const on = tr.dispositivos[0].pons[0].onus; on[0].cliente = {login: 'joao', nome: 'João <b>T</b>'}; on[0].n_clientes = 2; on[0].clientes = [{login: 'joao', nome: 'João <b>T</b>', modo_pppoe: 'pppoe_na_onu'}, {login: 'maria', nome: null, modo_pppoe: null}]; on[0].cto = {id: 5, nome: 'CTO-5', porta: 'Porta 3'}; return tr; };
  const sh2 = () => sheet({cliente: {login: 'joao', nome: 'João'}, n_clientes: 2, cliente_texto: 'João +1', cto: {id: 5, nome: 'CTO-5', porta: 'Porta 3'}, cto_texto: 'CTO-5 · Porta 3', clientes: [{login: 'joao', nome: 'João', modo_pppoe: 'pppoe_na_onu'}, {login: 'maria', nome: null, modo_pppoe: null}]});
  const fc = await boot({tree: tv, sheet: sh2, ficha: () => fichaCli});
  ok(fc.qa('#rf-table thead th').map(x => x.textContent).join('|') === 'ONU|Estado|Sinal|Distância|Cliente|CTO|Quedas 24 h|MAC' && !fc.calls.some(u => u.indexOf('clientes-resumo') !== -1), 'Login e Bairro/Localidade vêm desligadas e sem consulta ao MkAuth');
  fc.qa('#rf-cols-list input').filter(i => i.parentNode.textContent.trim() === 'Login')[0].checked = true; fc.ev(fc.qa('#rf-cols-list input').filter(i => i.parentNode.textContent.trim() === 'Login')[0], 'change');
  const lg = fc.qa('.rf-row--onu')[0];
  ok(fc.qa('#rf-table thead th').map(x => x.textContent).indexOf('Login') !== -1 && lg.textContent.indexOf('joao') !== -1 && lg.textContent.indexOf('+1') !== -1 && !fc.calls.some(u => u.indexOf('clientes-resumo') !== -1), 'coluna Login: primeiro login + "+1", sem consultar o MkAuth');
  const bx = fc.qa('#rf-cols-list input').filter(i => i.parentNode.textContent.trim() === 'Bairro/Localidade')[0]; bx.checked = true; fc.ev(bx, 'change'); await wait(60);
  ok(fc.calls.filter(u => u.indexOf('clientes-resumo') !== -1).length === 1, 'ligar Bairro/Localidade consulta o resumo UMA vez');
  const fcr = await boot({storage: {'rf.cols': JSON.stringify(['status', 'cliente', 'bairro'])}, tree: tv, resumo: () => ({clientes: {joao: {bairro: 'Centro', cidade: 'Coimbra'}}})}); await wait(60);
  ok(fcr.qa('.rf-row--onu')[0].textContent.indexOf('Centro') !== -1 && fcr.qa('.rf-row--onu')[0].querySelector('td[title="Centro · Coimbra"]') && fcr.qa('.rf-row--onu')[1].textContent.indexOf('sem vínculo') !== -1, 'coluna Bairro/Localidade mostra o bairro do primeiro cliente');
  const fer = await boot({storage: {'rf.cols': JSON.stringify(['status', 'cliente', 'bairro'])}, tree: tv, status: (u) => u.indexOf('clientes-resumo') !== -1 ? 503 : 0}); await wait(60);
  ok(fer.qa('.rf-row--onu')[0].textContent.indexOf('indisponível') !== -1 && fer.errs.length === 0, 'MkAuth fora: "indisponível" na coluna e a tela segue');
  fc.qa('.rf-row--onu')[0].querySelector('td').click(); await wait(80);
  const fb = fc.q('#rf-p-body'), fbt = fb.textContent;
  ok(fbt.indexOf('Dados dos clientes') !== -1 && fbt.indexOf('Rua A, 10 · Centro · Coimbra') !== -1 && fbt.indexOf('Plano: 100M') !== -1 && fbt.indexOf('Situação no MkAuth: Ativo') !== -1 && fbt.indexOf('cliente não encontrado no MkAuth') !== -1 && !fb.querySelector('b'), 'ficha da ONU: endereço curto, plano, situação e modo de cada cliente (texto puro)');
  const mapA = fb.querySelector('a[href*="?lat="]'); ok(!!mapA && /\?lat=-20\.85&lng=-42\.8$/.test(mapA.getAttribute('href')) && mapA.textContent === 'ver no mapa', 'link "ver no mapa" com as coordenadas');
  ok(!/cpf|telefone|celular|e-mail|@/i.test(fbt), 'a ficha não mostra CPF, telefone nem e-mail');
  ok(!!fc.q('#rf-define-cto') && fc.q('#rf-define-cto').textContent === 'Trocar CTO', 'ficha com CTO: botão "Trocar CTO"');
  fc.q('#rf-define-cto').click(); await wait(40);
  ok(!!fc.q('.rc-picker') && fc.calls.some(u => /ctos-proximas\?onu_id=\d+&q=$/.test(u)), '"Trocar CTO" abre o seletor e busca as CTOs (sem texto = mais próximas)');
  const f0 = await boot({tree: tv, sheet: () => sheet()}); f0.qa('.rf-row--onu')[3].querySelector('td').click(); await wait(60);
  ok(!f0.q('#rf-define-cto') && f0.q('#rf-p-body').textContent.indexOf('Vincule um cliente a esta ONU') !== -1, 'ONU sem cliente: sem botão e com a orientação de vincular primeiro');

  // Fechar ficha
  t.q('#rf-p-close').click(); ok(t.q('#rf-panel').hidden && !t.q('.rf-layout').classList.contains('has-panel'), 'fechar ficha');
  const allErrs = [].concat(pq.errs, pbad.errs, vt.errs, t.errs, e.errs, a.errs, f.errs, g.errs, h1.errs, h2.errs, h3.errs, h4.errs, st.errs, ev0.errs, ev1.errs, ev2.errs, ev3.errs, tc.errs, tcs.errs, stl.errs, so.errs, sp.errs, sh.errs, rr.errs, rEdge.errs, rEq.errs, rFar.errs, ch.errs, cf.errs); ok(allErrs.length === 0, 'sem erros no console: ' + allErrs.join(';'));
  console.log('falhas: ' + falhas + ' | verificacoes: ' + total + ' | erros console: ' + (allErrs.length ? allErrs.length : 'nenhum'));
  process.exit(falhas ? 1 : 0);
})().catch(err => { console.log('[FALHA] excecao: ' + err.stack); process.exit(1); });
