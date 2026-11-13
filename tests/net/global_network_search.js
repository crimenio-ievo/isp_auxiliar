const fs=require('fs'),path=require('path'),{execFileSync}=require('child_process');
const {JSDOM,VirtualConsole}=require('../map/node_modules/jsdom');
const root=path.resolve(__dirname,'../..');
const html=execFileSync('php',[path.join(__dirname,'render_page.php')],{encoding:'utf8'});
const errors=[],vc=new VirtualConsole();vc.on('jsdomError',e=>errors.push(String(e)));
const w=new JSDOM(html,{runScripts:'outside-only',url:'http://localhost/rede-fibra',virtualConsole:vc}).window;
const calls=[];w.fetch=async u=>{calls.push(u);return {ok:true,json:async()=>({ok:true,data:[{titulo:'ONU 2 · PON 1 · OLT <X>',url:'/rede-fibra?onu=2'}]})};};
w.eval(fs.readFileSync(path.join(root,'public/assets/js/app.js'),'utf8'));
const wait=ms=>new Promise(r=>setTimeout(r,ms));let n=0;
function check(ok,msg){n++;if(!ok)throw new Error(msg);}
(async()=>{
 const input=w.document.querySelector('[data-network-search] input');check(!!input,'gestor recebe endpoint na busca global');
 input.value='002e';input.dispatchEvent(new w.Event('input'));await wait(350);
 check(calls.some(u=>u.includes('/api/rede-fibra/buscar?q=002e')),'consulta rede local');
 const list=w.document.querySelector('.network-search-results');check(!list.hidden&&list.querySelector('a').getAttribute('href')==='/rede-fibra?onu=2','resultado abre ficha');
 check(list.textContent.includes('<X>')&&!list.querySelector('X'),'titulo tratado como texto');
 input.dispatchEvent(new w.KeyboardEvent('keydown',{key:'Escape'}));check(list.hidden,'escape fecha sugestoes');
 input.value='a';input.dispatchEvent(new w.Event('input'));await wait(300);check(calls.length===1,'busca curta nao consulta');
 check(errors.length===0,'sem erros de script');console.log('GlobalNetworkSearch: '+n+' verificacoes, zero falhas');
})().catch(e=>{console.error(e.message);process.exitCode=1;});
