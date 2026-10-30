const boot=require('./harness.js'); const {N,P,L}=boot;
const nodes=[N(1,'pop','POP-001',-20.85,-42.80),N(2,'olt','OLT-001',null,null,1),N(3,'switch','SW-001',null,null,1),N(5,'cto','CTO-001',-20.84,-42.80,null,{capacity:3})];
const ports=[P(10,2,'eth',1,'ETH 1'),P(11,2,'sfp',1,'SFP 1'),P(12,2,'pon',1,'PON 1',1),P(13,2,'pon',2,'PON 2',1),P(14,2,'pon',3,'PON 3',1),
 P(30,3,'eth',1,'ETH 1'),P(31,3,'eth',2,'ETH 2'),
 P(20,5,'fo',1,'Porta 1',1),P(21,5,'fo',2,'Porta 2',1),P(22,5,'fo',3,'Porta 3',1)];
ports.find(p=>p.id===13).status='defeito'; ports.find(p=>p.id===14).status='reservada'; ports.find(p=>p.id===22).status='reservada';
const links=[L(1,'distribuicao',2,5,'OLT→CTO',12,20),L(2,'ethernet',2,3,'OLT→SW',10,30)];
const {d,w,posts,errs,wait,q,qa,ptr}=boot(()=>({nodes,ports,links}));
let fail=0; const ck=(l,c,x='')=>{ if(!c)fail++; console.log(c?'[OK]  ':'[FALHA]',l,x); };
const opts=id=>qa('#'+id+' option').map(o=>o.textContent.trim());
(async()=>{
 await wait(300);
 // visão do site
 ptr(q('.mnode[data-node="n1"]')); await wait(20);
 const olt=q('.site-eq[data-eq="n2"]').textContent.replace(/\s+/g,' ');
 ck('site: portas da OLT compactas (rótulo, mídia, ext/int, destino/livre)', /PON 1 PON·ext → CTO-001/.test(olt)&&/ETH 1 RJ45·int → SW-001/.test(olt)&&/SFP 1 SFP·int livre/.test(olt)&&/PON 2 PON·ext livre/.test(olt), olt);
 ck('site: porta com defeito marcada', !!q('.eq-port.st-defeito[data-port="p13"]'));
 q('.eq-port[data-port="p11"]').click(); await wait(10);
 ck('modal de porta: mídia não editável, campos rótulo/uso/status', /mídia SFP/.test(d.getElementById('modal-el').textContent)&&!!d.getElementById('f-pt-label')&&!!d.getElementById('f-pt-ext')&&!q('#f-pt-media'));
 d.getElementById('f-pt-label').value='UPLINK'; d.getElementById('f-pt-ext').value='1'; d.getElementById('modal-save').click(); await wait(200);
 const pe=posts.find(p=>p[0]==='/portas/editar'); ck('POST /portas/editar sem media', pe&&pe[1].id==='11'&&pe[1].label==='UPLINK'&&pe[1].is_external==='1'&&pe[1].status==='livre'&&!('media' in pe[1]), pe&&JSON.stringify(pe[1]));
 await wait(100); ptr(q('.mnode[data-node="n1"]')); await wait(20); q('.eq-port[data-port="p12"]').click(); await wait(10);
 ck('porta em uso: Excluir desabilitado', d.getElementById('f-pt-del').disabled); d.getElementById('modal-x').click();
 // CTO
 ptr(q('.mnode[data-node="n5"]')); await wait(20);
 const rows=qa('#panel-right .port-row').map(r=>r.textContent.replace(/\s+/g,' ').trim());
 ck('CTO: portas reais (ocupada → destino, livre, reservada)', rows.length===3&&/→ OLT-001/.test(rows[0])&&/livre/.test(rows[1])&&/reservada/.test(rows[2]), JSON.stringify(rows));
 ck('CTO: clientes = portas ocupadas (1)', /Clientes1/.test(d.getElementById('panel-right').textContent.replace(/\s+/g,'')));
 // novo trecho OLT → CTO
 ptr(q('.mnode[data-node="n1"]')); await wait(20); q('[data-eq-link="n2"]').click(); await wait(10); ptr(q('.mnode[data-node="n5"]')); await wait(20);
 ck('distribuição OLT→CTO: só PON livres/sem defeito (+reservada) e SFP externo? não', JSON.stringify(opts('f-port-from'))===JSON.stringify(['sem porta','PON 3 · PON · ext (reservada)']), JSON.stringify(opts('f-port-from')));
 ck('CTO: só portas livres', JSON.stringify(opts('f-port-to'))===JSON.stringify(['sem porta','Porta 2 · Fibra · ext','Porta 3 · Fibra · ext (reservada)']), JSON.stringify(opts('f-port-to')));
 const sel=d.getElementById('f-linktype'); sel.value='ethernet'; sel.dispatchEvent(new w.Event('change'));
 ck('ethernet entre site e CTO: nenhuma porta compatível', opts('f-port-from').length===1&&opts('f-port-to').length===1);
 sel.value='distribuicao'; sel.dispatchEvent(new w.Event('change'));
 d.getElementById('f-port-from').value='p14'; d.getElementById('f-port-to').value='p21'; d.getElementById('modal-save').click(); await wait(200);
 const nl=posts.find(p=>p[0]==='/links'); ck('POST /links com from_port_id=14, to_port_id=21', nl&&nl[1].from_port_id==='14'&&nl[1].to_port_id==='21', nl&&JSON.stringify(nl[1]));
 // ligação interna: portas internas aparecem
 await wait(100); ptr(q('.mnode[data-node="n1"]')); await wait(20); q('[data-eq-link="n3"]').click(); await wait(10); ptr(q('.mnode[data-node="n1"]')); await wait(20); q('[data-eq-pick="n2"]').click(); await wait(20);
 const s2=d.getElementById('f-linktype'); s2.value='ethernet'; s2.dispatchEvent(new w.Event('change'));
 ck('ethernet interna SW→OLT: SW ETH 2 livre; OLT só SFP (ETH 1 ocupada)', JSON.stringify(opts('f-port-from'))===JSON.stringify(['sem porta','ETH 2 · RJ45 · int'])&&JSON.stringify(opts('f-port-to'))===JSON.stringify(['sem porta','SFP 1 · SFP · int']), JSON.stringify([opts('f-port-from'),opts('f-port-to')]));
 d.getElementById('modal-x').click();
 // editar trecho: porta atual pré-selecionada e conta como livre
 const hit=qa('.mlink-hit')[0]; hit.dispatchEvent(new w.MouseEvent('pointerdown',{bubbles:true,clientX:1,clientY:1})); hit.dispatchEvent(new w.MouseEvent('pointerup',{bubbles:true,clientX:1,clientY:1})); await wait(20); q('#btn-ver-trecho').click(); await wait(20);
 q('#btn-edit-link').click(); await wait(20);
 ck('editar: porta atual (PON 1) pré-selecionada', d.getElementById('f-lk-port-from').value==='p12'&&d.getElementById('f-lk-port-to').value==='p20');
 q('#f-lk-invert').click(); await wait(10);
 ck('inverter no modal leva as portas junto', d.getElementById('f-lk-port-from').value==='p20'&&d.getElementById('f-lk-port-to').value==='p12');
 q('#f-lk-invert').click(); await wait(10);
 const sf=d.getElementById('f-lk-from'); sf.value='n1'; sf.dispatchEvent(new w.Event('change')); await wait(10);
 ck('trocar ponta para o POP: sem select de porta', !d.getElementById('f-lk-port-from'));
 sf.value='n2'; sf.dispatchEvent(new w.Event('change')); d.getElementById('f-lk-port-from').value='p12'; d.getElementById('f-lk-port-from').dispatchEvent(new w.Event('change'));
 d.getElementById('modal-save').click(); await wait(200);
 const le=posts.filter(p=>p[0]==='/links/editar').pop(); ck('POST editar com portas (dbId)', le&&le[1].from_port_id==='12'&&le[1].to_port_id==='20'&&le[1].from_node_id==='2', le&&JSON.stringify(le[1]));
 console.log('falhas:',fail,'| erros console:',errs.length?errs.join('\n '):'nenhum'); process.exit(0);
})();
