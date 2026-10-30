const {JSDOM, VirtualConsole} = require('jsdom'); const fs=require('fs');
const html=fs.readFileSync(process.argv[2],'utf8'); const errs=[]; const vc=new VirtualConsole();
vc.on('jsdomError',e=>errs.push('jsdomError: '+(e.detail&&e.detail.message||e.message))); vc.on('error',e=>errs.push('console.error: '+e));
const N=(id,t,name,st,lc,i)=>({id,node_type:t,name,code:null,lat:-21+i*0.0006,lng:-42.8+i*0.0006,parent_node_id:null,status:st,lifecycle:lc,attrs_json:null,equipment_id:null,notes:null});
const nodes=[N(1,'cto','CTO-001','alerta','ativo',1),N(2,'cto','CTO-002','normal','projeto',2),N(3,'pop','POP-001','offline','desativado',3),N(4,'ceo','CEO-001','alerta','projeto',4),N(5,'ceo','CEO-002','normal','ativo',5)];
const links=[{id:1,link_type:'ethernet',medium:'cabo',from_node_id:5,to_node_id:1,status:'normal',lifecycle:'ativo',label:'a',length_m:null,fiber_count:null,attrs_json:null,path_json:null},
             {id:2,link_type:'distribuicao',medium:'fibra',from_node_id:5,to_node_id:2,status:'normal',lifecycle:'projeto',label:'b',length_m:null,fiber_count:null,attrs_json:null,path_json:null}];
const posts=[]; const json=(st,b)=>Promise.resolve({redirected:false,status:st,ok:st<400,headers:{get:()=>'application/json'},json:()=>Promise.resolve(b)});
const dom=new JSDOM(html,{runScripts:'dangerously',pretendToBeVisual:true,virtualConsole:vc,url:'http://localhost/mapa',beforeParse(w){
 w.fetch=(u,o={})=>{ if(o.method==='POST'){posts.push([u.replace('/api/mapa',''),Object.fromEntries(o.body.entries())]); return json(200,{ok:true,data:{node:{id:99},link:{id:99}}});} return json(200,{ok:true,data:{nodes:nodes.map(x=>({...x,ports:undefined})),ports:[],links}}); };
 w.HTMLCanvasElement.prototype.getContext=()=>null; }});
