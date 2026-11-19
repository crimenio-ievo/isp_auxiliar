// "Seguir rua" (OSRM via proxy do servidor) no editor de traçado: sucesso vira pontos editáveis; falha cai no modo manual.
const boot = require('../harness.js'); const {N, L} = boot;
const nodes = [N(1,'cto','CTO-A',-20.85,-42.80), N(2,'cto','CTO-B',-20.86,-42.81)];
const links = [L(1,'distribuicao',1,2,'A-B')];
let mode = 'ok';
const {d, w, posts, errs, wait, q, qa} = boot(()=>({nodes, ports:[], links, defaults:{}, routing:{enabled:true}}), p=>{
  if(p[0]!=='/rota') return null;
  if(mode==='ok') return [200,{ok:true,data:{pontos:[[-20.851,-42.801],[-20.853,-42.804],[-20.855,-42.806],[-20.86,-42.81]]}}];
  return [503,{ok:false,message:'O serviço de rotas não respondeu: desenhe os pontos à mão.'}];
});
let fail=0; const ck=(l,c,x='')=>{ if(!c) fail++; console.log(c?'[OK]  ':'[FALHA]', l, x); };
const ev=(el,t,x,y)=>el.dispatchEvent(new w.MouseEvent(t,{bubbles:true,clientX:x,clientY:y,button:0}));
const map=()=>d.getElementById('map'); const click=(x,y)=>{ ev(map(),'pointerdown',x,y); ev(map(),'pointerup',x,y); };
(async()=>{
  await wait(300);
  const h=q('.mlink-hit'); ev(h,'pointerdown',1,1); ev(h,'pointerup',1,1); await wait(20);
  q('#btn-ver-trecho').click(); await wait(20); q('#btn-trace-link').click(); await wait(20);
  ck('"Seguir rua" começa desligado', /desligado/.test(q('#tr-snap').textContent));
  click(100,100); ck('desligado: clique cria 1 ponto sem chamar o serviço', qa('.mvertex').length===1 && !posts.some(p=>p[0]==='/rota'));
  q('#tr-snap').click(); ck('liga o modo', /ligado/.test(q('#tr-snap').textContent));
  click(200,200); await wait(30);
  const r=posts.find(p=>p[0]==='/rota');
  ck('clique chama /rota com origem = último ponto e destino = clique', !!r && Math.abs(+r[1].from_lat)<90 && r[1].to_lat!==undefined && r[1].from_lat!==r[1].to_lat);
  ck('resultado vira 4 pontos editáveis (1 + 4)', qa('.mvertex').length===5, 'vértices='+qa('.mvertex').length);
  ck('mensagem informa os pontos', /seguindo a rua/.test(q('#tr-msg').textContent));
  q('#tr-route-end').click(); await wait(30);
  ck('"até o destino" acrescenta pontos sem duplicar o nó de destino', qa('.mvertex').length===8, 'vértices='+qa('.mvertex').length);
  mode='fail'; const n0=qa('.mvertex').length; click(300,300); await wait(30);
  ck('serviço falhou: ponto simples é adicionado (modo manual) e avisa', qa('.mvertex').length===n0+1 && /desenhe os pontos à mão|linha reta/.test((q('#toast').textContent+q('#tr-msg').textContent)), q('#toast').textContent);
  ck('a tela continua utilizável após a falha', !!q('#tr-save') && !q('#tr-save').disabled);
  console.log('falhas:', fail, '| erros console:', errs.length?errs.join(' | '):'nenhum'); process.exit(fail||errs.length?1:0);
})();
