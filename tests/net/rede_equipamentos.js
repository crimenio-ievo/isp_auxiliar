// jsdom: tela /rede-equipamentos (HTML do PHP + public/assets/js/rede-equipamentos.js). Fixtures de TESTE; fetch simulado.
const fs=require('fs'),path=require('path'),{execFileSync}=require('child_process');
const {JSDOM,VirtualConsole}=require('../map/node_modules/jsdom');
const root=path.resolve(__dirname,'../..');
const html=execFileSync('php',[path.join(__dirname,'render_devices.php')],{encoding:'utf8'});
const errors=[],vc=new VirtualConsole();vc.on('jsdomError',e=>errors.push(String(e)));
const w=new JSDOM(html,{runScripts:'outside-only',url:'http://localhost/rede-equipamentos',virtualConsole:vc}).window;
const D=w.document;
const fx={
  equipamentos:[
    {node_id:42,nome:'<img src=x onerror=window.__xss=1>OLT-1',site:'POP-A',site_id:8,fabricante:'Hioso',modelo:'HA7304',device_id:1,ip:'192.168.0.89',perfil:{id:3,code:'hioso-epon-25355-4-4-v3',status:'active'},perfil_pretendido:null,credencial:'NET_CRED_A',coleta_ativa:true,intervalo_status_s:600,intervalo_potencia_s:1800,identidade_aprovada:true,ultima_tentativa:'2026-11-19 10:00:00',ultima_leitura_ok:'2026-11-19 10:00:00',ultimo_resultado:'ok',falhas_seguidas:0,saude:{estado:'ok',texto:'Leitura em dia',minutos:2}},
    {node_id:43,nome:'OLT-2',site:null,site_id:null,fabricante:'',modelo:'',device_id:2,ip:'192.168.0.90',perfil:{id:3,code:'hioso-epon-25355-4-4-v3',status:'active'},perfil_pretendido:null,credencial:'NET_CRED_A',coleta_ativa:false,intervalo_status_s:600,intervalo_potencia_s:1800,identidade_aprovada:false,ultima_tentativa:null,ultima_leitura_ok:null,ultimo_resultado:null,falhas_seguidas:0,saude:{estado:'desativada',texto:'Coleta desativada',minutos:null}},
    {node_id:44,nome:'OLT-VSOL',site:'POP-A',site_id:8,fabricante:'V-SOL',modelo:'',device_id:null,ip:null,perfil:null,perfil_pretendido:'vsol',credencial:null,coleta_ativa:false,intervalo_status_s:null,intervalo_potencia_s:null,identidade_aprovada:false,ultima_tentativa:null,ultima_leitura_ok:null,ultimo_resultado:null,falhas_seguidas:0,saude:{estado:'sem_coleta',texto:'Perfil ainda não suportado: coleta desativada',minutos:null}},
    {node_id:45,nome:'OLT-LIVRE',site:'POP-A',site_id:8,fabricante:'',modelo:'',device_id:null,ip:null,perfil:null,perfil_pretendido:null,credencial:null,coleta_ativa:false,intervalo_status_s:null,intervalo_potencia_s:null,identidade_aprovada:false,ultima_tentativa:null,ultima_leitura_ok:null,ultimo_resultado:null,falhas_seguidas:0,saude:{estado:'sem_coleta',texto:'Sem registro de coleta',minutos:null}}
  ],
  perfis:{suportados:[{id:3,code:'hioso-epon-25355-4-4-v3',vendor:'Hioso',technology:'epon',version:3}],planejados:[{code:'vsol',label:'V-SOL'},{code:'ha7308vx',label:'HA7308VX'},{code:'outro',label:'Outro modelo'}]},
  credenciais:['NET_CRED_A','NET_CRED_B'],sites:[{id:8,nome:'POP-A',tipo:'pop'}],padroes:{intervalo_status_s:600,intervalo_potencia_s:1800},limites:{status_s:[300,86400],potencia_s:[600,86400]}
};
const calls=[];let nextPost=null;
w.fetch=async (u,o)=>{calls.push({u,o});
  if(!o||!o.method||o.method==='GET'){return {ok:true,status:200,json:async()=>({ok:true,data:fx})};}
  const r=nextPost||{status:200,body:{ok:true,message:'Feito.'}};
  return {ok:r.status<400,status:r.status,json:async()=>r.body};};
