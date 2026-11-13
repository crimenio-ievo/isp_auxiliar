/* Rede de fibra: árvore OLT > PON > ONU e ficha da ONU. Somente leitura, sem dados simulados.
   Todo texto vem do servidor via textContent (nunca innerHTML). */
(function () {
  'use strict';
  var app = document.getElementById('rf-app');
  if (!app) { return; }
  var API = { tree: app.dataset.apiTree, onu: app.dataset.apiOnu };
  var $ = function (id) { return document.getElementById(id); };
  var NS = 'http://www.w3.org/2000/svg';
  var YMIN = -30, YMAX = -12;
  var COLS = [
    { k: 'status', l: 'Estado' }, { k: 'rx', l: 'Sinal (dBm)' }, { k: 'band', l: 'Faixa' }, { k: 'tx', l: 'Tx (dBm)' },
    { k: 'temp', l: 'Temp. (°C)' }, { k: 'dist', l: 'Distância' }, { k: 'cliente', l: 'Cliente' }, { k: 'cto', l: 'CTO' },
    { k: 'quedas', l: 'Quedas 24 h' }, { k: 'mac', l: 'MAC' }
  ];
  var DEFAULT_COLS = ['status', 'rx', 'band', 'dist', 'cliente', 'cto', 'quedas', 'mac'];
  var BAND = { ok: 'Normal', atencao: 'Atenção', critico: 'Crítico', saturado: 'Saturado', unknown: 'Sem leitura' };
  var STATUS = { online: 'Online', offline: 'Offline', unknown: 'Desconhecido' };
  var state = { tree: null, cols: loadCols(), collapsed: {}, selected: null, period: '24h', from: '', to: '', sheet: null };

  function loadCols() {
    try { var v = JSON.parse(window.localStorage.getItem('rf.cols') || 'null'); if (Array.isArray(v)) { return v; } } catch (e) { /* sem storage */ }
    return DEFAULT_COLS.slice();
  }
  function saveCols() { try { window.localStorage.setItem('rf.cols', JSON.stringify(state.cols)); } catch (e) { /* sem storage */ } }

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined && text !== null) { n.textContent = text; } return n; }
  function svg(tag, attrs) { var n = document.createElementNS(NS, tag); Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); }); return n; }
  function clear(n) { while (n.firstChild) { n.removeChild(n.firstChild); } }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function fmtDT(s) { return s ? s.slice(8, 10) + '/' + s.slice(5, 7) + ' ' + s.slice(11, 16) : '—'; }
  function parse(s) { return new Date(String(s).replace(' ', 'T')).getTime(); }
  function dur(sec) {
    if (sec === null || sec === undefined) { return '—'; }
    if (sec < 60) { return sec + ' s'; }
    var m = Math.floor(sec / 60), s = sec % 60, h = Math.floor(m / 60);
    if (h > 0) { return h + ' h ' + pad(m % 60) + ' min'; }
    return m + ' min' + (s ? ' ' + s + ' s' : '');
  }
  function dbm(v, d) { return v === null || v === undefined ? 'sem leitura' : v.toFixed(d === undefined ? 2 : d) + ' dBm'; }
  function pill(kind, text) { return el('span', 'rf-pill rf-pill--' + kind, text); }
  function get(url) {
    return window.fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then(function (r) {
      return r.json().then(function (b) { if (!r.ok || !b.ok) { throw new Error(b && b.message || 'Falha ao ler os dados.'); } return b.data; });
    });
  }

  /* ---------- filtros e árvore ---------- */
  function filters() {
    return { olt: $('rf-f-olt').value, pon: $('rf-f-pon').value, status: $('rf-f-status').value, down: $('rf-f-down24').checked,
      novinc: $('rf-f-novinc').checked, q: $('rf-f-q').value.replace(/[^0-9a-f]/gi, '').toLowerCase() };
  }
  function onuLevel(f) { return !!(f.status || f.down || f.novinc || f.q); }
  function keep(o, f) {
    if (f.status && o.status !== f.status) { return false; }
    if (f.down && !(o.quedas_24h > 0)) { return false; }
    if (f.novinc && o.cliente !== null) { return false; }
    if (f.q && o.mac.replace(/:/g, '').toLowerCase().indexOf(f.q) === -1) { return false; }
    return true;
  }
  function fillSelectors() {
    var olt = $('rf-f-olt'), cur = olt.value;
    clear(olt); olt.appendChild(new Option('Todas', ''));
    state.tree.dispositivos.forEach(function (d) { olt.appendChild(new Option(d.nome, String(d.id))); });
    olt.value = cur;
    fillPons();
  }
  function fillPons() {
    var pon = $('rf-f-pon'), cur = pon.value, seen = {};
    clear(pon); pon.appendChild(new Option('Todas', ''));
    state.tree.dispositivos.forEach(function (d) {
      if ($('rf-f-olt').value && String(d.id) !== $('rf-f-olt').value) { return; }
      d.pons.forEach(function (p) { if (!seen[p.pon_index]) { seen[p.pon_index] = 1; pon.appendChild(new Option(p.label, String(p.pon_index))); } });
    });
    pon.value = cur;
  }
  function cell(o, k) {
    var td = el('td');
    if (k === 'status') { td.appendChild(pill(o.status, STATUS[o.status] || o.status)); if (o.desatualizado) { td.appendChild(el('span', 'rf-muted', ' (desatualizado)')); } }
    else if (k === 'rx') { td.textContent = o.rx_dbm === null ? 'sem leitura' : o.rx_dbm.toFixed(2); if (o.rx_dbm === null) { td.className = 'rf-muted'; } }
    else if (k === 'band') { td.appendChild(pill(o.rx_band, BAND[o.rx_band] || o.rx_band)); }
    else if (k === 'tx') { td.textContent = o.tx_dbm === null ? 'sem leitura' : o.tx_dbm.toFixed(2); }
    else if (k === 'temp') { td.textContent = o.temp_c === null ? 'sem leitura' : o.temp_c.toFixed(1); }
    else if (k === 'dist') { td.textContent = o.distancia_m === null ? '—' : '≈ ' + (o.distancia_m / 1000).toFixed(2) + ' km'; if (o.distancia_origem) { td.title = o.distancia_origem; } }
    else if (k === 'cliente') { td.textContent = o.cliente === null ? 'sem vínculo' : String(o.cliente); if (o.cliente === null) { td.className = 'rf-muted'; } }
    else if (k === 'cto') { td.textContent = o.cto === null ? 'ainda não lido' : String(o.cto); if (o.cto === null) { td.className = 'rf-muted'; } }
    else if (k === 'quedas') { td.textContent = o.quedas_24h > 0 ? String(o.quedas_24h) : '0'; if (o.quedas_24h > 0) { td.appendChild(el('span', 'rf-muted', ' · ' + fmtDT(o.ultima_queda_em))); } }
    else if (k === 'mac') { td.textContent = o.mac; }
    return td;
  }
  function renderTree() {
    var f = filters(), head = $('rf-table').tHead, body = $('rf-table').tBodies[0], cols = COLS.filter(function (c) { return state.cols.indexOf(c.k) !== -1; });
    clear(head); clear(body);
    var hr = el('tr'); hr.appendChild(el('th', '', 'ONU'));
    cols.forEach(function (c) { hr.appendChild(el('th', '', c.l)); });
    head.appendChild(hr);
    var span = cols.length + 1, shown = 0;
    state.tree.dispositivos.forEach(function (d) {
      if (f.olt && String(d.id) !== f.olt) { return; }
      var rows = [];
      d.pons.forEach(function (p) {
        if (f.pon && String(p.pon_index) !== f.pon) { return; }
        var onus = p.onus.filter(function (o) { return keep(o, f); });
        if (!onus.length && onuLevel(f)) { return; }
        rows.push({ p: p, onus: onus });
      });
      if (!rows.length && onuLevel(f)) { return; }
      var key = 'd' + d.id, tr = el('tr', 'rf-row rf-row--olt'), td = el('td'); td.colSpan = span;
      var b = el('button', 'rf-toggle', d.nome); b.type = 'button'; b.setAttribute('aria-expanded', String(!state.collapsed[key]));
      b.addEventListener('click', function () { state.collapsed[key] = !state.collapsed[key]; renderTree(); });
      td.appendChild(b); td.appendChild(el('span', 'rf-muted', '  · última leitura ok: ' + fmtDT(d.ultima_leitura_ok) + (d.ultimo_resultado && d.ultimo_resultado !== 'ok' ? ' · resultado: ' + d.ultimo_resultado : '')));
      tr.appendChild(td); body.appendChild(tr);
      if (state.collapsed[key]) { return; }
      rows.forEach(function (r) {
        var pk = key + 'p' + r.p.pon_index, ptr = el('tr', 'rf-row rf-row--pon'), ptd = el('td'); ptd.colSpan = span;
        var pb = el('button', 'rf-toggle', r.p.label); pb.type = 'button'; pb.setAttribute('aria-expanded', String(!state.collapsed[pk]));
        pb.addEventListener('click', function () { state.collapsed[pk] = !state.collapsed[pk]; renderTree(); });
        ptd.appendChild(pb); ptd.appendChild(el('span', 'rf-muted', '  · ' + r.p.online + ' online de ' + r.p.total + ' ONU(s)'));
        ptr.appendChild(ptd); body.appendChild(ptr);
        if (state.collapsed[pk]) { return; }
        r.onus.forEach(function (o) {
          var otr = el('tr', 'rf-row rf-row--onu' + (state.selected === o.id ? ' is-selected' : '')); otr.tabIndex = 0; otr.dataset.onu = String(o.id);
          otr.appendChild(el('td', '', 'ONU ' + o.onu_index));
          cols.forEach(function (c) { otr.appendChild(cell(o, c.k)); });
          var open = function () { openSheet(o.id); };
          otr.addEventListener('click', open);
          otr.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
          body.appendChild(otr); shown++;
        });
      });
    });
    $('rf-empty').hidden = shown !== 0;
    var s = state.tree.resumo;
    $('rf-summary').textContent = s.onus + ' ONUs · ' + s.online + ' online · ' + s.offline + ' offline · atenção ' + s.atencao + ' · crítico ' + s.critico +
      ' · caíram em 24 h: ' + s.quedas_24h + ' · sem vínculo: ' + s.sem_vinculo + ' · gerado em ' + fmtDT(state.tree.gerado_em);
    var notes = [];
    if (!state.tree.fontes.quedas_log) { notes.push('Quedas do log da OLT ainda não importadas: as quedas abaixo vêm só da coleta (hora aproximada, sem motivo).'); }
    notes.push('Cliente vinculado e CTO ainda não são lidos (sem vínculo cadastrado).');
    $('rf-note').textContent = notes.join(' '); $('rf-note').hidden = false;
  }
  function renderColsMenu() {
    var box = $('rf-cols-list'); clear(box);
    COLS.forEach(function (c) {
      var l = el('label'), i = el('input'); i.type = 'checkbox'; i.checked = state.cols.indexOf(c.k) !== -1;
      i.addEventListener('change', function () {
        state.cols = COLS.map(function (x) { return x.k; }).filter(function (k) { return k === c.k ? i.checked : state.cols.indexOf(k) !== -1; });
        saveCols(); renderTree();
      });
      l.appendChild(i); l.appendChild(document.createTextNode(' ' + c.l)); box.appendChild(l);
    });
  }

  /* ---------- ficha ---------- */
  function openSheet(id) {
    state.selected = id; renderTree(); $('rf-panel').hidden = false; document.querySelector('.rf-layout').classList.add('has-panel');
    $('rf-p-title').textContent = 'Carregando…'; $('rf-p-sub').textContent = ''; clear($('rf-p-body'));
    loadSheet();
  }
  function loadSheet() {
    var q = '?id=' + encodeURIComponent(state.selected) + '&periodo=' + encodeURIComponent(state.period);
    if (state.period === 'custom') { q += '&de=' + encodeURIComponent(state.from) + '&ate=' + encodeURIComponent(state.to); }
    return get(API.onu + q).then(function (d) { state.sheet = d; renderSheet(); }).catch(function (e) {
      $('rf-p-title').textContent = 'ONU'; clear($('rf-p-body')); $('rf-p-body').appendChild(el('p', 'rf-note', e.message));
    });
  }
  function pct(v) { return Math.max(0, Math.min(100, (v - YMIN) / (YMAX - YMIN) * 100)); }
  function ruler(d) {
    var L = d.limiares, box = el('div'), r = el('div', 'rf-ruler'); r.setAttribute('role', 'img');
    var z = [['crit', pct(L.critico_dbm)], ['warn', pct(L.atencao_dbm) - pct(L.critico_dbm)], ['ok', 100 - pct(L.atencao_dbm)]];
    z.forEach(function (x) { var s = el('div', 'rf-ruler__z rf-ruler__z--' + x[0]); s.style.width = x[1] + '%'; r.appendChild(s); });
    function mark(v, cls, label) { if (v === null || v === undefined) { return; } var m = el('div', 'rf-ruler__mark ' + cls); m.style.left = pct(v) + '%'; m.appendChild(el('span', '', label)); r.appendChild(m); }
    mark(d.onu.baseline_dbm, 'rf-ruler__mark--ref', 'ref. ' + (d.onu.baseline_dbm === null ? '' : d.onu.baseline_dbm.toFixed(1)));
    mark(d.vizinhos.mediana_dbm, 'rf-ruler__mark--med', 'viz. ' + (d.vizinhos.mediana_dbm === null ? '' : d.vizinhos.mediana_dbm.toFixed(1)));
    mark(d.onu.rx_dbm, 'rf-ruler__mark--now', d.onu.rx_dbm === null ? '' : d.onu.rx_dbm.toFixed(1));
    r.setAttribute('aria-label', 'Régua de sinal de ' + YMIN + ' a ' + YMAX + ' dBm: atual ' + dbm(d.onu.rx_dbm));
    box.appendChild(r);
    var sc = el('div', 'rf-ruler__scale'); [YMIN, L.critico_dbm, L.atencao_dbm, YMAX].forEach(function (v) { sc.appendChild(el('span', '', String(v))); }); box.appendChild(sc);
    box.appendChild(el('p', 'rf-legend', 'Crítico < ' + L.critico_dbm + ' · Atenção < ' + L.atencao_dbm + ' dBm · linha cheia = atual, tracejada = referência, pontilhada = mediana dos vizinhos'));
    return box;
  }
  function outageRows(list, max) {
    var t = el('table', 'rf-outages'), h = el('tr');
    ['Início', 'Retorno', 'Duração', 'Motivo'].forEach(function (x) { h.appendChild(el('th', '', x)); }); t.appendChild(h);
    list.slice(0, max).forEach(function (o) {
      var r = el('tr');
      r.appendChild(el('td', '', fmtDT(o.started_at)));
      r.appendChild(el('td', '', o.open ? 'em aberto' : fmtDT(o.ended_at)));
      r.appendChild(el('td', '', o.open ? '—' : dur(o.duration_s)));
      r.appendChild(el('td', '', o.reason + (o.source === 'poll' ? ' (hora aproximada)' : '')));
      t.appendChild(r);
    });
    return t;
  }
  function renderSheet() {
    var d = state.sheet, o = d.onu, body = $('rf-p-body'); clear(body);
    $('rf-p-title').textContent = 'ONU ' + o.onu_index + ' · PON ' + o.pon_index;
    $('rf-p-sub').textContent = o.mac + ' · ' + (STATUS[o.status] || o.status) + (o.status_desde ? ' desde ' + fmtDT(o.status_desde) : '');
    var s1 = el('div'); s1.appendChild(el('h3', '', 'Sinal')); s1.appendChild(ruler(d)); body.appendChild(s1);
    var g = el('dl', 'rf-grid');
    function row(k, v) { g.appendChild(el('dt', '', k)); g.appendChild(el('dd', '', v)); }
    row('Sinal atual', dbm(o.rx_dbm) + (o.rx_dbm === null ? '' : ' · ' + (BAND[o.rx_band] || '')));
    row('Referência', o.baseline_dbm === null ? 'ainda sem referência' : dbm(o.baseline_dbm) + ' (' + o.baseline_amostras + ' leituras)');
    row('Mediana dos vizinhos', d.vizinhos.mediana_dbm === null ? 'sem vizinhos com leitura' : dbm(d.vizinhos.mediana_dbm) + ' (' + d.vizinhos.quantidade + ' ONUs' + (d.vizinhos.diferenca_db === null ? '' : ', diferença ' + (d.vizinhos.diferenca_db > 0 ? '+' : '') + d.vizinhos.diferenca_db.toFixed(2) + ' dB') + ')');
    row('Tx da ONU', dbm(o.tx_dbm)); row('Temperatura', o.temp_c === null ? 'sem leitura' : o.temp_c.toFixed(1) + ' °C');
    row('Distância', o.distancia_m === null ? '—' : '≈ ' + (o.distancia_m / 1000).toFixed(2) + ' km (estimada pelo RTT)');
    row('Sinal recebido na OLT', d.onu.olt_rx_texto); row('Cliente', d.cliente_texto); row('CTO', d.cto_texto);
    row('Última leitura', fmtDT(o.rx_medido_em));
    body.appendChild(g);

    var cb = el('div', 'rf-chartbar'); cb.appendChild(el('h3', '', 'Sinal e quedas, 7 dias'));
    var big = el('button', '', 'Ampliar'); big.type = 'button'; big.addEventListener('click', function () { openModal(); }); cb.appendChild(big); body.appendChild(cb);
    var ch = el('div', 'rf-chart'); ch.id = 'rf-p-chart'; body.appendChild(ch); drawChart(ch, d, false);

    var pr = el('div'); pr.appendChild(el('h3', '', 'Quedas'));
    var per = el('div', 'rf-periods');
    [['1h', '1 h'], ['24h', '24 h'], ['7d', '1 semana'], ['custom', 'Personalizado']].forEach(function (p) {
      var b = el('button', state.period === p[0] ? 'is-on' : '', p[1]); b.type = 'button';
      b.addEventListener('click', function () { state.period = p[0]; if (p[0] !== 'custom') { loadSheet(); } else { renderSheet(); } });
      per.appendChild(b);
    });
    pr.appendChild(per);
    if (state.period === 'custom') {
      var c = el('div', 'rf-custom'), a = el('input'), b2 = el('input'), go = el('button', '', 'Aplicar');
      a.type = 'datetime-local'; b2.type = 'datetime-local'; a.id = 'rf-c-from'; b2.id = 'rf-c-to'; a.value = state.from; b2.value = state.to; go.type = 'button';
      go.addEventListener('click', function () { state.from = a.value; state.to = b2.value; if (!state.from) { return; } loadSheet(); });
      c.appendChild(el('span', '', 'de')); c.appendChild(a); c.appendChild(el('span', '', 'até')); c.appendChild(b2); c.appendChild(go); pr.appendChild(c);
    }
    var tot = d.quedas_total;
    pr.appendChild(el('p', 'rf-legend', 'Período: ' + fmtDT(d.periodo.from) + ' a ' + fmtDT(d.periodo.to) + ' · ' + tot.quedas + ' queda(s)' + (tot.em_aberto ? ' (' + tot.em_aberto + ' em aberto)' : '')));
    if (d.estavel) { pr.appendChild(el('p', 'rf-note', d.estavel_texto)); } else { pr.appendChild(outageRows(d.quedas, 8)); }
    body.appendChild(pr);
    var last = el('div'); last.appendChild(el('h3', '', 'Últimas quedas (30 dias)'));
    if (d.ultimas_quedas.length) { last.appendChild(outageRows(d.ultimas_quedas, 5)); } else { last.appendChild(el('p', 'rf-legend', 'Nenhuma queda registrada em 30 dias.')); }
    last.appendChild(el('p', 'rf-legend', d.fontes.quedas_log ? 'Motivo "sem aviso de energia" não indica a causa física.' : 'Log da OLT ainda não importado: horas aproximadas e sem motivo.'));
    body.appendChild(last);
  }

  /* ---------- gráfico ---------- */
  function drawChart(box, d, big) {
    clear(box);
    var W = 720, H = big ? 400 : 210, ml = 44, mr = 10, mt = 10, mb = 24, S = d.serie_7d;
    var t0 = parse(S.de), t1 = parse(S.ate), pts = S.pontos.map(function (p) { return { t: parse(p.t), s: p.t, v: p.rx }; });
    function X(t) { return ml + (Math.max(t0, Math.min(t1, t)) - t0) / (t1 - t0) * (W - ml - mr); }
    function Y(v) { return mt + (YMAX - Math.max(YMIN, Math.min(YMAX, v))) / (YMAX - YMIN) * (H - mt - mb); }
    var s = svg('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': 'Sinal da ONU nos últimos 7 dias, com as quedas como faixas verticais' });
    var L = d.limiares;
    [[YMIN, L.critico_dbm, '#f6d8d5'], [L.critico_dbm, L.atencao_dbm, '#fbefc7'], [L.atencao_dbm, YMAX, '#e6f4ea']].forEach(function (z) {
      s.appendChild(svg('rect', { x: ml, y: Y(z[1]), width: W - ml - mr, height: Y(z[0]) - Y(z[1]), fill: z[2] }));
    });
    [YMIN, L.critico_dbm, L.atencao_dbm, -20, YMAX].forEach(function (v) {
      s.appendChild(svg('line', { x1: ml, x2: W - mr, y1: Y(v), y2: Y(v), stroke: '#c9d3dc', 'stroke-width': 1 }));
      var tx = svg('text', { x: ml - 5, y: Y(v) + 4, 'text-anchor': 'end', 'font-size': 11, fill: '#617587' }); tx.textContent = String(v); s.appendChild(tx);
    });
    for (var day = Math.ceil(t0 / 86400000) * 86400000; day <= t1; day += 86400000) {
      var dd = new Date(day), tx2 = svg('text', { x: X(day), y: H - 6, 'text-anchor': 'middle', 'font-size': 11, fill: '#617587' });
      tx2.textContent = pad(dd.getDate()) + '/' + pad(dd.getMonth() + 1); s.appendChild(tx2);
    }
    var outs = d.quedas_serie.map(function (q) { return { a: parse(q.started_at), b: q.ended_at ? parse(q.ended_at) : t1, q: q }; });
    outs.forEach(function (o) {
      var x = X(o.a), w = Math.max(2.5, X(o.b) - x), r = svg('rect', { x: x, y: mt, width: w, height: H - mt - mb, fill: '#d64545', opacity: 0.35, class: 'rf-outage' });
      var tt = svg('title'); tt.textContent = 'Queda ' + fmtDT(o.q.started_at) + ' · ' + o.q.reason; r.appendChild(tt); s.appendChild(r);
    });
    if (!pts.length) {
      var nt = svg('text', { x: W / 2, y: H / 2, 'text-anchor': 'middle', 'font-size': 13, fill: '#617587' }); nt.textContent = 'Sem leituras de sinal nos últimos 7 dias'; s.appendChild(nt);
    } else {
      var path = 'M' + X(pts[0].t) + ' ' + Y(pts[0].v);
      for (var i = 1; i < pts.length; i++) { path += ' H' + X(pts[i].t) + ' V' + Y(pts[i].v); }
      path += ' H' + X(t1);
      s.appendChild(svg('path', { d: path, fill: 'none', stroke: '#17324d', 'stroke-width': 2, class: 'rf-line' }));
      pts.forEach(function (p) { s.appendChild(svg('circle', { cx: X(p.t), cy: Y(p.v), r: 2.6, fill: '#17324d' })); });
    }
    var cur = svg('line', { x1: 0, x2: 0, y1: mt, y2: H - mb, stroke: '#0f766e', 'stroke-width': 1, visibility: 'hidden' }); s.appendChild(cur);
    var dot = svg('circle', { r: 4, fill: '#0f766e', visibility: 'hidden' }); s.appendChild(dot);
    var tip = el('div', 'rf-tip'); tip.hidden = true;
    var ov = svg('rect', { x: ml, y: mt, width: W - ml - mr, height: H - mt - mb, fill: 'transparent', class: 'rf-overlay' }); s.appendChild(ov);
    function at(ev) {
      var r = s.getBoundingClientRect(), vx = r.width ? (ev.clientX - r.left) / r.width * W : ev.clientX;
      vx = Math.max(ml, Math.min(W - mr, vx));
      var t = t0 + (vx - ml) / (W - ml - mr) * (t1 - t0), val = null, i2;
      for (i2 = 0; i2 < pts.length; i2++) { if (pts[i2].t <= t) { val = pts[i2]; } }
      var inQ = outs.filter(function (o) { return t >= o.a && t <= o.b; })[0];
      var dt = new Date(t), label = pad(dt.getDate()) + '/' + pad(dt.getMonth() + 1) + ' ' + pad(dt.getHours()) + ':' + pad(dt.getMinutes());
      tip.textContent = label + ' · ' + (val ? val.v.toFixed(2) + ' dBm' : 'sem leitura') + (inQ ? ' · em queda (' + inQ.q.reason + ')' : '');
      tip.hidden = false; tip.style.left = (vx / W * 100) + '%'; tip.style.top = (val ? Y(val.v) / H * 100 : 20) + '%';
      cur.setAttribute('x1', vx); cur.setAttribute('x2', vx); cur.setAttribute('visibility', 'visible');
      if (val) { dot.setAttribute('cx', vx); dot.setAttribute('cy', Y(val.v)); dot.setAttribute('visibility', 'visible'); } else { dot.setAttribute('visibility', 'hidden'); }
    }
    function hide() { tip.hidden = true; cur.setAttribute('visibility', 'hidden'); dot.setAttribute('visibility', 'hidden'); }
    ov.addEventListener('pointermove', at); ov.addEventListener('pointerdown', at); ov.addEventListener('pointerleave', hide);
    box.appendChild(s); box.appendChild(tip);
  }
  function openModal() { $('rf-modal').hidden = false; drawChart($('rf-m-chart'), state.sheet, true); }

  /* ---------- carga e eventos ---------- */
  function loadTree() {
    $('rf-summary').textContent = 'Carregando…';
    return get(API.tree).then(function (d) { state.tree = d; fillSelectors(); renderTree(); if (state.selected) { loadSheet(); } })
      .catch(function (e) { $('rf-summary').textContent = e.message; });
  }
  ['rf-f-status', 'rf-f-down24', 'rf-f-novinc'].forEach(function (id) { $(id).addEventListener('change', function () { if (state.tree) { renderTree(); } }); });
  $('rf-f-q').addEventListener('input', function () { if (state.tree) { renderTree(); } });
  $('rf-f-olt').addEventListener('change', function () { if (state.tree) { fillPons(); renderTree(); } });
  $('rf-f-pon').addEventListener('change', function () { if (state.tree) { renderTree(); } });
  $('rf-refresh').addEventListener('click', loadTree);
  $('rf-p-close').addEventListener('click', function () { state.selected = null; $('rf-panel').hidden = true; document.querySelector('.rf-layout').classList.remove('has-panel'); if (state.tree) { renderTree(); } });
  $('rf-m-close').addEventListener('click', function () { $('rf-modal').hidden = true; });
  $('rf-modal').addEventListener('click', function (e) { if (e.target === $('rf-modal')) { $('rf-modal').hidden = true; } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { $('rf-modal').hidden = true; } });
  renderColsMenu();
  loadTree();
})();
