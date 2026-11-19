<?php
declare(strict_types=1);
// Migration 048: N clientes ativos por ONU, porta da CTO como ocupacao da ONU, modo PPPoE por vinculo e rollback.
// Schema descartavel; MkAuth substituido por um diretorio falso (nunca toca no MkAuth, na OLT nem no banco real).
$root = dirname(__DIR__, 2);
require $root . '/backend/bootstrap/autoload.php';
require $root . '/tests/support/ScratchSchema.php';

use App\Controllers\MapController;
use App\Controllers\NetBindingController;
use App\Controllers\NetCtoController;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\Map\MapRepository;
use App\Infrastructure\Net\ClientDirectory;
use App\Infrastructure\Net\OnuMatchSuggester;
use App\Services\Net\MapNodeGateway;
use Tests\Support\ScratchSchema;

$n = 0;
$check = static function (bool $ok, string $m) use (&$n): void { $n++; if (!$ok) { throw new RuntimeException("FALHA: {$m}"); } };
/** Diretorio de clientes falso (no lugar do MkAuth). */
$directory = new class () implements ClientDirectory {
    public bool $down = false;
    public array $calls = [];
    public array $clients = ['joao' => 'João Teste', 'maria' => 'Maria Teste', 'pedro' => 'Pedro Teste', 'ana' => 'Ana Teste'];
    public array $callers = [];
    public function search(string $term, int $limit): array { $this->calls[] = 'search'; if ($this->down) { throw new RuntimeException('mkauth fora (detalhe-interno)'); }
        $out = []; foreach ($this->clients as $l => $nm) { if (stripos($nm . $l, $term) !== false) { $out[] = ['login' => $l, 'nome' => $nm, 'bairro' => 'Centro', 'cidade' => 'Cidade', 'status' => 'ativo', 'cpf_cnpj' => '12345678901']; } } return array_slice($out, 0, $limit); }
    public function find(string $login): ?array { $this->calls[] = 'find'; if ($this->down) { throw new RuntimeException('mkauth fora (detalhe-interno)'); }
        foreach ($this->clients as $l => $nm) { if (strcasecmp($l, $login) === 0) { return ['login' => $l, 'nome' => $nm]; } } return null; }
    public function recentCallerMacs(int $days): array { $this->calls[] = 'callers'; if ($this->down) { throw new RuntimeException('mkauth fora (detalhe-interno)'); } return ['referencia' => '2026-10-13 09:45:43', 'inicio' => '2026-09-13 09:45:43', 'callers' => $this->callers]; }
    public array $briefs = [];
    public function briefByLogins(array $logins): array { $this->calls[] = 'brief'; if ($this->down) { throw new RuntimeException('mkauth fora (detalhe-interno)'); } $o = []; foreach ($logins as $l) { if (isset($this->briefs[strtolower($l)])) { $o[strtolower($l)] = $this->briefs[strtolower($l)]; } } return $o; }
    public function latestVisibleDate(): array { return ['data' => '2026-10-08', 'origem' => 'cliente']; }
    public function namesByLogins(array $logins): array { $o = []; foreach ($logins as $l) { if (isset($this->clients[strtolower($l)])) { $o[strtolower($l)] = $this->clients[strtolower($l)]; } } return $o; }
};

