// base jsdom: carrega o HTML com topologia fictícia e captura POSTs
const {JSDOM, VirtualConsole} = require('jsdom'); const fs=require('fs');
module.exports = function boot(topo, respond){
  const html=fs.readFileSync(require('path').join(__dirname,'../../modules/isp_map/isp-network-map.html'),'utf8'); const errs=[]; const vc=new VirtualConsole();
  vc.on('jsdomError',e=>errs.push('jsdomError: '+(e.detail&&e.detail.message||e.message))); vc.on('error',e=>errs.push('console.error: '+e));
  const posts=[]; const json=(st,b)=>Promise.resolve({redirected:false,status:st,ok:st<400,headers:{get:()=>'application/json'},json:()=>Promise.resolve(b)});
  const dom=new JSDOM(html,{runScripts:'dangerously',pretendToBeVisual:true,virtualConsole:vc,url:'http://localhost/mapa',beforeParse(w){
    w.confirm=()=>true;
    w.fetch=(u,o={})=>{ if(o.method==='POST'){ const p=[u.replace('/api/mapa',''),Object.fromEntries(o.body.entries())]; posts.push(p); const r=respond&&respond(p); return json(r?r[0]:200, r?r[1]:{ok:true,data:{node:{id:99},link:{id:99},port:{id:99}}}); } return json(200,{ok:true,data:topo()}); };
    w.HTMLCanvasElement.prototype.getContext=()=>null; }});
  const w=dom.window,d=w.document;
  return {w,d,posts,errs,wait:ms=>new Promise(r=>setTimeout(r,ms)),q:s=>d.querySelector(s),qa:s=>[...d.querySelectorAll(s)],
    ptr:el=>{ el.dispatchEvent(new w.MouseEvent('pointerdown',{bubbles:true,clientX:1,clientY:1})); el.dispatchEvent(new w.MouseEvent('pointerup',{bubbles:true,clientX:1,clientY:1})); }};
};
module.exports.N=(id,t,name,lat,lng,parent,attrs)=>({id,node_type:t,name,code:null,lat,lng,parent_node_id:parent||null,status:'normal',lifecycle:'ativo',attrs_json:attrs||null,equipment_id:null,notes:null});
module.exports.P=(id,node,role,num,label,ext,media)=>({id,node_id:node,port_num:num,label,port_role:role,media:media||{eth:'rj45',sfp:'sfp',pon:'pon',fo:'fibra'}[role],is_external:!!ext,status:'livre',attrs_json:null});
module.exports.L=(id,t,a,b,label,fp,tp,len)=>({id,link_type:t,medium:{ethernet:'cabo',wireless:'radio'}[t]||'fibra',from_node_id:a,to_node_id:b,from_port_id:fp||null,to_port_id:tp||null,status:'normal',lifecycle:'ativo',label,length_m:len==null?null:len,fiber_count:null,attrs_json:null,path_json:null});
