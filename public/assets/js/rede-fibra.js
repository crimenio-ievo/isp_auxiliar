/* Rede de fibra: árvore OLT > PON > ONU e ficha da ONU. Somente leitura, sem dados simulados.
   Todo texto vem do servidor via textContent (nunca innerHTML). */
(function () {
  'use strict';
  var app = document.getElementById('rf-app');
  if (!app) { return; }
  var API = { tree: app.dataset.apiTree, onu: app.dataset.apiOnu, events: app.dataset.apiEvents };
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
  var TYPE_LABEL = { olt: 'OLT', switch: 'Switch', router: 'Roteador', radio: 'Rádio', server: 'Servidor', other: 'Equipamento' };
  var SITE_LABEL = { pop: 'POP', tower: 'Torre' };
  var STATUS = { online: 'Online', offline: 'Offline', unknown: 'Desconhecido' };
  var state = { tree: null, cols: loadCols(), collapsed: {}, selected: null, period: '7d', from: '', to: '', sheet: null, evPeriod: '24h' };

  function loadCols() {
    try { var v = JSON.parse(window.localStorage.getItem('rf.cols') || 'null'); if (Array.isArray(v)) { return v; } } catch (e) { /* sem storage */ }
    return DEFAULT_COLS.slice();
  }
  function saveCols() { try { window.localStorage.setItem('rf.cols', JSON.stringify(state.cols)); } catch (e) { /* sem storage */ } }

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined && text !== null) { n.textContent = text; } return n; }
  function svg(tag, attrs) { var n = document.createElementNS(NS, tag); Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); }); return n; }
  function clear(n) { while (n.firstChild) { n.removeChild(n.firstChild); } }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  /* Horários da API estão em UTC ("AAAA-MM-DD hh:mm:ss"); a tela mostra America/Sao_Paulo. */
  var TZ = 'America/Sao_Paulo';
  var fmtParts = new Intl.DateTimeFormat('pt-BR', { timeZone: TZ, day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
  var fullParts = new Intl.DateTimeFormat('en-CA', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });
  function parts(f, ms) { var o = {}; f.formatToParts(new Date(ms)).forEach(function (p) { o[p.type] = p.value; }); return o; }
  function parse(s) { var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/.exec(String(s)); return m ? Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0)) : NaN; }
  function fmtMs(ms) { var p = parts(fmtParts, ms); return p.day + '/' + p.month + ' ' + p.hour + ':' + p.minute; }
  function fmtDT(s) { var ms = s ? parse(s) : NaN; return isNaN(ms) ? '—' : fmtMs(ms); }
  function fmtDay(ms) { var p = parts(fmtParts, ms); return p.day + '/' + p.month; }
  function spOffsetMs(utcMs) { var p = parts(fullParts, utcMs); return Date.UTC(+p.year, +p.month - 1, +p.day, +p.hour, +p.minute, +p.second) - Math.floor(utcMs / 1000) * 1000; }
  /* "AAAA-MM-DDThh:mm" digitado em horário de Brasília -> "AAAA-MM-DDThh:mm" em UTC. */
  function brtToUtc(v) {
    var local = parse(v); if (isNaN(local)) { return ''; }
    var utc = local - spOffsetMs(local); utc = local - spOffsetMs(utc);
    var d = new Date(utc); return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()) + 'T' + pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes());
  }
  function dur(sec) {
    if (sec === null || sec === undefined) { return '—'; }
    if (sec < 60) { return sec + ' s'; }
    var m = Math.floor(sec / 60), s = sec % 60, h = Math.floor(m / 60);
    if (h > 0) { return h + ' h ' + pad(m % 60) + ' min'; }
    return m + ' min' + (s ? ' ' + s + ' s' : '');
  }
  function num(v, d) { return Number(v).toFixed(d).replace('.', ','); }
  function dbm(v, d) { return v === null || v === undefined ? 'sem leitura' : num(v, d === undefined ? 2 : d) + ' dBm'; }
  function pill(kind, text) { return el('span', 'rf-pill rf-pill--' + kind, text); }
  function get(url) {
    return window.fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then(function (r) {
      return r.json().then(function (b) { if (!r.ok || !b.ok) { throw new Error(b && b.message || 'Falha ao ler os dados.'); } return b.data; });
    });
  }

  /* ---------- filtros e árvore ---------- */
  function filters() {
    return { site: $('rf-f-site').value, olt: $('rf-f-olt').value, pon: $('rf-f-pon').value, status: $('rf-f-status').value, down: $('rf-f-down24').checked,
      novinc: $('rf-f-novinc').checked, q: '' };
  }
  function onuLevel(f) { return !!(f.status || f.down || f.novinc || f.q); }
  function keep(o, f) {
    if (f.status === 'sem_resposta') { if (!o.desatualizado) { return false; } } else if (f.status && (o.desatualizado || o.status !== f.status)) { return false; }
    if (f.down && !(o.quedas_24h > 0)) { return false; }
    if (f.novinc && o.cliente !== null) { return false; }
    if (f.q && o.mac.replace(/:/g, '').toLowerCase().indexOf(f.q) === -1) { return false; }
    return true;
  }
  function siteKey(d) { return d.site ? String(d.site.id) : '0'; }
  function fillSelectors() {
    var site = $('rf-f-site'), sc = site.value, olt = $('rf-f-olt'), cur = olt.value, seen = {};
    clear(site); site.appendChild(new Option('Todos', ''));
    state.tree.dispositivos.forEach(function (d) { var k = siteKey(d); if (!seen[k]) { seen[k] = 1; site.appendChild(new Option(d.site ? d.site.nome : 'Sem site', k)); } });
    site.value = sc;
    clear(olt); olt.appendChild(new Option('Todos', ''));
    state.tree.dispositivos.forEach(function (d) { olt.appendChild(new Option(d.nome, String(d.id))); });
    olt.value = cur;
    fillPons();
  }
  function fillPons() {
    var pon = $('rf-f-pon'), cur = pon.value, seen = {};
    clear(pon); pon.appendChild(new Option('Todas', ''));
    state.tree.dispositivos.forEach(function (d) {
      if ($('rf-f-olt').value && String(d.id) !== $('rf-f-olt').value) { return; }
      if ($('rf-f-site').value && siteKey(d) !== $('rf-f-site').value) { return; }
      d.pons.forEach(function (p) { if (!seen[p.pon_index]) { seen[p.pon_index] = 1; pon.appendChild(new Option(p.label, String(p.pon_index))); } });
    });
    pon.value = cur;
  }
  function cell(o, k) {
    var td = el('td');
    if (k === 'status') {
      if (o.desatualizado) { td.appendChild(pill('unknown', 'Sem resposta')); td.appendChild(el('span', 'rf-muted', ' · último: ' + (STATUS[o.status] || o.status))); td.title = 'A OLT não respondeu na última coleta: este estado pode estar desatualizado.'; }
      else { td.appendChild(pill(o.status, STATUS[o.status] || o.status)); }
    }
    else if (k === 'rx') { var bar=el('span','rf-signal'); var fill=el('span','rf-signal__fill rf-pill--'+o.rx_band); var v=o.rx_dbm,L=state.tree.limiares; var width=v===null?0:v<L.critico_dbm?Math.max(0,(v+32)/(L.critico_dbm+32))*100/3:v<L.atencao_dbm?100/3+(v-L.critico_dbm)/(L.atencao_dbm-L.critico_dbm)*100/3:200/3+(v-L.atencao_dbm)/(-8-L.atencao_dbm)*100/3; fill.style.width=Math.min(100,width)+'%'; bar.appendChild(fill); td.appendChild(bar);td.appendChild(el('span','',v===null?'sem leitura':v.toFixed(2)+' dBm')); }
    else if (k === 'band') { td.appendChild(pill(o.rx_band, BAND[o.rx_band] || o.rx_band)); }
    else if (k === 'tx') { td.textContent = o.tx_dbm === null ? 'sem leitura' : o.tx_dbm.toFixed(2); }
    else if (k === 'temp') { td.textContent = o.temp_c === null ? 'sem leitura' : num(o.temp_c, 1); }
    else if (k === 'dist') { td.textContent = o.distancia_m === null ? '—' : '≈ ' + (o.distancia_m / 1000).toFixed(2) + ' km'; if (o.distancia_origem) { td.title = o.distancia_origem; } }
    else if (k === 'cliente') { td.textContent = o.cliente === null ? 'sem vínculo' : String(o.cliente); if (o.cliente === null) { td.className = 'rf-muted'; } }
    else if (k === 'cto') { td.textContent = o.cto === null ? 'ainda não lido' : String(o.cto); if (o.cto === null) { td.className = 'rf-muted'; } }
    else if (k === 'quedas') { td.textContent = o.quedas_24h > 0 ? String(o.quedas_24h) : '0'; if (o.quedas_24h > 0) { td.appendChild(el('span', 'rf-muted', ' · ' + fmtDT(o.ultima_queda_em))); } }
    else if (k === 'mac') { td.textContent = o.mac; }
    return td;
  }
  function counts(onus) { var c={ok:0,atencao:0,critico:0,offline:0}; onus.forEach(function(o){if(o.status==='offline')c.offline++;else if(o.rx_dbm!==null){c[o.rx_band==='saturado'?'atencao':o.rx_band]=(c[o.rx_band==='saturado'?'atencao':o.rx_band]||0)+1;}});return c; }
  function stacked(onus) { var c=counts(onus),bar=el('div','rf-stacked'); ['ok','atencao','critico','offline'].forEach(function(k){var part=el('span','rf-pill--'+k);part.style.width=(onus.length?100*c[k]/onus.length:0)+'%';part.title=k+': '+c[k];bar.appendChild(part);});return bar; }
  function decorateGroup(row,cell,key,onus,data) {
    var toggle=cell.querySelector('.rf-toggle'), label=el('span','rf-group-label',toggle.textContent); toggle.setAttribute('aria-label','Expandir ou recolher '+toggle.textContent); toggle.textContent=''; toggle.after(label);
    var badges=el('span','rf-group-counts'),c=counts(onus);Object.keys(c).forEach(function(k){badges.appendChild(pill(k,k+' '+c[k]));});cell.appendChild(badges);row.tabIndex=0;
    function open(){if(state.group===key){state.group=null;state.selected=null;$('rf-panel').hidden=true;document.querySelector('.rf-layout').classList.remove('has-panel');return;}state.group=key;state.selected=null;var body=$('rf-p-body');clear(body);$('rf-panel').hidden=false;document.querySelector('.rf-layout').classList.add('has-panel');$('rf-p-title').textContent=data.kind==='site'?(data.site?data.site.nome:'Sem site'):data.kind==='device'?data.device.nome:data.pon.label;$('rf-p-sub').textContent='';
      var online=onus.filter(function(o){return o.status==='online';}).length;
      body.appendChild(el('p','',online+' online / '+onus.length+' ONUs'));body.appendChild(stacked(onus));
      if(data.kind==='site')body.appendChild(el('p','',data.devs.length+' equipamentos'));
      if(data.kind==='device'){var d=data.device,m=d.modelo||{};body.appendChild(el('p','',(TYPE_LABEL[d.tipo_no]||d.tipo_no)+' · '+([m.fabricante,m.modelo].filter(Boolean).join(' ')||'a identificar')+' · '+(d.ip||'ainda não lido')+' · '+(d.tecnologia||'a identificar')));body.appendChild(el('p','',d.pons.length+' PONs · última coleta '+fmtDT(d.ultima_leitura_ok)));body.appendChild(el('p','','CPU: ainda não lido'));if(d.ip&&/^[a-zA-Z0-9.:-]+$/.test(d.ip)){var a=el('a','','Abrir web da OLT');a.href='http://'+d.ip+'/';a.target='_blank';a.rel='noopener noreferrer';body.appendChild(a);}d.pons.forEach(function(p){body.appendChild(el('p','',p.label));body.appendChild(stacked(p.onus));});}
      if(data.kind==='pon'){body.appendChild(el('p','','GBIC: a coletar · Potência de saída: a coletar'));var valid=onus.filter(function(o){return o.status==='online'&&o.rx_dbm!==null;}).sort(function(a,b){return a.rx_dbm-b.rx_dbm;});if(valid.length){var mid=Math.floor(valid.length/2),median=valid.length%2?valid[mid].rx_dbm:(valid[mid-1].rx_dbm+valid[mid].rx_dbm)/2;body.appendChild(el('p','','Mediana '+dbm(median)));var worst=el('button','','Pior ONU '+valid[0].onu_index+' · '+dbm(valid[0].rx_dbm));worst.addEventListener('click',function(){openSheet(valid[0].id);});body.appendChild(worst);}else body.appendChild(el('p','','Mediana: sem leitura'));var refresh=el('button','','Atualizar PON');refresh.disabled=true;refresh.title='leitura sob demanda ainda não disponível';body.appendChild(refresh);}}
    row.addEventListener('click',open);row.addEventListener('keydown',function(e){if(e.target===row&&(e.key==='Enter'||e.key===' ')){e.preventDefault();open();}});
  }
  function renderTree() {
    var f = filters(), head = $('rf-table').tHead, body = $('rf-table').tBodies[0], cols = COLS.filter(function (c) { return state.cols.indexOf(c.k) !== -1; });
    clear(head); clear(body);
    var hr = el('tr'); hr.appendChild(el('th', '', 'ONU'));
    cols.forEach(function (c) { hr.appendChild(el('th', '', c.l)); });
    head.appendChild(hr);
    var span = cols.length + 1, shown = 0;
    var groups = [], gi = {};
    state.tree.dispositivos.forEach(function (d) {
      if (f.site && siteKey(d) !== f.site) { return; }
      if (f.olt && String(d.id) !== f.olt) { return; }
      var k = siteKey(d); if (gi[k] === undefined) { gi[k] = groups.length; groups.push({ key: k, site: d.site, devs: [] }); }
      groups[gi[k]].devs.push(d);
    });
    groups.forEach(function (g) {
      var skey = 's' + g.key, drows = [];
      g.devs.forEach(function (d) {
        var rows = [];
        d.pons.forEach(function (p) {
          if (f.pon && String(p.pon_index) !== f.pon) { return; }
          var onus = p.onus.filter(function (o) { return keep(o, f); });
          if (!onus.length && onuLevel(f)) { return; }
          rows.push({ p: p, onus: onus });
        });
        if (!rows.length && onuLevel(f)) { return; }
        drows.push({ d: d, rows: rows });
      });
      if (!drows.length && onuLevel(f)) { return; }
      var str = el('tr', 'rf-row rf-row--site'), std = el('td'); std.colSpan = span;
      var sb = el('button', 'rf-toggle', g.site ? (SITE_LABEL[g.site.tipo] || 'Site') + ' ' + g.site.nome : 'Sem site'); sb.type = 'button'; sb.setAttribute('aria-expanded', String(!state.collapsed[skey]));
      sb.addEventListener('click', function (e) { e.stopPropagation(); state.collapsed[skey] = !state.collapsed[skey]; renderTree(); });
      std.appendChild(sb); std.appendChild(el('span', 'rf-muted', '  · ' + g.devs.length + ' equipamento(s)'));
      decorateGroup(str,std,skey,g.devs.flatMap(function(d){return d.pons.flatMap(function(p){return p.onus;});}),{kind:'site',site:g.site,devs:g.devs}); str.appendChild(std); body.appendChild(str);
      if (state.collapsed[skey]) { return; }
      drows.forEach(function (dr) {
        var d = dr.d, rows = dr.rows, key = 'd' + d.id, tr = el('tr', 'rf-row rf-row--eqp'), td = el('td'); td.colSpan = span;
        var b = el('button', 'rf-toggle', (TYPE_LABEL[d.tipo_no] || 'Equipamento') + ' ' + d.nome); b.type = 'button'; b.setAttribute('aria-expanded', String(!state.collapsed[key]));
        b.addEventListener('click', function (e) { e.stopPropagation(); state.collapsed[key] = !state.collapsed[key]; renderTree(); });
        td.appendChild(b); td.appendChild(el('span', 'rf-muted', ([((d.modelo||{}).fabricante),((d.modelo||{}).modelo)].filter(Boolean).length ? ' · '+[d.modelo.fabricante,d.modelo.modelo].filter(Boolean).join(' ') : ' · a identificar') + (d.tecnologia ? '  · ' + String(d.tecnologia).toUpperCase() : '') + (d.ip ? ' · '+d.ip : '') + '  · última leitura ok: ' + fmtDT(d.ultima_leitura_ok) + (d.ultimo_resultado && d.ultimo_resultado !== 'ok' ? ' · resultado: ' + d.ultimo_resultado : '')));
        decorateGroup(tr,td,key,d.pons.flatMap(function(p){return p.onus;}),{kind:'device',device:d}); tr.appendChild(td); body.appendChild(tr);
        if (state.collapsed[key]) { return; }
        rows.forEach(function (r) {
          var pk = key + 'p' + r.p.pon_index, ptr = el('tr', 'rf-row rf-row--pon'), ptd = el('td'); ptd.colSpan = span;
          var pb = el('button', 'rf-toggle', r.p.label); pb.type = 'button'; pb.setAttribute('aria-expanded', String(!state.collapsed[pk]));
          pb.addEventListener('click', function (e) { e.stopPropagation(); state.collapsed[pk] = !state.collapsed[pk]; renderTree(); });
          ptd.appendChild(pb); ptd.appendChild(el('span', 'rf-muted', '  · ' + r.p.online + ' online de ' + r.p.total + ' ONU(s)'));
          decorateGroup(ptr,ptd,pk,r.p.onus,{kind:'pon',pon:r.p,device:d}); ptr.appendChild(ptd); body.appendChild(ptr);
          if (state.collapsed[pk]) { return; }
          r.onus.forEach(function (o) {
            var otr = el('tr', 'rf-row rf-row--onu' + (state.selected === o.id ? ' is-selected' : '')); otr.tabIndex = 0; otr.dataset.onu = String(o.id);
            otr.appendChild(el('td', '', 'ONU ' + o.onu_index));
            cols.forEach(function (c) { otr.appendChild(cell(o, c.k)); });
            var open = function () { state.group=null; openSheet(o.id); };
            otr.addEventListener('click', open);
            otr.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
            body.appendChild(otr); shown++;
          });
        });
      });
    });
    $('rf-empty').hidden = shown !== 0;
    var s = state.tree.resumo;
    $('rf-summary').textContent = s.onus + ' ONUs · ' + s.online + ' online · ' + s.offline + ' offline · atenção ' + s.atencao + ' · crítico ' + s.critico +
      ' · caíram em 24 h: ' + s.quedas_24h + (s.sem_resposta ? ' · sem resposta: ' + s.sem_resposta : '') + ' · sem vínculo: ' + s.sem_vinculo + ' · gerado em ' + fmtDT(state.tree.gerado_em);
    renderHealth();
    var notes = [];
    if (!state.tree.fontes.quedas_log) { notes.push('Quedas do log da OLT ainda não importadas: as quedas abaixo vêm só da coleta (hora aproximada, sem motivo).'); }
    notes.push('Cliente vinculado e CTO ainda não são lidos (sem vínculo cadastrado).');
    $('rf-note').textContent = notes.join(' '); $('rf-note').hidden = false;
  }
  /* Aviso de saúde da coleta (vem do servidor: ok, atrasada, falha ou sem_leitura). Não afirma a causa. */
  function renderHealth() {
    var box = $('rf-health'); clear(box); var n = 0;
    state.tree.dispositivos.forEach(function (d) {
      var h = d.saude; if (!h || h.estado === 'ok') { return; }
      var who = (TYPE_LABEL[d.tipo_no] || 'Equipamento') + ' ' + d.nome, p = el('p', 'rf-health__item');
      if (h.estado === 'sem_leitura') { p.textContent = who + ': ainda sem nenhuma leitura registrada. Os estados abaixo podem estar desatualizados.'; }
      else { p.textContent = who + ' sem resposta há ' + dur(h.minutos * 60) + ': os estados abaixo podem estar desatualizados (última leitura ok: ' + fmtDT(d.ultima_leitura_ok) + ', horário de Brasília' + (d.ultimo_resultado && d.ultimo_resultado !== 'ok' ? '; resultado da última coleta: ' + d.ultimo_resultado : '') + ').'; }
      box.appendChild(p); n++;
    });
    box.hidden = n === 0;
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
    state.group=null;
    if (state.period === 'custom' && !state.from) { state.period = '7d'; }
    state.selected = id; renderTree(); $('rf-panel').hidden = false; document.querySelector('.rf-layout').classList.add('has-panel');
    $('rf-p-title').textContent = 'Carregando…'; $('rf-p-sub').textContent = ''; clear($('rf-p-body'));
    loadSheet();
  }
  function loadSheet() {
    if (state.period === 'custom' && !state.from) { return Promise.resolve(); }
    var requested = state.selected;
    var q = '?id=' + encodeURIComponent(state.selected) + '&periodo=' + encodeURIComponent(state.period);
    if (state.period === 'custom') { q += '&de=' + encodeURIComponent(brtToUtc(state.from)) + (state.to ? '&ate=' + encodeURIComponent(brtToUtc(state.to)) : ''); }
    return get(API.onu + q).then(function (d) { if (state.selected !== requested || state.group) { return; } state.sheet = d; renderSheet(); }).catch(function (e) {
      if (state.selected !== requested || state.group) { return; }
      $('rf-p-title').textContent = 'ONU'; clear($('rf-p-body')); $('rf-p-body').appendChild(el('p', 'rf-note', e.message));
    });
  }
  function pct(v) { return Math.max(0, Math.min(100, (v - YMIN) / (YMAX - YMIN) * 100)); }
  function ruler(d) {
    var L = d.limiares, box = el('div'), r = el('div', 'rf-ruler'); r.setAttribute('role', 'img');
    var z = [['crit', pct(L.critico_dbm), 'Crítico'], ['warn', pct(L.atencao_dbm) - pct(L.critico_dbm), 'Atenção'], ['ok', 100 - pct(L.atencao_dbm), 'Normal']];
    z.forEach(function (x) { var part = el('div', 'rf-ruler__z rf-ruler__z--' + x[0], x[2]); part.style.width = x[1] + '%'; r.appendChild(part); });
    var values = [{v:d.onu.rx_dbm, name:'Esta ONU', cls:'now'}, {v:d.vizinhos.mediana_dbm, name:'Vizinhos', cls:'med'}, {v:d.onu.baseline_dbm, name:'Referência', cls:'ref'}].filter(function (x) { return x.v !== null && x.v !== undefined; });
    values.forEach(function (x) { var marker=el('div','rf-ruler__mark rf-ruler__mark--'+x.cls); marker.style.left=pct(x.v)+'%'; r.appendChild(marker); });
    var groups=[];
    values.forEach(function (x) { var group=groups.find(function (g) { return Math.abs(g[0].v-x.v)<0.3; }); if(group) group.push(x); else groups.push([x]); });
    var below=[];
    groups.forEach(function (g) {
      var x=g[0], label=el('span','rf-ruler__label '+(g.some(function(q){return q.cls==='now';})?'rf-ruler__label--above':'rf-ruler__label--below'),g.map(function(q){return q.name;}).join(' = ')+' '+num(x.v,1));
      label.style.left=pct(x.v)+'%';
      if (!g.some(function(q){return q.cls==='now';})) { if (below.some(function(p){return Math.abs(p-pct(x.v))<14;})) label.style.top='53px'; below.push(pct(x.v)); }
      r.appendChild(label);
    });
    r.setAttribute('aria-label', 'Régua de sinal de ' + YMIN + ' a ' + YMAX + ' dBm: atual ' + dbm(d.onu.rx_dbm)); box.appendChild(r);
    var sc = el('div', 'rf-ruler__scale'); [YMIN, L.critico_dbm, L.atencao_dbm, YMAX].forEach(function (v) { sc.appendChild(el('span', '', String(v))); }); box.appendChild(sc);
    box.appendChild(el('p', 'rf-legend', 'Crítico abaixo de ' + L.critico_dbm + ' dBm · Atenção abaixo de ' + L.atencao_dbm + ' dBm'));
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
    $('rf-p-title').textContent = 'ONU ' + o.onu_index + ' · PON ' + o.pon_index + ' · ONU ou ONT: a identificar';
    $('rf-p-sub').textContent = o.mac + ' · ' + (STATUS[o.status] || o.status) + (o.status_desde ? ' desde ' + fmtDT(o.status_desde) : '');
    var s1 = el('div'); s1.appendChild(el('h3', '', 'Sinal')); s1.appendChild(ruler(d)); body.appendChild(s1);
    var g = el('dl', 'rf-grid');
    function row(k, v) { g.appendChild(el('dt', '', k)); g.appendChild(el('dd', '', v)); }
    row('Sinal atual', dbm(o.rx_dbm) + (o.rx_dbm === null ? '' : ' · ' + (BAND[o.rx_band] || '')));
    row('Referência', o.baseline_dbm === null ? 'ainda sem referência' : dbm(o.baseline_dbm) + ' (' + o.baseline_amostras + ' leituras)');
    row('Mediana dos vizinhos', d.vizinhos.mediana_dbm === null ? 'sem vizinhos com leitura' : dbm(d.vizinhos.mediana_dbm) + ' (' + d.vizinhos.quantidade + ' ONUs' + (d.vizinhos.diferenca_db === null ? '' : ', diferença ' + (d.vizinhos.diferenca_db > 0 ? '+' : '') + num(d.vizinhos.diferenca_db, 2) + ' dB') + ')');
    row('Tx da ONU', dbm(o.tx_dbm)); row('Temperatura', o.temp_c === null ? 'sem leitura' : num(o.temp_c, 1) + ' °C');
    row('Distância', o.distancia_m === null ? '—' : '≈ ' + num(o.distancia_m / 1000, 2) + ' km (estimada pelo RTT)');
    row('Sinal recebido na OLT', d.onu.olt_rx_texto); row('Cliente', d.cliente_texto); row('CTO', d.cto_texto);
    row('Última leitura', fmtDT(o.rx_medido_em));
    body.appendChild(g);

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
    body.appendChild(pr);
    var cb = el('div', 'rf-chartbar'); cb.appendChild(el('h3', '', 'Sinal e quedas'));
    var big = el('button', '', 'Ampliar'); big.type = 'button'; big.addEventListener('click', function () { openModal(); }); cb.appendChild(big); body.appendChild(cb);
    var ch = el('div', 'rf-chart'); ch.id = 'rf-p-chart'; body.appendChild(ch); drawChart(ch, d, false);
    var legend = el('p', 'rf-legend rf-chart-legend'); [['━━','Esta ONU','#17324d'],['┄','Referência','#718096'],['┄','Vizinhos','#0f766e'],['▉','Queda','#d64545']].forEach(function (item) { var mark=el('span','',item[0]); mark.style.color=item[2]; legend.appendChild(mark); legend.appendChild(document.createTextNode(' '+item[1]+'  ')); }); body.appendChild(legend);
    body.appendChild(el('p', 'rf-legend', 'O sinal é gravado quando muda mais de 1 dB e uma vez por dia.'));
    pr = el('div'); pr.appendChild(el('h3', '', 'Quedas'));
    var tot = d.quedas_total;
    pr.appendChild(el('p', 'rf-legend', 'Período (horário de Brasília): ' + fmtDT(d.periodo.from) + ' a ' + fmtDT(d.periodo.to) + ' · ' + tot.quedas + ' queda(s)' + (tot.em_aberto ? ' (' + tot.em_aberto + ' em aberto)' : '')));
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
    var W = big ? 840 : 420, H = big ? 400 : 220, ml = 38, mr = 72, mt = 10, mb = 25, S = d.serie;
    var t0 = parse(S.de), t1 = parse(S.ate), pts = S.pontos.map(function (p) { return { t: parse(p.t), s: p.t, v: p.rx }; });
    if (t1 <= t0) { t1 = t0 + 1000; }
    function X(t) { return ml + (Math.max(t0, Math.min(t1, t)) - t0) / (t1 - t0) * (W - ml - mr); }
    function Y(v) { return mt + (YMAX - Math.max(YMIN, Math.min(YMAX, v))) / (YMAX - YMIN) * (H - mt - mb); }
    var s = svg('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': 'Sinal da ONU no período selecionado, com as quedas como faixas verticais' });
    var L = d.limiares;
    [[YMIN, L.critico_dbm, '#f6d8d5'], [L.critico_dbm, L.atencao_dbm, '#fbefc7'], [L.atencao_dbm, YMAX, '#e6f4ea']].forEach(function (z) {
      s.appendChild(svg('rect', { x: ml, y: Y(z[1]), width: W - ml - mr, height: Y(z[0]) - Y(z[1]), fill: z[2] }));
    });
    [YMIN, -27, -25, -20, YMAX].forEach(function (v) {
      s.appendChild(svg('line', { x1: ml, x2: W - mr, y1: Y(v), y2: Y(v), stroke: '#c9d3dc', 'stroke-width': 1 }));
      var tx = svg('text', { x: ml - 5, y: Y(v) + 4, 'text-anchor': 'end', 'font-size': 12, fill: '#617587' }); tx.textContent = String(v); s.appendChild(tx);
    });
    var span = t1 - t0, step = state.period === '1h' ? 600000 : state.period === '24h' ? 14400000 : state.period === '7d' ? 86400000 : span <= 7200000 ? 600000 : span <= 172800000 ? 14400000 : span <= 1209600000 ? 86400000 : Math.ceil(span / 6 / 86400000) * 86400000;
    for (var h = Math.ceil((t0 - 10800000) / step) * step + 10800000; h <= t1; h += step) {
      var tx2 = svg('text', { x: X(h), y: H - 6, 'text-anchor': 'middle', 'font-size': 12, fill: '#617587' });
      tx2.textContent = step < 86400000 ? fmtMs(h).slice(-5) : fmtDay(h); s.appendChild(tx2);
    }
    var outs = d.quedas_serie.map(function (q) { return { a: parse(q.started_at), b: q.ended_at ? parse(q.ended_at) : t1, q: q }; });
    outs.forEach(function (o) {
      var x = X(o.a), w = Math.max(2.5, X(o.b) - x), r = svg('rect', { x: x, y: mt, width: w, height: H - mt - mb, fill: '#d64545', opacity: 0.35, class: 'rf-outage' });
      var tt = svg('title'); tt.textContent = 'Queda ' + fmtDT(o.q.started_at) + ' · ' + o.q.reason; r.appendChild(tt); s.appendChild(r);
    });
    if (!pts.length) {
      var nt = svg('text', { x: W / 2, y: H / 2, 'text-anchor': 'middle', 'font-size': 13, fill: '#617587' }); nt.textContent = 'Sem leituras no período'; s.appendChild(nt);
    } else {
      var path = 'M' + X(pts[0].t) + ' ' + Y(pts[0].v);
      for (var i = 1; i < pts.length; i++) { path += ' H' + X(pts[i].t) + ' V' + Y(pts[i].v); }
      path += ' H' + X(t1);
      s.appendChild(svg('path', { d: path, fill: 'none', stroke: '#17324d', 'stroke-width': 3, class: 'rf-line' }));
      pts.forEach(function (p) { s.appendChild(svg('circle', { cx: X(p.t), cy: Y(p.v), r: 2.6, fill: '#17324d' })); });
    }
    var referenceLines = [{v:d.onu.baseline_dbm, name:'Ref.', color:'#718096'}, {v:d.vizinhos.mediana_dbm, name:'Vizinhos', color:'#0f766e'}].filter(function (x) { return x.v !== null && x.v !== undefined; });
    referenceLines.forEach(function (x, i) {
      s.appendChild(svg('line', {x1:ml,x2:W-mr,y1:Y(x.v),y2:Y(x.v),stroke:x.color,'stroke-width':1.5,'stroke-dasharray':'4 3'}));
      var y=Y(x.v); if (i && Math.abs(Y(referenceLines[0].v)-y)<14) y=Math.min(H-mb-2,y+15);
      var label=svg('text',{x:W-mr+4,y:y+4,'font-size':12,fill:x.color}); label.textContent=x.name+' '+num(x.v,1); s.appendChild(label);
    });
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
      var label = fmtMs(t);
      tip.textContent = label + ' · ' + (val ? num(val.v, 2) + ' dBm' : 'sem leitura') + (inQ ? ' · em queda (' + inQ.q.reason + ')' : '');
      tip.hidden = false; tip.style.left = (vx / W * 100) + '%'; tip.style.top = (val ? Y(val.v) / H * 100 : 20) + '%';
      cur.setAttribute('x1', vx); cur.setAttribute('x2', vx); cur.setAttribute('visibility', 'visible');
      if (val) { dot.setAttribute('cx', vx); dot.setAttribute('cy', Y(val.v)); dot.setAttribute('visibility', 'visible'); } else { dot.setAttribute('visibility', 'hidden'); }
    }
    function hide() { tip.hidden = true; cur.setAttribute('visibility', 'hidden'); dot.setAttribute('visibility', 'hidden'); }
    ov.addEventListener('pointermove', at); ov.addEventListener('pointerdown', at); ov.addEventListener('pointerleave', hide);
    box.appendChild(s); box.appendChild(tip);
  }
  function openModal() { $('rf-modal').hidden = false; drawChart($('rf-m-chart'), state.sheet, true); }

  /* ---------- eventos recentes (quedas correlacionadas por PON) ---------- */
  var EV_MAX = 20, evLimit = 5;
  function renderEventPeriods() {
    var box = $('rf-ev-periods'); clear(box);
    [['1h', '1 h'], ['24h', '24 h'], ['7d', '1 semana']].forEach(function (p) {
      var b = el('button', state.evPeriod === p[0] ? 'is-on' : '', p[1]); b.type = 'button';
      b.addEventListener('click', function () { state.evPeriod = p[0]; renderEventPeriods(); loadEvents(); });
      box.appendChild(b);
    });
  }
  function renderEvents(d) {
    var list = $('rf-ev-list'); clear(list);
    var g = d.grupos;
    $('rf-ev-info').textContent = g.length === 0
      ? 'Nenhuma queda no período (' + fmtDT(d.periodo.from) + ' a ' + fmtDT(d.periodo.to) + ', horário de Brasília). Sem registro = estável. Manutenção e boot ficam de fora.'
      : g.length + ' evento(s) de queda no período (horário de Brasília); manutenção e boot ficam de fora.' + (d.fontes.quedas_log ? '' : ' Log da OLT ainda não importado: horas aproximadas.');
    g.slice(0, evLimit).forEach(function (x) {
      var li = el('li', 'rf-ev' + (x.correlacionada ? ' rf-ev--multi' : ''));
      li.appendChild(el('span', 'rf-ev__when', fmtDT(x.inicio)));
      li.appendChild(el('span', 'rf-ev__text', x.texto.charAt(0).toUpperCase() + x.texto.slice(1) + (x.dispositivo_nome ? ' · ' + x.dispositivo_nome : '')));
      var meta = (x.em_aberto ? x.em_aberto + ' em aberto' : 'retorno ' + fmtDT(x.fim)) + ' · precisão ' + x.precisao + (x.todas_dying_gasp ? ' · todas com Dying Gasp' : '');
      li.appendChild(el('span', 'rf-ev__meta', meta));
      var onus = el('span', 'rf-ev__onus');
      x.onus.forEach(function (o) {
        var b = el('button', '', 'ONU ' + o.onu_index); b.type = 'button'; b.title = o.mac;
        b.addEventListener('click', function () { openSheet(o.onu_id); });
        onus.appendChild(b);
      });
      li.appendChild(onus); list.appendChild(li);
    });
    if (g.length > evLimit && evLimit < EV_MAX) { var more = el('button', '', 'Mostrar mais'); more.type = 'button'; more.addEventListener('click', function () { evLimit = EV_MAX; renderEvents(d); }); var item = el('li'); item.appendChild(more); list.appendChild(item); }
    if (g.length > EV_MAX && evLimit >= EV_MAX) { list.appendChild(el('li', 'rf-legend', 'Mostrando os 20 mais recentes de ' + g.length + '.')); }
  }
  function loadEvents() {
    $('rf-ev-info').textContent = 'Carregando…';
    return get(API.events + '?periodo=' + encodeURIComponent(state.evPeriod)).then(renderEvents)
      .catch(function (e) { clear($('rf-ev-list')); $('rf-ev-info').textContent = e.message; });
  }

  /* ---------- carga e eventos ---------- */
  function loadTree() {
    $('rf-summary').textContent = 'Carregando…';
    var params = new URLSearchParams(window.location.search), device = params.get('dispositivo');
    var url = API.tree + (device && /^[1-9][0-9]{0,14}$/.test(device) ? '?dispositivo=' + encodeURIComponent(device) : '');
    return get(url).then(function (d) { state.tree = d; fillSelectors(); renderTree(); if (state.selected) { loadSheet(); } })
      .catch(function (e) { $('rf-summary').textContent = e.message; });
  }
  ['rf-f-status', 'rf-f-down24', 'rf-f-novinc'].forEach(function (id) { $(id).addEventListener('change', function () { if (state.tree) { renderTree(); } }); });
  $('rf-f-olt').addEventListener('change', function () { if (state.tree) { fillPons(); renderTree(); } });
  $('rf-f-site').addEventListener('change', function () { if (state.tree) { fillPons(); renderTree(); } });
  $('rf-f-pon').addEventListener('change', function () { if (state.tree) { renderTree(); } });
  $('rf-refresh').addEventListener('click', function () { loadTree(); loadEvents(); });
  $('rf-p-close').addEventListener('click', function () { state.selected = null; state.group=null; $('rf-panel').hidden = true; document.querySelector('.rf-layout').classList.remove('has-panel'); if (state.tree) { renderTree(); } });
  $('rf-m-close').addEventListener('click', function () { $('rf-modal').hidden = true; });
  $('rf-modal').addEventListener('click', function (e) { if (e.target === $('rf-modal')) { $('rf-modal').hidden = true; } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { $('rf-modal').hidden = true; } });
  renderColsMenu();
  renderEventPeriods();
  loadTree().then(function () { var id = new URLSearchParams(window.location.search).get('onu'); if (id !== null) { if (/^[1-9][0-9]{0,14}$/.test(id)) { openSheet(id); } else { $('rf-note').hidden = false; $('rf-note').textContent = 'ONU inválida.'; } } });
  loadEvents();
})();
