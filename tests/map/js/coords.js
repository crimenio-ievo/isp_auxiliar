const {JSDOM, VirtualConsole} = require('jsdom'); const fs=require('fs');
const html=fs.readFileSync(process.argv[2],'utf8'); const errs=[]; const vc=new VirtualConsole();
vc.on('jsdomError',e=>errs.push('jsdomError: '+(e.detail&&e.detail.message||e.message))); vc.on('error',e=>errs.push('console.error: '+e));
const posts=[]; let calls=0;
const json=(st,b)=>Promise.resolve({redirected:false,status:st,ok:st<400,headers:{get:()=>'application/json'},json:()=>Promise.resolve(b)});
const dom=new JSDOM(html,{runScripts:'dangerously',pretendToBeVisual:true,virtualConsole:vc,url:'http://localhost/mapa',beforeParse(w){
 w.fetch=(url,opt={})=>{ calls++; if(opt.method==='POST'){ posts.push(Object.fromEntries(opt.body.entries())); return json(200,{ok:true,data:{node:{id:2}}}); }
  return json(200,{ok:true,data:{nodes:[{id:1,node_type:'cto',name:'CTO Centro 10 20',lat:-21,lng:-42.8,parent_node_id:null,status:'normal',attrs_json:null,notes:null}],ports:[],links:[]}}); };
 w.HTMLCanvasElement.prototype.getContext=()=>null; }});
const w=dom.window,d=w.document; const wait=ms=>new Promise(r=>setTimeout(r,ms));
const inp=d.getElementById('search-input');
function search(q){ inp.value=q; inp.dispatchEvent(new w.Event('input',{bubbles:true})); const f=d.querySelector('#search-results .search-item'); return {first:f?f.textContent.replace('coordenada',''):null, isCoord:!!d.querySelector('#search-results .search-item[data-coord]')}; }
(async()=>{
 await wait(300); const base=calls; let fail=0;
 const ok=[ // [entrada, esperado]
  ['-21.0025, -42.8375','Ir para -21.00250, -42.83750'],['-21.0025 -42.8375','Ir para -21.00250, -42.83750'],
  ['-21,0025 -42,8375','Ir para -21.00250, -42.83750'],['-21,0025, -42,8375','Ir para -21.00250, -42.83750'],
  ['-21,0025; -42,8375','Ir para -21.00250, -42.83750'],['-21.0025,-42.8375','Ir para -21.00250, -42.83750'],
  ['21°00\'09"S 42°50\'15"W','Ir para -21.00250, -42.83750'],['21°00\'09"S, 42°50\'15"O','Ir para -21.00250, -42.83750'],
  ['21 00 09 S 42 50 15 W','Ir para -21.00250, -42.83750'],['21°00\'09S 42°50\'15W','Ir para -21.00250, -42.83750'],
  ['21°30\'N 42°15\'L','Ir para 21.50000, 42.25000'],['42°50\'15"W 21°00\'09"S','Ir para -21.00250, -42.83750'],
  ['https://www.google.com/maps/@-21.0025,-42.8375,17z','Ir para -21.00250, -42.83750'],
  ['https://www.google.com/maps/place/X/@-21.1,-42.1,15z/data=!3d-21.0025!4d-42.8375','Ir para -21.00250, -42.83750'],
  ['https://www.google.com/maps?q=-21.0025,-42.8375','Ir para -21.00250, -42.83750'],
  ['https://www.google.com/maps/search/?api=1&query=-21.0025%2C-42.8375','Ir para -21.00250, -42.83750'],
 ];
 const bad=['91, 10','10, 181','-21,5','-21,0025,-42,8375','21°70\'00"S 42°50\'15"W','21°00\'09"S 42°50\'15"S','21°00\'09"S','https://maps.app.goo.gl/abcd','CTO Centro','abc 12','-21.0025 -42.8375 5','95°00\'00"N 10°00\'00"E'];
 for(const [q,exp] of ok){ const r=search(q); const pass=r.isCoord&&r.first===exp; if(!pass)fail++; console.log(pass?'[OK]   ':'[FALHA]',q,'=>',r.first); }
 for(const q of bad){ const r=search(q); const pass=!r.isCoord; if(!pass)fail++; console.log(pass?'[OK]   ':'[FALHA]','(inválido)',q,'=>',r.first); }
 // busca normal intacta
 let r=search('centro'); console.log('busca normal "centro":',r.first, r.isCoord?'[FALHA]':'[OK]');
 // fluxo: coordenada -> Ir para -> pin -> Adicionar aqui
 search('-21.0025, -42.8375'); d.querySelector('#search-results .search-item[data-coord]').click(); await wait(50);
 console.log('pin-bar visível:', d.getElementById('pin-bar').classList.contains('show'), '| texto:', d.getElementById('pin-coords').textContent, '| marcador no svg:', d.querySelectorAll('.search-pin').length);
 d.getElementById('pin-type').value='cto'; d.getElementById('pin-add').click(); await wait(30);
 console.log('modal:', d.getElementById('modal-el').textContent.match(/Posição: [-\d., ]+/)[0]);
 d.getElementById('f-name').value='ZZ TESTE aqui'; d.getElementById('modal-save').click(); await wait(300);
 console.log('POST enviado:', JSON.stringify({t:posts[0].node_type,lat:posts[0].lat,lng:posts[0].lng,name:posts[0].name}), '| pin após salvar:', d.querySelectorAll('.search-pin').length);
 // limpar busca remove pin; clique no mapa também
 search('-21.0025 -42.8375'); d.querySelector('#search-results .search-item[data-coord]').click(); await wait(30);
 inp.value=''; inp.dispatchEvent(new w.Event('input',{bubbles:true})); console.log('após limpar busca -> marcador:', d.querySelectorAll('.search-pin').length, '| barra:', d.getElementById('pin-bar').classList.contains('show'));
 search('-21.0025 -42.8375'); d.querySelector('#search-results .search-item[data-coord]').click(); await wait(30);
 const map=d.getElementById('map'); map.dispatchEvent(new w.MouseEvent('pointerdown',{bubbles:true,clientX:5,clientY:5})); map.dispatchEvent(new w.MouseEvent('pointerup',{bubbles:true,clientX:5,clientY:5})); await wait(30);
 console.log('após clicar no mapa -> marcador:', d.querySelectorAll('.search-pin').length, '| input:', JSON.stringify(inp.value));
 console.log('chamadas de rede novas durante buscas (exceto o POST/GET do salvar):', calls-base-2);
 console.log('falhas:',fail,'| erros console:', errs.length?errs.join('\n '):'nenhum'); process.exit(0);
})();
