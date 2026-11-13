<?php
declare(strict_types=1);
namespace App\Infrastructure\Net;
use PDO;

/** Busca local; não consulta cliente, MkAuth nem equipamento. */
final class NetSearchReader
{
    public function __construct(private PDO $pdo) {}

    public function search(int $providerId, string $query): array
    {
        $query=trim($query);
        if (mb_strlen($query)<2 || mb_strlen($query)>100) { return []; }
        $out=[];
        $mac=preg_replace('/[\s:.\-]/','',$query);
        if (preg_match('/^[a-fA-F0-9]{4,12}$/D',$mac)) {
            $s=$this->pdo->prepare("SELECT o.id,o.onu_index,o.pon_index,n.name FROM net_onus o JOIN net_devices d ON d.id=o.device_id JOIN map_nodes n ON n.id=d.map_node_id WHERE o.provider_id=? AND d.provider_id=? AND n.provider_id=? AND o.removed_at IS NULL AND o.onu_identifier LIKE ? ORDER BY o.id LIMIT 10");
            $s->execute([$providerId,$providerId,$providerId,'%'.strtoupper($mac).'%']);
            foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r) { $out[]=['tipo'=>'onu','titulo'=>'ONU '.$r['onu_index'].' · PON '.$r['pon_index'].' · OLT '.$r['name'],'url'=>'/rede-fibra?onu='.$r['id']]; }
        }
        $like='%'.strtr($query,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
        $s=$this->pdo->prepare("SELECT n.id,n.name,d.id device_id FROM map_nodes n LEFT JOIN net_devices d ON d.map_node_id=n.id AND d.provider_id=n.provider_id WHERE n.provider_id=? AND n.node_type IN ('olt','switch','router','radio','server','other') AND (n.name LIKE ? ESCAPE '!' OR d.mgmt_host LIKE ? ESCAPE '!') ORDER BY n.id LIMIT 10");
        $s->execute([$providerId,$like,$like]);
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r) { $out[]=['tipo'=>'equipamento','titulo'=>$r['name'],'url'=>$r['device_id']?'/rede-fibra?dispositivo='.$r['device_id']:'/mapa']; }
        return array_slice($out,0,15);
    }
}
