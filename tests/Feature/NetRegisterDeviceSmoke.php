<?php
declare(strict_types=1);
// Verificacao estatica de scripts/net_register_device.php (sem banco, sem rede).
$src = (string) file_get_contents(dirname(__DIR__, 2) . '/scripts/net_register_device.php');
$n = 0;
$check = static function (bool $ok, string $m) use (&$n): void { $n++; if (!$ok) { throw new RuntimeException("FALHA: {$m}"); } };
$check(str_contains($src, "'2c', :ref, NULL, 1, 600, NOW(), NOW())"), 'novo equipamento nasce com status_interval_s = 600 (igual ao padrao da migration 033)');
$check(!str_contains($src, ', 1, 300,'), 'nao grava mais 300 s');
$check(str_contains($src, 'port_num') && str_contains($src, 'range(1, $expectPons)'), 'validacao port_num == pon_index segue presente');
echo "NetRegisterDeviceSmoke: {$n} verificacoes, zero falhas\n";
