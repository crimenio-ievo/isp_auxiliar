<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';
use App\Infrastructure\Net\NetIntervention;
use App\Infrastructure\Net\NetPurge;
use App\Infrastructure\Net\NetAlertEngine;
use App\Infrastructure\Net\NetCliOptions;
use App\Infrastructure\Net\Store\InMemoryNetStore;
use App\Infrastructure\Net\Store\InMemoryNetAlertStore;
use App\Infrastructure\Net\Store\InMemoryNetMaintenanceStore;

$n=0;
$check=static function(bool $ok,string $m) use (&$n):void { $n++; if(!$ok) { throw new RuntimeException($m); } };
$now='2026-11-12 12:00:00'; $clock=static fn()=>$now;
$net=new InMemoryNetStore(); $net->devices=[1=>['id'=>1,'provider_id'=>1],2=>['id'=>2,'provider_id'=>2]];
foreach([1,2,3] as $id) { $net->onus[$id]=['id'=>$id,'device_id'=>$id===3?2:1,'provider_id'=>$id===3?2:1,'pon_index'=>$id===2?2:1,'oper_status'=>'online','removed_at'=>null,'stale'=>0]; }
foreach([1,2,3] as $id) { $net->power[$id]=['baseline_rx_dbm'=>-20.0,'baseline_set_at'=>'2026-11-01 12:00:00','baseline_n'=>5,'bad_streak'=>2,'ok_streak'=>0,'rx_band'=>'critico','onu_rx_dbm'=>-28]; }
$alerts=new InMemoryNetAlertStore($net); $store=new InMemoryNetMaintenanceStore($net,$alerts);
$engine=new NetAlertEngine($net,$alerts,$clock); $engine->run(1);
$intervention=new NetIntervention($net,$store,$clock);
$before=serialize([$net,$alerts,$store->audit]);
$r=$intervention->run(1,1,null,null,'manutencao',true);
$check(serialize([$net,$alerts,$store->audit])===$before && $r['onu_ids']===[1], 'intervencao dry-run nao escreve');
$intervention->run(1,1,null,null,'manutencao');
$check($net->power[1]['baseline_rx_dbm']===null && $net->power[1]['baseline_set_at']===null && $net->power[1]['baseline_n']===0 && $net->power[1]['bad_streak']===0 && $net->power[1]['ok_streak']===0, 'zera referencia e streaks');
$check($net->power[2]['baseline_rx_dbm']===-20.0 && $net->power[3]['baseline_rx_dbm']===-20.0, 'outros escopos intactos');
$open=$alerts->openAlerts(1);
$check($open[0]['silenced_until']==='2026-11-13 12:00:00' && $open[1]['silenced_until']===null, 'silencia aberto do escopo');
$check(count($store->audit)===1 && $store->audit[0]['action']==='net.intervention.create', 'auditoria');
$intervention->run(1,null,2,2,'ONU');
$check($net->power[2]['baseline_n']===0 && count($store->audit)===2, 'escopo ONU');
$net->power[1]['bad_streak']=2; $engine->run(1);
$check(count($alerts->openAlerts(1))===2, 'silencio nao duplica alerta');
foreach([[1,null,null],[1,1,1],[1,null,3],[1,999,null],[9,1,null]] as [$d,$p,$o]) {
    try { $intervention->run($d,$p,$o); $check(false,'escopo invalido aceito'); }
    catch(InvalidArgumentException $e) { $check(true,'escopo invalido rejeitado'); }
}
try { $intervention->run(1,1,null,0); $check(false,'zero horas'); } catch(InvalidArgumentException $e) { $check(true,'zero horas rejeitado'); }
$net->settings['net.alert.mute_default_hours']='48'; $r=$intervention->run(1,1,null,null,'',true);
$check($r['intervention']['silenced_until']==='2026-11-14 12:00:00', 'default sobrescrito');
$opts=NetCliOptions::parse(['--device=1','--reason','troca fibra','--dry-run'],['device','reason'],['dry-run']);
$check(NetCliOptions::positive($opts,'device',true)===1 && $opts['reason']==='troca fibra' && $opts['dry-run'], 'parser CLI com motivo');
foreach([['--device=1','--device=2'],['--foo'],['--apply=false'],['--reason']] as $args) {
    try { NetCliOptions::parse($args,['device','reason'],['apply']); $check(false,'CLI invalida aceita'); }
    catch(InvalidArgumentException $e) { $check(true,'CLI invalida rejeitada'); }
}

// Purga em lotes, limites estritos, alertas abertos preservados e retencao por provedor.
$net->settings=[]; $net->samples=[]; $net->events=[];
for($i=0;$i<5001;$i++) { $net->samples[]=['onu_id'=>1,'sampled_at'=>'2026-01-01 00:00:00']; }
$boundary=date('Y-m-d H:i:s',strtotime($now)-60*86400);
$net->samples[]=['onu_id'=>1,'sampled_at'=>$boundary];
$net->samples[]=['onu_id'=>1,'sampled_at'=>$now];
$net->events[]=['onu_id'=>1,'occurred_at'=>'2026-01-01 00:00:00','event_type'=>'down'];
$net->events[]=['onu_id'=>1,'occurred_at'=>$now,'event_type'=>'up'];
$alerts->alerts[99]=['id'=>99,'provider_id'=>1,'device_id'=>1,'closed_at'=>'2026-01-01 00:00:00'];
$alerts->alerts[100]=['id'=>100,'provider_id'=>1,'device_id'=>1,'closed_at'=>date('Y-m-d H:i:s',strtotime($now)-180*86400)];
$purge=new NetPurge($net,$store,$clock);
$before=serialize([$net->samples,$net->events,$alerts->alerts]);
$r=$purge->run();
$check($r['counts']===['samples'=>5001,'events'=>1,'alerts'=>1], 'conta expirados');
$check(serialize([$net->samples,$net->events,$alerts->alerts])===$before, 'purga simula por padrao');
$r=$purge->run(true);
$check($r['counts']===['samples'=>5001,'events'=>1,'alerts'=>1], 'purga aplica contagens');
$check(max($store->batchSizes)===5000 && in_array(1,$store->batchSizes,true), 'lotes 5000 mais resto');
$check(count($net->samples)===2 && count($net->events)===1, 'preserva recentes e borda');
$check(count($alerts->openAlerts(1))===2 && isset($alerts->alerts[100]), 'abertos e fechado na borda preservados');
$check($purge->run(true)['counts']===['samples'=>0,'events'=>0,'alerts'=>0], 'purga idempotente');
$net->samples[]=['onu_id'=>1,'sampled_at'=>'2026-11-01 00:00:00']; $net->settings['net.retention.samples_days']='1';
$check($purge->run()['counts']['samples']===2, 'retencao configuravel');
echo "NetMaintenanceSmoke: $n verificacoes, zero falhas\n";
