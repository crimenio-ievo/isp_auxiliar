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
  distancia_origem: status === 'online' ? 'estimada pelo RTT' : null, cliente: null, cto: null, quedas_24h: 0, ultima_queda_em: null}, extra || {});
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
  cliente: null, cliente_texto: 'sem vínculo', cto: null, cto_texto: 'ainda não lido'}, over || {});

// Mesmas listas de chaves de tests/Feature/NetFiberReaderSmoke.php: se a API mudar, os dois testes devem mudar juntos.
const K = {
  tree: 'gerado_em,dispositivos,resumo,limiares,fontes', device: 'id,nome,ativo,intervalo_status_s,ultima_tentativa,ultima_leitura_ok,ultimo_resultado,pons,tecnologia,tipo_no,modelo,ip,site,saude',
  pon: 'pon_index,label,onus,total,online', onu: 'id,pon_index,onu_index,mac,status,status_desde,desatualizado,rx_dbm,rx_band,rx_medido_em,tx_dbm,temp_c,distancia_m,distancia_origem,cliente,cto,quedas_24h,ultima_queda_em',
  sheet: 'onu,vizinhos,serie,periodo,quedas,quedas_total,quedas_serie,ultimas_quedas,estavel,estavel_texto,limiares,fontes,cliente,cliente_texto,cto,cto_texto',
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

async function boot(opts) {
  opts = opts || {};
  const errs = [], vc = new VirtualConsole(); vc.on('jsdomError', e => errs.push(String(e.detail && e.detail.message || e.message))); vc.on('error', e => errs.push(String(e)));
  const calls = [];
  const dom = new JSDOM(html, {runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole: vc, url: opts.url || 'http://localhost/rede-fibra'});
  const w = dom.window;
  const style = w.document.createElement('style'); style.textContent = fs.readFileSync(path.join(root, 'public/assets/css/rede-fibra.css'), 'utf8'); w.document.head.appendChild(style);
  const treeData = opts.tree ? opts.tree(JSON.parse(JSON.stringify(tree))) : tree;
  w.fetch = (u) => { calls.push(u); const status = opts.status && opts.status(u); const body = status ? {ok: false, message: 'Acesso negado.'} : {ok: true, data: u.indexOf('/eventos') !== -1 ? (opts.events ? opts.events(u) : events([evGroup()])) : u.indexOf('/onu') !== -1 ? (opts.sheet ? opts.sheet(u) : sheet()) : treeData};
    return Promise.resolve({ok: !status, status: status || 200, json: () => Promise.resolve(body)}); };
  w.eval(js); await wait(40);
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
  ok(heads.join('|') === 'ONU|Estado|Sinal (dBm)|Faixa|Distância|Cliente|CTO|Quedas 24 h|MAC', 'colunas padrao: ' + heads.join('|'));
  const row1 = t.qa('.rf-row--onu')[0].textContent;
  ok(row1.indexOf('sem vínculo') !== -1 && row1.indexOf('ainda não lido') !== -1 && row1.indexOf('-20.00') !== -1 && row1.indexOf('≈ 22.64 km') !== -1 && row1.indexOf('00:2E:C7:00:00:01') !== -1, 'linha da ONU: sinal, distancia estimada, "sem vinculo" e "ainda nao lido"');
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
  ok(boxes.length === 10, 'menu de colunas'); const macBox = boxes[9]; macBox.checked = false; t.ev(macBox, 'change');
  ok(t.qa('#rf-table thead th').map(x => x.textContent).indexOf('MAC') === -1 && t.w.localStorage.getItem('rf.cols').indexOf('mac') === -1, 'coluna MAC oculta e salva');
  boxes[3].checked = true; t.ev(boxes[3], 'change'); ok(t.qa('#rf-table thead th').map(x => x.textContent).indexOf('Tx (dBm)') !== -1, 'coluna Tx habilitada');

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
  move(38 + (310 * (parse('2026-11-10 20:00:00') - parse('2026-11-06 12:00:00')) / (parse('2026-11-13 12:00:00') - parse('2026-11-06 12:00:00'))));
  const tip = t.q('#rf-p-chart .rf-tip'); ok(!tip.hidden && /10\/11 17:00 · -22,50 dBm$/.test(tip.textContent), 'cursor em horario de Brasilia (UTC 20:00 = 17:00): ' + tip.textContent);
  move(38 + (310 * (parse('2026-11-12 10:00:00') - parse('2026-11-06 12:00:00')) / (parse('2026-11-13 12:00:00') - parse('2026-11-06 12:00:00'))));
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

  // Erro 403 / falha de carga
  let f = await boot({status: () => 403});
  ok(f.q('#rf-summary').textContent === 'Acesso negado.', 'erro 403 mostrado, sem quebrar a pagina');
  let g = await boot({status: u => u.indexOf('/onu') !== -1 ? 404 : 0}); g.q('.rf-row--onu').click(); await wait(30);
  ok(g.q('#rf-p-body .rf-note').textContent === 'Acesso negado.' && g.q('#rf-p-title').textContent === 'ONU', 'erro ao abrir a ficha mostra aviso');

  // Fechar ficha
  t.q('#rf-p-close').click(); ok(t.q('#rf-panel').hidden && !t.q('.rf-layout').classList.contains('has-panel'), 'fechar ficha');
  const allErrs = [].concat(t.errs, e.errs, a.errs, f.errs, g.errs, h1.errs, h2.errs, h3.errs, h4.errs, st.errs, ev0.errs, ev1.errs, ev2.errs, ev3.errs); ok(allErrs.length === 0, 'sem erros no console: ' + allErrs.join(';'));
  console.log('falhas: ' + falhas + ' | verificacoes: ' + total + ' | erros console: ' + (allErrs.length ? allErrs.length : 'nenhum'));
  process.exit(falhas ? 1 : 0);
})().catch(err => { console.log('[FALHA] excecao: ' + err.stack); process.exit(1); });
