const {JSDOM, VirtualConsole} = require('jsdom'); const readMapHtml=require('../read_html.js');
const html=readMapHtml(process.argv[2]); const errs=[]; const vc=new VirtualConsole();
vc.on('jsdomError',e=>errs.push('jsdomError: '+(e.detail&&e.detail.message||e.message))); vc.on('error',e=>errs.push('console.error: '+e));
const db={nodes:[
 {id:1,name:'ZZ TESTE <b>x</b>',lat:-21.0,lng:-42.8,attrs:{ip:'<img src=x onerror=window.__xss=1>',poderia:1},notes:'<script>window.__xss=2</script>'},
 {id:2,name:'ZZ TESTE B',lat:-21.002,lng:-42.802,attrs:null,notes:null}], links:[]}; let nextL=1; const calls=[]; let forceErr=null;
const json=(st,b)=>Promise.resolve({redirected:false,status:st,ok:st<400,headers:{get:()=>'application/json'},json:()=>Promise.resolve(b)});
const dom=new JSDOM(html,{runScripts:'dangerously',pretendToBeVisual:true,virtualConsole:vc,url:'http://localhost/mapa',beforeParse(w){
 w.__xss=0; w.confirm=()=>true;
 w.fetch=(url,opt={})=>{ calls.push((opt.method||'GET')+' '+url.replace('/api/mapa',''));
  if(!opt.method) return json(200,{ok:true,data:{nodes:db.nodes.map(n=>({id:n.id,node_type:n.id===1?'pop':'cto',name:n.name,code:null,lat:n.lat,lng:n.lng,parent_node_id:null,status:'normal',attrs_json:n.attrs,equipment_id:null,notes:n.notes})),ports:[],
    links:db.links.map(l=>({from_port_id:null,to_port_id:null,path_json:null,fiber_count:null,length_m:null,attrs_json:null,label:null,...l}))}});
  const f=Object.fromEntries(opt.body.entries()); calls.push('   body '+JSON.stringify(f));
  if(forceErr) return json(forceErr.s,{ok:false,message:forceErr.m});
  if(url.endsWith('/links')){ db.links.push({id:nextL++,link_type:f.link_type,medium:'fibra',from_node_id:+f.from_node_id,to_node_id:+f.to_node_id,status:'normal',label:f.label,length_m:+f.length_m,fiber_count:+f.fiber_count||null,attrs_json:JSON.parse(f.attrs)}); return json(200,{ok:true,data:{link:{id:nextL-1}}}); }
  if(url.endsWith('/links/excluir')){ db.links=db.links.filter(l=>l.id!==+f.id); return json(200,{ok:true,data:{id:+f.id}}); }
  return json(200,{ok:true,data:{}}); };
 w.HTMLCanvasElement.prototype.getContext=()=>null; }});
const w=dom.window,d=w.document; const wait=ms=>new Promise(r=>setTimeout(r,ms));
const ptr=(el)=>{ el.dispatchEvent(new w.MouseEvent('pointerdown',{bubbles:true,clientX:1,clientY:1})); el.dispatchEvent(new w.MouseEvent('pointerup',{bubbles:true,clientX:1,clientY:1})); };
(async()=>{
 await wait(300);
 console.log('aviso canto:', d.getElementById('map-unsaved').textContent);
 console.log('nós desenhados:', d.querySelectorAll('.mnode').length);
 // XSS: abrir painel do nó 1 e pesquisar pelo nome
 ptr(d.querySelector('.mnode[data-node="n1"]')); await wait(30);
 const panel=d.getElementById('panel-right').innerHTML;
 console.log('painel: título escapado:', panel.includes('&lt;b&gt;x&lt;/b&gt;'), '| sem <b>x</b> cru:', !panel.includes('<b>x</b>'), '| sem <img>/<script> cru:', !/<img src=x|<script>window/.test(panel), '| __xss =', w.__xss);
 const inp=d.getElementById('search-input'); inp.value='teste'; inp.dispatchEvent(new w.Event('input',{bubbles:true}));
 const sr=d.getElementById('search-results').innerHTML; console.log('busca escapada:', sr.includes('&lt;b&gt;x&lt;/b&gt;') && !sr.includes('<b>x</b>'));
 console.log('rótulo no mapa escapado:', !d.getElementById('map').innerHTML.includes('<b>x</b>'));
 inp.value=''; inp.dispatchEvent(new w.Event('input',{bubbles:true}));
 // criar ligação: Conectar a partir daqui -> clicar no destino
 d.getElementById('btn-add-link-from').click(); ptr(d.querySelector('.mnode[data-node="n2"]')); await wait(30);
 console.log('modal novo trecho aberto:', /Novo trecho/.test(d.getElementById('modal-el').textContent));
 d.getElementById('modal-save').click(); await wait(400);
 console.log('chamadas:\n  '+calls.join('\n  '));
 console.log('links no mapa (.mlink):', d.querySelectorAll('.mlink').length, '| KPI links:', d.getElementById('kpi-links').textContent, '| toast:', d.getElementById('toast').textContent);
 // abrir o trecho, editar-botões
 ptr(d.querySelector('.mlink-hit')); await wait(30);
 console.log('painel ponto: sangria-ponto desabilitada:', d.getElementById('btn-sangria-ponto').disabled); d.getElementById('btn-ver-trecho').click(); await wait(30);
 console.log('painel trecho: sangria desabilitada:', d.getElementById('btn-sangria')&&d.getElementById('btn-sangria').disabled, '| tem Editar:', !!d.getElementById('btn-edit-link'));
 // erro 409 na exclusão: não altera o mapa
 forceErr={s:409,m:'Já existe uma ligação deste tipo entre esses dois elementos.'};
 d.getElementById('btn-delete-link').click(); await wait(200);
 console.log('409 -> toast:', d.getElementById('toast').textContent, '| links:', d.querySelectorAll('.mlink').length);
 d.getElementById('btn-edit-link').click(); d.getElementById('f-lk-label').value='ZZ TESTE novo rótulo'; d.getElementById('f-lk-status').value='alerta'; d.getElementById('f-lk-fo').value='24';
 forceErr=null; calls.length=0; d.getElementById('modal-save').click(); await wait(300);
 console.log('editar ->', calls.filter(c=>c.includes('editar')||c.includes('body')).join(' | ').slice(0,260));
 await wait(50); d.getElementById('btn-edit-link')||ptr(d.querySelector('.mlink-hit'));
 if(!d.getElementById('btn-delete-link')){ ptr(d.querySelector('.mlink-hit')); await wait(30); d.getElementById('btn-ver-trecho').click(); await wait(30);}
 d.getElementById('btn-delete-link').click(); await wait(300);
 console.log('excluir ok -> links no mapa:', d.querySelectorAll('.mlink').length, '| toast:', d.getElementById('toast').textContent);
 console.log('window.__xss final =', w.__xss, '| erros:', errs.length?errs.join('\n '):'nenhum'); process.exit(0);
})();
