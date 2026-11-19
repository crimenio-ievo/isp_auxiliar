<?php
declare(strict_types=1);
// Ficha da ONU (dados do cliente sem CPF/telefone), "Definir CTO" (busca por proximidade/nome, porta livre, ONU inteira) e resumo para colunas.
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

$schema = (new ScratchSchema($root, 'fichaonu'))->create();
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


    $asManager();
    $directory->briefs = [
        'joao' => ['login' => 'joao', 'nome' => 'João <b>Teste</b>', 'bairro' => 'Centro', 'cidade' => 'Coimbra', 'endereco' => 'Rua das Flores', 'numero' => '10', 'coordenadas' => '-20.8005,-42.8005', 'plano' => '100M', 'situacao' => 'ativo',
            'cpf_cnpj' => '12345678900', 'fone' => '31999990000', 'email' => 'segredo@exemplo.com'],   // campos que NUNCA podem sair
        'maria' => ['login' => 'maria', 'nome' => 'Maria Teste', 'bairro' => '', 'cidade' => 'Coimbra', 'endereco' => '', 'numero' => '', 'coordenadas' => '', 'plano' => '', 'situacao' => 'bloqueado'],
    ];
    $get = static fn (string $m, array $q = []) => $ctl->{$m}(new Request('GET', '/x', '', $q));
    $onu = static fn (string $login, int $onuId, array $extra = []) => $post('onu', ['onu_id' => (string) $onuId, 'login' => $login, 'acao' => 'adicionar'] + $extra);
    $ctoC = (int) $pdo->query("SELECT id FROM map_nodes WHERE name='CTO-A'")->fetchColumn();   // (-20.80,-42.80), 4 portas: A1 em ligacao, A4 com defeito
    $check($onu('joao', 1)->status() === 200 && $onu('maria', 1)->status() === 200 && $onu('pedro', 1)->status() === 200 && $onu('ana', 2)->status() === 200, 'ONU 1 com 3 clientes e ONU 2 com 1');

    // ---------- ficha da ONU: dados do cliente (somente leitura, sem dados sensiveis) ----------
    $r = $get('onuClients', ['id' => '1']);
    $j = $json($r);
    $c0 = $j['data']['clientes'][0] ?? [];
    $check($r->status() === 200 && count($j['data']['clientes']) === 3 && $j['data']['mkauth_ok'] === true && $c0['login'] === 'joao' && $c0['endereco'] === 'Rua das Flores, 10' && $c0['bairro'] === 'Centro' && $c0['cidade'] === 'Coimbra'
        && $c0['coordenadas'] === ['lat' => -20.8005, 'lng' => -42.8005] && $c0['plano'] === '100M' && $c0['situacao_mkauth'] === 'ativo' && $c0['encontrado_no_mkauth'] === true, 'ficha: login, nome, endereco curto, bairro, coordenadas, plano e situacao');
    $raw = (string) $r->body();
    $check(!str_contains($raw, '12345678900') && !str_contains($raw, '31999990000') && !str_contains($raw, 'segredo@exemplo.com') && !str_contains($raw, 'cpf') && !str_contains($raw, 'fone') && !str_contains($raw, 'email'), 'a resposta NUNCA traz CPF, telefone nem e-mail (lista fechada de campos)');
    $c1 = $j['data']['clientes'][1];
    $check($c1['login'] === 'maria' && $c1['endereco'] === null && $c1['bairro'] === null && $c1['coordenadas'] === null && $c1['situacao_mkauth'] === 'bloqueado' && $c1['modo_pppoe'] === null, 'campos vazios viram null (nao string vazia); modo NULL = nao verificado');
    $c2 = $j['data']['clientes'][2];
    $check($c2['login'] === 'pedro' && $c2['encontrado_no_mkauth'] === false && $c2['nome'] === 'Pedro Teste' && $c2['situacao_mkauth'] === null, 'cliente que nao esta no MkAuth: marcado e o nome vem da foto do vinculo');
    $directory->down = true;
    $r = $get('onuClients', ['id' => '1']);
    $check($r->status() === 200 && $json($r)['data']['mkauth_ok'] === false && count($json($r)['data']['clientes']) === 3 && !str_contains($r->body(), 'detalhe-interno'), 'MkAuth fora: a ficha segue (200, mkauth_ok=false) sem vazar a causa');
    $directory->down = false;
    $check($json($get('onuClients', ['id' => '3']))['data']['clientes'] === [] && $get('onuClients', ['id' => 'x'])->status() === 422, 'ONU sem cliente: lista vazia; id invalido 422');
    $asTech();
    $check($get('onuClients', ['id' => '1'])->status() === 403 && $get('clientsSummary')->status() === 403 && $get('nearbyCtos', ['onu_id' => '1'])->status() === 403 && $post('bindOnuCto', ['onu_id' => '1'])->status() === 403, 'tecnico nao acessa ficha de clientes, resumo, busca de CTO nem grava');
    $asManager();

    // ---------- resumo para as colunas Login/Bairro ----------
    $r = $get('clientsSummary');
    $j = $json($r);
    $check($r->status() === 200 && ($j['data']['clientes']['joao'] ?? null) === ['bairro' => 'Centro', 'cidade' => 'Coimbra'] && ($j['data']['clientes']['maria'] ?? null) === ['bairro' => null, 'cidade' => 'Coimbra'] && !isset($j['data']['clientes']['pedro']) && !str_contains($r->body(), '12345678900'), 'resumo: bairro/cidade por login, uma consulta, sem dados sensiveis');
    $directory->down = true;
    $check($get('clientsSummary')->status() === 503, 'resumo com MkAuth fora: 503 (a tela mostra "indisponivel")');
    $directory->down = false;

    // ---------- busca de CTO: proximidade, nome, portas livres ----------
    // CTO-A (-20.80,-42.80) perto do cliente (-20.8005,-42.8005), CTO-B (-20.81,-42.81) longe, CTO-Z sem coordenadas, CTO-Y outro provedor nao entra
    $pdo->exec("INSERT INTO map_nodes (provider_id,node_type,name,created_at,updated_at) VALUES ($providerId,'cto','CTO-Z',NOW(),NOW())");
    $r = $get('nearbyCtos', ['onu_id' => '1']);
    $j = $json($r)['data'];
    $names = array_column($j['ctos'], 'nome');
    $check($r->status() === 200 && $names === ['CTO-A', 'CTO-B', 'CTO-Z'] && $j['ctos'][0]['distancia_m'] < 100 && $j['ctos'][1]['distancia_m'] > 1000 && $j['ctos'][2]['distancia_m'] === null && $j['referencia']['login'] === 'joao' && $j['n_clientes'] === 3, 'sem texto: as mais proximas das coordenadas do cliente (A, B, Z sem coordenada no fim)');
    $free = static fn (array $c): array => array_column($c['livres'], 'rotulo');
    $check($free($j['ctos'][0]) === ['Porta 2', 'Porta 3'] && $j['ctos'][0]['total_portas'] === 4, 'portas livres da CTO-A: sem a em ligacao (Porta 1) e sem a com defeito (Porta 4)');
    $check(count($free($j['ctos'][1])) === 4 && $free($j['ctos'][2]) === [] && $j['atual'] === null, 'CTO-B com 4 livres; CTO-Z sem portas; ONU ainda sem CTO (atual null)');
    $j = $json($get('nearbyCtos', ['onu_id' => '1', 'q' => 'cto-b']))['data'];
    $check(array_column($j['ctos'], 'nome') === ['CTO-B'], 'por nome (sem diferenciar maiusculas)');
    $check(array_column($json($get('nearbyCtos', ['onu_id' => '1', 'q' => '%']))['data']['ctos'], 'nome') === [], 'o "%" e texto literal (nao vira curinga)');
    $j = $json($get('nearbyCtos', ['onu_id' => '2']))['data'];
    $check($j['referencia'] === null && str_contains((string) $j['aviso'], 'coordenadas') && array_column($j['ctos'], 'nome') === ['CTO-A', 'CTO-B', 'CTO-Z'], 'cliente sem coordenadas: lista por nome com aviso');
    $directory->down = true;
    $j = $json($get('nearbyCtos', ['onu_id' => '1']))['data'];
    $check($j['referencia'] === null && str_contains((string) $j['aviso'], 'MkAuth') && count($j['ctos']) === 3, 'MkAuth fora: lista por nome com aviso (nao quebra)');
    $directory->down = false;
    $j = $json($get('nearbyCtos', ['onu_id' => '3']))['data'];
    $check(str_contains((string) $j['aviso'], 'vincule um cliente') && $j['n_clientes'] === 0, 'ONU sem cliente: orienta a vincular primeiro');

    // ---------- Definir CTO: a ONU inteira vai para a porta ----------
    $pA2 = $port($ctoC, 2); $pA3 = $port($ctoC, 3); $pB1 = $port($ctoB, 1); $pB2 = $port($ctoB, 2); $pA1 = $port($ctoC, 1); $pA4 = $port($ctoC, 4);
    $bind = static fn (int $onuId, int $cto, int $p, array $x = []) => $post('bindOnuCto', ['onu_id' => (string) $onuId, 'cto_id' => (string) $cto, 'port_id' => (string) $p] + $x);
    $check($bind(3, $ctoC, $pA2)->status() === 409 && str_contains($json($bind(3, $ctoC, $pA2))['message'], 'Vincule um cliente'), 'ONU sem cliente: 409 orientando');
    $r = $bind(1, $ctoC, $pA2);
    $check($r->status() === 200 && str_contains($json($r)['message'], 'ONU com 3 clientes') && $count("SELECT COUNT(*) FROM client_network_bindings WHERE ativo=1 AND onu_id=1 AND cto_port_id=$pA2") === 3 && $count("SELECT COUNT(*) FROM client_network_bindings WHERE ativo=1 AND onu_id=1 AND cto_node_id=$ctoC") === 3, 'definir CTO: as 3 linhas da ONU 1 ficam na CTO-A, Porta 2');
    $check($audit('net.binding.onu.cto') === 1 && str_contains((string) $pdo->query("SELECT context_json FROM audit_logs WHERE action='net.binding.onu.cto'")->fetchColumn(), '"clientes":3'), 'auditoria registra a ONU e os 3 clientes');
    $r = $bind(1, $ctoC, $pA2);
    $check($r->status() === 200 && $json($r)['message'] === 'A ONU já está nesta porta.', 'mesma porta: ja esta (sem erro)');
    $r = $bind(1, $ctoB, $pB1);
    $j = $json($r);
    $check($r->status() === 409 && ($j['codigo'] ?? '') === 'mover' && str_contains($j['message'], 'os 3 clientes dessa ONU vão juntos') && $count("SELECT COUNT(*) FROM client_network_bindings WHERE ativo=1 AND cto_port_id=$pA2") === 3, 'trocar de porta pede confirmacao dizendo que os 3 vao juntos (nada mudou)');
    $r = $bind(1, $ctoB, $pB1, ['mover' => '1']);
    $check($r->status() === 200 && $count("SELECT COUNT(*) FROM client_network_bindings WHERE ativo=1 AND cto_port_id=$pB1") === 3 && $count("SELECT COUNT(*) FROM client_network_bindings WHERE ativo=1 AND cto_port_id=$pA2") === 0, 'confirmado: as 3 linhas mudam para a CTO-B Porta 1 e a Porta 2 da CTO-A fica livre');
    $j = $json($get('nearbyCtos', ['onu_id' => '1']))['data'];
    $check($j['atual']['cto'] === 'CTO-B' && $j['atual']['porta'] === 'Porta 1' && !in_array('Porta 1', $free(array_values(array_filter($j['ctos'], static fn ($c) => $c['nome'] === 'CTO-B'))[0]), true) && in_array('Porta 2', $free(array_values(array_filter($j['ctos'], static fn ($c) => $c['nome'] === 'CTO-A'))[0]), true), 'a busca mostra a CTO atual e a porta ocupada deixa de ser livre');
    $r = $bind(2, $ctoB, $pB1);
    $check($r->status() === 409 && str_contains($json($r)['message'], 'ocupada') && $count("SELECT COUNT(*) FROM client_network_bindings WHERE ativo=1 AND onu_id=2 AND cto_port_id IS NOT NULL") === 0, 'porta de outra ONU: 409 e nada muda');
    $check($bind(2, $ctoC, $pA1)->status() === 422 && $bind(2, $ctoC, $pA4)->status() === 422, 'porta em ligacao do mapa e porta com defeito: 422');
    $check($bind(2, $ctoB, $pA3)->status() === 422 && $bind(2, 999, $pB2)->status() === 404 && $post('bindOnuCto', ['onu_id' => '2', 'cto_id' => (string) $ctoB])->status() === 422, 'porta de outra CTO 422; CTO inexistente 404; sem porta 422');
    $check($post('bindOnuCto', ['onu_id' => '2', 'cto_id' => (string) $ctoB, 'port_id' => (string) $pB2], false)->status() === 419, 'sem CSRF = 419');
    $r = $bind(2, $ctoB, $pB2);
    $check($r->status() === 200 && str_contains($json($r)['message'], 'ONU ligada a CTO-B') && $count("SELECT COUNT(*) FROM client_network_bindings WHERE ativo=1 AND onu_id=2 AND cto_port_id=$pB2") === 1, 'ONU de 1 cliente: mensagem sem "N clientes"');

    // ---------- /mapa?lat=&lng= (link "ver no mapa") ----------
    $_SESSION['user'] = ['login' => 'zz_gestor', 'name' => 'ZZ', 'role' => 'manager', 'id' => 0];
    $show = static fn (array $q) => $mapCtl->show(new Request('GET', '/mapa', '', $q));
    $h = $show(['lat' => '-20.8005', 'lng' => '-42.8005'])->body();
    $check(str_contains($h, '"pin":{"lat":-20.8005,"lng":-42.8005}'), '/mapa?lat=&lng= entrega o ponto ao mapa');
    $check(str_contains($show(['lat' => '95', 'lng' => '0'])->body(), '"pin":null') && str_contains($show(['lat' => 'x', 'lng' => '1'])->body(), '"pin":null') && str_contains($show([])->body(), '"pin":null'), 'coordenadas invalidas ou ausentes: sem ponto');
} finally {
    $schema->drop();
}
echo "NetFichaOnuSmoke: {$n} verificacoes, zero falhas\n";
