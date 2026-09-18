<?php

declare(strict_types=1);

use App\Infrastructure\MkAuth\MkAuthDatabase;
use App\Infrastructure\MkAuth\MkAuthTicketService;

require_once dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';

/**
 * Regressão/smoke: MkAuthTicketService (abertura de chamado financeiro) não
 * tinha nenhuma cobertura de teste. Este arquivo cobre o que é seguro
 * exercitar sem tocar o MkAuth real:
 *
 *  - construção do payload normalizado enviado ao MkAuth;
 *  - comportamento em modo simulado/dry-run (o único habilitado por padrão —
 *    ver config/contracts.php: dry_run é forçado a true fora de produção ou
 *    sem MKAUTH_WRITE_ENABLED);
 *  - guard clauses de falha local (ticket vazio, banco MkAuth ausente);
 *  - a lógica pura de interpretação de sucesso/erro/ID de chamado na resposta
 *    do MkAuth (bodyIndicatesError/extractTicketId), via reflection, com
 *    respostas fabricadas — sem nenhuma chamada de rede;
 *  - a classificação de status de chamado (aberto/fechado/ambíguo) em
 *    MkAuthDatabase::describeSupportTicketStatus, que é pura.
 *
 * LIMITAÇÃO DOCUMENTADA: o round-trip HTTP real (sendHttpRequest/cURL) e a
 * consulta ao banco MkAuth (findSupportTicketByNumber) não são exercitados
 * aqui, porque MkAuthTicketService fala cURL diretamente e MkAuthDatabase é
 * uma classe final sem ponto de injeção para um HTTP client ou PDO fake.
 * Cobrir isso exigiria subir um servidor HTTP fake ou refatorar o serviço
 * para aceitar um client injetável — fora do escopo desta etapa (o pedido
 * era proteção mínima, não refatorar o serviço). Nenhuma chamada real ao
 * MkAuth é feita neste arquivo: todo teste usa enabled=false/dry_run=true ou
 * força o caminho de erro local antes de qualquer tentativa de rede.
 */

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

// baseUrl deliberadamente inválida: se algum caminho de código tentasse
// mesmo assim abrir uma conexão real, falharia rápido em vez de vazar para
// um host de verdade.
$service = new MkAuthTicketService(
    ['enabled' => false, 'dry_run' => true, 'message_fallback' => true],
    'http://mkauth.invalido.teste.local',
    null,
    null,
    null,
    null,
    null
);

// 1. Modo simulado: nenhuma rede é tocada, e o payload é normalizado.
$result = $service->openFinancialTicket([
    'login' => '  cliente123  ',
    'nome' => ' Cliente Teste ',
    'descricao' => '  Boleto em atraso  ',
]);
$assert($result['status'] === 'simulado', 'Chamado com enabled=false não retornou status simulado.');
$assert($result['dry_run'] === true, 'Resultado simulado não sinaliza dry_run.');
$assert($result['payload']['login'] === 'cliente123', 'Payload normalizado não removeu espaços do login.');
$assert($result['payload']['nome'] === 'Cliente Teste', 'Payload normalizado não removeu espaços do nome.');
$assert($result['payload']['descricao'] === 'Boleto em atraso', 'Descrição não foi normalizada corretamente.');
$assert($result['payload']['msg'] === 'Boleto em atraso', 'Campo msg não espelha a descrição normalizada.');
$assert($result['payload']['assunto'] === 'Financeiro - Boleto / Carne', 'Assunto padrão não foi aplicado quando ausente.');
$assert($result['payload']['prioridade'] === 'normal', 'Prioridade padrão não foi aplicada quando ausente.');
$assert($result['http_status'] === null, 'Modo simulado não deveria registrar http_status.');

// 2. testConnection também respeita o modo simulado.
$connection = $service->testConnection();
$assert($connection['status'] === 'simulado', 'testConnection com enabled=false não retornou status simulado.');

// 3. Falhas locais que não dependem de rede.
$emptyTicketRejected = false;
try {
    $service->checkFinancialTicketStatus('   ');
} catch (\RuntimeException) {
    $emptyTicketRejected = true;
}
$assert($emptyTicketRejected, 'Número de chamado vazio não foi rejeitado.');

