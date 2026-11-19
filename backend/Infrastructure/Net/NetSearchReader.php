<?php
declare(strict_types=1);
namespace App\Infrastructure\Net;
use PDO;

/**
 * Busca local da rede para a busca global do topo (so gestor/admin, ver NetFiberController::search).
 * Nao consulta MkAuth nem equipamento. Tipos: onu (MAC parcial de 4+ hex), equipamento (nome/IP), cto (nome), pon ("PON 2 OLT-001"),
 * cliente (so clientes JA vinculados a CTO/ONU, por nome ou login, mostrando CTO e ONU). Cada resultado abre a tela certa.
 */
final class NetSearchReader
{
    private const LIMIT = 20;

    public function __construct(private PDO $pdo) {}

    /** @return list<array{tipo:string,titulo:string,detalhe:?string,url:string}> */
    public function search(int $providerId, string $query): array
    {
        $query=trim($query);
        if (mb_strlen($query)<2 || mb_strlen($query)>100) { return []; }
        $out=[];
        $add=static function(string $tipo,string $titulo,string $url,?string $detalhe=null) use (&$out):void { $out[]=['tipo'=>$tipo,'titulo'=>$titulo,'detalhe'=>$detalhe,'url'=>$url]; };

        // ONU por MAC parcial
        $mac=preg_replace('/[\s:.\-]/','',$query);
        if (preg_match('/^[a-fA-F0-9]{4,12}$/D',$mac)) {
            $s=$this->pdo->prepare("SELECT o.id,o.onu_index,o.pon_index,n.name FROM net_onus o JOIN net_devices d ON d.id=o.device_id JOIN map_nodes n ON n.id=d.map_node_id WHERE o.provider_id=? AND d.provider_id=? AND n.provider_id=? AND o.removed_at IS NULL AND o.onu_identifier LIKE ? ORDER BY o.id LIMIT 5");
            $s->execute([$providerId,$providerId,$providerId,'%'.strtoupper($mac).'%']);
            foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r) { $add('onu','ONU '.$r['onu_index'].' · PON '.$r['pon_index'].' · OLT '.$r['name'],'/rede-fibra?onu='.$r['id']); }
        }

        $like='%'.strtr($query,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';

        // PON: "PON 2", "PON 2 OLT-001" ou "OLT-001 PON 2"
        $pon=null;
        if (preg_match('/^\s*pon\s*(\d{1,3})(?:\s+(.+))?$/iu',$query,$m)) { $pon=[(int)$m[1],trim((string)($m[2]??''))]; }
        elseif (preg_match('/^\s*(.+?)\s+pon\s*(\d{1,3})\s*$/iu',$query,$m)) { $pon=[(int)$m[2],trim($m[1])]; }
        if ($pon!==null) {
            $oltLike='%'.strtr($pon[1],['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
            $s=$this->pdo->prepare("SELECT d.id AS device_id,n.name,p.port_num,p.label,(SELECT COUNT(*) FROM net_onus o WHERE o.device_id=d.id AND o.pon_index=p.port_num AND o.removed_at IS NULL) AS onus
                FROM map_ports p JOIN map_nodes n ON n.id=p.node_id JOIN net_devices d ON d.map_node_id=n.id AND d.provider_id=n.provider_id
                WHERE n.provider_id=? AND p.provider_id=? AND p.port_role='pon' AND p.port_num=? AND (?='' OR n.name LIKE ? ESCAPE '!') ORDER BY n.name LIMIT 3");
            $s->execute([$providerId,$providerId,$pon[0],$pon[1],$oltLike]);
            foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r) { $add('pon',(string)($r['label']?:'PON '.$r['port_num']).' · OLT '.$r['name'],'/rede-fibra?dispositivo='.$r['device_id'].'&pon='.$r['port_num'],(int)$r['onus'].' ONU(s) lida(s)'); }
        }

        // Equipamento (OLT, switch...) por nome ou IP de gerencia
        $s=$this->pdo->prepare("SELECT n.id,n.name,d.id device_id,d.mgmt_host FROM map_nodes n LEFT JOIN net_devices d ON d.map_node_id=n.id AND d.provider_id=n.provider_id WHERE n.provider_id=? AND n.node_type IN ('olt','switch','router','radio','server','other') AND (n.name LIKE ? ESCAPE '!' OR d.mgmt_host LIKE ? ESCAPE '!') ORDER BY n.id LIMIT 4");
        $s->execute([$providerId,$like,$like]);
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $add('equipamento',(string)$r['name'],'/rede-equipamentos?no='.$r['id'],$r['mgmt_host']!==null?(string)$r['mgmt_host']:null);
            if ($r['device_id']!==null) { $add('telemetria',(string)$r['name'].' · telemetria','/rede-fibra?dispositivo='.$r['device_id']); }
        }

        // CTO por nome
        $s=$this->pdo->prepare("SELECT n.id,n.name,n.attrs_json FROM map_nodes n WHERE n.provider_id=? AND n.node_type='cto' AND n.name LIKE ? ESCAPE '!' ORDER BY n.name LIMIT 5");
        $s->execute([$providerId,$like]);
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cap=json_decode((string)($r['attrs_json']??''),true)['capacity']??null;
            $add('cto','CTO '.$r['name'],'/rede-ctos/ficha?id='.$r['id'],$cap!==null?'capacidade '.(int)$cap.' porta(s)':null);
        }

        // Clientes ja vinculados (nome/login do vinculo), com CTO e ONU
        if (mb_strlen($query)>=3 && $this->bindingsAvailable()) {
            $s=$this->pdo->prepare("SELECT b.mkauth_login,b.cliente_nome,n.name AS cto,p.label AS porta,o.pon_index,o.onu_index
                FROM client_network_bindings b LEFT JOIN map_nodes n ON n.id=b.cto_node_id LEFT JOIN map_ports p ON p.id=b.cto_port_id LEFT JOIN net_onus o ON o.id=b.onu_id
                WHERE b.provider_id=? AND b.ativo=1 AND (b.cliente_nome LIKE ? ESCAPE '!' OR b.mkauth_login LIKE ? ESCAPE '!') ORDER BY b.cliente_nome,b.mkauth_login LIMIT 5");
            $s->execute([$providerId,$like,$like]);
            foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $parts=[];
                $parts[]=$r['cto']!==null?'CTO '.$r['cto'].($r['porta']!==null?' · '.$r['porta']:''):'sem CTO';
                $parts[]=$r['onu_index']!==null?'ONU '.$r['onu_index'].' · PON '.$r['pon_index']:'sem ONU';
                $add('cliente',(string)($r['cliente_nome']?:$r['mkauth_login']).' ('.$r['mkauth_login'].')','/clientes/detalhe?login='.rawurlencode((string)$r['mkauth_login']),implode(' · ',$parts));
            }
        }

        return array_slice($out,0,self::LIMIT);
    }

    private function bindingsAvailable(): bool
    {
        try { $this->pdo->query('SELECT id FROM client_network_bindings LIMIT 0'); return true; } catch (\Throwable) { return false; }
    }
}
