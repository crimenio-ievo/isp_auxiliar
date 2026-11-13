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
  dispositivos: [{id: 1, nome: 'OLT-<b>X</b>', ativo: true, intervalo_status_s: 600, ultima_tentativa: '2026-11-13 12:00:00', ultima_leitura_ok: '2026-11-13 12:00:00', ultimo_resultado: 'ok', pons: [
    {pon_index: 1, label: 'PON 1', total: 3, online: 2, onus: [onu(1, 1, 1, 'online', -20.0, 'ok', {quedas_24h: 1, ultima_queda_em: '2026-11-13 10:00:00'}), onu(2, 2, 1, 'online', -26.0, 'atencao'), onu(3, 3, 1, 'offline', -21.0, 'ok')]},
    {pon_index: 2, label: 'PON 2', total: 1, online: 1, onus: [onu(4, 1, 2, 'online', null, 'unknown')]},
    {pon_index: 3, label: 'PON 3', total: 0, online: 0, onus: []}]}],
  resumo: {onus: 4, online: 3, offline: 1, desconhecido: 0, atencao: 1, critico: 0, saturado: 0, sem_sinal: 1, quedas_24h: 1, sem_vinculo: 4},
  limiares: {saturado_dbm: -8, atencao_dbm: -25, critico_dbm: -27, desvio_db: 3},
  fontes: {quedas_poll: true, quedas_log: false, vinculo_cliente: false, cto: false, sinal_na_olt: false}
};
const outage = {onu_id: 1, onu_identifier_mascarado: '00:2E:C7:xx:xx:xx', source: 'log', precisao: 'exata', category: 'queda', maintenance_reason: null, started_at: '2026-11-13 10:00:00', ended_at: '2026-11-13 10:10:00', open: false, duration_s: 600, downtime_in_period_s: 600, cause: 'energia', reason: 'Queda de energia (Dying Gasp)'};
const sheet = (over) => Object.assign({
  onu: Object.assign(onu(1, 1, 1, 'online', -20.0, 'ok'), {baseline_dbm: -20.5, baseline_desde: '2026-11-12 00:00:00', baseline_amostras: 5, olt_rx_dbm: null, olt_rx_texto: 'ainda não lido'}),
  vizinhos: {mediana_dbm: -26.0, quantidade: 3, diferenca_db: 6.0},
  serie_7d: {pontos: [{t: '2026-11-07 12:00:00', rx: -20.0}, {t: '2026-11-10 12:00:00', rx: -22.5}, {t: '2026-11-13 11:00:00', rx: -20.0}], de: '2026-11-06 12:00:00', ate: '2026-11-13 12:00:00', amostragem: 'x'},
  periodo: {kind: '24h', from: '2026-11-12 12:00:00', to: '2026-11-13 12:00:00'}, quedas: [outage], quedas_total: {quedas: 1, em_aberto: 0, downtime_no_periodo_s: 600, por_motivo: {energia: 1}, do_log: 1, do_poll: 0, log_sem_onu_cadastrada: 0},
  quedas_serie: [outage, Object.assign({}, outage, {started_at: '2026-11-11 08:00:00', ended_at: null, open: true, duration_s: null, reason: 'Sem aviso de energia', cause: 'sem_aviso_energia'})],
  ultimas_quedas: [outage], estavel: false, estavel_texto: null, limiares: tree.limiares, fontes: {quedas_poll: true, quedas_log: true},
  cliente: null, cliente_texto: 'sem vínculo', cto: null, cto_texto: 'ainda não lido'}, over || {});

async function boot(opts) {
  opts = opts || {};
  const errs = [], vc = new VirtualConsole(); vc.on('jsdomError', e => errs.push(String(e.detail && e.detail.message || e.message))); vc.on('error', e => errs.push(String(e)));
  const calls = [];
  const dom = new JSDOM(html, {runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole: vc, url: 'http://localhost/rede-fibra'});
  const w = dom.window;
  w.fetch = (u) => { calls.push(u); const status = opts.status && opts.status(u); const body = status ? {ok: false, message: 'Acesso negado.'} : {ok: true, data: u.indexOf('/onu') !== -1 ? (opts.sheet ? opts.sheet(u) : sheet()) : tree};
    return Promise.resolve({ok: !status, status: status || 200, json: () => Promise.resolve(body)}); };
  w.eval(js); await wait(40);
  const d = w.document, q = s => d.querySelector(s), qa = s => [...d.querySelectorAll(s)];
  return {w, d, q, qa, calls, errs, ev: (el, type, init) => el.dispatchEvent(new w.Event(type, Object.assign({bubbles: true}, init || {})))};
}

