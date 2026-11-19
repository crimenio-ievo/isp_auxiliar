/* /rede-credenciais, /rede-modelos e /rede-inventario: tabela + formulario montados pela secao. Todo texto dinamico
   entra por textContent (nunca innerHTML). O segredo de uma credencial so e digitado: a API nunca o devolve. */
(function () {
  'use strict';
  var app = document.getElementById('cg-app');
  if (!app) { return; }
  var SECTION = app.dataset.section, CSRF = app.dataset.csrf || '';
  var $ = function (id) { return document.getElementById(id); };
  var state = { data: null, editing: null, replacing: false };

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined && text !== null) { n.textContent = String(text); } return n; }
  function say(text, tone) { var m = $('cg-msg'); m.textContent = text || ''; m.hidden = !text; if (tone) { m.setAttribute('data-tone', tone); } else { m.removeAttribute('data-tone'); } }
  function fmtDT(s) { var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(s || ''); return m ? m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5] : (s || ''); }
  function post(url, fields) {
    var body = new URLSearchParams();
    Object.keys(fields).forEach(function (k) { if (fields[k] !== null && fields[k] !== undefined) { body.append(k, fields[k]); } });
    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF }, body: body.toString() })
      .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Resposta inesperada do servidor.' }; }).then(function (j) { j.httpStatus = r.status; return j; }); });
  }
  var ENUM = { no_mapa: 'No mapa', estoque: 'Estoque', cliente: 'Cliente', outro: 'Outro', instalado: 'Instalado', manutencao: 'Manutenção', baixado: 'Baixado' };
  function enumLabel(v) { return Object.prototype.hasOwnProperty.call(ENUM, v) ? ENUM[v] : v; }
  function opts(list, key, labelFn) { return list.map(function (x) { return { v: key ? x[key] : x, l: labelFn ? labelFn(x) : x }; }); }

  // ---- configuracao por secao -------------------------------------------------------------------------------
  var CFG = {
    credenciais: {
      cols: ['Nome', 'Tipo', 'Usuário', 'Segredo', 'Equipamentos', 'Ações'],
      fields: function (d) { return [
        { n: 'nome', l: 'Nome', max: 80, ro: state.replacing }, { n: 'tipo', l: 'Tipo', sel: opts(d.tipos), ro: state.replacing },
        { n: 'usuario', l: 'Usuário (opcional)', max: 120 }, { n: 'segredo', l: state.replacing ? 'Novo segredo' : 'Segredo', pw: true, max: 500 }]; },
      row: function (r) { return [r.nome, r.tipo, r.usuario || '—', 'definida em ' + fmtDT(r.definida_em) + ' por ' + (r.definida_por || 'desconhecido'), r.equipamentos]; },
      list: function (d) { return d.credenciais; }, title: 'Credencial'
    },
    modelos: {
      cols: ['Fabricante', 'Modelo', 'Tipo', 'Tecnologia', 'Portas', 'Perfil padrão', 'Em uso', 'Ações'],
      fields: function (d) { return [
        { n: 'fabricante', l: 'Fabricante', max: 80 }, { n: 'modelo', l: 'Modelo', max: 120 }, { n: 'tipo', l: 'Tipo', sel: opts(d.tipos) },
        { n: 'tecnologia', l: 'Tecnologia (EPON, GPON…)', max: 40 }, { n: 'portas_pon', l: 'Portas PON', num: true }, { n: 'portas_sfp', l: 'Portas SFP', num: true },
        { n: 'portas_eth', l: 'Portas ETH', num: true }, { n: 'portas_fo', l: 'Portas FO', num: true }, { n: 'perfil_padrao', l: 'Perfil de telemetria padrão', max: 80 },
        { n: 'ficha', l: 'Ficha técnica (JSON)', area: true }, { n: 'observacao', l: 'Observação', max: 500 }]; },
      row: function (r) { var p = Object.keys(r.portas || {}).map(function (k) { return r.portas[k] + ' ' + k.toUpperCase(); }).join(' · ');
        return [r.fabricante, r.modelo, r.tipo, r.tecnologia || '—', p || '—', r.perfil_padrao || '—', r.em_uso]; },
      list: function (d) { return d.modelos; }, title: 'Modelo'
    },
    inventario: {
      cols: ['Modelo', 'Série', 'MAC', 'Patrimônio', 'Situação', 'Local', 'Ações'],
      fields: function (d) { return [
        { n: 'modelo_id', l: 'Modelo', sel: opts(d.modelos, 'id', function (m) { return m.rotulo; }) }, { n: 'serie', l: 'Série (opcional)', max: 80 },
        { n: 'mac', l: 'MAC (opcional)', max: 17 }, { n: 'patrimonio', l: 'Patrimônio (opcional)', max: 60 }, { n: 'situacao', l: 'Situação', sel: opts(d.situacoes, null, enumLabel) },
        { n: 'local_tipo', l: 'Local', sel: opts(d.locais, null, enumLabel) }, { n: 'no_id', l: 'Elemento do mapa', sel: [{ v: '', l: '—' }].concat(opts(d.nos, 'id', function (n) { return n.nome + ' (' + n.tipo + ')'; })) },
        { n: 'local_obs', l: 'Observação do local', max: 160 }, { n: 'observacao', l: 'Observação', max: 500 }]; },
      row: function (r) { return [r.fabricante + ' ' + r.modelo, r.serie || '—', r.mac || '—', r.patrimonio || '—', enumLabel(r.situacao), r.no_nome ? r.no_nome : (enumLabel(r.local_tipo) + (r.local_obs ? ' · ' + r.local_obs : ''))]; },
      list: function (d) { return d.equipamentos; }, title: 'Equipamento'
    }
  }[SECTION];

  function load() {
    return fetch(app.dataset.api, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { j.httpStatus = r.status; return j; }); })
      .then(function (j) { if (!j.ok) { say(j.message || 'Não foi possível carregar.', 'error'); $('cg-summary').textContent = ''; return; } state.data = j.data; render(); })
      .catch(function () { say('Não foi possível carregar.', 'error'); });
  }
  function render() {
    var d = state.data, rows = CFG.list(d);
    $('cg-summary').textContent = rows.length + ' cadastrado(s)';
    var warn = $('cg-warn');
    if (SECTION === 'credenciais' && !d.chave_disponivel) { warn.textContent = 'A chave de cifragem NET_SECRET_KEY não está definida neste servidor: o cadastro e a substituição estão bloqueados. Rode scripts/net_secret_key.php (a chave não é exibida).'; warn.hidden = false; $('cg-new').disabled = true; }
    else if (SECTION === 'credenciais' && !d.tabela_disponivel) { warn.textContent = 'A tabela de credenciais ainda não existe neste ambiente (migration 046).'; warn.hidden = false; $('cg-new').disabled = true; }
    else { warn.hidden = true; $('cg-new').disabled = false; }
    var hr = document.querySelector('#cg-table thead tr'); hr.replaceChildren();
    CFG.cols.forEach(function (c) { hr.appendChild(el('th', null, c)); });
    var tb = document.querySelector('#cg-table tbody'); tb.replaceChildren();
    rows.forEach(function (r) {
      var tr = el('tr'); tr.setAttribute('data-id', r.id);
      CFG.row(r).forEach(function (v, i) { var td = el('td', null, v); td.setAttribute('data-label', CFG.cols[i]); tr.appendChild(td); });
      var act = el('td'); act.setAttribute('data-label', 'Ações');
      var btn = function (t, fn, danger) { var b = el('button', 'rc-btn' + (danger ? ' rc-btn--danger' : ''), t); b.type = 'button'; b.addEventListener('click', fn); act.appendChild(b); };
      if (SECTION === 'credenciais') { btn('Substituir', function () { openForm(r, true); }); btn('Excluir', function () { del(r); }, true); }
      else if (SECTION === 'modelos') { btn('Editar', function () { openForm(r); }); btn('Excluir', function () { del(r); }, true); }
      else { btn('Editar', function () { openForm(r); }); btn('Histórico', function () { history(r); }); }
      tr.appendChild(act); tb.appendChild(tr);
    });
    $('cg-empty').hidden = rows.length > 0;
  }
  function openForm(r, replacing) {
    state.editing = r || null; state.replacing = !!replacing; say('');
    var box = $('cg-fields'); box.replaceChildren();
    CFG.fields(state.data).forEach(function (f) {
      var lab = el('label', 'rf-field'); lab.appendChild(el('span', null, f.l));
      var inp;
      if (f.sel) { inp = el('select'); f.sel.forEach(function (o) { var op = el('option', null, o.l); op.value = o.v; inp.appendChild(op); }); }
      else if (f.area) { inp = el('textarea'); inp.rows = 4; }
      else { inp = el('input'); inp.type = f.pw ? 'password' : (f.num ? 'number' : 'text'); if (f.num) { inp.min = 0; inp.max = 256; } if (f.max) { inp.maxLength = f.max; } if (f.pw) { inp.autocomplete = 'new-password'; } }
      inp.name = f.n; if (f.ro) { inp.disabled = true; }
      var cur = r ? valueOf(r, f.n) : null; if (!f.pw && cur !== null && cur !== undefined) { inp.value = cur; }
      lab.appendChild(inp); box.appendChild(lab);
    });
    $('cg-id').value = r ? r.id : '';
    $('cg-form-title').textContent = (replacing ? 'Substituir segredo: ' + r.nome : (r ? 'Editar ' : 'Cadastrar ') + CFG.title.toLowerCase());
    $('cg-form-card').hidden = false;
  }
  function valueOf(r, n) {
    var m = { portas_pon: (r.portas || {}).pon, portas_sfp: (r.portas || {}).sfp, portas_eth: (r.portas || {}).eth, portas_fo: (r.portas || {}).fo, ficha: r.ficha && Object.keys(r.ficha).length ? JSON.stringify(r.ficha) : '', modelo_id: r.modelo_id, no_id: r.no_id === null ? '' : r.no_id };
    return Object.prototype.hasOwnProperty.call(m, n) ? m[n] : r[n];
  }
  function del(r) { if (!window.confirm('Excluir "' + (r.nome || r.modelo) + '"?')) { return; } post(app.dataset.apiDelete, { id: r.id }).then(function (j) { say(j.message, j.ok ? null : 'error'); if (j.ok) { load(); } }); }
  function history(r) {
    fetch(app.dataset.api + '?historico=' + encodeURIComponent(r.id), { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (x) { return x.json(); }).then(function (j) {
      var ul = $('cg-hist-list'); ul.replaceChildren();
      ((j.data && j.data.historico) || []).forEach(function (h) { ul.appendChild(el('li', null, fmtDT(h.moved_at) + ' · ' + (h.from_status || '—') + ' → ' + h.to_status + (h.note ? ' · ' + h.note : '') + (h.actor ? ' · ' + h.actor : ''))); });
      if (!ul.childElementCount) { ul.appendChild(el('li', null, 'Sem movimentação registrada.')); }
      $('cg-hist').hidden = false;
    });
  }
  $('cg-new').addEventListener('click', function () { openForm(null, false); });
  $('cg-cancel').addEventListener('click', function () { $('cg-form-card').hidden = true; });
  $('cg-form').addEventListener('submit', function (ev) {
    ev.preventDefault();
    var f = {}; Array.prototype.forEach.call($('cg-form').elements, function (x) { if (x.name && !x.disabled) { f[x.name] = x.value; } });
    var url = state.replacing ? app.dataset.apiReplace : app.dataset.api;
    $('cg-save').disabled = true;
    post(url, f).then(function (j) {
      $('cg-save').disabled = false; say(j.message || (j.ok ? 'Salvo.' : 'Não foi possível salvar.'), j.ok ? null : 'error');
      if (j.ok) { $('cg-form-card').hidden = true; Array.prototype.forEach.call($('cg-form').querySelectorAll('input[type=password]'), function (x) { x.value = ''; }); load(); }
    });
  });
  load();
})();
