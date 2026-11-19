<?php
declare(strict_types=1);
// Fase 4: vinculos cliente<->CTO/porta e ONU<->cliente (sugestao pelo MAC, confirmar/recusar), regras de unicidade, protecao no mapa.
// Schema descartavel; MkAuth substituido por um diretorio falso (nunca toca no MkAuth).
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

// --- confianca (pura) ---
$check(OnuMatchSuggester::confidence(0, true, false) === 'alta' && OnuMatchSuggester::confidence(1, true, false) === 'alta' && OnuMatchSuggester::confidence(-1, true, false) === 'alta', 'MAC igual ou +-1 no ultimo octeto, unico: alta');
$check(OnuMatchSuggester::confidence(9, true, false) === 'media' && OnuMatchSuggester::confidence(16, true, false) === 'media' && OnuMatchSuggester::confidence(17, true, false) === 'baixa', 'ate 16 e media; acima, baixa');
$check(OnuMatchSuggester::confidence(0, false, false) === 'baixa' && OnuMatchSuggester::confidence(0, true, true) === 'baixa', 'ambiguo ou login em varias ONUs: baixa');

$router = new Router();
(require $root . '/backend/routes.php')($router);
foreach ([['GET', '/rede-ctos/ficha'], ['GET', '/api/rede-ctos/ficha'], ['GET', '/rede-vinculos'], ['GET', '/api/rede-vinculos/clientes'], ['GET', '/api/rede-vinculos/sugestoes'], ['POST', '/api/rede-vinculos/cto'], ['POST', '/api/rede-vinculos/remover'], ['POST', '/api/rede-vinculos/onu']] as [$m, $p]) {
    $check($router->match($m, $p) !== null, "rota {$m} {$p}");
}
$check($router->match('GET', '/api/rede-vinculos/cto') === null && $router->match('GET', '/api/rede-vinculos/onu') === null && $router->match('GET', '/api/rede-vinculos/remover') === null, 'escritas so por POST');

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

    // --- Permissao ---
    $asNobody();
    $check($get('ficha', ['id' => (string) $ctoA])->status() === 403 && $get('fichaPage')->status() === 302 && $get('vinculosPage')->status() === 302, 'sem perfil: ficha 403, paginas redirecionam');
    $asTech();
    $r = $get('ficha', ['id' => (string) $ctoA]);
    $check($r->status() === 200 && $json($r)['data']['pode_editar'] === false && count($json($r)['data']['portas']) === 4, 'tecnico le a ficha (somente leitura)');
    $page = $get('fichaPage', ['id' => (string) $ctoA]);
    $check($page->status() === 200 && str_contains($page->body(), 'data-can-edit="0"') && str_contains($page->body(), 'data-cto="' . $ctoA . '"') && !str_contains($page->body(), 'Vínculos</a>'), 'pagina da ficha do tecnico: leitura, sem aba de vinculos');
    foreach (['searchClients' => 'get', 'suggestions' => 'get', 'vinculosPage' => 'get'] as $m => $_) {
        $res = $get($m, ['q' => 'joao']);
        $check($res->status() === ($m === 'vinculosPage' ? 302 : 403), "tecnico: {$m} negado");
    }
    foreach (['bindCto' => ['login' => 'joao'], 'remove' => ['id' => '1'], 'onu' => ['onu_id' => '1', 'login' => 'joao', 'acao' => 'confirmar']] as $m => $f) {
        $check($post($m, $f)->status() === 403, "tecnico nao grava ({$m})");
    }
    $check($directory->calls === [], 'tecnico: nenhuma consulta ao diretorio de clientes');
    $asManager();
    $page = $get('vinculosPage');
    $check($page->status() === 200 && str_contains($page->body(), 'id="vc-app"') && str_contains($page->body(), 'data-csrf="' . Csrf::token('rede') . '"'), 'gestor ve a pagina de vinculos');
    foreach (['bindCto', 'remove', 'onu'] as $m) { $check($post($m, ['login' => 'joao'], false)->status() === 419, "{$m} sem CSRF = 419"); }

    // --- Ficha ---
    $r = $get('ficha', ['id' => (string) $ctoA]);
    $d = $json($r)['data'];
    $check($d['cto']['nome'] === 'CTO-A' && $d['pode_editar'] === true && $d['vinculos_disponiveis'] === true, 'ficha do gestor');
    $pa = array_column($d['portas'], null, 'num');
    $check($pa[1]['ligacao'] !== null && $pa[1]['cliente'] === null && $pa[4]['status'] === 'defeito' && $pa[2]['ligacao'] === null, 'ficha: porta em ligacao, porta com defeito, porta livre');
    $check($get('ficha', ['id' => '70'])->status() === 404 && $get('ficha', ['id' => '999'])->status() === 404 && $get('ficha', ['id' => 'x'])->status() === 404 && $get('ficha')->status() === 404, 'ficha: CTO de outro provedor/inexistente = 404');

    // --- Busca de cliente (somente leitura) ---
    $r = $get('searchClients', ['q' => 'Te']);
    $check($json($r)['data'] === [], 'menos de 3 caracteres nao consulta');
    $r = $get('searchClients', ['q' => 'Teste']);
    $j = $json($r);
    $check(count($j['data']) === 4 && $j['data'][0]['vinculo'] === null && !str_contains($r->body(), '12345678901'), 'busca devolve nome/login/bairro, nunca CPF');
    $directory->down = true;
    $r = $get('searchClients', ['q' => 'Teste']);
    $check($r->status() === 503 && !str_contains($r->body(), 'detalhe-interno'), 'MkAuth fora: 503 sem vazar detalhe');
    $directory->down = false;

    // --- Vincular cliente a porta ---
    $base = ['cto_id' => (string) $ctoA, 'port_id' => (string) $port($ctoA, 2), 'login' => 'joao'];
    foreach ([
        ['CTO inexistente', ['cto_id' => '999'], 404], ['CTO de outro provedor', ['cto_id' => '70'], 404], ['porta de outra CTO', ['port_id' => (string) $port($ctoB, 1)], 422],
        ['porta em ligacao', ['port_id' => (string) $port($ctoA, 1)], 422], ['porta com defeito', ['port_id' => (string) $port($ctoA, 4)], 422], ['porta inexistente', ['port_id' => '999'], 422],
        ['cliente vazio', ['login' => ''], 422], ['cliente inexistente', ['login' => 'fantasma'], 404], ['porta de OLT (nao e fo)', ['port_id' => (string) $pon1], 422],
    ] as [$label, $over, $code]) {
        $r = $post('bindCto', $over + $base);
        $check($r->status() === $code, "bindCto: {$label} -> {$code} (veio {$r->status()})");
    }
    $check($count('SELECT COUNT(*) FROM client_network_bindings') === 0, 'nada gravado pelas validacoes');
    $directory->down = true;
    $r = $post('bindCto', $base);
    $check($r->status() === 503 && $count('SELECT COUNT(*) FROM client_network_bindings') === 0 && !str_contains($r->body(), 'detalhe-interno'), 'MkAuth fora: 503, nada gravado');
    $directory->down = false;
    $r = $post('bindCto', ['login' => 'JOAO'] + $base);
    $j = $json($r);
    $check($r->status() === 200 && $j['ok'] === true && str_contains($j['message'], 'vinculado'), 'vincula (login em outra caixa: usa o login oficial do MkAuth)');
    $b = $pdo->query('SELECT * FROM client_network_bindings')->fetch(PDO::FETCH_ASSOC);
    $check($b['mkauth_login'] === 'joao' && $b['cliente_nome'] === 'João Teste' && (int) $b['cto_node_id'] === $ctoA && (int) $b['cto_port_id'] === $port($ctoA, 2) && $b['origem'] === 'manual' && $b['confirmado_por'] === 'zz_gestor' && $b['confirmado_em'] !== null && (int) $b['ativo'] === 1 && $b['onu_id'] === null, 'linha do vinculo: login, nome, CTO, porta, origem manual, quem e quando');
    $check($audit('net.binding.create') === 1 && !str_contains((string) $pdo->query("SELECT context_json FROM audit_logs WHERE action='net.binding.create'")->fetchColumn(), '12345678901'), 'auditoria net.binding.create (sem CPF)');
    $pa = array_column($json($get('ficha', ['id' => (string) $ctoA]))['data']['portas'], null, 'num');
    $check($pa[2]['cliente']['login'] === 'joao' && $pa[2]['cliente']['nome'] === 'João Teste' && $pa[2]['cliente']['onu'] === null, 'ficha mostra o cliente na porta');
    $row = array_values(array_filter($json($ctoCtl->list(new Request('GET', '/x')))['data']['ctos'], static fn (array $c): bool => $c['id'] === $ctoA))[0];
    $check($row['clientes'] === 1 && $row['portas']['com_cliente'] === 1 && $row['portas']['ocupadas'] === 2 && $row['portas']['livres'] === 1 && $json($ctoCtl->list(new Request('GET', '/x')))['data']['vinculos_disponiveis'] === true, 'lista de CTOs: 1 cliente, portas ocupadas (ligacao + cliente) e livres');
    // Porta ocupada por outro
    $r = $post('bindCto', ['login' => 'maria'] + $base);
    $check($r->status() === 409 && str_contains($json($r)['message'], 'João Teste'), 'porta ocupada por outro cliente: 409 com o nome');
    // Mesmo cliente, mesma porta: nao duplica
    $r = $post('bindCto', $base);
    $check($r->status() === 200 && $count('SELECT COUNT(*) FROM client_network_bindings') === 1 && $audit('net.binding.create') === 1, 'mesmo cliente na mesma porta: idempotente');
    // Mover: exige confirmacao
    $r = $post('bindCto', ['port_id' => (string) $port($ctoB, 3), 'cto_id' => (string) $ctoB, 'login' => 'joao']);
    $j = $json($r);
    $check($r->status() === 409 && $j['codigo'] === 'mover' && str_contains($j['message'], 'CTO-A') && str_contains($j['message'], 'Porta 2') && (int) $pdo->query('SELECT cto_node_id FROM client_network_bindings')->fetchColumn() === $ctoA, 'cliente ja vinculado: pede confirmacao para mover, nada muda');
    $r = $post('bindCto', ['port_id' => (string) $port($ctoB, 3), 'cto_id' => (string) $ctoB, 'login' => 'joao', 'mover' => '1']);
    $check($r->status() === 200 && str_contains($json($r)['message'], 'movido') && $count('SELECT COUNT(*) FROM client_network_bindings') === 1 && (int) $pdo->query('SELECT cto_node_id FROM client_network_bindings')->fetchColumn() === $ctoB && $audit('net.binding.move') === 1, 'mover com confirmacao troca CTO/porta na mesma linha e audita');
    $pa = array_column($json($get('ficha', ['id' => (string) $ctoA]))['data']['portas'], null, 'num');
    $check($pa[2]['cliente'] === null, 'a porta antiga ficou livre');
    // Dois clientes, duas portas; unicidade no banco
    $check($post('bindCto', ['login' => 'maria', 'port_id' => (string) $port($ctoB, 3), 'cto_id' => (string) $ctoB])->status() === 409, 'porta ocupada (apos mover): 409');
    $check($post('bindCto', ['login' => 'maria', 'port_id' => (string) $port($ctoB, 1), 'cto_id' => (string) $ctoB])->status() === 200, 'segundo cliente em outra porta');
    $threw = false;
    try { $pdo->exec("INSERT INTO client_network_bindings (provider_id,mkauth_login,cto_node_id,cto_port_id,ativo,created_at,updated_at) VALUES ($providerId,'pedro',$ctoB," . $port($ctoB, 1) . ",1,NOW(),NOW())"); } catch (PDOException) { $threw = true; }
    $check($threw, 'banco: uma porta de CTO so aceita um cliente ativo');
    $threw = false;
    try { $pdo->exec("INSERT INTO client_network_bindings (provider_id,mkauth_login,ativo,created_at,updated_at) VALUES ($providerId,'maria',1,NOW(),NOW())"); } catch (PDOException) { $threw = true; }
    $check($threw, 'banco: um cliente so tem um vinculo ativo');

    // --- Protecao no mapa: excluir CTO / reduzir portas com cliente ---
    $joaoPort = $port($ctoB, 3);
    $r = $ctoCtl->delete(new Request('POST', '/x', '', [], ['_csrf' => Csrf::token('rede'), 'id' => (string) $ctoB]));
    $check($r->status() === 409 && str_contains($json($r)['message'], 'cliente(s) vinculado(s)') && $count("SELECT COUNT(*) FROM map_nodes WHERE id=$ctoB") === 1, 'excluir CTO com cliente: 409 pelo motivo');
    $r = $mapCtl->deleteNode(new Request('POST', '/api/mapa/nos/excluir', '', [], ['_csrf' => Csrf::token('map'), 'id' => (string) $ctoB]));
    $check($r->status() === 409 && str_contains($json($r)['message'], 'cliente(s) estão vinculados') && $count("SELECT COUNT(*) FROM map_nodes WHERE id=$ctoB") === 1, 'pelo mapa tambem: nao exclui CTO com cliente');
    $r = $mapCtl->updateNode(new Request('POST', '/api/mapa/nos/editar', '', [], ['_csrf' => Csrf::token('map'), 'id' => (string) $ctoB, 'attrs' => '{"capacity":2}']));
    $check($r->status() === 409 && str_contains($json($r)['message'], 'cliente joao') && $count("SELECT COUNT(*) FROM map_ports WHERE node_id=$ctoB") === 4, 'reduzir portas com cliente nelas: 409 citando o cliente, nada removido');
    $r = $mapCtl->updateNode(new Request('POST', '/api/mapa/nos/editar', '', [], ['_csrf' => Csrf::token('map'), 'id' => (string) $ctoB, 'attrs' => '{"capacity":3}']));
    $check($r->status() === 200 && $count("SELECT COUNT(*) FROM map_ports WHERE node_id=$ctoB") === 3 && $count("SELECT COUNT(*) FROM client_network_bindings WHERE ativo=1 AND cto_port_id=$joaoPort") === 1, 'reduzir para 3 remove so a porta 4 (livre); a porta do cliente continua');

    // --- Desvincular ---
    $bid = (int) $pdo->query("SELECT id FROM client_network_bindings WHERE mkauth_login='maria'")->fetchColumn();
    $check($post('remove', ['id' => (string) $bid, 'parte' => 'x'])->status() === 422 && $post('remove', ['id' => '999', 'parte' => 'tudo'])->status() === 404, 'remover: parte invalida 422, vinculo inexistente 404');
    $r = $post('remove', ['id' => (string) $bid, 'parte' => 'cto']);
    $check($r->status() === 200 && (int) $pdo->query("SELECT ativo FROM client_network_bindings WHERE id=$bid")->fetchColumn() === 0 && $audit('net.binding.remove') === 1, 'desvincular de CTO sem ONU: vinculo fica inativo (historico) e audita');
    $check($post('remove', ['id' => (string) $bid, 'parte' => 'tudo'])->status() === 404, 'vinculo ja inativo: 404');
    $check($post('bindCto', ['login' => 'pedro', 'port_id' => (string) $port($ctoB, 1), 'cto_id' => (string) $ctoB])->status() === 200, 'porta liberada pode ser reutilizada (historico nao bloqueia)');
    $check($post('bindCto', ['login' => 'maria', 'port_id' => (string) $port($ctoB, 2), 'cto_id' => (string) $ctoB])->status() === 200 && $count("SELECT COUNT(*) FROM client_network_bindings WHERE mkauth_login='maria'") === 2, 'cliente desvinculado pode ser vinculado de novo (nova linha, historico preservado)');
    foreach (['joao', 'pedro', 'maria'] as $l) { $id = (int) $pdo->query("SELECT id FROM client_network_bindings WHERE mkauth_login='$l' AND ativo=1")->fetchColumn(); $post('remove', ['id' => (string) $id, 'parte' => 'tudo']); }
    $check($count('SELECT COUNT(*) FROM client_network_bindings WHERE ativo=1') === 0, 'tudo desvinculado');
    $r = $ctoCtl->delete(new Request('POST', '/x', '', [], ['_csrf' => Csrf::token('rede'), 'id' => (string) $ctoB]));
    $check($r->status() === 200 && $count("SELECT COUNT(*) FROM map_nodes WHERE id=$ctoB") === 0 && $count("SELECT COUNT(*) FROM client_network_bindings WHERE cto_node_id=$ctoB") === 0, 'so historico: a CTO pode ser excluida (o historico sai junto; a trilha fica em audit_logs)');

    // --- ONU <-> cliente: sugestoes pelo MAC ---
    $directory->callers = [
        'joao' => '00:6D:61:AA:00:11',   // ONU 1: diferenca +1 -> alta
        'maria' => '00:6D:61:BB:00:29',  // ONU 2: +9 -> candidato; pedro tambem -> ambiguo
        'pedro' => '00:6D:61:BB:00:28',  // ONU 2: +8
        'ana' => '00:6D:61:CC:00:41',    // ONU 4: +1 -> alta
        'sem' => 'lixo',                 // caller invalido
    ];
    $directory->clients['sem'] = 'Sem Mac';
    $check($count('SELECT COUNT(*) FROM client_network_bindings WHERE ativo=1') === 0, 'antes de confirmar nada esta vinculado');
    $r = $get('suggestions');
    $j = $json($r);
    $check($r->status() === 200 && $j['data']['sem_vinculo'] === 4 && count($j['data']['sugestoes']) === 3 && $j['data']['sem_candidato'] === 1 && $j['data']['caller_invalido'] === 1 && $j['data']['radacct_referencia'] === '2026-10-13 09:45:43' && $j['data']['janela_dias'] === 30 && $j['data']['base_mkauth_ate'] === '2026-10-08' && $j['data']['base_mkauth_origem'] === 'cliente', 'sugestoes: 4 ONUs sem vinculo (a removida nao entra), 3 com sugestao, 1 sem candidato');
    $s = array_column($j['data']['sugestoes'], null, array_column(array_column($j['data']['sugestoes'], 'onu'), 'id') === [1, 2, 4] ? null : null);
    $byOnu = [];
    foreach ($j['data']['sugestoes'] as $x) { $byOnu[$x['onu']['id']] = $x; }
    $check($byOnu[1]['candidatos'][0]['login'] === 'joao' && $byOnu[1]['candidatos'][0]['distancia'] === 1 && $byOnu[1]['candidatos'][0]['confianca'] === 'alta' && $byOnu[1]['candidatos'][0]['nome'] === 'João Teste', 'ONU 1: joao (+1, alta, com nome)');
    $check(count($byOnu[2]['candidatos']) === 2 && $byOnu[2]['candidatos'][0]['confianca'] === 'baixa' && $byOnu[2]['candidatos'][1]['confianca'] === 'baixa', 'ONU 2: ambigua (maria e pedro) = baixa');
    $check(!isset($byOnu[3]) && $byOnu[4]['candidatos'][0]['login'] === 'ana', 'ONU 3 sem candidato; ONU 4 -> ana');
    $check($byOnu[1]['onu']['olt'] === 'OLT-T' && $byOnu[1]['onu']['pon'] === 1 && $byOnu[1]['onu']['mac'] === '006D61AA0010', 'dados da ONU na sugestao');
    $check($count('SELECT COUNT(*) FROM client_network_bindings WHERE ativo=1') === 0 && $count('SELECT COUNT(*) FROM client_network_binding_rejections') === 0, 'pedir sugestoes nunca grava');

    // Validacoes
    $check($post('onu', ['onu_id' => '1', 'login' => 'joao', 'acao' => 'x'])->status() === 422 && $post('onu', ['onu_id' => '999', 'login' => 'joao', 'acao' => 'confirmar'])->status() === 404 && $post('onu', ['onu_id' => '5', 'login' => 'joao', 'acao' => 'confirmar'])->status() === 404, 'confirmar: acao invalida 422; ONU inexistente ou removida 404');
    $check($post('onu', ['onu_id' => '1', 'login' => 'fantasma', 'acao' => 'confirmar'])->status() === 404, 'confirmar cliente inexistente no MkAuth = 404');
    $directory->down = true;
    $check($post('onu', ['onu_id' => '1', 'login' => 'joao', 'acao' => 'confirmar'])->status() === 503 && $count('SELECT COUNT(*) FROM client_network_bindings WHERE ativo=1') === 0, 'MkAuth fora ao confirmar: 503, nada gravado');
    $check($get('suggestions')->status() === 503, 'MkAuth fora nas sugestoes: 503');
    $directory->down = false;
    // Confirmar
    $r = $post('onu', ['onu_id' => '1', 'login' => 'joao', 'acao' => 'confirmar', 'confianca' => 'alta']);
    $check($r->status() === 200, 'confirma ONU 1 = joao: ' . $r->body());
    $b = $pdo->query("SELECT * FROM client_network_bindings WHERE mkauth_login='joao' AND ativo=1")->fetch(PDO::FETCH_ASSOC);
    $check((int) $b['onu_id'] === 1 && $b['origem'] === 'sugestao_mac' && $b['confianca'] === 'alta' && $b['confirmado_por'] === 'zz_gestor' && $b['confirmado_em'] !== null && $b['cto_node_id'] === null, 'vinculo ONU: origem sugestao_mac, confianca, quem e quando');
    $check($audit('net.binding.onu.confirm') === 1, 'auditoria da confirmacao');
    $check($post('onu', ['onu_id' => '1', 'login' => 'maria', 'acao' => 'confirmar'])->status() === 409, 'ONU ja vinculada a outro: 409');
    $check($post('onu', ['onu_id' => '2', 'login' => 'joao', 'acao' => 'confirmar'])->status() === 409, 'cliente que ja tem outra ONU: 409');
    $check($post('onu', ['onu_id' => '2', 'login' => 'maria', 'acao' => 'confirmar', 'confianca' => 'invalida'])->status() === 200 && $pdo->query("SELECT confianca FROM client_network_bindings WHERE mkauth_login='maria' AND ativo=1")->fetchColumn() === null, 'confianca fora da lista vira nula');
    $j = $json($get('suggestions'));
    $ids = array_map(static fn (array $x): int => $x['onu']['id'], $j['data']['sugestoes']);
    $check($ids === [4] && $j['data']['sem_vinculo'] === 2, 'ONUs vinculadas saem da lista de sugestoes');
    // Recusar
    $check($post('onu', ['onu_id' => '4', 'login' => 'ana', 'acao' => 'recusar'])->status() === 200 && $audit('net.binding.onu.reject') === 1 && $count('SELECT COUNT(*) FROM client_network_binding_rejections') === 1, 'recusar grava a recusa e audita');
    $check($post('onu', ['onu_id' => '4', 'login' => 'ana', 'acao' => 'recusar'])->status() === 200 && $count('SELECT COUNT(*) FROM client_network_binding_rejections') === 1, 'recusar de novo e idempotente');
    $j = $json($get('suggestions'));
    $check($j['data']['sugestoes'] === [] && $count("SELECT COUNT(*) FROM client_network_bindings WHERE mkauth_login='ana'") === 0, 'sugestao recusada nao volta e nada foi vinculado');
    // Cliente com CTO + ONU na mesma linha e na ficha
    $r = $gateway->create(['node_type' => 'cto', 'name' => 'CTO-C', 'lat' => '-20.9', 'lng' => '-42.9', 'attrs' => '{"capacity":4}']);
    $ctoC = (int) $r['node']['id'];
    $check($post('bindCto', ['login' => 'joao', 'port_id' => (string) $port($ctoC, 1), 'cto_id' => (string) $ctoC])->status() === 200 && $count("SELECT COUNT(*) FROM client_network_bindings WHERE mkauth_login='joao'") === 1, 'vincular CTO a quem ja tem ONU atualiza a mesma linha');
    $pa = array_column($json($get('ficha', ['id' => (string) $ctoC]))['data']['portas'], null, 'num');
    $check($pa[1]['cliente']['onu'] === ['id' => 1, 'pon' => 1, 'onu_index' => 1] && $pa[1]['cliente']['origem'] === 'sugestao_mac', 'ficha mostra a ONU do cliente');
    $r = $post('remove', ['id' => (string) $pa[1]['cliente']['id'], 'parte' => 'cto']);
    $b = $pdo->query("SELECT * FROM client_network_bindings WHERE mkauth_login='joao' AND ativo=1")->fetch(PDO::FETCH_ASSOC);
    $check($r->status() === 200 && $b !== false && $b['cto_node_id'] === null && $b['cto_port_id'] === null && (int) $b['onu_id'] === 1, 'desvincular so da CTO mantem o vinculo da ONU');
    $r = $post('remove', ['id' => (string) $b['id'], 'parte' => 'onu']);
    $check($r->status() === 200 && $count("SELECT COUNT(*) FROM client_network_bindings WHERE mkauth_login='joao' AND ativo=1") === 0, 'sem CTO e sem ONU o vinculo fica inativo');
    $check($count("SELECT COUNT(*) FROM client_network_bindings WHERE mkauth_login='joao'") === 2 || $count("SELECT COUNT(*) FROM client_network_bindings WHERE mkauth_login='joao'") === 1, 'historico preservado');
    // Isolamento por provedor
    $pdo->exec("INSERT INTO net_onus (id,provider_id,device_id,slot,pon_index,onu_index,snmp_index,onu_identifier,identifier_type,first_seen_at,last_seen_at) VALUES (9,$providerId,1,1,1,9,'1.1.9','006D61EE0090','epon_mac',NOW(),NOW())");
    $pdo->exec("INSERT INTO net_onus (id,provider_id,device_id,slot,pon_index,onu_index,snmp_index,onu_identifier,identifier_type,first_seen_at,last_seen_at) SELECT 10,$other,1,1,1,10,'1.1.10','006D61FF0100','epon_mac',NOW(),NOW()");
    $check($post('onu', ['onu_id' => '10', 'login' => 'ana', 'acao' => 'confirmar'])->status() === 404, 'ONU de outro provedor: 404');
    $log = (string) $pdo->query("SELECT GROUP_CONCAT(context_json) FROM audit_logs WHERE action LIKE 'net.binding.%'")->fetchColumn();
    $check(!str_contains($log, '12345678901'), 'auditoria dos vinculos nunca guarda CPF');
} finally {
    $schema->drop();
}
// Ambiente sem a migration 045: nada quebra, nada e gravado.
$old = (new ScratchSchema($root, 'netbind034'))->create(34);
try {
    $local2 = new LocalRepository($old->database, 'default');
    $pid2 = (int) $local2->currentProviderId();
    $old->pdo->exec("INSERT INTO map_nodes (id,provider_id,node_type,name,lat,lng,attrs_json,created_at,updated_at) VALUES (5,$pid2,'cto','CTO-X',-20.1,-42.1,'{\"capacity\":2}',NOW(),NOW())");
    $old->pdo->exec("INSERT INTO map_ports (id,provider_id,node_id,port_num,label,port_role,media,is_external,created_at,updated_at) VALUES (11,$pid2,5,1,'Porta 1','fo','fibra',1,NOW(),NOW())");
    $mr2 = new MapRepository($old->database);
    $ctl2 = new NetBindingController(new View($root . '/backend/Views'), new Config(['app' => ['name' => 'T']]), $local2, $old->database, $mr2, $directory);
    $_SESSION = ['user' => ['login' => 'zz_gestor', 'name' => 'ZZ', 'role' => 'manager', 'id' => 0]];
    $f = json_decode($ctl2->ficha(new Request('GET', '/x', '', ['id' => '5']))->body(), true);
    $check($f['ok'] === true && $f['data']['vinculos_disponiveis'] === false && count($f['data']['portas']) === 1, 'sem a tabela: ficha abre e informa que vinculos nao estao disponiveis');
    $tok = Csrf::token('rede');
    $check($ctl2->bindCto(new Request('POST', '/x', '', [], ['_csrf' => $tok, 'cto_id' => '5', 'port_id' => '11', 'login' => 'joao']))->status() === 409 && $ctl2->suggestions(new Request('GET', '/x'))->status() === 409, 'sem a tabela: gravar e sugerir devolvem 409 claro');
    $mapCtl2 = new MapController($local2, $mr2);
    $r = $mapCtl2->deleteNode(new Request('POST', '/x', '', [], ['_csrf' => Csrf::token('map'), 'id' => '5']));
    $check($r->status() === 200, 'sem a tabela: o mapa exclui CTO normalmente (protecao e opcional)');
} finally {
    $old->drop();
}
echo "NetBindingSmoke: {$n} verificacoes, zero falhas\n";
