const {JSDOM, VirtualConsole} = require('jsdom'); const readMapHtml=require('../read_html.js');
const html=readMapHtml(process.argv[2]); const errs=[]; const vc=new VirtualConsole();
vc.on('jsdomError',e=>errs.push('jsdomError: '+(e.detail&&e.detail.message||e.message))); vc.on('error',e=>errs.push('console.error: '+e));
const db={nodes:[]}; let nextId=2; const calls=[];
const json=(status,body)=>Promise.resolve({redirected:false,status,ok:status<400,headers:{get:()=>'application/json'},json:()=>Promise.resolve(body)});
const dom=new JSDOM(html,{runScripts:'dangerously',pretendToBeVisual:true,virtualConsole:vc,url:'http://localhost/mapa',beforeParse(w){
 w.fetch=(url,opt={})=>{
  calls.push((opt.method||'GET')+' '+url+(opt.headers&&opt.headers['X-CSRF-Token']?' [csrf ok]':''));
  if(!opt.method||opt.method==='GET') return json(200,{ok:true,data:{nodes:db.nodes.map(n=>({id:n.id,node_type:n.t,name:n.name,code:null,lat:n.lat,lng:n.lng,parent_node_id:null,status:'normal',attrs_json:n.attrs,equipment_id:null,notes:n.notes})),ports:[],links:[]}});
  const f=Object.fromEntries(opt.body.entries());
  if(url.endsWith('/nos')){ if(f.node_type==='client') return json(422,{ok:false,message:'Tipo de elemento inválido.'}); const n={id:nextId++,t:f.node_type,name:f.name,lat:+f.lat,lng:+f.lng,attrs:JSON.parse(f.attrs||'{}'),notes:f.notes}; db.nodes.push(n); return json(200,{ok:true,data:{node:{id:n.id}}}); }
  return json(409,{ok:false,message:'Não é possível excluir: 1 ligação(ões) dependem dele.'});
 }; w.HTMLCanvasElement.prototype.getContext=()=>null; }});
const w=dom.window, d=w.document; const wait=ms=>new Promise(r=>setTimeout(r,ms));
(async()=>{
 await wait(300);
 console.log('inicial:', d.getElementById('map-status').textContent, '| menu itens:', [...d.querySelectorAll('.add-menu-item')].map(e=>e.textContent.trim()).join(','));
 console.log('aviso canto:', d.getElementById('map-unsaved').textContent);
 d.querySelector('.add-menu-item[data-type="pop"]').dispatchEvent(new w.MouseEvent('click',{bubbles:true}));
 const map=d.getElementById('map');
 map.dispatchEvent(new w.MouseEvent('pointerdown',{bubbles:true,clientX:100,clientY:100}));
 map.dispatchEvent(new w.MouseEvent('pointerup',{bubbles:true,clientX:100,clientY:100}));
 await wait(50);
 console.log('modal aberto:', d.getElementById('modal-overlay').classList.contains('show'));
 const html0=d.getElementById('modal-el').innerHTML; console.log('modal tem IP aleatório?', /192\.168\.99/.test(html0));
 d.getElementById('f-name').value='ZZ TESTE POP'; d.getElementById('modal-save').click();
 await wait(400);
 console.log('chamadas:', calls.join(' | '));
 console.log('toast:', d.getElementById('toast').textContent);
 console.log('KPI links/pop/cto:', ['kpi-pop','kpi-cto','kpi-links'].map(i=>d.getElementById(i).textContent).join('/'), '| nó no mapa (g.mnode):', d.querySelectorAll('.mnode').length, '| status:', JSON.stringify(d.getElementById('map-status').textContent));
 // exclusão que o servidor recusa (409): mapa não muda e mostra mensagem
 const del=d.getElementById('btn-delete-node'); w.confirm=()=>true;
 if(del){ del.click(); await wait(300); console.log('apos 409 -> toast:', d.getElementById('toast').textContent, '| nós:', d.querySelectorAll('.mnode').length); } else console.log('sem botão excluir (painel não abriu)');
 console.log('erros:', errs.length?errs.join('\n '):'nenhum'); process.exit(0);
})();
