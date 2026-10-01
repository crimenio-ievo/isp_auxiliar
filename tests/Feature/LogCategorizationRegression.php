<?php

declare(strict_types=1);

use App\Services\Logs\AuditEventCatalog as C;

require dirname(__DIR__, 2) . '/backend/bootstrap/app.php';

$n = 0;
$check = static function (bool $ok, string $msg) use (&$n): void {
    if (!$ok) {
        throw new RuntimeException("Verificação falhou: {$msg}");
    }
    $n++;
};

$expected = [
    'auth.login' => C::SECURITY,
    'contract.signed_term.operator_viewed' => C::SECURITY,
    'contract.acceptance.accepted' => C::CONTRACT,
    'client.acceptance.whatsapp.sent' => C::CONTRACT,
    'contract.financial_task.created' => C::CONTRACT,
    'contract.financial_task.ticket.created' => C::TICKET,
    'financial_task.mkauth_ticket.closed' => C::TICKET,
    'client.financial_task.ticket.auto_created' => C::TICKET,
    'client.provisioned' => C::PROVISIONING,
    'installation.completed' => C::PROVISIONING,
    'operational_process.step.defer' => C::PROCESS,
    'contract.upgrade.operational_task.created' => C::PROCESS,
    'settings.smtp.test' => C::SETTINGS,
    'ai.configuration.saved' => C::SETTINGS,
    'release.channel.preference_changed' => C::SETTINGS,
    'algo.desconhecido' => C::OTHER,
];
foreach ($expected as $action => $category) {
    $check(C::category($action) === $category, "{$action} deveria ser {$category}, veio " . C::category($action));
}
$check(C::category('algo.desconhecido', 'financial_task') === C::CONTRACT, 'fallback por entity_type');
$check(C::label('contract.acceptance.confirmed') === 'Cliente confirmou o aceite.', 'texto preservado do dicionário antigo');
$check(C::label('operational_process.step.qualquer') === 'Etapa operacional atualizada.', 'prefixo de etapa preservado');
$check(C::label('x.y') === 'Registro operacional atualizado.', 'fallback genérico');
$check(C::isFailure('contract.acceptance.email.failed') && !C::isFailure('contract.acceptance.created'), 'detecção de falha');
$check(isset(C::categories()[C::OTHER]) && count(C::categories()) === 7, 'sete categorias');

echo "LogCategorizationRegression: {$n} checks passed\n";