w.eval(fs.readFileSync(path.join(root,'public/assets/js/rede-equipamentos.js'),'utf8'));
const wait=ms=>new Promise(r=>setTimeout(r,ms));let n=0;
function check(ok,msg){n++;if(!ok)throw new Error('FALHA: '+msg);}
const body=c=>Object.fromEntries(new w.URLSearchParams(c.o.body));
const rows=()=>[...D.querySelectorAll('#rc-table tbody tr')];
const btn=(row,text)=>[...row.querySelectorAll('button,a')].find(b=>b.textContent===text);
(async()=>{
 await wait(30);
 check(rows().length===4,'4 linhas (3 OLTs + 1 sem coleta)');
 check(w.__xss===undefined&&!D.querySelector('#rc-table img'),'nome tratado como texto (sem XSS)');
 check(rows()[0].textContent.includes('<img src=x'),'o nome malicioso aparece como texto');
 const t0=rows()[0].textContent;
 check(t0.includes('POP-A')&&t0.includes('Hioso · HA7304')&&t0.includes('192.168.0.89')&&t0.includes('Ativa')&&t0.includes('19/11/2026 10:00')&&t0.includes('Leitura em dia'),'linha da OLT ativa com site, modelo, IP, coleta, ultima leitura e saude');
 const t1=rows()[1].textContent;
 check(t1.includes('sem site')&&t1.includes('a identificar')&&t1.includes('ainda não lida')&&t1.includes('Desativada')&&t1.includes('identidade a aprovar'),'campos ausentes aparecem como "a identificar"/"ainda nao lida", sem dado inventado');
 const t2=rows()[2].textContent;
 check(t2.includes('perfil ainda não suportado')&&t2.includes('sem IP cadastrado')&&t2.includes('Sem coleta')&&t2.includes('Perfil ainda não suportado: coleta desativada'),'perfil nao suportado explicito');
 check(!btn(rows()[2],'Testar conexão')&&!btn(rows()[2],'Ativar coleta'),'sem registro de coleta nao tem testar/ativar');
 check(D.getElementById('rc-summary').textContent.includes('4 OLT(s) no mapa')&&D.getElementById('rc-summary').textContent.includes('1 com coleta ativa'),'resumo');
 // formulario
 D.getElementById('rc-new').click();
 check(!D.getElementById('rc-form-card').hidden,'abre o formulario');
 const cred=[...D.getElementById('rc-cred').options].map(o=>o.textContent);
 check(cred.join('|')==='Escolha a credencial|NET_CRED_A (.env)|NET_CRED_B (.env)','credencial lista so nomes (sem valores), marcando a origem .env');
 const perf=[...D.getElementById('rc-perfil').options].map(o=>o.textContent);
 check(perf.length===4&&perf[1].includes('V-SOL')&&perf[1].includes('perfil ainda não suportado'),'perfis suportados + nao suportados');
 const nodes=[...D.getElementById('rc-node-id').options].map(o=>o.textContent);
 check(nodes.length===3&&nodes[1].startsWith('OLT-VSOL')&&nodes[2].startsWith('OLT-LIVRE'),'OLTs sem registro de coleta para escolher');
 const f=D.getElementById('rc-form');
 check(D.getElementById('rc-i-status').value==='600'&&D.getElementById('rc-i-power').value==='1800','intervalos padrao 600 e 1800');
 // perfil nao suportado esconde campos de coleta
 f.elements.perfil.value='vsol';f.elements.perfil.dispatchEvent(new w.Event('change'));
 check(!D.getElementById('rc-planned').hidden&&D.getElementById('rc-collect-fields').hidden,'perfil nao suportado esconde IP/credencial e mostra aviso');
 f.elements.perfil.value='3';f.elements.perfil.dispatchEvent(new w.Event('change'));
 check(D.getElementById('rc-planned').hidden&&!D.getElementById('rc-collect-fields').hidden,'perfil suportado mostra os campos de coleta');
 // salvar OLT existente
 calls.length=0;
 f.elements.node_id.value='45';f.elements.mgmt_host.value=' 192.168.0.50 ';f.elements.credential_ref.value='NET_CRED_B';
 f.dispatchEvent(new w.Event('submit',{cancelable:true}));await wait(30);
 let c=calls.find(x=>x.o&&x.o.method==='POST');const b=body(c);
 check(c.u==='/api/rede-equipamentos'&&c.o.headers['X-CSRF-Token']==='TOKEN-TESTE','POST com X-CSRF-Token');
 check(b.node_id==='45'&&b.mgmt_host==='192.168.0.50'&&b.credential_ref==='NET_CRED_B'&&b.perfil==='3'&&b.intervalo_status_s==='600'&&!('name' in b),'corpo do POST (OLT existente)');
 check(D.getElementById('rc-form-card').hidden&&D.getElementById('rc-msg').textContent==='Feito.','fecha o formulario e mostra a mensagem do servidor');
 // criar OLT nova
 D.getElementById('rc-new').click();
 f.elements.node_mode.forEach?0:0;
 const radios=[...f.querySelectorAll('input[name=node_mode]')];radios.find(r=>r.value==='new').checked=true;radios.find(r=>r.value==='new').dispatchEvent(new w.Event('change'));
 check(D.getElementById('rc-node-id').closest('label').hidden&&!f.elements.name.closest('label').hidden,'modo novo mostra nome/site/PON e esconde a lista');
 f.elements.name.value='OLT-NOVA';f.elements.site_id.value='8';f.elements.ports_pon.value='8';f.elements.mgmt_host.value='192.168.0.60';f.elements.credential_ref.value='NET_CRED_A';f.elements.fabricante.value='Hioso';
 calls.length=0;nextPost={status:422,body:{ok:false,message:'Nome da OLT é obrigatório.'}};
 f.dispatchEvent(new w.Event('submit',{cancelable:true}));await wait(30);
 c=calls.find(x=>x.o&&x.o.method==='POST');const b2=body(c);
 check(b2.node_mode==='new'&&b2.name==='OLT-NOVA'&&b2.site_id==='8'&&b2.ports_pon==='8'&&b2.fabricante==='Hioso'&&!('node_id' in b2),'corpo do POST (OLT nova)');
 check(!D.getElementById('rc-form-card').hidden&&D.getElementById('rc-msg').textContent==='Nome da OLT é obrigatório.'&&D.getElementById('rc-msg').dataset.tone==='error','erro 422 mantem o formulario e mostra a mensagem');
 nextPost=null;D.getElementById('rc-cancel').click();check(D.getElementById('rc-form-card').hidden,'cancelar fecha');
 // editar
 btn(rows()[0],'Editar').click();
 check(D.getElementById('rc-form-title').textContent.startsWith('Editar equipamento')&&f.elements.mgmt_host.value==='192.168.0.89'&&f.elements.credential_ref.value==='NET_CRED_A'&&f.elements.device_id.value==='1'&&D.getElementById('rc-node-fs').disabled,'edicao carrega os dados e trava a escolha do no');
 calls.length=0;f.dispatchEvent(new w.Event('submit',{cancelable:true}));await wait(30);
 const b3=body(calls.find(x=>x.o&&x.o.method==='POST'));check(b3.device_id==='1'&&b3.node_id==='42'&&b3.mgmt_host==='192.168.0.89','edicao envia device_id e node_id');
 // ativar exige confirmacao
 calls.length=0;
 btn(rows()[1],'Ativar coleta').click();
 check(!D.getElementById('rc-confirm').hidden&&D.getElementById('rc-confirm-text').textContent.includes('OLT-2')&&D.getElementById('rc-confirm-text').textContent.includes('--approve-identity'),'ativar abre a confirmacao');
 check(!calls.some(x=>x.o&&x.o.method==='POST'),'nada enviado antes de confirmar');
 D.getElementById('rc-confirm-no').click();await wait(10);
 check(D.getElementById('rc-confirm').hidden&&!calls.some(x=>x.o&&x.o.method==='POST'),'cancelar nao envia');
 btn(rows()[1],'Ativar coleta').click();D.getElementById('rc-confirm-ok').click();await wait(30);
 const b4=body(calls.find(x=>x.o&&x.o.method==='POST'));
 check(b4.device_id==='2'&&b4.ativar==='1'&&b4.confirmar==='1'&&calls.find(x=>x.o&&x.o.method==='POST').u==='/api/rede-equipamentos/coleta','confirmar envia ativar=1 e confirmar=1');
 calls.length=0;btn(rows()[0],'Desativar coleta').click();await wait(30);
 check(D.getElementById('rc-confirm').hidden&&body(calls.find(x=>x.o&&x.o.method==='POST')).ativar==='0','desativar nao pede confirmacao');
 // testar conexao
 calls.length=0;nextPost={status:200,body:{ok:true,message:'O equipamento respondeu.',data:{ok:true,codigo:'ok',mensagem:'O equipamento respondeu.',leituras:{sysName:'OLT <b>X</b>',sysDescr:'Fake',sysUpTime:'1d 00h 05min'},duracao_ms:900}}};
 btn(rows()[0],'Testar conexão').click();await wait(40);
 const tc=calls.find(x=>x.o&&x.o.method==='POST');check(tc.u==='/api/rede-equipamentos/testar'&&body(tc).device_id==='1','POST em /testar com device_id');
 const box=D.querySelector('[data-test-result=ok]');check(box&&box.textContent.includes('sysName')&&box.textContent.includes('OLT <b>X</b>')&&!box.querySelector('b'),'leituras do teste como texto');
 nextPost={status:429,body:{ok:false,message:'Aguarde 20 s para testar este equipamento de novo.',data:{aguarde_s:20}}};
 btn(rows()[0],'Testar conexão').click();await wait(30);
 check(D.getElementById('rc-msg').textContent.startsWith('Aguarde 20 s')&&D.getElementById('rc-msg').dataset.tone==='warn','limite de 30 s mostra o aviso');
 nextPost={status:200,body:{ok:false,message:'O equipamento não respondeu a tempo.',data:{ok:false,codigo:'sem_resposta',mensagem:'O equipamento não respondeu a tempo.',leituras:{},duracao_ms:2100}}};
 btn(rows()[0],'Testar conexão').click();await wait(30);
 check(D.querySelector('[data-test-result=erro]')&&D.querySelector('[data-test-result=erro]').textContent.includes('não respondeu'),'erro do teste sem expor nada alem da mensagem');
 {
  const w2=new JSDOM(html,{runScripts:'outside-only',url:'http://localhost/rede-equipamentos?no=43',virtualConsole:vc,pretendToBeVisual:true}).window;
  w2.HTMLElement.prototype.scrollIntoView=function(){};
  w2.fetch=async ()=>({ok:true,status:200,json:async()=>({ok:true,data:fx})});
  w2.eval(fs.readFileSync(path.join(root,'public/assets/js/rede-equipamentos.js'),'utf8'));await wait(30);
  const f2=[...w2.document.querySelectorAll('#rc-table tbody tr')].filter(r=>r.classList.contains('rc-focus'));
  check(f2.length===1&&f2[0].getAttribute('data-node')==='43','?no=43 (busca global) destaca a OLT');
 }
 check(errors.length===0,'sem erros de script: '+errors.join('|'));
 console.log('RedeEquipamentos: '+n+' verificacoes, zero falhas');
})().catch(e=>{console.error(e.message);process.exitCode=1;});
