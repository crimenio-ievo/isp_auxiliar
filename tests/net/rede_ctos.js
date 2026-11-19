// jsdom: tela /rede-ctos (HTML do PHP + public/assets/js/rede-ctos.js). Fixtures de TESTE; fetch e geolocalizacao simulados.
const fs=require('fs'),path=require('path'),{execFileSync}=require('child_process');
const {JSDOM,VirtualConsole}=require('../map/node_modules/jsdom');
const root=path.resolve(__dirname,'../..');
const js=fs.readFileSync(path.join(root,'public/assets/js/rede-ctos.js'),'utf8');
const wait=ms=>new Promise(r=>setTimeout(r,ms));let n=0;
function check(ok,msg){n++;if(!ok)throw new Error('FALHA: '+msg);}
const fx={
  ctos:[
    {id:1,nome:'<img src=x onerror=window.__xss=1>CTO-1',lat:-20.8455534,lng:-42.80236,capacidade:16,portas:{total:16,ocupadas:3,livres:13,em_ligacao:3,com_cliente:0},clientes:null,origem:'OLT-001 · PON 1',observacao:'poste',situacao:'ativo'},
    {id:2,nome:'CTO-2',lat:null,lng:null,capacidade:8,portas:{total:8,ocupadas:0,livres:8,em_ligacao:0,com_cliente:0},clientes:null,origem:null,observacao:'',situacao:'ativo'},
    {id:3,nome:'CTO-3',lat:-20.9,lng:-42.9,capacidade:8,portas:{total:8,ocupadas:2,livres:6,em_ligacao:1,com_cliente:1},clientes:4,onus:1,origem:'POP-A',observacao:'',situacao:'ativo'}
  ],
  pode_editar:true,origens:{sites:[{id:8,nome:'POP-A',tipo:'pop'}],pons:[{id:84,rotulo:'OLT-001 · PON 1',livre:false},{id:85,rotulo:'OLT-001 · PON 2',livre:true}]},vinculos_disponiveis:false,limites:{capacidade:[1,144],proximidade_m:15,csv_linhas:500}
};
function boot(mode){
  const html=execFileSync('php',[path.join(__dirname,'render_ctos.php'),mode],{encoding:'utf8'});
  const errors=[],vc=new VirtualConsole();vc.on('jsdomError',e=>errors.push(String(e)));
  const w=new JSDOM(html,{runScripts:'outside-only',url:'http://localhost/rede-ctos?cto=2',virtualConsole:vc,pretendToBeVisual:true}).window;
  w.HTMLElement.prototype.scrollIntoView=function(){};
  const calls=[];let next=null;
  w.fetch=async (u,o)=>{calls.push({u,o});
    if(!o||!o.method||o.method==='GET'){return {ok:true,status:200,json:async()=>({ok:true,data:fx})};}
    const r=(typeof next==='function'?next(o):next)||{status:200,body:{ok:true,message:'Feito.'}};
    return {ok:r.status<400,status:r.status,json:async()=>r.body};};
  return {w,D:w.document,calls,errors,setNext:v=>{next=v;},eval:()=>w.eval(js)};
}
const body=c=>Object.fromEntries(new URLSearchParams(c.o.body));
const posts=c=>c.filter(x=>x.o&&x.o.method==='POST');
(async()=>{
 // ---------- somente leitura (tecnico) ----------
 {const t=boot('read');t.eval();await wait(30);const D=t.D;
  check(D.querySelectorAll('#ct-table tbody tr').length===3,'tecnico ve as 3 CTOs');
  check(!D.getElementById('ct-form')&&!D.getElementById('ct-new')&&!D.getElementById('ct-import-open'),'tecnico: sem formulario, nova CTO nem importar');
  const btns=[...D.querySelectorAll('#ct-table tbody button')];check(btns.length===0,'tecnico: sem Editar/Excluir');
  check([...D.querySelectorAll('#ct-table tbody a')].every(a=>/^\/(mapa\?no|rede-ctos\/ficha\?id)=\d+$/.test(a.getAttribute('href')))&&D.querySelectorAll('#ct-table tbody a').length===6,'tecnico: so links "Ficha" e "Ver no mapa" (?id= / ?no=)');
  check(D.querySelectorAll('.rc-tab').length===1&&D.querySelector('.rc-tab').textContent==='CTOs','tecnico: so a aba CTOs');
  D.getElementById('ct-filter').value='pop';D.getElementById('ct-filter').dispatchEvent(new t.w.Event('input'));
  check(D.querySelectorAll('#ct-table tbody tr').length===1&&D.getElementById('ct-summary').textContent.includes('1 na busca'),'filtro por origem');
  check(t.errors.length===0,'sem erros (leitura)');}
 // ---------- gestor ----------
 const t=boot('edit');const {w,D,calls}=t;
 w.navigator.geolocation={getCurrentPosition:(ok,err)=>{w.__geo(ok,err);}};
 t.eval();await wait(30);
 const rows=()=>[...D.querySelectorAll('#ct-table tbody tr')];
 const btn=(row,text)=>[...row.querySelectorAll('button,a')].find(b=>b.textContent===text);
 check(rows().length===3&&w.__xss===undefined&&!D.querySelector('#ct-table img')&&rows()[0].textContent.includes('<img src=x'),'lista com nome malicioso como texto');
 const r0=rows()[0].textContent;
 check(rows().some(r=>r.textContent.includes('CTO-3')&&r.textContent.includes('4 · 1 ONU(s)')),'lista: 4 clientes em 1 ONU aparecem separados (clientes x ONUs)');
 check(r0.includes('OLT-001 · PON 1')&&r0.includes('-20.845553, -42.802360')&&r0.includes('16')&&r0.includes('3 ocupadas · 13 livres')&&r0.includes('ainda não lido'),'linha: origem, coordenadas, capacidade, portas e clientes "ainda nao lido"');
 const r1=rows()[1].textContent;check(r1.includes('a identificar')&&r1.includes('sem coordenadas')&&r1.includes('0 ocupadas · 8 livres'),'campos ausentes: "a identificar"/"sem coordenadas"');
 check(rows()[2].textContent.includes('1')&&!rows()[2].textContent.includes('ainda não lido'),'cliente vinculado mostrado quando existe');
 check(rows()[1].classList.contains('rc-focus'),'?cto=2 destaca a linha');
 check(D.getElementById('ct-summary').textContent==='3 CTO(s) · 5 de 32 portas ocupadas','resumo');
 check(btn(rows()[0],'Ver no mapa').getAttribute('href')==='/mapa?no=1'&&btn(rows()[0],'Ficha').getAttribute('href')==='/rede-ctos/ficha?id=1','ver no mapa e ficha');
 // formulario e coordenadas
 D.getElementById('ct-new').click();const f=D.getElementById('ct-form');
 check(!D.getElementById('ct-form-card').hidden&&D.getElementById('ct-form-title').textContent==='Nova CTO','abre formulario');
 const origins=[...D.getElementById('ct-origem').options].map(o=>o.textContent);
 check(origins.join('|')==='Sem origem por enquanto|Site: POP-A (POP)|PON livre: OLT-001 · PON 2','origens: so PON livre');
 const hint=D.getElementById('ct-coord-hint'),coord=f.elements.coordenadas;
 coord.value='-20,8455534 -42,8023600';coord.dispatchEvent(new w.Event('input'));
 check(hint.textContent.includes('Latitude -20.8455534')&&hint.dataset.tone==='ok','cola "lat lng" com virgula decimal');
 coord.dispatchEvent(new w.Event('blur'));check(coord.value==='-20.8455534, -42.80236','blur normaliza para "lat, lng"');
 for(const bad of ['95, 10','0, 0','perto da praça','1,2,3']){coord.value=bad;coord.dispatchEvent(new w.Event('input'));check(hint.dataset.tone==='error','invalida: '+bad);}
 // geolocalizacao
 w.__geo=(ok)=>ok({coords:{latitude:-20.84567891,longitude:-42.80123456,accuracy:12.4}});
 D.getElementById('ct-geo').click();
 check(coord.value==='-20.8456789, -42.8012346'&&hint.textContent.includes('±12 m')&&!hint.textContent.includes('baixa'),'usar minha localizacao preenche e mostra a precisao');
 w.__geo=(ok)=>ok({coords:{latitude:-20.1,longitude:-42.1,accuracy:80}});D.getElementById('ct-geo').click();
 check(hint.textContent.includes('baixa'),'precisao baixa avisada');
 w.__geo=(ok,err)=>err({code:1});D.getElementById('ct-geo').click();
 check(hint.textContent.includes('Permissão de localização negada')&&hint.dataset.tone==='error','permissao negada');
 w.__geo=(ok,err)=>err({code:2});D.getElementById('ct-geo').click();check(hint.textContent.includes('Não foi possível obter'),'falha de localizacao');
 // salvar
 f.elements.nome.value='  CTO-NOVA ';coord.value='-20.85, -42.80';f.elements.capacidade.value='12';f.elements.origem.value='site:8';f.elements.observacao.value='obs';
 calls.length=0;f.dispatchEvent(new w.Event('submit',{cancelable:true}));await wait(30);
 let c=posts(calls)[0],b=body(c);
 check(c.u==='/api/rede-ctos'&&c.o.headers['X-CSRF-Token']==='TOKEN-TESTE'&&b.nome==='CTO-NOVA'&&b.coordenadas==='-20.85, -42.80'&&b.capacidade==='12'&&b.origem==='site:8'&&b.observacao==='obs'&&!('id' in b),'POST de criacao com CSRF e campos');
 check(D.getElementById('ct-form-card').hidden&&D.getElementById('ct-msg').textContent==='Feito.','fecha e mostra a mensagem');
 // 409 proximo -> confirmacao -> reenvio
 D.getElementById('ct-new').click();f.elements.nome.value='CTO-P';coord.value='-20.85, -42.80';
 t.setNext(o=>body({o}).confirmar_proximo==='1'?{status:200,body:{ok:true,message:'CTO criada.'}}:{status:409,body:{ok:false,codigo:'proximo',message:'Já existe CTO a menos de 15 m: CTO-1 (6 m).'}});
 calls.length=0;f.dispatchEvent(new w.Event('submit',{cancelable:true}));await wait(30);
 check(!D.getElementById('ct-confirm-box').hidden&&D.getElementById('ct-confirm-text').textContent.includes('CTO-1 (6 m)')&&posts(calls).length===1,'409 proximo abre a confirmacao sem regravar');
 D.getElementById('ct-confirm-no').click();await wait(10);check(posts(calls).length===1&&!D.getElementById('ct-form-card').hidden,'cancelar mantem o formulario e nao reenvia');
 f.dispatchEvent(new w.Event('submit',{cancelable:true}));await wait(30);D.getElementById('ct-confirm-ok').click();await wait(30);
 check(posts(calls).length===3&&body(posts(calls)[2]).confirmar_proximo==='1'&&D.getElementById('ct-form-card').hidden,'confirmar reenvia com confirmar_proximo=1');
 // erro 422
 D.getElementById('ct-new').click();t.setNext({status:422,body:{ok:false,message:'Nome é obrigatório (até 120 caracteres).'}});
 f.dispatchEvent(new w.Event('submit',{cancelable:true}));await wait(30);
 check(!D.getElementById('ct-form-card').hidden&&D.getElementById('ct-msg').dataset.tone==='error'&&D.getElementById('ct-msg').textContent.startsWith('Nome é obrigatório'),'422 mantem o formulario e mostra o erro');
 t.setNext(null);D.getElementById('ct-cancel').click();
 // editar
 btn(rows()[0],'Editar').click();
 check(D.getElementById('ct-form-title').textContent==='Editar CTO — '+fx.ctos[0].nome&&f.elements.nome.value===fx.ctos[0].nome&&f.elements.coordenadas.value==='-20.8455534, -42.80236'&&f.elements.capacidade.value==='16'&&f.elements.observacao.value==='poste'&&D.getElementById('ct-id').value==='1','edicao carrega os dados');
 check(D.getElementById('ct-origin-field').hidden,'CTO que ja tem origem esconde o campo origem');
 calls.length=0;f.dispatchEvent(new w.Event('submit',{cancelable:true}));await wait(30);
 b=body(posts(calls)[0]);check(b.id==='1'&&!('origem' in b),'edicao envia id e nao envia origem');
 btn(rows()[1],'Editar').click();check(!D.getElementById('ct-origin-field').hidden,'CTO sem origem mostra o campo');
 D.getElementById('ct-cancel').click();
 // excluir
 calls.length=0;btn(rows()[1],'Excluir').click();
 check(!D.getElementById('ct-confirm-box').hidden&&D.getElementById('ct-confirm-text').textContent.includes('CTO-2')&&posts(calls).length===0,'excluir pede confirmacao');
 t.setNext({status:409,body:{ok:false,message:'Não é possível excluir "CTO-2": há 1 ligação(ões) no mapa. Remova-os antes.'}});
 D.getElementById('ct-confirm-ok').click();await wait(30);
 check(posts(calls)[0].u==='/api/rede-ctos/excluir'&&body(posts(calls)[0]).id==='2'&&D.getElementById('ct-msg').textContent.includes('há 1 ligação')&&D.getElementById('ct-msg').dataset.tone==='error','excluir bloqueado mostra o motivo');
 t.setNext(null);
 // importacao CSV
 D.getElementById('ct-import-open').click();
 check(!D.getElementById('ct-import-card').hidden&&D.getElementById('ct-analyze').disabled,'abre importacao; analisar desabilitado sem arquivo');
 const input=D.getElementById('ct-file');
 Object.defineProperty(input,'files',{value:[{text:async()=>'nome,lat,lng\nA,-20.9,-42.9\n'}],configurable:true});
 input.dispatchEvent(new w.Event('change'));await wait(10);check(!D.getElementById('ct-analyze').disabled,'arquivo escolhido habilita analisar');
 const analysis={previa:true,ok:true,linhas:[
   {linha:2,nome:'A',lat:-20.9,lng:-42.9,capacidade:16,observacao:'',status:'ok',mensagens:[]},
   {linha:3,nome:'B <i>x</i>',lat:-20.9,lng:-42.90005,capacidade:16,observacao:'',status:'proximo',mensagens:['A menos de 15 m de: A (5 m).']},
   {linha:4,nome:'CTO-001',lat:-20.8,lng:-42.8,capacidade:16,observacao:'',status:'duplicado',mensagens:['Já existe uma CTO com este nome.']},
   {linha:5,nome:'D',lat:null,lng:null,capacidade:null,observacao:'',status:'erro',mensagens:['Coordenadas inválidas.']}],
   resumo:{total:4,ok:1,erro:1,duplicado:1,proximo:1}};
 t.setNext({status:200,body:{ok:true,message:'Prévia: nada foi gravado.',data:analysis}});
 calls.length=0;D.getElementById('ct-analyze').click();await wait(30);
 check(body(posts(calls)[0]).csv.startsWith('nome,lat,lng')&&!('confirmar' in body(posts(calls)[0])),'analisar envia o CSV sem confirmar');
 const pr=[...D.querySelectorAll('#ct-preview-table tbody tr')];
 check(pr.length===4&&pr[0].textContent.includes('Pronta')&&pr[1].textContent.includes('Muito perto')&&pr[2].textContent.includes('Nome duplicado')&&pr[3].textContent.includes('Erro')&&!D.querySelector('#ct-preview-table i'),'previa linha a linha (nomes como texto)');
 check(D.getElementById('ct-preview-summary').textContent.includes('Nada foi gravado ainda')&&D.getElementById('ct-confirm').textContent==='Confirmar importação de 1 CTO(s)','resumo e botao de confirmar com a contagem');
 const force=D.querySelector('[data-force="3"]');force.checked=true;force.dispatchEvent(new w.Event('change'));
 check(D.getElementById('ct-confirm').textContent==='Confirmar importação de 2 CTO(s)','marcar "mesmo assim" soma a linha');
 t.setNext({status:200,body:{ok:true,message:'2 CTO(s) importada(s); 2 linha(s) ignorada(s).',data:{previa:false,relatorio:{criadas:[{linha:2,id:10,nome:'A'},{linha:3,id:11,nome:'B'}],ignoradas:[{linha:4,nome:'CTO-001',status:'duplicado',motivo:'Já existe uma CTO com este nome.'},{linha:5,nome:'D',status:'erro',motivo:'Coordenadas inválidas.'}]}}}});
 calls.length=0;D.getElementById('ct-confirm').click();
 check(!D.getElementById('ct-confirm-box').hidden&&posts(calls).length===0,'confirmar abre a confirmacao e nao envia ainda');
 D.getElementById('ct-confirm-ok').click();await wait(40);
 const ib=body(posts(calls)[0]);check(ib.confirmar==='1'&&ib.forcar==='3'&&ib.csv.startsWith('nome,lat,lng'),'importar envia confirmar=1 e as linhas forcadas');
 const rep=D.getElementById('ct-report');check(!rep.hidden&&rep.textContent.includes('2 criada(s): A, B')&&rep.textContent.includes('Linha 4 (CTO-001)')&&rep.textContent.includes('Linha 5 (D)'),'relatorio com criadas e ignoradas');
 check(D.getElementById('ct-preview').hidden,'previa some depois de importar');
 // KML/KMZ: bytes em base64 (kml_b64), mesma previa; o CSV nao e enviado junto
 D.getElementById('ct-import-open').click();
 const kmlBytes=Buffer.from('<kml><Placemark><name>K</name><Point><coordinates>-42.1,-20.1</coordinates></Point></Placemark></kml>');
 const ab=kmlBytes.buffer.slice(kmlBytes.byteOffset,kmlBytes.byteOffset+kmlBytes.byteLength);
 Object.defineProperty(input,'files',{value:[{name:'Rede Centro.KMZ',arrayBuffer:async()=>ab}],configurable:true});
 const OrigFR=w.FileReader;w.FileReader=function(){this.readAsArrayBuffer=function(){const me=this;setTimeout(()=>{me.result=new w.Uint8Array(kmlBytes).buffer;me.onload();},0);};};
 input.dispatchEvent(new w.Event('change'));await wait(30);
 check(!D.getElementById('ct-analyze').disabled,'arquivo .kmz habilita analisar');
 t.setNext({status:200,body:{ok:true,message:'Prévia: nada foi gravado.',data:{previa:true,ok:true,origem:'kml',ignorados_sem_ponto:2,linhas:[{linha:2,nome:'K',lat:-20.1,lng:-42.1,capacidade:16,observacao:'',status:'ok',mensagens:[]}],resumo:{total:1,ok:1,erro:0,duplicado:0,proximo:0}}}});
 calls.length=0;D.getElementById('ct-analyze').click();await wait(30);
 const kb=body(posts(calls)[0]);
 check(Buffer.from(kb.kml_b64,'base64').toString()===kmlBytes.toString()&&!('csv' in kb)&&!('confirmar' in kb),'KMZ: envia kml_b64 (bytes originais), sem csv e sem confirmar');
 check(D.getElementById('ct-preview-summary').textContent.includes('2 elemento(s) do KML que não são pontos'),'previa avisa quantos elementos que nao sao pontos foram ignorados');
 check(D.getElementById('ct-import-open').textContent.includes('KML')&&D.getElementById('ct-file').accept.includes('.kmz'),'botao e seletor aceitam KML/KMZ');
 check(t.errors.length===0,'sem erros de script: '+t.errors.join('|'));
 console.log('RedeCtos: '+n+' verificacoes, zero falhas');
})().catch(e=>{console.error(e.message);process.exitCode=1;});
