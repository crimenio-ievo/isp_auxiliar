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
        var ok = el('button', 'rc-btn', 'Confirmar'); ok.type = 'button'; ok.addEventListener('click', function () { act(s, c, 'confirmar', row); });
        var no = el('button', 'rc-btn rc-btn--danger', 'Recusar'); no.type = 'button'; no.addEventListener('click', function () { act(s, c, 'recusar', row); });
        row.appendChild(ok); row.appendChild(no); box.appendChild(row);
      });
      cell.appendChild(box); tb.appendChild(tr);
    });
  }

  function act(s, c, acao, row) {
    Array.prototype.forEach.call(row.querySelectorAll('button'), function (b) { b.disabled = true; });
    post(D.apiOnu, { onu_id: s.onu.id, login: c.login, acao: acao, confianca: c.confianca }).then(function (j) {
      say(j.message || (j.ok ? 'Feito.' : 'Não foi possível concluir.'), j.ok ? null : 'error');
      if (j.ok) { return load(); }
      Array.prototype.forEach.call(row.querySelectorAll('button'), function (b) { b.disabled = false; });
    });
  }
  $('vc-reload').addEventListener('click', load);
  load();
})();
