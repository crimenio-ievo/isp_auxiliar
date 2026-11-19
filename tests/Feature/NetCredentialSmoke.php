<?php
declare(strict_types=1);
// CP-1: credenciais cifradas (libsodium), rotas, permissao, CSRF, segredo nunca em resposta/HTML/auditoria, migrador do .env, script de chave. Schema descartavel.
$root = dirname(__DIR__, 2);
require $root . '/backend/bootstrap/autoload.php';
require $root . '/tests/support/ScratchSchema.php';

use App\Controllers\NetCatalogController;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Router;
use App\Core\View;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\Map\MapRepository;
use App\Infrastructure\Net\NetCredentialMigrator;
use App\Infrastructure\Net\NetCredentialRepository;
use App\Infrastructure\Net\NetSecretBox;
use App\Infrastructure\Net\NetTransportFactory;
use Tests\Support\ScratchSchema;

$n = 0;
$check = static function (bool $ok, string $m) use (&$n): void { $n++; if (!$ok) { throw new RuntimeException("FALHA: {$m}"); } };
$SECRET = 'segredo-MUITO-secreto-123';

// --- NetSecretBox (puro) ---
$key = NetSecretBox::generateKey();
$box = new NetSecretBox(base64_decode($key), 3);
$enc = $box->encrypt($SECRET);
$check($box->available() && $box->version() === 3 && strlen($enc['nonce']) === SODIUM_CRYPTO_SECRETBOX_NONCEBYTES && !str_contains($enc['cipher'], $SECRET), 'secretbox: cifra e nonce de 24 bytes, texto claro ausente');
$check($box->decrypt($enc['cipher'], $enc['nonce']) === $SECRET, 'secretbox: ida e volta');
$enc2 = $box->encrypt($SECRET);
$check($enc2['nonce'] !== $enc['nonce'] && $enc2['cipher'] !== $enc['cipher'], 'secretbox: nonce novo a cada cifragem');
$other = new NetSecretBox(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
try { $other->decrypt($enc['cipher'], $enc['nonce']); $check(false, 'chave errada deveria falhar'); } catch (RuntimeException $e) { $check(!str_contains($e->getMessage(), $SECRET) && !str_contains($e->getMessage(), $key), 'secretbox: chave errada falha sem vazar segredo/chave'); }
$none = new NetSecretBox(null);
$check(!$none->available(), 'sem chave: indisponivel');
try { $none->encrypt($SECRET); $check(false, 'sem chave deveria falhar'); } catch (RuntimeException $e) { $check(!str_contains($e->getMessage(), $SECRET), 'sem chave: cifrar falha sem vazar'); }

// --- script de chave (arquivo .env temporario) ---
$tmpEnv = sys_get_temp_dir() . '/net_secret_key_test_' . getmypid() . '.env';
file_put_contents($tmpEnv, "APP_X=1");
$out = (string) shell_exec('php ' . escapeshellarg($root . '/scripts/net_secret_key.php') . ' --env=' . escapeshellarg($tmpEnv) . ' 2>&1');
$content = (string) file_get_contents($tmpEnv);
preg_match('/^NET_SECRET_KEY=(\S+)$/m', $content, $m);
$check(isset($m[1]) && strlen((string) base64_decode($m[1], true)) === 32 && str_contains($content, "APP_X=1\nNET_SECRET_KEY=") && !str_contains($out, $m[1]), 'script de chave: gera chave de 32 bytes e nao a imprime');
$before = $content;
$out2 = (string) shell_exec('php ' . escapeshellarg($root . '/scripts/net_secret_key.php') . ' --env=' . escapeshellarg($tmpEnv) . ' 2>&1');
$check(file_get_contents($tmpEnv) === $before && str_contains($out2, 'já existe'), 'script de chave: nao troca uma chave existente');
unlink($tmpEnv);

$schema = (new ScratchSchema($root, 'netcred'))->create();
try {
    $pdo = $schema->pdo;
    $local = new LocalRepository($schema->database, 'default');
    $providerId = (int) $local->currentProviderId();
    $pdo->exec("INSERT INTO providers (name,slug,status,created_at,updated_at) VALUES ('OUTRO','outro','active',NOW(),NOW())");
    $other = (int) $pdo->lastInsertId();
    $appPdo = $schema->database->pdo();
    $repo = new NetCredentialRepository($appPdo, $box);
    $check($repo->available(), 'migration 046 aplicada: tabela existe');

    // --- repositorio ---
    $id = $repo->create($providerId, 'OLT 89', 'snmp_v2c', null, $SECRET, 'gestor');
    $row = $pdo->query("SELECT secret_cipher, nonce, key_version FROM `{$schema->schema}`.net_credentials WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
    $check(!str_contains((string) $row['secret_cipher'], $SECRET) && strlen((string) $row['nonce']) === 24 && (int) $row['key_version'] === 3, 'banco guarda so cifra + nonce + versao da chave');
    $list = $repo->list($providerId);
    $check(count($list) === 1 && !array_key_exists('segredo', $list[0]) && !str_contains(json_encode($list), $SECRET) && $list[0]['definida_por'] === 'gestor', 'listagem: so metadados (definida em/por), sem segredo');
    $check($repo->secretFor($providerId, $id) === $SECRET && $repo->secretFor($other, $id) === null, 'secretFor: texto claro so para o provedor dono');
    $repo->replaceSecret($providerId, $id, 'novo-segredo', null, 'outro-gestor');
    $check($repo->secretFor($providerId, $id) === 'novo-segredo' && $repo->list($providerId)[0]['definida_por'] === 'outro-gestor', 'substituir: novo segredo e "definida por" atualizada');
    $repo->replaceSecret($providerId, $id, $SECRET, null, 'gestor');

    // --- equipamento usando a credencial: coletor prefere credential_id ---
    $pdo->exec("INSERT INTO map_nodes (id,provider_id,node_type,name,lat,lng,created_at,updated_at) VALUES (8,$providerId,'pop','POP',-20.1,-42.1,NOW(),NOW())");
    $pdo->exec("INSERT INTO map_nodes (id,provider_id,node_type,name,parent_node_id,created_at,updated_at) VALUES (42,$providerId,'olt','OLT-T',8,NOW(),NOW())");
    $pdo->exec("INSERT INTO net_devices (provider_id,map_node_id,profile_id,mgmt_host,snmp_version,credential_ref,credential_id,enabled,created_at,updated_at) VALUES ($providerId,42,3,'192.168.0.50','2c','NET_CRED_TESTE_X',$id,0,NOW(),NOW())");
    $device = $appPdo->query('SELECT * FROM net_devices WHERE map_node_id = 42')->fetch(PDO::FETCH_ASSOC);
    putenv('NET_CRED_TESTE_X=do-env'); $_ENV['NET_CRED_TESTE_X'] = 'do-env'; $_SERVER['NET_CRED_TESTE_X'] = 'do-env';
    $check(NetTransportFactory::resolveSecret($device, $repo) === $SECRET, 'coletor: credential_id (banco) tem preferencia sobre credential_ref (.env)');
    $device['credential_id'] = null;
    $check(NetTransportFactory::resolveSecret($device, $repo) === 'do-env', 'coletor: sem credential_id usa credential_ref do .env (fallback)');
    $device['credential_id'] = $id;
    try { NetTransportFactory::resolveSecret($device, null); $check(false, 'sem repositorio deveria falhar'); } catch (RuntimeException $e) { $check(!str_contains($e->getMessage(), $SECRET), 'credential_id sem repositorio: erro sem segredo'); }
    try { NetTransportFactory::resolveSecret($device, new NetCredentialRepository($appPdo, $other_box = new NetSecretBox(random_bytes(32)))); $check(false, 'chave trocada deveria falhar'); } catch (RuntimeException $e) { $check(!str_contains($e->getMessage(), $SECRET), 'chave trocada: erro sem segredo'); }

    // --- controller ---
    $mapRepo = new MapRepository($schema->database);
    $make = static fn (NetSecretBox $b) => new NetCatalogController(new View($root . '/backend/Views'), new Config(['app' => ['name' => 'T']]), $local, $schema->database, $mapRepo, new NetCredentialRepository($schema->database->pdo(), $b));
    $ctl = $make($box);
    $mgr = static function (): void { $_SESSION = ['user' => ['login' => 'zz_gestor', 'name' => 'ZZ', 'role' => 'manager', 'id' => 0]]; };
    $tec = static function (): void { $_SESSION = ['user' => ['login' => 'zz_tec', 'name' => 'ZZ', 'role' => 'technician', 'id' => 0]]; };
    $post = static fn (string $m, array $f, bool $csrf = true) => $ctl->$m(new Request('POST', '/x', '', [], $f + ($csrf ? ['_csrf' => Csrf::token('rede')] : [])));
    $get = static fn (string $m, array $q = []) => $ctl->$m(new Request('GET', '/x', '', $q));
    $json = static fn ($r): array => json_decode($r->body(), true) ?? [];

    $mgr();
    $r = $get('credentials'); $j = $json($r);
    $check($r->status() === 200 && $j['data']['chave_disponivel'] === true && count($j['data']['credenciais']) === 1 && !str_contains($r->body(), $SECRET) && $r->header('Cache-Control') === 'private, no-store', 'GET lista: sem segredo, sem cache');
    $r = $post('credentialCreate', ['nome' => 'Rádio 1', 'tipo' => 'ssh', 'usuario' => 'admin', 'segredo' => 'senha-ssh-ULTRA'], false);
    $check($r->status() === 419, 'POST sem CSRF: 419');
    $r = $post('credentialCreate', ['nome' => 'Rádio 1', 'tipo' => 'ssh', 'usuario' => 'admin', 'segredo' => 'senha-ssh-ULTRA']); $j = $json($r);
    $check($r->status() === 200 && !str_contains($r->body(), 'senha-ssh-ULTRA'), 'criar: 200 e o segredo nao volta na resposta');
    foreach ([['nome' => '', 'segredo' => 'x'], ['nome' => 'a/b', 'segredo' => 'x'], ['nome' => 'Rádio 1', 'tipo' => 'ssh', 'segredo' => 'x'], ['nome' => 'Novo', 'tipo' => 'xyz', 'segredo' => 'x'], ['nome' => 'Novo', 'tipo' => 'ssh', 'segredo' => ''], ['nome' => 'Novo', 'tipo' => 'ssh', 'segredo' => str_repeat('a', 501)]] as $bad) {
        $r = $post('credentialCreate', $bad + ['tipo' => 'snmp_v2c']);
        $check($r->status() === 422 && !str_contains($r->body(), 'senha-ssh-ULTRA'), 'validacao 422: ' . json_encode(array_keys($bad)) . ' ' . ($bad['nome'] ?? ''));
    }
    $newId = (int) $json($post('credentialCreate', ['nome' => 'Para trocar', 'tipo' => 'snmp_v2c', 'segredo' => 'abc']))['data']['id'];
    $r = $post('credentialReplace', ['id' => (string) $newId, 'segredo' => 'segredo-novo-XYZ']);
    $check($r->status() === 200 && !str_contains($r->body(), 'segredo-novo-XYZ') && $repo->secretFor($providerId, $newId) === 'segredo-novo-XYZ', 'substituir: 200, segredo novo gravado e nao devolvido');
    $check($post('credentialReplace', ['id' => '99999', 'segredo' => 'x'])->status() === 404 && $post('credentialReplace', ['id' => (string) $newId, 'segredo' => ''])->status() === 422, 'substituir: 404 inexistente, 422 sem segredo');
    $r = $post('credentialDelete', ['id' => (string) $id]);
    $check($r->status() === 409, 'excluir credencial em uso por equipamento: 409');
    $check($post('credentialDelete', ['id' => (string) $newId])->status() === 200, 'excluir credencial sem uso: 200');
    // isolamento por provedor
    $pdo->exec("INSERT INTO `{$schema->schema}`.net_credentials (provider_id,name,kind,secret_cipher,nonce,key_version,created_at,updated_at) VALUES ($other,'DE-OUTRO','snmp_v2c','x','" . str_repeat('n', 24) . "',1,NOW(),NOW())");
    $foreign = (int) $pdo->lastInsertId();
    $check($post('credentialReplace', ['id' => (string) $foreign, 'segredo' => 'x'])->status() === 404 && $post('credentialDelete', ['id' => (string) $foreign])->status() === 404 && count($json($get('credentials'))['data']['credenciais']) === 2, 'credencial de outro provedor: invisivel e intocavel');

    // permissao
    $tec();
    foreach (['credentials', 'models', 'equipment'] as $m) { $check($get($m)->status() === 403, "tecnico: GET {$m} 403"); }
    foreach ([['credentialCreate', ['nome' => 'T', 'segredo' => 'x']], ['credentialReplace', ['id' => '1', 'segredo' => 'x']], ['credentialDelete', ['id' => '1']]] as [$m, $f]) { $check($post($m, $f)->status() === 403, "tecnico: POST {$m} 403"); }
    $page = $ctl->credentialsPage(new Request('GET', '/rede-credenciais', ''));
    $check($page->status() === 302, 'tecnico: pagina redireciona');
    $mgr();
    $page = $ctl->credentialsPage(new Request('GET', '/rede-credenciais', ''));
    $check($page->status() === 200 && str_contains($page->body(), 'id="cg-app"') && str_contains($page->body(), 'rede-catalogo.js') && !str_contains($page->body(), $SECRET), 'gestor: pagina de credenciais 200, sem segredo no HTML');

    // sem chave: bloqueia
    $ctlNoKey = $make(new NetSecretBox(null));
    $noKey = static fn (string $m, array $f) => $ctlNoKey->$m(new Request('POST', '/x', '', [], $f + ['_csrf' => Csrf::token('rede')]));
    $r = $noKey('credentialCreate', ['nome' => 'Sem chave', 'tipo' => 'snmp_v2c', 'segredo' => 'zzz']);
    $check($r->status() === 409 && str_contains($r->body(), 'NET_SECRET_KEY') && $noKey('credentialReplace', ['id' => (string) $id, 'segredo' => 'zzz'])->status() === 409, 'sem NET_SECRET_KEY: cadastro e substituicao bloqueados (409) com aviso');
    $r = $ctlNoKey->credentials(new Request('GET', '/x', ''));
    $check($json($r)['data']['chave_disponivel'] === false, 'sem chave: a listagem avisa chave_disponivel=false');

    // auditoria e logs: nunca o segredo
    $audit = (string) json_encode($pdo->query("SELECT * FROM `{$schema->schema}`.audit_logs")->fetchAll(PDO::FETCH_ASSOC));
    $check(str_contains($audit, 'net.credential.create') && str_contains($audit, 'net.credential.replace') && !str_contains($audit, $SECRET) && !str_contains($audit, 'senha-ssh-ULTRA') && !str_contains($audit, 'segredo-novo-XYZ') && !str_contains($audit, 'abc"'), 'auditoria registra criar/substituir/excluir sem nenhum segredo');
    $logFiles = glob($root . '/storage/logs/*') ?: [];
    $leak = false; foreach ($logFiles as $lf) { if (is_file($lf) && filesize($lf) < 5_000_000 && str_contains((string) file_get_contents($lf), $SECRET)) { $leak = true; } }
    $check(!$leak, 'logs em storage/logs nao contem o segredo');

    // rotas
    $router = new Router(); (require $root . '/backend/routes.php')($router);
    foreach ([['GET', '/rede-credenciais'], ['GET', '/api/rede-credenciais'], ['POST', '/api/rede-credenciais'], ['POST', '/api/rede-credenciais/substituir'], ['POST', '/api/rede-credenciais/excluir']] as [$m, $p]) { $check($router->match($m, $p) !== null, "rota {$m} {$p}"); }
    $check($router->match('GET', '/api/rede-credenciais/substituir') === null && $router->match('GET', '/api/rede-credenciais/excluir') === null, 'escritas so por POST');

    // --- migrador do .env ---
    $pdo->exec("DELETE FROM `{$schema->schema}`.net_devices");
    $pdo->exec("INSERT INTO `{$schema->schema}`.net_credentials (provider_id,name,kind,secret_cipher,nonce,key_version,created_at,updated_at) VALUES ($providerId,'placeholder','snmp_v2c','x','" . str_repeat('n', 24) . "',1,NOW(),NOW())");
    $pdo->exec("INSERT INTO net_devices (provider_id,map_node_id,profile_id,mgmt_host,snmp_version,credential_ref,enabled,created_at,updated_at) VALUES ($providerId,42,3,'192.168.0.50','2c','NET_CRED_OLT_89_COMMUNITY',0,NOW(),NOW())");
    $mig = new NetCredentialMigrator($appPdo, $repo);
    $sim = $mig->run('NET_CRED_OLT_89_COMMUNITY', 'OLT 89 migrada', false, static fn (string $n): string => 'valor-do-env-QWE');
    $check($sim['ok'] && !$sim['aplicado'] && str_contains($sim['mensagem'], 'SIMULAÇÃO') && !str_contains(json_encode($sim), 'valor-do-env-QWE') && !$repo->nameExists($providerId, 'OLT 89 migrada') && $appPdo->query('SELECT credential_id FROM net_devices')->fetchColumn() === null, 'migrador: simulacao nao grava nada nem mostra o segredo');
    $semChave = (new NetCredentialMigrator($appPdo, new NetCredentialRepository($appPdo, new NetSecretBox(null))))->run('NET_CRED_OLT_89_COMMUNITY', 'x', true, static fn (string $n): string => 'v');
    $check(!$semChave['ok'] && str_contains($semChave['mensagem'], 'NET_SECRET_KEY'), 'migrador: --apply sem chave recusa');
    $semVar = $mig->run('NET_CRED_OLT_89_COMMUNITY', 'x', true, static fn (string $n): string => '');
    $check(!$semVar['ok'] && str_contains($semVar['mensagem'], 'não está definida'), 'migrador: variavel ausente do .env recusa');
    $check(!$mig->run('FOO', 'x', true, static fn (string $n): string => 'v')['ok'], 'migrador: referencia fora do padrao NET_CRED_* recusada');
    $ap = $mig->run('NET_CRED_OLT_89_COMMUNITY', 'OLT 89 migrada', true, static fn (string $n): string => 'valor-do-env-QWE');
    $newCred = (int) $appPdo->query("SELECT id FROM net_credentials WHERE name = 'OLT 89 migrada'")->fetchColumn();
    $dev = $appPdo->query('SELECT credential_id, credential_ref FROM net_devices')->fetch(PDO::FETCH_ASSOC);
    $check($ap['ok'] && $ap['aplicado'] && (int) $dev['credential_id'] === $newCred && $dev['credential_ref'] === 'NET_CRED_OLT_89_COMMUNITY' && $repo->secretFor($providerId, $newCred) === 'valor-do-env-QWE' && !str_contains(json_encode($ap), 'valor-do-env-QWE'), 'migrador --apply: credencial cifrada criada, equipamento aponta para ela, credential_ref preservado, segredo fora do relatorio');
    $again = $mig->run('NET_CRED_OLT_89_COMMUNITY', 'OLT 89 migrada', true, static fn (string $n): string => 'valor-do-env-QWE');
    $check($again['ok'] && str_contains($again['mensagem'], 'nada a migrar') && (int) $appPdo->query("SELECT COUNT(*) FROM net_credentials WHERE name = 'OLT 89 migrada'")->fetchColumn() === 1, 'migrador: repetir e idempotente');
} finally {
    $schema->drop();
}
echo "NetCredentialSmoke: {$n} verificacoes, zero falhas\n";
