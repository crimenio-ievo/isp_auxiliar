<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';
use App\Infrastructure\Net\NetTick;
use App\Infrastructure\Net\NetDeviceLock;
use App\Infrastructure\Net\NetAlertEngine;
use App\Infrastructure\Net\DeviceProfile;
use App\Infrastructure\Net\Snmp\FakeSnmpTransport;
use App\Infrastructure\Net\Store\InMemoryNetStore;
use App\Infrastructure\Net\Store\InMemoryNetAlertStore;

$n=0;
$check=static function(bool $ok,string $m)use(&$n):void { $n++; if(!$ok){throw new RuntimeException($m);} };
$now='2026-11-12 12:00:00';
$clock=static function()use(&$now){return $now;};
$dir=sys_get_temp_dir().'/net-tick-test-'.bin2hex(random_bytes(6)); mkdir($dir,0700);
$sql=file_get_contents(dirname(__DIR__,2).'/database/migrations/032_net_power.sql');
preg_match("/'(\{\s*\"schema\".*?\})',\s*'active'/s",$sql,$m);
$data=json_decode($m[1],true); $data['enumeration']['probe']['pons']=[1]; $data['enumeration']['probe']['max_onu']=2;
$json=json_encode($data); $profile=DeviceProfile::fromJson('fake',$json);
$mib=[];
$identity=['sys_object_id'=>['OID','1.3.6.1.4.1.25355.4.4'],'self_ip'=>['IpAddress','198.51.100.1'],'engine_id'=>['Hex-STRING','AA BB'],'uptime'=>['Timeticks','9000000'],'sys_name'=>['STRING','TESTE']];
foreach($profile->identityOids() as $k=>$oid){$mib[$oid]=$identity[$k];}
$mib[$profile->metric('onu_identifier')['oid'].'.1.1.1']=['STRING','001122334455'];
$mib[$profile->metric('oper_status')['oid'].'.1.1.1']=['INTEGER','200'];
foreach($profile->powerGet() as $k=>$metric){$mib[$metric['oid'].'.1.1.1']=['STRING',$k==='onu_rx_dbm'?'-20.00':'2.00'];}
$fixture=static function()use($json,$mib,$clock,$dir):array{
    $net=new InMemoryNetStore();
    $net->devices[1]=['id'=>1,'provider_id'=>1,'enabled'=>1,'status_interval_s'=>600,'power_interval_s'=>1800,'profile_code'=>'fake','profile_json'=>$json,'mgmt_host'=>'198.51.100.1','credential_ref'=>'NET_CRED_FAKE','expected_identity_json'=>json_encode(['sys_object_id'=>'1.3.6.1.4.1.25355.4.4','self_ip'=>'198.51.100.1','engine_id'=>'AABB'])];
    $net->onus[10]=['id'=>10,'provider_id'=>1,'device_id'=>1,'slot'=>1,'pon_index'=>1,'onu_index'=>1,'snmp_index'=>'1.1.1','onu_identifier'=>'001122334455','identifier_type'=>'epon_mac','first_seen_at'=>'2026-01-01 00:00:00','last_seen_at'=>'2026-11-12 11:59:00','removed_at'=>null,'oper_status'=>'online','raw_status_code'=>'200','status_since'=>'2026-01-01 00:00:00','stale'=>0,'missing_cycles'=>0];
    $net->pollStates[1]=['last_result'=>'ok','consecutive_failures'=>0,'last_attempt_at'=>'2026-11-12 11:59:00','last_power_attempt_at'=>'2026-11-12 11:59:00'];
    $s=new InMemoryNetAlertStore($net);
    $transports=new ArrayObject();
    $factory=static function()use($mib,$transports){$t=new FakeSnmpTransport($mib);$transports[]=$t;return $t;};
    $engine=new NetAlertEngine($net,$s,$clock);
    $tick=new NetTick($net,$engine,$factory,new NetDeviceLock($dir),$dir,$clock);
    return [$net,$s,$tick,$transports,$factory,$engine];
};
try {
    [$net,$s,$tick,$ts]=$fixture();
    $r=$tick->run(1); $check($r['result']==='idle' && count($ts)===0,'nada vencido: zero transporte/pedidos');
    $net->pollStates[1]['last_attempt_at']='2026-11-12 11:50:00';
    $r=$tick->run(1); $check($r['status']['result']==='ok' && $r['power']===null && count($ts)===1,'status vencido, potencia recente');
    $check($net->pollStates[1]['last_attempt_at']===$now && $net->pollStates[1]['last_power_attempt_at']==='2026-11-12 11:59:00','status nao muda tentativa potencia');
    $net->pollStates[1]['last_power_attempt_at']='2026-11-12 11:30:00';
    $r=$tick->run(1); $check($r['status']===null && $r['power']['result']==='ok' && isset($net->power[10]),'potencia vencida com ultimo status ok');
    $check($net->pollStates[1]['last_power_attempt_at']===$now,'marca tentativa potencia');
    [$net,$s,$tick,$ts]=$fixture(); $net->pollStates[1]['last_attempt_at']='2026-11-12 11:00:00'; $net->pollStates[1]['last_power_attempt_at']=null;
    $r=$tick->run(1); $check($r['status']['result']==='ok' && $r['power']['result']==='ok' && count($ts)===2,'status e potencia vencidos, nessa ordem');
    [$net,$s,$tick,$ts,$factory,$engine]=$fixture();
    $fails=new ArrayObject();
    $timeoutFactory=static function()use($mib,$fails){$t=new FakeSnmpTransport($mib,static fn()=>'timeout');$fails[]=$t;return $t;};
    $tick=new NetTick($net,$engine,$timeoutFactory,new NetDeviceLock($dir),$dir,$clock);
    $net->pollStates[1]['last_attempt_at']='2026-11-12 11:00:00'; $net->pollStates[1]['last_power_attempt_at']=null;
    $r=$tick->run(1); $check($r['status']['result']==='timeout' && $r['power']===null && count($fails)===1,'status falha bloqueia potencia');
    $check($net->pollStates[1]['last_power_attempt_at']===null,'potencia nao tentada nao atualiza data');
    $net->pollStates[1]['consecutive_failures']=3;
    $r=$tick->run(1,false,true,true);
    $check($r['result']==='backoff' && count($fails)===1,'backoff ignora force e nao cria transporte');
    $check(count($s->openAlerts(1))===1 && $s->openAlerts(1)[0]['alert_type']==='device_unreachable','backoff avalia alertas armazenados');
    $now='2026-11-12 12:15:00'; $r=$tick->run(1);
    $check(count($fails)===2 && $r['status']['result']==='timeout','tenta apos backoff exato');
    $now='2026-11-12 12:00:00';
    [$net,$s,$tick,$ts]=$fixture(); $net->pollStates[1]['last_result']='partial';
    $r=$tick->run(1,false,false,true);
    $check(count($ts)===0 && $r['power_skipped']==='status_not_ok','force-power nao ignora status ruim');
    [$net,$s,$tick,$ts]=$fixture();
    $before=serialize([$net,$s->alerts]); $logBefore=file_get_contents($dir.'/net_tick-2026-11-12.log');
    $r=$tick->run(1,true,true,true);
    $check($r['result']==='dry_run' && count($ts)===0 && serialize([$net,$s->alerts])===$before,'dry-run force nao toca store nem transporte');
    $check(file_get_contents($dir.'/net_tick-2026-11-12.log')===$logBefore,'dry-run nao grava log');
    $r=$tick->run(1,false,true,true); $check(count($ts)===2,'force executa ambos mesmo recentes');
    // Floco concorrente identico ao net_collect, arquivo nao removido durante uso.
    [$net,$s,$tick,$ts]=$fixture();
    $handle=fopen($dir.'/net_collect_device_1.lock','c'); flock($handle,LOCK_EX|LOCK_NB);
    $r=$tick->run(1); $check($r['result']==='locked' && count($ts)===0,'lock compartilhado impede ciclo');
    $net->devices[2]=array_merge($net->devices[1],['id'=>2]); $net->pollStates[2]=$net->pollStates[1];
    $r=$tick->run(2); $check($r['result']==='idle','lock isolado por equipamento');
    flock($handle,LOCK_UN); fclose($handle);
    $check($tick->run(1)['result']==='idle','lock liberado permite proximo tick');
    [$net,$s,$tick,$ts,$factory,$engine]=$fixture();
    $throws=static function(){throw new RuntimeException('SENTINELA_SEGREDO');};
    $tick=new NetTick($net,$engine,$throws,new NetDeviceLock($dir),$dir,$clock);
    $r=$tick->run(1,false,true,true);
    $check($r['result']==='error' && !str_contains(json_encode($r),'SENTINELA_SEGREDO'),'erro arbitrario do transporte nao vaza');
    $log=file_get_contents($dir.'/net_tick-2026-11-12.log');
    $check(!str_contains($log,'SENTINELA_SEGREDO') && !str_contains($log,'credential_ref') && !str_contains($log,'NET_CRED_FAKE'),'JSON log sem segredo ou credencial');
    foreach(explode("\n",trim($log))as$line){$check(is_array(json_decode($line,true)),'cada linha de log e JSON valido');}
    [$net,$s,$tick,$ts]=$fixture(); unset($net->devices[1]['power_interval_s']);
    $r=$tick->run(1,false,true,true); $check($r['result']==='error' && count($ts)===0,'schema ausente para antes do transporte');
    [$net,$s,$tick,$ts,$factory,$engine]=$fixture();
    $tick=new NetTick($net,$engine,$timeoutFactory,new NetDeviceLock($dir),$dir,$clock);
    $r=$tick->run(1,false,false,true);
    $check($r['power']['result']==='timeout' && $net->pollStates[1]['last_power_attempt_at']===$now && $net->pollStates[1]['last_result']==='ok','falha potencia grava tentativa sem corromper estado de status');
    echo "NetTickSmoke: $n verificacoes, zero falhas\n";
}finally{
    foreach(glob($dir.'/*')?:[]as$p){unlink($p);} rmdir($dir);
}
