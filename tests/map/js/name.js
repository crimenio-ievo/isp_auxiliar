const {JSDOM, VirtualConsole} = require('jsdom'); const readMapHtml=require('../read_html.js');
const html=readMapHtml(process.argv[2]); const errs=[]; const vc=new VirtualConsole();
vc.on('jsdomError',e=>errs.push('jsdomError: '+(e.detail&&e.detail.message||e.message))); vc.on('error',e=>errs.push('console.error: '+e));
const N=(id,t,name)=>({id,node_type:t,name,code:null,lat:-21+id*0.0005,lng:-42.8,parent_node_id:null,status:'normal',attrs_json:null,equipment_id:null,notes:null});
const nodes=[N(1,'cto','CTO-001'),N(2,'cto','CTO-007'),N(3,'cto','CTO-abc'),N(4,'cto','cto-12'),N(5,'olt','OLT-003'),N(6,'cto','CTO-7 extra'),N(7,'pop','CTO-050')];
const json=(st,b)=>Promise.resolve({redirected:false,status:st,ok:st<400,headers:{get:()=>'application/json'},json:()=>Promise.resolve(b)});
const posts=[];
const dom=new JSDOM(html,{runScripts:'dangerously',pretendToBeVisual:true,virtualConsole:vc,url:'http://localhost/mapa',beforeParse(w){
 w.fetch=(u,o={})=>{ if(o.method==='POST'){posts.push(Object.fromEntries(o.body.entries())); return json(200,{ok:true,data:{node:{id:99}}});} return json(200,{ok:true,data:{nodes,ports:[],links:[]}}); };
 w.HTMLCanvasElement.prototype.getContext=()=>null; }});
const w=dom.window,d=w.document; const wait=ms=>new Promise(r=>setTimeout(r,ms));
(async()=>{
 await wait(300);
 const map=d.getElementById('map');
 for(const t of ['cto','pop','tower','ceo']){
  d.querySelector(`.add-menu-item[data-type="${t}"]`).dispatchEvent(new w.MouseEvent('click',{bubbles:true}));
  map.dispatchEvent(new w.MouseEvent('pointerdown',{bubbles:true,clientX:50,clientY:50})); map.dispatchEvent(new w.MouseEvent('pointerup',{bubbles:true,clientX:50,clientY:50})); await wait(20);
  const f=d.getElementById('f-name'); console.log(t.padEnd(7),'sugerido:',f.value,'| selecionado:',f.selectionStart===0&&f.selectionEnd===f.value.length,'| foco:',d.activeElement===f);
  d.getElementById('modal-x').click();
 }
 // Enter salva
 d.querySelector('.add-menu-item[data-type="cto"]').dispatchEvent(new w.MouseEvent('click',{bubbles:true}));
 map.dispatchEvent(new w.MouseEvent('pointerdown',{bubbles:true,clientX:50,clientY:50})); map.dispatchEvent(new w.MouseEvent('pointerup',{bubbles:true,clientX:50,clientY:50})); await wait(20);
 d.getElementById('f-name').dispatchEvent(new w.KeyboardEvent('keydown',{key:'Enter',bubbles:true})); await wait(200);
 console.log('Enter salva -> POST name =',posts[0]&&posts[0].name);
 // nome vazio continua inválido
 d.querySelector('.add-menu-item[data-type="pop"]').dispatchEvent(new w.MouseEvent('click',{bubbles:true}));
 map.dispatchEvent(new w.MouseEvent('pointerdown',{bubbles:true,clientX:50,clientY:50})); map.dispatchEvent(new w.MouseEvent('pointerup',{bubbles:true,clientX:50,clientY:50})); await wait(20);
 d.getElementById('f-name').value=''; d.getElementById('modal-save').click(); await wait(50);
 console.log('nome vazio -> toast:', d.getElementById('toast').textContent, '| POSTs:', posts.length);
 d.getElementById('modal-x').click();
 // overzoom: Esri e OSM em zoom alto
 const sel=d.getElementById('sel-basemap');
 for(const k of ['sat','osm']){
  sel.value=k; sel.dispatchEvent(new w.Event('change',{bubbles:true}));
  for(let i=0;i<14;i++) d.getElementById('btn-zoom-in').click();
  await wait(20);
  const zs=[...d.querySelectorAll('image.mtile')].map(i=>{const m=i.getAttribute('href').match(/tile\/(\d+)\/|\/(\d+)\/\d+\/\d+\.png/);return +(m[1]||m[2]);});
  const size=d.querySelector('image.mtile')&&+d.querySelector('image.mtile').getAttribute('width');
  console.log(k,'zoom máximo do mapa: tiles pedidos em z =',[...new Set(zs)].join(','),'| tamanho do tile na tela:',size,'px (>256 = ampliado)');
 }
 console.log('erros:',errs.length?errs.join('\n '):'nenhum'); process.exit(0);
})();
