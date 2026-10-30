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
$N='/api/mapa/nos'; $c=fn($f)=>call($app,'POST',$N,$f,$tok,$g); $e=fn($f)=>call($app,'POST',$N.'/editar',$f,$tok,$g);
$id=fn($r)=>$r[1]['data']['node']['id']??0; $msg=fn($r)=>$r[1]['message']??'';
try {
 $p1=$id($r=$c(['node_type'=>'pop','name'=>'ZZ TESTE POP1','lat'=>'-20.85','lng'=>'-42.80'])); chk('POP com coordenada',$r[0]===200);
 $p2=$id($c(['node_type'=>'pop','name'=>'ZZ TESTE POP2','lat'=>'-20.86','lng'=>'-42.81']));
 $r=$c(['node_type'=>'pop','name'=>'ZZ TESTE semcoord']); chk('contêiner sem coordenada 422',$r[0]===422,$msg($r));
 $r=$c(['node_type'=>'tower','name'=>'ZZ TESTE torre semcoord','parent_node_id'=>$p1]); chk('torre sem coordenada 422',$r[0]===422);
 $t1=$id($r=$c(['node_type'=>'tower','name'=>'ZZ TESTE TORRE1','lat'=>'-20.851','lng'=>'-42.801','parent_node_id'=>$p1])); chk('torre dentro de POP ok',$r[0]===200&&$r[1]['data']['node']['parent_node_id']===$p1);
 $r=$c(['node_type'=>'pop','name'=>'ZZ TESTE pop-em-pop','lat'=>'1','lng'=>'1','parent_node_id'=>$p1]); chk('POP dentro de POP 422',$r[0]===422,$msg($r));
 $cto=$id($c(['node_type'=>'cto','name'=>'ZZ TESTE CTO','lat'=>'-20.84','lng'=>'-42.80']));
 $r=$c(['node_type'=>'tower','name'=>'ZZ TESTE torre-em-cto','lat'=>'1','lng'=>'1','parent_node_id'=>$cto]); chk('torre dentro de CTO 422',$r[0]===422);
 $r=$c(['node_type'=>'cto','name'=>'ZZ TESTE cto-em-pop','lat'=>'1','lng'=>'1','parent_node_id'=>$p1]); chk('CTO com pai 422',$r[0]===422,$msg($r));
 $r=$c(['node_type'=>'olt','name'=>'ZZ TESTE OLT solta']); chk('equipamento sem pai 422',$r[0]===422,$msg($r));
 $r=$c(['node_type'=>'olt','name'=>'ZZ TESTE OLT na CTO','parent_node_id'=>$cto]); chk('equipamento com pai CTO 422',$r[0]===422,$msg($r));
 $olt=$id($r=$c(['node_type'=>'olt','name'=>'ZZ TESTE OLT','parent_node_id'=>$p1,'lat'=>'-1','lng'=>'-1','attrs'=>'{"fabricante":"Huawei","modelo":"MA5800"}']));
 chk('equipamento com lat/lng enviado => gravado NULL',$r[0]===200&&$r[1]['data']['node']['lat']===null&&$r[1]['data']['node']['lng']===null&&$r[1]['data']['node']['attrs_json']['modelo']==='MA5800');
 foreach(['router','server','other','switch','radio'] as $t){ $r=$c(['node_type'=>$t,'name'=>"ZZ TESTE $t",'parent_node_id'=>$t1]); chk("tipo $t dentro da torre",$r[0]===200); }
 $r=$c(['node_type'=>'switch','name'=>'ZZ TESTE sw-em-olt','parent_node_id'=>$olt]); chk('equipamento dentro de equipamento 422',$r[0]===422,$msg($r));
 $r=$e(['id'=>$olt,'parent_node_id'=>$p2]); chk('mover equipamento entre POPs',$r[0]===200&&$r[1]['data']['node']['parent_node_id']===$p2);
 $r=$e(['id'=>$olt,'parent_node_id'=>'']); chk('tirar equipamento do contêiner 422',$r[0]===422);
 $r=$e(['id'=>$olt,'lat'=>'5','lng'=>'5']); chk('editar lat/lng de equipamento => continua NULL',$r[0]===200&&$r[1]['data']['node']['lat']===null);
 $r=$e(['id'=>$olt,'node_type'=>'switch']); chk('mudar tipo 422',$r[0]===422);
 $r=$e(['id'=>$p1,'parent_node_id'=>$t1]); chk('ciclo (POP dentro da própria torre) 422',$r[0]===422);
 $r=$e(['id'=>$p1,'lat'=>'-20.852','lng'=>'-42.802']); chk('mover contêiner (lat/lng)',$r[0]===200);
 $r=call($app,'POST',$N.'/excluir',['id'=>$p2],$tok,$g); chk('excluir POP com equipamento 409',$r[0]===409,$msg($r));
 [$s,$rr]=call($app,'GET','/api/mapa',[],null,$g); $eq=array_values(array_filter($rr['data']['nodes'],fn($n)=>$n['id']===$olt))[0]??null;
 chk('GET devolve equipamento com lat NULL e parent',$eq&&$eq['lat']===null&&$eq['parent_node_id']===$p2);
} finally {
 $d1="DELETE FROM map_links WHERE from_node_id IN (SELECT id FROM (SELECT id FROM map_nodes WHERE name LIKE 'ZZ TESTE%') x) OR to_node_id IN (SELECT id FROM (SELECT id FROM map_nodes WHERE name LIKE 'ZZ TESTE%') y)";
 echo "-- limpeza\nSQL> $d1\n"; $pdo->exec($d1);
 // filhos antes dos pais (FK RESTRICT em parent_node_id): repete até não sobrar
 $d2="DELETE FROM map_nodes WHERE name LIKE 'ZZ TESTE%' AND id NOT IN (SELECT pid FROM (SELECT parent_node_id pid FROM map_nodes WHERE parent_node_id IS NOT NULL) z)";
 echo "SQL> $d2   (repetido até zerar)\n"; for($i=0;$i<10;$i++){ $pdo->exec($d2); if(!(int)$pdo->query("SELECT COUNT(*) FROM map_nodes WHERE name LIKE 'ZZ TESTE%'")->fetchColumn()) break; }
 echo "depois: map_nodes={$cnt('map_nodes')} (antes {$b['n']}) map_links={$cnt('map_links')} (antes {$b['l']})\n";
}
echo $ok?"TUDO OK\n":"HOUVE FALHAS\n";

exit($ok ? 0 : 1);
