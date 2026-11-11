// Fase 2C (jsdom): splitter e DIO como passivos — adicionar no site e na CTO, listas, sem ícone no mapa.
const {JSDOM, VirtualConsole} = require('jsdom'); const readMapHtml=require('../read_html.js');
const html=readMapHtml(process.argv[2]); const errs=[]; const vc=new VirtualConsole();
vc.on('jsdomError',e=>errs.push('jsdomError: '+(e.detail&&e.detail.message||e.message))); vc.on('error',e=>errs.push('console.error: '+e));
const N=(id,t,name,lat,lng,parent,attrs)=>({id,node_type:t,name,code:null,lat,lng,parent_node_id:parent||null,status:'normal',lifecycle:'ativo',attrs_json:attrs||null,equipment_id:null,notes:null});
const nodes=[N(1,'pop','POP-001',-20.85,-42.80),N(2,'cto','CTO-001',-20.84,-42.79,null,{capacity:4}),N(3,'ceo','CEO-001',-20.86,-42.81),
 N(4,'splitter','SPL-001',null,null,1,{razao:'1x8'}),N(5,'splitter','SPL-002',null,null,2,{razao:'10/90'}),N(6,'dio','DIO-001',null,null,1,{capacidade:12})];
const P=(id,node,role,num,label,ext,attrs)=>({id,node_id:node,port_num:num,label,port_role:role,media:'fibra',is_external:ext,status:'livre',attrs_json:attrs||null});
const ports=[P(1,4,'spl_in',1,'Entrada',false)].concat([1,2,3,4,5,6,7,8].map(i=>P(1+i,4,'spl_out',i,'Saída '+i,false)),
 [P(20,5,'spl_in',1,'Entrada',false),P(21,5,'spl_out',1,'Saída 1',false,{percentual:10}),P(22,5,'spl_out',2,'Saída 2',false,{percentual:90})],
 [1,2,3].map(i=>P(30+i,6,'dio',i,'DIO '+i,false)));
const posts=[]; const json=(st,b)=>Promise.resolve({redirected:false,status:st,ok:st<400,headers:{get:()=>'application/json'},json:()=>Promise.resolve(b)});
const dom=new JSDOM(html,{runScripts:'dangerously',pretendToBeVisual:true,virtualConsole:vc,url:'http://localhost/mapa',beforeParse(w){
 w.confirm=()=>true;
 w.fetch=(u,o={})=>{ if(o.method==='POST'){ posts.push([u.replace('/api/mapa',''),Object.fromEntries(o.body.entries())]); return json(200,{ok:true,data:{node:{id:99},link:{id:99},id:5}}); } return json(200,{ok:true,data:{nodes,ports:ports.flat(),links:[]}}); };
 w.HTMLCanvasElement.prototype.getContext=()=>null; }});