$missingDatabaseRejected = false;
try {
    $service->checkFinancialTicketStatus('12345');
} catch (\RuntimeException $exception) {
    $missingDatabaseRejected = str_contains($exception->getMessage(), 'Banco MkAuth');
}
$assert($missingDatabaseRejected, 'Consulta de status sem MkAuthDatabase configurado não foi rejeitada com a mensagem esperada.');

// 4. normalizePayload (privado): valores ausentes recebem os defaults certos.
$normalize = new ReflectionMethod(MkAuthTicketService::class, 'normalizePayload');
$normalized = $normalize->invoke($service, ['mensagem' => '  Falta pagamento  ']);
$assert($normalized['descricao'] === 'Falta pagamento', 'normalizePayload não aceitou "mensagem" como origem da descrição.');
$assert($normalized['observacao'] === 'Falta pagamento', 'normalizePayload não usa a descrição como observação padrão.');

// 5. bodyIndicatesError (privado, puro): interpretação de sucesso/erro da resposta.
$bodyIndicatesError = new ReflectionMethod(MkAuthTicketService::class, 'bodyIndicatesError');
$assert($bodyIndicatesError->invoke($service, ['status' => 'ok', 'id' => 42]) === false, 'Resposta de sucesso foi interpretada como erro.');
$assert($bodyIndicatesError->invoke($service, ['status' => 'erro']) === true, 'Resposta com status=erro não foi detectada como erro.');
$assert($bodyIndicatesError->invoke($service, ['erro' => 'falha qualquer']) === true, 'Resposta com chave erro não foi detectada como erro.');
$assert($bodyIndicatesError->invoke($service, ['success' => false]) === true, 'Resposta com success=false não foi detectada como erro.');
$assert($bodyIndicatesError->invoke($service, ['success' => true, 'id' => 1]) === false, 'Resposta com success=true foi interpretada como erro.');

// 6. extractTicketId (privado, puro): leitura do ID em diferentes formatos de resposta.
$extractTicketId = new ReflectionMethod(MkAuthTicketService::class, 'extractTicketId');
$assert($extractTicketId->invoke($service, ['id' => '555']) === '555', 'ID de chamado não extraído de {id}.');
$assert($extractTicketId->invoke($service, ['data' => ['chamado' => '777']]) === '777', 'ID de chamado não extraído de {data.chamado}.');
$assert($extractTicketId->invoke($service, ['retorno' => ['id' => '888']]) === '888', 'ID de chamado não extraído de {retorno.id}.');
$assert($extractTicketId->invoke($service, []) === null, 'Resposta sem ID deveria retornar null.');

// 7. applyMessageFallback (privado): sem MkAuthDatabase, não tenta nada e não falha.
$applyMessageFallback = new ReflectionMethod(MkAuthTicketService::class, 'applyMessageFallback');
$fallbackResult = $applyMessageFallback->invoke($service, '999', ['descricao' => 'texto'], []);
$assert($fallbackResult['used'] === false && $fallbackResult['status'] === 'unavailable', 'Fallback de mensagem sem MkAuthDatabase deveria ficar indisponível, sem tentar nada.');

// 8. MkAuthDatabase::describeSupportTicketStatus (puro, sem tocar o banco):
//    classificação aberto/fechado/ambíguo usada por checkFinancialTicketStatus.
$mkauthDatabase = (new ReflectionClass(MkAuthDatabase::class))->newInstanceWithoutConstructor();
$describeStatus = new ReflectionMethod(MkAuthDatabase::class, 'describeSupportTicketStatus');

$open = $describeStatus->invoke($mkauthDatabase, ['status' => 'aberto', 'fechamento' => '']);
$assert(($open['state'] ?? null) === 'open', 'Chamado aberto sem fechamento não foi classificado como open.');

$closed = $describeStatus->invoke($mkauthDatabase, ['status' => 'fechado', 'fechamento' => '2026-08-01 10:00:00']);
$assert(($closed['state'] ?? null) === 'closed', 'Chamado com status fechado não foi classificado como closed.');

$ambiguous = $describeStatus->invoke($mkauthDatabase, ['status' => 'desconhecido', 'fechamento' => '']);
$assert(($ambiguous['state'] ?? null) === 'ambiguous', 'Status desconhecido sem fechamento não foi classificado como ambiguous.');

echo "MkAuthTicketServiceRegression: {$checks} checks passed (nenhuma chamada real ao MkAuth foi feita)\n";
