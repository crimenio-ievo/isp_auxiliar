<?php
declare(strict_types=1);
// Remove o schema descartável do navegador e o diretório de sessão do harness. Só apaga o que tem o nome esperado.
$dir = $argv[1] ?? '';
$pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('DROP DATABASE IF EXISTS `isp_auxiliar_browser_sim`');
if ($dir !== '' && str_starts_with(basename($dir), 'mapa_harness_') && is_dir($dir)) {
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
}
echo "harness removido\n";
