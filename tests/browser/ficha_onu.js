// Roteiro único em navegador real (Chromium headless): ficha da ONU com dados do cliente, "Definir CTO", colunas Login/Bairro e "ver no mapa".
// Schema descartável + "MkAuth" FICTICIO local (com CPF/telefone/e-mail na tabela para provar que não saem) + sessão de harness; tudo removido no fim.
const {spawnSync, spawn} = require('child_process');
const fs = require('fs'), path = require('path');
const root = path.resolve(__dirname, '../..');
const {chromium} = require(process.env.PLAYWRIGHT_PATH || '/tmp/net7-browser/node_modules/playwright');
const PORT = 8095, BASE = 'http://127.0.0.1:' + PORT;
let fail = 0; const ck = (l, c, x = '') => { if (!c) fail++; console.log(c ? '[OK]  ' : '[FALHA]', l, x); };
const envFile = path.join(root, '.env');
if (fs.existsSync(envFile)) { console.error('Ja existe um .env nesta worktree: nao sobrescrevo.'); process.exit(2); }
fs.writeFileSync(envFile, 'DB_HOST=localhost\nDB_DATABASE=isp_auxiliar_browser_sim\nDB_USERNAME=root\nDB_PASSWORD=\nSESSION_PATH=\nSESSION_COOKIE_SECURE=false\nAPP_ENV=local\nNET_COLLECTOR_ENABLED=0\n');
const up = spawnSync('php', ['tests/browser/scratch_up_vinculos.php'], {cwd: root, encoding: 'utf8'});
if (up.status !== 0) { fs.unlinkSync(envFile); console.error(up.stdout, up.stderr); process.exit(2); }
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
    const api = async (p) => page.evaluate(async (u) => (await (await fetch(u, {credentials: 'same-origin'})).json()), p);
    const expand = async () => { for (let i = 0; i < 6 && !(await page.locator('.rf-row--onu').count()); i++) { const tg = page.locator('.rf-toggle[aria-expanded="false"]'); if (!(await tg.count())) { break; } await tg.first().click(); await page.waitForTimeout(150); } await page.waitForSelector('.rf-row--onu'); };

    // ---- /rede-fibra: colunas opcionais ----
    await page.goto(BASE + '/rede-fibra', {waitUntil: 'networkidle'}); await expand();
    ck('Login e Bairro/Localidade vêm desligadas', !(await page.locator('#rf-table thead th', {hasText: 'Login'}).count()) && !(await page.locator('#rf-table thead th', {hasText: 'Bairro'}).count()));
    const opt = async (name) => { const b = page.locator('#rf-cols-list label', {hasText: name}).locator('input'); if (!(await b.isVisible())) { await page.locator('#rf-cols summary, #rf-cols-btn, details summary').first().click().catch(() => {}); } await b.check(); };
    await opt('Login'); await opt('Bairro/Localidade'); await page.waitForTimeout(900);
    const r1 = (await page.locator('.rf-row--onu').first().textContent()).replace(/\s+/g, ' ');
    ck('coluna Login: cliente_a +3; coluna Bairro: Centro +3 (vindo do MkAuth)', /cliente_a\s*\+3/.test(r1) && /Centro\s*\+3/.test(r1), r1.slice(0, 160));

    // ---- ficha da ONU: dados do cliente (MkAuth, sem CPF/telefone) ----
    await page.locator('.rf-row--onu').first().locator('td').first().click();
    await page.waitForSelector('.rf-clientcard');
    const fb = (await page.locator('#rf-p-body').textContent()).replace(/\s+/g, ' ');
    ck('ficha: 4 clientes com endereço curto, plano, situação e modo', (await page.locator('.rf-clientcard').count()) === 4 && fb.includes('Rua das Flores, 10 · Centro · Coimbra') && fb.includes('Plano: Plano 100M') && fb.includes('Situação no MkAuth: Ativo') && fb.includes('Situação no MkAuth: Bloqueado') && fb.includes('Situação no MkAuth: Desativado') && fb.includes('PPPoE na ONU') && fb.includes('atrás da ONU'));
    ck('sem CPF, telefone nem e-mail na tela e nas respostas da API', !/11122233344|31988887777|sigiloso@/.test(fb) && !/11122233344|31988887777|sigiloso@/.test(JSON.stringify(await api('/api/rede-vinculos/onu-ficha?id=1'))) && !/11122233344|31988887777|sigiloso@/.test(JSON.stringify(await api('/api/rede-vinculos/clientes-resumo'))));
    const mapHref = await page.locator('.rf-clientcard a', {hasText: 'ver no mapa'}).first().getAttribute('href');
    ck('"ver no mapa" aponta para /mapa com as coordenadas', /\/mapa\?lat=-20\.8571&lng=-42\.8011$/.test(mapHref), mapHref);
    ck('cliente sem coordenadas: "não informadas"', fb.includes('Coordenadas: não informadas'));

    // ---- Definir CTO a partir da ficha (ONU 2: 1 cliente, ainda sem CTO) ----
    await page.locator('.rf-row--onu').nth(1).locator('td').first().click(); await page.waitForSelector('#rf-define-cto');
    ck('ONU sem CTO: botão "Definir CTO"', (await page.locator('#rf-define-cto').textContent()) === 'Definir CTO');
    await page.click('#rf-define-cto'); await page.waitForSelector('.rc-picker .rc-results li');
    const lis = (await page.locator('.rc-picker .rc-results li').allTextContents()).map(t => t.replace(/\s+/g, ' '));
    ck('mais próximas das coordenadas do cliente: CTO-T2 (perto de cliente_e) antes de CTO-T1, com distância e livres', lis.length === 2 && lis[0].startsWith('CTO-T2') && /\d+ m/.test(lis[0]) && /livre\(s\) de 4/.test(lis[0]), lis[0]);
    await page.fill('.rc-picker input[type=search]', 'T1'); await page.waitForTimeout(700);
    ck('busca por nome', (await page.locator('.rc-picker .rc-results li').count()) === 1 && /CTO-T1/.test(await page.locator('.rc-picker .rc-results li').first().textContent()));
    ck('porta ocupada por outra ONU não é oferecida (Porta 1 da CTO-T1 tem a ONU 1)', !(await page.locator('.rc-picker .rc-results li button', {hasText: /^Porta 1$/}).count()));
    await page.locator('.rc-picker .rc-results li button', {hasText: /^Porta 2$/}).click();
    ck('pede confirmação antes de gravar', /Ligar a ONU para CTO-T1, Porta 2\?/.test(await page.locator('.rc-pick-confirm').textContent()));
    await page.locator('.rc-pick-confirm .rc-btn--ok').click(); await page.waitForTimeout(1200);
    let bound = (await api('/api/rede-vinculos/onus')).data.onus;
    ck('gravou: ONU 2 na CTO-T1, Porta 2', bound.find(o => o.onu.id === 2).cto && bound.find(o => o.onu.id === 2).cto.porta === 'Porta 2');

    // ---- Trocar CTO da ONU 1 (4 clientes) em /rede-vinculos, com o aviso de quantos vão junto ----
    await page.goto(BASE + '/rede-vinculos', {waitUntil: 'networkidle'}); await page.waitForSelector('#vb-table tbody tr');
    const row1 = page.locator('#vb-table tbody tr').first();
    await row1.locator('button', {hasText: 'Trocar CTO'}).click(); await page.waitForSelector('.rc-picker .rc-results li');
    ck('seletor avisa que os 4 clientes vão juntos', /os 4 clientes dela vão juntos/.test(await row1.locator('.rc-picker').textContent()));
    await page.fill('.rc-picker input[type=search]', 'T2'); await page.waitForTimeout(700);
    await row1.locator('.rc-picker .rc-results li button').first().click();
    ck('a confirmação diz "Mover a ONU (4 clientes)" para a nova porta', /Mover a ONU \(4 clientes\) para CTO-T2, Porta \d\?/.test(await row1.locator('.rc-pick-confirm').textContent()));
    await row1.locator('.rc-pick-confirm .rc-btn--ok').click(); await page.waitForTimeout(1300);
    bound = (await api('/api/rede-vinculos/onus')).data.onus;
    const o1 = bound.find(o => o.onu.id === 1);
    ck('as 4 linhas foram para a CTO-T2 juntas', o1.cto.nome === 'CTO-T2' && o1.clientes.length === 4);

    // ---- /mapa?lat=&lng= ----
    await page.goto(BASE + mapHref.replace(/^.*\/mapa/, '/mapa'), {waitUntil: 'networkidle'}); await page.waitForSelector('.mnode');
    ck('/mapa com lat/lng marca o ponto (mira) e abre sem erro', (await page.locator('.search-pin').count()) === 1);
    ck('sem erros de script no console', errors.length === 0, errors.join(' | '));
    await ctx.close();
  } catch (e) {
    fail++; console.error('ERRO NO TESTE:', e && e.stack || e);
  } finally {
    if (browser) await browser.close();
    server.kill();
    const down = spawnSync('php', ['tests/browser/scratch_down.php', sessionDir], {cwd: root, encoding: 'utf8'});
    try { fs.unlinkSync(envFile); } catch (e) { /* ja removido */ }
    console.log(down.stdout.trim(), '| sessão, schemas e .env temporario removidos');
    console.log('falhas:', fail);
    process.exit(fail ? 1 : 0);
  }
})();
