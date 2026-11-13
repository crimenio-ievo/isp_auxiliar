<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';

use App\Infrastructure\Net\OltLogParser;

$n = 0;
$check = static function (bool $ok, string $m) use (&$n): void { $n++; if (!$ok) { throw new RuntimeException("FALHA: {$m}"); } };
$M1 = 'AA:BB:CC:00:00:01';
$M2 = 'AA:BB:CC:00:00:02';
$M3 = 'AA:BB:CC:00:00:03';
$L = static function (string $ts, string $mac, string $ev, string $ix = '0/1:1'): string {
    return 'Nov  9 ' . substr($ts, 11) . ' EPON: [' . $ts . '] ONU ' . $ix . "      [ {$mac} ] [Na] {$ev}\n";
};
$parse = static fn (string $c, ?array $w = null) => ($w === null ? new OltLogParser() : new OltLogParser($w))->parse($c);
$one = static function (array $r): array { return $r['outages'][0]; };

// Data vem do timestamp entre colchetes; linhas sem colchetes (login web/CLI) sao ignoradas.
$r = $parse("Nov  9 10:00:00 EPON HIOSO 1: User admin login from Web 10.4.0.2\nNov  9 10:00:01 EPON: User admin login from CLI 10.4.0.2\n"
    . 'Nov  1 01:01:01 EPON: [2026-11-12 10:00:00] ONU 0/1:1 [ ' . $M1 . " ] [Na] linkdown\n" . $L('2026-11-12 10:05:00', $M1, 'linkup'));
$o = $one($r);
$check($o['started_at'] === '2026-11-12 10:00:00' && $o['ended_at'] === '2026-11-12 10:05:00' && $o['duration_s'] === 300, 'usa timestamp entre colchetes (nao o prefixo syslog)');
$check($r['summary']['linhas_ignoradas'] === 2 && count($r['outages']) === 1, 'login web/CLI ignorado');

// Chave = MAC, nunca o indice 0/x:y: mesmo MAC em PON diferente e a mesma ONU.
$r = $parse($L('2026-11-12 10:00:00', $M1, 'linkdown', '0/1:5') . $L('2026-11-12 10:01:00', $M1, 'linkup', '0/4:2')
    . $L('2026-11-12 11:00:00', $M2, 'linkdown', '0/1:5') . $L('2026-11-12 11:00:30', $M2, 'linkup', '0/1:5'));
$check(count($r['outages']) === 2 && $r['outages'][0]['mac'] === $M1 && $r['outages'][0]['ended_at'] === '2026-11-12 10:01:00', 'queda fecha por MAC mesmo com indice diferente');
$check($r['outages'][1]['mac'] === $M2, 'dois MACs no mesmo indice nao se misturam');
$r = $parse($L('2026-11-12 10:00:00', strtolower($M1), 'linkdown') . $L('2026-11-12 10:00:09', $M1, 'linkup'));
$check($r['outages'][0]['mac'] === $M1 && count($r['outages']) === 1, 'MAC minusculo normalizado');

// Dying Gasp + linkdown em ate 5 s = energia (gasp antes ou depois); sem Dying Gasp = sem aviso de energia.
$r = $parse($L('2026-11-12 10:00:00', $M1, 'Dying Gasp (PowerFaile)') . $L('2026-11-12 10:00:03', $M1, 'linkdown') . $L('2026-11-12 10:02:00', $M1, 'linkup'));
$check($one($r)['cause'] === 'energia' && $one($r)['dying_gasp_at'] === '2026-11-12 10:00:00', 'gasp 3 s antes do linkdown = energia');
$r = $parse($L('2026-11-12 10:00:00', $M1, 'linkdown') . $L('2026-11-12 10:00:05', $M1, 'Dying Gasp (PowerFaile)') . $L('2026-11-12 10:02:00', $M1, 'linkup'));
$check($one($r)['cause'] === 'energia', 'gasp 5 s depois do linkdown (limite) = energia');
$r = $parse($L('2026-11-12 10:00:00', $M1, 'linkdown') . $L('2026-11-12 10:00:06', $M1, 'Dying Gasp (PowerFaile)') . $L('2026-11-12 10:02:00', $M1, 'linkup'));
$check($one($r)['cause'] === 'sem_aviso_energia' && $r['summary']['dying_gasp_sem_linkdown'] === 1, 'gasp 6 s depois nao pareia');
$r = $parse($L('2026-11-12 10:00:00', $M1, 'linkdown') . $L('2026-11-12 10:00:01', $M2, 'Dying Gasp (PowerFaile)') . $L('2026-11-12 10:02:00', $M1, 'linkup'));
$check($one($r)['cause'] === 'sem_aviso_energia', 'gasp de outra ONU nao conta');
$check(!str_contains(json_encode($one($r)), 'fibra'), 'nao afirma fibra');

