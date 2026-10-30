const {JSDOM, VirtualConsole} = require('jsdom'); const fs=require('fs');
const html=fs.readFileSync(process.argv[2],'utf8'); const errs=[]; const vc=new VirtualConsole();
vc.on('jsdomError',e=>errs.push('jsdomError: '+(e.detail&&e.detail.message||e.message))); vc.on('error',e=>errs.push('console.error: '+e));
const N=(id,t,name,lat,lng,parent,attrs)=>({id,node_type:t,name,code:null,lat,lng,parent_node_id:parent||null,status:'normal',lifecycle:'ativo',attrs_json:attrs||null,equipment_id:null,notes:null});
const nodes=[N(1,'pop','POP-001',-20.85,-42.80),N(2,'olt','OLT-001',null,null,1,{fabricante:'Huawei',modelo:'MA5800'}),N(3,'switch','SW-001',null,null,1),N(4,'router','RT-001',null,null,1),
 N(5,'ceo','CEO-001',-20.86,-42.81),N(6,'tower','TORRE-001',-20.84,-42.79,1),N(7,'radio','AP-001',null,null,6),N(8,'pop','POP-002',-20.87,-42.82)];
const L=(id,t,a,b,label)=>({id,link_type:t,medium:'x',from_node_id:a,to_node_id:b,from_port_id:null,to_port_id:null,status:'normal',lifecycle:'ativo',label,length_m:null,fiber_count:null,attrs_json:null,path_json:null});
const links=[L(1,'distribuicao',2,5,'OLT→CEO'),L(2,'ethernet',2,3,'OLT→SW interna'),L(3,'ethernet',1,4,'POP→RT interna'),L(4,'wireless',7,8,'AP→POP2')];
const posts=[]; const json=(st,b)=>Promise.resolve({redirected:false,status:st,ok:st<400,headers:{get:()=>'application/json'},json:()=>Promise.resolve(b)});
const dom=new JSDOM(html,{runScripts:'dangerously',pretendToBeVisual:true,virtualConsole:vc,url:'http://localhost/mapa',beforeParse(w){
 w.confirm=()=>true;
 w.fetch=(u,o={})=>{ if(o.method==='POST'){ posts.push([u.replace('/api/mapa',''),Object.fromEntries(o.body.entries())]); return json(200,{ok:true,data:{node:{id:99},link:{id:99}}}); } return json(200,{ok:true,data:{nodes,ports:[],links}}); };
 w.HTMLCanvasElement.prototype.getContext=()=>null; }});
