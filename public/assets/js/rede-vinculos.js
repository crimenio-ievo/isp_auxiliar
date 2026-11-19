/* /rede-vinculos: ONUs sem vinculo e sugestoes pelo MAC. Confirmar ou recusar; nada e vinculado sem confirmacao.
   Todo texto dinamico entra por textContent (nunca innerHTML). */
(function () {
  'use strict';
  var app = document.getElementById('vc-app');
  if (!app) { return; }
  var D = app.dataset, CSRF = D.csrf || '';
  var $ = function (id) { return document.getElementById(id); };
  var CONF = { alta: 'Alta', media: 'Média', baixa: 'Baixa' };

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined && text !== null) { n.textContent = String(text); } return n; }
  function say(text, tone) { var m = $('vc-msg'); m.textContent = text || ''; m.hidden = !text; if (tone) { m.setAttribute('data-tone', tone); } else { m.removeAttribute('data-tone'); } }
  function post(url, fields) {
    var body = new URLSearchParams();
    Object.keys(fields).forEach(function (k) { if (fields[k] !== null && fields[k] !== undefined) { body.append(k, fields[k]); } });
    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF }, body: body.toString() })
      .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Resposta inesperada do servidor.' }; }).then(function (j) { j.httpStatus = r.status; return j; }); });
  }
  function fmtDT(s) { var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(s || ''); return m ? m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5] : null; }
  function macText(mac) { return String(mac).replace(/(..)(?=.)/g, '$1:'); }
  function motivo(d, conf) {
    var t = d === 0 ? 'MAC igual' : 'MAC ' + (d > 0 ? '+' : '−') + Math.abs(d);
    if (conf === 'media') { return t + ' no último octeto (diferença maior que 1: confira antes de confirmar)'; }
    if (conf === 'baixa') { return t + ' · ambígua ou distante: só confirme com certeza'; }
    return t + ' no último octeto';
  }
  function diffText(d) { return d === 0 ? 'MAC igual' : (d > 0 ? '+' : '') + d + ' no último octeto'; }

  function load() {
    $('vc-summary').textContent = 'Consultando sessões PPPoE no MkAuth (somente leitura)…';
    return fetch(D.api, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || j.ok !== true) { $('vc-summary').textContent = ''; say((j && j.message) || 'Não foi possível ler as sugestões.', 'error'); return; }
        render(j.data);
      }).catch(function () { $('vc-summary').textContent = ''; say('Não foi possível ler as sugestões.', 'error'); });
  }

  function render(d) {
    var tb = document.querySelector('#vc-table tbody'); tb.replaceChildren();
    $('vc-summary').textContent = d.sem_vinculo + ' ONU(s) sem vínculo · ' + d.sugestoes.length + ' com sugestão · ' + d.sem_candidato + ' sem candidato pelo MAC · ' + d.logins_com_sessao + ' login(s) ativos com sessão';
    var ref = fmtDT(d.radacct_referencia);
    var note = $('vc-note');
    note.textContent = 'Janela de ' + d.janela_dias + ' dias contada a partir do registro mais recente do radacct' + (ref ? ' (' + ref + ')' : ' (nenhuma sessão lida)') + '. Regra: ' + d.regra + '.';
    note.hidden = false;
    var base = $('vc-base');
    if (d.base_mkauth_ate) { var p = d.base_mkauth_ate.split('-'); base.textContent = 'A base do MkAuth lida por este servidor vai até ' + p[2] + '/' + p[1] + '/' + p[0] + (d.base_mkauth_origem === 'sessao' ? ' (sessão mais recente)' : ' (cliente mais recente)') + '. Clientes e sessões posteriores a essa data não aparecem aqui.'; }
    else { base.textContent = 'Não foi possível determinar até que data vai a base do MkAuth lida por este servidor.'; }
    base.hidden = false;
    $('vc-empty').hidden = d.sugestoes.length > 0;
    d.sugestoes.forEach(function (s) {
      var tr = el('tr'); tr.setAttribute('data-onu', s.onu.id);
      var n = 0, labels = ['ONU', 'OLT · PON', 'MAC', 'Sugestões'];
      function add(td) { td.setAttribute('data-label', labels[n++]); tr.appendChild(td); return td; }
      var a = el('a', null, 'ONU ' + s.onu.onu_index); a.href = D.onuUrl + '?onu=' + s.onu.id; add(el('td')).appendChild(a);
      add(el('td', null, s.onu.olt + ' · PON ' + s.onu.pon));
      add(el('td', null, macText(s.onu.mac)));
      var cell = add(el('td')), box = el('div', 'rc-cands');
      s.candidatos.forEach(function (c) {
        var row = el('div', 'rc-cand'); row.setAttribute('data-login', c.login);
        var who = el('a', null, c.nome || c.login); who.href = D.clientUrl + '?login=' + encodeURIComponent(c.login); row.appendChild(who);
        row.appendChild(el('span', 'rf-muted', c.login));
        row.appendChild(el('span', 'rf-pill rc-conf--' + c.confianca, 'Confiança ' + (CONF[c.confianca] || c.confianca)));
        row.appendChild(el('span', 'rf-muted rc-motivo', motivo(c.distancia, c.confianca)));
        if (c.vinculado) { row.appendChild(el('span', 'rc-chip', 'já tem vínculo (CTO)')); }
        var sel = modeSelect(null); sel.setAttribute('aria-label', 'Modo PPPoE'); row.appendChild(sel);
        var ok = el('button', 'rc-btn', 'Confirmar'); ok.type = 'button'; ok.addEventListener('click', function () { act(s, c, 'confirmar', row, sel.value); });
        var no = el('button', 'rc-btn rc-btn--danger', 'Recusar'); no.type = 'button'; no.addEventListener('click', function () { act(s, c, 'recusar', row); });
        row.appendChild(ok); row.appendChild(no); box.appendChild(row);
      });
      cell.appendChild(box); tb.appendChild(tr);
    });
  }

  function act(s, c, acao, row, modo) {
    Array.prototype.forEach.call(row.querySelectorAll('button'), function (b) { b.disabled = true; });
    post(D.apiOnu, { onu_id: s.onu.id, login: c.login, acao: acao, confianca: c.confianca, modo: modo || '' }).then(function (j) {
      say(j.message || (j.ok ? 'Feito.' : 'Não foi possível concluir.'), j.ok ? null : 'error');
      if (j.ok) { return Promise.all([load(), loadBound()]); }
      Array.prototype.forEach.call(row.querySelectorAll('button'), function (b) { b.disabled = false; });
    });
  }

  // ----- ONUs ja vinculadas: varios clientes por ONU e modo PPPoE por vinculo -----
  var MODOS = [['', 'modo não verificado'], ['pppoe_na_onu', 'PPPoE na ONU'], ['atras_da_onu', 'atrás da ONU']];
  function modeSelect(value) {
    var s = el('select', 'rc-modo');
    MODOS.forEach(function (m) { var o = el('option', null, m[1]); o.value = m[0]; if ((value || '') === m[0]) { o.selected = true; } s.appendChild(o); });
    return s;
  }
  function loadBound() {
    return fetch(D.apiOnus, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok === true) { renderBound(j.data.onus); } else { say((j && j.message) || 'Não foi possível ler as ONUs vinculadas.', 'error'); } })
      .catch(function () { say('Não foi possível ler as ONUs vinculadas.', 'error'); });
  }
  function renderBound(onus) {
    var tb = document.querySelector('#vb-table tbody'); tb.replaceChildren(); $('vb-empty').hidden = onus.length > 0;
    onus.forEach(function (o) {
      var tr = el('tr'); tr.setAttribute('data-onu', o.onu.id);
      var n = 0, labels = ['ONU', 'CTO · porta', 'Clientes', 'Adicionar cliente'];
      function add(td) { td.setAttribute('data-label', labels[n++]); tr.appendChild(td); return td; }
      var a = el('a', null, 'PON ' + o.onu.pon + ' · ONU ' + o.onu.onu_index); a.href = D.onuUrl + '?onu=' + o.onu.id; add(el('td')).appendChild(a);
      var ctoCell = add(el('td', o.cto ? null : 'rf-muted', o.cto ? o.cto.nome + (o.cto.porta ? ' · ' + o.cto.porta : '') : 'sem CTO'));
      if (D.apiCtos && window.RedeCtoPicker) {
        var pickHost = el('div'), pb = el('button', 'rc-btn', o.cto ? 'Trocar CTO' : 'Definir CTO'); pb.type = 'button';
        pb.addEventListener('click', function () {
          window.RedeCtoPicker.open(pickHost, { onuId: o.onu.id, nClientes: o.clientes.length, apiList: D.apiCtos, apiBind: D.apiBind, csrf: CSRF,
            onDone: function (m) { say(m, null); Promise.all([load(), loadBound()]); } });
        });
        ctoCell.appendChild(document.createTextNode(' ')); ctoCell.appendChild(pb); ctoCell.appendChild(pickHost);
      }
      var cells = add(el('td'));
      o.clientes.forEach(function (c) {
        var line = el('div', 'rc-cand'); line.setAttribute('data-login', c.login);
        var who = el('a', null, c.nome || c.login); who.href = D.clientUrl + '?login=' + encodeURIComponent(c.login); line.appendChild(who);
        line.appendChild(el('span', 'rf-muted', c.login));
        var sel = modeSelect(c.modo_pppoe); sel.setAttribute('aria-label', 'Modo PPPoE de ' + (c.nome || c.login));
        sel.addEventListener('change', function () {
          post(D.apiModo, { id: c.id, modo: sel.value }).then(function (j) { say(j.message || (j.ok ? 'Modo atualizado.' : 'Não foi possível salvar o modo.'), j.ok ? null : 'error'); if (!j.ok) { loadBound(); } });
        });
        line.appendChild(sel);
        var rm = el('button', 'rc-btn rc-btn--danger', 'Tirar da ONU'); rm.type = 'button';
        rm.addEventListener('click', function () {
          if (!window.confirm('Tirar ' + (c.nome || c.login) + ' desta ONU?' + (o.clientes.length > 1 ? ' Os outros clientes continuam nela.' : ''))) { return; }
          post(D.apiRemove, { id: c.id, parte: 'onu' }).then(function (j) { say(j.message || (j.ok ? 'Feito.' : 'Não foi possível remover.'), j.ok ? null : 'error'); if (j.ok) { return Promise.all([load(), loadBound()]); } });
        });
        line.appendChild(rm); cells.appendChild(line);
      });
      add(el('td')).appendChild(addForm(o));
      tb.appendChild(tr);
    });
  }
  function addForm(o) {
    var box = el('div', 'rc-add'), q = el('input'), results = el('ul', 'rc-results'), gen = 0, timer = null;
    q.type = 'search'; q.placeholder = 'Buscar cliente (nome, login ou CPF)'; q.setAttribute('aria-label', 'Buscar cliente para a ONU ' + o.onu.onu_index); q.maxLength = 80;
    q.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(function () {
        var text = q.value.trim(), token = ++gen; results.replaceChildren();
        if (text.length < 3) { return; }
        fetch(D.apiSearch + '?q=' + encodeURIComponent(text), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
          .then(function (r) { return r.json(); }).then(function (j) {
            if (token !== gen) { return; }
            if (!j.ok) { results.appendChild(el('li', 'rf-muted', j.message || 'Não foi possível buscar.')); return; }
            if (!j.data.length) { results.appendChild(el('li', 'rf-muted', 'Nenhum cliente encontrado.')); return; }
            j.data.forEach(function (c) {
              var li = el('li'), sel = modeSelect(null), b = el('button', 'rc-result', ''); b.type = 'button';
              b.appendChild(el('strong', null, c.nome)); b.appendChild(el('span', 'rf-muted', ' · ' + c.login));
              if (c.vinculo && c.vinculo.onu) { b.appendChild(el('span', 'rc-chip', 'já tem ONU')); b.disabled = true; }
              b.addEventListener('click', function () {
                b.disabled = true;
                post(D.apiOnu, { onu_id: o.onu.id, login: c.login, acao: 'adicionar', modo: sel.value }).then(function (r) {
                  say(r.message || (r.ok ? 'Feito.' : 'Não foi possível adicionar.'), r.ok ? null : 'error');
                  if (r.ok) { return Promise.all([load(), loadBound()]); }
                  b.disabled = false;
                });
              });
              li.appendChild(b); li.appendChild(sel); results.appendChild(li);
            });
          }).catch(function () { if (token === gen) { results.appendChild(el('li', 'rf-muted', 'Não foi possível buscar.')); } });
      }, 250);
    });
    box.appendChild(q); box.appendChild(results);
    return box;
  }
  $('vc-reload').addEventListener('click', function () { load(); loadBound(); });
  load();
  loadBound();
})();
