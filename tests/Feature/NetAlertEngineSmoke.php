<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';
use App\Infrastructure\Net\NetAlertEngine;
use App\Infrastructure\Net\NetSettings;
use App\Infrastructure\Net\Store\InMemoryNetStore;
use App\Infrastructure\Net\Store\InMemoryNetAlertStore;

$n = 0;
$check = static function (bool $ok, string $message) use (&$n): void { $n++; if (!$ok) { throw new RuntimeException($message); } };
$now = '2026-11-12 12:00:00';
$clock = static function () use (&$now) { return $now; };
$fixture = static function () use ($clock): array {
    $net = new InMemoryNetStore();
    $net->devices[1] = ['id' => 1, 'provider_id' => 1];
    $net->pollStates[1] = ['last_result' => 'ok', 'consecutive_failures' => 0];
    for ($id=1; $id<=6; $id++) { $net->onus[$id] = ['id' => $id, 'device_id' => 1, 'provider_id' => 1, 'pon_index' => $id<=4 ? 1 : 2, 'removed_at' => null, 'oper_status' => 'online', 'stale' => 0]; }
    $alerts = new InMemoryNetAlertStore($net);
    return [$net, $alerts, new NetAlertEngine($net, $alerts, $clock)];
};
$event = static function ($net, int $id, string $type, string $at, array $details = []): void { $net->events[] = ['onu_id' => $id, 'event_type' => $type, 'occurred_at' => $at, 'details' => $details]; };
$ofType = static fn ($alerts, string $type) => array_values(array_filter($alerts->openAlerts(1), static fn ($a) => $a['alert_type'] === $type));
$badPower = ['rx_band' => 'atencao', 'onu_rx_dbm' => -26, 'bad_streak' => 2, 'ok_streak' => 0, 'baseline_rx_dbm' => -20, 'baseline_set_at' => '2026-11-12 10:00:00'];
$check(NetSettings::resolve(['net.alert.mass_pct'=>'999'])['net.alert.mass_pct'] === 100, 'limiar validado');
[$net,$s,$e]=$fixture(); $e->run(1); $check($s->alerts===[], 'sem condicoes: sem alertas');
$net->power[1]=$badPower;
$e->run(1,true); $check($s->alerts===[], 'dry-run nao grava');
$e->run(1); $check(count($ofType($s,'rx_range'))===1, 'rx_range abre apos 2 ciclos');
$e->run(1); $check(count($s->alerts)===1, 'dedupe');
$net->power[1]['rx_band']='saturado'; $e->run(1); $check($ofType($s,'rx_range')[0]['severity']==='critico', 'range atualiza severidade');
$net->power[1]['bad_streak']=0; $net->power[1]['ok_streak']=1; $net->power[1]['rx_band']='ok'; $e->run(1);
$check(count($ofType($s,'rx_range'))===1, 'um ok nao fecha');
$net->power[1]['ok_streak']=2; $e->run(1); $check($ofType($s,'rx_range')===[], 'dois ok fecham');
$net->power[1]=$badPower; $net->power[1]['bad_streak']=1; $e->run(1); $check($ofType($s,'rx_range')===[], 'um ruim nao abre');
$net->power[1]['bad_streak']=2; $net->settings['net.alert.band_cycles']='3'; $e->run(1); $check($ofType($s,'rx_range')===[], 'provider settings sobrescreve');
$net->settings=[];
$net->pollStates[1]=['last_result'=>'timeout','consecutive_failures'=>3]; $e->run(1);
$check(count($ofType($s,'device_unreachable'))===1 && $ofType($s,'rx_range')===[], 'inacessivel suprime filhos');
$e->run(1); $check(count($ofType($s,'device_unreachable'))===1, 'inacessivel dedupe');
$net->pollStates[1]['last_result']='error'; $e->run(1); $check($ofType($s,'rx_range')===[], 'error nao prova recuperacao');
$net->pollStates[1]=['last_result'=>'ok','consecutive_failures'=>0]; $e->run(1);
$check($ofType($s,'device_unreachable')===[] && count($ofType($s,'rx_range'))===1, 'ok fecha device e libera filhos');
$net->pollStates[1]=['last_result'=>'partial','consecutive_failures'=>3]; $e->run(1);
$check(count($ofType($s,'device_unreachable'))===1 && count($ofType($s,'rx_range'))===1, 'partial abre; preserva filho existente');
[$net,$s,$e]=$fixture(); $net->pollStates[1]=['last_result'=>'timeout','consecutive_failures'=>2]; $e->run(1); $check($s->alerts===[], 'menos falhas nao abre');

