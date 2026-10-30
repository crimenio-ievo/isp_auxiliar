<?php
require __DIR__.'/common.php';
$L=fn($f)=>$P('/links',$f); $LE=fn($f)=>$P('/links/editar',$f); $lid=fn($r)=>$r[1]['data']['link']['id']??0;
$pid=function($node,$role,$n) use($ports){ return (int)$ports($node,$role)[$n-1]['id']; };
try {
 $pop1=$id($c(['node_type'=>'pop','name'=>'ZZ TESTE POP1','lat'=>'-20.85','lng'=>'-42.80']));
 $pop2=$id($c(['node_type'=>'pop','name'=>'ZZ TESTE POP2','lat'=>'-20.868','lng'=>'-42.80']));   // ~2 km
 $olt=$id($c(['node_type'=>'olt','name'=>'ZZ TESTE OLT','parent_node_id'=>$pop1,'ports_eth'=>'2','ports_pon'=>'4','ports_sfp'=>'2']));
 $sw1=$id($c(['node_type'=>'switch','name'=>'ZZ TESTE SW1','parent_node_id'=>$pop1,'ports_eth'=>'4','ports_sfp'=>'1']));
 $sw2=$id($c(['node_type'=>'switch','name'=>'ZZ TESTE SW2','parent_node_id'=>$pop2,'ports_eth'=>'2']));
 $cto=$id($c(['node_type'=>'cto','name'=>'ZZ TESTE CTO','lat'=>'-20.851','lng'=>'-42.80','attrs'=>'{"capacity":4}']));
 $cto2=$id($c(['node_type'=>'cto','name'=>'ZZ TESTE CTO2','lat'=>'-20.852','lng'=>'-42.80','attrs'=>'{"capacity":2}']));
 $r=$L(['link_type'=>'ethernet','from_node_id'=>$olt,'to_node_id'=>$sw1,'from_port_id'=>$pid($olt,'eth',1),'to_port_id'=>$pid($sw1,'eth',1)]);
 $l1=$lid($r); chk('ethernet interna ETH→ETH no mesmo site ok, sem aviso',$r[0]===200&&$r[1]['data']['warnings']===[]&&$r[1]['data']['link']['from_port_id']===$pid($olt,'eth',1),$msg($r));
 $r=$L(['link_type'=>'ethernet','from_node_id'=>$olt,'to_node_id'=>$sw1,'from_port_id'=>$pid($sw1,'eth',2)]); chk('porta de outro nó 422',$r[0]===422&&str_contains($msg($r),'não pertence'),$msg($r));
 $r=$L(['link_type'=>'distribuicao','from_node_id'=>$olt,'to_node_id'=>$cto,'from_port_id'=>$pid($olt,'sfp',1),'to_port_id'=>$pid($cto,'fo',1)]); chk('porta interna (SFP) entre site e campo 422',$r[0]===422&&str_contains($msg($r),'interna'),$msg($r));
 $r=$L(['link_type'=>'ethernet','from_node_id'=>$olt,'to_node_id'=>$sw1,'from_port_id'=>$pid($olt,'pon',1)]); chk('PON em ethernet 422',$r[0]===422&&str_contains($msg($r),'PON'),$msg($r));
 $r=$L(['link_type'=>'backbone','from_node_id'=>$olt,'to_node_id'=>$sw1,'from_port_id'=>$pid($olt,'eth',2)]); chk('RJ45 em fibra 422',$r[0]===422&&str_contains($msg($r),'RJ45'),$msg($r));
 $r=$L(['link_type'=>'distribuicao','from_node_id'=>$olt,'to_node_id'=>$cto,'from_port_id'=>$pid($olt,'pon',1),'to_port_id'=>$pid($cto,'fo',1)]);
 $l2=$lid($r); chk('PON (externa) → CTO Porta 1 em fibra ok',$r[0]===200,$msg($r));
 $r=$L(['link_type'=>'backbone','from_node_id'=>$olt,'to_node_id'=>$cto2,'from_port_id'=>$pid($olt,'pon',1)]); chk('porta já usada 409 indicando a ligação',$r[0]===409&&str_contains($msg($r),'#'.$l2),$msg($r));
 $r=$L(['link_type'=>'backbone','from_node_id'=>$olt,'to_node_id'=>$sw1,'from_port_id'=>$pid($olt,'sfp',2),'to_port_id'=>$pid($sw1,'sfp',1)]); chk('SFP em fibra ok',$r[0]===200,$msg($r));
 $r=$L(['link_type'=>'wireless','from_node_id'=>$sw1,'to_node_id'=>$sw2,'from_port_id'=>$pid($sw1,'eth',4)]); chk('sem fio com porta 422',$r[0]===422&&str_contains($msg($r),'sem fio'),$msg($r));
 // externas p/ ethernet entre sites
 $P('/portas/editar',['id'=>$pid($sw1,'eth',3),'is_external'=>'1']); $P('/portas/editar',['id'=>$pid($sw2,'eth',1),'is_external'=>'1']);
 $r=$L(['link_type'=>'ethernet','from_node_id'=>$sw1,'to_node_id'=>$sw2,'from_port_id'=>$pid($sw1,'eth',3),'to_port_id'=>$pid($sw2,'eth',1)]);
 $l4=$lid($r); $w=$r[1]['data']['warnings']??[]; chk('ethernet ~2 km entre sites: grava com aviso >100 m',$r[0]===200&&count($w)===1&&str_contains($w[0],'100 m'),json_encode($w,JSON_UNESCAPED_UNICODE));
 chk('não grava length_m na criação',$r[1]['data']['link']['length_m']===null);
 $r=$L(['link_type'=>'ethernet','from_node_id'=>$sw1,'to_node_id'=>$sw2,'from_port_id'=>$pid($sw1,'eth',4)]); chk('porta interna entre sites 422',$r[0]===422,$msg($r));
 // defeito / reservada
 $P('/portas/editar',['id'=>$pid($cto,'fo',2),'status'=>'defeito']); $P('/portas/editar',['id'=>$pid($cto,'fo',3),'status'=>'reservada']);
 $r=$L(['link_type'=>'drop','from_node_id'=>$cto,'to_node_id'=>$cto2,'from_port_id'=>$pid($cto,'fo',2)]); chk('porta com defeito 422',$r[0]===422&&str_contains($msg($r),'defeito'),$msg($r));
 $r=$L(['link_type'=>'drop','from_node_id'=>$cto,'to_node_id'=>$cto2,'from_port_id'=>$pid($cto,'fo',3),'to_port_id'=>$pid($cto2,'fo',1)]);
 $l5=$lid($r); chk('porta reservada grava com aviso',$r[0]===200&&str_contains(json_encode($r[1]['data']['warnings'],JSON_UNESCAPED_UNICODE),'reservada'),json_encode($r[1]['data']['warnings']??null,JSON_UNESCAPED_UNICODE));
 // refinar ponta
 $r=$L(['link_type'=>'distribuicao','from_node_id'=>$pop1,'to_node_id'=>$cto2]); $l6=$lid($r); chk('ligação POP→CTO2 sem porta ("a detalhar") permitida',$r[0]===200);
 $r=$LE(['id'=>$l6,'from_node_id'=>$olt,'from_port_id'=>$pid($olt,'pon',2),'to_port_id'=>$pid($cto2,'fo',2)]);
 chk('refinar ponta POP → OLT do mesmo POP, com portas',$r[0]===200&&$r[1]['data']['link']['from_node_id']===$olt&&$r[1]['data']['link']['from_port_id']===$pid($olt,'pon',2),$msg($r));
 $r=$LE(['id'=>$l6,'from_node_id'=>$sw2]); chk('trocar ponta para outro site 422',$r[0]===422&&str_contains($msg($r),'mesmo site'),$msg($r));
 $r=$LE(['id'=>$l6,'to_node_id'=>$cto]); chk('trocar ponta de campo (CTO2→CTO) 422',$r[0]===422,$msg($r));
 $r=$LE(['id'=>$l6,'from_node_id'=>$sw1,'from_port_id'=>'']); chk('trocar para SW1 do mesmo POP ok (porta zerada)',$r[0]===200&&$r[1]['data']['link']['from_port_id']===null,$msg($r));
 $r=$LE(['id'=>$l6,'from_node_id'=>$olt,'from_port_id'=>$pid($olt,'pon',1)]); chk('refinar com porta já usada 409',$r[0]===409,$msg($r));
 // inverter sentido (portas acompanham)
 $u0=$audit('map.link.update',$l2);
 $r=$LE(['id'=>$l2,'from_node_id'=>$cto,'to_node_id'=>$olt]); $lk=$r[1]['data']['link']??[];
 chk('inverter sentido: troca from/to e as portas',$r[0]===200&&$lk['from_node_id']===$cto&&$lk['to_node_id']===$olt&&$lk['from_port_id']===$pid($cto,'fo',1)&&$lk['to_port_id']===$pid($olt,'pon',1),$msg($r));
 chk('inversão auditada como map.link.update',$audit('map.link.update',$l2)===$u0+1);
 $row=$pdo->query("SELECT context_json FROM audit_logs WHERE action='map.link.update' AND entity_id=$l2 ORDER BY id DESC LIMIT 1")->fetchColumn(); $ctx=json_decode($row,true);
 chk('auditoria guarda before/after das pontas',$ctx['before']['from_node_id']===$olt&&$ctx['after']['from_node_id']===$cto);
 // porta mantida que virou defeito não bloqueia edição
 $P('/portas/editar',['id'=>$pid($olt,'pon',1),'status'=>'defeito']);
 $r=$LE(['id'=>$l2,'label'=>'ZZ TESTE editado']); chk('editar trecho com porta mantida em defeito ok',$r[0]===200,$msg($r));
 $r=$LE(['id'=>$l2,'link_type'=>'backbone']); chk('tipo continua imutável 422',$r[0]===422);
 // reduzir portas ocupadas pela via real
 $r=$e(['id'=>$olt,'ports_pon'=>'0']); chk('reduzir PON com PON 1/2 em uso 409',$r[0]===409&&count($r[1]['busy_ports'])===1,$msg($r));
} finally { cleanup($pdo,$cnt,$b); }
echo $ok?"TUDO OK\n":"HOUVE FALHAS\n";

exit($ok ? 0 : 1);
