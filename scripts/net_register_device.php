#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Telemetria de fibra - registra um equipamento gerenciável (net_devices) ligado 1:1 a um nó do mapa.
 * Simulação por padrão; grava só com --apply (uma transação + registro net.device.create em audit_logs).
 * Não lê nem imprime a credencial: guarda apenas o NOME da variável (credential_ref, NET_CRED_*).
 *
 * Uso: php scripts/net_register_device.php --map-node=42 --host=192.168.0.89 --profile=hioso-epon-25355-4-4-v1 \
 *        --credential-ref=NET_CRED_OLT_89_COMMUNITY --expect-pons=4 [--apply]
 */

use App\Core\Env;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Net\DeviceProfile;
use App\Infrastructure\Net\NetTransportFactory;

require dirname(__DIR__) . '/backend/bootstrap/app.php';

$o = getopt('', ['map-node:', 'host:', 'profile:', 'credential-ref:', 'expect-pons:', 'apply']);
$nodeId = (int) ($o['map-node'] ?? 0);
$host = (string) ($o['host'] ?? '');
$code = (string) ($o['profile'] ?? '');
$ref = (string) ($o['credential-ref'] ?? '');
$expectPons = (int) ($o['expect-pons'] ?? 0);
$apply = isset($o['apply']);

$errors = [];
if ($nodeId < 1 || $host === '' || $code === '' || $ref === '' || $expectPons < 1) {
    fwrite(STDERR, "Uso: --map-node=ID --host=IP --profile=CODE --credential-ref=NET_CRED_X --expect-pons=N [--apply]\n");
    exit(1);
}
if (!filter_var($host, FILTER_VALIDATE_IP)) {
    $errors[] = 'host precisa ser um IP.';
}
if (!NetTransportFactory::isValidCredentialRef($ref)) {
    $errors[] = 'credential-ref precisa seguir NET_CRED_[A-Z0-9_].';
}

$app = bootstrapApplication();
$pdo = (new Database($app->config()))->pdo();
echo 'Telemetria - registro de equipamento (' . ($apply ? 'APLICANDO' : 'SIMULAÇÃO; use --apply para gravar') . ")\n\n";

$st = $pdo->prepare('SELECT id, provider_id, node_type, name, lifecycle FROM map_nodes WHERE id = :id');
$st->execute(['id' => $nodeId]);
$node = $st->fetch(PDO::FETCH_ASSOC);
if ($node === false) {
    $errors[] = "nó {$nodeId} não existe no mapa.";
} else {
    echo "Nó do mapa: #{$node['id']} {$node['name']} (tipo {$node['node_type']}, provedor {$node['provider_id']}, {$node['lifecycle']})\n";
    if ($node['node_type'] !== 'olt') {
        $errors[] = 'o nó não é do tipo olt.';
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM map_ports WHERE node_id = :id AND port_role = 'pon'");
    $st->execute(['id' => $nodeId]);
    $pons = (int) $st->fetchColumn();
    echo "Portas PON no mapa: {$pons} (esperado {$expectPons})\n";
    if ($pons !== $expectPons) {
        $errors[] = "o nó tem {$pons} portas PON, esperado {$expectPons}: confira antes de registrar.";
    }
    // Contrato de dados de rede: port_num da PON no mapa == pon_index da OLT (1..N), conferido na OLT 1.
    $st = $pdo->prepare("SELECT port_num FROM map_ports WHERE node_id = :id AND port_role = 'pon' ORDER BY port_num");
    $st->execute(['id' => $nodeId]);
    $nums = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if ($nums !== range(1, $expectPons)) {
        $errors[] = 'port_num das PONs no mapa (' . implode(',', $nums) . ') não é 1..' . $expectPons . ': o contrato exige port_num == pon_index.';
    }
    $st = $pdo->prepare('SELECT id FROM net_devices WHERE map_node_id = :id');
    $st->execute(['id' => $nodeId]);
    if (($existing = $st->fetchColumn()) !== false) {
        $errors[] = "o nó já tem equipamento registrado (net_devices #{$existing}).";
    }
}

$st = $pdo->prepare('SELECT id, code, status, profile_json FROM net_device_profiles WHERE code = :c');
$st->execute(['c' => $code]);
$profile = $st->fetch(PDO::FETCH_ASSOC);
if ($profile === false) {
    $errors[] = "perfil {$code} não existe.";
} else {
    try {
        DeviceProfile::fromJson($profile['code'], (string) $profile['profile_json']);
        echo "Perfil: #{$profile['id']} {$profile['code']} ({$profile['status']}) válido\n";
    } catch (\Throwable $e) {
        $errors[] = $e->getMessage();
    }
    if ($profile['status'] !== 'active') {
        $errors[] = 'perfil não está ativo.';
    }
}
echo "Host: {$host} | SNMP v2c | credencial: {$ref} (" . ((string) (Env::get($ref, '') ?? '') !== '' ? 'definida no .env' : 'AINDA NÃO definida no .env') . ")\n";

if ($errors !== []) {
    echo "\nNada gravado:\n";
    foreach ($errors as $e) {
        echo " - {$e}\n";
    }
    exit(2);
}
if (!$apply) {
    echo "\nSimulação ok. Rode com --apply para gravar.\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    $ins = $pdo->prepare(
        "INSERT INTO net_devices (provider_id, map_node_id, profile_id, mgmt_host, snmp_version, credential_ref, expected_identity_json, enabled, status_interval_s, created_at, updated_at)
         VALUES (:p, :n, :prof, :h, '2c', :ref, NULL, 1, 300, NOW(), NOW())"
    );
    $ins->execute(['p' => $node['provider_id'], 'n' => $nodeId, 'prof' => $profile['id'], 'h' => $host, 'ref' => $ref]);
    $deviceId = (int) $pdo->lastInsertId();
    $audit = $pdo->prepare(
        "INSERT INTO audit_logs (provider_id, user_id, actor_login, action, entity_type, entity_id, ip_address, user_agent, context_json, created_at)
         VALUES (:p, NULL, 'cli', 'net.device.create', 'net_device', :id, 'cli', 'scripts/net_register_device.php', :ctx, NOW())"
    );
    $audit->execute(['p' => $node['provider_id'], 'id' => $deviceId, 'ctx' => json_encode(['map_node_id' => $nodeId, 'host' => $host, 'profile' => $code, 'credential_ref' => $ref], JSON_UNESCAPED_SLASHES)]);
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Falha ao gravar: ' . $e->getMessage() . "\n");
    exit(2);
}
echo "\nEquipamento registrado: net_devices #{$deviceId}. Identidade de referência ainda vazia (aprovar no 1º ciclo com --approve-identity).\n";
