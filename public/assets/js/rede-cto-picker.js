/* "Definir CTO" de uma ONU: busca por nome ou, sem texto, as CTOs mais próximas das coordenadas do cliente, com as portas livres.
   Compartilhado por /rede-fibra (ficha da ONU) e /rede-vinculos ("ONUs já vinculadas"). Todo texto dinâmico entra por textContent.
   RedeCtoPicker.open(host, {onuId, nClientes, atual, apiList, apiBind, csrf, onDone(msg)}) */
(function () {
  'use strict';
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined && text !== null) { n.textContent = String(text); } return n; }
  function post(url, csrf, fields) {
    var body = new URLSearchParams();
    Object.keys(fields).forEach(function (k) { if (fields[k] !== null && fields[k] !== undefined) { body.append(k, fields[k]); } });
    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf }, body: body.toString() })
      .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Resposta inesperada do servidor.' }; }).then(function (j) { j.httpStatus = r.status; return j; }); });
  }
  function fmtDist(m) { return m === null || m === undefined ? '' : (m >= 1000 ? (m / 1000).toFixed(2).replace('.', ',') + ' km' : m + ' m'); }

  function open(host, o) {
    var old = host.querySelector('.rc-picker'); if (old) { old.remove(); return; }
    var box = el('div', 'rc-picker'); box.setAttribute('role', 'group'); box.setAttribute('aria-label', 'Definir CTO');
    var title = el('strong', null, 'Definir CTO'); box.appendChild(title);
    box.appendChild(el('p', 'rf-muted', o.nClientes > 1 ? 'A porta é da ONU: os ' + o.nClientes + ' clientes dela vão juntos.' : 'A porta é da ONU.'));
    var q = el('input'); q.type = 'search'; q.maxLength = 80; q.placeholder = 'Nome da CTO (vazio = mais próximas do cliente)'; q.setAttribute('aria-label', 'Buscar CTO'); box.appendChild(q);
    var info = el('p', 'rf-muted'); info.setAttribute('role', 'status'); box.appendChild(info);
    var msg = el('p', 'rc-msg'); msg.hidden = true; msg.setAttribute('role', 'status'); box.appendChild(msg);
    var list = el('ul', 'rc-results'); box.appendChild(list);
    var close = el('button', 'rc-btn', 'Fechar'); close.type = 'button'; close.addEventListener('click', function () { box.remove(); }); box.appendChild(close);
    host.appendChild(box);
    var gen = 0, timer = null;

    function say(t, tone) { msg.textContent = t || ''; msg.hidden = !t; if (tone) { msg.setAttribute('data-tone', tone); } else { msg.removeAttribute('data-tone'); } }
    function confirmPort(c, p, li, mover) {
      var ask = el('div', 'rc-pick-confirm');
      ask.appendChild(el('span', null, (mover ? 'Mover a ONU' : 'Ligar a ONU') + (o.nClientes > 1 ? ' (' + o.nClientes + ' clientes)' : '') + ' para ' + c.nome + ', ' + p.rotulo + '?'));
      var yes = el('button', 'rc-btn rc-btn--ok', 'Confirmar'), no = el('button', 'rc-btn', 'Cancelar'); yes.type = 'button'; no.type = 'button';
      no.addEventListener('click', function () { ask.remove(); });
      yes.addEventListener('click', function () {
        yes.disabled = true;
        post(o.apiBind, o.csrf, { onu_id: o.onuId, cto_id: c.id, port_id: p.id, mover: mover ? '1' : '0' }).then(function (j) {
          if (j.httpStatus === 409 && j.codigo === 'mover') { ask.remove(); say(j.message, 'warn'); confirmPort(c, p, li, true); return; }
          if (!j.ok) { yes.disabled = false; say(j.message || 'Não foi possível definir a CTO.', 'error'); return; }
          box.remove(); if (typeof o.onDone === 'function') { o.onDone(j.message || 'CTO definida.'); }
        });
      });
      ask.appendChild(yes); ask.appendChild(no); li.appendChild(ask);
    }
    function render(data) {
      list.replaceChildren();
      info.textContent = data.aviso ? data.aviso : (data.referencia ? 'Mais próximas das coordenadas de ' + data.referencia.login + '.' : (q.value.trim() ? 'Resultado por nome.' : ''));
      if (data.atual) { info.textContent += (info.textContent ? ' ' : '') + 'Hoje: ' + data.atual.cto + ', ' + data.atual.porta + '.'; }
      if (!data.ctos.length) { list.appendChild(el('li', 'rf-muted', 'Nenhuma CTO encontrada.')); return; }
      data.ctos.forEach(function (c) {
        var li = el('li');
        li.appendChild(el('strong', null, c.nome));
        li.appendChild(el('span', 'rf-muted', (c.distancia_m !== null ? ' · ' + fmtDist(c.distancia_m) : '') + ' · ' + c.livres.length + ' livre(s) de ' + c.total_portas));
        var ports = el('div', 'rc-rowactions');
        if (!c.livres.length) { ports.appendChild(el('span', 'rf-muted', 'sem porta livre')); }
        c.livres.slice(0, 16).forEach(function (p) {
          var b = el('button', 'rc-btn', p.rotulo); b.type = 'button'; b.setAttribute('aria-label', 'Escolher ' + p.rotulo + ' de ' + c.nome);
          b.addEventListener('click', function () { var prev = li.querySelector('.rc-pick-confirm'); if (prev) { prev.remove(); } say(''); confirmPort(c, p, li, !!data.atual); });
          ports.appendChild(b);
        });
        if (c.livres.length > 16) { ports.appendChild(el('span', 'rf-muted', '+' + (c.livres.length - 16) + ' livres')); }
        li.appendChild(ports); list.appendChild(li);
      });
    }
    function load() {
      var token = ++gen; info.textContent = 'Buscando…';
      fetch(o.apiList + '?onu_id=' + encodeURIComponent(o.onuId) + '&q=' + encodeURIComponent(q.value.trim()), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); }).then(function (j) {
          if (token !== gen) { return; }
          if (!j || j.ok !== true) { info.textContent = ''; say((j && j.message) || 'Não foi possível buscar.', 'error'); return; }
          render(j.data);
        }).catch(function () { if (token === gen) { info.textContent = ''; say('Não foi possível buscar.', 'error'); } });
    }
    q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(load, 250); });
    load(); q.focus();
  }
  window.RedeCtoPicker = { open: open };
})();
