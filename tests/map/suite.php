<?php
require dirname(__DIR__, 2).'/backend/bootstrap/app.php';
$app=bootstrapApplication();
$db=new App\Infrastructure\Database\Database($app->config()); $pdo=$db->pdo();
require __DIR__.'/safety.php';
$_SERVER['SCRIPT_NAME']='/index.php'; $_SERVER['HTTP_HOST']='localhost';
$user=fn($role)=>['id'=>0,'login'=>'zz_teste_'.$role,'role'=>$role,'name'=>'t'];
function call($app,$method,$path,$post=[],$csrf=null,$sess=null){
  $_SERVER['REQUEST_METHOD']=$method; $_SERVER['REQUEST_URI']=$path; $_POST=$post;
  unset($_SERVER['HTTP_X_CSRF_TOKEN']); if($csrf!==null) $_SERVER['HTTP_X_CSRF_TOKEN']=$csrf;
  $_SESSION['user']=$sess;
  $req=App\Core\Request::fromGlobals(); App\Core\Url::setBasePath($req->basePath());
  http_response_code(200); ob_start(); $app->handle($req)->send(); $o=ob_get_clean();
  return [http_response_code(), json_decode($o,true)];
}
$_SESSION=[]; $g=$user('manager'); $t=$user('technician');
$tok=App\Core\Csrf::token('map');
$ok=true;
function chk($label,$cond,$extra=''){ global $ok; if(!$cond)$ok=false; echo ($cond?'[OK]  ':'[FALHA] '),$label,' ',$extra,"\n"; }
try {
 [$s,$r]=call($app,'POST','/api/mapa/nos',['node_type'=>'pop','name'=>'ZZ TESTE pai','lat'=>'-21.0','lng'=>'-42.8','attrs'=>'{"ip":"10.1.1.1","n":3}'],$tok,$g);
 chk('criar valido',$s===200&&$r['ok'],"status=$s"); $pai=$r['data']['node']['id']??0;
 [$s,$r]=call($app,'POST','/api/mapa/nos',['node_type'=>'olt','name'=>'ZZ TESTE filho','lat'=>'-21.1','lng'=>'-42.9','parent_node_id'=>$pai],$tok,$g);
 chk('criar filho com pai',$s===200,"status=$s"); $filho=$r['data']['node']['id']??0;
 [$s,$r]=call($app,'POST','/api/mapa/nos',['node_type'=>'client','name'=>'ZZ TESTE x','lat'=>'1','lng'=>'1'],$tok,$g); chk("tipo 'client' rejeitado",$s===422,"status=$s msg=".($r['message']??''));
 [$s,$r]=call($app,'POST','/api/mapa/nos',['node_type'=>'splitter','name'=>'ZZ TESTE x','lat'=>'1','lng'=>'1'],$tok,$g); chk("tipo 'splitter' rejeitado",$s===422);
 [$s,$r]=call($app,'POST','/api/mapa/nos',['node_type'=>'pop','name'=>'ZZ TESTE x','lat'=>'91','lng'=>'1'],$tok,$g); chk('lat invalida',$s===422,"status=$s");
 [$s,$r]=call($app,'POST','/api/mapa/nos',['node_type'=>'pop','name'=>'ZZ TESTE x','lat'=>'1','lng'=>'181'],$tok,$g); chk('lng invalida',$s===422);
 [$s,$r]=call($app,'POST','/api/mapa/nos',['node_type'=>'pop','name'=>'ZZ TESTE x','lat'=>'1','lng'=>'1','attrs'=>'{"a":{"b":1}}'],$tok,$g); chk('attrs aninhado rejeitado',$s===422);
 [$s,$r]=call($app,'POST','/api/mapa/nos',['node_type'=>'pop','name'=>'ZZ TESTE x','lat'=>'1','lng'=>'1','attrs'=>'['.str_repeat('1,',3000).'1]'],$tok,$g); chk('attrs >4KB/lista rejeitado',$s===422);
 [$s,$r]=call($app,'POST','/api/mapa/nos',['node_type'=>'pop','name'=>'ZZ TESTE x','lat'=>'1','lng'=>'1','parent_node_id'=>'999999'],$tok,$g); chk('pai inexistente',$s===422);
 [$s,$r]=call($app,'POST','/api/mapa/nos',['node_type'=>'pop','name'=>'','lat'=>'1','lng'=>'1'],$tok,$g); chk('nome vazio',$s===422);
 [$s,$r]=call($app,'POST','/api/mapa/nos',['node_type'=>'pop','name'=>'ZZ TESTE x','lat'=>'1','lng'=>'1'],null,$g); chk('sem CSRF',$s===419,"status=$s");
 [$s,$r]=call($app,'POST','/api/mapa/nos',['node_type'=>'pop','name'=>'ZZ TESTE x','lat'=>'1','lng'=>'1'],'errado',$g); chk('CSRF errado',$s===419);
 [$s,$r]=call($app,'POST','/api/mapa/nos',['node_type'=>'pop','name'=>'ZZ TESTE x','lat'=>'1','lng'=>'1'],$tok,$t); chk('nao-gestor',$s===403,"status=$s");
 [$s,$r]=call($app,'POST','/api/mapa/nos',['node_type'=>'pop','name'=>'ZZ TESTE x','lat'=>'1','lng'=>'1'],$tok,null); chk('sem sessao (redireciona)',$s===302,"status=$s");
 [$s,$r]=call($app,'POST','/api/mapa/nos/editar',['id'=>$pai,'name'=>'ZZ TESTE pai ed','status'=>'alerta','notes'=>'obs'],$tok,$g); chk('editar',$s===200&&$r['data']['node']['name']==='ZZ TESTE pai ed'&&$r['data']['node']['attrs_json']['ip']==='10.1.1.1',"status=$s (attrs preservados)");
 [$s,$r]=call($app,'POST','/api/mapa/nos/editar',['id'=>$pai,'lat'=>'-21.5','lng'=>'-42.5'],$tok,$g); chk('mover (so lat/lng)',$s===200&&$r['data']['node']['lat']==-21.5&&$r['data']['node']['name']==='ZZ TESTE pai ed');
 [$s,$r]=call($app,'POST','/api/mapa/nos/editar',['id'=>$pai,'parent_node_id'=>$pai],$tok,$g); chk('pai = proprio no',$s===422);
 [$s,$r]=call($app,'POST','/api/mapa/nos/editar',['id'=>$pai,'parent_node_id'=>$filho],$tok,$g); chk('ciclo pai->filho',$s===422);
 [$s,$r]=call($app,'POST','/api/mapa/nos/editar',['id'=>$pai,'node_type'=>'cto'],$tok,$g); chk('tipo imutavel',$s===422);
 [$s,$r]=call($app,'POST','/api/mapa/nos/editar',['id'=>'999999','name'=>'x'],$tok,$g); chk('editar inexistente',$s===404);
 [$s,$r]=call($app,'POST','/api/mapa/nos/excluir',['id'=>$pai],$tok,$g); chk('excluir com filho -> 409',$s===409,"status=$s ".json_encode($r['blockers']['children']??null));
 [$s,$r]=call($app,'POST','/api/mapa/nos/excluir',['id'=>$filho],$tok,$t); chk('excluir como nao-gestor',$s===403);
 [$s,$r]=call($app,'POST','/api/mapa/nos/excluir',['id'=>$filho],$tok,$g); chk('excluir sem dependencias',$s===200,"status=$s");
 $a=$pdo->query("SELECT action,entity_id,actor_login,context_json FROM audit_logs WHERE action='map.node.delete' AND entity_id=$filho")->fetch();
 chk('registro em audit_logs (map.node.delete)',$a&&strpos($a['context_json'],'ZZ TESTE filho')!==false,$a?'ator='.$a['actor_login']:'');
 $n=$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action IN ('map.node.create','map.node.update') AND actor_login LIKE 'zz_teste_%'")->fetchColumn(); chk('auditoria create/update',$n>=3,"linhas=$n");
 [$s,$r]=call($app,'POST','/api/mapa/nos/excluir',['id'=>$pai],$tok,$g); chk('excluir pai (agora livre)',$s===200);
} finally {
  echo "-- limpeza\n"; $c="DELETE FROM map_nodes WHERE name LIKE 'ZZ TESTE%' ORDER BY id DESC"; echo "SQL> $c\n"; $pdo->exec($c);
  echo "map_nodes restantes: ",$pdo->query("SELECT COUNT(*) FROM map_nodes")->fetchColumn(),"\n";
}
echo $ok?"TUDO OK\n":"HOUVE FALHAS\n";

exit($ok ? 0 : 1);
