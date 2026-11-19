<?php

declare(strict_types=1);

// Migra fabricante/modelo do mapa (attrs_json) para o catalogo e liga cada no ao equipamento fisico (map_nodes.equipment_id).
// Uso: php scripts/net_equipment_migrate.php [--link-node=42 --model=HA7004S] [--apply]
// Sem --apply e so simulacao (relatorio JSON). Requer a migration 047. Nao toca em credenciais nem na OLT.

use App\Infrastructure\Database\Database;
use App\Infrastructure\Net\NetEquipmentMigrator;
use App\Infrastructure\Net\NetEquipmentRepository;

require dirname(__DIR__) . '/backend/bootstrap/app.php';

$opts = getopt('', ['link-node:', 'model:', 'apply']);
$links = [];
if (isset($opts['link-node'])) {
    $node = (int) $opts['link-node'];
    $model = trim((string) ($opts['model'] ?? ''));
    if ($node < 1 || $model === '') {
        fwrite(STDERR, "Uso: --link-node=ID --model=NOME_DO_MODELO [--apply]\n");
        exit(1);
    }
    $links[$node] = $model;
}
try {
    $app = bootstrapApplication();
    $pdo = (new Database($app->config()))->pdo();
    $repo = new NetEquipmentRepository($pdo);
    if (!$repo->available()) {
        fwrite(STDERR, "A migration 047 ainda nao foi aplicada.\n");
        exit(1);
    }
    $report = (new NetEquipmentMigrator($pdo, $repo))->run(isset($opts['apply']), $links);
} catch (Throwable) {
    fwrite(STDERR, "Nao foi possivel executar (banco ou configuracao indisponivel).\n");
    exit(1);
}
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
echo $report['aplicado'] ? "APLICADO.\n" : "SIMULACAO: nada foi gravado. Use --apply para aplicar.\n";
