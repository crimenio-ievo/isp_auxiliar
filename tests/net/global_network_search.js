const fs=require('fs'),path=require('path'),{execFileSync}=require('child_process');
const {JSDOM,VirtualConsole}=require('../map/node_modules/jsdom');
const root=path.resolve(__dirname,'../..');
const html=execFileSync('php',[path.join(__dirname,'render_page.php')],{encoding:'utf8'});
const errors=[],vc=new VirtualConsole();vc.on('jsdomError',e=>errors.push(String(e)));
const w=new JSDOM(html,{runScripts:'outside-only',url:'http://localhost/rede-fibra',virtualConsole:vc}).window;
const calls=[];w.fetch=async u=>{calls.push(u);return {ok:true,json:async()=>({ok:true,data:[{tipo:'onu',titulo:'ONU 2 · PON 1 · OLT <X>',detalhe:null,url:'/rede-fibra?onu=2'},{tipo:'cliente',titulo:'Joao (joao_s)',detalhe:'CTO CTO-1 · Porta 2 · ONU 3 · PON 1',url:'/clientes/detalhe?login=joao_s'},{tipo:'cto',titulo:'CTO CTO-1',detalhe:'capacidade 16 porta(s)',url:'/rede-ctos/ficha?id=5'}]})};};
w.eval(fs.readFileSync(path.join(root,'public/assets/js/app.js'),'utf8'));
const wait=ms=>new Promise(r=>setTimeout(r,ms));let n=0;
function check(ok,msg){n++;if(!ok)throw new Error(msg);}
(async()=>{
 const input=w.document.querySelector('[data-network-search] input');check(!!input,'gestor recebe endpoint na busca global');
 input.value='002e';input.dispatchEvent(new w.Event('input'));await wait(350);
 check(calls.some(u=>u.includes('/api/rede-fibra/buscar?q=002e')),'consulta rede local');
 const list=w.document.querySelector('.network-search-results');check(!list.hidden&&list.querySelector('a').getAttribute('href')==='/rede-fibra?onu=2','resultado abre ficha');
 check(list.textContent.includes('<X>')&&!list.querySelector('X'),'titulo tratado como texto');
 const items=[...list.querySelectorAll('a')];
 check(items.length===3&&items[0].querySelector('.network-search-results__tipo').textContent==='ONU'&&items[1].querySelector('.network-search-results__tipo').textContent==='Cliente'&&items[2].querySelector('.network-search-results__tipo').textContent==='CTO','cada resultado mostra o tipo');
 check(items[1].querySelector('.network-search-results__detalhe').textContent==='CTO CTO-1 · Porta 2 · ONU 3 · PON 1'&&items[1].getAttribute('href')==='/clientes/detalhe?login=joao_s'&&!items[0].querySelector('.network-search-results__detalhe'),'cliente vinculado mostra CTO e ONU; sem detalhe, sem linha extra');
 check(items[2].getAttribute('href')==='/rede-ctos/ficha?id=5','CTO abre a ficha');
 input.dispatchEvent(new w.KeyboardEvent('keydown',{key:'Escape'}));check(list.hidden,'escape fecha sugestoes');
 input.value='a';input.dispatchEvent(new w.Event('input'));await wait(300);check(calls.length===1,'busca curta nao consulta');
 check(errors.length===0,'sem erros de script');console.log('GlobalNetworkSearch: '+n+' verificacoes, zero falhas');
})().catch(e=>{console.error(e.message);process.exitCode=1;});
