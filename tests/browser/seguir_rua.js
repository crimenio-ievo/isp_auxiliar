// B3: "Seguir rua" de ponta a ponta, SEM OSRM de verdade: um servidor HTTP falso (Node) responde no formato do OSRM e o PHP real
// (OsrmRouter + curl) o consulta pela URL gravada em provider_settings via scripts/map_config.php. CTO A -> CTO B vira traçado.
// Schema descartavel + sessao de harness + .env temporario (removidos no fim). Nenhum equipamento, MkAuth ou OLT.
const {spawnSync, spawn} = require('child_process');
const http = require('http');
const fs = require('fs'), path = require('path');
const root = path.resolve(__dirname, '../..');
const {chromium} = require(process.env.PLAYWRIGHT_PATH || '/tmp/net7-browser/node_modules/playwright');
const SHOTS = process.env.CAPTURAS_DIR || '/root/isp-auxiliar-backups/capturas-pre-producao';
const PORT = 8096, OSRM = 5099, BASE = 'http://127.0.0.1:' + PORT;
fs.mkdirSync(SHOTS, {recursive: true});
let fail = 0; const ck = (l, c, x = '') => { if (!c) fail++; console.log(c ? '[OK]  ' : '[FALHA]', l, x); };
const hav = (a, b) => { const r = Math.PI / 180, dl = (b[0] - a[0]) * r, dg = (b[1] - a[1]) * r, h = Math.sin(dl / 2) ** 2 + Math.cos(a[0] * r) * Math.cos(b[0] * r) * Math.sin(dg / 2) ** 2; return 2 * 6371000 * Math.asin(Math.sqrt(h)); };

const envFile = path.join(root, '.env');
if (fs.existsSync(envFile)) { console.error('Ja existe um .env nesta worktree: nao sobrescrevo. Abortando.'); process.exit(2); }
fs.writeFileSync(envFile, 'DB_HOST=localhost\nDB_DATABASE=isp_auxiliar_browser_sim\nDB_USERNAME=root\nDB_PASSWORD=\nSESSION_PATH=\nSESSION_COOKIE_SECURE=false\nAPP_ENV=local\nNET_COLLECTOR_ENABLED=0\n');
const up = spawnSync('php', ['tests/browser/scratch_up.php'], {cwd: root, encoding: 'utf8'});
if (up.status !== 0) { fs.unlinkSync(envFile); console.error(up.stdout, up.stderr); process.exit(2); }
const {sessionDir, sessionId} = JSON.parse(up.stdout.trim().split('\n').pop());

// OSRM falso: rota em "L" (sobe/desce pela rua e depois segue na transversal), com pontos intermediarios
const asked = [];
let osrmOn = true;
const osrm = http.createServer((req, res) => {
  const m = /^\/route\/v1\/([a-z]+)\/(-?[\d.]+),(-?[\d.]+);(-?[\d.]+),(-?[\d.]+)\?(.*)$/.exec(req.url);
  if (!m || !osrmOn) { res.writeHead(m ? 503 : 400); res.end('{"code":"Error"}'); return; }
  const [, prof, lo1, la1, lo2, la2, qs] = m; asked.push({prof, from: [+la1, +lo1], to: [+la2, +lo2], qs});
  const A = [+la1, +lo1], B = [+la2, +lo2], C1 = [A[0] + 0.0012, A[1]], C2 = [A[0] + 0.0012, B[1]];   // a rua sobe, atravessa e desce
  const leg = (p, q, n) => Array.from({length: n}, (_, i) => [p[0] + (q[0] - p[0]) * (i + 1) / (n + 1), p[1] + (q[1] - p[1]) * (i + 1) / (n + 1) + (i % 2 ? 0.00001 : 0)]);
  const pts = [A, ...leg(A, C1, 4), C1, ...leg(C1, C2, 4), C2, ...leg(C2, B, 4), B].map(([la, lo]) => [lo, la]);
  res.writeHead(200, {'Content-Type': 'application/json'});
  res.end(JSON.stringify({code: 'Ok', routes: [{geometry: {type: 'LineString', coordinates: pts}, distance: 0, duration: 0}]}));
}).listen(OSRM, '127.0.0.1');
const server = spawn('php', ['-S', '127.0.0.1:' + PORT, '-t', 'public', 'tests/browser/router.php'], {cwd: root, env: Object.assign({}, process.env, {SESSION_PATH: sessionDir}), stdio: 'ignore'});

