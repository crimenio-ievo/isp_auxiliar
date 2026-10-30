#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * ISP Map (fase 1G): gera as portas reais das CTOs que já existiam antes da 1G, pela attrs.capacity.
 * Mesma regra do MapController: port_role 'fo', media 'fibra' (derivada), externa, rótulo "Porta n", status 'livre'.
 * Só mexe em CTO que ainda não tem nenhuma porta 'fo'. Simulação por padrão; grava só com --apply
 * (uma transação, com registro map.port.sync em audit_logs por CTO).
 */

use App\Infrastructure\Database\Database;

require dirname(__DIR__) . '/backend/bootstrap/app.php';

$apply = in_array('--apply', array_slice($argv, 1), true);
$app = bootstrapApplication();
$pdo = (new Database($app->config()))->pdo();

echo 'ISP Map - backfill de portas das CTOs (' . ($apply ? 'APLICANDO' : 'SIMULAÇÃO; use --apply para gravar') . ")\n\n";

$ctos = $pdo->query(
    "SELECT n.id, n.provider_id, n.name, n.attrs_json,
            (SELECT COUNT(*) FROM map_ports p WHERE p.node_id = n.id AND p.port_role = 'fo') AS fo_ports
       FROM map_nodes n WHERE n.node_type = 'cto' ORDER BY n.provider_id, n.id"
)->fetchAll(PDO::FETCH_ASSOC);

$plan = [];
foreach ($ctos as $cto) {
    $attrs = $cto['attrs_json'] === null ? [] : (json_decode((string) $cto['attrs_json'], true) ?: []);
    $cap = $attrs['capacity'] ?? null;
    $label = "#{$cto['id']} {$cto['name']} (provedor {$cto['provider_id']})";
    if ((int) $cto['fo_ports'] > 0) {
        echo "[IGNORADA] {$label}: já tem {$cto['fo_ports']} porta(s).\n";
        continue;
    }
    if (!is_numeric($cap) || (int) $cap < 1 || (int) $cap > 144) {
        echo "[AVISO]    {$label}: capacity ausente/inválida (" . json_encode($cap) . "); nada a gerar.\n";
        continue;
    }
    $plan[] = ['cto' => $cto, 'capacity' => (int) $cap];
    echo "[GERAR]    {$label}: capacity {$cap} => Porta 1..Porta {$cap} (fo, fibra, externa, livre)\n";
}

$total = array_sum(array_column($plan, 'capacity'));
echo "\nResumo: " . count($plan) . ' CTO(s), ' . $total . " porta(s) a criar.\n";

if (!$apply || $plan === []) {
    exit(0);
}

$insertPort = $pdo->prepare(
    "INSERT INTO map_ports (provider_id, node_id, port_num, label, port_role, media, is_external, status, created_at, updated_at)
     VALUES (:p, :n, :num, :label, 'fo', 'fibra', 1, 'livre', NOW(), NOW())"
);
$insertAudit = $pdo->prepare(
    "INSERT INTO audit_logs (provider_id, user_id, actor_login, action, entity_type, entity_id, ip_address, user_agent, context_json, created_at)
     VALUES (:p, NULL, 'script:backfill_map_cto_ports', 'map.port.sync', 'map_node', :n, '', 'cli', :ctx, NOW())"
);

$pdo->beginTransaction();
try {
    foreach ($plan as $item) {
        $cto = $item['cto'];
        for ($i = 1; $i <= $item['capacity']; $i++) {
            $insertPort->execute(['p' => $cto['provider_id'], 'n' => $cto['id'], 'num' => $i, 'label' => 'Porta ' . $i]);
        }
        $insertAudit->execute([
            'p' => $cto['provider_id'],
            'n' => $cto['id'],
            'ctx' => json_encode(['backfill' => true, 'capacity' => $item['capacity'], 'added' => $item['capacity']], JSON_UNESCAPED_UNICODE),
        ]);
    }
    $pdo->commit();
} catch (\Throwable $exception) {
    $pdo->rollBack();
    fwrite(STDERR, "Erro; nada gravado: {$exception->getMessage()}\n");
    exit(1);
}

echo "Gravado: {$total} porta(s).\n";
