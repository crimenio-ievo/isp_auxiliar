<?php
require __DIR__.'/common.php';
try {
 $pop=$id($c(['node_type'=>'pop','name'=>'ZZ TESTE POP','lat'=>'-20.85','lng'=>'-42.80']));
 $olt=$id($r=$c(['node_type'=>'olt','name'=>'ZZ TESTE OLT','parent_node_id'=>$pop,'ports_pon'=>'2','ports_eth'=>'1','attrs'=>'{"fabricante":"X","tx_padrao_dbm":"3,5","max_clientes_padrao":"128"}']));
 $a=$r[1]['data']['node']['attrs_json']; chk('OLT grava padrões normalizados e preserva fabricante',$r[0]===200&&$a['tx_padrao_dbm']===3.5&&$a['max_clientes_padrao']===128&&$a['fabricante']==='X',json_encode($a));
 $r=$c(['node_type'=>'olt','name'=>'ZZ TESTE OLT2','parent_node_id'=>$pop,'attrs'=>'{"tx_padrao_dbm":"15"}']); chk('padrão Tx fora da faixa 422',$r[0]===422,$msg($r));
 $r=$c(['node_type'=>'olt','name'=>'ZZ TESTE OLT3','parent_node_id'=>$pop,'attrs'=>'{"max_clientes_padrao":"300"}']); chk('padrão máx fora da faixa 422',$r[0]===422,$msg($r));
 $r=$c(['node_type'=>'switch','name'=>'ZZ TESTE SW','parent_node_id'=>$pop,'attrs'=>'{"tx_padrao_dbm":"1"}']); chk('padrão em não-OLT 422',$r[0]===422,$msg($r));
 $r=$e(['id'=>$olt,'attrs'=>'{"fabricante":"X","tx_padrao_dbm":"","max_clientes_padrao":"64"}']); $a=$r[1]['data']['node']['attrs_json']??[];
 chk('vazio apaga o padrão; outro muda',$r[0]===200&&!array_key_exists('tx_padrao_dbm',$a)&&$a['max_clientes_padrao']===64,json_encode($a));
 $pon=$ports($olt,'pon'); $eth=$ports($olt,'eth')[0]['id']; $p1=(int)$pon[0]['id']; $p2=(int)$pon[1]['id'];
 $r=$P('/portas/editar',['id'=>$p1,'tx_dbm'=>'4.25','max_clientes'=>'64']); $a=$r[1]['data']['port']['attrs_json']??[];
 chk('PON aceita tx e máx',$r[0]===200&&$a['tx_dbm']===4.25&&$a['max_clientes']===64,json_encode($a));
 // mescla preserva outras chaves
 $pdo->exec("UPDATE map_ports SET attrs_json='{\"onus\":7,\"tx_dbm\":4.25,\"max_clientes\":64}' WHERE id=$p1");
 $r=$P('/portas/editar',['id'=>$p1,'tx_dbm'=>'-1.5']); $a=$r[1]['data']['port']['attrs_json']??[];
 chk('mescla preserva onus e max_clientes',$r[0]===200&&$a['onus']===7&&$a['max_clientes']===64&&$a['tx_dbm']===-1.5,json_encode($a));
 $r=$P('/portas/editar',['id'=>$p1,'tx_dbm'=>'','max_clientes'=>'']); $a=$r[1]['data']['port']['attrs_json']??[];
 chk('vazio apaga só os dois, mantém onus',$r[0]===200&&$a===['onus'=>7],json_encode($a));
 foreach([['tx_dbm','11'],['tx_dbm','-10.5'],['tx_dbm','1.234'],['tx_dbm','abc'],['max_clientes','0'],['max_clientes','257'],['max_clientes','1.5']] as [$k,$v]){ $r=$P('/portas/editar',['id'=>$p2,$k=>$v]); chk("$k=$v 422",$r[0]===422,$msg($r)); }
 $r=$P('/portas/editar',['id'=>$p2,'tx_dbm'=>'10','max_clientes'=>'256']); chk('limites 10 e 256 ok',$r[0]===200);
 $r=$P('/portas/editar',['id'=>$eth,'tx_dbm'=>'3']); chk('tx em porta ETH 422',$r[0]===422&&str_contains($msg($r),'PON'),$msg($r));
 $r=$P('/portas/editar',['id'=>$eth,'max_clientes'=>'3']); chk('máx em porta ETH 422',$r[0]===422);
 $r=$P('/portas/editar',['id'=>$eth,'label'=>'LAN']); chk('ETH sem potência continua editando',$r[0]===200);
 chk('auditoria map.port.update com attrs',$audit('map.port.update',$p1)>=3);
 // defaults
 [$s,$rr]=call($app,'GET','/api/mapa',[],null,$g); $d=$rr['data']['defaults']??[];
 chk('data.defaults com padrões',$d==['map.loss.fusion_db'=>0.1,'map.loss.connector_db'=>0.5,'map.atten.1490_db_km'=>0.3,'map.onu.min_rx_dbm'=>-27],json_encode($d));
 echo "[PULADO] sobreposição de map.loss.fusion_db: escrita em provider_settings reais proibida.\n";
} finally { cleanup($pdo,$cnt,$b); echo "provider_settings map.*: ".(int)$pdo->query("SELECT COUNT(*) FROM provider_settings WHERE setting_key LIKE 'map.%'")->fetchColumn()."\n"; }
echo $ok?"TUDO OK\n":"HOUVE FALHAS\n";

exit($ok ? 0 : 1);