(async () => {
  let browser;
  try {
    await new Promise(r => setTimeout(r, 1200));
    // 1) sem URL: desligado e a tela cai no manual (a configuracao so por script)
    const cfg0 = spawnSync('php', ['scripts/map_config.php', '--show'], {cwd: root, encoding: 'utf8'});
    ck('map_config --show: URL do OSRM "nao definido" por padrao', /map\.routing\.osrm_url\s+\(nao definido\)/.test(cfg0.stdout), cfg0.stdout.split('\n').slice(0, 3).join(' | '));
    const sim = spawnSync('php', ['scripts/map_config.php', '--osrm-url=http://127.0.0.1:' + OSRM, '--slack-pct=5'], {cwd: root, encoding: 'utf8'});
    ck('sem --apply e so simulacao (nada gravado)', /simulacao/.test(sim.stdout) && /nao definido/.test(spawnSync('php', ['scripts/map_config.php', '--show'], {cwd: root, encoding: 'utf8'}).stdout));
    ck('URL invalida e recusada', spawnSync('php', ['scripts/map_config.php', '--osrm-url=file:///etc/passwd'], {cwd: root, encoding: 'utf8'}).status === 1);

    browser = await chromium.launch({headless: true});
    const ctx = await browser.newContext({viewport: {width: 1366, height: 820}});
    await ctx.addCookies([{name: 'isp_auxiliar_session', value: sessionId, url: BASE}]);
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push('pageerror: ' + e.message));
    page.on('console', m => { if (m.type() === 'error' && !/Failed to load resource|net::ERR/.test(m.text())) errors.push('console: ' + m.text()); });
    const api = async () => page.evaluate(async () => (await (await fetch('/api/mapa', {credentials: 'same-origin'})).json()).data);
    const toastText = async () => (await page.locator('#toast').textContent()) || '';
    await page.goto(BASE + '/mapa', {waitUntil: 'networkidle'});
    await page.waitForSelector('.mnode');
    let d = await api();
    ck('GET /api/mapa: routing.enabled = false sem URL', d.routing && d.routing.enabled === false);
    // cabo CTO-T1 -> CTO-T2 (reta) criado pela API da propria tela
    const A = d.nodes.find(n => n.name === 'CTO-T1'), B = d.nodes.find(n => n.name === 'CTO-T2');
    await page.evaluate(async ([a, b]) => { const fd = new FormData(); fd.append('link_type', 'distribuicao'); fd.append('from_node_id', a); fd.append('to_node_id', b); fd.append('label', 'A-B'); fd.append('attrs', '{}');
      await fetch('/api/mapa/links', {method: 'POST', body: fd, credentials: 'same-origin', headers: {'X-CSRF-Token': window.ISP_MAP.csrf, Accept: 'application/json'}}); }, [A.id, B.id]);
    await page.reload({waitUntil: 'networkidle'}); await page.waitForSelector('.mnode');
    const openCable = async () => {
      if (await page.locator('#btn-trace-link').count()) { return; } const c = await page.evaluate(() => { const l = document.querySelector('line.mlink-hit, polyline.mlink-hit'); const r = document.querySelector('#map svg').getBoundingClientRect(); if (l.tagName === 'line') { return {x: r.left + (+l.getAttribute('x1') + +l.getAttribute('x2')) / 2, y: r.top + (+l.getAttribute('y1') + +l.getAttribute('y2')) / 2}; } const P = [...l.points]; const m = P[Math.floor(P.length / 2)]; return {x: r.left + m.x, y: r.top + m.y}; });
      await page.mouse.click(c.x, c.y); await page.waitForSelector('#btn-ver-trecho'); await page.click('#btn-ver-trecho'); await page.waitForSelector('#btn-trace-link'); };
    await openCable(); await page.click('#btn-trace-link'); await page.waitForSelector('#tr-route-end');
    await page.click('#tr-route-end'); await page.waitForTimeout(900);
    ck('sem URL do OSRM: aviso de servico nao configurado e nenhum ponto (modo manual segue)', /não configurado/.test(await toastText()) && (await page.locator('.mvertex').count()) === 0, await toastText());
    await page.keyboard.press('Escape');

    // 2) configura a URL (script oficial) e repete: CTO A -> CTO B gera o traçado
    const apply = spawnSync('php', ['scripts/map_config.php', '--osrm-url=http://127.0.0.1:' + OSRM, '--slack-pct=5', '--apply'], {cwd: root, encoding: 'utf8'});
    ck('map_config --apply grava a URL e a folga', apply.status === 0 && /Gravado/.test(apply.stdout) && /osrm_url\s+http:\/\/127\.0\.0\.1:5099/.test(spawnSync('php', ['scripts/map_config.php', '--show'], {cwd: root, encoding: 'utf8'}).stdout));
    await page.reload({waitUntil: 'networkidle'}); await page.waitForSelector('.mnode');
    d = await api();
    ck('GET /api/mapa: routing.enabled = true e folga padrao 5 %', d.routing.enabled === true && d.defaults['map.cable.slack_pct'] === 5);
    await openCable(); await page.click('#btn-trace-link'); await page.waitForSelector('#tr-route-end');
    await page.click('#tr-route-end'); await page.waitForTimeout(1200);
    const nv = await page.locator('.mvertex').count();
    ck('"Seguir rua até o destino": o servidor consultou o OSRM (curl) com origem = CTO A e destino = CTO B', asked.length === 1 && Math.abs(asked[0].from[0] - A.lat) < 1e-5 && Math.abs(asked[0].to[1] - B.lng) < 1e-5 && /overview=full&geometries=geojson/.test(asked[0].qs), JSON.stringify(asked[0]));
    ck('o resultado virou pontos editáveis (a rota faz a volta pela rua: os 2 cantos sobram na simplificação)', nv >= 2 && nv <= 12, 'vértices=' + nv);
    ck('a mensagem do painel informa os pontos', /seguindo a rua/.test(await page.locator('#tr-msg').textContent()));
    await page.screenshot({path: path.join(SHOTS, '10-seguir-rua-ate-o-destino.png')});
    // arrastar um ponto continua possível (são pontos comuns)
    const v0 = await page.locator('.mvertex').first().boundingBox();
    await page.mouse.move(v0.x + 8, v0.y + 8); await page.mouse.down(); await page.mouse.move(v0.x + 30, v0.y - 20, {steps: 5}); await page.mouse.up();
    await page.click('#tr-save'); await page.waitForTimeout(900);
    d = await api(); const lk = d.links[0];
    const line = [[A.lat, A.lng], ...lk.path_json.pontos, [B.lat, B.lng]];
    const traced = line.slice(1).reduce((t, p, i) => t + hav(line[i], p), 0);
    ck('salvou: path_json com os pontos da rua; traçado maior que a linha reta (a rota faz a esquina)', lk.path_json.pontos.length === nv && traced > hav([A.lat, A.lng], [B.lat, B.lng]) * 1.05, Math.round(traced) + ' m vs reta ' + Math.round(hav([A.lat, A.lng], [B.lat, B.lng])) + ' m');

    // 3) "Seguir rua ao clicar" (ponto a ponto)
    await openCable(); await page.click('#btn-trace-link'); await page.waitForSelector('#tr-snap');
    await page.click('#tr-snap'); const before = await page.locator('.mvertex').count(); const nAsked = asked.length;
    const mb = await page.locator('#map').boundingBox();
    await page.mouse.click(mb.x + 260, mb.y + 250); await page.waitForTimeout(1000);
    ck('clique com "seguir rua" liga: consulta o OSRM do ultimo ponto ate o clique e acrescenta os pontos', asked.length === nAsked + 1 && (await page.locator('.mvertex').count()) > before);

    // 4) servico fora do ar: segue no modo manual
    osrmOn = false; const b2 = await page.locator('.mvertex').count();
    await page.mouse.click(mb.x + 300, mb.y + 300); await page.waitForTimeout(1000);
    ck('OSRM fora do ar (503): aviso e o clique vira ponto manual; a tela continua usavel', (await page.locator('.mvertex').count()) === b2 + 1 && /não respondeu|à mão|linha reta/.test(await toastText() + await page.locator('#tr-msg').textContent()) && (await page.locator('#tr-save').count()) === 1, await toastText());
    osrmOn = true;
    await page.keyboard.press('Escape');
    ck('sem erros de script no console', errors.length === 0, errors.join(' | '));
    await ctx.close();
  } catch (e) {
    fail++; console.error('ERRO NO TESTE:', e && e.stack || e);
  } finally {
    if (browser) await browser.close();
    server.kill(); osrm.close();
    const down = spawnSync('php', ['tests/browser/scratch_down.php', sessionDir], {cwd: root, encoding: 'utf8'});
    try { fs.unlinkSync(envFile); } catch (e) { /* ja removido */ }
    console.log(down.stdout.trim(), '| sessão de harness, schema e .env temporario removidos');
    console.log('falhas:', fail);
    process.exit(fail ? 1 : 0);
  }
})();
