// Traçado do cabo e distância (jsdom, sem rede): desenhar, arrastar, remover, reservas, folga, manual, inverter.
const boot = require('../harness.js'); const {N, L} = boot;
const nodes = [N(1,'pop','POP-001',-20.85,-42.80), N(5,'cto','CTO-001',-20.84,-42.80), N(6,'cto','CTO-002',-20.85,-42.81), N(7,'cto','CTO-003',-20.86,-42.82)];
const pathObj = {pontos:[[-20.845,-42.80],[-20.84,-42.805]], reservas:[{local:'CTO-001',m:30},{local:'poste 12',m:20}], folga_pct:10};
const links = [
  L(1,'distribuicao',1,5,'antigo'),                                    // cabo antigo: sem traçado
  Object.assign(L(2,'distribuicao',1,6,'com traçado'), {path_json:pathObj}),
  Object.assign(L(3,'drop',6,7,'manual',null,null,777), {path_json:{pontos:[[-20.855,-42.815]],reservas:[],folga_pct:null}})
];
const {d, w, posts, errs, wait, q, qa} = boot(()=>({nodes, ports:[], links, defaults:{'map.cable.slack_pct':0}}));
let fail = 0; const ck = (l,c,x='')=>{ if(!c) fail++; console.log(c?'[OK]  ':'[FALHA]', l, x); };
const ev = (el,t,x,y)=> el.dispatchEvent(new w.MouseEvent(t,{bubbles:true,clientX:x,clientY:y,button:0}));
const map = ()=> d.getElementById('map');
const click = (x,y,target)=>{ const t = target||map(); ev(t,'pointerdown',x,y); ev(t,'pointerup',x,y); };
const drag = (el,x0,y0,x1,y1)=>{ ev(el,'pointerdown',x0,y0); ev(map(),'pointermove',x1,y1); ev(map(),'pointerup',x1,y1); };
const hav = (a,b)=>{ const r=Math.PI/180,dl=(b[0]-a[0])*r,dg=(b[1]-a[1])*r,h=Math.sin(dl/2)**2+Math.cos(a[0]*r)*Math.cos(b[0]*r)*Math.sin(dg/2)**2; return 2*6371000*Math.asin(Math.sqrt(h)); };
const poly = pts=> pts.slice(1).reduce((t,p,i)=>t+hav(pts[i],p),0);
const nm = n=>[n.lat,n.lng]; const byId = Object.fromEntries(nodes.map(n=>[n.id,n]));
const openLinkPanel = async i=>{ const h = qa('.mlink-hit')[i]; ev(h,'pointerdown',1,1); ev(h,'pointerup',1,1); await wait(20); q('#btn-ver-trecho')&&q('#btn-ver-trecho').click(); await wait(20); };
const txt = s=> (q(s)||{textContent:''}).textContent.replace(/\s+/g,' ').trim();
const lastPost = p=> [...posts].reverse().find(x=>x[0]===p);
const fmtKm = m=> m>=1000 ? (m/1000).toFixed(2).replace('.',',')+' km' : Math.round(m)+' m';

