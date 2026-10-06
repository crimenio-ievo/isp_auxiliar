<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';

$root = dirname(__DIR__, 2);
$n = 0;
$assert = static function (bool $ok, string $msg) use (&$n): void {
    if (!$ok) {
        throw new RuntimeException("Verificação falhou: {$msg}");
    }
    $n++;
};

$render = static function (array $tile) use ($root): string {
    $alertTile = $tile;
    ob_start();
    require $root . '/backend/Views/components/alert_tile.php';

    return (string) ob_get_clean();
};

$linked = $render(['label' => 'X', 'value' => '3', 'variant' => 'queue', 'href' => '/clientes?situacao=em_dia']);
$assert(str_starts_with($linked, '<a ') && str_contains($linked, 'href="/clientes?situacao=em_dia"') && str_contains($linked, 'alert-tile--link') && str_contains($linked, '</a>'), 'bloco com href vira link');
$plain = $render(['label' => 'X', 'value' => '3', 'variant' => 'ok']);
$assert(str_starts_with($plain, '<article ') && !str_contains($plain, 'href') && str_contains($plain, '</article>'), 'bloco sem href continua article');
$xss = $render(['label' => 'X', 'value' => '1', 'href' => '/a"><script>']);
$assert(!str_contains($xss, '<script>'), 'href é escapado');

$db = (string) file_get_contents($root . '/backend/Infrastructure/MkAuth/MkAuthDatabase.php');
$assert(substr_count($db, 'self::OVERDUE_LOGINS_SUBQUERY') >= 2 && str_contains($db, 'GROUP BY LOWER(TRIM(l.login))'), 'contagem e lista reaproveitam o JOIN agregado de vencidos');
$i = strpos($db, 'function listClientsBySituation');
$body = substr($db, $i, 2600);
$assert(!str_contains($body, 'EXISTS') && !str_contains($body, 'radacct'), 'lista não usa subquery por cliente');

// Home limpa: só os 4 blocos (o "Fluxo do dia" foi para /instalacoes).
$home = (string) file_get_contents($root . '/backend/Views/dashboard/index.php');
foreach (['Dashboard de cadastro', 'Usuario atual', 'Integração', 'Rotina recomendada', 'hero-panel'] as $removed) {
    $assert(!str_contains($home, $removed), "Home ainda contém: {$removed}");
}
$assert(str_contains($home, 'alert-tiles') && !str_contains($home, 'Fluxo do dia'), 'Home só tem os blocos; Fluxo do dia saiu');

echo "HomeAlertTilesRegression: {$n} checks passed\n";
