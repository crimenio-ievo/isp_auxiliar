// Teste em navegador real (Chromium headless) do /mapa: desenhar, editar e importar cabo; arrastar CTO; conferir distância.
// Usa schema descartável + sessão de harness (nada do banco real) e remove tudo no fim. Capturas só com dados fictícios.
// Uso: node tests/browser/mapa_tracado.js   (PLAYWRIGHT_PATH aponta para node_modules/playwright; CAPTURAS_DIR = destino)
const {spawnSync, spawn} = require('child_process');
const fs = require('fs'), path = require('path');
const root = path.resolve(__dirname, '../..');
const {chromium} = require(process.env.PLAYWRIGHT_PATH || '/tmp/net7-browser/node_modules/playwright');
const SHOTS = process.env.CAPTURAS_DIR || '/root/isp-auxiliar-backups/capturas-mapa-tracado';
const PORT = 8099, BASE = 'http://127.0.0.1:' + PORT;
fs.mkdirSync(SHOTS, {recursive: true});

let fail = 0; const ck = (l, c, x = '') => { if (!c) fail++; console.log(c ? '[OK]  ' : '[FALHA]', l, x); };
const hav = (a, b) => { const r = Math.PI / 180, dl = (b[0] - a[0]) * r, dg = (b[1] - a[1]) * r, h = Math.sin(dl / 2) ** 2 + Math.cos(a[0] * r) * Math.cos(b[0] * r) * Math.sin(dg / 2) ** 2; return 2 * 6371000 * Math.asin(Math.sqrt(h)); };
const poly = pts => pts.slice(1).reduce((t, p, i) => t + hav(pts[i], p), 0);
const fmt = m => m >= 1000 ? (m / 1000).toFixed(2).replace('.', ',') + ' km' : Math.round(m) + ' m';

const up = spawnSync('php', ['tests/browser/scratch_up.php'], {cwd: root, encoding: 'utf8'});
if (up.status !== 0) { console.error(up.stdout, up.stderr); process.exit(2); }
const {sessionDir, sessionId} = JSON.parse(up.stdout.trim().split('\n').pop());
const server = spawn('php', ['-S', '127.0.0.1:' + PORT, 'tests/browser/router.php'], {cwd: root, env: Object.assign({}, process.env, {SESSION_PATH: sessionDir}), stdio: 'ignore'});

