<?php
declare(strict_types=1);
// Sobe um schema descartável (isp_auxiliar_browser_sim), semeia 1 POP + 3 CTOs fictícias e grava UMA sessão de harness
// (gestor fictício, sem usuário no banco) num diretório temporário. Imprime JSON {sessionDir, sessionId}.
// Teardown: php tests/browser/scratch_down.php <sessionDir>
$root = dirname(__DIR__, 2);
require $root . '/backend/bootstrap/autoload.php';
require $root . '/tests/support/ScratchSchema.php';

use App\Controllers\MapController;
use App\Core\Csrf;
use App\Core\Request;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\Map\MapRepository;
use Tests\Support\ScratchSchema;

$schema = (new ScratchSchema($root, 'browser'))->create();
$pdo = $schema->pdo;
$ctl = new MapController(new LocalRepository($schema->database, 'default'), new MapRepository($schema->database));
$_SESSION = ['user' => ['login' => 'zz_harness', 'name' => 'Harness', 'role' => 'manager', 'id' => 0]];
$mk = static function (array $f) use ($ctl): int {
    $r = $ctl->createNode(new Request('POST', '/x', '', [], $f + ['_csrf' => Csrf::token('map')]));
    return (int) (json_decode($r->body(), true)['data']['node']['id'] ?? 0);
};
// Coimbra/MG, coordenadas fictícias de teste
$mk(['node_type' => 'cto', 'name' => 'CTO-T1', 'lat' => '-20.8570', 'lng' => '-42.8010', 'attrs' => '{"capacity":8}']);
$mk(['node_type' => 'cto', 'name' => 'CTO-T2', 'lat' => '-20.8570', 'lng' => '-42.7990', 'attrs' => '{"capacity":8}']);
$mk(['node_type' => 'cto', 'name' => 'CTO-T3', 'lat' => '-20.8550', 'lng' => '-42.7990', 'attrs' => '{"capacity":8}']);
$mk(['node_type' => 'pop', 'name' => 'POP-T', 'lat' => '-20.8550', 'lng' => '-42.8010']);

$dir = sys_get_temp_dir() . '/mapa_harness_' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
session_save_path($dir);
session_name('isp_auxiliar_session');
$id = 'harness' . bin2hex(random_bytes(8));
session_id($id);
session_start();
$_SESSION = ['user' => ['login' => 'zz_harness', 'name' => 'Harness', 'role' => 'manager', 'id' => 0]];
session_write_close();
// o ScratchSchema só dropa se $created; mantemos o schema no ar (a conexão fecha sem apagar: drop() não é chamado)
echo json_encode(['sessionDir' => $dir, 'sessionId' => $id, 'schema' => $schema->schema]), "\n";
