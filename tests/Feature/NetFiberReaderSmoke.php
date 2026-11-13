<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';

use App\Controllers\NetFiberController;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\Net\NetFiberReader;

$n = 0;
$check = static function (bool $ok, string $m) use (&$n): void { $n++; if (!$ok) { throw new RuntimeException("FALHA: {$m}"); } };
$root = dirname(__DIR__, 2);

// Rotas registradas (GET apenas: tela somente leitura, sem CSRF).
$router = new Router();
(require $root . '/backend/routes.php')($router);
foreach (['/rede-fibra', '/api/rede-fibra', '/api/rede-fibra/onu'] as $path) {
    $check($router->match('GET', $path) !== null, "rota GET {$path}");
    $check($router->match('POST', $path) === null, "rota POST {$path} nao existe (somente leitura)");
}

// Sem login: redireciona para /login (pagina e API), nunca entrega dados.
$_SESSION = [];
$app = new App\Core\Application(new Config([]), new App\Core\Container(), $router);
$st0 = new ReflectionProperty(Response::class, 'status');
$hd0 = new ReflectionProperty(Response::class, 'headers');
foreach (['/rede-fibra', '/api/rede-fibra', '/api/rede-fibra/onu'] as $path) {
    $resp = $app->handle(new Request('GET', $path));
    $check($st0->getValue($resp) === 302 && ($hd0->getValue($resp)['Location'] ?? '') === '/login', "anonimo em {$path} vai para o login");
}
// Menu: "Rede de fibra" so para gestor/admin.
$render = static function (array $access) use ($root): string {
    $program = <<<'PHP'
require $argv[1] . '/backend/bootstrap/autoload.php';
App\Core\Url::setBasePath('/x');
$user = ['name' => 'U', 'role' => 'x', 'access' => json_decode($argv[2], true)];
$currentPath = '/rede-fibra';
$appName = 'ISP';
require $argv[1] . '/backend/Views/layouts/sidebar.php';
PHP;
    return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($program) . ' ' . escapeshellarg($root) . ' ' . escapeshellarg(json_encode($access)) . ' 2>&1');
};
$check(substr_count($render(['is_manager' => true]), 'href="/x/rede-fibra"') === 2 && str_contains($render(['is_admin' => true]), 'href="/x/rede-fibra"'), 'gestor/admin veem Rede de fibra (trilha e Mais)');
$check(!str_contains($render(['can_search_clients' => true]), 'rede-fibra'), 'tecnico nao ve Rede de fibra');

$pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$schema = 'isp_auxiliar_netfiber_sim';
if ($pdo->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$schema'")->fetchColumn()) { throw new RuntimeException('Schema descartavel ja existe: preservar e parar.'); }
$pdo->exec("CREATE DATABASE `$schema`");
try {
    $pdo->exec("USE `$schema`");
    $files = glob($root . '/database/migrations/*.sql'); sort($files);
    foreach ($files as $f) {
        $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($f));
        foreach (explode(';', $sql) as $s) { if (trim($s) !== '') { $pdo->exec($s); } }
    }
    $db = new Database(new Config(['database' => ['host' => 'localhost', 'port' => '3306', 'database' => $schema, 'username' => 'root', 'password' => '', 'charset' => 'utf8mb4']]));
    $local = new LocalRepository($db);
    $providerId = (int) $local->currentProviderId();
    $pdo->exec("INSERT INTO providers (name,slug,status,created_at,updated_at) VALUES ('OUTRO','outro','active',NOW(),NOW())");
    $other = (int) $pdo->lastInsertId();

    $now = date('Y-m-d H:i:s');
    $ago = static fn (int $s): string => date('Y-m-d H:i:s', time() - $s);
    foreach ([[42, $providerId, 'OLT-TESTE'], [43, $other, 'OLT-OUTRO']] as [$id, $p, $name]) {
        $pdo->exec("INSERT INTO map_nodes (id,provider_id,node_type,name,created_at,updated_at) VALUES ($id,$p,'olt','$name',NOW(),NOW())");
        for ($i = 1; $i <= 4; $i++) { $pdo->exec("INSERT INTO map_ports (provider_id,node_id,port_num,label,port_role,created_at,updated_at) VALUES ($p,$id,$i,'PON $i','pon',NOW(),NOW())"); }
    }
    // Site (pop) pai do equipamento 42 e um equipamento que NAO e OLT, sem site (node_type other).
    $pdo->exec("INSERT INTO map_nodes (id,provider_id,node_type,name,created_at,updated_at) VALUES (41,$providerId,'pop','SITE-TESTE',NOW(),NOW())");
    $pdo->exec('UPDATE map_nodes SET parent_node_id = 41 WHERE id = 42');
    $pdo->exec("INSERT INTO map_nodes (id,provider_id,node_type,name,created_at,updated_at) VALUES (44,$providerId,'other','ZZ-EQUIP',NOW(),NOW())");
    $pdo->exec("INSERT INTO map_nodes (id,provider_id,node_type,name,created_at,updated_at) VALUES (45,$providerId,'cto','CTO-TESTE',NOW(),NOW())");
    $pdo->exec('UPDATE map_nodes SET parent_node_id = 45 WHERE id = 44');
    $prof = "SELECT id FROM net_device_profiles WHERE version=3";
    $pdo->exec("INSERT INTO net_devices (id,provider_id,map_node_id,profile_id,mgmt_host,credential_ref,status_interval_s,created_at,updated_at) SELECT 1,$providerId,42,id,'fake','FAKE',600,NOW(),NOW() FROM net_device_profiles WHERE version=3");
    $pdo->exec("INSERT INTO net_devices (id,provider_id,map_node_id,profile_id,mgmt_host,credential_ref,created_at,updated_at) SELECT 2,$other,43,id,'fake','FAKE',NOW(),NOW() FROM net_device_profiles WHERE version=3");
    $pdo->exec("INSERT INTO net_devices (id,provider_id,map_node_id,profile_id,mgmt_host,credential_ref,created_at,updated_at) SELECT 3,$providerId,44,id,'fake','FAKE',NOW(),NOW() FROM net_device_profiles WHERE version=3");
    $pdo->exec("INSERT INTO net_device_poll_state (device_id,last_attempt_at,last_success_at,last_result,updated_at) VALUES (1,'$now','$now','ok',NOW())");
    // id, device, provider, pon, onuIdx, mac, status, rtt, rx, band, removed
    $onus = [
        [1, 1, $providerId, 1, 1, '002EC7000001', 'online', 14001, -20.00, 'ok', null],
        [2, 1, $providerId, 1, 2, '002EC7000002', 'online', 8000, -26.00, 'atencao', null],
        [3, 1, $providerId, 1, 3, '002EC7000003', 'online', 5000, -28.50, 'critico', null],
        [4, 1, $providerId, 1, 4, '002EC7000004', 'offline', 0, -21.00, 'ok', null],
        [5, 1, $providerId, 2, 1, '002EC7000005', 'online', 3000, null, 'unknown', null],
        [6, 1, $providerId, 1, 6, '002EC7000006', 'online', 6000, -22.00, 'ok', null],
        [7, 1, $providerId, 3, 1, '002EC7000007', 'offline', 0, null, 'unknown', $ago(86400)],
        [8, 2, $other, 1, 1, '002EC7000008', 'online', 7000, -19.00, 'ok', null],
    ];
    foreach ($onus as [$id, $dev, $p, $pon, $idx, $mac, $st, $rtt, $rx, $band, $rem]) {
        $remSql = $rem === null ? 'NULL' : "'$rem'";
        $pdo->exec("INSERT INTO net_onus (id,provider_id,device_id,slot,pon_index,onu_index,snmp_index,onu_identifier,identifier_type,first_seen_at,last_seen_at,removed_at) VALUES ($id,$p,$dev,1,$pon,$idx,'1.$pon.$idx','$mac','epon_mac','{$ago(999999)}','$now',$remSql)");
        $pdo->exec("INSERT INTO net_onu_state (onu_id,oper_status,raw_status_code,status_since,stale,updated_at) VALUES ($id,'$st','$rtt','{$ago(7200)}',0,'$now')");
        if ($rx !== null) {
            $pdo->exec("INSERT INTO net_onu_power (onu_id,onu_rx_dbm,onu_tx_dbm,onu_temp_c,measured_at,rx_band,baseline_rx_dbm,baseline_set_at,baseline_n,updated_at) VALUES ($id,$rx,2.4,30.5,'{$ago(120)}','$band',-20.5,'{$ago(86400)}',5,'$now')");
        }
    }
    // Amostras da ONU 1 (7 dias) e uma fora da janela.
    foreach ([[8 * 86400, -19.0], [5 * 86400, -20.0], [3 * 86400, -20.5], [86400, -20.0]] as [$s, $rx]) {
        $pdo->exec("INSERT INTO net_onu_power_samples (onu_id,sampled_at,onu_rx_dbm,reason) VALUES (1,'{$ago($s)}',$rx,'daily')");
    }
    // Quedas: ONU 1 pelo log (energia, 2 h atras, 10 min) e ONU 6 so pelo poll (down 3 h, up 2h45).
    $pdo->exec("INSERT INTO net_onu_outages (provider_id,device_id,onu_id,onu_identifier,source,event_key,started_at,ended_at,duration_s,cause,category,imported_at,updated_at) VALUES ($providerId,1,1,'002EC7000001','log','" . str_repeat('a', 64) . "','{$ago(7200)}','{$ago(6600)}',600,'energia','queda','$now','$now')");
    $pdo->exec("INSERT INTO net_onu_events (onu_id,event_type,occurred_at) VALUES (6,'down','{$ago(10800)}'),(6,'up','{$ago(9900)}')");

    $reader = new NetFiberReader($pdo);
    $t = $reader->tree($providerId);
    $check(count($t['dispositivos']) === 2 && $t['dispositivos'][0]['nome'] === 'OLT-TESTE' && $t['dispositivos'][0]['ultimo_resultado'] === 'ok', 'arvore: so os equipamentos do provedor (OLT com site primeiro, sem site depois)');
    $d0 = $t['dispositivos'][0]; $d1 = $t['dispositivos'][1];
    $check($d0['tecnologia'] === 'epon' && $d0['tipo_no'] === 'olt' && $d0['site'] === ['id' => 41, 'nome' => 'SITE-TESTE', 'tipo' => 'pop'], 'equipamento: tecnologia do perfil, tipo do no e site (pop pai)');
    $check($d1['nome'] === 'ZZ-EQUIP' && $d1['tipo_no'] === 'other' && $d1['site'] === null && $d1['pons'] === [] && $d1['tecnologia'] === 'epon', 'equipamento que nao e OLT e cujo pai (CTO) nao e site: "Sem site", sem PONs');
    $pons = $t['dispositivos'][0]['pons'];
    $check(count($pons) === 4 && $pons[0]['total'] === 5 && $pons[0]['online'] === 4 && $pons[1]['total'] === 1 && $pons[2]['total'] === 0 && $pons[3]['total'] === 0, 'PONs do mapa (port_num) com e sem ONU; ONU removida fora');
    $o1 = $pons[0]['onus'][0];
    $check($o1['mac'] === '00:2E:C7:00:00:01' && $o1['status'] === 'online' && $o1['rx_dbm'] === -20.0 && $o1['rx_band'] === 'ok' && $o1['distancia_m'] === 22638 && $o1['distancia_origem'] === 'estimada pelo RTT', 'ONU: MAC, estado, sinal e distancia estimada (RTT 14001 = 22638 m)');
    $check($o1['cliente'] === null && $o1['cto'] === null && $o1['quedas_24h'] === 1, 'sem vinculo: cliente/CTO null; queda em 24 h');
    $o4 = $pons[0]['onus'][3];
    $check($o4['status'] === 'offline' && $o4['distancia_m'] === null, 'offline sem distancia');
    $o5 = $pons[1]['onus'][0];
    $check($o5['rx_dbm'] === null && $o5['rx_band'] === 'unknown' && $o5['tx_dbm'] === null, 'sem leitura de sinal = null/unknown');
    $o6 = array_values(array_filter($pons[0]['onus'], static fn ($o) => $o['id'] === 6))[0];
    $check($o6['quedas_24h'] === 1, 'queda so do poll aparece em 24 h');
    $s = $t['resumo'];
    $check($s['onus'] === 6 && $s['online'] === 5 && $s['offline'] === 1 && $s['atencao'] === 1 && $s['critico'] === 1 && $s['sem_sinal'] === 1 && $s['quedas_24h'] === 2 && $s['sem_vinculo'] === 6, 'resumo');
    $check($t['limiares'] === ['saturado_dbm' => -8.0, 'atencao_dbm' => -25.0, 'critico_dbm' => -27.0, 'desvio_db' => 3.0], 'limiares atuais (atencao < -25, critico < -27)');
    $check($t['fontes']['quedas_log'] === true && $t['fontes']['vinculo_cliente'] === false && $t['fontes']['cto'] === false && $t['fontes']['sinal_na_olt'] === false, 'fontes: o que ainda nao existe');
    $json = json_encode($t);
    $check(!str_contains((string) $json, '002EC7000008') && !str_contains((string) $json, 'OLT-OUTRO'), 'isolamento por provedor');
    $check($reader->tree($providerId, 2)['dispositivos'] === [] && count($reader->tree($providerId, 3)['dispositivos']) === 1, 'filtro por equipamento: de outro provedor vazio, do proprio 1');

    // Contrato de chaves da API (o fixture do jsdom em tests/net/rede_fibra.js usa as mesmas listas).
    $sortKeys = static function (string $csv): string { $x = explode(',', $csv); sort($x); return implode(',', $x); };
    $keys = static function (array $a): string { $x = array_keys($a); sort($x); return implode(',', $x); };
    $check($keys($t) === $sortKeys('gerado_em,dispositivos,resumo,limiares,fontes'), 'chaves da arvore');
    $check($keys($t['dispositivos'][0]) === $sortKeys('id,nome,ativo,intervalo_status_s,ultima_tentativa,ultima_leitura_ok,ultimo_resultado,pons,tecnologia,tipo_no,site,saude'), 'chaves do equipamento (as atuais + tecnologia, tipo_no, site e saude)');
    $check($keys($pons[0]) === $sortKeys('pon_index,label,onus,total,online'), 'chaves da PON');
    $check($keys($o1) === $sortKeys('id,pon_index,onu_index,mac,status,status_desde,desatualizado,rx_dbm,rx_band,rx_medido_em,tx_dbm,temp_c,distancia_m,distancia_origem,cliente,cto,quedas_24h,ultima_queda_em'), 'chaves da ONU na arvore');
    $check($keys($t['resumo']) === $sortKeys('onus,online,offline,desconhecido,atencao,critico,saturado,sem_sinal,quedas_24h,sem_vinculo,sem_resposta'), 'chaves do resumo');
    $fk = $reader->sheet($providerId, 1);
    $check($keys($fk) === $sortKeys('onu,vizinhos,serie_7d,periodo,quedas,quedas_total,quedas_serie,ultimas_quedas,estavel,estavel_texto,limiares,fontes,cliente,cliente_texto,cto,cto_texto'), 'chaves da ficha');
    $check($keys($fk['onu']) === $sortKeys('id,pon_index,onu_index,mac,status,status_desde,desatualizado,rx_dbm,rx_band,rx_medido_em,tx_dbm,temp_c,distancia_m,distancia_origem,cliente,cto,baseline_dbm,baseline_desde,baseline_amostras,olt_rx_dbm,olt_rx_texto'), 'chaves da ONU na ficha');
    $check($keys($fk['quedas'][0]) === $sortKeys('onu_id,onu_identifier_mascarado,source,precisao,category,maintenance_reason,started_at,ended_at,open,duration_s,downtime_in_period_s,cause,reason'), 'chaves da queda');

    // Ficha.
    $f = $reader->sheet($providerId, 1);
    $check($f['onu']['rx_dbm'] === -20.0 && $f['onu']['baseline_dbm'] === -20.5 && $f['onu']['olt_rx_dbm'] === null && $f['onu']['olt_rx_texto'] === 'ainda não lido', 'ficha: sinal atual, referencia e sinal na OLT ainda nao lido');
    $check($f['vizinhos'] === ['mediana_dbm' => -26.0, 'quantidade' => 3, 'diferenca_db' => 6.0], 'mediana dos vizinhos da PON (sem a propria ONU, so online com leitura)');
    $pts = $f['serie_7d']['pontos'];
    $check(count($pts) === 4 && $pts[0]['rx'] === -20.0 && $pts[3]['t'] === $pts[3]['t'] && end($pts)['rx'] === -20.0 && $pts === array_values($pts) && array_column($pts, 't') === (function ($c) { sort($c); return $c; })(array_column($pts, 't')), 'serie de 7 dias ordenada, sem a amostra de 8 dias, com a leitura atual');
    $check(count($f['quedas']) === 1 && $f['quedas'][0]['reason'] === 'Queda de energia (Dying Gasp)' && $f['quedas'][0]['duration_s'] === 600 && $f['estavel'] === false && $f['estavel_texto'] === null, 'ficha: queda do periodo com motivo e duracao');
    $check($f['cliente'] === null && $f['cliente_texto'] === 'sem vínculo' && $f['cto'] === null && $f['cto_texto'] === 'ainda não lido', 'ficha: cliente e CTO');
    $check(!str_contains(strtolower((string) json_encode($f)), 'fibra'), 'nunca afirma fibra');
    $f1 = $reader->sheet($providerId, 1, '1h');
    $check($f1['quedas'] === [] && $f1['estavel'] === true && $f1['estavel_texto'] === 'Sem queda registrada no período (estável).' && count($f1['ultimas_quedas']) === 1, 'sem registro no periodo = estavel; ultimas quedas (30 d) continuam');
    $f7 = $reader->sheet($providerId, 6, '7d');
    $check(count($f7['quedas']) === 1 && $f7['quedas'][0]['source'] === 'poll' && $f7['quedas'][0]['reason'] === 'Detectada pelo poll, motivo não informado', 'ficha: queda so do poll, motivo nao informado');
    $fc = $reader->sheet($providerId, 1, 'custom', $ago(7800), $ago(6000));
    $check(count($fc['quedas']) === 1 && $fc['periodo']['kind'] === 'custom', 'periodo personalizado');
    try { $reader->sheet($providerId, 1, 'custom', $now, $ago(100)); $check(false, 'periodo invertido aceito'); } catch (InvalidArgumentException $e) { $check(true, 'periodo personalizado invertido rejeitado'); }
    $check($reader->sheet($providerId, 999) === null && $reader->sheet($providerId, 8) === null && $reader->sheet($providerId, 7) === null, 'ONU inexistente, de outro provedor ou removida = null');
    $f5 = $reader->sheet($providerId, 5);
    $check($f5['vizinhos']['mediana_dbm'] === null && $f5['onu']['rx_dbm'] === null && $f5['serie_7d']['pontos'] === [], 'ONU sem leitura: sem mediana e sem serie');

    // Saude da coleta (CP2): ok / atrasada / falha / sem_leitura, sem afirmar a causa.
    $check($d0['saude'] === ['estado' => 'ok', 'minutos' => 0, 'limite_s' => 1200] && $d1['saude']['estado'] === 'sem_leitura' && $d1['saude']['minutos'] === null, 'saude: ok com leitura recente; sem_leitura quando nunca houve');
    $check($t['resumo']['sem_resposta'] === 0 && $o1['desatualizado'] === false, 'sem ONU desatualizada: sem_resposta = 0');
    $pdo->exec("UPDATE net_device_poll_state SET last_success_at = '{$ago(1190)}' WHERE device_id = 1");
    $h = $reader->tree($providerId)['dispositivos'][0]['saude'];
    $check($h['estado'] === 'ok' && $h['minutos'] === 19, 'saude: logo abaixo de 2 intervalos ainda e ok');
    $pdo->exec("UPDATE net_device_poll_state SET last_success_at = '{$ago(1500)}' WHERE device_id = 1");
    $h = $reader->tree($providerId)['dispositivos'][0]['saude'];
    $check($h['estado'] === 'atrasada' && $h['minutos'] === 25 && $h['limite_s'] === 1200, 'saude: leitura de 25 min com intervalo de 10 = atrasada');
    $pdo->exec("UPDATE net_device_poll_state SET last_success_at = '$now', last_result = 'timeout' WHERE device_id = 1");
    $check($reader->tree($providerId)['dispositivos'][0]['saude']['estado'] === 'falha', 'saude: ultimo resultado diferente de ok = falha, mesmo com leitura recente');
    $pdo->exec("UPDATE net_device_poll_state SET last_result = 'partial' WHERE device_id = 1");
    $check($reader->tree($providerId)['dispositivos'][0]['saude']['estado'] === 'falha', 'saude: resultado parcial tambem');
    $pdo->exec("UPDATE net_onu_state SET stale = 1 WHERE onu_id IN (1, 4)");
    $tt = $reader->tree($providerId);
    $dd = array_column($tt['dispositivos'][0]['pons'][0]['onus'], 'desatualizado', 'id');
    $check($dd[1] === true && $dd[4] === true && $dd[2] === false && $tt['resumo']['sem_resposta'] === 2 && $tt['resumo']['offline'] === 1, 'ONU desatualizada (stale) distinta de offline; resumo conta sem_resposta');
    $check($reader->sheet($providerId, 4)['onu']['desatualizado'] === true, 'ficha tambem traz desatualizado');
    $pdo->exec("UPDATE net_onu_state SET stale = 0"); $pdo->exec("UPDATE net_device_poll_state SET last_result = 'ok' WHERE device_id = 1");

    // Limiares do provedor sobrescrevem o padrao.
    $pdo->exec("INSERT INTO provider_settings (provider_id,setting_key,setting_value,updated_at) VALUES ($providerId,'net.rx.warn_dbm','-24',NOW())");
    $check($reader->tree($providerId)['limiares']['atencao_dbm'] === -24.0, 'limiar do provedor (provider_settings net.*)');

    // Controller: acesso e respostas.
    $status = new ReflectionProperty(Response::class, 'status');
    $headers = new ReflectionProperty(Response::class, 'headers');
    $body = new ReflectionProperty(Response::class, 'body');
    $cfg = new Config(['app' => ['name' => 'ISP Teste'], 'paths' => ['views' => $root . '/backend/Views']]);
    $ctl = new NetFiberController(new View($root . '/backend/Views'), $cfg, $local, $db);
    $call = static function (string $method, array $q = []) use ($ctl): Response { return $ctl->$method(new Request('GET', '/x', '', $q)); };
    $_SESSION = ['user' => ['login' => 'tec', 'role' => 'technician', 'name' => 'Tec']];
    foreach (['tree', 'onu'] as $m) {
        $r = $call($m, ['id' => '1']);
        $check($status->getValue($r) === 403 && str_contains((string) $body->getValue($r), 'Acesso negado') && ($headers->getValue($r)['Cache-Control'] ?? '') === 'private, no-store', "tecnico: {$m} 403 sem cache");
    }
    $r = $call('page');
    $check($status->getValue($r) === 302 && ($headers->getValue($r)['Location'] ?? '') === '/dashboard', 'tecnico: pagina redireciona');
    $_SESSION = ['user' => ['login' => 'adm', 'role' => 'admin', 'is_admin' => true, 'name' => 'Adm']];
    $r = $call('tree');
    $d = json_decode((string) $body->getValue($r), true);
    $check($status->getValue($r) === 200 && $d['ok'] === true && $d['data']['resumo']['onus'] === 6 && ($headers->getValue($r)['Cache-Control'] ?? '') === 'private, no-store', 'admin: arvore 200');
    $r = $call('onu', ['id' => '1', 'periodo' => '24h']);
    $d = json_decode((string) $body->getValue($r), true);
    $check($status->getValue($r) === 200 && $d['data']['vizinhos']['quantidade'] === 3, 'admin: ficha 200');
    foreach ([[[], 422], [['id' => 'x'], 422], [['id' => '0'], 422], [['id' => '1', 'periodo' => 'ano'], 422], [['id' => '1', 'periodo' => 'custom'], 422], [['id' => '1', 'periodo' => 'custom', 'de' => '2026-13-99 00:00'], 422], [['id' => '1', 'periodo' => 'custom', 'de' => $now, 'ate' => '2020-01-01 00:00:00'], 422], [['id' => '999'], 404], [['id' => '8'], 404]] as [$q, $code]) {
        $check($status->getValue($call('onu', $q)) === $code, 'ficha: validacao ' . json_encode($q) . " => {$code}");
    }
    $r = $call('onu', ['id' => '1', 'periodo' => 'custom', 'de' => date('Y-m-d\TH:i', time() - 86400), 'ate' => date('Y-m-d\TH:i')]);
    $check($status->getValue($r) === 200, 'periodo custom aceita datetime-local');

    // Pagina HTML (shell completo) para gestor.
    $r = $call('page');
    $html = (string) $body->getValue($r);
    $check($status->getValue($r) === 200 && str_contains($html, 'id="rf-app"') && str_contains($html, 'data-api-tree="/api/rede-fibra"') && str_contains($html, 'data-api-onu="/api/rede-fibra/onu"') && str_contains($html, 'class="app-nav"') && str_contains($html, 'rede-fibra.js') && ($headers->getValue($r)['Cache-Control'] ?? '') === 'private, no-store', 'pagina: 200, shell, APIs e scripts, sem cache');
    $check(!preg_match('/simulad|fict[ií]c|lorem/i', $html), 'pagina sem dados simulados');

    // Sem a migration 034: so poll, avisa nas fontes.
    $pdo->exec('DROP TABLE net_onu_outages');
    $t = $reader->tree($providerId);
    $check($t['fontes']['quedas_log'] === false && $t['resumo']['quedas_24h'] === 1, 'sem 034: so quedas do poll e fonte log marcada como indisponivel');
    $check($reader->sheet($providerId, 1)['fontes']['quedas_log'] === false && $reader->sheet($providerId, 1)['estavel'] === true, 'sem 034: ficha funciona');

    echo "NetFiberReaderSmoke: {$n} verificacoes, zero falhas\n";
} finally {
    $pdo->exec('DROP DATABASE `isp_auxiliar_netfiber_sim`');
    echo "Schema descartavel removido.\n";
}