// Dedupe: 3 Dying Gasp iguais = 1; linkup repetido sem linkdown = 1.
$r = $parse(str_repeat($L('2026-11-12 10:00:00', $M1, 'Dying Gasp (PowerFaile)'), 3) . $L('2026-11-12 10:00:00', $M1, 'linkdown') . $L('2026-11-12 10:01:00', $M1, 'linkup'));
$check($r['summary']['dying_gasp_duplicados'] === 2 && count($r['outages']) === 1 && $one($r)['cause'] === 'energia', '3 Dying Gasp iguais = 1');
$r = $parse($L('2026-11-12 10:00:00', $M1, 'linkdown') . $L('2026-11-12 10:01:00', $M1, 'linkup') . $L('2026-11-12 10:01:03', $M1, 'linkup') . $L('2026-11-12 10:01:05', $M1, 'linkup'));
$check($r['summary']['linkup_duplicados'] === 2 && count($r['outages']) === 1 && $one($r)['ended_at'] === '2026-11-12 10:01:00', 'linkup repetido sem linkdown = 1 (vale o primeiro)');
$r = $parse($L('2026-11-12 10:00:00', $M1, 'linkdown') . $L('2026-11-12 10:00:10', $M1, 'linkdown') . $L('2026-11-12 10:01:00', $M1, 'linkup'));
$check(count($r['outages']) === 1 && $one($r)['started_at'] === '2026-11-12 10:00:00' && $r['summary']['linkdown_duplicados'] === 1, 'linkdown repetido sem linkup entre eles = 1 (decisao documentada)');

// Queda = linkdown ate o proximo linkup; sem linkup = em aberto; duas quedas do mesmo MAC.
$r = $parse($L('2026-11-12 10:00:00', $M1, 'linkdown') . $L('2026-11-12 10:00:20', $M1, 'linkup') . $L('2026-11-12 12:00:00', $M1, 'linkdown'));
$check(count($r['outages']) === 2 && $r['outages'][0]['duration_s'] === 20 && $r['outages'][1]['open'] === true && $r['outages'][1]['ended_at'] === null && $r['outages'][1]['duration_s'] === null, 'queda fechada e queda em aberto');
$check($r['outages'][0]['event_key'] !== $r['outages'][1]['event_key'] && strlen($r['outages'][0]['event_key']) === 64, 'event_key distinto por queda');
$r2 = $parse($L('2026-11-12 10:00:00', $M1, 'linkdown') . $L('2026-11-12 10:00:20', $M1, 'linkup'));
$check($r2['outages'][0]['event_key'] === $r['outages'][0]['event_key'], 'event_key estavel (idempotencia)');

// "first time linkup" nao e queda.
$r = $parse($L('2026-11-12 10:00:00', $M1, 'first time linkup') . $L('2026-11-12 10:00:30', $M2, 'first time linkup'));
$check($r['outages'] === [] && $r['summary']['first_linkup'] === 2, 'first time linkup nao gera queda');
$r = $parse($L('2026-11-12 10:00:00', $M1, 'linkdown') . $L('2026-11-12 10:00:30', $M1, 'first time linkup', '0/2:1'));
$check(count($r['outages']) === 1 && $one($r)['returned_via'] === 'first_linkup' && $one($r)['open'] === false, 'first time linkup fecha queda aberta (ONU voltou)');

