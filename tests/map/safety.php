<?php
// Os harnesses históricos limpam por prefixo: não tocar em fixtures preexistentes.
if ((int) $pdo->query("SELECT COUNT(*) FROM map_nodes WHERE name LIKE 'ZZ TESTE%'")->fetchColumn() !== 0) {
    fwrite(STDERR, "Há nós ZZ TESTE preexistentes. Execução cancelada sem limpeza.\n");
    exit(1);
}
$auditStart = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM audit_logs')->fetchColumn();
register_shutdown_function(static function () use ($pdo, $auditStart): void {
    // Inclui a trilha gerada por estas sessões fictícias, preservando logs anteriores.
    $stmt = $pdo->prepare("DELETE FROM audit_logs WHERE id > ? AND actor_login IN ('zz_teste_manager', 'zz_teste_technician', 'zz_teste_tec') AND action LIKE 'map.%'");
    $stmt->execute([$auditStart]);
    $remaining = (int) $pdo->query("SELECT COUNT(*) FROM map_nodes WHERE name LIKE 'ZZ TESTE%'")->fetchColumn();
    echo "Fixtures ZZ TESTE restantes: $remaining\n";
    if ($remaining !== 0) {
        fwrite(STDERR, "[FALHA] limpeza incompleta\n");
        exit(1);
    }
});