(async () => {
  let t = await boot();
  ok(t.q('#rf-summary').textContent.indexOf('4 ONUs · 3 online · 1 offline') === 0, 'resumo no topo');
  ok(t.qa('.rf-row--olt').length === 1 && t.qa('.rf-row--pon').length === 3 && t.qa('.rf-row--onu').length === 4, 'arvore: 1 OLT, 3 PONs (inclusive vazia), 4 ONUs');
  ok(t.q('.rf-row--olt').textContent.indexOf('OLT-<b>X</b>') !== -1 && !t.q('.rf-row--olt b'), 'nome do equipamento como texto, sem HTML');
  const heads = t.qa('#rf-table thead th').map(x => x.textContent);
  ok(heads.join('|') === 'ONU|Estado|Sinal (dBm)|Faixa|Distância|Cliente|CTO|Quedas 24 h|MAC', 'colunas padrao: ' + heads.join('|'));
  const row1 = t.qa('.rf-row--onu')[0].textContent;
  ok(row1.indexOf('sem vínculo') !== -1 && row1.indexOf('ainda não lido') !== -1 && row1.indexOf('-20.00') !== -1 && row1.indexOf('≈ 22.64 km') !== -1 && row1.indexOf('00:2E:C7:00:00:01') !== -1, 'linha da ONU: sinal, distancia estimada, "sem vinculo" e "ainda nao lido"');
  ok(t.qa('.rf-row--onu')[3].textContent.indexOf('sem leitura') !== -1, 'ONU sem leitura de sinal');
  ok(!/simulad|fict[ií]c|fake/i.test(t.d.body.textContent), 'nenhum texto de dado simulado');
  ok(t.q('#rf-note').textContent.indexOf('Quedas do log da OLT ainda não importadas') !== -1, 'aviso quando o log da OLT ainda nao foi importado');

  // Filtros
  const sel = (id, v) => { t.q(id).value = v; t.ev(t.q(id), 'change'); };
  sel('#rf-f-status', 'offline'); ok(t.qa('.rf-row--onu').length === 1 && t.q('.rf-row--onu').textContent.indexOf('Offline') !== -1, 'filtro estado offline');
  sel('#rf-f-status', ''); t.q('#rf-f-down24').checked = true; t.ev(t.q('#rf-f-down24'), 'change');
  ok(t.qa('.rf-row--onu').length === 1 && t.qa('.rf-row--pon').length === 1, 'filtro "cairam em 24 h" (esconde PONs sem ONU)');
  t.q('#rf-f-down24').checked = false; t.ev(t.q('#rf-f-down24'), 'change');
  t.q('#rf-f-novinc').checked = true; t.ev(t.q('#rf-f-novinc'), 'change'); ok(t.qa('.rf-row--onu').length === 4, 'filtro "sem vinculo" (todas, pois nao ha vinculo)');
  t.q('#rf-f-novinc').checked = false; t.ev(t.q('#rf-f-novinc'), 'change');
  sel('#rf-f-pon', '2'); ok(t.qa('.rf-row--onu').length === 1 && t.qa('.rf-row--pon').length === 1, 'filtro PON');
  sel('#rf-f-pon', ''); t.q('#rf-f-q').value = '00:2e:c7:00:00:03'; t.ev(t.q('#rf-f-q'), 'input'); ok(t.qa('.rf-row--onu').length === 1, 'busca por MAC');
  t.q('#rf-f-q').value = ''; t.ev(t.q('#rf-f-q'), 'input');
  ok(t.qa('#rf-f-olt option').length === 2 && t.qa('#rf-f-pon option').length === 4, 'seletores de OLT e PON preenchidos');
  sel('#rf-f-status', 'unknown'); ok(t.qa('.rf-row--onu').length === 0 && !t.q('#rf-empty').hidden, 'sem resultado: mensagem de vazio'); sel('#rf-f-status', ''); ok(t.q('#rf-empty').hidden, 'mensagem de vazio some');

  // Colunas escolhiveis (persistem)
  const boxes = t.qa('#rf-cols-list input');
  ok(boxes.length === 10, 'menu de colunas'); const macBox = boxes[9]; macBox.checked = false; t.ev(macBox, 'change');
  ok(t.qa('#rf-table thead th').map(x => x.textContent).indexOf('MAC') === -1 && t.w.localStorage.getItem('rf.cols').indexOf('mac') === -1, 'coluna MAC oculta e salva');
  boxes[3].checked = true; t.ev(boxes[3], 'change'); ok(t.qa('#rf-table thead th').map(x => x.textContent).indexOf('Tx (dBm)') !== -1, 'coluna Tx habilitada');

  // Recolher
  t.q('.rf-row--pon .rf-toggle').click(); ok(t.qa('.rf-row--onu').length === 1, 'recolher PON esconde as ONUs');
  t.q('.rf-row--pon .rf-toggle').click(); t.q('.rf-row--olt .rf-toggle').click(); ok(t.qa('.rf-row--pon').length === 0, 'recolher OLT esconde as PONs'); t.q('.rf-row--olt .rf-toggle').click();

  // Ficha
  t.q('.rf-row--onu').click(); await wait(30);
  ok(t.calls.some(u => /\/api\/rede-fibra\/onu\?id=1&periodo=24h$/.test(u)), 'abrir ficha chama a API com periodo 24h');
  ok(!t.q('#rf-panel').hidden && t.q('#rf-p-title').textContent === 'ONU 1 · PON 1' && t.q('#rf-p-sub').textContent.indexOf('00:2E:C7:00:00:01') === 0, 'painel lateral com a ONU');
  const body = t.q('#rf-p-body').textContent;
  ok(body.indexOf('ainda não lido') !== -1 && body.indexOf('sem vínculo') !== -1 && body.indexOf('≈ 22.64 km (estimada pelo RTT)') !== -1 && body.indexOf('-26.00 dBm (3 ONUs, diferença +6.00 dB)') !== -1, 'ficha: sinal na OLT/CTO "ainda nao lido", cliente "sem vinculo", mediana dos vizinhos');
  ok(t.qa('.rf-ruler__z').length === 3 && t.qa('.rf-ruler__mark').length === 3 && t.q('.rf-ruler__scale').textContent.indexOf('-30') !== -1 && t.q('.rf-ruler__scale').textContent.indexOf('-12') !== -1, 'regua de -30 a -12 com 3 faixas e 3 marcas');
  const zs = t.qa('.rf-ruler__z').map(z => parseFloat(z.style.width)); ok(Math.abs(zs[0] - 16.67) < 0.1 && Math.abs(zs[1] - 11.11) < 0.1 && Math.abs(zs[0] + zs[1] + zs[2] - 100) < 0.01, 'larguras das faixas seguem -27 e -25');
  const mk = t.q('.rf-ruler__mark:not(.rf-ruler__mark--ref):not(.rf-ruler__mark--med)'); ok(Math.abs(parseFloat(mk.style.left) - 55.56) < 0.1, 'marca do sinal atual em -20 dBm');
  ok(t.qa('#rf-p-chart svg').length === 1 && t.qa('#rf-p-chart .rf-outage').length === 2 && t.qa('#rf-p-chart path.rf-line').length === 1, 'grafico com linha e as quedas como faixas verticais');
  ok(/Queda de energia \(Dying Gasp\)/.test(t.q('#rf-p-body .rf-outages').textContent) && t.q('#rf-p-body .rf-outages').textContent.indexOf('10/11') === -1 && t.q('#rf-p-body .rf-outages').textContent.indexOf('13/11 10:00') !== -1 && t.q('#rf-p-body .rf-outages').textContent.indexOf('10 min') !== -1, 'lista: inicio, retorno, duracao e motivo');
  ok(!/fibra/i.test(t.q('#rf-p-body').textContent), 'ficha nunca afirma fibra');
  ok(t.qa('#rf-p-body .rf-outages').length === 2, 'lista do periodo e lista das ultimas quedas');

  // Cursor do grafico
  const ov = t.q('#rf-p-chart .rf-overlay'), svg = t.q('#rf-p-chart svg'); svg.getBoundingClientRect = () => ({left: 0, width: 720, top: 0, height: 210});
  const move = x => ov.dispatchEvent(new t.w.MouseEvent('pointermove', {clientX: x, bubbles: true}));
  move(44 + (666 * (parse('2026-11-10 20:00:00') - parse('2026-11-06 12:00:00')) / (parse('2026-11-13 12:00:00') - parse('2026-11-06 12:00:00'))));
  const tip = t.q('#rf-p-chart .rf-tip'); ok(!tip.hidden && /10\/11 20:00 · -22\.50 dBm$/.test(tip.textContent), 'cursor: data/hora e valor (' + tip.textContent + ')');
  move(44 + (666 * (parse('2026-11-12 10:00:00') - parse('2026-11-06 12:00:00')) / (parse('2026-11-13 12:00:00') - parse('2026-11-06 12:00:00')))); 
  ok(/em queda \(Sem aviso de energia\)/.test(tip.textContent), 'cursor dentro de uma queda em aberto mostra o motivo (' + tip.textContent + ')');
  move(44); ok(/sem leitura/.test(tip.textContent), 'cursor antes da primeira leitura: sem leitura'); ov.dispatchEvent(new t.w.MouseEvent('pointerleave', {bubbles: true})); ok(tip.hidden, 'cursor some ao sair');

  // Ampliar
  t.qa('#rf-p-body .rf-chartbar button')[0].click(); ok(!t.q('#rf-modal').hidden && t.qa('#rf-m-chart svg').length === 1 && t.q('#rf-m-chart svg').getAttribute('viewBox').indexOf('400') !== -1, 'ampliar abre o grafico grande');
  t.q('#rf-m-close').click(); ok(t.q('#rf-modal').hidden, 'fechar o grafico ampliado');
  t.q('#rf-modal').hidden = false; t.d.dispatchEvent(new t.w.KeyboardEvent('keydown', {key: 'Escape', bubbles: true})); ok(t.q('#rf-modal').hidden, 'Esc fecha o grafico ampliado');

  // Periodos
  const btn = txt => t.qa('#rf-p-body .rf-periods button').filter(b => b.textContent === txt)[0];
  btn('1 h').click(); await wait(30); ok(/periodo=1h$/.test(t.calls[t.calls.length - 1]), 'periodo 1 h');
  btn('1 semana').click(); await wait(30); ok(/periodo=7d$/.test(t.calls[t.calls.length - 1]), 'periodo 1 semana');
  btn('Personalizado').click(); await wait(10); ok(!!t.q('#rf-c-from') && !!t.q('#rf-c-to'), 'personalizado mostra os campos de data');
  t.q('#rf-c-from').value = '2026-11-12T08:00'; t.q('#rf-c-to').value = '2026-11-13T09:30'; [...t.qa('.rf-custom button')][0].click(); await wait(30);
  ok(/periodo=custom&de=2026-11-12T08%3A00&ate=2026-11-13T09%3A30$/.test(t.calls[t.calls.length - 1]), 'personalizado envia de/ate (' + t.calls[t.calls.length - 1] + ')');
  function parse(s) { return new Date(s.replace(' ', 'T')).getTime(); }

  // Estavel e em aberto
  let e = await boot({sheet: () => sheet({quedas: [], quedas_serie: [], ultimas_quedas: [], estavel: true, estavel_texto: 'Sem queda registrada no período (estável).', quedas_total: {quedas: 0, em_aberto: 0, downtime_no_periodo_s: 0, por_motivo: {}, do_log: 0, do_poll: 0, log_sem_onu_cadastrada: 0}, serie_7d: {pontos: [], de: '2026-11-06 12:00:00', ate: '2026-11-13 12:00:00', amostragem: 'x'}})});
  e.q('.rf-row--onu').click(); await wait(30);
  ok(e.q('#rf-p-body').textContent.indexOf('Sem queda registrada no período (estável).') !== -1 && e.qa('#rf-p-body .rf-outages').length === 0 && e.q('#rf-p-chart').textContent.indexOf('Sem leituras de sinal') !== -1, 'sem registro no periodo = estavel; grafico sem leituras avisa');
  let a = await boot({sheet: () => sheet({quedas: [Object.assign({}, outage, {open: true, ended_at: null, duration_s: null})]})});
  a.q('.rf-row--onu').click(); await wait(30); ok(a.q('#rf-p-body .rf-outages').textContent.indexOf('em aberto') !== -1, 'queda em aberto na lista');

  // Erro 403 / falha de carga
  let f = await boot({status: () => 403});
  ok(f.q('#rf-summary').textContent === 'Acesso negado.', 'erro 403 mostrado, sem quebrar a pagina');
  let g = await boot({status: u => u.indexOf('/onu') !== -1 ? 404 : 0}); g.q('.rf-row--onu').click(); await wait(30);
  ok(g.q('#rf-p-body .rf-note').textContent === 'Acesso negado.' && g.q('#rf-p-title').textContent === 'ONU', 'erro ao abrir a ficha mostra aviso');

  // Fechar ficha
  t.q('#rf-p-close').click(); ok(t.q('#rf-panel').hidden && !t.q('.rf-layout').classList.contains('has-panel'), 'fechar ficha');
  const allErrs = [].concat(t.errs, e.errs, a.errs, f.errs, g.errs); ok(allErrs.length === 0, 'sem erros no console: ' + allErrs.join(';'));
  console.log('falhas: ' + falhas + ' | verificacoes: ' + total + ' | erros console: ' + (allErrs.length ? allErrs.length : 'nenhum'));
  process.exit(falhas ? 1 : 0);
})().catch(err => { console.log('[FALHA] excecao: ' + err.stack); process.exit(1); });
