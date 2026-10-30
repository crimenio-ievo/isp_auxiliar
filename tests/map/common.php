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
$b=['n'=>$cnt('map_nodes'),'l'=>$cnt('map_links'),'p'=>$cnt('map_ports')]; echo "antes: map_nodes={$b['n']} map_links={$b['l']} map_ports={$b['p']}\n";
$P=fn($path,$f)=>call($app,'POST','/api/mapa'.$path,$f,$tok,$g);
$c=fn($f)=>$P('/nos',$f); $e=fn($f)=>$P('/nos/editar',$f);
$id=fn($r)=>$r[1]['data']['node']['id']??0; $msg=fn($r)=>$r[1]['message']??'';
$ports=function($node,$role=null) use($pdo){ $q=$pdo->prepare('SELECT * FROM map_ports WHERE node_id=?'.($role?' AND port_role=?':'').' ORDER BY port_role, port_num'); $q->execute($role?[$node,$role]:[$node]); return $q->fetchAll(); };
$audit=fn($action,$eid)=>(int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='$action' AND entity_id=".(int)$eid." AND actor_login='zz_teste_manager'")->fetchColumn();
function cleanup($pdo,$cnt,$b){
 $d1="DELETE FROM map_links WHERE from_node_id IN (SELECT id FROM (SELECT id FROM map_nodes WHERE name LIKE 'ZZ TESTE%') x) OR to_node_id IN (SELECT id FROM (SELECT id FROM map_nodes WHERE name LIKE 'ZZ TESTE%') y)";
 echo "-- limpeza (só 'ZZ TESTE'; portas saem por CASCADE)\nSQL> $d1\n"; $pdo->exec($d1);
 $d2="DELETE FROM map_nodes WHERE name LIKE 'ZZ TESTE%' AND id NOT IN (SELECT pid FROM (SELECT parent_node_id pid FROM map_nodes WHERE parent_node_id IS NOT NULL) z)";
 echo "SQL> $d2   (repetido até zerar)\n"; for($i=0;$i<10;$i++){ $pdo->exec($d2); if(!(int)$pdo->query("SELECT COUNT(*) FROM map_nodes WHERE name LIKE 'ZZ TESTE%'")->fetchColumn()) break; }
 echo "depois: map_nodes={$cnt('map_nodes')} (antes {$b['n']}) map_links={$cnt('map_links')} (antes {$b['l']}) map_ports={$cnt('map_ports')} (antes {$b['p']})\n";
}
