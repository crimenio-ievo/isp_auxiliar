// Importação de cabos KML/KMZ na tela: prévia lista o que liga a qual CTO; só importa o marcado; erros viram aviso.
const boot = require('../harness.js'); const {N} = boot;
const nodes = [N(1,'cto','CTO-A',-20.85,-42.80), N(2,'cto','CTO-B',-20.86,-42.81)];
const previa = {previa:true, raio_m:25, resumo:{ok:1, sem_pontas:1}, ignorados_sem_linha:1, linhas:[
  {indice:0, nome:'A-B <img src=x onerror=window.__xss=1>', n_pontos:5, comprimento_m:1500.5, status:'ok', mensagem:'CTO-A → CTO-B', origem:{id:1,nome:'CTO-A',dist_m:2.5}, destino:{id:2,nome:'CTO-B',dist_m:1.1}, n_pontos_meio:3},
  {indice:1, nome:'longe', n_pontos:2, comprimento_m:100, status:'sem_pontas', mensagem:'Nenhuma ponta chega a um elemento dentro de 25 m (início: mais próximo: CTO-A a 90000 m).', origem:null, destino:null, n_pontos_meio:0}]};
let failNext = false;
const {d, w, posts, errs, wait, q, qa} = boot(()=>({nodes, ports:[], links:[], defaults:{}}), p=>{
  if(p[0]!=='/importar-cabos') return null;
  if(failNext) return [422,{ok:false,message:'Nenhuma linha (LineString) encontrada: pontos e polígonos não viram cabo.'}];
  return p[1].confirmar==='1' ? [200,{ok:true,message:'1 cabo(s) importado(s).',data:{criados:[7]}}] : [200,{ok:true,data:previa}];
});
let fail=0; const ck=(l,c,x='')=>{ if(!c) fail++; console.log(c?'[OK]  ':'[FALHA]', l, x); };
const pickFile = (name, content)=>{ const f = new w.File([content], name); Object.defineProperty(q('#f-imp-file'), 'files', {value:[f], configurable:true}); };
(async()=>{
  await wait(300);
  ck('menu Adicionar tem "Importar cabos (KML/KMZ)"', !!q('#add-import-cables'));
  q('#add-import-cables').click(); await wait(20);
  ck('abre o modal de importação', !!q('#f-imp-file') && q('#f-imp-raio').value==='25' && q('#f-imp-go').disabled);
  q('#f-imp-read').click(); ck('sem arquivo: avisa e não posta', !posts.length && /Escolha um arquivo/.test(q('#toast').textContent));
  pickFile('rede.kml','<kml/>'); q('#f-imp-raio').value='40'; q('#f-imp-tipo').value='drop'; q('#f-imp-fo').value='12';
  q('#f-imp-read').click(); await wait(80);
  const p0 = posts[0];
  ck('prévia envia arquivo em base64, raio, tipo, situação e fibras; sem confirmar', !!p0 && p0[0]==='/importar-cabos' && p0[1].kml_b64===Buffer.from('<kml/>').toString('base64') && p0[1].raio_m==='40' && p0[1].tipo==='drop' && p0[1].fibras==='12' && !p0[1].confirmar, JSON.stringify(p0&&Object.keys(p0[1])));
  ck('prévia lista origem → destino e a distância das pontas', /CTO-A → CTO-B/.test(q('#f-imp-result').textContent) && /2\.5 m \/ 1\.1 m/.test(q('#f-imp-result').textContent));
  ck('linha sem elemento fica desabilitada com o motivo', q('input.imp-chk[data-idx="1"]').disabled && /mais próximo: CTO-A/.test(q('#f-imp-result').textContent));
  ck('só a linha pronta vem marcada; botão mostra a contagem', q('input.imp-chk[data-idx="0"]').checked && q('#f-imp-go').textContent==='Importar selecionados (1)' && !q('#f-imp-go').disabled);
  ck('nome do KML é escapado (sem XSS)', !w.__xss && !q('#f-imp-result img'));
  q('input.imp-chk[data-idx="0"]').checked=false; q('input.imp-chk[data-idx="0"]').dispatchEvent(new w.Event('change',{bubbles:true}));
  ck('desmarcar tudo desabilita o botão', q('#f-imp-go').disabled);
  q('input.imp-chk[data-idx="0"]').checked=true; q('input.imp-chk[data-idx="0"]').dispatchEvent(new w.Event('change',{bubbles:true}));
  q('#f-imp-go').click(); await wait(80);
  const p1 = posts[posts.length-1];
  ck('importar envia confirmar=1 e só as linhas marcadas', p1[1].confirmar==='1' && p1[1].selecionados==='0', JSON.stringify(p1[1].selecionados));
  ck('fecha o modal e recarrega o mapa', !q('#f-imp-file'));
  // erro do servidor vira aviso e a tela continua utilizável
  q('#add-import-cables').click(); await wait(20); failNext = true; pickFile('x.kml','<kml/>'); q('#f-imp-read').click(); await wait(80);
  ck('erro na leitura: aviso com o motivo e botão desabilitado', /LineString/.test(q('#toast').textContent) && q('#f-imp-go').disabled);
  console.log('falhas:', fail, '| erros console:', errs.length?errs.join(' | '):'nenhum'); process.exit(fail||errs.length?1:0);
})();
