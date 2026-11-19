#!/usr/bin/env php
<?php
declare(strict_types=1);
// Configuracao do /mapa em provider_settings (nada secreto): URL do OSRM ("Seguir rua"), perfil e folga padrao dos cabos.
// Simulacao por padrao; so grava com --apply. Uso:
//   php scripts/map_config.php --show
//   php scripts/map_config.php --osrm-url=http://127.0.0.1:5000 [--osrm-profile=driving] [--slack-pct=5] [--provider=ID] [--apply]
//   php scripts/map_config.php --clear-osrm-url [--apply]
use App\Infrastructure\Database\Database;
use App\Infrastructure\Map\OsrmRouter;
use App\Infrastructure\Net\NetCliOptions;

require dirname(__DIR__) . '/backend/bootstrap/app.php';

try {
    $o = NetCliOptions::parse(array_slice($argv, 1), ['osrm-url', 'osrm-profile', 'slack-pct', 'provider'], ['show', 'apply', 'clear-osrm-url']);
} catch (InvalidArgumentException) {
    fwrite(STDERR, "Uso: php scripts/map_config.php --show | [--osrm-url=URL|--clear-osrm-url] [--osrm-profile=P] [--slack-pct=N] [--provider=ID] [--apply]\n");
    exit(1);
}
$changes = [];
if (isset($o['osrm-url'])) {
    $url = OsrmRouter::validBase((string) $o['osrm-url']);
    if ($url === null) { fwrite(STDERR, "URL invalida: use http:// ou https:// e nada de espacos.\n"); exit(1); }
    $changes['map.routing.osrm_url'] = $url;
}
if (isset($o['clear-osrm-url'])) { $changes['map.routing.osrm_url'] = ''; }
if (isset($o['osrm-profile'])) {
    if (!in_array($o['osrm-profile'], ['driving', 'car', 'bike', 'foot', 'walking', 'cycling'], true)) { fwrite(STDERR, "Perfil invalido.\n"); exit(1); }
    $changes['map.routing.profile'] = (string) $o['osrm-profile'];
}
if (isset($o['slack-pct'])) {
    if (!is_numeric($o['slack-pct']) || (float) $o['slack-pct'] < 0 || (float) $o['slack-pct'] > 100) { fwrite(STDERR, "Folga invalida (0 a 100).\n"); exit(1); }
    $changes['map.cable.slack_pct'] = (string) (float) $o['slack-pct'];
}
if ($changes === [] && !isset($o['show'])) { fwrite(STDERR, "Nada a fazer: use --show ou informe o que mudar.\n"); exit(1); }

try {
    $pdo = (new Database(bootstrapApplication()->config()))->pdo();
    $providerId = isset($o['provider']) && ctype_digit((string) $o['provider']) ? (int) $o['provider'] : (int) $pdo->query('SELECT id FROM providers ORDER BY id LIMIT 1')->fetchColumn();
    if ($providerId < 1) { throw new RuntimeException('sem provedor'); }
    $keys = ['map.routing.osrm_url', 'map.routing.profile', 'map.cable.slack_pct'];
    $read = static function () use ($pdo, $providerId, $keys): array {
        $st = $pdo->prepare('SELECT setting_key, setting_value FROM provider_settings WHERE provider_id = ? AND setting_key IN (?, ?, ?)');
        $st->execute(array_merge([$providerId], $keys));
        return $st->fetchAll(PDO::FETCH_KEY_PAIR);
    };
    $now = $read();
    echo "Provedor {$providerId}\n";
    foreach ($keys as $k) { echo sprintf("  %-24s %s\n", $k, isset($now[$k]) && $now[$k] !== '' ? $now[$k] : '(nao definido)'); }
    if ($changes === []) { exit(0); }
    echo "Mudancas " . (isset($o['apply']) ? 'APLICADAS' : 'propostas (simulacao; use --apply)') . ":\n";
    foreach ($changes as $k => $v) { echo sprintf("  %-24s %s -> %s\n", $k, $now[$k] ?? '(nao definido)', $v === '' ? '(vazio: desligado)' : $v); }
    if (!isset($o['apply'])) { exit(0); }
    $up = $pdo->prepare('INSERT INTO provider_settings (provider_id, setting_key, setting_value, is_secret, updated_at) VALUES (?, ?, ?, 0, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()');
    foreach ($changes as $k => $v) { $up->execute([$providerId, $k, $v]); }
    echo "Gravado.\n";
} catch (Throwable) {
    fwrite(STDERR, "Nao foi possivel ler/gravar a configuracao (banco ou provedor).\n");
    exit(2);
}
