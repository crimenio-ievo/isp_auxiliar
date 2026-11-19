// jsdom: /rede-credenciais, /rede-modelos e /rede-inventario. Fixtures de TESTE; fetch simulado. O segredo digitado nunca reaparece na pagina.
const fs=require('fs'),path=require('path'),{execFileSync}=require('child_process');
const {JSDOM,VirtualConsole}=require('../map/node_modules/jsdom');
const root=path.resolve(__dirname,'../..');
const wait=ms=>new Promise(r=>setTimeout(r,ms));let n=0;
function check(ok,msg){n++;if(!ok)throw new Error('FALHA: '+msg);}
function boot(section,router){
  const html=execFileSync('php',[path.join(__dirname,'render_catalogo.php'),section],{encoding:'utf8'});
  const errors=[],vc=new VirtualConsole();vc.on('jsdomError',e=>errors.push(String(e)));
  const w=new JSDOM(html,{runScripts:'outside-only',url:'http://localhost/x',virtualConsole:vc,pretendToBeVisual:true}).window;
  w.HTMLElement.prototype.scrollIntoView=function(){};w.confirm=()=>true;
  const calls=[];
  w.fetch=async (u,o)=>{calls.push({u,o});const r=router(u,o)||{status:200,body:{ok:true,message:'Feito.'}};return {ok:r.status<400,status:r.status,json:async()=>r.body};};
  w.eval(fs.readFileSync(path.join(root,'public/assets/js/rede-catalogo.js'),'utf8'));
  return {w,D:w.document,calls,errors};
}
const body=c=>Object.fromEntries(new URLSearchParams(c.o.body));
const posts=c=>c.filter(x=>x.o&&x.o.method==='POST');
(async()=>{
 const SEGREDO='senha-que-nunca-volta-99';
 // ---------------- credenciais ----------------
 {
  const cred={id:1,nome:'OLT 89 <b>x</b>',tipo:'snmp_v2c',usuario:null,definida_em:'2026-11-19 15:00:00',definida_por:'gestor',criada_em:'2026-11-19 15:00:00',versao_chave:1,equipamentos:1};
  let key=true;
  const t=boot('credenciais',(u,o)=>{if(o&&o.method==='POST')return null;return {status:200,body:{ok:true,data:{chave_disponivel:key,tabela_disponivel:true,tipos:['snmp_v2c','snmp_v3','ssh','telnet','http'],credenciais:[cred]}}};});
  const D=t.D;await wait(30);
  const row=D.querySelector('#cg-table tbody tr');
  check(row.textContent.includes('definida em 19/11/2026 15:00 por gestor')&&!row.querySelector('b')&&row.textContent.includes('OLT 89 <b>x</b>'),'linha: "definida em DATA por USUARIO", nome escapado (sem HTML)');
  check([...D.querySelectorAll('#cg-table thead th')].map(x=>x.textContent).join('|')==='Nome|Tipo|Usuário|Segredo|Equipamentos|Ações'&&[...row.querySelectorAll('button')].map(x=>x.textContent).join('|')==='Substituir|Excluir','colunas e acoes (Substituir/Excluir; nunca "mostrar segredo")');
  check(D.getElementById('cg-warn').hidden&&!D.getElementById('cg-new').disabled,'com chave: sem aviso e cadastro liberado');
  D.getElementById('cg-new').click();
  const pw=D.querySelector('#cg-form input[name="segredo"]');
  check(pw.type==='password'&&pw.autocomplete==='new-password','campo do segredo e do tipo senha, sem autocompletar');
  D.querySelector('#cg-form input[name="nome"]').value='Nova';pw.value=SEGREDO;
  D.getElementById('cg-form').dispatchEvent(new t.w.Event('submit',{cancelable:true}));await wait(40);
  const p=posts(t.calls)[0];
  check(p.u==='/api/rede-credenciais'&&p.o.headers['X-CSRF-Token']==='TOKEN-TESTE'&&body(p).nome==='Nova'&&body(p).segredo===SEGREDO&&body(p).tipo==='snmp_v2c','criar: POST com CSRF, nome, tipo e segredo (so no envio)');
  check(!D.documentElement.outerHTML.includes(SEGREDO)&&D.querySelector('#cg-form input[name="segredo"]')&&D.querySelector('#cg-form-card').hidden,'depois de salvar o segredo nao esta em nenhum ponto do HTML e o formulario fecha');
  [...D.querySelectorAll('#cg-table tbody button')][0].click();
  check(D.getElementById('cg-form-title').textContent.startsWith('Substituir segredo')&&D.querySelector('#cg-form input[name="nome"]').disabled&&D.querySelector('#cg-form input[name="segredo"]').value==='','substituir: abre vazio, nome travado, titulo "Substituir segredo"');
  D.querySelector('#cg-form input[name="segredo"]').value='novo-'+SEGREDO;t.calls.length=0;
  D.getElementById('cg-form').dispatchEvent(new t.w.Event('submit',{cancelable:true}));await wait(40);
  const rp=posts(t.calls)[0];check(rp.u==='/api/rede-credenciais/substituir'&&body(rp).id==='1'&&body(rp).segredo==='novo-'+SEGREDO&&!('nome' in body(rp)),'substituir: POST /substituir so com id e novo segredo');
  check(!D.documentElement.outerHTML.includes(SEGREDO),'apos substituir: nenhum segredo no HTML');
  t.calls.length=0;[...D.querySelectorAll('#cg-table tbody button')][1].click();await wait(40);
  check(posts(t.calls)[0].u==='/api/rede-credenciais/excluir'&&body(posts(t.calls)[0]).id==='1','excluir: POST /excluir com confirmacao');
  check(t.errors.length===0,'sem erros: '+t.errors.join('|'));
  const nk=boot('credenciais',()=>({status:200,body:{ok:true,data:{chave_disponivel:false,tabela_disponivel:true,tipos:['snmp_v2c'],credenciais:[]}}}));await wait(30);
  check(!nk.D.getElementById('cg-warn').hidden&&nk.D.getElementById('cg-warn').textContent.includes('NET_SECRET_KEY')&&nk.D.getElementById('cg-new').disabled,'sem NET_SECRET_KEY: aviso e cadastro bloqueado');
  const nt=boot('credenciais',()=>({status:200,body:{ok:true,data:{chave_disponivel:true,tabela_disponivel:false,tipos:[],credenciais:[]}}}));await wait(30);
  check(nt.D.getElementById('cg-warn').textContent.includes('migration 046')&&nt.D.getElementById('cg-new').disabled,'sem a tabela: aviso da migration 046');
  const er=boot('credenciais',()=>({status:403,body:{ok:false,message:'Acesso negado.'}}));await wait(30);
  check(er.D.getElementById('cg-msg').textContent==='Acesso negado.','erro 403: mostra a mensagem');
 }
 // ---------------- modelos ----------------
 {
  const m={id:5,fabricante:'Hioso',modelo:'HA7004S',tipo:'olt',tecnologia:'EPON',portas:{pon:4},perfil_padrao:'hioso-epon-25355-4-4-v3',ficha:{},observacao:null,em_uso:1};
  const m2={id:6,fabricante:'V-SOL',modelo:'a identificar',tipo:'olt',tecnologia:null,portas:{},perfil_padrao:'vsol',ficha:{a:1},observacao:null,em_uso:0};
  const t=boot('modelos',(u,o)=>{if(o&&o.method==='POST')return null;return {status:200,body:{ok:true,data:{modelos:[m,m2],tipos:['olt','onu','ont','switch','roteador','radio','outro'],familias_portas:['pon','sfp','eth','fo']}}};});
  const D=t.D;await wait(30);
  const rows=[...D.querySelectorAll('#cg-table tbody tr')];
  check(rows.length===2&&rows[0].textContent.includes('4 PON')&&rows[0].textContent.includes('EPON')&&rows[1].textContent.includes('—')&&rows[1].textContent.includes('a identificar'),'catalogo: portas por familia, tecnologia e "a identificar" sem inventar dados');
  D.getElementById('cg-new').click();
  check(['fabricante','modelo','tipo','tecnologia','portas_pon','portas_sfp','portas_eth','portas_fo','perfil_padrao','ficha','observacao'].every(k=>D.querySelector('#cg-form [name="'+k+'"]')),'formulario do modelo com todos os campos');
  D.querySelector('#cg-form [name="fabricante"]').value='Fab';D.querySelector('#cg-form [name="modelo"]').value='Mod';D.querySelector('#cg-form [name="portas_pon"]').value='8';
  D.getElementById('cg-form').dispatchEvent(new t.w.Event('submit',{cancelable:true}));await wait(40);
  const p=posts(t.calls)[0];check(p.u==='/api/rede-modelos'&&body(p).fabricante==='Fab'&&body(p).portas_pon==='8'&&body(p).tipo==='olt','criar modelo: POST com campos');
  [...rows[0].querySelectorAll('button')][0].click();
  check(D.querySelector('#cg-form [name="portas_pon"]').value==='4'&&D.querySelector('#cg-form [name="fabricante"]').value==='Hioso'&&D.getElementById('cg-id').value==='5','editar: formulario preenchido com o modelo');
  t.calls.length=0;[...rows[1].querySelectorAll('button')][1].click();await wait(40);
  check(posts(t.calls)[0].u==='/api/rede-modelos/excluir'&&body(posts(t.calls)[0]).id==='6','excluir modelo');
  check(t.errors.length===0,'sem erros (modelos)');
 }
 // ---------------- inventario ----------------
 {
  const eq={id:9,modelo_id:5,fabricante:'Hioso',modelo:'HA7004S',tipo:'olt',serie:'SN-1',mac:'00:2E:C7:AA:BB:01',patrimonio:null,situacao:'instalado',local_tipo:'no_mapa',no_id:42,no_nome:'OLT-001',local_obs:null,observacao:null};
  const t=boot('inventario',(u,o)=>{if(o&&o.method==='POST')return null;
    if(u.includes('historico=9'))return {status:200,body:{ok:true,data:{historico:[{moved_at:'2026-11-19 15:30:00',from_status:null,to_status:'instalado',note:'cadastro',actor:'gestor'}]}}};
    return {status:200,body:{ok:true,data:{equipamentos:[eq],modelos:[{id:5,rotulo:'Hioso HA7004S',tipo:'olt'}],nos:[{id:42,nome:'OLT-001',tipo:'olt'}],situacoes:['estoque','instalado','manutencao','baixado'],locais:['no_mapa','estoque','cliente','outro'],historico:null}}};});
  const D=t.D;await wait(30);
  const row=D.querySelector('#cg-table tbody tr');
  check(row.textContent.includes('Hioso HA7004S')&&row.textContent.includes('SN-1')&&row.textContent.includes('OLT-001')&&row.textContent.includes('—'),'inventario: modelo, serie, local no mapa e campos vazios como "—"');
  D.getElementById('cg-new').click();
  check(D.querySelector('#cg-form select[name="situacao"]').options.length===4&&D.querySelector('#cg-form select[name="no_id"]').options.length===2,'formulario: situacoes e elementos do mapa');
  D.querySelector('#cg-form [name="serie"]').value='SN-2';D.querySelector('#cg-form [name="situacao"]').value='estoque';D.querySelector('#cg-form [name="local_tipo"]').value='estoque';
  D.getElementById('cg-form').dispatchEvent(new t.w.Event('submit',{cancelable:true}));await wait(40);
  const p=posts(t.calls)[0];check(p.u==='/api/rede-inventario'&&body(p).serie==='SN-2'&&body(p).situacao==='estoque'&&body(p).modelo_id==='5','criar equipamento: POST com modelo, serie e situacao');
  [...row.querySelectorAll('button')][1].click();await wait(40);
  check(D.getElementById('cg-hist').hidden===false&&D.getElementById('cg-hist-list').textContent.includes('19/11/2026 15:30')&&D.getElementById('cg-hist-list').textContent.includes('instalado'),'historico de movimentacao');
  check(t.errors.length===0,'sem erros (inventario)');
 }
 console.log('RedeCatalogo: '+n+' verificacoes, zero falhas');
})().catch(e=>{console.error(e.message);process.exitCode=1;});