// Manutencao/boot: ate 2 min apos "EPON System Start"; depois disso conta.
$start = "Nov  9 20:00:00 EPON: [2026-11-12 10:00:00] EPON System Start, V2.2.86\n";
$r = $parse($start . $L('2026-11-12 10:02:00', $M1, 'linkdown') . $L('2026-11-12 10:02:05', $M1, 'linkup')
    . $L('2026-11-12 10:02:01', $M2, 'linkdown') . $L('2026-11-12 10:02:30', $M2, 'linkup'));
$check($r['outages'][0]['maintenance'] === 'boot' && $r['outages'][0]['counts_as_outage'] === false, 'linkdown a exatos 2 min do boot e manutencao');
$check($r['outages'][1]['maintenance'] === null && $r['outages'][1]['counts_as_outage'] === true, 'linkdown 2 min + 1 s depois do boot conta como queda');
$r = $parse($L('2026-11-12 09:59:59', $M1, 'linkdown') . $L('2026-11-12 10:00:30', $M1, 'linkup') . $start);
$check($one($r)['maintenance'] === null, 'antes do boot nao e manutencao');
$r = $parse($start . $L('2026-11-12 10:00:30', $M3, 'Dying Gasp (PowerFaile)') . $L('2026-11-12 10:00:31', $M3, 'linkdown') . $L('2026-11-12 10:00:50', $M3, 'linkup'));
$check($one($r)['maintenance'] === 'boot' && $one($r)['cause'] === 'energia', 'boot mantem a causa observada mas nao conta');

// Troca de PON de 09/11 20:23-20:26 (varias ONUs): manutencao por janela, fora da janela conta.
$swap = '';
foreach ([$M1, $M2, $M3] as $i => $m) {
    $swap .= $L('2026-11-09 20:23:58', $m, 'linkdown', '0/1:' . $i) . $L('2026-11-09 20:26:09', $m, 'linkup', '0/2:' . $i);
}
$r = $parse($swap);
$check(count($r['outages']) === 3 && count(array_filter($r['outages'], static fn ($o) => $o['maintenance'] === 'troca_pon' && !$o['counts_as_outage'])) === 3, 'janela de troca de PON (padrao) = manutencao');
$r = $parse($L('2026-11-09 20:27:00', $M1, 'linkdown') . $L('2026-11-09 20:27:20', $M1, 'linkup') . $L('2026-11-09 20:22:59', $M2, 'linkdown') . $L('2026-11-09 20:23:10', $M2, 'linkup'));
$check($r['outages'][0]['maintenance'] === null && $r['outages'][1]['maintenance'] === null, 'logo fora da janela conta como queda');
$check((new OltLogParser([['from' => '2026-11-09 20:23:00', 'to' => '2026-11-09 20:26:59', 'reason' => 'obra']]))->parse($swap)['outages'][0]['maintenance'] === 'obra', 'janelas configuraveis');
$check(count(array_filter((new OltLogParser([]))->parse($swap)['outages'], static fn ($o) => $o['counts_as_outage'])) === 3, 'sem janelas, tudo conta');

// Entrada estranha: linha invalida, texto desconhecido, vazio.
$r = $parse("lixo\n\n" . 'Nov  9 20:00:00 EPON: [2026-13-45 99:99:99] ONU 0/1:1 [ ' . $M1 . " ] [Na] linkdown\n" . $L('2026-11-12 10:00:00', $M1, 'algo novo'));
$check($r['outages'] === [] && $r['summary']['linhas_invalidas'] === 1 && $r['summary']['onu_linhas_desconhecidas'] === 1, 'linha invalida e evento desconhecido nao geram queda');
$check($parse('')['outages'] === [] && $parse('')['summary']['eventos_onu'] === 0, 'arquivo vazio');
$r = $parse($L('2026-11-12 10:00:05', $M1, 'linkup') . $L('2026-11-12 10:00:01', $M1, 'linkdown'));
$check(count($r['outages']) === 1 && $one($r)['started_at'] === '2026-11-12 10:00:01', 'ordena por timestamp, nao pela ordem do arquivo');

