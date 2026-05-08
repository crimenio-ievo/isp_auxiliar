<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\AcceptanceController;
use App\Controllers\ContractController;
use App\Controllers\ClientController;
use App\Controllers\DashboardController;
use App\Controllers\SettingsController;
use App\Controllers\SystemController;
use App\Core\Router;

/**
 * Arquivo central de rotas da aplicacao.
 *
 * Nesta etapa mantemos todas as rotas em um unico lugar para facilitar a
 * leitura. Se o projeto crescer, esta lista pode ser quebrada em modulos.
 */
return static function (Router $router): void {
    $router->get('/', [AuthController::class, 'home'], 'home');
    $router->get('/login', [AuthController::class, 'showLogin'], 'login');
    $router->post('/login', [AuthController::class, 'login'], 'login.submit');
    $router->get('/logout', [AuthController::class, 'logout'], 'logout');
    $router->get('/api/usuario/validar', [AuthController::class, 'validateUser'], 'api.usuario.validate');

    $router->get('/dashboard', [DashboardController::class, 'index'], 'dashboard');
    $router->get('/contratos', [ContractController::class, 'index'], 'contracts.index');
    $router->post('/contratos', [ContractController::class, 'index'], 'contracts.index.save');
    $router->get('/contratos/novos', [ContractController::class, 'novos'], 'contracts.new');
    $router->get('/contratos/aceites/pendentes', [ContractController::class, 'aceitesPendentes'], 'contracts.acceptances.pending');
    $router->get('/contratos/detalhe', [ContractController::class, 'detalhe'], 'contracts.detail');
    $router->post('/contratos/aceite/enviar', [ContractController::class, 'enviarAceiteWhatsapp'], 'contracts.acceptance.send');
    $router->post('/contratos/aceite/email', [ContractController::class, 'enviarAceiteEmail'], 'contracts.acceptance.email');
    $router->post('/contratos/financeiro/concluir', [ContractController::class, 'concluirFinanceiro'], 'contracts.financial.complete');
    $router->post('/contratos/financeiro/cancelar', [ContractController::class, 'cancelarFinanceiro'], 'contracts.financial.cancel');
    $router->post('/contratos/financeiro/chamado', [ContractController::class, 'abrirChamadoFinanceiro'], 'contracts.financial.ticket');
    $router->get('/aceite/{token}', [AcceptanceController::class, 'show'], 'acceptance.show');
    $router->post('/aceite/{token}/confirmar', [AcceptanceController::class, 'confirm'], 'acceptance.confirm');
    $router->get('/clientes/novo', [ClientController::class, 'create'], 'clients.create');
    $router->post('/clientes/novo', [ClientController::class, 'store'], 'clients.store');
    $router->post('/clientes/rascunho/limpar', [ClientController::class, 'clearDraft'], 'clients.draft.clear');
    $router->get('/clientes/novo/aceite', [ClientController::class, 'acceptance'], 'clients.acceptance');
    $router->post('/clientes/novo/aceite', [ClientController::class, 'finalize'], 'clients.finalize');
    $router->get('/clientes/conexao', [ClientController::class, 'connection'], 'clients.connection');
    $router->post('/clientes/conexao/finalizar', [ClientController::class, 'completeConnection'], 'clients.connection.complete');
    $router->get('/clientes/retomar', [ClientController::class, 'resume'], 'clients.resume');
    $router->get('/clientes/evidencias', [ClientController::class, 'evidence'], 'clients.evidence');
    $router->get('/clientes/evidencias/arquivo', [ClientController::class, 'evidenceFile'], 'clients.evidence.file');
    $router->get('/api/cep/lookup', [ClientController::class, 'lookupCep'], 'api.cep.lookup');
    $router->get('/api/cliente/validar', [ClientController::class, 'validateClientField'], 'api.client.validate');
    $router->get('/api/cliente/conexao', [ClientController::class, 'checkConnection'], 'api.client.connection');
    $router->get('/instalacoes', [SystemController::class, 'installations'], 'installations.index');
    $router->post('/instalacoes/excluir', [SystemController::class, 'deleteInstallation'], 'installations.delete');
    $router->get('/usuarios', [SystemController::class, 'users'], 'users.index');
    $router->post('/usuarios/gestor', [SystemController::class, 'saveManager'], 'users.manager.save');
    $router->get('/logs', [SystemController::class, 'logs'], 'logs.index');
    $router->get('/configuracoes', [SettingsController::class, 'index'], 'settings.index');
    $router->post('/configuracoes', [SettingsController::class, 'save'], 'settings.save');
    $router->get('/api/health', [SystemController::class, 'health'], 'api.health');
};
