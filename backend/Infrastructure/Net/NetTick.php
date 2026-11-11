<?php
declare(strict_types=1);
namespace App\Infrastructure\Net;
use App\Infrastructure\Net\Store\NetStore;

/** Decide vencimento e reaproveita os coletores, sem duplicar SNMP ou regras de coleta. */
final class NetTick
{
    private $clock;
    private $transportFactory;
    public function __construct(private NetStore $net, private NetAlertEngine $alerts, callable $transportFactory,
        private NetDeviceLock $lock, private ?string $logDirectory = null, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn () => date('Y-m-d H:i:s');
        $this->transportFactory = $transportFactory;
    }

    public function run(int $deviceId, bool $dryRun = false, bool $forceStatus = false, bool $forcePower = false): array
    {
        if ($deviceId < 1) { throw new \InvalidArgumentException('Equipamento invalido.'); }
        $now = ($this->clock)();
        $summary = ['device_id'=>$deviceId,'started_at'=>$now,'dry_run'=>$dryRun,'result'=>'idle','status'=>null,'power'=>null];
        if (!$this->lock->acquire($deviceId)) { return array_merge($summary,['result'=>'locked']); }
        try {
            $device = $this->net->findDevice($deviceId);
            if (!$device || !(int)($device['enabled'] ?? 0)) { throw new \RuntimeException('Equipamento indisponivel.'); }
            if (!array_key_exists('power_interval_s',$device)) { throw new \RuntimeException('Migration 033 necessaria.'); }
            $poll = $this->net->pollState($deviceId) ?? [];
            $t = NetSettings::resolve($this->net->providerSettings((int)$device['provider_id']));
            $due = static fn (?string $at,int $interval): bool => $at === null || strtotime($now)-strtotime($at) >= $interval;
            $statusDue = $forceStatus || $due($poll['last_attempt_at'] ?? null,max(1,(int)($device['status_interval_s'] ?? 600)));
            $powerDue = $forcePower || $due($poll['last_power_attempt_at'] ?? null,max(1,(int)$device['power_interval_s']));
            $backoff = (int)($poll['consecutive_failures'] ?? 0) >= $t['net.tick.backoff_failures'] && !$due($poll['last_attempt_at'] ?? null,$t['net.tick.backoff_min']*60);
            $summary['due'] = ['status'=>$statusDue,'power'=>$powerDue,'backoff'=>$backoff];
            if ($dryRun) {
                // Simula apenas o plano. Ao contrario de net_collect --dry-run, nao acessa nem o equipamento.
                $summary['result']='dry_run';
            } elseif ($backoff) {
                // As flags force vencem os intervalos, mas nao o backoff nem a exigencia de status ok.
                $summary['result']='backoff';
            } else {
                $statusOk = ($poll['last_result'] ?? null)==='ok';
                if ($statusDue) {
                    $r = (new NetStatusCollector($this->net,$this->transportFactory,$this->clock))->run($deviceId);
                    $summary['status']=$this->safeResult($r);
                    $statusOk = $r['result']==='ok';
                    $summary['result']=$r['result'];
                }
                if ($powerDue && $statusOk) {
                    $this->net->savePollState($deviceId,['last_power_attempt_at'=>($this->clock)()]);
                    $r = (new NetPowerCollector($this->net,$this->transportFactory,$this->clock))->run($deviceId);
                    $summary['power']=$this->safeResult($r);
                    $summary['result']=$r['result'];
                } elseif ($powerDue && !$statusOk) { $summary['power_skipped']='status_not_ok'; }
            }
            $a = $this->alerts->run($deviceId,$dryRun);
            $summary['alerts']=['upserts'=>count($a['upserts']),'closed'=>count($a['closed_ids']),'suppressed'=>$a['suppressed']];
        } catch (\Throwable $e) {
            // Nao registrar mensagens arbitrarias do transporte/configuracao (podem conter segredos).
            $summary['result']='error'; $summary['error']='Falha interna; conferir schema, perfil e configuracao local.';
        } finally {
            $this->lock->release();
        }
        $summary['finished_at']=($this->clock)();
        if (!$dryRun && $this->logDirectory !== null) {
            if (!is_dir($this->logDirectory) && !@mkdir($this->logDirectory,0750,true) && !is_dir($this->logDirectory)) { throw new \RuntimeException('Log indisponivel.'); }
            $path=$this->logDirectory.'/net_tick-'.substr($now,0,10).'.log';
            if (file_put_contents($path,json_encode($summary,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n",FILE_APPEND|LOCK_EX)===false) { throw new \RuntimeException('Falha ao registrar tick.'); }
        }
        return $summary;
    }

    private function safeResult(array $r): array
    {
        return array_intersect_key($r,array_flip(['result','requests','cycle_ms','onus_seen','onus_lidas','amostras','faixas']));
    }
}
