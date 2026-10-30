const {JSDOM, VirtualConsole} = require('jsdom'); const fs=require('fs');
const [,, htmlPath, mode] = process.argv;
const html = fs.readFileSync(htmlPath,'utf8');
const errs=[]; const vc=new VirtualConsole();
vc.on('jsdomError',e=>errs.push('jsdomError: '+(e.detail&&e.detail.message||e.message)));
vc.on('error',e=>errs.push('console.error: '+e));
const payloads={
 empty:{ok:true,data:{nodes:[],ports:[],links:[]}},
 one:{ok:true,data:{
  nodes:[{id:7,node_type:'pop',name:'POP Teste',code:null,lat:-21.0,lng:-42.83,parent_node_id:null,status:'normal',attrs_json:{ip:'10.0.0.1'},equipment_id:null,notes:null},
         {id:8,node_type:'olt',name:'OLT Teste',code:null,lat:-21.001,lng:-42.831,parent_node_id:null,status:'normal',attrs_json:null,equipment_id:null,notes:null}],
  ports:[{id:3,node_id:8,port_num:1,label:'PON 1',port_role:'pon',status:'normal',attrs_json:{onus:4,power:-19.2}}],
  links:[{id:5,link_type:'ethernet',medium:'cobre',from_node_id:7,to_node_id:8,from_port_id:null,to_port_id:null,status:'normal',label:'Uplink',length_m:null,fiber_count:null,attrs_json:null,path_json:null}]}}
};
const dom = new JSDOM(html,{runScripts:'dangerously',pretendToBeVisual:true,virtualConsole:vc,url:'http://localhost/mapa',
 beforeParse(w){
  w.fetch=()=>{
    if(mode==='session') return Promise.resolve({redirected:true,status:200,headers:{get:()=> 'text/html'},json:()=>Promise.reject(new Error('x'))});
    if(mode==='forbidden') return Promise.resolve({redirected:false,status:403,headers:{get:()=>'application/json'},json:()=>Promise.resolve({ok:false})});
    return Promise.resolve({redirected:false,status:200,headers:{get:()=>'application/json; charset=UTF-8'},json:()=>Promise.resolve(payloads[mode])});
  };
  w.HTMLCanvasElement.prototype.getContext=()=>null;
 }});
setTimeout(()=>{
 const d=dom.window.document;
 console.log('mode='+mode,'| status="'+d.getElementById('map-status').textContent+'" show='+d.getElementById('map-status').classList.contains('show'));
 console.log('kpi nós/cto/links:', ['kpi-cto','kpi-links'].map(i=>{const e=d.getElementById(i);return e&&e.textContent}).join(' / '));
 console.log('markers no svg:', d.querySelectorAll('#map-svg g').length, '| aviso diagrama:', !!d.querySelector('.diagram-unsaved'), '| aviso edição:', d.getElementById('map-unsaved').textContent);
 console.log('erros:', errs.length? errs.join('\n  '):'nenhum');
 process.exit(0);
},800);
