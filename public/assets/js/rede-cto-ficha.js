/* /rede-ctos/ficha: portas da CTO e clientes vinculados. Busca de cliente = somente leitura no MkAuth.
   Todo texto dinamico entra por textContent (nunca innerHTML). */
(function () {
  'use strict';
  var app = document.getElementById('cf-app');
  if (!app) { return; }
  var D = app.dataset, CSRF = D.csrf || '', CAN_EDIT = D.canEdit === '1';
  var $ = function (id) { return document.getElementById(id); };
  var state = { data: null, port: null, mode: 'bind', mover: null, confirm: null, gen: 0, timer: null };

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined && text !== null) { n.textContent = String(text); } return n; }
  function say(text, tone) { var m = $('cf-msg'); m.textContent = text || ''; m.hidden = !text; if (tone) { m.setAttribute('data-tone', tone); } else { m.removeAttribute('data-tone'); } }
  function post(url, fields) {
    var body = new URLSearchParams();
    Object.keys(fields).forEach(function (k) { if (fields[k] !== null && fields[k] !== undefined) { body.append(k, fields[k]); } });
    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF }, body: body.toString() })
      .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Resposta inesperada do servidor.' }; }).then(function (j) { j.httpStatus = r.status; return j; }); });
  }
  function ask(title, text, okLabel, cb) {
    $('cf-confirm-title').textContent = title; $('cf-confirm-text').textContent = text; $('cf-confirm-ok').textContent = okLabel;
    state.confirm = cb; $('cf-confirm-box').hidden = false; $('cf-confirm-ok').focus();
  }
  function closeAsk() { $('cf-confirm-box').hidden = true; state.confirm = null; }
  $('cf-confirm-ok').addEventListener('click', function () { var cb = state.confirm; closeAsk(); if (cb) { cb(); } });
  $('cf-confirm-no').addEventListener('click', closeAsk);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !$('cf-confirm-box').hidden) { closeAsk(); } });

  function load() {
    return fetch(D.api + '?id=' + encodeURIComponent(D.cto), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
      .then(function (res) {
        if (!res.body || res.body.ok !== true) { say((res.body && res.body.message) || 'Não foi possível ler a CTO.', 'error'); $('cf-summary').textContent = ''; return; }
        state.data = res.body.data; render();
      }).catch(function () { say('Não foi possível ler a CTO.', 'error'); });
  }

  function clientLink(c) {
    var a = el('a', null, c.nome || c.login); a.href = D.clientUrl + '?login=' + encodeURIComponent(c.login); return a;
  }

  function render() {
    var d = state.data, c = d.cto, tb = document.querySelector('#cf-table tbody');
    $('cf-title').textContent = 'CTO ' + c.nome;
    $('cf-sub').textContent = [c.origem ? 'Origem: ' + c.origem : 'Origem a identificar', c.lat === null ? 'sem coordenadas' : c.lat.toFixed(6) + ', ' + c.lng.toFixed(6), 'capacidade ' + c.capacidade].join(' · ');
    if (D.mapUrl) { $('cf-map').href = D.mapUrl + '?no=' + c.id; } else { $('cf-map').hidden = true; }
    var cnt = d.contagem || { clientes: 0, onus: 0 };
    // ONUs ocupam portas; clientes sao assinantes (uma ONU pode ter varios)
    var clients = d.portas.filter(function (p) { return p.cliente; }).length;
    $('cf-summary').textContent = c.portas.total + ' porta(s) · ' + c.portas.em_ligacao + ' em ligação · ' + clients + ' ocupada(s) · ' + cnt.clientes + ' cliente(s) em ' + cnt.onus + ' ONU(s) · ' + c.portas.livres + ' livre(s)' + (c.portas.indisponiveis ? ' · ' + c.portas.indisponiveis + ' indisponível(is)' : '') + (d.vinculos_disponiveis ? '' : ' · vínculos ainda não disponíveis neste ambiente');
    tb.replaceChildren(); $('cf-empty').hidden = d.portas.length > 0;
    var freePorts = d.portas.filter(function (p) { return !p.cliente && !p.ligacao && p.status !== 'defeito'; });
    d.portas.forEach(function (p) {
      var tr = el('tr'); tr.setAttribute('data-port', p.id);
      var n = 0, labels = ['Porta', 'Situação', 'Cliente', 'ONU', 'Ações'];
      function add(td) { td.setAttribute('data-label', labels[n++]); tr.appendChild(td); return td; }
      add(el('td', null, p.rotulo));
      var st = add(el('td'));
      var kind = p.cliente ? ['Cliente', 'online'] : p.ligacao ? ['Em ligação', 'ok'] : p.status === 'defeito' ? ['Defeito', 'critico'] : p.status === 'reservada' ? ['Reservada', 'atencao'] : ['Livre', 'unknown'];
      st.appendChild(el('span', 'rf-pill rf-pill--' + kind[1], kind[0])); if (p.ligacao) { st.appendChild(el('div', 'rf-muted', p.ligacao)); }
      var cl = add(el('td'));
      if (p.cliente) {
        (p.clientes && p.clientes.length ? p.clientes : [p.cliente]).forEach(function (c) {
          var line = el('div'); line.appendChild(clientLink(c)); line.appendChild(el('span', 'rf-muted', ' · ' + c.login + (c.modo_pppoe ? ' · ' + (c.modo_pppoe === 'pppoe_na_onu' ? 'PPPoE na ONU' : 'atrás da ONU') : '')));
          cl.appendChild(line);
        });
      } else { cl.className = 'rf-muted'; cl.textContent = p.ligacao ? '—' : 'sem cliente'; }
      var on = add(el('td'));
      if (p.cliente && p.cliente.onu) { var a = el('a', null, 'PON ' + p.cliente.onu.pon + ' · ONU ' + p.cliente.onu.onu_index); a.href = D.onuUrl + '?onu=' + p.cliente.onu.id; on.appendChild(a); }
      else { on.className = 'rf-muted'; on.textContent = p.cliente ? 'a identificar' : '—'; }
      var act = add(el('td')), box = el('div', 'rc-rowactions');
      if (CAN_EDIT && d.vinculos_disponiveis) {
        if (p.cliente) {
          var mv = el('button', 'rc-btn', 'Trocar de porta'); mv.type = 'button'; mv.addEventListener('click', function () { openMove(p, freePorts); }); box.appendChild(mv);
          var rm = el('button', 'rc-btn rc-btn--danger', 'Desvincular'); rm.type = 'button'; rm.addEventListener('click', function () { askRemove(p); }); box.appendChild(rm);
        } else if (!p.ligacao && p.status !== 'defeito') {
          var bd = el('button', 'rc-btn', 'Vincular cliente'); bd.type = 'button'; bd.addEventListener('click', function () { openPicker(p); }); box.appendChild(bd);
        }
      }
      act.appendChild(box); tb.appendChild(tr);
    });
    if (d.sem_porta.length) {
      var tr2 = el('tr'); var td = el('td', 'rf-muted', 'Clientes vinculados a esta CTO sem porta definida: ' + d.sem_porta.map(function (x) { return x.nome || x.login; }).join(', ')); td.colSpan = 5; tr2.appendChild(td); tb.appendChild(tr2);
    }
  }

  // ----- vincular -----
  function openPicker(p) {
    state.port = p; state.mode = 'bind'; $('cf-picker-title').textContent = 'Vincular cliente à ' + p.rotulo;
    $('cf-q').value = ''; $('cf-results').replaceChildren(); $('cf-picker').hidden = false; $('cf-q').focus(); say('');
  }
  $('cf-picker-close').addEventListener('click', function () { $('cf-picker').hidden = true; });
  function search() {
    var q = $('cf-q').value.trim(), box = $('cf-results'), token = ++state.gen; box.replaceChildren();
    if (q.length < 3) { return; }
    fetch(D.apiSearch + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); }).then(function (j) {
        if (token !== state.gen) { return; }
        if (!j.ok) { box.appendChild(el('li', 'rf-muted', j.message || 'Não foi possível buscar.')); return; }
        if (!j.data.length) { box.appendChild(el('li', 'rf-muted', 'Nenhum cliente encontrado.')); return; }
        j.data.forEach(function (c) {
          var li = el('li'), b = el('button', 'rc-result', ''); b.type = 'button';
          b.appendChild(el('strong', null, c.nome)); b.appendChild(el('span', 'rf-muted', ' · ' + c.login + (c.bairro ? ' · ' + c.bairro : '') + (c.cidade ? ' · ' + c.cidade : '')));
          if (c.vinculo && c.vinculo.cto) { b.appendChild(el('span', 'rc-chip', 'já em ' + c.vinculo.cto.nome + ', ' + c.vinculo.cto.porta)); }
          b.addEventListener('click', function () { choose(c); }); li.appendChild(b); box.appendChild(li);
        });
      }).catch(function () { if (token === state.gen) { box.appendChild(el('li', 'rf-muted', 'Não foi possível buscar.')); } });
  }
  $('cf-q').addEventListener('input', function () { clearTimeout(state.timer); state.timer = setTimeout(search, 250); });
  function choose(c) { submitBind(c.login, state.port.id, false); }
  function submitBind(login, portId, mover) {
    post(D.apiBind, { cto_id: D.cto, port_id: portId, login: login, mover: mover ? '1' : '0' }).then(function (j) {
      if (j.httpStatus === 409 && j.codigo === 'mover') { ask('Mover cliente?', j.message, 'Sim, mover', function () { submitBind(login, portId, true); }); return; }
      say(j.message || (j.ok ? 'Feito.' : 'Não foi possível vincular.'), j.ok ? null : 'error');
      if (j.ok) { $('cf-picker').hidden = true; return load(); }
    });
  }
  // ----- trocar de porta -----
  function openMove(p, free) {
    if (!free.length) { say('Não há porta livre nesta CTO para onde mover.', 'warn'); return; }
    var nn = (p.clientes || []).length;
    state.port = p; $('cf-picker-title').textContent = 'Mover ' + (nn > 1 ? 'a ONU com ' + nn + ' clientes' : (p.cliente.nome || p.cliente.login)) + ' de ' + p.rotulo + ' para:';
    var box = $('cf-results'); box.replaceChildren(); $('cf-q').value = ''; $('cf-picker').hidden = false;
    free.forEach(function (f) {
      var li = el('li'), b = el('button', 'rc-result', f.rotulo); b.type = 'button';
      b.addEventListener('click', function () { submitBind(p.cliente.login, f.id, true); });
      li.appendChild(b); box.appendChild(li);
    });
  }
  function askRemove(p) {
    var nn = (p.clientes || []).length;
    ask('Desvincular da porta?', (nn > 1 ? 'A porta é da ONU: a ' + p.rotulo + ' será retirada dos ' + nn + ' clientes dessa ONU. ' : 'Desvincular ' + (p.cliente.nome || p.cliente.login) + ' de ' + p.rotulo + '? ') + (p.cliente.onu ? 'O vínculo com a ONU é mantido.' : ''), 'Sim, desvincular', function () {
      post(D.apiRemove, { id: p.cliente.id, parte: 'cto' }).then(function (j) { say(j.message || (j.ok ? 'Feito.' : 'Não foi possível desvincular.'), j.ok ? null : 'error'); if (j.ok) { return load(); } });
    });
  }

  load();
})();
