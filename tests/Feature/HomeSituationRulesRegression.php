<?php

declare(strict_types=1);

use App\Infrastructure\MkAuth\MkAuthDatabase;

require dirname(__DIR__, 2) . '/backend/bootstrap/app.php';

/**
 * Regras dos blocos da Home contra o MkAuth de leitura (somente SELECT):
 * vermelho = ativo e (bloqueado OU status_corte=down); amarelo = ativo, não vermelho, vencido há MAIS de N dias;
 * em dia = demais ativos; desativados fora; contagem = lista; os três somam o total de ativos.
 */

$n = 0;
$assert = static function (bool $ok, string $msg) use (&$n): void {
    if (!$ok) {
        throw new RuntimeException("Verificação falhou: {$msg}");
    }
    $n++;
};

// 1) Motivo em texto simples (sem banco).
$assert(MkAuthDatabase::situationReason(['bloqueado' => 'sim'], 5) === 'Bloqueado', 'motivo: bloqueado');
$assert(MkAuthDatabase::situationReason(['bloqueado' => 'nao', 'status_corte' => 'down'], 5) === 'Velocidade reduzida', 'motivo: velocidade reduzida');
$assert(MkAuthDatabase::situationReason(['bloqueado' => 'nao', 'status_corte' => 'full', 'overdue_days' => '12'], 5) === 'Fatura vencida há 12 dias', 'motivo: vencido há 12 dias');
$assert(MkAuthDatabase::situationReason(['bloqueado' => 'nao', 'overdue_days' => '1'], 0) === 'Fatura vencida há 1 dia', 'motivo: singular');
$assert(str_starts_with(MkAuthDatabase::situationReason(['bloqueado' => 'nao', 'overdue_days' => '3'], 5), 'Em dia'), 'motivo: dentro do prazo é em dia');
$assert(MkAuthDatabase::situationReason(['bloqueado' => 'nao'], 5) === 'Em dia', 'motivo: sem vencido');

$app = bootstrapApplication();
$container = (new ReflectionProperty($app, 'container'))->getValue($app);
/** @var MkAuthDatabase $db */
$db = $container->get(MkAuthDatabase::class);
if (!$db->isConfigured()) {
    echo "HomeSituationRulesRegression: {$n} checks passed (MkAuth não configurado: parte com banco ignorada)\n";
    exit(0);
}
$fetchOne = new ReflectionMethod($db, 'fetchOne');
$active = (int) $fetchOne->invoke($db, 'SELECT COUNT(*) AS n FROM sis_cliente WHERE login IS NOT NULL AND login <> "" AND LOWER(TRIM(cli_ativado)) IN ("s","sim","1","true","yes")')['n'];

foreach ([0, 5, 10] as $grace) {
    $counts = $db->homeSituationCounts($grace);
    $assert(array_sum($counts) === $active, "N={$grace}: vermelho+amarelo+em dia = total de ativos ({$active}), veio " . json_encode($counts));
    foreach (['bloqueados', 'atencao', 'em_dia'] as $situation) {
        $list = $db->listClientsBySituation($situation, $grace, 200, 0);
        $expected = min(200, $counts[$situation]);
        $assert(count($list) === $expected, "N={$grace} {$situation}: lista ({$expected}) deve ter o mesmo tamanho da contagem {$counts[$situation]}, veio " . count($list));
        foreach ($list as $row) {
            $reason = (string) ($row['situation_reason'] ?? '');
            $assert($reason !== '', "N={$grace} {$situation}: cliente sem motivo");
            if ($situation === 'atencao') {
                $assert(str_starts_with($reason, 'Fatura vencida há '), 'amarelo deve explicar a fatura vencida');
            }
            if ($situation === 'bloqueados') {
                $assert(in_array($reason, ['Bloqueado', 'Velocidade reduzida'], true), 'vermelho deve ser bloqueado ou reduzido');
            }
        }
    }
}

// Desativados (cli_ativado='n') não aparecem em nenhuma lista.
$rawFetchAll = new ReflectionMethod($db, 'fetchAll');
$inactive = array_flip(array_map('strtolower', array_column($rawFetchAll->invoke($db, 'SELECT login FROM sis_cliente WHERE LOWER(TRIM(cli_ativado)) NOT IN ("s","sim","1","true","yes")'), 'login')));
foreach (['bloqueados', 'atencao', 'em_dia'] as $situation) {
    foreach ($db->listClientsBySituation($situation, 5, 200, 0) as $row) {
        $assert(!isset($inactive[strtolower((string) $row['login'])]), "desativado apareceu em {$situation}");
    }
}

// Quanto maior N, menos amarelo (e o excedente vai para Em dia).
$c0 = $db->homeSituationCounts(0);
$c30 = $db->homeSituationCounts(30);
$assert($c30['atencao'] <= $c0['atencao'] && $c30['em_dia'] >= $c0['em_dia'] && $c30['bloqueados'] === $c0['bloqueados'], 'N maior move clientes de Atenção para Em dia sem tocar no vermelho');

echo "HomeSituationRulesRegression: {$n} checks passed (ativos={$active}, N=5: " . json_encode($db->homeSituationCounts(5)) . ")\n";
