<?php

declare(strict_types=1);

use App\Controllers\ContractController;
use App\Core\Config;
use App\Infrastructure\Contracts\MessageTemplateRepository;
use App\Infrastructure\Local\LocalRepository;
use App\Services\Notifications\NotificationTemplateService;

require_once dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';

/**
 * Regressão: o reenvio manual de aceite (ContractController::enviarAceiteWhatsapp/
 * enviarAceiteEmail) tinha um MessageTemplateRepository injetado mas nunca
 * consultado — o texto era sempre hardcoded, ignorando qualquer customização
 * feita em Configurações > Mensagens. Além disso, o purpose que o código
 * morto calculava (aceite_nova_instalacao/aceite_regularizacao_contrato)
 * nunca correspondia ao esquema realmente lido em nenhum outro lugar do
 * sistema. Este teste não faz nenhuma chamada real ao MkAuth, Evotrix ou
 * SMTP, e não toca no banco de dados real.
 */

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

$root = dirname(__DIR__, 2);
$contractControllerSource = (string) file_get_contents($root . '/backend/Controllers/ContractController.php');
$notificationServiceSource = (string) file_get_contents($root . '/backend/Services/Notifications/NotificationTemplateService.php');

// 1. NotificationTemplateService::render() é uma função pura: prova que,
//    dado um template customizado, o texto customizado é o que sai — sem
//    tocar em banco de dados.
$templateRepositoryStub = (new ReflectionClass(MessageTemplateRepository::class))->newInstanceWithoutConstructor();
$notificationService = new NotificationTemplateService($templateRepositoryStub);

$customTemplate = [
    'id' => 999,
    'purpose' => 'instalacao_reenviar_aceite',
    'channel' => 'whatsapp',
    'subject' => '',
    'body' => 'Mensagem customizada para %nomecliente%: acesse %linkaceite%',
    'active' => 1,
    'enabled_channels_json' => json_encode(['whatsapp']),
];
$rendered = $notificationService->render($customTemplate, [
    'nomecliente' => 'Fulano de Tal',
    'linkaceite' => 'https://exemplo.com/aceite/token123',
]);
$assert(
    $rendered['body'] === 'Mensagem customizada para Fulano de Tal: acesse https://exemplo.com/aceite/token123',
    'Template customizado não foi renderizado corretamente.'
);
$assert(!str_contains($rendered['body'], 'iEvo Technology'), 'Texto renderizado não deveria conter o texto padrão hardcoded.');

// 2. Confere que os builders de mensagem do ContractController realmente
//    consultam o template ativo antes de cair no texto padrão (wiring).
$assert(str_contains($contractControllerSource, "findActiveAcceptanceTemplate(\$contract, 'whatsapp')"), 'buildAcceptanceWhatsappMessages não consulta mais template configurado.');
$assert(str_contains($contractControllerSource, "findActiveAcceptanceTemplate(\$contract, 'email')"), 'buildAcceptanceEmailMessage não consulta mais template configurado.');
$assert(str_contains($contractControllerSource, '$this->notificationTemplateService->render($template'), 'Builders não usam NotificationTemplateService::render ao encontrar template ativo.');

// 3. Os purposes usados agora são os do esquema realmente lido em produção
//    (o mesmo que ClientController::dispatchAcceptanceChannels usa), não o
//    esquema órfão antigo (aceite_nova_instalacao/aceite_regularizacao_contrato)
//    que nunca era consultado por nenhum código.
foreach (['instalacao_reenviar_aceite', 'migracao_reenviar_aceite', 'assinatura_avulsa_reenviar'] as $purpose) {
    $assert(str_contains($contractControllerSource, "'{$purpose}'"), "ContractController não referencia o purpose vivo {$purpose}.");
    $assert(str_contains($notificationServiceSource, "'{$purpose}'"), "Purpose {$purpose} não é semeado por NotificationTemplateService (seria órfão).");
}
$assert(!str_contains($contractControllerSource, "'aceite_nova_instalacao'"), 'ContractController ainda referencia o purpose órfão aceite_nova_instalacao.');
$assert(!str_contains($contractControllerSource, "'aceite_regularizacao_contrato'"), 'ContractController ainda referencia o purpose órfão aceite_regularizacao_contrato.');

// 4. Execução real dos builders sem template disponível (repositório e
//    serviço de template propositalmente quebrados/sem DB): prova que o
//    fallback hardcoded continua funcionando e é usado quando não há
//    template ativo. evotrixService/emailService ficam propositalmente sem
//    valor: qualquer tentativa real de envio explodiria com erro de
//    propriedade não inicializada, então checks > 0 abaixo já comprova que
//    nenhum envio foi disparado.
$controller = (new ReflectionClass(ContractController::class))->newInstanceWithoutConstructor();
$controllerReflection = new ReflectionClass(ContractController::class);

$controllerReflection->getProperty('localRepository')->setValue($controller, (new ReflectionClass(LocalRepository::class))->newInstanceWithoutConstructor());
$controllerReflection->getProperty('config')->setValue($controller, new Config([
    'app' => ['name' => 'ISP Auxiliar Teste'],
    'contracts' => [
        'commercial' => ['central_assinante_url' => 'https://central.teste'],
        'acceptance_ttl_hours' => 48,
    ],
]));
$controllerReflection->getProperty('messageTemplateRepository')->setValue($controller, (new ReflectionClass(MessageTemplateRepository::class))->newInstanceWithoutConstructor());
$controllerReflection->getProperty('notificationTemplateService')->setValue($controller, (new ReflectionClass(NotificationTemplateService::class))->newInstanceWithoutConstructor());

$fakeDetail = [
    'contract' => [
        'id' => 4242,
        'tipo_aceite' => 'nova_instalacao',
        'nome_cliente' => 'Cliente Teste',
        'technician_name' => 'Téc. Teste',
    ],
    'acceptance' => ['id' => 1, 'token_hash' => 'tokenfalso'],
    'acceptance_delivery_token' => 'tokenfalso',
];

$buildWhatsapp = new ReflectionMethod(ContractController::class, 'buildAcceptanceWhatsappMessages');
$whatsappMessages = $buildWhatsapp->invoke($controller, $fakeDetail);
$assert(is_array($whatsappMessages) && count($whatsappMessages) === 1, 'Fallback de WhatsApp não retornou exatamente uma mensagem.');
$assert(str_contains($whatsappMessages[0], 'Cliente Teste'), 'Fallback de WhatsApp não usou o nome do cliente.');
$assert(str_contains($whatsappMessages[0], 'iEvo Technology'), 'Fallback de WhatsApp deixou de existir quando não há template ativo.');

$buildEmail = new ReflectionMethod(ContractController::class, 'buildAcceptanceEmailMessage');
[$subject, $html, $text] = $buildEmail->invoke($controller, $fakeDetail);
$assert(str_contains($subject, 'Aceite digital do contrato'), 'Fallback de e-mail não gerou assunto padrão.');
$assert(str_contains($text, 'Cliente Teste'), 'Fallback de e-mail não usou o nome do cliente.');
$assert(str_contains($html, 'Cliente Teste'), 'Fallback de e-mail (HTML) não usou o nome do cliente.');

echo "ContractTemplateResendRegression: {$checks} checks passed\n";