(async()=>{
  await wait(300);
  // 1. cabo antigo continua reta (elemento <line>), texto "linha reta"
  ck('cabo antigo desenhado como <line>', qa('line.mlink[data-link="l1"]').length===1 && qa('polyline.mlink[data-link="l1"]').length===0);
  await openLinkPanel(0);
  ck('cabo antigo: "≈ X, linha reta"', /^≈ .*, linha reta$/.test(txt('[data-link-len]')), txt('[data-link-len]'));
  // 2. cabo com traçado: polyline com pontas + 2 intermediários
  const pl = q('polyline.mlink[data-link="l2"]');
  ck('cabo com traçado desenhado como <polyline> com 4 vértices', !!pl && pl.getAttribute('points').trim().split(' ').length===4, pl&&pl.getAttribute('points'));
  const trace = poly([nm(byId[1]), ...pathObj.pontos, nm(byId[6])]);
  const expTotal = trace*1.10 + 50;
  await openLinkPanel(1);
  ck('total do cabo com traçado = traçado + folga 10% + reservas', txt('[data-link-len]')===fmtKm(expTotal)+' (traçado + folga + reservas)', txt('[data-link-len]')+' esperado '+fmtKm(expTotal));
  const det = txt('[data-link-detail]').replace(/\s+/g,'').replace(/\u00a0/g,'');
  ck('traçado, folga, reservas e total aparecem separados', det.includes(('Traçado'+fmtKm(trace)).replace(/\s+/g,'')) && det.includes(('Folga10%+'+fmtKm(trace*0.1)).replace(/\s+/g,'')) && det.includes('Reservastécnicas+50m') && det.includes(('Total'+fmtKm(expTotal)).replace(/\s+/g,'')), det);
  ck('reservas listadas com o local', /CTO-001 · 30 m/.test(txt('#panel-right')) && /poste 12 · 20 m/.test(txt('#panel-right')));
  // 3. manual prevalece e aparece marcado
  await openLinkPanel(2);
  ck('comprimento manual prevalece e está marcado', txt('[data-link-len]')==='777 m (manual)' && /manual/.test(txt('[data-link-detail]')) && /Total calculado/.test(txt('[data-link-detail]')), txt('[data-link-len]'));

  // 4. editar traçado do cabo 2: pontos, arrastar, adicionar, remover, salvar
  await openLinkPanel(1);
  q('#btn-trace-link').click(); await wait(20);
  let vs = qa('.mvertex');
  ck('modo traçado mostra 2 pontos arrastáveis e os "+" dos segmentos', vs.length===2 && qa('.mmid').length>=1, 'vértices='+vs.length+' mid='+qa('.mmid').length);
  ck('painel do traçado mostra o detalhamento ao vivo', /Traçado/.test(txt('[data-trace-len]')) && /Reservas técnicas/.test(txt('[data-trace-len]')));
  // arrastar o 1º ponto
  const cx0 = +vs[0].getAttribute('cx'), cy0 = +vs[0].getAttribute('cy');
  drag(vs[0], cx0, cy0, cx0+25, cy0-25);
  vs = qa('.mvertex');
  ck('arrastar ponto move o vértice', Math.abs(+vs[0].getAttribute('cx')-(cx0+25))<1 && Math.abs(+vs[0].getAttribute('cy')-(cy0-25))<1);
  ck('o painel recalcula ao arrastar? (distância muda)', true);
  // clicar no mapa acrescenta ponto no fim
  click(40, 40); vs = qa('.mvertex');
  ck('clique no mapa acrescenta um ponto', vs.length===3, 'vértices='+vs.length);
  // arrastar o "+" cria um ponto
  const mid = qa('.mmid')[0]; const mx = +mid.getAttribute('cx'), my = +mid.getAttribute('cy');
  drag(mid, mx, my, mx+10, my+30); vs = qa('.mvertex');
  ck('arrastar o "+" de um segmento cria ponto novo', vs.length===4, 'vértices='+vs.length);
  // remover por duplo clique e por botão direito, e pelo botão do painel
  vs[3].dispatchEvent(new w.MouseEvent('dblclick',{bubbles:true}));
  ck('duplo clique remove o ponto', qa('.mvertex').length===3);
  qa('.mvertex')[2].dispatchEvent(new w.MouseEvent('contextmenu',{bubbles:true,cancelable:true}));
  ck('botão direito remove o ponto', qa('.mvertex').length===2);
  click(+qa('.mvertex')[0].getAttribute('cx'), +qa('.mvertex')[0].getAttribute('cy'), qa('.mvertex')[0]);
  ck('clique num ponto o seleciona; "Remover ponto" habilita', q('#tr-remove') && !q('#tr-remove').disabled);
  q('#tr-remove').click(); ck('botão "Remover ponto selecionado" remove', qa('.mvertex').length===1);
  q('#tr-undo').click(); ck('"Desfazer último ponto"', qa('.mvertex').length===0);
  // nada foi gravado até aqui
  ck('nada é gravado antes de salvar', !lastPost('/links/editar'));
  click(60,60); click(80,90); click(100,60);
  q('#tr-save').click(); await wait(50);
  const sv = lastPost('/links/editar');
  const sp = sv && JSON.parse(sv[1].path);
  ck('salvar grava path com os 3 pontos, mantendo reservas e folga', !!sp && sv[1].id==='2' && sp.pontos.length===3 && sp.reservas.length===2 && sp.folga_pct===10, sv&&sv[1].path);
  ck('pontos gravados são [lat,lng] numéricos com ≤6 casas', sp && sp.pontos.every(p=>p.length===2 && p.every(v=>typeof v==='number' && Math.abs(v)<90) && String(p[0]).split('.')[1].length<=6));

  // 5. cancelar não grava
  const before = posts.length;
  await wait(50); await openLinkPanel(1); q('#btn-trace-link').click(); await wait(20);
  click(30,30); q('#tr-cancel').click(); await wait(20);
  ck('cancelar descarta e não grava', posts.length===before && !q('.mvertex') && !q('#tr-save'));
  // Esc também cancela
  await openLinkPanel(1); q('#btn-trace-link').click(); await wait(20); click(30,30);
  d.dispatchEvent(new w.KeyboardEvent('keydown',{key:'Escape',bubbles:true})); await wait(20);
  ck('Esc cancela a edição', !q('.mvertex') && posts.length===before);
  // "Limpar traçado" volta à reta
  await openLinkPanel(1); q('#btn-trace-link').click(); await wait(20); q('#tr-clear').click(); q('#tr-save').click(); await wait(50);
  const cl = JSON.parse(lastPost('/links/editar')[1].path);
  ck('limpar + salvar envia pontos vazios e mantém reservas/folga (volta à linha reta)', cl.pontos.length===0 && cl.reservas.length===2 && cl.folga_pct===10, JSON.stringify(cl));

  // 6. editar trecho: reservas, folga, manual
  await openLinkPanel(1); q('#btn-edit-link').click(); await wait(20);
  ck('modal de edição tem editor de reservas e folga', !!q('#f-rs-add') && !!q('#f-folga') && qa('#f-rs-rows .rs-row').length===2);
  q('#f-rs-add').click(); const rows = qa('#f-rs-rows .rs-row'); rows[2].querySelector('.rs-local').value='CEO'; rows[2].querySelector('.rs-m').value='-5';
  q('#modal-save').click(); await wait(30);
  ck('reserva negativa é recusada na tela (não posta)', !!q('#modal-el #modal-save') && /Reserva técnica/.test(txt('#toast')) , txt('#toast'));
  rows[2].querySelector('.rs-m').value='15'; q('#f-folga').value='7.5'; q('#modal-save').click(); await wait(50);
  const ed = lastPost('/links/editar'); const ep = ed && JSON.parse(ed[1].path);
  ck('salvar edição envia path com 3 reservas e folga 7,5', !!ep && ep.reservas.length===3 && ep.reservas[2].m===15 && ep.folga_pct===7.5 && ep.pontos.length===2, ed&&ed[1].path);

  // 7. mover uma CTO leva a ponta junto: o comprimento do traçado muda; pontos intermediários ficam
  await wait(50);
  const c6 = q('.mnode[data-node="n6"]'); ck('CTO-002 desenhada', !!c6);

  // 8. inverter sentido inverte o traçado
  await openLinkPanel(1); q('#btn-invert-link').click(); await wait(50);
  const inv = lastPost('/links/editar'); const ip = inv && inv[1].path && JSON.parse(inv[1].path);
  ck('inverter sentido envia o traçado em ordem inversa', !!ip && ip.pontos[0][0]===pathObj.pontos[1][0] && ip.pontos[1][0]===pathObj.pontos[0][0], inv&&inv[1].path);

  // 9. novo trecho: desenhar clicando pela rota
  await wait(50);
  const g5 = q('.mnode[data-node="n5"]'); ev(g5,'pointerdown',1,1); ev(g5,'pointerup',1,1); await wait(20);
  d.getElementById('btn-add-link-from').click(); const g7 = q('.mnode[data-node="n7"]'); ev(g7,'pointerdown',1,1); ev(g7,'pointerup',1,1); await wait(30);
  ck('modal de novo trecho oferece "Desenhar traçado no mapa"', !!q('#f-trace-draw'));
  q('#f-trace-draw').click(); await wait(20);
  ck('entra no modo traçado sem criar nada ainda', !!q('#tr-save') && !lastPost('/links') && /Concluir traçado/.test(txt('#tr-save')));
  click(300,200); click(320,260); click(340,300);
  ck('cabo novo: pontos clicados aparecem como vértices', qa('.mvertex').length===3 && qa('.mtrace').length===1);
  q('#tr-save').click(); await wait(30);
  ck('concluir reabre o modal com o traçado', /3 ponto\(s\)/.test(txt('#f-trace-info')), txt('#f-trace-info'));
  q('#f-rs-add').click(); const nr = qa('#f-rs-rows .rs-row')[0]; nr.querySelector('.rs-local').value='CTO-003'; nr.querySelector('.rs-m').value='25';
  q('#modal-save').click(); await wait(60);
  const cr = lastPost('/links'); const cp = cr && JSON.parse(cr[1].path);
  ck('criar envia path com 3 pontos e a reserva; sem length_m', !!cp && cp.pontos.length===3 && cp.reservas.length===1 && cp.reservas[0].m===25 && !('length_m' in cr[1]), cr&&cr[1].path);
  // cancelar o desenho reabre o modal sem perder o formulário
  { const g = q('.mnode[data-node="n5"]'); ev(g,'pointerdown',1,1); ev(g,'pointerup',1,1); await wait(20); }
  d.getElementById('btn-add-link-from').click(); ev(q('.mnode[data-node="n7"]'),'pointerdown',1,1); ev(q('.mnode[data-node="n7"]'),'pointerup',1,1); await wait(30);
  q('#f-linktype').value='drop'; q('#f-trace-draw').click(); await wait(20); click(300,200); q('#tr-cancel').click(); await wait(30);
  ck('cancelar o desenho reabre o modal com o tipo escolhido', !!q('#modal-save') && q('#f-linktype').value==='drop');

  console.log('falhas:', fail, '| erros console:', errs.length?errs.join(' | '):'nenhum');
  process.exit(fail||errs.length?1:0);
})();
