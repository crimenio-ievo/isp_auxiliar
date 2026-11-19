// jsdom: ficha da CTO (vincular/mover/desvincular) e pagina de vinculos de ONU (confirmar/recusar). Fixtures de TESTE; fetch simulado.
const fs=require('fs'),path=require('path'),{execFileSync}=require('child_process');
const {JSDOM,VirtualConsole}=require('../map/node_modules/jsdom');
const root=path.resolve(__dirname,'../..');
const wait=ms=>new Promise(r=>setTimeout(r,ms));let n=0;
function check(ok,msg){n++;if(!ok)throw new Error('FALHA: '+msg);}
function boot(page,mode,jsFile,router){
  const html=execFileSync('php',[path.join(__dirname,'render_vinculos.php'),page,mode],{encoding:'utf8'});
  const errors=[],vc=new VirtualConsole();vc.on('jsdomError',e=>errors.push(String(e)));
  const w=new JSDOM(html,{runScripts:'outside-only',url:'http://localhost/x',virtualConsole:vc,pretendToBeVisual:true}).window;
  w.HTMLElement.prototype.scrollIntoView=function(){};
  const calls=[];
  w.fetch=async (u,o)=>{calls.push({u,o});const r=router(u,o)||{status:200,body:{ok:true,message:'Feito.'}};return {ok:r.status<400,status:r.status,json:async()=>r.body};};
  w.eval(fs.readFileSync(path.join(root,'public/assets/js/'+jsFile),'utf8'));
  return {w,D:w.document,calls,errors};
}
const body=c=>Object.fromEntries(new URLSearchParams(c.o.body));
const posts=c=>c.filter(x=>x.o&&x.o.method==='POST');
const cto={id:33,nome:'CTO-001',lat:-20.841435,lng:-42.803899,capacidade:4,portas:{total:4,ocupadas:2,livres:1,indisponiveis:1,em_ligacao:1,com_cliente:1},clientes:1,origem:'OLT-001 · PON 1',observacao:'',situacao:'ativo'};
const ficha={cto,pode_editar:true,vinculos_disponiveis:true,sem_porta:[],portas:[
  {id:101,num:1,rotulo:'Porta 1',status:'livre',ligacao:'OLT-001 → CTO',cliente:null},
  {id:102,num:2,rotulo:'Porta 2',status:'livre',ligacao:null,cliente:{id:7,login:'joao',nome:'João <b>Teste</b>',origem:'sugestao_mac',confianca:'alta',confirmado_por:'g',confirmado_em:'2026-11-19 10:00:00',cto:{id:33,nome:'CTO-001',porta:'Porta 2'},onu:{id:5,pon:1,onu_index:3}}},
  {id:103,num:3,rotulo:'Porta 3',status:'livre',ligacao:null,cliente:null},
  {id:104,num:4,rotulo:'Porta 4',status:'defeito',ligacao:null,cliente:null}]};
