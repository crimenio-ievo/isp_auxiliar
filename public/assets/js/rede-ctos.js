/* /rede-ctos: lista e cadastro rapido de CTOs. Todo texto dinamico entra por textContent (nunca innerHTML).
   Nenhum dado simulado: o que nao existe aparece como "ainda nao lido" / "a identificar". */
(function () {
  'use strict';
  var app = document.getElementById('ct-app');
  if (!app) { return; }
  var API = { list: app.dataset.api, del: app.dataset.apiDelete, imp: app.dataset.apiImport };
  var CSRF = app.dataset.csrf || '';
  var CAN_EDIT = app.dataset.canEdit === '1';
  var $ = function (id) { return document.getElementById(id); };
  var state = { data: null, filter: '', focus: app.dataset.focus || '', csv: '', analysis: null, confirm: null };
  var LABELS = ['Nome', 'Origem', 'Coordenadas', 'Capacidade', 'Portas', 'Clientes', 'Ações'];

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined && text !== null) { n.textContent = String(text); }
    return n;
  }
  function say(text, tone) {
    var m = $('ct-msg'); m.textContent = text || ''; m.hidden = !text;
    if (tone) { m.setAttribute('data-tone', tone); } else { m.removeAttribute('data-tone'); }
  }
  function post(url, fields) {
    var body = new URLSearchParams();
    Object.keys(fields).forEach(function (k) { if (fields[k] !== null && fields[k] !== undefined) { body.append(k, fields[k]); } });
    return fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF },
      body: body.toString()
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, message: 'Resposta inesperada do servidor.' }; }).then(function (j) { j.httpStatus = r.status; return j; });
    });
  }

  // Mesma regra do servidor (Coordinates::parsePair): "lat, lng" ou "lat lng", ponto ou virgula decimal.
  function parsePair(text) {
    text = String(text || '').trim().replace(/ /g, ' ').replace(/;/g, ' ');
    if (!text || text.length > 80) { return null; }
    var num = '([+-]?\\d{1,3}(?:[.,]\\d+)?)', m = new RegExp('^' + num + '\\s*,\\s*' + num + '$').exec(text), a, b;
    if (m && m[1].indexOf(',') === -1 && m[2].indexOf(',') === -1) { a = m[1]; b = m[2]; }
    else { m = new RegExp('^' + num + '\\s+' + num + '$').exec(text); if (!m) { return null; } a = m[1]; b = m[2]; }
    var lat = parseFloat(a.replace(',', '.')), lng = parseFloat(b.replace(',', '.'));
    if (Math.abs(lat) > 90 || Math.abs(lng) > 180 || (lat === 0 && lng === 0)) { return null; }
    return [lat, lng];
  }

  function load() {
    return fetch(API.list, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || j.ok !== true) { say((j && j.message) || 'Não foi possível ler as CTOs.', 'error'); $('ct-summary').textContent = ''; return; }
        state.data = j.data; render();
      }).catch(function () { say('Não foi possível ler as CTOs.', 'error'); });
  }

  function render() {
    var d = state.data, tb = document.querySelector('#ct-table tbody'), q = state.filter.toLowerCase();
    tb.replaceChildren();
    var list = d.ctos.filter(function (c) { return !q || (c.nome + ' ' + (c.origem || '')).toLowerCase().indexOf(q) !== -1; });
    var totals = d.ctos.reduce(function (t, c) { t.ports += c.portas.total; t.used += c.portas.ocupadas; return t; }, { ports: 0, used: 0 });
    $('ct-summary').textContent = d.ctos.length + ' CTO(s) · ' + totals.used + ' de ' + totals.ports + ' portas ocupadas' + (list.length !== d.ctos.length ? ' · ' + list.length + ' na busca' : '');
    $('ct-empty').hidden = list.length > 0;
    $('ct-empty').textContent = d.ctos.length ? 'Nenhuma CTO com esse filtro.' : 'Nenhuma CTO cadastrada.' + (CAN_EDIT ? ' Use “Nova CTO” ou “Importar CSV”.' : '');
    list.forEach(function (c) {
      var tr = el('tr'); tr.setAttribute('data-cto', c.id);
      if (String(c.id) === state.focus) { tr.className = 'rc-focus'; }
      var n = 0;
      function add(td) { td.setAttribute('data-label', LABELS[n++]); tr.appendChild(td); return td; }
      add(el('td', null, c.nome));
      add(el('td', c.origem ? null : 'rf-muted', c.origem || 'a identificar'));
      add(el('td', c.lat === null ? 'rf-muted' : null, c.lat === null ? 'sem coordenadas' : c.lat.toFixed(6) + ', ' + c.lng.toFixed(6)));
      add(el('td', null, c.capacidade));
      var pt = add(el('td'));
      pt.appendChild(el('span', 'rc-ports', c.portas.ocupadas + ' ocupadas · ' + c.portas.livres + ' livres'));
      if (c.portas.total > 0) {
        var bar = el('span', 'rf-stacked rc-bar'); var fill = el('span', 'rf-fill--ok'); fill.style.width = Math.round(100 * c.portas.ocupadas / c.portas.total) + '%'; bar.appendChild(fill); pt.appendChild(bar);
      }
      add(el('td', c.clientes === null ? 'rf-muted' : null, c.clientes === null ? 'ainda não lido' : c.clientes));
      var act = add(el('td')), box = el('div', 'rc-rowactions');
      var fi = el('a', 'rc-btn', 'Ficha'); fi.href = app.dataset.fichaUrl + '?id=' + c.id; box.appendChild(fi);
      if (app.dataset.mapUrl) { var map = el('a', 'rc-btn', 'Ver no mapa'); map.href = app.dataset.mapUrl + '?no=' + c.id; box.appendChild(map); }
      if (CAN_EDIT) {
        var ed = el('button', 'rc-btn', 'Editar'); ed.type = 'button'; ed.addEventListener('click', function () { openForm(c); }); box.appendChild(ed);
        var rm = el('button', 'rc-btn rc-btn--danger', 'Excluir'); rm.type = 'button'; rm.addEventListener('click', function () { askDelete(c); }); box.appendChild(rm);
      }
      act.appendChild(box);
      tb.appendChild(tr);
    });
    var f = tb.querySelector('.rc-focus'); if (f && f.scrollIntoView) { f.scrollIntoView({ block: 'center' }); }
  }
  $('ct-filter').addEventListener('input', function (e) { state.filter = e.target.value.trim(); render(); });

  // ----- confirmacao generica -----
  function ask(title, text, okLabel, cb) {
    $('ct-confirm-title').textContent = title; $('ct-confirm-text').textContent = text; $('ct-confirm-ok').textContent = okLabel;
    state.confirm = cb; $('ct-confirm-box').hidden = false; $('ct-confirm-ok').focus();
  }
  function closeAsk() { $('ct-confirm-box').hidden = true; state.confirm = null; }
  $('ct-confirm-ok').addEventListener('click', function () { var cb = state.confirm; closeAsk(); if (cb) { cb(); } });
  $('ct-confirm-no').addEventListener('click', closeAsk);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !$('ct-confirm-box').hidden) { closeAsk(); } });

  function askDelete(c) {
    ask('Excluir CTO?', 'Excluir "' + c.nome + '"? Só é possível se não houver ligação, elemento dentro da caixa nem cliente vinculado.', 'Sim, excluir', function () {
      post(API.del, { id: c.id }).then(function (j) { say(j.message || (j.ok ? 'Excluída.' : 'Não foi possível excluir.'), j.ok ? null : 'error'); if (j.ok) { return load(); } });
    });
  }

  if (!CAN_EDIT) { load(); return; }

  // ----- formulario -----
  var form = $('ct-form');
  function field(n) { return form.elements[n]; }
  function showCoordHint() {
    var h = $('ct-coord-hint'), v = field('coordenadas').value.trim();
    if (!v) { h.textContent = 'Faixa válida: latitude -90 a 90, longitude -180 a 180.'; h.removeAttribute('data-tone'); return; }
    var p = parsePair(v);
    if (p) { h.textContent = 'Latitude ' + p[0] + ' · longitude ' + p[1]; h.setAttribute('data-tone', 'ok'); }
    else { h.textContent = 'Coordenadas inválidas: use “lat, lng” (ex.: -20.845553, -42.802360).'; h.setAttribute('data-tone', 'error'); }
  }
  field('coordenadas').addEventListener('input', showCoordHint);
  field('coordenadas').addEventListener('blur', function () { var p = parsePair(field('coordenadas').value); if (p) { field('coordenadas').value = p[0] + ', ' + p[1]; showCoordHint(); } });

  $('ct-geo').addEventListener('click', function () {
    var h = $('ct-coord-hint');
    if (!navigator.geolocation) { h.textContent = 'Este navegador não oferece localização.'; h.setAttribute('data-tone', 'error'); return; }
    h.textContent = 'Obtendo a localização…'; h.removeAttribute('data-tone');
    navigator.geolocation.getCurrentPosition(function (pos) {
      var la = pos.coords.latitude, ln = pos.coords.longitude;
      field('coordenadas').value = la.toFixed(7) + ', ' + ln.toFixed(7);
      showCoordHint();
      h.textContent += ' · precisão do aparelho: ±' + Math.round(pos.coords.accuracy) + ' m' + (pos.coords.accuracy > 30 ? ' (baixa: aproxime-se do céu aberto e tente de novo)' : '');
    }, function (err) {
      h.textContent = err && err.code === 1 ? 'Permissão de localização negada. Libere no navegador ou cole as coordenadas.' : 'Não foi possível obter a localização. Cole as coordenadas.';
      h.setAttribute('data-tone', 'error');
    }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
  });

  function openForm(c) {
    var d = state.data; say('');
    $('ct-form-title').textContent = c ? 'Editar CTO — ' + c.nome : 'Nova CTO';
    form.reset(); $('ct-id').value = c ? c.id : '';
    var sel = $('ct-origem'); sel.replaceChildren(); sel.appendChild(new Option('Sem origem por enquanto', ''));
    d.origens.sites.forEach(function (s) { sel.appendChild(new Option('Site: ' + s.nome + ' (' + (s.tipo === 'pop' ? 'POP' : 'torre') + ')', 'site:' + s.id)); });
    d.origens.pons.forEach(function (p) { if (p.livre) { sel.appendChild(new Option('PON livre: ' + p.rotulo, 'pon:' + p.id)); } });
    $('ct-origin-field').hidden = !!(c && c.origem);
    if (c) {
      field('nome').value = c.nome; field('capacidade').value = c.capacidade; field('observacao').value = c.observacao || '';
      field('coordenadas').value = c.lat === null ? '' : c.lat + ', ' + c.lng;
    }
    showCoordHint();
    $('ct-form-card').hidden = false; field('nome').focus();
  }
  $('ct-new').addEventListener('click', function () { openForm(null); });
  $('ct-cancel').addEventListener('click', function () { $('ct-form-card').hidden = true; });

  function collect() {
    var f = {};
    ['id', 'nome', 'coordenadas', 'capacidade', 'observacao'].forEach(function (k) { f[k] = field(k).value.trim(); });
    if (!$('ct-origin-field').hidden) { f.origem = field('origem').value; }
    return f;
  }
  function submit(extra) {
    var f = Object.assign(collect(), extra || {});
    if (!f.id) { delete f.id; }
    $('ct-save').disabled = true;
    post(API.list, f).then(function (j) {
      $('ct-save').disabled = false;
      if (j.httpStatus === 409 && j.codigo === 'proximo') {
        ask('CTO muito perto de outra', j.message, 'Gravar mesmo assim', function () { submit({ confirmar_proximo: '1' }); });
        return;
      }
      if (!j.ok) { say(j.message || 'Não foi possível salvar.', 'error'); return; }
      $('ct-form-card').hidden = true; say(j.message, j.data && j.data.aviso ? 'warn' : null); return load();
    }).catch(function () { $('ct-save').disabled = false; say('Não foi possível salvar.', 'error'); });
  }
  form.addEventListener('submit', function (ev) { ev.preventDefault(); submit(); });

  // ----- importacao CSV -----
  $('ct-import-open').addEventListener('click', function () { $('ct-import-card').hidden = false; $('ct-preview').hidden = true; $('ct-report').hidden = true; });
  $('ct-import-cancel').addEventListener('click', function () { $('ct-import-card').hidden = true; });
  $('ct-file').addEventListener('change', function () {
    var file = $('ct-file').files && $('ct-file').files[0];
    $('ct-analyze').disabled = !file; state.csv = '';
    if (!file) { return; }
    var done = function (text) { state.csv = text; };
    if (file.text) { file.text().then(done); } else { var r = new FileReader(); r.onload = function () { done(String(r.result)); }; r.readAsText(file); }
  });
  $('ct-analyze').addEventListener('click', function () {
    if (!state.csv) { say('Escolha um arquivo CSV.', 'warn'); return; }
    post(API.imp, { csv: state.csv }).then(function (j) {
      if (!j.ok) { say(j.message || 'Não foi possível analisar.', 'error'); return; }
      say(''); state.analysis = j.data; renderPreview();
    });
  });

  var STATUS = { ok: ['Pronta', 'online'], erro: ['Erro', 'critico'], duplicado: ['Nome duplicado', 'critico'], proximo: ['Muito perto (< 15 m)', 'atencao'] };
  function renderPreview() {
    var a = state.analysis, tb = document.querySelector('#ct-preview-table tbody'); tb.replaceChildren();
    a.linhas.forEach(function (r) {
      var tr = el('tr'); tr.setAttribute('data-line', r.linha);
      tr.appendChild(el('td', null, r.linha)); tr.appendChild(el('td', null, r.nome || '—'));
      tr.appendChild(el('td', null, r.lat === null ? '—' : r.lat)); tr.appendChild(el('td', null, r.lng === null ? '—' : r.lng)); tr.appendChild(el('td', null, r.capacidade === null ? '—' : r.capacidade));
      var st = el('td'); st.appendChild(el('span', 'rf-pill rf-pill--' + STATUS[r.status][1], STATUS[r.status][0])); tr.appendChild(st);
      tr.appendChild(el('td', 'rc-detail', r.mensagens.join(' ')));
      var cb = el('td');
      if (r.status === 'proximo') { var i = document.createElement('input'); i.type = 'checkbox'; i.setAttribute('data-force', r.linha); i.setAttribute('aria-label', 'Importar mesmo assim a linha ' + r.linha); i.addEventListener('change', updateConfirm); cb.appendChild(i); }
      else { cb.textContent = r.status === 'ok' ? 'sim' : 'não'; cb.className = r.status === 'ok' ? '' : 'rf-muted'; }
      tr.appendChild(cb); tb.appendChild(tr);
    });
    var s = a.resumo;
    $('ct-preview-summary').textContent = s.total + ' linha(s): ' + s.ok + ' pronta(s), ' + s.proximo + ' muito perto de outra CTO, ' + s.duplicado + ' com nome duplicado, ' + s.erro + ' com erro. Nada foi gravado ainda.';
    $('ct-preview').hidden = false; $('ct-report').hidden = true; updateConfirm();
  }
  function forced() { return Array.prototype.map.call(document.querySelectorAll('#ct-preview-table [data-force]:checked'), function (i) { return i.getAttribute('data-force'); }); }
  function updateConfirm() { var n = state.analysis.resumo.ok + forced().length; $('ct-confirm').disabled = n === 0; $('ct-confirm').textContent = 'Confirmar importação de ' + n + ' CTO(s)'; }
  $('ct-confirm').addEventListener('click', function () {
    var f = forced(), n = state.analysis.resumo.ok + f.length;
    ask('Importar ' + n + ' CTO(s)?', 'As CTOs serão gravadas no mapa numa única transação (tudo ou nada), com as portas criadas pela capacidade.', 'Sim, importar', function () {
      post(API.imp, { csv: state.csv, confirmar: '1', forcar: f.join(',') }).then(function (j) {
        if (!j.ok) { say(j.message || 'Não foi possível importar.', 'error'); return; }
        say(j.message); renderReport(j.data.relatorio); $('ct-preview').hidden = true; return load();
      });
    });
  });
  function renderReport(r) {
    var box = $('ct-report'); box.replaceChildren(); box.hidden = false;
    box.appendChild(el('h3', null, 'Relatório da importação'));
    box.appendChild(el('p', null, r.criadas.length + ' criada(s): ' + (r.criadas.map(function (c) { return c.nome; }).join(', ') || 'nenhuma')));
    if (r.ignoradas.length) {
      box.appendChild(el('p', null, r.ignoradas.length + ' ignorada(s):'));
      var ul = el('ul'); r.ignoradas.forEach(function (i) { ul.appendChild(el('li', null, 'Linha ' + i.linha + ' (' + (i.nome || 'sem nome') + '): ' + (i.motivo || i.status))); }); box.appendChild(ul);
    }
  }

  load();
})();
