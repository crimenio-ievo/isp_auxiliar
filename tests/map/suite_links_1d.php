<?php
require dirname(__DIR__, 2).'/backend/bootstrap/app.php';
$app=bootstrapApplication(); $db=new App\Infrastructure\Database\Database($app->config()); $pdo=$db->pdo();
require __DIR__.'/safety.php';
$_SERVER['SCRIPT_NAME']='/index.php'; $_SERVER['HTTP_HOST']='localhost';
function call($app,$path,$post=[],$csrf=null,$sess=null){
  $_SERVER['REQUEST_METHOD']='POST'; $_SERVER['REQUEST_URI']=$path; $_POST=$post;
  unset($_SERVER['HTTP_X_CSRF_TOKEN']); if($csrf!==null) $_SERVER['HTTP_X_CSRF_TOKEN']=$csrf; $_SESSION['user']=$sess;
  $req=App\Core\Request::fromGlobals(); App\Core\Url::setBasePath($req->basePath());
  http_response_code(200); ob_start(); @$app->handle($req)->send(); $o=ob_get_clean(); return [http_response_code(), json_decode($o,true)];
}
$g=['id'=>0,'login'=>'zz_teste_manager','role'=>'manager']; $t=['id'=>0,'login'=>'zz_teste_tec','role'=>'technician'];
$_SESSION=[]; $tok=App\Core\Csrf::token('map'); $ok=true;
function chk($l,$c,$x=''){ global $ok; if(!$c)$ok=false; echo ($c?'[OK]  ':'[FALHA] '),$l,' ',$x,"\n"; }
$ins=function($n,$prov=1)use($pdo){ $pdo->exec("INSERT INTO map_nodes (provider_id,node_type,name,lat,lng,status,created_at,updated_at) VALUES ($prov,'pop','$n',1,1,'normal',NOW(),NOW())"); return (int)$pdo->lastInsertId(); };
$before=['n'=>$pdo->query("SELECT COUNT(*) FROM map_nodes")->fetchColumn(),'l'=>$pdo->query("SELECT COUNT(*) FROM map_links")->fetchColumn()];
echo "antes: map_nodes={$before['n']} map_links={$before['l']}\n";
try {
 $a=$ins('ZZ TESTE a'); $b=$ins('ZZ TESTE b'); $c=$ins('ZZ TESTE c');
 $otherProv=$pdo->query("SELECT id FROM providers WHERE id<>1 LIMIT 1")->fetchColumn(); $outro=null;
 if($otherProv){ $outro=$ins('ZZ TESTE outro',(int)$otherProv); } else { echo "(sem 2o provider no banco: teste de outro provider feito com id inexistente)\n"; }
 $L='/api/mapa/links'; $base=['link_type'=>'distribuicao','from_node_id'=>$a,'to_node_id'=>$b];
 [$s,$r]=call($app,$L,$base+['label'=>'ZZ TESTE ligacao','fiber_count'=>'12','length_m'=>'350.5','attrs'=>'{"cabo_nome":"CABO X","cabo_tipo":"12FO"}','medium'=>'radio'],$tok,$g);
 chk('criar valido',$s===200&&$r['ok'],"status=$s"); $lk=$r['data']['link']['id']??0;
 chk('medium do cliente ignorado (derivado=fibra)',($r['data']['link']['medium']??'')==='fibra',$r['data']['link']['medium']??'');
 [$s,$r]=call($app,$L,['link_type'=>'fibra','from_node_id'=>$a,'to_node_id'=>$c],$tok,$g); chk('tipo invalido',$s===422);
 [$s,$r]=call($app,$L,['link_type'=>'ethernet','from_node_id'=>$a,'to_node_id'=>$a],$tok,$g); chk('mesmo no nos dois lados',$s===422);
 [$s,$r]=call($app,$L,['link_type'=>'ethernet','from_node_id'=>$a,'to_node_id'=>'99999999'],$tok,$g); chk('no inexistente',$s===422);
 if($outro){ [$s,$r]=call($app,$L,['link_type'=>'ethernet','from_node_id'=>$a,'to_node_id'=>$outro],$tok,$g); chk('no de outro provider',$s===422,"status=$s"); }
 [$s,$r]=call($app,$L,$base,$tok,$g); chk('duplicada (mesmo sentido) 409',$s===409,"status=$s");
 [$s,$r]=call($app,$L,['link_type'=>'distribuicao','from_node_id'=>$b,'to_node_id'=>$a],$tok,$g); chk('duplicada invertida 409',$s===409);
 [$s,$r]=call($app,$L,['link_type'=>'ethernet','from_node_id'=>$b,'to_node_id'=>$a],$tok,$g); chk('mesmo par, outro tipo: permitido',$s===200); $lk2=$r['data']['link']['id']??0;
 chk('ethernet => medium cabo',($r['data']['link']['medium']??'')==='cabo');
 [$s,$r]=call($app,$L,['link_type'=>'wireless','from_node_id'=>$a,'to_node_id'=>$c,'fiber_count'=>'4'],$tok,$g); chk('fiber_count em wireless rejeitado',$s===422,$r['message']??'');
 [$s,$r]=call($app,$L,['link_type'=>'drop','from_node_id'=>$a,'to_node_id'=>$c,'fiber_count'=>'289'],$tok,$g); chk('fiber_count 289 rejeitado',$s===422);
 [$s,$r]=call($app,$L,['link_type'=>'drop','from_node_id'=>$a,'to_node_id'=>$c,'length_m'=>'-1'],$tok,$g); chk('length_m negativo rejeitado',$s===422);
 [$s,$r]=call($app,$L,['link_type'=>'drop','from_node_id'=>$a,'to_node_id'=>$c,'status'=>'xx'],$tok,$g); chk('status invalido',$s===422);
 [$s,$r]=call($app,$L,['link_type'=>'drop','from_node_id'=>$a,'to_node_id'=>$c,'attrs'=>'{"x":[1]}'],$tok,$g); chk('attrs nao escalar',$s===422);
 [$s,$r]=call($app,$L,$base,null,$g); chk('sem CSRF 419',$s===419); [$s,$r]=call($app,$L,$base,$tok,['id'=>0,'login'=>'zz_teste_tec','role'=>'technician']); chk('nao-gestor 403',$s===403);
 [$s,$r]=call($app,$L.'/editar',['id'=>$lk,'label'=>'ZZ TESTE editada','status'=>'alerta','fiber_count'=>'24'],$tok,$g); chk('editar label/status/fibras',$s===200&&$r['data']['link']['label']==='ZZ TESTE editada'&&$r['data']['link']['status']==='alerta'&&$r['data']['link']['fiber_count']===24&&$r['data']['link']['attrs_json']['cabo_nome']==='CABO X',"status=$s");
 [$s,$r]=call($app,$L.'/editar',['id'=>$lk,'to_node_id'=>$c],$tok,$g); chk('mudar destino 422',$s===422);
 [$s,$r]=call($app,$L.'/editar',['id'=>$lk,'from_node_id'=>$c],$tok,$g); chk('mudar origem 422',$s===422);
 [$s,$r]=call($app,$L.'/editar',['id'=>$lk,'link_type'=>'drop'],$tok,$g); chk('mudar tipo 422',$s===422);
 [$s,$r]=call($app,$L.'/editar',['id'=>$lk2,'fiber_count'=>'4'],$tok,$g); chk('fiber_count em ethernet (editar) 422',$s===422);
 [$s,$r]=call($app,$L.'/editar',['id'=>'999999','label'=>'x'],$tok,$g); chk('editar inexistente 404',$s===404);
 [$s,$r]=call($app,'/api/mapa/nos/excluir',['id'=>$a],$tok,$g); chk('excluir no com ligacao 409',$s===409,"status=$s");
 [$s,$r]=call($app,$L.'/excluir',['id'=>$lk],$tok,$t); chk('excluir ligacao como nao-gestor 403',$s===403);
 [$s,$r]=call($app,$L.'/excluir',['id'=>$lk],$tok,$g); chk('excluir ligacao',$s===200);
 $au=$pdo->query("SELECT context_json FROM audit_logs WHERE action='map.link.delete' AND entity_id=$lk")->fetch(); chk('auditoria map.link.delete com registro completo',$au&&strpos($au['context_json'],'ZZ TESTE editada')!==false);
 $n=$pdo->query("SELECT COUNT(DISTINCT action) FROM audit_logs WHERE action IN ('map.link.create','map.link.update','map.link.delete') AND actor_login='zz_teste_manager'")->fetchColumn(); chk('auditoria create/update/delete presentes',(int)$n===3,"acoes=$n");
 [$s,$r]=call($app,$L.'/excluir',['id'=>$lk2],$tok,$g); chk('excluir 2a ligacao',$s===200);
 [$s,$r]=call($app,'/api/mapa/nos/excluir',['id'=>$a],$tok,$g); chk('agora o no exclui (200)',$s===200,"status=$s");
} finally {
 $d1="DELETE FROM map_links WHERE from_node_id IN (SELECT id FROM (SELECT id FROM map_nodes WHERE name LIKE 'ZZ TESTE%') x) OR to_node_id IN (SELECT id FROM (SELECT id FROM map_nodes WHERE name LIKE 'ZZ TESTE%') y)";
 $d2="DELETE FROM map_nodes WHERE name LIKE 'ZZ TESTE%' ORDER BY id DESC";
 echo "-- limpeza\nSQL> $d1\nSQL> $d2\n"; $pdo->exec($d1); $pdo->exec($d2);
 echo "depois: map_nodes=",$pdo->query("SELECT COUNT(*) FROM map_nodes")->fetchColumn()," (antes {$before['n']}) map_links=",$pdo->query("SELECT COUNT(*) FROM map_links")->fetchColumn()," (antes {$before['l']})\n";
}
echo $ok?"TUDO OK\n":"HOUVE FALHAS\n";

exit($ok ? 0 : 1);