(async()=>{
 // ---------------- ficha (gestor) ----------------
 {
  let nextPost=null;
  const t=boot('ficha','edit','rede-cto-ficha.js',(u,o)=>{
    if(o&&o.method==='POST'){return typeof nextPost==='function'?nextPost(o):nextPost;}
    if(u.indexOf('/api/rede-vinculos/clientes')===0){return {status:200,body:{ok:true,data:[{login:'maria',nome:'Maria <i>X</i>',bairro:'Centro',cidade:'C',status:'ativo',vinculo:null},{login:'pedro',nome:'Pedro',bairro:'',cidade:'',status:'ativo',vinculo:{id:9,login:'pedro',nome:'Pedro',cto:{id:40,nome:'CTO-9',porta:'Porta 1'},onu:null}}]}};}
    return {status:200,body:{ok:true,data:ficha}};});
  const D=t.D;await wait(30);
  check(D.getElementById('cf-title').textContent==='CTO CTO-001'&&D.getElementById('cf-sub').textContent.includes('OLT-001 · PON 1')&&D.getElementById('cf-map').getAttribute('href')==='/mapa?no=33','cabecalho da ficha');
  check(t.calls[0].u==='/api/rede-ctos/ficha?id=33','le a ficha da CTO do data-cto');
  const rows=[...D.querySelectorAll('#cf-table tbody tr')];check(rows.length===4,'4 portas');
  check(D.getElementById('cf-summary').textContent.startsWith('4 porta(s) · 1 em ligação · 1 com cliente · 1 livre(s) · 1 indisponível(is)'),'resumo: livres sem contar a porta com defeito');
  check(rows[0].textContent.includes('Em ligação')&&rows[0].textContent.includes('OLT-001 → CTO')&&![...rows[0].querySelectorAll('button')].length,'porta em ligacao: sem acao');
  check(rows[1].textContent.includes('João <b>Teste</b>')&&!rows[1].querySelector('b')&&rows[1].querySelector('a[href="/clientes/detalhe?login=joao"]')&&rows[1].querySelector('a[href="/rede-fibra?onu=5"]').textContent==='PON 1 · ONU 3','cliente na porta como texto, link do perfil e da ONU');
  check(rows[2].textContent.includes('Livre')&&rows[2].textContent.includes('sem cliente')&&[...rows[2].querySelectorAll('button')].map(b=>b.textContent).join()==='Vincular cliente','porta livre: Vincular cliente');
  check(rows[3].textContent.includes('Defeito')&&![...rows[3].querySelectorAll('button')].length,'porta com defeito: sem acao');
  check([...rows[1].querySelectorAll('button')].map(b=>b.textContent).join()==='Trocar de porta,Desvincular','porta com cliente: trocar e desvincular');
  // vincular: busca
  [...rows[2].querySelectorAll('button')][0].click();
  check(!D.getElementById('cf-picker').hidden&&D.getElementById('cf-picker-title').textContent==='Vincular cliente à Porta 3','abre o seletor');
  const q=D.getElementById('cf-q');q.value='ma';q.dispatchEvent(new t.w.Event('input'));await wait(320);
  check(!t.calls.some(c=>c.u.indexOf('/api/rede-vinculos/clientes')===0),'menos de 3 caracteres nao busca');
  q.value='mar';q.dispatchEvent(new t.w.Event('input'));await wait(320);
  const res=[...D.querySelectorAll('#cf-results .rc-result')];
  check(t.calls.some(c=>c.u==='/api/rede-vinculos/clientes?q=mar')&&res.length===2&&res[0].textContent.includes('Maria <i>X</i>')&&!D.querySelector('#cf-results i')&&res[1].textContent.includes('já em CTO-9, Porta 1'),'resultados como texto; cliente ja vinculado marcado');
  // escolher maria
  nextPost={status:200,body:{ok:true,message:'Cliente vinculado a Porta 3.'}};t.calls.length=0;res[0].click();await wait(30);
  let p=posts(t.calls)[0];
  check(p.u==='/api/rede-vinculos/cto'&&p.o.headers['X-CSRF-Token']==='TOKEN-TESTE'&&body(p).cto_id==='33'&&body(p).port_id==='103'&&body(p).login==='maria'&&body(p).mover==='0','POST vincular com CSRF, CTO, porta e login');
  check(D.getElementById('cf-picker').hidden&&D.getElementById('cf-msg').textContent==='Cliente vinculado a Porta 3.','fecha o seletor e mostra a mensagem');
  // escolher quem ja tem vinculo -> 409 mover -> confirmar
  [...D.querySelectorAll('#cf-table tbody tr')][2].querySelector('button').click();q.value='mar';q.dispatchEvent(new t.w.Event('input'));await wait(320);
  nextPost=o=>body({o}).mover==='1'?{status:200,body:{ok:true,message:'Cliente movido para Porta 3.'}}:{status:409,body:{ok:false,codigo:'mover',message:'Pedro já está em CTO-9, Porta 1. Confirme para mover para CTO-001, Porta 3.'}};
  t.calls.length=0;[...D.querySelectorAll('#cf-results .rc-result')][1].click();await wait(30);
  check(!D.getElementById('cf-confirm-box').hidden&&D.getElementById('cf-confirm-text').textContent.includes('CTO-9, Porta 1')&&posts(t.calls).length===1,'409 mover abre confirmacao');
  D.getElementById('cf-confirm-ok').click();await wait(30);
  check(posts(t.calls).length===2&&body(posts(t.calls)[1]).mover==='1'&&body(posts(t.calls)[1]).login==='pedro','confirmar reenvia com mover=1');
  // erro 409 porta ocupada
  [...D.querySelectorAll('#cf-table tbody tr')][2].querySelector('button').click();q.value='mar';q.dispatchEvent(new t.w.Event('input'));await wait(320);
  nextPost={status:409,body:{ok:false,message:'Porta 3 já tem o cliente João Teste vinculado.'}};[...D.querySelectorAll('#cf-results .rc-result')][0].click();await wait(30);
  check(D.getElementById('cf-msg').dataset.tone==='error'&&D.getElementById('cf-msg').textContent.includes('já tem o cliente'),'erro do servidor mostrado');
  D.getElementById('cf-picker-close').click();
  // trocar de porta
  nextPost={status:200,body:{ok:true,message:'Cliente movido para Porta 3.'}};t.calls.length=0;
  [...D.querySelectorAll('#cf-table tbody tr')][1].querySelectorAll('button')[0].click();
  const targets=[...D.querySelectorAll('#cf-results .rc-result')].map(b=>b.textContent);
  check(D.getElementById('cf-picker-title').textContent.startsWith('Mover João')&&targets.join()==='Porta 3','trocar de porta: so portas livres e utilizaveis');
  D.querySelector('#cf-results .rc-result').click();await wait(30);
  p=posts(t.calls)[0];check(body(p).port_id==='103'&&body(p).login==='joao'&&body(p).mover==='1','trocar envia login e mover=1');
  // desvincular
  t.calls.length=0;[...D.querySelectorAll('#cf-table tbody tr')][1].querySelectorAll('button')[1].click();
  check(!D.getElementById('cf-confirm-box').hidden&&D.getElementById('cf-confirm-text').textContent.includes('vínculo com a ONU é mantido')&&posts(t.calls).length===0,'desvincular pede confirmacao e avisa que a ONU fica');
  D.getElementById('cf-confirm-ok').click();await wait(30);
  p=posts(t.calls)[0];check(p.u==='/api/rede-vinculos/remover'&&body(p).id==='7'&&body(p).parte==='cto','POST remover parte=cto');
  check(t.errors.length===0,'sem erros (ficha): '+t.errors.join('|'));
 }
 // ---------------- ficha (somente leitura / sem tabela) ----------------
 {
  const t=boot('ficha','read','rede-cto-ficha.js',(u)=>({status:200,body:{ok:true,data:Object.assign({},ficha,{pode_editar:false})}}));await wait(30);
  check(t.D.querySelectorAll('#cf-table button').length===0&&t.D.querySelectorAll('.rc-tab').length===1,'tecnico: sem botoes e so a aba CTOs');
  const t2=boot('ficha','edit','rede-cto-ficha.js',(u)=>({status:200,body:{ok:true,data:Object.assign({},ficha,{vinculos_disponiveis:false})}}));await wait(30);
  check(t2.D.querySelectorAll('#cf-table button').length===0&&t2.D.getElementById('cf-summary').textContent.includes('vínculos ainda não disponíveis'),'sem a tabela de vinculos: sem botoes e aviso');
  const t3=boot('ficha','edit','rede-cto-ficha.js',(u)=>({status:404,body:{ok:false,message:'CTO não encontrada.'}}));await wait(30);
  check(t3.D.getElementById('cf-msg').textContent==='CTO não encontrada.','CTO inexistente mostra a mensagem');
 }
 // ---------------- vinculos de ONU ----------------
 {
  const data={sugestoes:[
    {onu:{id:1,mac:'006D61AA0010',pon:1,onu_index:1,olt:'OLT-<b>1</b>'},candidatos:[{login:'joao',nome:'João Teste',distancia:1,confianca:'alta',vinculado:false}]},
    {onu:{id:2,mac:'006D61BB0020',pon:1,onu_index:2,olt:'OLT-1'},candidatos:[{login:'maria',nome:null,distancia:9,confianca:'baixa',vinculado:true},{login:'pedro',nome:'Pedro',distancia:-8,confianca:'baixa',vinculado:false}]}],
    sem_candidato:1,sem_vinculo:3,logins_com_sessao:591,caller_invalido:0,janela_dias:30,radacct_referencia:'2026-10-13 09:45:43',janela_inicio:'2026-09-13 09:45:43',regra:'MAC do PPPoE igual...'};
  let nextPost=null,listCalls=0;
  const t=boot('vinculos','edit','rede-vinculos.js',(u,o)=>{if(o&&o.method==='POST'){return nextPost;}listCalls++;return {status:200,body:{ok:true,data}};});
  const D=t.D;await wait(30);
  const rows=[...D.querySelectorAll('#vc-table tbody tr')];
  check(rows.length===2&&D.getElementById('vc-summary').textContent==='3 ONU(s) sem vínculo · 2 com sugestão · 1 sem candidato pelo MAC · 591 login(s) ativos com sessão','resumo');
  check(D.getElementById('vc-note').textContent.includes('13/10/2026 09:45')&&D.getElementById('vc-note').textContent.includes('30 dias'),'mostra a data de referencia do radacct e a janela');
  check(rows[0].textContent.includes('OLT-<b>1</b> · PON 1')&&!rows[0].querySelector('b')&&rows[0].textContent.includes('00:6D:61:AA:00:10')&&rows[0].textContent.includes('+1 no último octeto')&&rows[0].textContent.includes('Confiança Alta')&&rows[0].querySelector('a[href="/clientes/detalhe?login=joao"]'),'sugestao: ONU, MAC, diferenca, confianca e link do cliente');
  check(rows[1].textContent.includes('Confiança Baixa')&&rows[1].textContent.includes('-8 no último octeto')&&rows[1].textContent.includes('maria')&&rows[1].textContent.includes('já tem vínculo'),'ambigua: dois candidatos, "ja tem vinculo" marcado');
  check(D.querySelector('a[href="/rede-fibra?onu=1"]'),'link para a ONU na /rede-fibra');
  nextPost={status:200,body:{ok:true,message:'ONU vinculada a João Teste.'}};t.calls.length=0;
  rows[0].querySelector('.rc-cand button').click();await wait(40);
  let p=posts(t.calls)[0];check(p.u==='/api/rede-vinculos/onu'&&p.o.headers['X-CSRF-Token']==='TOKEN-TESTE'&&body(p).onu_id==='1'&&body(p).login==='joao'&&body(p).acao==='confirmar'&&body(p).confianca==='alta','confirmar envia onu, login, acao e confianca');
  check(listCalls===2&&D.getElementById('vc-msg').textContent==='ONU vinculada a João Teste.','recarrega as sugestoes depois de confirmar');
  nextPost={status:200,body:{ok:true,message:'Sugestão recusada: ela não aparece mais.'}};t.calls.length=0;
  [...D.querySelectorAll('#vc-table tbody tr')][1].querySelectorAll('.rc-cand')[1].querySelectorAll('button')[1].click();await wait(40);
  p=posts(t.calls)[0];check(body(p).acao==='recusar'&&body(p).login==='pedro'&&body(p).onu_id==='2','recusar envia a acao e o candidato certo');
  nextPost={status:409,body:{ok:false,message:'Essa ONU já está vinculada a João Teste.'}};t.calls.length=0;
  const bs=[...D.querySelectorAll('#vc-table tbody tr')][0].querySelectorAll('.rc-cand button');bs[0].click();await wait(40);
  check(D.getElementById('vc-msg').dataset.tone==='error'&&!bs[0].disabled,'erro 409: mostra e reabilita os botoes');
  check(t.errors.length===0,'sem erros (vinculos): '+t.errors.join('|'));
  const e=boot('vinculos','edit','rede-vinculos.js',()=>({status:503,body:{ok:false,message:'Não foi possível consultar o MkAuth agora.'}}));await wait(30);
  check(e.D.getElementById('vc-msg').textContent==='Não foi possível consultar o MkAuth agora.'&&e.D.getElementById('vc-summary').textContent==='','MkAuth fora: mensagem e sem lista');
 }
 console.log('RedeVinculos: '+n+' verificacoes, zero falhas');
})().catch(e=>{console.error(e.message);process.exitCode=1;});