$schema = (new ScratchSchema($root, 'netbind'))->create();
try {
    $pdo = $schema->pdo;
    $local = new LocalRepository($schema->database, 'default');
    $providerId = (int) $local->currentProviderId();
    $pdo->exec("INSERT INTO providers (name,slug,status,created_at,updated_at) VALUES ('OUTRO','outro','active',NOW(),NOW())");
    $other = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO map_nodes (id,provider_id,node_type,name,lat,lng,created_at,updated_at) VALUES (8,$providerId,'pop','POP-T',-20.1,-42.1,NOW(),NOW()),(70,$other,'cto','CTO-OUTRO',-20.5,-42.5,NOW(),NOW())");
    $pdo->exec("INSERT INTO map_nodes (id,provider_id,node_type,name,parent_node_id,created_at,updated_at) VALUES (42,$providerId,'olt','OLT-T',8,NOW(),NOW())");
    $mapRepo = new MapRepository($schema->database);
    $mapCtl = new MapController($local, $mapRepo);
    $gateway = new MapNodeGateway($mapCtl);
    $view = new View($root . '/backend/Views');
    $cfg = new Config(['app' => ['name' => 'T']]);
    $ctl = new NetBindingController($view, $cfg, $local, $schema->database, $mapRepo, $directory);
    $ctoCtl = new NetCtoController($view, $cfg, $local, $schema->database, $mapRepo, $gateway);

    $asManager = static function (): void { $_SESSION = ['user' => ['login' => 'zz_gestor', 'name' => 'ZZ', 'role' => 'manager', 'id' => 0]]; };
    $asTech = static function (): void { $_SESSION = ['user' => ['login' => 'zz_tec', 'name' => 'ZZ', 'role' => 'technician', 'id' => 0]]; };
    $asNobody = static function (): void { $_SESSION = ['user' => ['login' => 'zz_x', 'name' => 'ZZ', 'role' => 'visitante', 'id' => 0]]; };
    $json = static fn (Response $r): array => (array) json_decode($r->body(), true);
    $get = static fn (string $m, array $q = []) => $ctl->{$m}(new Request('GET', '/x', '', $q));
    $post = static function (string $m, array $f, bool $csrf = true) use ($ctl): Response { if ($csrf) { $f['_csrf'] = Csrf::token('rede'); } return $ctl->{$m}(new Request('POST', '/x', '', [], $f)); };
    $count = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();
    $audit = static fn (string $a): int => $count("SELECT COUNT(*) FROM audit_logs WHERE action='$a'");

    // CTOs pelas regras do mapa: A (4 portas), B (4 portas) e uma de outro provedor sem portas.
    $asManager();
    foreach ([['CTO-A', '-20.80, -42.80'], ['CTO-B', '-20.81, -42.81']] as [$name, $coord]) {
        $r = $gateway->create(['node_type' => 'cto', 'name' => $name, 'lat' => explode(', ', $coord)[0], 'lng' => explode(', ', $coord)[1], 'attrs' => '{"capacity":4}']);
        $check($r['ok'], "cria {$name}: " . $r['message']);
    }
    $ctoA = (int) $pdo->query("SELECT id FROM map_nodes WHERE name='CTO-A'")->fetchColumn();
    $ctoB = (int) $pdo->query("SELECT id FROM map_nodes WHERE name='CTO-B'")->fetchColumn();
    $port = static fn (int $cto, int $num): int => (int) $GLOBALS['pdo']->query("SELECT id FROM map_ports WHERE node_id=$cto AND port_num=$num")->fetchColumn();
    $GLOBALS['pdo'] = $pdo;
    // Porta A1 em uma ligacao do mapa (alimentacao); A4 com defeito.
    $pdo->exec("INSERT INTO map_ports (provider_id,node_id,port_num,label,port_role,media,is_external,created_at,updated_at) VALUES ($providerId,42,1,'PON 1','pon','pon',1,NOW(),NOW())");
    $pon1 = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO map_links (provider_id,link_type,medium,from_node_id,to_node_id,from_port_id,to_port_id,created_at,updated_at) VALUES ($providerId,'backbone','fibra',42,$ctoA,$pon1,{$port($ctoA, 1)},NOW(),NOW())");
    $pdo->exec("UPDATE map_ports SET status='defeito' WHERE id={$port($ctoA, 4)}");
    // ONUs (3) de uma OLT com equipamento
    $pdo->exec("INSERT INTO net_device_profiles (code,vendor,technology,version,profile_json,status,created_at,updated_at) VALUES ('p-teste','X','epon',1,'{}','active',NOW(),NOW())");
    $profile = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO net_devices (id,provider_id,map_node_id,profile_id,mgmt_host,credential_ref,enabled,created_at,updated_at) VALUES (1,$providerId,42,$profile,'192.0.2.1','NET_CRED_X',0,NOW(),NOW())");
    foreach ([[1, 1, '006D61AA0010'], [2, 2, '006D61BB0020'], [3, 3, 'AABBCC000001'], [4, 4, '006D61CC0040']] as [$id, $idx, $mac]) {
        $pdo->exec("INSERT INTO net_onus (id,provider_id,device_id,slot,pon_index,onu_index,snmp_index,onu_identifier,identifier_type,first_seen_at,last_seen_at) VALUES ($id,$providerId,1,1,1,$idx,'1.1.$idx','$mac','epon_mac',NOW(),NOW())");
    }
    $pdo->exec("INSERT INTO net_onus (id,provider_id,device_id,slot,pon_index,onu_index,snmp_index,onu_identifier,identifier_type,first_seen_at,last_seen_at,removed_at) VALUES (5,$providerId,1,1,1,5,'1.1.5','006D61DD0050','epon_mac',NOW(),NOW(),NOW())");


    $fiber = new \App\Infrastructure\Net\NetFiberReader($pdo, static fn (): string => '2026-11-19 12:00:00');
    $repo = new \App\Infrastructure\Net\NetBindingRepository($pdo);
    $ctoRepo = new \App\Infrastructure\Net\NetCtoRepository($pdo);
    $search = new \App\Infrastructure\Net\NetSearchReader($pdo);
    $directory->clients['zeca'] = 'Zeca Teste';
    $asManager();

    // ---------- esquema (048) ----------
    $idx = static fn (string $name): ?int => ($v = $pdo->query("SELECT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_network_bindings' AND INDEX_NAME = '$name' LIMIT 1")->fetchColumn()) === false ? null : (int) $v;
    $check($idx('cnb_onu_ativa') === 1 && $idx('cnb_porta_ativa') === 1 && $idx('cnb_par_ativo') === 0 && $idx('cnb_login_ativo') === 0, '048: onu/porta nao unicas; par ativo e login ativo unicos');
    $col = $pdo->query("SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_network_bindings' AND COLUMN_NAME = 'modo_pppoe'")->fetch(PDO::FETCH_ASSOC);
    $check($col !== false && $col['COLUMN_TYPE'] === "enum('pppoe_na_onu','atras_da_onu')" && $col['IS_NULLABLE'] === 'YES' && in_array($col['COLUMN_DEFAULT'], [null, 'NULL'], true), '048: modo_pppoe ENUM nullable, sem default (NULL = nao verificado)');
    $router = new Router();
    (require $root . '/backend/routes.php')($router);
    $check($router->match('GET', '/api/rede-vinculos/onus') !== null && $router->match('POST', '/api/rede-vinculos/modo') !== null && $router->match('GET', '/api/rede-vinculos/modo') === null, 'rotas novas: onus (GET) e modo (so POST)');

    // ---------- 4 clientes na mesma ONU ----------
    $onu = static fn (string $login, string $acao, int $onuId = 1, array $extra = []) => $post('onu', ['onu_id' => (string) $onuId, 'login' => $login, 'acao' => $acao] + $extra);
    $r = $onu('joao', 'confirmar', 1, ['confianca' => 'alta', 'modo' => 'pppoe_na_onu']);
    $check($r->status() === 200 && $count('SELECT COUNT(*) FROM client_network_bindings WHERE ativo=1 AND onu_id=1') === 1, 'primeiro cliente da ONU (sugestao) com modo');
    $r = $onu('maria', 'confirmar');
    $check($r->status() === 409 && str_contains($json($r)['message'], 'adicionar'), 'sugestao ("confirmar") nao empilha em ONU ja vinculada: 409 orientando "adicionar"');
    foreach (['maria', 'pedro', 'ana'] as $l) {
        $r = $onu($l, 'adicionar', 1, $l === 'ana' ? ['modo' => 'atras_da_onu'] : []);
        $check($r->status() === 200, "adicionar {$l} a ONU 1");
    }
    $rows = $pdo->query('SELECT mkauth_login, origem, confianca, modo_pppoe FROM client_network_bindings WHERE ativo=1 AND onu_id=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $check(count($rows) === 4 && array_column($rows, 'mkauth_login') === ['joao', 'maria', 'pedro', 'ana'], '4 clientes ativos na MESMA ONU');
    $check($rows[0]['modo_pppoe'] === 'pppoe_na_onu' && $rows[1]['modo_pppoe'] === null && $rows[3]['modo_pppoe'] === 'atras_da_onu' && $rows[1]['origem'] === 'manual' && $rows[1]['confianca'] === null, 'modo por vinculo (NULL = nao verificado), origem manual e sem confianca no "adicionar"');
    $check($audit('net.binding.onu.add') === 3 && $audit('net.binding.onu.confirm') === 1, 'auditoria distingue confirmar e adicionar');
    $r = $onu('joao', 'adicionar', 2);
    $check($r->status() === 409 && str_contains($json($r)['message'], 'outra ONU'), 'cliente que ja tem ONU nao vai para outra: 409');
    $r = $post('onu', ['onu_id' => '1', 'login' => 'zeca', 'acao' => 'adicionar', 'modo' => 'invalido']);
    $check($r->status() === 422, 'modo invalido: 422');
    $check($onu('naoexiste', 'adicionar')->status() === 404 && $onu('zeca', 'adicionar', 5)->status() === 404, 'cliente ou ONU removida inexistente: 404');
    $threw = false;
    try { $pdo->exec("INSERT INTO client_network_bindings (provider_id,mkauth_login,onu_id,ativo,created_at,updated_at) VALUES ($providerId,'maria',1,0,NOW(),NOW())"); } catch (PDOException) { $threw = true; }
    $check(!$threw, 'historico (ativo=0) do mesmo par nao colide com o par ativo');
    $pdo->exec("DELETE FROM client_network_bindings WHERE ativo=0");
    $threw = false;
    try { $pdo->exec("INSERT INTO client_network_bindings (provider_id,mkauth_login,onu_id,ativo,created_at,updated_at) VALUES ($providerId,'maria',1,1,NOW(),NOW())"); } catch (PDOException) { $threw = true; }
    $check($threw, 'banco: o mesmo par (ONU, login) ativo nao se repete');

    // ---------- leitores devolvem listas ----------
    $lists = $repo->forOnus($providerId, [1, 2]);
    $check(array_keys($lists) === [1] && count($lists[1]) === 4 && $lists[1][0]['mkauth_login'] === 'joao', 'forOnus: LISTA por ONU, na ordem de criacao');
    $bs = $repo->boundSets($providerId);
    $check($bs['onus'] === [1 => 4] && count($bs['logins']) === 4 && count($bs['logins_onu']) === 4, 'boundSets: ONU 1 com 4 clientes');
    $tree = $fiber->tree($providerId)['dispositivos'][0]['pons'][0]['onus'];
    $t1 = $tree[0];
    $check($t1['n_clientes'] === 4 && count($t1['clientes']) === 4 && $t1['cliente'] === ['login' => 'joao', 'nome' => 'João Teste'] && $t1['clientes'][3]['modo_pppoe'] === 'atras_da_onu' && $t1['cto'] === null, 'arvore: ONU com 4 clientes, primeiro em "cliente" (forma antiga), modo na lista');
    $check($tree[1]['n_clientes'] === 0 && $tree[1]['clientes'] === [] && $tree[1]['cliente'] === null, 'ONU sem vinculo: lista vazia e cliente null');
    $sheet = $fiber->sheet($providerId, 1);
    $check($sheet !== null && $sheet['n_clientes'] === 4 && count($sheet['clientes']) === 4 && str_contains($sheet['cliente_texto'], '+3'), 'ficha da ONU lista os 4 e o texto resume "+3"');
    $sum = $fiber->tree($providerId)['resumo'];
    $check($sum['sem_vinculo'] === 3, 'resumo: sem_vinculo conta ONUs (nao clientes): 4 ONUs - 1 vinculada');
    $sug = (new OnuMatchSuggester($pdo, $repo))->suggest($providerId, ['zeca' => '00:6D:61:AA:00:12'], []);
    $check(array_column(array_column($sug['sugestoes'], 'onu'), 'id') === [] && $sug['sem_vinculo'] === 3, 'sugestoes ignoram ONU que ja tem cliente');

    // ---------- porta da CTO = ocupacao da ONU ----------
    $b1 = $port($ctoB, 1);
    $r = $post('bindCto', ['login' => 'joao', 'port_id' => (string) $b1, 'cto_id' => (string) $ctoB]);
    $check($r->status() === 200 && $count("SELECT COUNT(*) FROM client_network_bindings WHERE ativo=1 AND cto_port_id=$b1") === 4 && $count("SELECT COUNT(DISTINCT onu_id) FROM client_network_bindings WHERE ativo=1 AND cto_port_id=$b1") === 1, 'ligar um cliente a porta liga a ONU inteira: 4 linhas na mesma porta, 1 ONU');
    $check($count("SELECT COUNT(*) FROM client_network_bindings WHERE ativo=1 AND cto_node_id=$ctoB") === 4, 'as 4 linhas ficam na mesma CTO');
    $r = $post('bindCto', ['login' => 'zeca', 'port_id' => (string) $b1, 'cto_id' => (string) $ctoB]);
    $check($r->status() === 409 && str_contains($json($r)['message'], 'já tem o cliente'), 'cliente SEM ONU na porta da ONU: 409');
    $check($onu('zeca', 'adicionar', 3)->status() === 200, 'zeca na ONU 3');
    $r = $post('bindCto', ['login' => 'zeca', 'port_id' => (string) $b1, 'cto_id' => (string) $ctoB]);
    $check($r->status() === 409 && str_contains($json($r)['message'], 'outra ONU') || str_contains($json($r)['message'], 'ONU de outro'), 'cliente de OUTRA ONU na mesma porta: 409');
    $check($count("SELECT COUNT(*) FROM client_network_bindings WHERE ativo=1 AND cto_port_id=$b1") === 4, 'nada mudou depois dos 409');
    $r = $post('bindCto', ['login' => 'maria', 'port_id' => (string) $b1, 'cto_id' => (string) $ctoB]);
    $check($r->status() === 200 && $json($r)['message'] === 'O cliente já está nesta porta.', 'cliente da mesma ONU na mesma porta: ja esta (sem erro)');
    $check($onu('ana', 'adicionar', 3)->status() === 409, 'ana ja esta na ONU 1: nao vai para a 3');
    // novo cliente na ONU ja ligada herda CTO/porta
    $directory->clients['lia'] = 'Lia Teste';
    $check($onu('lia', 'adicionar', 1)->status() === 200 && $count("SELECT COUNT(*) FROM client_network_bindings WHERE ativo=1 AND cto_port_id=$b1") === 5, 'cliente novo na ONU ja ligada herda a CTO/porta (5 na porta)');
    // cliente com porta propria entra numa ONU sem porta: a porta passa a ser da ONU
    $directory->clients['rui'] = 'Rui Teste';
    $b2 = $port($ctoB, 2);
    $check($post('bindCto', ['login' => 'rui', 'port_id' => (string) $b2, 'cto_id' => (string) $ctoB])->status() === 200, 'rui em CTO-B porta 2 (sem ONU)');
    $check($onu('rui', 'adicionar', 4)->status() === 200 && $count("SELECT COUNT(*) FROM client_network_bindings WHERE ativo=1 AND onu_id=4 AND cto_port_id=$b2") === 1, 'rui entra na ONU 4: a porta dele vira a da ONU');
    $directory->clients['gil'] = 'Gil Teste';
    $check($post('bindCto', ['login' => 'gil', 'port_id' => (string) $port($ctoA, 2), 'cto_id' => (string) $ctoA])->status() === 200, 'gil em CTO-A porta 2 (sem ONU)');
    $r = $onu('gil', 'adicionar', 4);
    $check($r->status() === 409 && str_contains($json($r)['message'], 'remova a CTO'), 'cliente com CTO diferente da CTO da ONU: 409 explicando');
    $directory->clients['teo'] = 'Teo Teste';
    $check($onu('teo', 'adicionar', 4)->status() === 200 && $count("SELECT COUNT(*) FROM client_network_bindings WHERE ativo=1 AND cto_port_id=$b2") === 2, 'teo entra na ONU 4 e herda a porta B2 (2 clientes na ONU 4)');

    // ---------- leitores: CTO (ONUs x clientes), ficha, busca ----------
    $cp = $ctoRepo->clientPorts($providerId);
    $check($cp['clientes'][$ctoB] === 7 && $cp['portas'][$ctoB] === 2 && $cp['onus'][$ctoB] === 2, 'clientPorts: CTO-B tem 7 clientes em 2 portas / 2 ONUs');
    $list = array_column($ctoRepo->list($providerId), null, 'id')[$ctoB];
    $check($list['clientes'] === 7 && $list['onus'] === 2 && $list['portas']['com_cliente'] === 2 && $list['portas']['ocupadas'] === 2, 'lista de CTOs separa clientes (7), ONUs (2) e portas ocupadas (2)');
    $f = $json($get('ficha', ['id' => (string) $ctoB]))['data'];
    $p1 = array_values(array_filter($f['portas'], static fn ($p) => $p['id'] === $b1))[0];
    $check($f['contagem'] === ['clientes' => 7, 'onus' => 2, 'portas_com_cliente' => 2] && count($p1['clientes']) === 5 && $p1['cliente']['login'] === 'joao' && $p1['onu']['id'] === 1, 'ficha da CTO: contagem e a lista de clientes por porta (5 na porta da ONU 1)');
    $check($f['sem_porta'] === [], 'nenhum cliente sem porta');
    $s = $search->search($providerId, '006D61AA');
    $titles = array_column($s, 'titulo');
    $check(count(array_filter($titles, static fn ($t) => str_starts_with($t, 'ONU 1 '))) === 1 && str_contains(json_encode($s, JSON_UNESCAPED_UNICODE), 'João Teste +4'), 'busca por MAC: 1 resultado para a ONU (sem duplicar por cliente), com "+4"');
    $s = $search->search($providerId, 'maria');
    $check(count($s) === 1 && $s[0]['tipo'] === 'cliente' && str_contains($s[0]['detalhe'], 'ONU 1'), 'busca por cliente: continua uma linha por cliente');
    $sr = $search->search($providerId, '006D61AA', true);
    $check(count($sr) === 1 && str_contains($sr[0]['url'], '/rede-ctos/ficha'), 'busca somente leitura (tecnico): leva a CTO, uma vez');

    // ---------- modo ----------
    $mid = (int) $pdo->query("SELECT id FROM client_network_bindings WHERE ativo=1 AND mkauth_login='maria'")->fetchColumn();
    $r = $post('mode', ['id' => (string) $mid, 'modo' => 'atras_da_onu']);
    $check($r->status() === 200 && $pdo->query("SELECT modo_pppoe FROM client_network_bindings WHERE id=$mid")->fetchColumn() === 'atras_da_onu' && $audit('net.binding.mode') === 1, 'definir modo de um vinculo (audita)');
    $check($post('mode', ['id' => (string) $mid, 'modo' => ''])->status() === 200 && $pdo->query("SELECT modo_pppoe FROM client_network_bindings WHERE id=$mid")->fetchColumn() === null, 'modo vazio volta a "nao verificado" (NULL)');
    $check($post('mode', ['id' => (string) $mid, 'modo' => 'x'])->status() === 422 && $post('mode', ['id' => '9999', 'modo' => 'atras_da_onu'])->status() === 404 && $post('mode', ['id' => (string) $mid, 'modo' => 'atras_da_onu'], false)->status() === 419, 'modo: invalido 422, inexistente 404, sem CSRF 419');
    $bo = $json($get('boundOnus'))['data'];
    $o1 = array_values(array_filter($bo['onus'], static fn ($o) => $o['onu']['id'] === 1))[0];
    $check(count($bo['onus']) === 3 && count($o1['clientes']) === 5 && $o1['cto']['nome'] === 'CTO-B' && $o1['clientes'][3]['modo_pppoe'] === 'atras_da_onu' && $bo['modos'] === ['pppoe_na_onu', 'atras_da_onu'], 'API de ONUs vinculadas: lista de clientes com modo');
    $asTech();
    $check($get('boundOnus')->status() === 403 && $post('mode', ['id' => (string) $mid, 'modo' => ''])->status() === 403, 'tecnico: sem acesso a ONUs vinculadas nem ao modo');
    $asManager();

    // ---------- mover a ONU inteira ----------
    $b3 = $port($ctoB, 3);
    $r = $post('bindCto', ['login' => 'joao', 'port_id' => (string) $b3, 'cto_id' => (string) $ctoB]);
    $j = $json($r);
    $check($r->status() === 409 && ($j['codigo'] ?? '') === 'mover' && str_contains($j['message'], '4 cliente(s)'), 'mover cliente de ONU compartilhada pede confirmacao e avisa que os outros 4 vao junto');
    $r = $post('bindCto', ['login' => 'joao', 'port_id' => (string) $b3, 'cto_id' => (string) $ctoB, 'mover' => '1']);
    $check($r->status() === 200 && $count("SELECT COUNT(*) FROM client_network_bindings WHERE ativo=1 AND cto_port_id=$b3") === 5 && $count("SELECT COUNT(*) FROM client_network_bindings WHERE ativo=1 AND cto_port_id=$b1") === 0, 'confirmado: as 5 linhas da ONU 1 mudam para a porta 3; a porta 1 fica livre');
    $check($count("SELECT COUNT(*) FROM audit_logs WHERE action='net.binding.move' AND context_json LIKE '%\"linhas_movidas\":5%'") === 1, 'auditoria registra quantas linhas foram');

    // ---------- remover ----------
    $lia = (int) $pdo->query("SELECT id FROM client_network_bindings WHERE ativo=1 AND mkauth_login='lia'")->fetchColumn();
    $r = $post('remove', ['id' => (string) $lia, 'parte' => 'onu']);
    $check($r->status() === 200 && (int) $pdo->query("SELECT ativo FROM client_network_bindings WHERE id=$lia")->fetchColumn() === 0 && $count("SELECT COUNT(*) FROM client_network_bindings WHERE ativo=1 AND onu_id=1 AND cto_port_id=$b3") === 4, 'tirar 1 cliente da ONU: ele sai (historico), os outros 4 ficam na porta');
    $maria = (int) $pdo->query("SELECT id FROM client_network_bindings WHERE ativo=1 AND mkauth_login='maria'")->fetchColumn();
    $r = $post('remove', ['id' => (string) $maria, 'parte' => 'cto']);
    $check($r->status() === 200 && $count("SELECT COUNT(*) FROM client_network_bindings WHERE ativo=1 AND onu_id=1 AND cto_port_id IS NULL AND cto_node_id IS NULL") === 4, 'tirar a CTO de um cliente da ONU tira de todos (a porta e da ONU); continuam na ONU');
    $check($post('bindCto', ['login' => 'pedro', 'port_id' => (string) $b3, 'cto_id' => (string) $ctoB])->status() === 200 && $count("SELECT COUNT(*) FROM client_network_bindings WHERE ativo=1 AND onu_id=1 AND cto_port_id=$b3") === 4, 'religar pelo pedro leva as 4 linhas de volta');
    foreach (['joao', 'maria', 'pedro'] as $l) {
        $id = (int) $pdo->query("SELECT id FROM client_network_bindings WHERE ativo=1 AND mkauth_login='$l'")->fetchColumn();
        $post('remove', ['id' => (string) $id, 'parte' => 'onu']);
    }
    $left = $pdo->query("SELECT mkauth_login, onu_id, cto_port_id FROM client_network_bindings WHERE ativo=1 AND mkauth_login IN ('ana')")->fetch(PDO::FETCH_ASSOC);
    $check($left !== false && (int) $left['onu_id'] === 1, 'sobrou 1 cliente na ONU 1');
    $ana = (int) $pdo->query("SELECT id FROM client_network_bindings WHERE ativo=1 AND mkauth_login='ana'")->fetchColumn();
    $r = $post('remove', ['id' => (string) $ana, 'parte' => 'onu']);
    $check($r->status() === 200 && $count("SELECT COUNT(*) FROM client_network_bindings WHERE ativo=1 AND onu_id=1") === 0, 'ultimo cliente sai da ONU');
    $check($count("SELECT COUNT(*) FROM client_network_bindings WHERE ativo=1 AND cto_port_id=$b3 AND onu_id IS NULL") === 1, 'o ultimo cliente a sair da ONU fica com a porta como cliente SEM ONU (comportamento anterior a 048, a porta nao some)');

    // ---------- protecao no mapa com N clientes ----------
    $r = $ctoCtl->delete(new Request('POST', '/x', '', [], ['_csrf' => Csrf::token('rede'), 'id' => (string) $ctoB]));
    $check($r->status() === 409 && str_contains($json($r)['message'], 'cliente(s) vinculado(s)'), 'excluir CTO com clientes: 409');

    // ---------- rollback 048 ----------
    $stmtRollback = static function () use ($root, $pdo): void {
        $sql = (string) file_get_contents($root . '/scripts/rollback_048_binding_n_clientes_por_onu.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $first = trim(explode(';', $sql)[0]);   // so o ALTER (o DELETE de schema_migrations nao existe no schema descartavel)
        $pdo->exec($first);
    };
    $rows = (int) $pdo->query('SELECT COUNT(*) FROM client_network_bindings WHERE ativo=1 AND onu_id=4')->fetchColumn();
    $check($rows === 2, 'preparo do rollback: ONU 4 ainda tem 2 clientes ativos');
    $failed = false;
    try { $stmtRollback(); } catch (PDOException) { $failed = true; }
    $check($failed && $idx('cnb_onu_ativa') === 1 && $idx('cnb_par_ativo') === 0 && $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_network_bindings' AND COLUMN_NAME = 'modo_pppoe'")->fetchColumn() == 1, 'rollback com 2 clientes na mesma ONU: FALHA e NAO altera nada (atomico; indices e coluna intactos)');
    $teo = (int) $pdo->query("SELECT id FROM client_network_bindings WHERE ativo=1 AND mkauth_login='teo'")->fetchColumn();
    $post('remove', ['id' => (string) $teo, 'parte' => 'tudo']);
    $stmtRollback();
    $check($idx('cnb_onu_ativa') === 0 && $idx('cnb_porta_ativa') === 0 && $idx('cnb_par_ativo') === null && $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_network_bindings' AND COLUMN_NAME = 'modo_pppoe'")->fetchColumn() == 0, 'rollback com 1 cliente por ONU/porta: unicos restaurados, par ativo e coluna modo_pppoe removidos');
    $threw = false;
    try { $pdo->exec("INSERT INTO client_network_bindings (provider_id,mkauth_login,onu_id,ativo,created_at,updated_at) VALUES ($providerId,'outro1',4,1,NOW(),NOW())"); } catch (PDOException) { $threw = true; }
    $check($threw, 'depois do rollback o banco volta a recusar 2 clientes ativos na mesma ONU');
    $schema->applyFile($root . '/database/migrations/048_binding_n_clientes_por_onu.sql');
    $check($idx('cnb_onu_ativa') === 1 && $idx('cnb_par_ativo') === 0, 'a 048 reaplica sem erro depois do rollback');
    $schema->applyFile($root . '/database/migrations/048_binding_n_clientes_por_onu.sql');
    $check($idx('cnb_onu_ativa') === 1 && $idx('cnb_porta_ativa') === 1 && $idx('cnb_par_ativo') === 0, 'a 048 tambem e idempotente (aplicar duas vezes nao falha)');
} finally {
    $schema->drop();
}
echo "NetBindingNClientesSmoke: {$n} verificacoes, zero falhas\n";
