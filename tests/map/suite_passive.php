<?php
// Fase 2C: splitter e DIO como nós passivos. Só fixtures "ZZ TESTE"; nenhuma tabela de configuração é tocada.
require __DIR__.'/common.php';
$cn=fn($path,$f)=>$P($path,$f);
try {
 $pop=$id($c(['node_type'=>'pop','name'=>'ZZ TESTE POP','lat'=>'-20.85','lng'=>'-42.80']));
 $tw=$id($c(['node_type'=>'tower','name'=>'ZZ TESTE TORRE','lat'=>'-20.86','lng'=>'-42.81']));
 $cto=$id($c(['node_type'=>'cto','name'=>'ZZ TESTE CTO','lat'=>'-20.84','lng'=>'-42.80','attrs'=>'{"capacity":4}']));
 $cto2=$id($c(['node_type'=>'cto','name'=>'ZZ TESTE CTO B','lat'=>'-20.83','lng'=>'-42.79','attrs'=>'{"capacity":4}']));
 $ceo=$id($c(['node_type'=>'ceo','name'=>'ZZ TESTE CEO','lat'=>'-20.82','lng'=>'-42.78']));
 $olt=$id($c(['node_type'=>'olt','name'=>'ZZ TESTE OLT','parent_node_id'=>$pop,'ports_pon'=>'2']));
 $spl=fn($parent,$razao,$name='ZZ TESTE SPL')=>$c(['node_type'=>'splitter','name'=>$name,'parent_node_id'=>$parent,'attrs'=>json_encode(['razao'=>$razao])]);

 // --- pais válidos
 foreach(['POP'=>$pop,'torre'=>$tw,'CTO'=>$cto,'CEO'=>$ceo] as $k=>$par){ $r=$spl($par,'1x4'); chk("splitter aceita pai $k",$r[0]===200,$msg($r)); }
 $r=$c(['node_type'=>'dio','name'=>'ZZ TESTE DIO','parent_node_id'=>$pop,'attrs'=>'{"capacidade":12}']); chk('DIO aceita pai POP',$r[0]===200,$msg($r));
 $r=$c(['node_type'=>'dio','name'=>'ZZ TESTE DIO T','parent_node_id'=>$tw,'attrs'=>'{"capacidade":24}']); chk('DIO aceita pai torre',$r[0]===200,$msg($r));
 $r=$c(['node_type'=>'dio','name'=>'ZZ TESTE DIO 2','parent_node_id'=>$pop,'attrs'=>'{"capacidade":24}']); chk('vários DIO no mesmo site',$r[0]===200);
 $n=$r[1]['data']['node']; chk('DIO sem coordenada própria',$n['lat']===null&&$n['lng']===null);
 // --- pais inválidos
 $r=$spl($olt,'1x4'); chk('splitter em OLT 422',$r[0]===422,$msg($r));
 $r=$spl(null,'1x4'); chk('splitter sem pai 422',$r[0]===422,$msg($r));
 $r=$c(['node_type'=>'dio','name'=>'ZZ TESTE DIO X','parent_node_id'=>$cto,'attrs'=>'{"capacidade":12}']); chk('DIO em CTO 422',$r[0]===422,$msg($r));
 $r=$c(['node_type'=>'dio','name'=>'ZZ TESTE DIO X','parent_node_id'=>$ceo,'attrs'=>'{"capacidade":12}']); chk('DIO em CEO 422',$r[0]===422,$msg($r));
 $r=$c(['node_type'=>'dio','name'=>'ZZ TESTE DIO X','attrs'=>'{"capacidade":12}']); chk('DIO sem pai 422',$r[0]===422);
 $r=$c(['node_type'=>'dio','name'=>'ZZ TESTE DIO X','parent_node_id'=>$pop,'attrs'=>'{"capacidade":13}']); chk('capacidade DIO fora da lista 422',$r[0]===422,$msg($r));
 $r=$spl($pop,'1x3'); chk('razão inválida 422',$r[0]===422,$msg($r));
 $r=$c(['node_type'=>'splitter','name'=>'ZZ TESTE SPL','parent_node_id'=>$pop]); chk('splitter sem razão 422',$r[0]===422);
 $r=$spl($pop,'25/75'); chk('desbalanceado fora da lista aprovada 422',$r[0]===422);
 // --- CTO/CEO aceitam filho só splitter
 foreach([['olt',$cto],['switch',$ceo],['tower',$cto],['pop',$ceo],['cto',$cto],['ceo',$ceo]] as [$t,$par]){
  $r=$c(['node_type'=>$t,'name'=>'ZZ TESTE X','parent_node_id'=>$par,'lat'=>'1','lng'=>'1']); chk("filho $t em CTO/CEO continua 422",$r[0]===422,$msg($r)); }
 $r=$e(['id'=>$cto2,'parent_node_id'=>$cto]); chk('CTO não pode virar filha de CTO 422',$r[0]===422);
 // --- geração 1x8
 $s8=$id($r=$spl($pop,'1x8','ZZ TESTE SPL 1x8')); $ps=$ports($s8);
 $in=$ports($s8,'spl_in'); $out=$ports($s8,'spl_out');
 chk('1x8: 1 spl_in + 8 spl_out',$r[0]===200&&count($in)===1&&count($out)===8&&count($ps)===9,count($ps));
 chk('1x8: media fibra, internas, rótulos',$in[0]['media']==='fibra'&&(int)$in[0]['is_external']===0&&$out[7]['label']==='Saída 8'&&$out[0]['media']==='fibra'&&(int)$out[3]['is_external']===0);
 chk('1x8: sem percentual',$out[0]['attrs_json']===null);
 chk('1x8: sem coordenada, pai gravado',$r[1]['data']['node']['lat']===null&&$r[1]['data']['node']['parent_node_id']===$pop);
 // --- geração 10/90
 $su=$id($r=$spl($cto,'10/90','ZZ TESTE SPL 10-90')); $out=$ports($su,'spl_out');
 $pc=array_map(fn($p)=>json_decode($p['attrs_json'],true)['percentual']??null,$out);
 chk('10/90: 1 entrada + 2 saídas com 10 e 90',$r[0]===200&&count($ports($su,'spl_in'))===1&&$pc===[10,90],json_encode($pc));
 // --- troca de razão
 $r=$e(['id'=>$s8,'attrs'=>'{"razao":"1x8"}']); chk('mesma razão: no-op (9 portas)',$r[0]===200&&count($ports($s8))===9);
 $r=$e(['id'=>$s8,'attrs'=>'{"razao":"1x4"}']); chk('1x8→1x4 livre: regera 1+4',$r[0]===200&&count($ports($s8,'spl_out'))===4&&count($ports($s8,'spl_in'))===1);
 $r=$e(['id'=>$s8,'attrs'=>'{"razao":"20/80"}']); $pc=array_map(fn($p)=>json_decode($p['attrs_json'],true)['percentual']??null,$ports($s8,'spl_out'));
 chk('1x4→20/80 livre',$r[0]===200&&$pc===[20,80],json_encode($pc));
 // ligação dentro da caixa: CTO → splitter (porta interna do splitter, porta externa da CTO)
 $cp=$ports($cto)[0]['id']; $sin=$ports($su,'spl_in')[0]['id'];
 $lk=$P('/links',['link_type'=>'drop','from_node_id'=>$cto,'to_node_id'=>$su,'from_port_id'=>$cp,'to_port_id'=>$sin]);
 chk('porta interna liga dentro da mesma caixa (CTO↔splitter)',$lk[0]===200,$msg($lk));
 $lid=$lk[1]['data']['link']['id']??0;
 $r=$e(['id'=>$su,'attrs'=>'{"razao":"1x4"}']); chk('troca de razão com porta ocupada: 409 listando',$r[0]===409&&str_contains($msg($r),'Entrada')&&($r[1]['busy_ports'][0]['link_id']??0)===$lid,$msg($r));
 chk('409 não alterou as portas',count($ports($su))===3&&$ports($su,'spl_out')[0]['attrs_json']!==null);
 $r=$e(['id'=>$su,'parent_node_id'=>$cto2]); chk('mover splitter com ligações 422',$r[0]===422,$msg($r));
 $r=$P('/nos/excluir',['id'=>$cto]); chk('excluir CTO com splitter dentro 409',$r[0]===409);
 $r=$P('/nos/excluir',['id'=>$su]); chk('excluir splitter ligado 409',$r[0]===409);
 // porta interna não sai da caixa; externa sim
 $out1=$ports($su,'spl_out')[0]['id']; $cp2=$ports($cto2)[0]['id'];
 $r=$P('/links',['link_type'=>'drop','from_node_id'=>$su,'to_node_id'=>$cto2,'from_port_id'=>$out1,'to_port_id'=>$cp2]); chk('porta interna do splitter não sai da caixa 422',$r[0]===422&&str_contains($msg($r),'interna'),$msg($r));
 $P('/portas/editar',['id'=>$out1,'is_external'=>'1']);
 $r=$P('/links',['link_type'=>'drop','from_node_id'=>$su,'to_node_id'=>$cto2,'from_port_id'=>$out1,'to_port_id'=>$cp2]); chk('porta externa do splitter pode sair',$r[0]===200,$msg($r));
 // regras de site intactas: porta interna de equipamento não sai do site; CTO↔CTO sem sangria
 $eth=$id($c(['node_type'=>'switch','name'=>'ZZ TESTE SW','parent_node_id'=>$pop,'ports_eth'=>'2'])); $ep=$ports($eth,'eth')[0]['id'];
 $r=$P('/links',['link_type'=>'ethernet','from_node_id'=>$eth,'to_node_id'=>$cto2,'from_port_id'=>$ep]); chk('regra de site: porta interna de equipamento não sai do site (422)',$r[0]===422,$msg($r));
 $r=$P('/links',['link_type'=>'ethernet','from_node_id'=>$eth,'to_node_id'=>$olt,'from_port_id'=>$ep]); chk('regra de site: interna liga dentro do site',$r[0]===200,$msg($r));
 // --- DIO
 $dio=$id($r=$c(['node_type'=>'dio','name'=>'ZZ TESTE DIO R','parent_node_id'=>$pop,'attrs'=>'{"capacidade":12}'])); $dp=$ports($dio);
 chk('DIO 12: portas dio "DIO n", fibra, internas',$r[0]===200&&count($dp)===12&&$dp[0]['label']==='DIO 1'&&$dp[11]['label']==='DIO 12'&&$dp[0]['port_role']==='dio'&&$dp[0]['media']==='fibra'&&(int)$dp[0]['is_external']===0);
 $r=$e(['id'=>$dio,'attrs'=>'{"capacidade":24}']); chk('DIO 12→24 acrescenta',$r[0]===200&&count($ports($dio))===24);
 $d13=$ports($dio)[12]['id'];
 $pdo->exec("INSERT INTO map_links (provider_id,link_type,medium,from_node_id,to_node_id,from_port_id,status,label,created_at,updated_at) SELECT provider_id,'drop','fibra',$dio,$olt,$d13,'normal','ZZ TESTE lk',NOW(),NOW() FROM map_nodes WHERE id=$dio");
 $r=$e(['id'=>$dio,'attrs'=>'{"capacidade":12}']); chk('DIO 24→12 com DIO 13 ocupada: 409',$r[0]===409&&str_contains($msg($r),'DIO 13'),$msg($r));
 $pdo->exec("DELETE FROM map_links WHERE label='ZZ TESTE lk'");
 $r=$e(['id'=>$dio,'attrs'=>'{"capacidade":12}']); chk('DIO 24→12 livre remove só as livres',$r[0]===200&&count($ports($dio))===12);
 $r=$e(['id'=>$dio,'attrs'=>'{"capacidade":13}']); chk('redimensionar para valor fora da lista 422',$r[0]===422);
 $r=$e(['id'=>$dio,'name'=>'ZZ TESTE DIO R2']); chk('editar DIO sem attrs não mexe nas portas',$r[0]===200&&count($ports($dio))===12);
 // --- equipamento não aceita família passiva por ports_*
 $r=$c(['node_type'=>'olt','name'=>'ZZ TESTE OLT2','parent_node_id'=>$pop,'ports_dio'=>'4','ports_spl_in'=>'1']); chk('ports_dio/ports_spl_in em equipamento são ignorados (0 portas)',$r[0]===200&&count($ports($id($r)))===0);
 // --- GET: media derivada
 [$s,$rr]=call($app,'GET','/api/mapa',[],null,$g); $gp=array_filter($rr['data']['ports'],fn($p)=>in_array($p['node_id'],[$s8,$dio],true));
 $media=array_unique(array_column($gp,'media')); chk('GET devolve media fibra para spl_in/spl_out/dio',$media===['fibra']&&count($gp)>0,json_encode($media));
 chk('auditoria da criação do splitter (map.port.sync)',$audit('map.port.sync',$s8)>=1);
} finally { cleanup($pdo,$cnt,$b); }
echo $ok?"TUDO OK\n":"HOUVE FALHAS\n";
exit($ok ? 0 : 1);
