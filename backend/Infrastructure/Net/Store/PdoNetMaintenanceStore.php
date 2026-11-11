<?php
declare(strict_types=1);
namespace App\Infrastructure\Net\Store;
use PDO;

final class PdoNetMaintenanceStore implements NetMaintenanceStore
{
    public function __construct(private PDO $pdo) {}

    public function intervene(array $i, array $onuIds): void
    {
        $this->pdo->beginTransaction();
        try {
            $s = $this->pdo->prepare('SELECT id FROM net_devices WHERE id=? AND provider_id=? FOR UPDATE');
            $s->execute([$i['device_id'], $i['provider_id']]);
            if (!$s->fetchColumn()) { throw new \RuntimeException('Equipamento nao encontrado.'); }
            $s = $this->pdo->prepare('INSERT INTO net_interventions (provider_id,device_id,scope_type,scope_id,created_at,silenced_until,reason) VALUES (:provider_id,:device_id,:scope_type,:scope_id,:created_at,:silenced_until,:reason)');
            $s->execute($i); $interventionId = (int) $this->pdo->lastInsertId();
            $s = $this->pdo->prepare('UPDATE net_alerts SET silenced_until=GREATEST(COALESCE(silenced_until,?),?) WHERE device_id=? AND closed_at IS NULL AND scope_type=? AND scope_id=?');
            $s->execute([$i['silenced_until'],$i['silenced_until'],$i['device_id'],$i['scope_type'],$i['scope_id']]);
            foreach (array_chunk($onuIds, 200) as $ids) {
                $in = implode(',', array_fill(0,count($ids),'?'));
                $s = $this->pdo->prepare("UPDATE net_onu_power p JOIN net_onus o ON o.id=p.onu_id SET p.baseline_rx_dbm=NULL,p.baseline_set_at=NULL,p.baseline_n=0,p.bad_streak=0,p.ok_streak=0,p.updated_at=? WHERE o.device_id=? AND p.onu_id IN ($in)");
                $s->execute(array_merge([$i['created_at'],$i['device_id']],$ids));
                $s = $this->pdo->prepare("UPDATE net_alerts SET silenced_until=GREATEST(COALESCE(silenced_until,?),?) WHERE device_id=? AND closed_at IS NULL AND scope_type='onu' AND scope_id IN ($in)");
                $s->execute(array_merge([$i['silenced_until'],$i['silenced_until'],$i['device_id']],$ids));
            }
            $s = $this->pdo->prepare("INSERT INTO audit_logs (provider_id,actor_login,action,entity_type,entity_id,context_json,created_at) VALUES (?,'net_cli','net.intervention.create','net_intervention',?,?,?)");
            $s->execute([$i['provider_id'],$interventionId,json_encode($i + ['onu_ids'=>$onuIds],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$i['created_at']]);
            $this->pdo->commit();
        } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }

    public function providers(): array
    { return array_map('intval',$this->pdo->query('SELECT DISTINCT provider_id FROM net_devices')->fetchAll(PDO::FETCH_COLUMN)); }

    private function queryParts(string $kind): array
    {
        return match ($kind) {
            'samples' => ['net_onu_power_samples','JOIN net_onus o ON o.id=t.onu_id','o.provider_id','t.sampled_at'],
            'events' => ['net_onu_events','JOIN net_onus o ON o.id=t.onu_id','o.provider_id','t.occurred_at'],
            'alerts' => ['net_alerts','','t.provider_id','t.closed_at'],
            default => throw new \InvalidArgumentException('kind invalido'),
        };
    }
    public function countExpired(string $kind, int $providerId, string $before): int
    {
        [$table,$join,$provider,$date]=$this->queryParts($kind);
        $s=$this->pdo->prepare("SELECT COUNT(*) FROM $table t $join WHERE $provider=? AND $date<?");
        $s->execute([$providerId,$before]); return (int)$s->fetchColumn();
    }
    public function purgeBatch(string $kind, int $providerId, string $before): int
    {
        [$table,$join,$provider,$date]=$this->queryParts($kind);
        $s=$this->pdo->prepare("DELETE FROM $table WHERE id IN (SELECT id FROM (SELECT t.id FROM $table t $join WHERE $provider=? AND $date<? ORDER BY t.id LIMIT 5000) expired)");
        $s->execute([$providerId,$before]); return $s->rowCount();
    }
}
