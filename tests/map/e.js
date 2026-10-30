const boot=require('./harness.js'); const {N,L}=boot;
const nodes=[N(1,'pop','POP-001',-20.85,-42.80),N(5,'cto','CTO-001',-20.84,-42.80),N(6,'cto','CTO-002',-20.85,-42.81)];
const links=[L(1,'distribuicao',1,5,'POP→CTO'),L(2,'drop',1,6,'POP→CTO2',null,null,1500)];
const {d,w,posts,errs,wait,q,qa,ptr}=boot(()=>({nodes,ports:[],links}));
let fail=0; const ck=(l,c,x='')=>{ if(!c)fail++; console.log(c?'[OK]  ':'[FALHA]',l,x); };
const openLink=async i=>{ const h=qa('.mlink-hit')[i]; h.dispatchEvent(new w.MouseEvent('pointerdown',{bubbles:true,clientX:1,clientY:1})); h.dispatchEvent(new w.MouseEvent('pointerup',{bubbles:true,clientX:1,clientY:1})); await wait(20); q('#btn-ver-trecho').click(); await wait(20); };
const len=()=>q('[data-link-len]').textContent;
(async()=>{
 await wait(300);
 await openLink(0); const l0=len(); ck('sem length_m: "≈ X km, linha reta" calculado', /^≈ 1,11 km, linha reta$/.test(l0), l0);
 await openLink(1); ck('com length_m: "1,50 km (informado)"', len()==='1,50 km (informado)', len());
 // arrastar a CTO-001 para ~2,2 km e reabrir o trecho
 const g=q('.mnode[data-node="n5"]'); const m=g.getAttribute('transform').match(/translate\(([-\d.e]+),([-\d.e]+)\)/); const x=+m[1], y=+m[2];
 ptr(g); await wait(20);   // seleciona
 const map=d.getElementById('map'); const g2=q('.mnode[data-node="n5"]'); g2.dispatchEvent(new w.MouseEvent('pointerdown',{bubbles:true,clientX:x,clientY:y}));
 map.dispatchEvent(new w.MouseEvent('pointermove',{bubbles:true,clientX:x,clientY:y-200})); map.dispatchEvent(new w.MouseEvent('pointerup',{bubbles:true,clientX:x,clientY:y-200})); await wait(100);
 const mv=posts.find(p=>p[0]==='/nos/editar'); ck('arraste gravou a posição', !!mv);
 await openLink(0); const l1=len(); ck('distância recalculada após arrastar', l1!==l0&&/^≈ .* km, linha reta$/.test(l1), l0+' → '+l1);
 // novo trecho não envia length_m
 ptr(q('.mnode[data-node="n6"]')); await wait(20); d.getElementById("btn-add-link-from").click(); ptr(q(".mnode[data-node=\"n1\"]")); await wait(20);
 d.getElementById('modal-save').click(); await wait(200);
 const nl=posts.find(p=>p[0]==='/links'); ck('novo trecho sem length_m', nl&&!('length_m' in nl[1]), nl&&JSON.stringify(nl[1]));
 console.log('falhas:',fail,'| erros console:',errs.length?errs.join('\n '):'nenhum'); process.exit(0);
})();