const w=dom.window,d=w.document; const wait=ms=>new Promise(r=>setTimeout(r,ms)); const q=s=>d.querySelector(s), qa=s=>[...d.querySelectorAll(s)];
const ptr=el=>{ el.dispatchEvent(new w.MouseEvent('pointerdown',{bubbles:true,clientX:1,clientY:1})); el.dispatchEvent(new w.MouseEvent('pointerup',{bubbles:true,clientX:1,clientY:1})); };
let fail=0; const ck=(l,c,x='')=>{ if(!c)fail++; console.log(c?'[OK]  ':'[FALHA]',l,x); };
(async()=>{
 await wait(300);
 const drawn=qa('.mnode').map(g=>g.getAttribute('data-node')).sort();
 ck('só contêineres e campo desenhados', JSON.stringify(drawn)===JSON.stringify(['n1','n5','n6','n8']), drawn.join(','));
 const badge=id=>{ const b=q(`.mnode[data-node="${id}"] .msite-badge text`); return b&&b.textContent; };
 ck('selo POP-001 = 3 equipamentos', badge('n1')==='3', badge('n1')); ck('selo TORRE-001 = 1', badge('n6')==='1'); ck('POP-002 sem selo', !badge('n8')); ck('CEO sem selo', !badge('n5'));
 // ligações: só externas desenhadas
 const lines=qa('.mlink'); ck('desenhadas só as 2 externas (OLT→CEO e AP→POP2)', lines.length===2, 'qtd='+lines.length);
 // OLT→CEO sai do ponto do POP
 const pop=q('.mnode[data-node="n1"]').getAttribute('transform').match(/translate\(([-\d.e]+),([-\d.e]+)\)/);
 const ceo=q('.mnode[data-node="n5"]').getAttribute('transform').match(/translate\(([-\d.e]+),([-\d.e]+)\)/);
 const lk=q('.mlink[data-link="l1"]'); const near=(a,b)=>Math.abs(+a-+b)<0.01;
 ck('ligação OLT→CEO sai do POP e chega na CEO', lk&&near(lk.getAttribute('x1'),pop[1])&&near(lk.getAttribute('y1'),pop[2])&&near(lk.getAttribute('x2'),ceo[1])&&near(lk.getAttribute('y2'),ceo[2]));
 ck('ligação interna OLT→SW não desenhada', !q('.mlink[data-link="l2"]')); ck('ligação interna POP→RT não desenhada', !q('.mlink[data-link="l3"]'));
 ck('KPI links conta todas as ativas (4)', d.getElementById('kpi-links').textContent==='4');
 // menu Adicionar
 ck('menu Adicionar só POP/Torre/CEO/CTO', qa('.add-menu-item').map(e=>e.getAttribute('data-type')).join(',')==='pop,tower,ceo,cto');
 // painel do site
 ptr(q('.mnode[data-node="n1"]')); await wait(20);
 const pan=d.getElementById('panel-right');
 ck('painel do site agrupa por tipo', qa('#panel-right .site-group-title').map(e=>e.textContent).join('|')==='OLT (1)|Switch (1)|Roteador (1)', qa('#panel-right .site-group-title').map(e=>e.textContent).join('|'));
 const oltRow=q('.site-eq[data-eq="n2"]').textContent;
 ck('equipamento mostra fabricante/modelo, situação e status', /Huawei · MA5800/.test(oltRow)&&/Situação: Ativo/.test(oltRow)&&/Online/.test(oltRow));
 ck('OLT lista ligação externa (CEO) e interna (SW)', /externa · Distribuição \(fibra\) → CEO-001/.test(oltRow)&&/interna · Ethernet → SW-001/.test(oltRow));
 ck('ações Editar/Conectar/Excluir por equipamento', !!q('[data-eq-edit="n2"]')&&!!q('[data-eq-link="n2"]')&&!!q('[data-eq-del="n2"]'));
 ck('torre listada no POP', /TORRE-001/.test(pan.textContent));
 const opts=qa('#site-add-type option').map(o=>o.value).join(',');
 ck('adicionar dentro: tipos de equipamento + torre (POP)', opts==='olt,switch,router,radio,server,other,tower', opts);
 // criar equipamento dentro
 d.getElementById('site-add-type').value='server'; d.getElementById('btn-site-add').click(); await wait(20);
 const mt=d.getElementById('modal-el').textContent;
 ck('modal: dentro do site, sem posição, nome SRV-001', /Dentro de: POP-001/.test(mt)&&/Sem posição própria/.test(mt)&&d.getElementById('f-name').value==='SRV-001', d.getElementById('f-name').value);
 d.getElementById('f-fab').value='Dell'; d.getElementById('f-modelo').value='R740'; d.getElementById('modal-save').click(); await wait(300);
 const pc=posts.find(p=>p[0]==='/nos');
 ck('POST: parent_node_id=1, sem lat/lng, fabricante/modelo em attrs', pc&&pc[1].parent_node_id==='1'&&!('lat' in pc[1])&&!('lng' in pc[1])&&JSON.parse(pc[1].attrs).modelo==='R740', pc&&JSON.stringify(pc[1]));
 // torre dentro do POP: com posição inicial do POP
 ptr(q('.mnode[data-node="n1"]')); await wait(20);
 d.getElementById('site-add-type').value='tower'; d.getElementById('btn-site-add').click(); await wait(20);
 ck('torre dentro do POP: modal com posição e pai', /Dentro de: POP-001/.test(d.getElementById('modal-el').textContent)&&/Posição: -20\.85000, -42\.80000/.test(d.getElementById('modal-el').textContent)&&!d.getElementById('f-fab'));
 d.getElementById('modal-x').click();
 // editar equipamento: fabricante/modelo e mover de site
 ptr(q('.mnode[data-node="n1"]')); await wait(20); q('[data-eq-edit="n2"]').click(); await wait(10);
 ck('modal editar: fabricante e modelo preenchidos', d.getElementById('f-fab').value==='Huawei'&&d.getElementById('f-modelo').value==='MA5800');
 d.getElementById('f-site').value='8'; d.getElementById('f-modelo').value=''; d.getElementById('modal-save').click(); await wait(300);
 const pe=posts.find(p=>p[0]==='/nos/editar'); const at=pe&&JSON.parse(pe[1].attrs);
 ck('POST editar: mover p/ POP-002 e modelo apagado', pe&&pe[1].parent_node_id==='8'&&at.fabricante==='Huawei'&&!('modelo' in at), pe&&JSON.stringify(pe[1]));
 // conectar a partir de equipamento -> clicar num site com equipamentos -> escolher
 ptr(q('.mnode[data-node="n1"]')); await wait(20); q('[data-eq-link="n3"]').click(); await wait(10);
 ptr(q('.mnode[data-node="n1"]')); await wait(20);
 ck('destino num site com equipamentos abre a escolha', !!q('#btn-pick-site')&&!!q('[data-eq-pick="n2"]')&&!q('[data-eq-pick="n3"]'));
 q('[data-eq-pick="n4"]').click(); await wait(20);
 ck('escolher equipamento abre o modal de trecho (SW-001 → RT-001)', /Novo trecho/.test(d.getElementById('modal-el').textContent)&&/SW-001/.test(d.getElementById('modal-el').textContent)&&/RT-001/.test(d.getElementById('modal-el').textContent));
 d.getElementById('modal-save').click(); await wait(300);
 const pl=posts.find(p=>p[0]==='/links'); ck('POST ligação entre equipamentos do mesmo site', pl&&pl[1].from_node_id==='3'&&pl[1].to_node_id==='4');
 // busca acha equipamento e abre o site com destaque
 const inp=d.getElementById('search-input'); inp.value='rt-0'; inp.dispatchEvent(new w.Event('input',{bubbles:true}));
 const item=q('#search-results .search-item[data-id="n4"]'); ck('busca acha equipamento (mostra o site)', item&&/POP-001/.test(item.textContent));
 item.click(); await wait(20);
 ck('abre painel do site com o equipamento destacado', /POP-001/.test(q('#panel-right .panel-title').textContent)&&!!q('.site-eq.hl[data-eq="n4"]'));
 // ctx menu do mapa vazio
 console.log('falhas:',fail,'| erros console:',errs.length?errs.join('\n '):'nenhum'); process.exit(0);
})();
