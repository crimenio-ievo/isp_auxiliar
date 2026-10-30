<?php
require dirname(__DIR__, 2).'/backend/bootstrap/app.php';
$app=bootstrapApplication(); $db=new App\Infrastructure\Database\Database($app->config()); $pdo=$db->pdo();
require __DIR__.'/safety.php';
$_SERVER['SCRIPT_NAME']='/index.php'; $_SERVER['HTTP_HOST']='localhost';
function call($app,$m,$path,$post=[],$csrf=null,$sess=null){
  $_SERVER['REQUEST_METHOD']=$m; $_SERVER['REQUEST_URI']=$path; $_POST=$post;
  unset($_SERVER['HTTP_X_CSRF_TOKEN']); if($csrf!==null) $_SERVER['HTTP_X_CSRF_TOKEN']=$csrf; $_SESSION['user']=$sess;
  $req=App\Core\Request::fromGlobals(); App\Core\Url::setBasePath($req->basePath());
  http_response_code(200); ob_start(); @$app->handle($req)->send(); $o=ob_get_clean(); return [http_response_code(), json_decode($o,true)];
}
$g=['id'=>0,'login'=>'zz_teste_manager','role'=>'manager']; $_SESSION=[]; $tok=App\Core\Csrf::token('map'); $ok=true;
function chk($l,$c,$x=''){ global $ok; if(!$c)$ok=false; echo ($c?'[OK]  ':'[FALHA] '),$l,' ',$x,"\n"; }
$cnt=fn($t)=>(int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
$b=['n'=>$cnt('map_nodes'),'l'=>$cnt('map_links')]; echo "antes: map_nodes={$b['n']} map_links={$b['l']}\n";
$N='/api/mapa/nos'; $L='/api/mapa/links';
try {
 [$s,$r]=call($app,'POST',$N,['node_type'=>'pop','name'=>'ZZ TESTE padrao','lat'=>'1','lng'=>'1'],$tok,$g); chk('no sem lifecycle => padrao ativo',$s===200&&$r['data']['node']['lifecycle']==='ativo'); $a=$r['data']['node']['id'];
 [$s,$r]=call($app,'POST',$N,['node_type'=>'pop','name'=>'ZZ TESTE proj','lat'=>'1','lng'=>'1','lifecycle'=>'projeto','status'=>'offline'],$tok,$g); chk('no projeto + status independente',$s===200&&$r['data']['node']['lifecycle']==='projeto'&&$r['data']['node']['status']==='offline'); $p=$r['data']['node']['id'];
 [$s,$r]=call($app,'POST',$N,['node_type'=>'pop','name'=>'ZZ TESTE x','lat'=>'1','lng'=>'1','lifecycle'=>'xyz'],$tok,$g); chk('lifecycle invalido (no) 422',$s===422,$r['message']??'');
 [$s,$r]=call($app,'POST',$N.'/editar',['id'=>$a,'lifecycle'=>'desativado'],$tok,$g); chk('editar lifecycle (no)',$s===200&&$r['data']['node']['lifecycle']==='desativado'&&$r['data']['node']['status']==='normal');
 [$s,$r]=call($app,'POST',$N.'/editar',['id'=>$a,'lifecycle'=>'foo'],$tok,$g); chk('editar lifecycle invalido 422',$s===422);
 [$s,$r]=call($app,'POST',$N.'/editar',['id'=>$a,'name'=>'ZZ TESTE padrao 2'],$tok,$g); chk('editar sem lifecycle mantem valor',$s===200&&$r['data']['node']['lifecycle']==='desativado');
 [$s,$r]=call($app,'POST',$L,['link_type'=>'ethernet','from_node_id'=>$a,'to_node_id'=>$p],$tok,$g); chk('ligacao sem lifecycle => ativo',$s===200&&$r['data']['link']['lifecycle']==='ativo'); $l1=$r['data']['link']['id'];
 [$s,$r]=call($app,'POST',$L,['link_type'=>'wireless','from_node_id'=>$a,'to_node_id'=>$p,'lifecycle'=>'projeto'],$tok,$g); chk('ligacao projeto',$s===200&&$r['data']['link']['lifecycle']==='projeto'); $l2=$r['data']['link']['id'];
 [$s,$r]=call($app,'POST',$L,['link_type'=>'drop','from_node_id'=>$a,'to_node_id'=>$p,'lifecycle'=>'x'],$tok,$g); chk('lifecycle invalido (ligacao) 422',$s===422);
 [$s,$r]=call($app,'POST',$L.'/editar',['id'=>$l1,'lifecycle'=>'desativado'],$tok,$g); chk('editar lifecycle (ligacao)',$s===200&&$r['data']['link']['lifecycle']==='desativado'&&$r['data']['link']['status']==='normal');
 [$s,$r]=call($app,'POST',$L.'/editar',['id'=>$l1,'lifecycle'=>'zzz'],$tok,$g); chk('editar ligacao lifecycle invalido 422',$s===422);
 [$s,$r]=call($app,'GET','/api/mapa',[],null,$g); $d=$r['data']??[];
 $fn=array_values(array_filter($d['nodes'],fn($n)=>$n['id']===$p))[0]??null; $fl=array_values(array_filter($d['links'],fn($x)=>$x['id']===$l2))[0]??null;
 chk('GET /api/mapa devolve lifecycle (no e ligacao)',($fn['lifecycle']??'')==='projeto'&&($fl['lifecycle']??'')==='projeto');
 chk('nos reais seguem ativo',count(array_filter($d['nodes'],fn($n)=>strpos($n['name'],'ZZ TESTE')!==0&&$n['lifecycle']==='ativo'))===$b['n']);
 $au=$pdo->query("SELECT context_json FROM audit_logs WHERE action='map.node.update' AND entity_id=$a ORDER BY id DESC LIMIT 1")->fetchColumn(); chk('auditoria registra lifecycle antes/depois',strpos($au,'"lifecycle":"desativado"')!==false&&strpos($au,'"lifecycle":"ativo"')!==false);
} finally {
 $d1="DELETE FROM map_links WHERE from_node_id IN (SELECT id FROM (SELECT id FROM map_nodes WHERE name LIKE 'ZZ TESTE%') x) OR to_node_id IN (SELECT id FROM (SELECT id FROM map_nodes WHERE name LIKE 'ZZ TESTE%') y)";
 $d2="DELETE FROM map_nodes WHERE name LIKE 'ZZ TESTE%' ORDER BY id DESC"; echo "-- limpeza\nSQL> $d1\nSQL> $d2\n"; $pdo->exec($d1); $pdo->exec($d2);
 echo "depois: map_nodes={$cnt('map_nodes')} (antes {$b['n']}) map_links={$cnt('map_links')} (antes {$b['l']})\n";
}
echo $ok?"TUDO OK\n":"HOUVE FALHAS\n";

exit($ok ? 0 : 1);
