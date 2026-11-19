#!/usr/bin/env php
<?php
declare(strict_types=1);
use App\Infrastructure\Database\Database;
use App\Infrastructure\Net\NetCliOptions;
use App\Infrastructure\Net\NetDeviceLock;
use App\Infrastructure\Net\NetTick;
use App\Infrastructure\Net\NetTickAll;
use App\Infrastructure\Net\NetAlertEngine;
use App\Infrastructure\Net\NetCredentialRepository;
use App\Infrastructure\Net\NetSecretBox;
use App\Infrastructure\Net\NetTransportFactory;
use App\Infrastructure\Net\Store\PdoNetStore;
use App\Infrastructure\Net\Store\PdoNetAlertStore;

require dirname(__DIR__) . '/backend/bootstrap/app.php';
try {
    $opts=NetCliOptions::parse(array_slice($argv,1),['device'],['all','dry-run','force-status','force-power']);
    $all=isset($opts['all']);
    // --device=ID ou --all (um dos dois, nunca ambos).
    if ($all === isset($opts['device'])) { throw new InvalidArgumentException('Informe --device=ID ou --all.'); }
    $id=$all ? 0 : NetCliOptions::positive($opts,'device',true);
} catch(InvalidArgumentException $e) { fwrite(STDERR,"Uso: php scripts/net_tick.php --device=ID|--all [--dry-run] [--force-status] [--force-power]\n"); exit(1); }
try {
    $app=bootstrapApplication(); $pdo=(new Database($app->config()))->pdo();
    // Falhar antes de qualquer SNMP se a 033 ainda nao estiver aplicada por completo.
    $pdo->query('SELECT power_interval_s FROM net_devices LIMIT 0');
    $pdo->query('SELECT last_power_attempt_at FROM net_device_poll_state LIMIT 0');
    $pdo->query('SELECT bad_streak,ok_streak FROM net_onu_power LIMIT 0');
    $pdo->query('SELECT id FROM net_alerts LIMIT 0');
    $pdo->query('SELECT id FROM net_interventions LIMIT 0');
    $net=new PdoNetStore($pdo); $root=dirname(__DIR__);
    $tick=new NetTick($net,new NetAlertEngine($net,new PdoNetAlertStore($pdo)),new NetTransportFactory(new NetCredentialRepository($pdo, NetSecretBox::fromEnv())),new NetDeviceLock($root.'/tmp'),$root.'/storage/logs');
    if ($all) {
        // Percorre os equipamentos ativos; um lock por equipamento (dentro do NetTick); falha de um nao para os outros.
        $r=(new NetTickAll(
            static fn (): array => array_map('intval',$pdo->query('SELECT id FROM net_devices WHERE enabled = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)),
            static fn (int $deviceId): array => $tick->run($deviceId,isset($opts['dry-run']),isset($opts['force-status']),isset($opts['force-power']))
        ))->run();
        echo json_encode($r,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n";
        exit($r['result']==='ok'?0:2);
    }
    $r=$tick->run($id,isset($opts['dry-run']),isset($opts['force-status']),isset($opts['force-power']));
    echo json_encode($r,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n";
    exit($r['result']==='locked'?3:(in_array($r['result'],['ok','idle','dry_run','backoff'],true)?0:2));
} catch(Throwable $e) { fwrite(STDERR,"Tick nao executado ou interrompido; confira migration 033, locks e configuracao.\n"); exit(2); }
