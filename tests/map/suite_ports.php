<?php
require __DIR__.'/common.php';
try {
 $pop=$id($c(['node_type'=>'pop','name'=>'ZZ TESTE POP','lat'=>'-20.85','lng'=>'-42.80']));
 $olt=$id($r=$c(['node_type'=>'olt','name'=>'ZZ TESTE OLT','parent_node_id'=>$pop,'ports_eth'=>'4','ports_pon'=>'8','ports_sfp'=>'2']));
 $ps=$ports($olt); chk('OLT criada com 14 portas',$r[0]===200&&count($ps)===14,count($ps));
 $pon=$ports($olt,'pon'); $eth=$ports($olt,'eth'); $sfp=$ports($olt,'sfp');
 chk('PON: rótulo PON n, media pon, externa',$pon[0]['label']==='PON 1'&&$pon[7]['label']==='PON 8'&&$pon[0]['media']==='pon'&&(int)$pon[0]['is_external']===1);
 chk('ETH: rótulo ETH n, media rj45, interna',$eth[3]['label']==='ETH 4'&&$eth[0]['media']==='rj45'&&(int)$eth[0]['is_external']===0);
 chk('SFP: media sfp, interna',$sfp[1]['label']==='SFP 2'&&$sfp[0]['media']==='sfp'&&(int)$sfp[0]['is_external']===0);
 chk('auditoria map.port.sync na criação',$audit('map.port.sync',$olt)===1);
 $r=$e(['id'=>$olt,'ports_eth'=>'6']); chk('aumentar ETH 4→6 acrescenta ETH 5 e 6',$r[0]===200&&array_column($ports($olt,'eth'),'label')===['ETH 1','ETH 2','ETH 3','ETH 4','ETH 5','ETH 6']);
 chk('famílias não enviadas ficam como estão',count($ports($olt,'pon'))===8&&count($ports($olt,'sfp'))===2);
 // ocupar ETH 6 e PON 8 com uma ligação ZZ (direto no banco: regras de ligação são da parte C)
 $sw=$id($c(['node_type'=>'switch','name'=>'ZZ TESTE SW','parent_node_id'=>$pop,'ports_eth'=>'2']));
 $e6=$ports($olt,'eth')[5]['id']; $p8=$ports($olt,'pon')[7]['id'];
 $pdo->exec("INSERT INTO map_links (provider_id,link_type,medium,from_node_id,to_node_id,from_port_id,status,label,created_at,updated_at) SELECT provider_id,'ethernet','cabo',$olt,$sw,$e6,'normal','ZZ TESTE lk',NOW(),NOW() FROM map_nodes WHERE id=$olt");
 $lk=(int)$pdo->lastInsertId();
 $r=$e(['id'=>$olt,'ports_eth'=>'3']); chk('reduzir ETH 6→3 com ETH 6 ocupada: 409 listando',$r[0]===409&&str_contains($msg($r),'ETH 6')&&($r[1]['busy_ports'][0]['link_id']??0)===$lk,$msg($r));
 chk('409 não removeu nada',count($ports($olt,'eth'))===6);
 $r=$e(['id'=>$olt,'ports_eth'=>'6','ports_pon'=>'4']); chk('reduzir PON 8→4 (livres) ok',$r[0]===200&&array_column($ports($olt,'pon'),'label')===['PON 1','PON 2','PON 3','PON 4']);
 $r=$e(['id'=>$olt,'ports_sfp'=>'0']); chk('zerar SFP',$r[0]===200&&count($ports($olt,'sfp'))===0);
 $r=$e(['id'=>$olt,'ports_eth'=>'999']); chk('quantidade inválida 422',$r[0]===422,$msg($r));
 $r=$c(['node_type'=>'pop','name'=>'ZZ TESTE POPX','lat'=>'1','lng'=>'1','ports_eth'=>'2']); chk('ports_* em contêiner 422',$r[0]===422,$msg($r));
 // CTO
 $cto=$id($r=$c(['node_type'=>'cto','name'=>'ZZ TESTE CTO','lat'=>'-20.84','lng'=>'-42.80','attrs'=>'{"capacity":8}']));
 $cp=$ports($cto); chk('CTO capacity 8 => Porta 1..8, fo/fibra/externa',count($cp)===8&&$cp[0]['label']==='Porta 1'&&$cp[7]['label']==='Porta 8'&&$cp[0]['port_role']==='fo'&&$cp[0]['media']==='fibra'&&(int)$cp[0]['is_external']===1);
 $r=$e(['id'=>$cto,'attrs'=>'{"capacity":12}']); chk('CTO 8→12',$r[0]===200&&count($ports($cto))===12);
 $pdo->exec("UPDATE map_links SET to_node_id=$cto, to_port_id=".(int)$ports($cto)[10]['id']." WHERE id=$lk");
 $r=$e(['id'=>$cto,'attrs'=>'{"capacity":4}']); chk('CTO 12→4 com Porta 11 ocupada: 409',$r[0]===409&&str_contains($msg($r),'Porta 11'),$msg($r));
 $pdo->exec("UPDATE map_links SET to_node_id=$sw, to_port_id=NULL WHERE id=$lk");
 $r=$e(['id'=>$cto,'attrs'=>'{"capacity":4}']); chk('CTO 12→4 livre ok',$r[0]===200&&count($ports($cto))===4);
 $r=$e(['id'=>$cto,'attrs'=>'{"capacity":200}']); chk('capacity 200 422',$r[0]===422,$msg($r));
 $r=$e(['id'=>$cto,'name'=>'ZZ TESTE CTO2']); chk('editar CTO sem attrs não mexe nas portas',$r[0]===200&&count($ports($cto))===4);
 // rotas de porta
 $e1=$ports($olt,'eth')[0]['id'];
 $r=$P('/portas/editar',['id'=>$e1,'label'=>'UPLINK','is_external'=>'1','status'=>'reservada','media'=>'pon']);
 $row=$ports($olt,'eth')[0];
 chk('editar porta: label/externa/status; media enviada ignorada',$r[0]===200&&$row['label']==='UPLINK'&&(int)$row['is_external']===1&&$row['status']==='reservada'&&$row['media']==='rj45'&&$r[1]['data']['port']['media']==='rj45');
 chk('auditoria map.port.update',$audit('map.port.update',$e1)===1);
 $r=$P('/portas/editar',['id'=>$e1,'status'=>'ocupada']); chk('status inválido 422',$r[0]===422,$msg($r));
 $r=$P('/portas/editar',['id'=>$e1,'label'=>'']); chk('rótulo vazio 422',$r[0]===422);
 $r=$P('/portas/editar',['id'=>999999999,'label'=>'x']); chk('porta inexistente 404',$r[0]===404);
 // porta ocupada em ligação que sai do site não pode virar interna
 $pdo->exec("UPDATE map_links SET from_port_id=$e1, to_node_id=$cto WHERE id=$lk");
 $r=$P('/portas/editar',['id'=>$e1,'is_external'=>'0']); chk('porta externa em ligação inter-site não vira interna (409)',$r[0]===409,$msg($r));
 $r=$P('/portas/excluir',['id'=>$e1]); chk('excluir porta ocupada 409',$r[0]===409,$msg($r));
 $pdo->exec("UPDATE map_links SET from_port_id=NULL, to_node_id=$sw WHERE id=$lk");
 $r=$P('/portas/excluir',['id'=>$e1]); chk('excluir porta livre ok',$r[0]===200&&count($ports($olt,'eth'))===5);
 chk('auditoria map.port.delete',$audit('map.port.delete',$e1)===1);
 $r=call($app,'POST','/api/mapa/portas/editar',['id'=>$e6,'label'=>'x'],'tokenruim',$g); chk('CSRF inválido 419',$r[0]===419);
 $r=call($app,'POST','/api/mapa/portas/excluir',['id'=>$e6],$tok,['id'=>0,'login'=>'zz','role'=>'tecnico']); chk('não gestor 403',$r[0]===403);
 [$s,$rr]=call($app,'GET','/api/mapa',[],null,$g); $gp=array_values(array_filter($rr['data']['ports'],fn($p)=>$p['node_id']===$cto));
 chk('GET devolve portas com media e is_external (bool)',count($gp)===4&&$gp[0]['media']==='fibra'&&$gp[0]['is_external']===true);
} finally { cleanup($pdo,$cnt,$b); }
echo $ok?"TUDO OK\n":"HOUVE FALHAS\n";

exit($ok ? 0 : 1);
