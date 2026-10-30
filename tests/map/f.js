const boot=require('./harness.js'); const {N,P}=boot;
const nodes=[N(1,'pop','POP-001',-20.85,-42.80),N(2,'olt','OLT-001',null,null,1,{tx_padrao_dbm:3.5,max_clientes_padrao:128}),N(3,'olt','OLT-002',null,null,1)];
const pon=(id,node,n,a)=>Object.assign(P(id,node,'pon',n,'PON '+n,1),{attrs_json:a||null});
const ports=[pon(10,2,1,{tx_dbm:6,max_clientes:64}),pon(11,2,2),pon(12,2,3,{tx_dbm:-1.25}),pon(20,3,1),P(21,3,'eth',1,'ETH 1')];
const {d,w,posts,errs,wait,q,qa,ptr}=boot(()=>({nodes,ports,links:[],defaults:{'map.loss.fusion_db':0.1}}));
let fail=0; const ck=(l,c,x='')=>{ if(!c)fail++; console.log(c?'[OK]  ':'[FALHA]',l,x); };
const txt=s=>q(s).textContent.replace(/\s+/g,' ').trim();
(async()=>{
 await wait(300);
 ck('GET com defaults carrega sem erro', errs.length===0);
 ptr(q('.mnode[data-node="n1"]')); await wait(20);
 ck('resumo da OLT-001', txt('[data-olt-sum="n2"]')==='PONs: 3 · Tx padrão: +3,5 dBm · máx/porta: 128', txt('[data-olt-sum="n2"]'));
 ck('resumo da OLT-002 sem padrão', txt('[data-olt-sum="n3"]')==='PONs: 1 · Tx padrão: — · máx/porta: —', txt('[data-olt-sum="n3"]'));
 ck('PON 1: Tx e máx próprios (da porta / porta)', /Tx \+6 dBm da porta · clientes — \/ 64 da porta/.test(txt('[data-pon="p10"]')), txt('[data-pon="p10"]'));
 ck('PON 2: tudo herdado da OLT', /Tx \+3,5 dBm padrão da OLT · clientes — \/ 128 padrão da OLT/.test(txt('[data-pon="p11"]')), txt('[data-pon="p11"]'));
 ck('PON 3: Tx próprio negativo, máx herdado', /Tx -1,25 dBm da porta · clientes — \/ 128 padrão da OLT/.test(txt('[data-pon="p12"]')), txt('[data-pon="p12"]'));
 ck('PON sem nada: "Tx não informado"', /Tx não informado/.test(txt('[data-pon="p20"]'))&&/máx não informado/.test(txt('[data-pon="p20"]')), txt('[data-pon="p20"]'));
 ck('ETH não ganha linha de potência', !q('[data-pon="p21"]'));
 // modal PON 2 (herdado)
 q('.eq-port[data-port="p11"]').click(); await wait(10);
 const tx=d.getElementById('f-pt-tx'), mx=d.getElementById('f-pt-max');
 ck('modal PON: campos vazios com placeholder do padrão e aviso de herança', tx&&mx&&tx.value===''&&/padrão da OLT: \+3,5 dBm/.test(tx.placeholder)&&/herda o padrão da OLT/.test(d.getElementById('modal-el').textContent), tx&&tx.placeholder);
 tx.value='4,5'; d.getElementById('modal-save').click(); await wait(200);
 const pe=posts.find(p=>p[0]==='/portas/editar'); ck('POST envia tx_dbm "4,5" e max_clientes vazio', pe&&pe[1].tx_dbm==='4,5'&&pe[1].max_clientes==='', pe&&JSON.stringify(pe[1]));
 // modal PON 1 (próprio)
 await wait(100); ptr(q('.mnode[data-node="n1"]')); await wait(20); q('.eq-port[data-port="p10"]').click(); await wait(10);
 ck('modal PON própria: valores preenchidos e "Valor desta porta"', d.getElementById('f-pt-tx').value==='6'&&d.getElementById('f-pt-max').value==='64'&&/Valor desta porta/.test(d.getElementById('modal-el').textContent));
 d.getElementById('modal-x').click();
 // modal ETH sem campos
 ptr(q('.mnode[data-node="n1"]')); await wait(20); q('.eq-port[data-port="p21"]').click(); await wait(10);
 ck('modal de ETH sem campos de potência', !d.getElementById('f-pt-tx')); d.getElementById('modal-x').click();
 // editar OLT
 ptr(q('.mnode[data-node="n1"]')); await wait(20); q('[data-eq-edit="n2"]').click(); await wait(10);
 ck('modal da OLT: padrões preenchidos', d.getElementById('f-olt-tx').value==='3,5'&&d.getElementById('f-olt-max').value==='128');
 d.getElementById('f-olt-tx').value=''; d.getElementById('f-olt-max').value='96'; d.getElementById('modal-save').click(); await wait(200);
 const ne=posts.find(p=>p[0]==='/nos/editar'); const at=ne&&JSON.parse(ne[1].attrs);
 ck('POST OLT: tx vazio (apagar) e máx 96', at&&at.tx_padrao_dbm===''&&at.max_clientes_padrao==='96', ne&&ne[1].attrs);
 // switch não tem campos
 await wait(100); ptr(q('.mnode[data-node="n1"]')); await wait(20); q('[data-eq-edit="n3"]').click(); await wait(10);
 ck('OLT-002 editar: campos vazios', d.getElementById('f-olt-tx').value===''); d.getElementById('modal-x').click();
 // criar OLT nova
 ptr(q('.mnode[data-node="n1"]')); await wait(20); d.getElementById('site-add-type').value='olt'; d.getElementById('btn-site-add').click(); await wait(10);
 d.getElementById('f-olt-tx').value='5'; d.getElementById('modal-save').click(); await wait(200);
 const nc=posts.find(p=>p[0]==='/nos'); const ac=nc&&JSON.parse(nc[1].attrs); ck('criar OLT: só envia o que preencheu', ac&&ac.tx_padrao_dbm==='5'&&!('max_clientes_padrao' in ac), nc&&nc[1].attrs);
 d.getElementById('modal-x')&&0;
 ptr(q('.mnode[data-node="n1"]')); await wait(20); d.getElementById('site-add-type').value='switch'; d.getElementById('btn-site-add').click(); await wait(10);
 ck('criar switch: sem campos de padrão OLT', !d.getElementById('f-olt-tx'));
 console.log('falhas:',fail,'| erros console:',errs.length?errs.join('\n '):'nenhum'); process.exit(0);
})();