// Fixture reduzido (MACs mascarados, trechos reais do syslog).
$fx = dirname(__DIR__) . '/fixtures/olt_syslog_reduced.log';
$txt = (string) file_get_contents($fx);
$check($txt !== '' && preg_match('/\[ ([0-9A-F]{2}:){2}[0-9A-F]{2}:00:00:[0-9A-F]{2} \]/', $txt) === 1 && preg_match_all('/\[ ((?:[0-9A-F]{2}:){5}[0-9A-F]{2}) \]/', $txt, $mm) > 0 && count(array_filter($mm[1], static fn ($x) => !str_contains($x, ':00:00:'))) === 0, 'fixture com todos os MACs mascarados');
$r = $parse($txt);
$s = $r['summary'];
$check($s['system_start'] === 1 && $s['linhas_ignoradas'] === 16 && $s['dying_gasp_duplicados'] === 4 && $s['linkup_duplicados'] === 2, 'fixture: contadores do resumo');
$real = array_values(array_filter($r['outages'], static fn ($o) => $o['counts_as_outage']));
$check(count($r['outages']) === 18 && count($real) === 5, 'fixture: 18 eventos, 5 contam como queda');
$check(count(array_filter($r['outages'], static fn ($o) => $o['maintenance'] === 'boot')) === 5 && count(array_filter($r['outages'], static fn ($o) => $o['maintenance'] === 'troca_pon')) === 8, 'fixture: boot 5 e troca_pon 8');
$by = static fn (string $at) => array_values(array_filter($real, static fn ($o) => $o['started_at'] === $at))[0] ?? null;
$check($by('2026-11-09 20:41:19')['cause'] === 'sem_aviso_energia' && $by('2026-11-09 20:41:19')['duration_s'] === 2, 'fixture: queda sem aviso de energia');
$q = $by('2026-11-09 23:36:02');
$check($q['cause'] === 'energia' && $q['duration_s'] === 161 && $q['dying_gasp_at'] === '2026-11-09 23:36:01', 'fixture: Dying Gasp triplicado vira 1 queda por energia (161 s)');
$check($by('2026-11-10 01:20:31')['open'] === true, 'fixture: queda em aberto');
$check($by('2026-11-10 08:59:05')['duration_s'] === 1825, 'fixture: queda longa por energia (1825 s)');

// Janelas de manutencao configuraveis.
$check((new OltLogParser())->windows() === OltLogParser::DEFAULT_WINDOWS && (new OltLogParser([]))->windows() === [], 'janela padrao mantida; lista vazia = sem janelas');
$w = OltLogParser::windowFromString('2026-11-12 10:00:00,2026-11-12 10:30:59,obra_pon');
$check($w === ['from' => '2026-11-12 10:00:00', 'to' => '2026-11-12 10:30:59', 'reason' => 'obra_pon'], 'janela valida');
$check(OltLogParser::windowFromString(' 2026-11-12T10:00 , 2026-11-12 10:30 ') === ['from' => '2026-11-12 10:00:00', 'to' => '2026-11-12 10:30:00', 'reason' => 'manutencao'], 'janela com T, sem segundos e motivo padrao');
foreach (['', 'so-um', '2026-11-12 10:00:00,2026-11-12 11:00:00,a,b', '2026-13-45 10:00:00,2026-11-12 11:00:00', '2026-11-12 11:00:00,2026-11-12 10:00:00', 'lixo,2026-11-12 10:00:00', '2026-11-12 10:00:00,2026-11-12 11:00:00,Ruim!', '2026-11-12 10:00:00,2026-11-12 11:00:00,' . str_repeat('a', 31), '2026-11-12 10:00:00,2026-11-12 11:00:00,'] as $bad) {
    try { OltLogParser::windowFromString($bad); $check(false, 'janela invalida aceita: ' . $bad); } catch (InvalidArgumentException $e) { $check(true, 'janela invalida rejeitada'); }
}
$extra = [OltLogParser::DEFAULT_WINDOWS[0], OltLogParser::windowFromString('2026-11-12 10:00:00,2026-11-12 10:30:00,obra')];
$rr = (new OltLogParser($extra))->parse($L('2026-11-12 10:05:00', $M1, 'linkdown') . $L('2026-11-12 10:06:00', $M1, 'linkup') . $L('2026-11-09 20:24:00', $M2, 'linkdown') . $L('2026-11-09 20:25:00', $M2, 'linkup') . $L('2026-11-12 10:31:00', $M3, 'linkdown') . $L('2026-11-12 10:32:00', $M3, 'linkup'));
$byMac = []; foreach ($rr['outages'] as $o) { $byMac[$o['mac']] = $o; }
$check($byMac[$M1]['maintenance'] === 'obra' && $byMac[$M2]['maintenance'] === 'troca_pon' && $byMac[$M3]['maintenance'] === null, 'janela acrescentada convive com a padrao; fora das janelas conta como queda');
$check((new OltLogParser(OltLogParser::DEFAULT_WINDOWS))->parse($L('2026-11-12 10:05:00', $M1, 'linkdown') . $L('2026-11-12 10:06:00', $M1, 'linkup'))['outages'][0]['maintenance'] === null, 'sem a janela extra, a mesma queda conta');