(async () => {
  let browser;
  try {
    await new Promise(r => setTimeout(r, 1200));
    browser = await chromium.launch({headless: true});
    const ctx = await browser.newContext({viewport: {width: 1366, height: 820}});
    await ctx.addCookies([{name: 'isp_auxiliar_session', value: sessionId, url: BASE}]);
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push('pageerror: ' + e.message));
    page.on('console', m => { if (m.type() === 'error' && !/Failed to load resource|net::ERR/.test(m.text())) errors.push('console: ' + m.text()); });
    page.on('dialog', d => d.accept());
    const api = () => page.evaluate(async () => (await (await fetch('/api/mapa', {credentials: 'same-origin'})).json()).data);
    const shot = (n) => page.screenshot({path: path.join(SHOTS, n + '.png')});
    const mapBox = async () => (await page.locator('#map').boundingBox());
    const nodePos = async (i) => { const b = await page.locator('.mnode').nth(i).boundingBox(); return {x: b.x + b.width / 2, y: b.y + b.height / 2}; };
    const toastText = async () => (await page.locator('#toast').textContent()) || '';

    await page.goto(BASE + '/mapa', {waitUntil: 'networkidle'});
    await page.waitForSelector('.mnode');
    ck('/mapa carrega com 4 elementos fictícios', (await page.locator('.mnode').count()) === 4);

    // ---- 5. nomes de rua sobre o satélite ----
    await page.selectOption('#sel-basemap', 'satStreets');
    await page.waitForTimeout(2500);
    const tiles = await page.evaluate(() => [...document.querySelectorAll('image.mtile')].map(i => i.getAttribute('href')));
    ck('"Satélite + ruas" pede satélite, ruas e localidades', tiles.some(u => /World_Imagery/.test(u)) && tiles.some(u => /World_Transportation/.test(u)) && tiles.some(u => /World_Boundaries_and_Places/.test(u)), tiles.length + ' tiles');
    await shot('01-satelite-ruas');

    // ---- 1/2. cabo novo desenhado clicando pela rota ----
    await page.selectOption('#sel-basemap', 'osm'); await page.waitForTimeout(800);
    let t1 = await nodePos(0);
    await page.mouse.click(t1.x, t1.y); await page.waitForSelector('#btn-add-link-from'); await page.waitForTimeout(400);
    await page.click('#btn-add-link-from');
    t1 = await nodePos(0); const t2 = await nodePos(1);   // o painel lateral pode reenquadrar o mapa: reler as posições
    await page.mouse.click(t2.x, t2.y);
    await page.waitForSelector('#f-trace-draw');
    await page.click('#f-trace-draw');
    await page.waitForSelector('#tr-save');
    const mb = await mapBox();
    const y0 = t1.y;   // CTO-T1/T2 ficam na parte de baixo do mapa: os pontos clicados sobem pela rua
    const clicks = [[t1.x + 60, y0 - 90], [t1.x + 220, y0 - 130], [t2.x - 60, y0 - 90]];
    for (const [x, y] of clicks) { await page.mouse.click(x, y); await page.waitForTimeout(80); }
    ck('3 pontos clicados viram 3 vértices', (await page.locator('.mvertex').count()) === 3);
    ck('painel mostra o detalhamento ao vivo', /Traçado/.test(await page.locator('[data-trace-len]').textContent()));
    await shot('02-desenhando-tracado');
    await page.click('#tr-save');
    await page.waitForSelector('#f-trace-info');
    ck('modal volta com o traçado', /3 ponto\(s\)/.test(await page.locator('#f-trace-info').textContent()));
    await page.click('#modal-save');
    await page.waitForFunction(() => !document.querySelector('#modal-save'));
    await page.waitForTimeout(500);
    let d = await api();
    let link = d.links[0];
    ck('cabo criado com path_json de 3 pontos e sem length_m', d.links.length === 1 && link.path_json && link.path_json.pontos.length === 3 && link.length_m === null);
    const A = d.nodes.find(n => n.name === 'CTO-T1'), B = d.nodes.find(n => n.name === 'CTO-T2');
    const line = () => [[A.lat, A.lng], ...link.path_json.pontos, [B.lat, B.lng]];
    const expected = poly(line());
    // abrir o painel do trecho clicando sobre o cabo (ponto do meio do segmento 2)
    const openLinkPanel = async (fr = 0.5) => {
      const pts = await page.evaluate(() => { const r = document.querySelector('#map svg').getBoundingClientRect(); return [...document.querySelector('polyline.mlink-hit').points].map(p => [p.x + r.left, p.y + r.top]); });
      const m = pts[1], n = pts[2]; await page.mouse.click(m[0] + (n[0] - m[0]) * fr, m[1] + (n[1] - m[1]) * fr);; await page.waitForSelector('#btn-ver-trecho'); await page.click('#btn-ver-trecho'); await page.waitForSelector('[data-link-len]');
    };
    await openLinkPanel();
    const lenTxt = () => page.locator('[data-link-len]').textContent();
    ck('distância mostrada = soma haversine do traçado (conferida fora da tela)', (await lenTxt()).startsWith(fmt(expected)), (await lenTxt()) + ' esperado ' + fmt(expected));
    ck('mais longa que a linha reta (o cabo segue o traçado)', expected > hav([A.lat, A.lng], [B.lat, B.lng]));
    await shot('03-cabo-com-tracado');

    // ---- 1. editar: arrastar, "+" , remover ----
    await page.click('#btn-trace-link');
    await page.waitForSelector('.mvertex');
    const v0 = await page.locator('.mvertex').nth(0).boundingBox();
    await page.mouse.move(v0.x + v0.width / 2, v0.y + v0.height / 2); await page.mouse.down();
    await page.mouse.move(v0.x + 40, v0.y + 50, {steps: 6}); await page.mouse.up();
    const v0b = await page.locator('.mvertex').nth(0).boundingBox();
    ck('arrastar vértice com o mouse move o ponto', Math.abs(v0b.x - (v0.x + 40 - v0.width / 2)) < 6 && Math.abs(v0b.y - (v0.y + 50 - v0.height / 2)) < 6, JSON.stringify([v0, v0b]));
    const mid = await page.locator('.mmid').nth(0).boundingBox();
    await page.mouse.move(mid.x + mid.width / 2, mid.y + mid.height / 2); await page.mouse.down();
    await page.mouse.move(mid.x + 20, mid.y + 60, {steps: 6}); await page.mouse.up();
    ck('arrastar o "+" cria um ponto (4 vértices)', (await page.locator('.mvertex').count()) === 4);
    await page.locator('.mvertex').nth(3).dblclick();
    ck('duplo clique remove um ponto (3 vértices)', (await page.locator('.mvertex').count()) === 3);
    await shot('04-editando-tracado');
    await page.click('#tr-save');
    await page.waitForTimeout(700);
    d = await api(); link = d.links[0];
    ck('salvar grava o traçado editado (3 pontos, 1º movido)', link.path_json.pontos.length === 3 && Math.abs(link.path_json.pontos[0][0] - (JSON.parse(JSON.stringify(link.path_json.pontos))[0][0])) < 1e-9);
    // cancelar não grava
    const snapshot = JSON.stringify(link.path_json);
    await openLinkPanel(); await page.click('#btn-trace-link'); await page.waitForSelector('.mvertex');
    await page.mouse.click(t1.x + 20, t1.y - 200); await page.keyboard.press('Escape'); await page.waitForTimeout(300);
    ck('Esc descarta sem gravar', JSON.stringify((await api()).links[0].path_json) === snapshot);

    // ---- "Seguir rua" sem serviço: segue no modo manual ----
    await openLinkPanel(); await page.click('#btn-trace-link'); await page.waitForSelector('#tr-snap');
    await page.click('#tr-snap');
    const before = await page.locator('.mvertex').count();
    await page.mouse.click(t1.x + 100, t1.y - 160); await page.waitForTimeout(700);
    ck('sem serviço de rotas: aviso e ponto manual, tela continua usável', (await page.locator('.mvertex').count()) === before + 1 && /rotas|à mão|linha reta/i.test(await toastText() + await page.locator('#tr-msg').textContent()), await toastText());
    await page.keyboard.press('Escape'); await page.waitForTimeout(300);

    // ---- 2. reservas, folga, manual ----
    await openLinkPanel(); await page.click('#btn-edit-link'); await page.waitForSelector('#f-rs-add');
    await page.click('#f-rs-add');
    await page.locator('#f-rs-rows .rs-local').first().fill('CTO-T1');
    await page.locator('#f-rs-rows .rs-m').first().fill('20');
    await page.fill('#f-folga', '10');
    await page.click('#modal-save'); await page.waitForTimeout(800);
    d = await api(); link = d.links[0];
    const tr = poly(line());
    const exp2 = tr * 1.1 + 20;
    await openLinkPanel();
    ck('total = traçado + folga 10% + reserva 20 m', (await lenTxt()).startsWith(fmt(exp2)), (await lenTxt()) + ' esperado ' + fmt(exp2));
    const det = (await page.locator('[data-link-detail]').textContent()).replace(/\s+/g, '');
    ck('traçado, folga e reservas em separado', det.includes(('Traçado' + fmt(tr)).replace(/\s+/g, '')) && det.includes('Reservastécnicas+20m'), det);
    await shot('05-distancia-detalhada');

    // ---- B2: reserva técnica como ponto no mapa ----
    const cablePt = async (seg, f) => page.evaluate(([sg, fr]) => { const r = document.querySelector('#map svg').getBoundingClientRect(); const P = [...document.querySelector('polyline.mlink-hit').points]; const a = P[sg], b = P[sg + 1]; return {x: r.left + a.x + (b.x - a.x) * fr, y: r.top + a.y + (b.y - a.y) * fr}; }, [seg, f]);
    ck('reserva antiga (sem pos) aparece como "sem posição" no painel e não tem ícone', /sem posição/.test(await page.locator('#panel-right').textContent()) && (await page.locator('.mreserve').count()) === 0);
    await page.locator('[data-place-reserve="0"]').click();
    ck('"Posicionar" mostra a faixa do modo', /Clique sobre o cabo/.test(await page.locator('#mode-banner').textContent()));
    let cp = await cablePt(1, 0.5); await page.mouse.click(cp.x, cp.y); await page.waitForTimeout(800);
    d = await api(); link = d.links[0];
    ck('a reserva ganhou posição gravada no cabo e o ícone aparece no mapa', Array.isArray(link.path_json.reservas[0].pos) && (await page.locator('.mreserve').count()) === 1);
    await shot('05b-reserva-no-mapa');
    await page.locator('.mreserve').first().click(); await page.waitForTimeout(200);
    const tt = await toastText();
    ck('clicar no ícone mostra os metros da reserva (20 m)', /20 m/.test(tt) && /CTO-T1/.test(tt), tt);
    await openLinkPanel(0.2);
    const pos0 = JSON.stringify((await api()).links[0].path_json.reservas[0].pos);
    const ib = await page.locator('.mreserve').first().boundingBox();
    const tgt = await cablePt(2, 0.5);
    await page.mouse.move(ib.x + ib.width / 2, ib.y + ib.height / 2); await page.mouse.down(); await page.mouse.move(tgt.x, tgt.y + 25, {steps: 8}); await page.mouse.up(); await page.waitForTimeout(800);
    ck('arrastar o ícone (cabo selecionado) grava outra posição', JSON.stringify((await api()).links[0].path_json.reservas[0].pos) !== pos0);
    await openLinkPanel(); await page.click('#btn-reserve-link');
    cp = await cablePt(0, 0.5); await page.mouse.click(cp.x, cp.y);
    await page.waitForSelector('#f-rsv-m'); await page.fill('#f-rsv-local', 'poste 7'); await page.fill('#f-rsv-m', '12'); await page.click('#modal-save'); await page.waitForTimeout(800);
    d = await api(); link = d.links[0];
    ck('marcar nova reserva clicando no cabo: 2 reservas, as 2 posicionadas, 2 ícones; total soma 20 + 12', link.path_json.reservas.length === 2 && link.path_json.reservas.every(r => r.pos) && (await page.locator('.mreserve').count()) === 2 && link.path_json.reservas.reduce((t, r) => t + r.m, 0) === 32);
    await shot('05c-duas-reservas');
    await page.keyboard.press('Escape');
    await page.click('#btn-edit-link'); await page.waitForSelector('#f-lk-len');
    await page.fill('#f-lk-len', '500'); await page.click('#modal-save'); await page.waitForTimeout(800);
    await openLinkPanel();
    ck('comprimento manual prevalece e aparece marcado', /500 m \(manual\)/.test(await lenTxt()) && /manual/.test(await page.locator('[data-link-detail]').textContent()), await lenTxt());
    await shot('06-comprimento-manual');
    // volta ao calculado
    await page.click('#btn-edit-link'); await page.waitForSelector('#f-lk-len'); await page.fill('#f-lk-len', ''); await page.click('#modal-save'); await page.waitForTimeout(800);

    // ---- arrastar uma CTO e conferir a distância ----
    await page.keyboard.press('Escape');
    d = await api(); link = d.links[0];
    const resM = l => (l.path_json.reservas || []).reduce((t, r) => t + r.m, 0);
    const beforeTotal = poly(line()) * 1.1 + resM(link);
    if (await page.locator('#panel-close-btn').count()) await page.click('#panel-close-btn');   // o painel cobre a CTO-T2
    await page.waitForTimeout(400);
    const t2b = await nodePos(1);
    await page.mouse.click(t2b.x, t2b.y); await page.waitForTimeout(500);   // 1º clique seleciona (o arraste só vale no nó selecionado)
    const t2c = await nodePos(1);
    await page.mouse.move(t2c.x, t2c.y); await page.mouse.down();
    await page.mouse.move(t2c.x + 60, t2c.y - 40, {steps: 8}); await page.mouse.up();
    await page.waitForTimeout(900);
    d = await api(); const B2 = d.nodes.find(n => n.name === 'CTO-T2'); const link2 = d.links[0];
    ck('arrastar a CTO gravou a nova posição e manteve os pontos do meio', (B2.lat !== B.lat || B2.lng !== B.lng) && link2.path_json.pontos.length === link.path_json.pontos.length, JSON.stringify([t2b, t2c, B, B2]));
    const exp3 = poly([[A.lat, A.lng], ...link2.path_json.pontos, [B2.lat, B2.lng]]) * 1.1 + resM(link2);
    await openLinkPanel();
    ck('distância acompanha a CTO movida (ponta segue o nó)', (await lenTxt()).startsWith(fmt(exp3)) && fmt(exp3) !== fmt(beforeTotal), (await lenTxt()) + ' esperado ' + fmt(exp3) + ' antes ' + fmt(beforeTotal));
    await shot('07-cto-arrastada');

    // ---- 4. importar KML ----
    const nT2 = d.nodes.find(n => n.name === 'CTO-T2'), nT3 = d.nodes.find(n => n.name === 'CTO-T3'), pop = d.nodes.find(n => n.name === 'POP-T');
    const ls = (name, pts) => `<Placemark><name>${name}</name><LineString><coordinates>${pts.map(p => p[1] + ',' + p[0] + ',0').join(' ')}</coordinates></LineString></Placemark>`;
    const kml = `<?xml version="1.0"?><kml xmlns="http://www.opengis.net/kml/2.2"><Document>`
      + ls('T2-T3', [[nT2.lat, nT2.lng], [(nT2.lat + nT3.lat) / 2, nT2.lng - 0.0003], [nT3.lat + 0.00001, nT3.lng]])
      + ls('T3-POP', [[nT3.lat, nT3.lng], [nT3.lat + 0.0002, (nT3.lng + pop.lng) / 2], [pop.lat, pop.lng]])
      + ls('solto', [[-21.1, -43.1], [-21.2, -43.2]]) + `</Document></kml>`;
    const kmlPath = path.join(sessionDir, 'rede-teste.kml'); fs.writeFileSync(kmlPath, kml);
    await page.keyboard.press('Escape'); await page.mouse.click(mb.x + 30, mb.y + mb.height - 30); await page.waitForTimeout(300);
    await page.click('#btn-add'); await page.click('#add-import-cables');
    await page.setInputFiles('#f-imp-file', kmlPath);
    await page.click('#f-imp-read'); await page.waitForSelector('input.imp-chk');
    ck('prévia: 2 linhas prontas e 1 sem elementos', (await page.locator('input.imp-chk:checked').count()) === 2 && (await page.locator('input.imp-chk:disabled').count()) === 1);
    const res = await page.locator('#f-imp-result').textContent();
    ck('prévia lista a qual CTO cada cabo liga', /CTO-T2 → CTO-T3/.test(res) && /CTO-T3 → POP-T/.test(res), res.slice(0, 160));
    await shot('08-importar-kml-previa');
    const nLinks = (await api()).links.length;
    ck('a prévia não gravou nada', nLinks === 1);
    await page.click('#f-imp-go'); await page.waitForTimeout(1000);
    d = await api();
    ck('importou 2 cabos com traçado', d.links.length === 3 && d.links.slice(1).every(l => l.path_json && l.path_json.pontos.length >= 1), d.links.length + ' cabos');
    await page.waitForSelector('polyline.mlink');
    ck('cabos importados desenhados como polilinha', (await page.locator('polyline.mlink').count()) === 3);
    await shot('09-apos-importar');

    ck('sem erros de script no console', errors.length === 0, errors.join(' | '));
    await ctx.close();
  } catch (e) {
    fail++; console.error('ERRO NO TESTE:', e && e.stack || e);
  } finally {
    if (browser) await browser.close();
    server.kill();
    const down = spawnSync('php', ['tests/browser/scratch_down.php', sessionDir], {cwd: root, encoding: 'utf8'});
    console.log(down.stdout.trim(), '| sessão de harness e schema removidos');
    console.log('falhas:', fail);
    process.exit(fail ? 1 : 0);
  }
})();