const w=dom.window,d=w.document; const wait=ms=>new Promise(r=>setTimeout(r,ms)); const q=s=>d.querySelector(s), qa=s=>[...d.querySelectorAll(s)];
const ptr=(el)=>{ el.dispatchEvent(new w.MouseEvent('pointerdown',{bubbles:true,clientX:1,clientY:1})); el.dispatchEvent(new w.MouseEvent('pointerup',{bubbles:true,clientX:1,clientY:1})); };
let fail=0; const ck=(l,c,x='')=>{ if(!c)fail++; console.log(c?'[OK]  ':'[FALHA]',l,x); };
(async()=>{
 await wait(300);
 const kpi=i=>d.getElementById(i).textContent;
 ck('KPI CTO conta só ativo (1 de 2)',kpi('kpi-cto')==='1',kpi('kpi-cto')); ck('KPI POP ignora desativado (0)',kpi('kpi-pop')==='0'); ck('KPI links só ativo (1 de 2)',kpi('kpi-links')==='1',kpi('kpi-links'));
 ck('alertas só ativo (1: CTO-001; ignora OLT projeto e POP desativado)',kpi('kpi-alerts')==='1',kpi('kpi-alerts'));
 const gn=id=>q(`.mnode[data-node="n${id}"]`);
 ck('nó projeto: círculo tracejado e translúcido', gn(2)&&/stroke-dasharray/.test(gn(2).innerHTML)&&gn(2).getAttribute('opacity')==='0.6');
 ck('nó ativo: sólido e opaco', gn(1)&&!/stroke-dasharray/.test(gn(1).innerHTML.split('<circle').slice(0,3).join(''))&&gn(1).getAttribute('opacity')==='1');
 const lk=qa('.mlink'); ck('trecho projeto tracejado/translúcido', lk.some(l=>l.getAttribute('stroke-dasharray')==='3,4'&&parseFloat(l.getAttribute('opacity'))<.6));
 ck('filtro Situação na barra (3 opções, todas marcadas)', qa('[data-filter-lifecycle]').length===3&&qa('[data-filter-lifecycle]').every(c=>c.checked));
 const cb=q('[data-filter-lifecycle="projeto"]'); cb.checked=false; cb.dispatchEvent(new w.Event('change',{bubbles:true})); await wait(20);
 ck('desmarcar Projeto esconde nós e trechos em projeto', !gn(2)&&!gn(4)&&qa('.mlink').length===1&&!!gn(1), 'nós='+qa('.mnode').length+' trechos='+qa('.mlink').length);
 cb.checked=true; cb.dispatchEvent(new w.Event('change',{bubbles:true})); await wait(20);
 ck('remarcar volta tudo', qa('.mnode').length===5);
 ptr(gn(2)); await wait(20); ck('painel mostra a situação', /Situação: Projeto/.test(d.getElementById('panel-right').textContent));
 d.getElementById('btn-edit-node')&&0; const eb=qa('#panel-right button').find(b=>/Editar/.test(b.textContent)); eb.click();
 ck('modal editar nó: Situação pré-selecionada = projeto', d.getElementById('f-lifecycle').value==='projeto');
 d.getElementById('f-lifecycle').value='desativado'; calls=0; d.getElementById('modal-save').click(); await wait(300);
 const pe=posts.find(p=>p[0]==='/nos/editar'); ck('POST editar envia lifecycle', pe&&pe[1].lifecycle==='desativado', JSON.stringify(pe&&pe[1].lifecycle));
 // criar nó: padrão Ativo
 q('.add-menu-item[data-type="cto"]').dispatchEvent(new w.MouseEvent('click',{bubbles:true})); const map=d.getElementById('map'); ptr(map); await wait(20);
 ck('modal criar nó: Situação padrão = ativo', d.getElementById('f-lifecycle').value==='ativo');
 d.getElementById('f-lifecycle').value='projeto'; d.getElementById('modal-save').click(); await wait(300);
 const pc=posts.find(p=>p[0]==='/nos'); ck('POST criar envia lifecycle=projeto', pc&&pc[1].lifecycle==='projeto');
 // trecho
 ptr(gn(1)); await wait(20); d.getElementById('btn-add-link-from').click(); ptr(gn(5)); await wait(20);
 ck('modal novo trecho: Situação padrão = ativo', d.getElementById('f-linklifecycle')&&d.getElementById('f-linklifecycle').value==='ativo');
 d.getElementById('f-linklifecycle').value='projeto'; d.getElementById('modal-save').click(); await wait(300);
 const pl=posts.find(p=>p[0]==='/links'); ck('POST novo trecho envia lifecycle', pl&&pl[1].lifecycle==='projeto');
 // editar trecho
 ptr(qa('.mlink-hit')[1]); await wait(20); q('#btn-ver-trecho').click(); await wait(20); ck('painel do trecho mostra situação', /Situação: Projeto/.test(d.getElementById('panel-right').textContent));
 q('#btn-edit-link').click(); ck('modal editar trecho: Situação pré-selecionada', d.getElementById('f-lk-lifecycle').value==='projeto');
 d.getElementById('f-lk-lifecycle').value='ativo'; d.getElementById('modal-save').click(); await wait(300);
 const pk=posts.find(p=>p[0]==='/links/editar'); ck('POST editar trecho envia lifecycle', pk&&pk[1].lifecycle==='ativo');
 // mapa escuro
 const sel=d.getElementById('sel-basemap'); ck('opção do mapa escuro renomeada', [...sel.options].some(o=>/OSM, filtro/.test(o.textContent)) && ![...sel.options].some(o=>/Carto/.test(o.textContent)));
 sel.value='dark'; sel.dispatchEvent(new w.Event('change',{bubbles:true})); await wait(20);
 const g=q('image.mtile').parentNode; ck('mapa escuro: tiles OSM com filtro CSS só no grupo de tiles', /tile\.openstreetmap\.org/.test(q('image.mtile').getAttribute('href'))&&/invert\(1\)/.test(g.getAttribute('style'))&&!/filter/.test(q('.mnode').parentNode.getAttribute('style')||''));
 console.log('falhas:',fail,'| erros console:',errs.length?errs.join('\n '):'nenhum'); process.exit(0);
})();