const w=dom.window,d=w.document; const wait=ms=>new Promise(r=>setTimeout(r,ms)); const q=s=>d.querySelector(s), qa=s=>[...d.querySelectorAll(s)];
let fail=0; const ck=(l,c,x='')=>{ if(!c)fail++; console.log(c?'[OK]  ':'[FALHA]',l,x); };
const open=async name=>{ const i=d.getElementById('search-input'); i.value=name; i.dispatchEvent(new w.Event('input',{bubbles:true})); const it=[...d.querySelectorAll('#search-results .search-item[data-id]')].find(e=>e.firstChild.textContent===name); it.click(); await wait(20); };
const last=path=>posts.filter(p=>p[0]===path).pop();
(async()=>{
 await wait(300);
 const drawn=qa('.mnode').map(g=>g.getAttribute('data-node')).sort();
 ck('splitter e DIO não viram ícone no mapa', JSON.stringify(drawn)==='["n1","n2","n3"]', drawn.join(','));
 ck('selo do POP não conta passivos como equipamento', !q('.mnode[data-node="n1"] .msite-badge'));
 ck('menu Adicionar do mapa não oferece splitter/DIO', qa('.add-menu-item').map(e=>e.getAttribute('data-type')).join(',')==='pop,tower,ceo,cto');
 // --- site (POP)
 await open('POP-001');
 const pan=d.getElementById('panel-right');
 ck('POP lista "Splitters e DIOs (2)"', /Splitters e DIOs \(2\)/.test(pan.textContent));
 const s1=q('[data-passive="n4"]').textContent;
 ck('splitter 1x8: razão, balanceado, 1 entrada + 8 saídas', /1x8 · balanceado · 1 entrada \+ 8 saídas/.test(s1), s1.slice(0,120));
 ck('splitter 1x8: 9 portas listadas', qa('[data-passive="n4"] .eq-port').length===9);
 const dd=q('[data-passive="n6"]').textContent; ck('DIO: capacidade e portas', /DIO · 12 portas/.test(dd)&&qa('[data-passive="n6"] .eq-port').length===3);
 ck('equipamentos do POP: 0 (passivos não contam)', /Equipamentos\s*0/.test(pan.textContent)||/Equipamentos \(0\)/.test(pan.textContent));
 const opts=qa('#site-add-type option').map(o=>o.value);
 ck('adicionar aqui dentro inclui Splitter e DIO', opts.includes('splitter')&&opts.includes('dio'), opts.join(','));
 // adicionar splitter no site
 d.getElementById('site-add-type').value='splitter'; d.getElementById('btn-site-add').click(); await wait(20);
 let mt=d.getElementById('modal-el').textContent;
 ck('modal splitter: dentro do POP, sem posição, nome SPL-003, sem fabricante/portas', /Dentro de: POP-001/.test(mt)&&/Sem posição própria/.test(mt)&&d.getElementById('f-name').value==='SPL-003'&&!d.getElementById('f-fab')&&!d.getElementById('f-ports-eth'), d.getElementById('f-name').value);
 const razoes=qa('#f-razao option').map(o=>o.value).join(',');
 ck('razões: 6 balanceadas + 7 desbalanceadas', razoes==='1x2,1x4,1x8,1x16,1x32,1x64,5/95,10/90,15/85,20/80,30/70,40/60,50/50', razoes);
 d.getElementById('f-razao').value='10/90'; d.getElementById('modal-save').click(); await wait(300);
 let pc=last('/nos'); ck('POST splitter: tipo, pai=1, sem lat/lng, attrs.razao', pc&&pc[1].node_type==='splitter'&&pc[1].parent_node_id==='1'&&!('lat' in pc[1])&&JSON.parse(pc[1].attrs).razao==='10/90'&&!Object.keys(pc[1]).some(k=>k.startsWith('ports_')), pc&&JSON.stringify(pc[1]));
 // adicionar DIO no site
 await open('POP-001');
 d.getElementById('site-add-type').value='dio'; d.getElementById('btn-site-add').click(); await wait(20);
 ck('modal DIO: nome DIO-002 e capacidades', d.getElementById('f-name').value==='DIO-002'&&qa('#f-dio-cap option').map(o=>o.value).join(',')==='12,24,36,48,72,96,144', d.getElementById('f-name').value);
 d.getElementById('f-dio-cap').value='48'; d.getElementById('modal-save').click(); await wait(300);
 pc=last('/nos'); ck('POST DIO: attrs.capacidade=48, pai=1', pc&&pc[1].node_type==='dio'&&pc[1].parent_node_id==='1'&&JSON.parse(pc[1].attrs).capacidade===48, pc&&JSON.stringify(pc[1]));
 // editar DIO no site
 await open('POP-001'); q('[data-pas-edit="n6"]').click(); await wait(10);
 ck('editar DIO: capacidade atual selecionada', d.getElementById('f-dio-cap').value==='12');
 d.getElementById('f-dio-cap').value='24'; d.getElementById('modal-save').click(); await wait(300);
 pc=last('/nos/editar'); ck('POST editar DIO: id e capacidade 24, preserva attrs', pc&&pc[1].id==='6'&&JSON.parse(pc[1].attrs).capacidade===24, pc&&JSON.stringify(pc[1]));
 // --- CTO
 await open('CTO-001');
 ck('CTO: seção "Splitters nesta caixa (1)"', /Splitters nesta caixa \(1\)/.test(pan.textContent));
 const s2=q('[data-passive="n5"]').textContent;
 ck('CTO: 10/90 desbalanceado com percentuais nas saídas', /10\/90 · desbalanceado · 1 entrada \+ 2 saídas \(10% e 90%\)/.test(s2)&&/Saída 1 · 10%/.test(s2)&&/Saída 2 · 90%/.test(s2), s2.slice(0,160));
 ck('CTO: botões Editar/Conectar/Excluir e Adicionar', !!q('[data-pas-edit="n5"]')&&!!q('[data-pas-link="n5"]')&&!!q('[data-pas-del="n5"]')&&!!d.getElementById('btn-spl-add'));
 d.getElementById('btn-spl-add').click(); await wait(20);
 mt=d.getElementById('modal-el').textContent;
 ck('adicionar na CTO: dentro de CTO-001, tipo splitter', /Dentro de: CTO-001/.test(mt)&&/Novo elemento — Splitter/.test(mt));
 d.getElementById('f-razao').value='1x16'; d.getElementById('modal-save').click(); await wait(300);
 pc=last('/nos'); ck('POST na CTO: parent_node_id=2, razão 1x16', pc&&pc[1].node_type==='splitter'&&pc[1].parent_node_id==='2'&&JSON.parse(pc[1].attrs).razao==='1x16');
 await open('CTO-001'); q('[data-pas-edit="n5"]').click(); await wait(10);
 ck('editar splitter: razão atual selecionada', d.getElementById('f-razao').value==='10/90');
 d.getElementById('f-razao').value='1x4'; d.getElementById('modal-save').click(); await wait(300);
 pc=last('/nos/editar'); ck('POST editar splitter: razão 1x4', pc&&pc[1].id==='5'&&JSON.parse(pc[1].attrs).razao==='1x4');
 await open('CTO-001'); q('[data-pas-del="n5"]').click(); await wait(300);
 pc=last('/nos/excluir'); ck('excluir splitter da CTO: POST /nos/excluir id=5', pc&&pc[1].id==='5');
 // --- CEO sem splitters
 await open('CEO-001');
 ck('CEO: seção com 0 splitters e botão adicionar', /Splitters nesta caixa \(0\)/.test(pan.textContent)&&!!d.getElementById('btn-spl-add'));
 // clique num passivo (ex.: busca) abre o painel do pai
 await open('SPL-002');
 ck('onNodeClick em passivo abre o painel do pai (CTO)', /CTO-001/.test(d.querySelector('.panel-title').textContent));
 ck('erros: nenhum', errs.length===0, errs.join(' | '));
 console.log('falhas: '+fail+' | erros console: '+(errs.length?errs.join(' | '):'nenhum')); process.exit(fail||errs.length?1:0);
})();
