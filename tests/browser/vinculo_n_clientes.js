// Navegador real (Chromium headless): N clientes por ONU em /rede-fibra, ficha da CTO e /rede-vinculos.
// Schema descartavel com dados FICTICIOS + sessao de harness (removidos no fim). O MkAuth nao e consultado (busca de cliente fica de fora:
// coberta por jsdom e pelo smoke PHP). Capturas sem dados de cliente reais.
const {spawnSync, spawn} = require('child_process');
const fs = require('fs'), path = require('path');
const root = path.resolve(__dirname, '../..');
const {chromium} = require(process.env.PLAYWRIGHT_PATH || '/tmp/net7-browser/node_modules/playwright');
const SHOTS = process.env.CAPTURAS_DIR || '/root/isp-auxiliar-backups/capturas-vinculo-n-clientes';
const PORT = 8098, BASE = 'http://127.0.0.1:' + PORT;
fs.mkdirSync(SHOTS, {recursive: true});
let fail = 0; const ck = (l, c, x = '') => { if (!c) fail++; console.log(c ? '[OK]  ' : '[FALHA]', l, x); };

const up = spawnSync('php', ['tests/browser/scratch_up_vinculos.php'], {cwd: root, encoding: 'utf8'});
if (up.status !== 0) { console.error(up.stdout, up.stderr); process.exit(2); }
const {sessionDir, sessionId} = JSON.parse(up.stdout.trim().split('\n').pop());
const server = spawn('php', ['-S', '127.0.0.1:' + PORT, '-t', 'public', 'tests/browser/router.php'], {cwd: root, env: Object.assign({}, process.env, {SESSION_PATH: sessionDir}), stdio: 'ignore'});

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
    const shot = n => page.screenshot({path: path.join(SHOTS, n + '.png'), fullPage: true});
    const api = async (p) => page.evaluate(async (u) => (await (await fetch(u, {credentials: 'same-origin'})).json()), p);

    // ---- /rede-fibra ----
    await page.goto(BASE + '/rede-fibra', {waitUntil: 'networkidle'});
    for (let i = 0; i < 6 && !(await page.locator('.rf-row--onu').count()); i++) {   // a arvore abre recolhida: expande o que houver
      const tg = page.locator('.rf-toggle[aria-expanded="false"]'); if (!(await tg.count())) { break; } await tg.first().click(); await page.waitForTimeout(150);
    }
    await page.waitForSelector('.rf-row--onu');
    const row1 = page.locator('.rf-row--onu').first();
    const t1 = (await row1.textContent()).replace(/\s+/g, ' ');
    ck('coluna Cliente: primeiro cliente + "+3" na ONU com 4 clientes', t1.includes('Cliente Teste A') && /\+3/.test(t1), t1.slice(0, 120));
    const row2 = (await page.locator('.rf-row--onu').nth(1).textContent()).replace(/\s+/g, ' ');
    ck('ONU com 1 cliente nao mostra "+N"; ONU sem vinculo mostra "sem vínculo"', row2.includes('Cliente Teste E') && !/\+\d/.test(row2) && (await page.locator('.rf-row--onu').nth(2).textContent()).includes('sem vínculo'));
    await shot('01-rede-fibra-coluna-cliente');
    await row1.locator('td').first().click();   // o centro da linha cai num link de cliente
    await page.waitForSelector('#rf-p-body dt');
    const body = (await page.locator('#rf-p-body').textContent()).replace(/\s+/g, ' ');
    ck('ficha da ONU lista os 4 clientes com o modo de cada um', body.includes('Clientes (4)') && ['Cliente Teste A', 'Cliente Teste B', 'Cliente Teste C', 'Cliente Teste D'].every(n => body.includes(n)) && body.includes('PPPoE na ONU') && body.includes('atrás da ONU') && body.includes('modo não verificado'), body.slice(body.indexOf('Clientes'), body.indexOf('Clientes') + 200));
    await shot('02-rede-fibra-ficha-onu');

    // ---- ficha da CTO ----
    const cto = (await api('/api/rede-ctos')).data.ctos.find(c => c.nome === 'CTO-T1');
    ck('lista de CTOs (API): 4 clientes, 1 ONU, 1 porta ocupada', cto.clientes === 4 && cto.onus === 1 && cto.portas.com_cliente === 1 && cto.portas.ocupadas === 1, JSON.stringify([cto.clientes, cto.onus, cto.portas.ocupadas]));
    await page.goto(BASE + '/rede-ctos', {waitUntil: 'networkidle'});
    await page.waitForSelector('#ct-table tbody tr, table tbody tr');
    const ctoRow = (await page.locator('tr', {hasText: 'CTO-T1'}).first().textContent()).replace(/\s+/g, ' ');
    ck('/rede-ctos separa clientes e ONUs ("4 · 1 ONU(s)")', ctoRow.includes('4 · 1 ONU(s)'), ctoRow.slice(0, 160));
    await shot('03-rede-ctos-lista');
    await page.goto(BASE + '/rede-ctos/ficha?id=' + cto.id, {waitUntil: 'networkidle'});
    await page.waitForSelector('#cf-table tbody tr');
    const sum = (await page.locator('#cf-summary').textContent());
    ck('ficha da CTO conta clientes e ONUs separadamente', sum.includes('1 ocupada(s)') && sum.includes('4 cliente(s) em 1 ONU(s)'), sum);
    const p1 = (await page.locator('#cf-table tbody tr').first().textContent()).replace(/\s+/g, ' ');
    ck('a porta lista os 4 clientes e a ONU uma vez', ['Cliente Teste A', 'Cliente Teste B', 'Cliente Teste C', 'Cliente Teste D'].every(n => p1.includes(n)) && (await page.locator('#cf-table tbody tr').first().locator('a[href*="/rede-fibra?onu="]').count()) === 1, p1.slice(0, 200));
    await shot('04-ficha-cto-porta-compartilhada');

    // ---- /rede-vinculos: ONUs vinculadas, modo e remocao ----
    await page.goto(BASE + '/rede-vinculos', {waitUntil: 'networkidle'});
    await page.waitForSelector('#vb-table tbody tr');
    ck('"ONUs já vinculadas": 2 ONUs; a 1 com 4 clientes', (await page.locator('#vb-table tbody tr').count()) === 2 && (await page.locator('#vb-table tbody tr').first().locator('.rc-cand').count()) === 4);
    await shot('05-vinculos-onus-vinculadas');
    const selC = page.locator('#vb-table tbody tr').first().locator('.rc-cand', {hasText: 'cliente_c'}).locator('select');
    await selC.selectOption('atras_da_onu'); await page.waitForTimeout(600);
    let bound = (await api('/api/rede-vinculos/onus')).data.onus;
    ck('mudar o modo grava no banco (cliente_c: atrás da ONU)', bound.find(o => o.onu.id === 1).clientes.find(c => c.login === 'cliente_c').modo_pppoe === 'atras_da_onu');
    await selC.selectOption(''); await page.waitForTimeout(600);
    bound = (await api('/api/rede-vinculos/onus')).data.onus;
    ck('"modo não verificado" volta a NULL', bound.find(o => o.onu.id === 1).clientes.find(c => c.login === 'cliente_c').modo_pppoe === null);
    await page.locator('#vb-table tbody tr').first().locator('.rc-cand', {hasText: 'cliente_d'}).locator('button', {hasText: 'Tirar da ONU'}).click();
    await page.waitForTimeout(900);
    bound = (await api('/api/rede-vinculos/onus')).data.onus;
    const o1 = bound.find(o => o.onu.id === 1);
    ck('tirar 1 cliente: os outros 3 continuam na ONU e na CTO/porta', o1.clientes.length === 3 && o1.cto && o1.cto.nome === 'CTO-T1' && !o1.clientes.some(c => c.login === 'cliente_d'));
    ck('cada linha de adicionar tem busca de cliente', (await page.locator('#vb-table tbody tr').first().locator('.rc-add input').count()) === 1);
    await shot('06-vinculos-apos-alteracoes');
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
