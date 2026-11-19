<?php

declare(strict_types=1);

// Gera NET_SECRET_KEY no .env SOMENTE se ainda não existir (não troca uma chave existente: trocar = perder as credenciais cifradas).
// Uso: php scripts/net_secret_key.php [--env=/caminho/.env]. Não imprime a chave. Faça backup do .env depois (ver docs).

use App\Infrastructure\Net\NetSecretBox;

require dirname(__DIR__) . '/backend/Infrastructure/Net/NetSecretBox.php';

$opts = getopt('', ['env:']);
$file = (string) ($opts['env'] ?? dirname(__DIR__) . '/.env');
if (!is_file($file) || !is_writable($file)) {
    fwrite(STDERR, "Arquivo .env não encontrado ou sem permissão de escrita.\n");
    exit(1);
}
$content = (string) file_get_contents($file);
if (preg_match('/^\s*NET_SECRET_KEY\s*=\s*\S+/m', $content) === 1) {
    echo "NET_SECRET_KEY já existe: nada foi alterado.\n";
    exit(0);
}
$line = 'NET_SECRET_KEY=' . NetSecretBox::generateKey() . "\n";
$prefix = $content === '' || str_ends_with($content, "\n") ? '' : "\n";
if (file_put_contents($file, $prefix . $line, FILE_APPEND | LOCK_EX) === false) {
    fwrite(STDERR, "Não foi possível gravar a chave.\n");
    exit(1);
}
echo "NET_SECRET_KEY gerada e gravada no .env (valor não exibido). Guarde uma cópia do .env: perder a chave = perder as credenciais.\n";
exit(0);
