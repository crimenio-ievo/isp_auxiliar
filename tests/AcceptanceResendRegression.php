<?php

declare(strict_types=1);

use App\Core\Config;
use App\Services\Contracts\AcceptanceWorkflowService;

require_once dirname(__DIR__) . '/backend/bootstrap/autoload.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/backend/Controllers/ContractController.php');
$repository = (string) file_get_contents($root . '/backend/Infrastructure/Contracts/ContractAcceptanceRepository.php');
$publicController = (string) file_get_contents($root . '/backend/Controllers/AcceptanceController.php');
$view = (string) file_get_contents($root . '/backend/Views/contracts/acceptance.php');
$common = (string) file_get_contents($root . '/scripts/releases/common.sh');

$service = new AcceptanceWorkflowService(new Config(['contracts' => ['acceptance_ttl_hours' => 48]]));
$renewal = $service->renewExpiredToken([]);
$assert(preg_match('/^[a-f0-9]{32}$/', $renewal['token']) === 1, 'Token renovado não usa CSPRNG de 128 bits.');
$assert((new DateTimeImmutable($renewal['token_expires_at'])) > new DateTimeImmutable('+47 hours'), 'Token renovado não recebeu TTL configurado.');
$assert(str_contains($controller, 'findByIdForUpdate($acceptanceId)'), 'Reenvio não bloqueia o aceite persistido durante a rotação.');
$assert(str_contains($controller, 'renewExpiredToken($acceptance)'), 'Reenvio expirado não gera novo token.');
$assert(str_contains($controller, 'updateById($acceptanceId, $updated)'), 'Novo token não é persistido antes da URL.');
$assert(str_contains($controller, 'findById($acceptanceId)'), 'Novo token não é relido após persistência.');
$assert(str_contains($controller, 'acceptance_delivery_token'), 'Mensagem não usa o token confirmado para entrega.');
$assert(str_contains($controller, "'token_hash' => (string) (\$acceptance['token_hash'] ?? '')") === false, 'Teste de regressão requer link de entrega explícito.');
$assert(str_contains($controller, "'rotated' => false"), 'Aceite ainda válido não preserva a expiração existente.');
$assert(str_contains($controller, "(string) (\$acceptance['status'] ?? '') === 'aceito'"), 'Reenvio forçado de aceite concluído poderia substituir seu histórico.');
$assert(str_contains($repository, 'findByIdForUpdate'), 'Repositório não oferece bloqueio transacional do aceite.');
$assert(str_contains($repository, 'legacy_token_hash'), 'Links legados com hash não permanecem verificáveis.');
$assert(!str_contains($controller, 'Central do Assinante'), 'Mensagem de aceite ainda menciona Central do Assinante.');
$assert(!str_contains($controller, 'expira em {$ttl} horas'), 'Mensagem de aceite ainda informa prazo genérico.');
$assert(str_contains($publicController, 'Este link não está mais válido.') && str_contains($publicController, 'Use o link mais recente enviado pela iEvo.'), 'Erros públicos de token não estão claros.');
$assert(str_contains($view, '$isUnavailable = !empty($context[\'error\']);'), 'Tela pública ainda pode renderizar dados vazios para token inválido.');
$assert(str_contains($common, 'RELEASE_WEB_GROUP:-www-data') && str_contains($common, '-m 2770'), 'Permissões permanentes da release não foram configuradas.');

echo "AcceptanceResendRegression: {$checks} checks passed\n";
