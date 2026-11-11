#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Telemetria de fibra - relatório de casamento MAC da ONU x caller-id do PPPoE. SOMENTE LEITURA.
 * Entrada: net_onus (não removidas) do banco local + MkAuth (só SELECT): último callingstationid de cada login ativo
 * com sessão nos últimos N dias do radacct. A janela é contada a partir do registro MAIS RECENTE do radacct
 * (o clone .88 está parado), e a data de referência é impressa.
 * Não grava nada (nem client_network_bindings, nem MkAuth). MACs mascarados nos 3 últimos octetos, logins mascarados.
 *
 * Uso: php scripts/net_match_report.php [--device=ID] [--days=30] [--examples=10]
 */

use App\Infrastructure\Database\Database;
use App\Infrastructure\MkAuth\MkAuthDatabase;
use App\Infrastructure\Net\MacMatcher;
use App\Infrastructure\Net\ValueDecoder;

require dirname(__DIR__) . '/backend/bootstrap/app.php';

$o = getopt('', ['device:', 'days:', 'examples:']);
$deviceId = isset($o['device']) ? (int) $o['device'] : null;
$days = max(1, min(365, (int) ($o['days'] ?? 30)));
$examples = max(0, min(50, (int) ($o['examples'] ?? 10)));

$app = bootstrapApplication();
$pdo = (new Database($app->config()))->pdo();
$sql = 'SELECT id, onu_identifier, pon_index, onu_index FROM net_onus WHERE removed_at IS NULL AND identifier_type = \'epon_mac\'' . ($deviceId ? ' AND device_id = :d' : '');
$st = $pdo->prepare($sql);
$st->execute($deviceId ? ['d' => $deviceId] : []);
$onus = array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'mac' => $r['onu_identifier'], 'pon' => (int) $r['pon_index'], 'onu' => (int) $r['onu_index']], $st->fetchAll(PDO::FETCH_ASSOC));

$container = (new ReflectionProperty($app, 'container'))->getValue($app);
/** @var MkAuthDatabase $mk */
$mk = $container->get(MkAuthDatabase::class);
if (!$mk->isConfigured()) {
    fwrite(STDERR, "MkAuth não configurado.\n");
    exit(2);
}
$mkPdo = (new ReflectionMethod($mk, 'connection'))->invoke($mk);
$ref = (string) $mkPdo->query('SELECT MAX(acctstarttime) FROM radacct')->fetchColumn();
$from = date('Y-m-d H:i:s', strtotime($ref) - $days * 86400);
$st = $mkPdo->prepare(
    "SELECT r.username, r.callingstationid
       FROM radacct r
       JOIN (SELECT username, MAX(radacctid) AS id FROM radacct WHERE acctstarttime >= :f GROUP BY username) x ON x.id = r.radacctid
       JOIN sis_cliente c ON c.login = r.username
      WHERE LOWER(TRIM(c.cli_ativado)) IN ('s','sim')"
);
$st->execute(['f' => $from]);
$callers = [];
$badCaller = 0;
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $mac = ValueDecoder::mac((string) $r['callingstationid']);
    $mac === null ? $badCaller++ : $callers[(string) $r['username']] = $mac;
}

$res = MacMatcher::match($onus, $callers);
echo "Telemetria - casamento MAC ONU x caller-id PPPoE (somente leitura)\n";
echo "radacct: registro mais recente {$ref}; janela de {$days} dias a partir de {$from}\n";
echo 'ONUs (net_onus não removidas' . ($deviceId ? ", equipamento {$deviceId}" : '') . '): ' . count($onus) . "\n";
echo 'Logins ativos com sessão na janela: ' . count($callers) . ($badCaller ? " (+{$badCaller} com caller-id inválido)" : '') . "\n\n";
$n = max(1, count($onus));
printf("Candidato único: %d (%.1f%%)\nAmbíguo:         %d (%.1f%%)\nSem candidato:   %d (%.1f%%)\n", $res['unique'], 100 * $res['unique'] / $n, $res['ambiguous'], 100 * $res['ambiguous'] / $n, $res['none'], 100 * $res['none'] / $n);
echo "Pares encontrados: {$res['exact']} iguais, {$res['near']} só nos 5 primeiros octetos\n";
echo 'Distâncias no último octeto (caller - ONU => pares): ' . ($res['distances'] === [] ? 'nenhuma' : json_encode($res['distances'])) . "\n";
echo "Logins casados com mais de uma ONU: {$res['login_conflicts']}\n";

$shown = ['unique' => 0, 'ambiguous' => 0, 'none' => 0];
echo "\nExemplos:\n";
foreach ($res['items'] as $it) {
    if ($shown[$it['kind']] >= $examples) {
        continue;
    }
    $shown[$it['kind']]++;
    $c = array_map(static fn (array $x): string => MacMatcher::maskLogin($x['login']) . ' ' . ValueDecoder::maskMac($x['caller']) . ' d=' . $x['distance'], $it['candidates']);
    printf("  [%s] PON %d ONU %d %s -> %s\n", $it['kind'], $it['onu']['pon'], $it['onu']['onu'], ValueDecoder::maskMac($it['onu']['mac']), $c === [] ? '-' : implode('; ', $c));
}
echo "\nNada foi gravado.\n";
