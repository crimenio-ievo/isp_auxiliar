<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/backend/bootstrap/autoload.php';
use App\Infrastructure\Net\NetClockSegments as C;
use App\Infrastructure\Net\NetLogImporter;
use App\Infrastructure\Net\OltLogParser;
use App\Infrastructure\Net\Store\InMemoryNetStore;
use App\Infrastructure\Net\Store\InMemoryNetOutageStore;
$n=0; $check=static function(bool $v,string $m)use(&$n){$n++;if(!$v)throw new RuntimeException($m);};
$seg=C::parse(['2026-11-12 14:23:39,+82166']);
$check(C::convert('2026-11-12 14:23:39','America/Sao_Paulo','UTC',$seg)==='2026-11-13 13:13:05','limite inclusivo');
$check(C::convert('2026-11-12 14:23:40','America/Sao_Paulo','UTC',$seg)==='2026-11-12 17:23:40','apos limite fuso');
$check(C::convert('2026-11-12 13:39:44','America/Sao_Paulo','America/Sao_Paulo',$seg)==='2026-11-13 09:29:10','conversao Brasil');
foreach([['2026-13-01 00:00:00,+1'],['2026-01-01 00:00:00,-1'],['2026-01-01 00:00:00,+172801'],['2026-01-01 00:00:00,+1','2026-01-01 00:00:00,+2'],array_fill(0,6,'2026-01-01 00:00:00,+1')] as $bad){try{C::parse($bad);$check(false,'aceitou segmento invalido');}catch(InvalidArgumentException){$check(true,'rejeita');}}
$net=new InMemoryNetStore();$net->devices=[1=>['id'=>1,'provider_id'=>1]];$s=new InMemoryNetOutageStore();$i=new NetLogImporter($net,$s,new OltLogParser());
$log="[2026-11-12 13:39:44] ONU 0/2:8 [ AA:BB:CC:01:02:03 ] [Na] linkdown\n[2026-11-12 14:17:57] ONU 0/2:8 [ AA:BB:CC:01:02:03 ] [Na] linkup\n";
$r=$i->run(1,$log,'America/Sao_Paulo','UTC',true,$seg);$check($r['novas']===1 && $r['divergentes']===0,'primeiro import');
$r=$i->run(1,$log,'America/Sao_Paulo','UTC',true,$seg);$check($r['novas']===0 && $r['divergentes']===0,'idempotencia');
$r=$i->run(1,$log,'America/Sao_Paulo','UTC',false,[]);$check($r['divergentes']===1,'detecta divergencia');
$old=serialize([$s->rows,$s->audit]);$r=$i->run(1,$log,'America/Sao_Paulo','UTC',true,[]);$check($r['recusado']===true && $r['divergentes']===1 && serialize([$s->rows,$s->audit])===$old,'divergencia nao grava');
$check($s->audit[0]['context']['clock_segments']===$seg,'auditoria');
echo "NetClockSegmentsSmoke: $n verificacoes, zero falhas\n";
