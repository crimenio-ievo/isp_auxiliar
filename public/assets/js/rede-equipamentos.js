/* /rede-equipamentos: cadastro de OLTs da telemetria. Todo texto vem do servidor e entra por textContent
   (nunca innerHTML). Nenhum dado simulado: o que nao existe aparece como "ainda nao lida" / "a identificar". */
(function () {
  'use strict';
  var app = document.getElementById('rc-app');
  if (!app) { return; }
  var API = { list: app.dataset.api, toggle: app.dataset.apiToggle, test: app.dataset.apiTest };
  var CSRF = app.dataset.csrf || '';
  var $ = function (id) { return document.getElementById(id); };
  var state = { data: null, editing: null, pendingToggle: null, tests: {} };

  var LABELS = ['Nome', 'Site', 'Modelo', 'IP de gerência', 'Perfil', 'Coleta', 'Última leitura OK', 'Saúde', 'Ações'];
  var HEALTH = { ok: 'ok', atrasada: 'atencao', falha: 'critico', sem_leitura: 'unknown', desativada: 'unknown', sem_coleta: 'unknown' };

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined && text !== null) { n.textContent = String(text); }
    return n;
  }
  function fmtDT(s) {
    if (!s) { return null; }
    var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(s);
    return m ? m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5] : String(s);
  }
  function say(text, tone) {
    var m = $('rc-msg');
    m.textContent = text; m.hidden = !text;
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

  function load() {
    return fetch(API.list, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) {
      return r.json().then(function (j) { return { status: r.status, body: j }; });
    }).then(function (res) {
      if (!res.body || res.body.ok !== true) { say((res.body && res.body.message) || 'Não foi possível ler os equipamentos.', 'error'); $('rc-summary').textContent = ''; return; }
      state.data = res.body.data; render();
    }).catch(function () { say('Não foi possível ler os equipamentos.', 'error'); });
  }

  function profileLabel(r) {
    if (r.perfil) { return r.perfil.code + (r.perfil.status !== 'active' ? ' (' + r.perfil.status + ')' : ''); }
    if (r.perfil_pretendido) {
      var found = (state.data.perfis.planejados || []).filter(function (p) { return p.code === r.perfil_pretendido; })[0];
      return (found ? found.label : r.perfil_pretendido) + ' — perfil ainda não suportado';
    }
    return 'a identificar';
  }

  function render() {
    var d = state.data, list = d.equipamentos, tb = document.querySelector('#rc-table tbody');
    tb.replaceChildren();
    var active = list.filter(function (r) { return r.coleta_ativa; }).length;
    $('rc-summary').textContent = list.length + ' OLT(s) no mapa · ' + active + ' com coleta ativa · ' + list.filter(function (r) { return r.device_id !== null; }).length + ' com registro de coleta';
    $('rc-empty').hidden = list.length > 0;
    list.forEach(function (r) {
      var tr = el('tr'); tr.setAttribute('data-node', r.node_id);
      var cellNo = 0, origAppend = tr.appendChild.bind(tr);
      tr.appendChild = function (td) { td.setAttribute('data-label', LABELS[cellNo++] || ''); return origAppend(td); };
      tr.appendChild(el('td', null, r.nome));
      var site = el('td', r.site ? null : 'rf-muted', r.site || 'sem site'); tr.appendChild(site);
      var model = [r.fabricante, r.modelo].filter(Boolean).join(' · ');
      tr.appendChild(el('td', model ? null : 'rf-muted', model || 'a identificar'));
      tr.appendChild(el('td', r.ip ? null : 'rf-muted', r.ip || 'sem IP cadastrado'));
      tr.appendChild(el('td', r.perfil ? null : 'rf-muted', profileLabel(r)));
      var coleta = el('td'); var pill = el('span', 'rf-pill rf-pill--' + (r.coleta_ativa ? 'online' : 'unknown'), r.device_id === null ? 'Sem coleta' : (r.coleta_ativa ? 'Ativa' : 'Desativada'));
      coleta.appendChild(pill);
      if (r.device_id !== null && !r.identidade_aprovada) { coleta.appendChild(el('div', 'rf-muted', 'identidade a aprovar')); }
      tr.appendChild(coleta);
      var last = fmtDT(r.ultima_leitura_ok);
      tr.appendChild(el('td', last ? null : 'rf-muted', last || 'ainda não lida'));
      var hs = el('td'); hs.appendChild(el('span', 'rf-pill rf-pill--' + (HEALTH[r.saude.estado] || 'unknown'), r.saude.texto));
      tr.appendChild(hs);
      var act = el('td'); var box = el('div', 'rc-rowactions');
      var edit = el('button', 'rc-btn', r.device_id === null ? 'Configurar coleta' : 'Editar'); edit.type = 'button';
      edit.addEventListener('click', function () { openForm(r); });
      box.appendChild(edit);
      if (r.device_id !== null) {
        var t = el('button', 'rc-btn', 'Testar conexão'); t.type = 'button'; t.setAttribute('data-test', r.device_id);
        t.addEventListener('click', function () { runTest(r, t, act); });
        box.appendChild(t);
        var tg = el('button', 'rc-btn' + (r.coleta_ativa ? ' rc-btn--danger' : ''), r.coleta_ativa ? 'Desativar coleta' : 'Ativar coleta'); tg.type = 'button'; tg.setAttribute('data-toggle', r.device_id);
        tg.addEventListener('click', function () { r.coleta_ativa ? doToggle(r, false) : askActivate(r); });
        box.appendChild(tg);
      }
      var link = el('a', 'rc-btn', 'Ver no mapa'); link.href = app.dataset.mapUrl; box.appendChild(link);
      act.appendChild(box);
      var keep = state.tests[r.device_id]; if (keep) { act.appendChild(testBox(keep)); }
      tr.appendChild(act);
      tb.appendChild(tr);
    });
  }

  function testBox(res) {
    var box = el('div', 'rc-test'); box.setAttribute('data-test-result', res.ok ? 'ok' : 'erro');
    box.appendChild(el('strong', null, res.ok ? 'Respondeu: ' : 'Sem sucesso: '));
    box.appendChild(document.createTextNode(res.mensagem || ''));
    var reads = res.leituras || {};
    if (Object.keys(reads).length) {
      var dl = el('dl');
      Object.keys(reads).forEach(function (k) { dl.appendChild(el('dt', null, k)); dl.appendChild(el('dd', null, reads[k])); });
      box.appendChild(dl);
    }
    return box;
  }

  function runTest(r, btn) {
    btn.disabled = true; say('Testando a conexão com ' + r.nome + '… (3 leituras SNMP de sysName, sysDescr e sysUpTime)');
    post(API.test, { device_id: r.device_id }).then(function (j) {
      btn.disabled = false;
      if (j.httpStatus === 429) { say(j.message, 'warn'); return; }
      if (j.httpStatus >= 400 && !j.data) { say(j.message || 'Não foi possível testar.', 'error'); return; }
      state.tests[r.device_id] = j.data || { ok: false, mensagem: j.message };
      say(j.ok ? 'Teste concluído: o equipamento respondeu.' : (j.message || 'Teste sem sucesso.'), j.ok ? null : 'warn');
      render();
    }).catch(function () { btn.disabled = false; say('Não foi possível testar.', 'error'); });
  }

  function askActivate(r) {
    state.pendingToggle = r;
    $('rc-confirm-text').textContent = 'Ativar a coleta de "' + r.nome + '" (' + r.ip + ')? O cron passará a consultar este equipamento por SNMP (somente leitura) a cada ' + Math.round(r.intervalo_status_s / 60) + ' min. Sem a identidade de referência aprovada, os ciclos falham com "identity_mismatch" até rodar net_collect.php --device=' + r.device_id + ' --kind=status --approve-identity.';
    $('rc-confirm').hidden = false; $('rc-confirm-ok').focus();
  }
  function closeConfirm() { $('rc-confirm').hidden = true; state.pendingToggle = null; }
  function doToggle(r, enable) {
    post(API.toggle, { device_id: r.device_id, ativar: enable ? '1' : '0', confirmar: enable ? '1' : '0' }).then(function (j) {
      say(j.message || (j.ok ? 'Feito.' : 'Não foi possível alterar a coleta.'), j.ok ? null : 'error');
      if (j.ok) { return load(); }
    });
  }
  $('rc-confirm-ok').addEventListener('click', function () { var r = state.pendingToggle; closeConfirm(); if (r) { doToggle(r, true); } });
  $('rc-confirm-no').addEventListener('click', closeConfirm);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !$('rc-confirm').hidden) { closeConfirm(); } });

  // ----- formulario -----
  var form = $('rc-form');
  function field(name) { return form.elements[name]; }
  function fillSelect(sel, items, valueKey, labelFn, placeholder) {
    sel.replaceChildren();
    if (placeholder) { var o0 = new Option(placeholder, ''); sel.appendChild(o0); }
    items.forEach(function (it) { sel.appendChild(new Option(labelFn(it), String(it[valueKey]))); });
  }
  function setMode(mode) {
    Array.prototype.forEach.call(form.querySelectorAll('[data-mode]'), function (n) { n.hidden = n.getAttribute('data-mode') !== mode; });
    Array.prototype.forEach.call(form.elements.node_mode, function (r) { r.checked = r.value === mode; });
  }
  function syncPlanned() {
    var planned = isNaN(parseInt(field('perfil').value, 10)) && field('perfil').value !== '';
    $('rc-planned').hidden = !planned; $('rc-collect-fields').hidden = planned; $('rc-cred-note').hidden = planned;
  }
  function openForm(r) {
    var d = state.data; state.editing = r || null;
    $('rc-form-title').textContent = r && r.device_id !== null ? 'Editar equipamento — ' + r.nome : (r ? 'Configurar coleta — ' + r.nome : 'Nova OLT / configurar coleta');
    var free = d.equipamentos.filter(function (x) { return x.device_id === null; });
    fillSelect($('rc-node-id'), r ? [r] : free, 'node_id', function (x) { return x.nome + (x.site ? ' — ' + x.site : ''); }, r ? null : (free.length ? 'Escolha a OLT' : 'Nenhuma OLT livre no mapa'));
    fillSelect($('rc-site-id'), d.sites, 'id', function (x) { return x.nome + ' (' + (x.tipo === 'pop' ? 'POP' : 'torre') + ')'; }, d.sites.length ? 'Escolha o site' : 'Cadastre um POP no mapa antes');
    var sel = $('rc-perfil'); sel.replaceChildren();
    d.perfis.suportados.forEach(function (p) { sel.appendChild(new Option(p.vendor + ' — ' + p.code + ' (' + p.technology.toUpperCase() + ')', String(p.id))); });
    d.perfis.planejados.forEach(function (p) { sel.appendChild(new Option(p.label + ' — perfil ainda não suportado: coleta desativada', p.code)); });
    fillSelect($('rc-cred'), d.credenciais.map(function (n) { return { n: n }; }), 'n', function (x) { return x.n; }, d.credenciais.length ? 'Escolha a credencial' : 'Nenhuma NET_CRED_* no .env');
    form.reset(); say('');
    $('rc-device-id').value = r && r.device_id !== null ? r.device_id : '';
    $('rc-i-status').value = (r && r.intervalo_status_s) || d.padroes.intervalo_status_s;
    $('rc-i-power').value = (r && r.intervalo_potencia_s) || d.padroes.intervalo_potencia_s;
    $('rc-i-status').min = d.limites.status_s[0]; $('rc-i-status').max = d.limites.status_s[1];
    $('rc-i-power').min = d.limites.potencia_s[0]; $('rc-i-power').max = d.limites.potencia_s[1];
    if (r) {
      setMode('existing'); $('rc-node-id').value = String(r.node_id);
      field('fabricante').value = r.fabricante || ''; field('modelo').value = r.modelo || '';
      if (r.perfil) { field('perfil').value = String(r.perfil.id); } else if (r.perfil_pretendido) { field('perfil').value = r.perfil_pretendido; }
      field('mgmt_host').value = r.ip || ''; if (r.credencial) { $('rc-cred').value = r.credencial; }
      document.getElementById('rc-node-fs').disabled = true;
    } else {
      document.getElementById('rc-node-fs').disabled = false; setMode(free.length ? 'existing' : 'new');
    }
    syncPlanned();
    $('rc-form-card').hidden = false; $('rc-form-card').scrollIntoView && $('rc-form-card').scrollIntoView({ block: 'nearest' });
  }
  $('rc-new').addEventListener('click', function () { openForm(null); });
  $('rc-cancel').addEventListener('click', function () { $('rc-form-card').hidden = true; });
  Array.prototype.forEach.call(form.elements.node_mode, function (r) { r.addEventListener('change', function () { setMode(r.value); }); });
  field('perfil').addEventListener('change', syncPlanned);

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var f = {}, mode = form.elements.node_mode.value || 'existing', planned = !$('rc-planned').hidden;
    ['device_id', 'fabricante', 'modelo', 'perfil'].forEach(function (k) { f[k] = field(k).value.trim(); });
    if (state.editing) { f.node_id = String(state.editing.node_id); }
    else if (mode === 'existing') { f.node_id = field('node_id').value; }
    else { f.node_mode = 'new'; f.name = field('name').value.trim(); f.site_id = field('site_id').value; f.ports_pon = field('ports_pon').value; }
    if (!planned) {
      f.mgmt_host = field('mgmt_host').value.trim(); f.credential_ref = field('credential_ref').value;
      f.intervalo_status_s = field('intervalo_status_s').value; f.intervalo_potencia_s = field('intervalo_potencia_s').value;
    }
    $('rc-save').disabled = true;
    post(API.list, f).then(function (j) {
      $('rc-save').disabled = false;
      if (!j.ok) { say(j.message || 'Não foi possível salvar.', 'error'); return; }
      $('rc-form-card').hidden = true; say(j.message, j.message.indexOf('DESATIVADA') !== -1 && j.message.indexOf('mudaram') !== -1 ? 'warn' : null); return load();
    }).catch(function () { $('rc-save').disabled = false; say('Não foi possível salvar.', 'error'); });
  });

  load();
})();