// PON pequena: dois online caem juntos. Offline antigo nao entra no denominador.
[$net,$s,$e]=$fixture();
$net->onus[1]['oper_status']='offline'; $net->onus[2]['oper_status']='offline';
$net->onus[3]['oper_status']='offline'; $net->onus[4]['oper_status']='offline';
$event($net,1,'down','2026-11-12 11:55:00'); $event($net,2,'down','2026-11-12 11:55:00');
$e->run(1); $mass=$ofType($s,'mass_down_pon');
$check(count($mass)===1 && $mass[0]['scope_id']===1001, 'PON de dois: massa abre');
$check($mass[0]['details']['online_before']===2 && $mass[0]['details']['onu_ids']===[1,2], 'offline antigo nao conta');
$check($mass[0]['details']['causa']==='indeterminada (sem dying gasp no SNMP)', 'sem inventar dying gasp');
$e->run(1); $check(count($s->alerts)===1, 'massa dedupe');
$now='2026-11-12 14:00:00'; $e->run(1); $check(count($ofType($s,'mass_down_pon'))===1, 'massa permanece alem da janela');
$net->onus[1]['oper_status']='online'; $event($net,1,'up',$now); $e->run(1); $check(count($ofType($s,'mass_down_pon'))===1, 'metade voltou nao fecha');
$net->onus[2]['oper_status']='online'; $event($net,2,'up',$now); $e->run(1); $check($ofType($s,'mass_down_pon')===[], 'mais da metade voltou fecha');
$e->run(1); $check(count($s->alerts)===1, 'massa recuperada nao reabre');
$now='2026-11-12 12:00:00';
[$net,$s,$e]=$fixture(); $net->onus[5]['oper_status']='offline'; $event($net,5,'down','2026-11-12 11:59:00'); $e->run(1); $check($ofType($s,'mass_down_pon')===[], 'uma ONU nao e massa');
[$net,$s,$e]=$fixture();
foreach ([1,2,3] as $id) { $net->onus[$id]['oper_status']='offline'; $event($net,$id,'down','2026-11-12 11:59:00'); }
$e->run(1); $check($ofType($s,'mass_down_pon')===[], 'minimo 4 com 4 online exige 4');
$net->settings=['net.alert.mass_min_onus'=>'2','net.alert.mass_pct'=>'80']; $e->run(1); $check($ofType($s,'mass_down_pon')===[], 'percentual respeitado');
$net->settings['net.alert.mass_pct']='50'; $e->run(1); $check(count($ofType($s,'mass_down_pon'))===1, 'limiares de massa personalizados');

[$net,$s,$e]=$fixture();
for($i=0;$i<5;$i++) { $event($net,1,'down',sprintf('2026-11-12 11:%02d:00',$i)); }
$e->run(1); $check(count($ofType($s,'onu_unstable'))===1, 'instavel com cinco downs');
$now='2026-11-13 10:59:00'; $e->run(1); $check(count($ofType($s,'onu_unstable'))===1, 'instavel nao fecha so porque count caiu');
$now='2026-11-13 11:05:00'; $e->run(1); $check($ofType($s,'onu_unstable')===[], 'fecha apos 24h sem down');
$now='2026-11-12 12:00:00';
[$net,$s,$e]=$fixture(); $net->power[1]=$badPower;
$event($net,1,'rx_deviation','2026-11-12 11:00:00',['state'=>'enter']); $e->run(1);
$check(count($ofType($s,'rx_deviation'))===1, 'deviation enter abre');
$event($net,1,'rx_deviation','2026-11-12 11:30:00',['state'=>'exit']); $e->run(1); $check($ofType($s,'rx_deviation')===[], 'ultimo exit fecha');
$event($net,1,'rx_deviation','2026-11-12 11:40:00',['state'=>'enter']); $e->run(1);
$net->onus[1]['oper_status']='offline'; $e->run(1); $check($ofType($s,'rx_deviation')===[] && $ofType($s,'rx_range')===[], 'offline fecha potencia');
$net->onus[1]['oper_status']='online'; $net->onus[1]['removed_at']=$now; $e->run(1); $check($ofType($s,'rx_range')===[], 'removida nao abre');
$net->onus[1]['removed_at']=null; $net->onus[1]['stale']=1; $e->run(1); $check($ofType($s,'rx_range')===[], 'stale nao abre');
$net->onus[1]['stale']=0; $net->power[1]['baseline_rx_dbm']=null; $e->run(1); $check($ofType($s,'rx_deviation')===[], 'referencia zerada nao usa evento velho');

[$net,$s,$e]=$fixture(); $net->power[1]=$badPower;
$s->interventions[]=['device_id'=>1,'scope_type'=>'pon','scope_id'=>1001,'silenced_until'=>'2026-11-13 12:00:00'];
$e->run(1); $a=$ofType($s,'rx_range')[0];
$check($a['silenced_until']==='2026-11-13 12:00:00', 'novo alerta herda silencio PON');
$e->run(1); $check(count($s->alerts)===1 && $ofType($s,'rx_range')[0]['id']===$a['id'], 'silenciado continua registrado sem reabrir');
$net->power[5]=$badPower; $e->run(1);
$check($ofType($s,'rx_range')[1]['silenced_until']===null, 'silencio nao vaza para outra PON');
$s->interventions[]=['device_id'=>1,'scope_type'=>'onu','scope_id'=>5,'silenced_until'=>'2026-11-13 13:00:00']; $e->run(1);
$check($ofType($s,'rx_range')[1]['silenced_until']==='2026-11-13 13:00:00', 'silencio ONU atualiza aberto');
$before=serialize($s->alerts); $e->run(1,true); $check(serialize($s->alerts)===$before, 'dry-run preserva existentes');
echo "NetAlertEngineSmoke: $n verificacoes, zero falhas\n";