// Opcao repetivel da CLI.
$args = ['--device=1', '--maintenance-window=a,b', '--file', 'x.log', '--maintenance-window', 'c,d', '--apply'];
$got = App\Infrastructure\Net\NetCliOptions::extractRepeatable($args, 'maintenance-window');
$check($got === ['a,b', 'c,d'] && $args === ['--device=1', '--file', 'x.log', '--apply'], 'extractRepeatable: varias ocorrencias, nas duas formas, sem tocar nas outras opcoes');
$none = ['--device=1']; $check(App\Infrastructure\Net\NetCliOptions::extractRepeatable($none, 'maintenance-window') === [] && $none === ['--device=1'], 'extractRepeatable: sem ocorrencias');
foreach ([['--maintenance-window'], ['--maintenance-window='], ['--maintenance-window', '--apply']] as $badArgs) {
    try { App\Infrastructure\Net\NetCliOptions::extractRepeatable($badArgs, 'maintenance-window'); $check(false, 'opcao sem valor aceita'); } catch (InvalidArgumentException $e) { $check(true, 'extractRepeatable: opcao sem valor rejeitada'); }
}
// CLI: janela invalida/excesso falham na validacao de argumentos, antes de qualquer acesso a banco (codigo 1).
$cli = static function (array $a): array { $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/scripts/net_log_import.php') . ' ' . implode(' ', array_map('escapeshellarg', $a)) . ' 2>&1'; exec($cmd, $out, $code); return [$code, implode("\n", $out)]; };
$fx = dirname(__DIR__) . '/fixtures/olt_syslog_reduced.log';
[$code, $out] = $cli(['--device=1', '--file=' . $fx, '--maintenance-window=2026-11-12 11:00:00,2026-11-12 10:00:00']);
$check($code === 1 && str_contains($out, 'o início é depois do fim'), 'CLI rejeita janela invertida');
[$code, $out] = $cli(['--device=1', '--file=' . $fx, '--maintenance-window=lixo']);
$check($code === 1 && str_contains($out, 'Janela inválida'), 'CLI rejeita janela mal formada');
$many = []; for ($i = 0; $i <= OltLogParser::MAX_WINDOWS; $i++) { $many[] = '--maintenance-window=2026-11-12 10:00:00,2026-11-12 10:30:00'; }
[$code, $out] = $cli(array_merge(['--device=1', '--file=' . $fx], $many));
$check($code === 1 && str_contains($out, 'Janelas demais'), 'CLI limita o numero de janelas');

echo "OltLogParserSmoke: {$n} verificacoes, 0 falhas\n";
