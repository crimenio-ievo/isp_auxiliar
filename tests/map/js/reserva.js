// Reserva técnica como ponto no mapa (B2): ícone próprio, marcar clicando no cabo, arrastar, metros ao clicar, antigas "sem posição".
const boot = require('../harness.js'); const {N, L} = boot;
const nodes = [N(1,'cto','CTO-A',-20.85,-42.80), N(2,'cto','CTO-B',-20.86,-42.81), N(3,'cto','CTO-C',-20.87,-42.80)];
const links = [
  Object.assign(L(1,'distribuicao',1,2,'A-B'), {path_json:{pontos:[], reservas:[{local:'CTO-A',m:30,pos:[-20.855,-42.805]},{local:'antiga',m:20}], folga_pct:null}}),
  L(2,'distribuicao',2,3,'B-C')
];
const {d, w, posts, errs, wait, q, qa} = boot(()=>({nodes, ports:[], links, defaults:{}}));
let fail = 0; const ck = (l,c,x='')=>{ if(!c) fail++; console.log(c?'[OK]  ':'[FALHA]', l, x); };
const ev = (el,t,x,y,extra)=> el.dispatchEvent(new w.MouseEvent(t,Object.assign({bubbles:true,clientX:x,clientY:y,button:0},extra||{})));
const map = ()=> d.getElementById('map');
const txt = s=> (q(s)||{textContent:''}).textContent.replace(/\s+/g,' ').trim();
const last = p=> [...posts].reverse().find(x=>x[0]===p);
const mid = el=>({x:(+el.getAttribute('x1')+ +el.getAttribute('x2'))/2, y:(+el.getAttribute('y1')+ +el.getAttribute('y2'))/2});
const lineAt = (l, f)=>{ const a=l.getAttribute('x1'), b=l.getAttribute('x2'); return {x:+a+(+b-+a)*f, y:+l.getAttribute('y1')+(+l.getAttribute('y2')-+l.getAttribute('y1'))*f}; };
const offLine = ([lat,lng])=>{ const kx=111320*Math.cos(lat*Math.PI/180), ky=110540, ax=-42.80*kx, ay=-20.85*ky, bx=-42.81*kx, by=-20.86*ky, px=lng*kx, py=lat*ky, dx=bx-ax, dy=by-ay, t=((px-ax)*dx+(py-ay)*dy)/(dx*dx+dy*dy); return Math.hypot(px-(ax+t*dx), py-(ay+t*dy)); };
const openPanel = async (sel)=>{ const h=q(sel); ev(h,'pointerdown',1,1); ev(h,'pointerup',1,1); await wait(20); if(q('#btn-ver-trecho')){ q('#btn-ver-trecho').click(); await wait(20);} };
(async()=>{
  await wait(300);
  const ic = qa('.mreserve');
  ck('só a reserva com posição ganha ícone no mapa', ic.length===1 && /Reserva técnica: CTO-A · 30 m/.test(ic[0].textContent), ic.length+' ícone(s)');
  ck('cabo sem reservas não tem ícone (cabos antigos intactos)', qa('.mreserve[data-link="l2"]').length===0);
  await openPanel('.mlink-hit');
  ck('painel lista a reserva posicionada ("no mapa") e a antiga "sem posição" com o botão Posicionar', /CTO-A · 30 m · no mapa/.test(txt('#panel-right')) && /antiga · 20 m · sem posição/.test(txt('#panel-right')) && qa('[data-place-reserve]').length===1);
  ck('as DUAS reservas somam no total (30 + 20)', /\(traçado \+ folga \+ reservas\)/.test(txt('[data-link-len]')) && /Reservas técnicas\+ 50 m/.test(txt('[data-link-detail]')), txt('[data-link-detail]'));

  // clicar no ícone mostra os metros
  const g = q('.mreserve'); const m0 = g.getAttribute('transform').match(/translate\(([-\d.]+),([-\d.]+)\)/);
  ev(g,'pointerdown',+m0[1],+m0[2]); ev(g,'pointerup',+m0[1],+m0[2]); await wait(20);
  ck('clicar na reserva mostra local e metros e seleciona o cabo', /CTO-A/.test(txt('#toast')) && /30 m/.test(txt('#toast')) && qa('.mreserve.sel').length===1, txt('#toast'));

  // marcar uma nova reserva clicando no cabo
  q('#btn-reserve-link').click(); await wait(10);
  ck('"Marcar reserva" entra no modo (faixa e cursor)', /Clique sobre o cabo/.test(txt('#mode-banner')));
  const hit = q('.mlink-hit'), p25 = lineAt(hit, 0.25);
  ev(hit,'pointerdown',p25.x,p25.y); ev(hit,'pointerup',p25.x,p25.y); await wait(20);
  ck('clique no cabo abre o modal de local e metros', !!q('#f-rsv-m') && !q('#mode-banner.show'));
  q('#f-rsv-local').value='poste 9'; q('#f-rsv-m').value='-3'; q('#modal-save').click(); await wait(10);
  ck('metros inválidos não gravam', !last('/links/editar') && /Informe os metros/.test(txt('#toast')));
  q('#f-rsv-m').value='15,5'.replace(',','.'); q('#modal-save').click(); await wait(40);
  let ed = last('/links/editar'), pp = ed && JSON.parse(ed[1].path);
  ck('grava a nova reserva com pos NO cabo e preserva as outras duas', !!pp && pp.reservas.length===3 && pp.reservas[2].local==='poste 9' && pp.reservas[2].m===15.5 && offLine(pp.reservas[2].pos)<1 && pp.reservas[0].pos[0]===-20.855 && !('pos' in pp.reservas[1]), ed&&ed[1].path);

  // posicionar a antiga
  await wait(40); await openPanel('.mlink-hit');
  q('[data-place-reserve="1"]').click(); await wait(10);
  ck('"Posicionar" entra no modo com o nome da reserva', /antiga/.test(txt('#mode-banner')));
  // clique fora do cabo não faz nada
  const nBefore = posts.length;
  ev(map(),'pointerdown',5,5); ev(map(),'pointerup',5,5); await wait(10);
  ck('clique fora do cabo avisa, não grava e continua no modo', /Clique sobre o cabo/.test(txt('#toast')) && posts.length===nBefore && /Clique sobre o cabo/.test(txt('#mode-banner')));
  const hit2 = q('.mlink-hit'), p75 = lineAt(hit2, 0.75);
  const before = posts.length;
  ev(hit2,'pointerdown',p75.x,p75.y); ev(hit2,'pointerup',p75.x,p75.y); await wait(40);
  ed = last('/links/editar'); pp = JSON.parse(ed[1].path);
  ck('posicionar grava pos na reserva "antiga" (índice 1) e mantém o resto', posts.length===before+1 && pp.reservas[1].local==='antiga' && offLine(pp.reservas[1].pos)<1 && pp.reservas[0].pos[0]===-20.855 && pp.reservas[1].m===20, ed[1].path);

  // arrastar com o cabo selecionado
  await wait(40); await openPanel('.mlink-hit');
  const gi = q('.mreserve'), t0 = gi.getAttribute('transform').match(/translate\(([-\d.]+),([-\d.]+)\)/);
  const hit3 = q('.mlink-hit'), tgt = lineAt(hit3, 0.6);
  const n0 = posts.length;
  ev(gi,'pointerdown',+t0[1],+t0[2]); ev(map(),'pointermove',tgt.x,tgt.y+30); ev(map(),'pointerup',tgt.x,tgt.y+30); await wait(40);
  ed = last('/links/editar'); pp = JSON.parse(ed[1].path);
  ck('arrastar a reserva grava a nova pos ENCAIXADA no cabo (mesmo se o mouse sai da linha)', posts.length===n0+1 && offLine(pp.reservas[0].pos)<1 && Math.abs(pp.reservas[0].pos[0]-(-20.855))>1e-5 && pp.reservas[0].m===30, ed[1].path);

  // arrastar sem o cabo selecionado: só seleciona/mostra
  q('#panel-close-btn').click(); await wait(10);
  const gi2 = q('.mreserve'), t1 = gi2.getAttribute('transform').match(/translate\(([-\d.]+),([-\d.]+)\)/), n1 = posts.length;
  ev(gi2,'pointerdown',+t1[1],+t1[2]); ev(map(),'pointermove',+t1[1]+40,+t1[2]+40); ev(map(),'pointerup',+t1[1]+40,+t1[2]+40); await wait(30);
  ck('sem o cabo selecionado, arrastar não move a reserva (evita acidente)', posts.length===n1);

  // editar trecho preserva a pos
  await openPanel('.mlink-hit'); q('#btn-edit-link').click(); await wait(20);
  const rows = qa('#f-rs-rows .rs-row'); rows[0].querySelector('.rs-m').value='35';
  q('#modal-save').click(); await wait(40);
  ed = last('/links/editar'); pp = JSON.parse(ed[1].path);
  ck('editar os metros no modal mantém a posição da reserva e a antiga continua sem pos', pp.reservas[0].m===35 && Array.isArray(pp.reservas[0].pos) && !('pos' in pp.reservas[1]), ed[1].path);

  // menu de contexto
  const h4 = q('.mlink-hit'), pc = lineAt(h4, 0.4);
  h4.dispatchEvent(new w.MouseEvent('contextmenu',{bubbles:true,cancelable:true,clientX:pc.x,clientY:pc.y})); await wait(10);
  ck('menu do cabo oferece "Marcar reserva técnica aqui"', /Marcar reserva técnica aqui/.test(txt('#ctxmenu')));
  console.log('falhas:', fail, '| erros console:', errs.length?errs.join(' | '):'nenhum');
  process.exit(fail||errs.length?1:0);
})();
