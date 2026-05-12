<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Url;
use App\Core\View;
use App\Infrastructure\Contracts\ContractAcceptanceRepository;
use App\Infrastructure\Contracts\MessageTemplateRepository;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Contracts\FinancialTaskRepository;
use App\Infrastructure\Contracts\NotificationLogRepository;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\MkAuth\MkAuthTicketService;
use App\Infrastructure\Notifications\EmailService;
use App\Infrastructure\Notifications\EvotrixService;

/**
 * Primeira interface visual do modulo Contratos e Aceites.
 *
 * Nesta fase o controlador apenas consulta dados reais quando as tabelas
 * existem e mostra mensagens amigaveis quando a base ainda nao foi criada.
 */
final class ContractController
{
    private const REQUIRED_TABLES = [
        'client_contracts',
        'contract_acceptances',
        'financial_tasks',
        'message_templates',
        'notification_logs',
    ];

    public function __construct(
        private View $view,
        private Config $config,
        private Database $database,
        private LocalRepository $localRepository,
        private FinancialTaskRepository $financialTaskRepository,
        private NotificationLogRepository $notificationLogRepository,
        private ContractAcceptanceRepository $contractAcceptanceRepository,
        private MessageTemplateRepository $messageTemplateRepository,
        private EvotrixService $evotrixService,
        private MkAuthTicketService $mkAuthTicketService,
        private EmailService $emailService
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->canAccessContracts()) {
            Flash::set('error', 'Seu usuário não possui acesso ao módulo Contratos e Aceites.');
            return Response::redirect('/dashboard');
        }

        $currentTab = $this->normalizeTab((string) ($request->input('tab', $request->query('tab', 'resumo'))));

        if ($currentTab === 'configuracoes' && $request->method() === 'GET') {
            return Response::redirect('/configuracoes?tab=contratos');
        }

        if ($currentTab === 'configuracoes' && $request->method() === 'POST' && $request->input('save_config', '') === '1') {
            if (!$this->canManageContracts()) {
                Flash::set('error', 'Seu usuário não possui permissão para alterar as configurações do módulo.');
                return Response::redirect('/contratos?tab=configuracoes');
            }

            try {
                $this->saveModuleSettings(
                    $this->normalizeCommercialSettingsFromRequest($request),
                    $this->normalizeEmailSettingsFromRequest($request)
                );
                Flash::set('success', 'Configuracoes do modulo atualizadas com sucesso.');
            } catch (\Throwable $exception) {
                Flash::set('error', 'Nao foi possivel salvar as configuracoes agora. Verifique permissões e tente novamente.');
            }

            return Response::redirect('/contratos?tab=configuracoes');
        }

        $state = $this->buildState();

        $html = $this->view->render('contracts/index', [
            'pageTitle' => 'Contratos e Aceites',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveViewUser(),
            'currentTab' => $currentTab,
            'moduleReady' => $state['moduleReady'],
            'moduleMessage' => $state['moduleMessage'],
            'summaryCards' => $state['summaryCards'],
            'recentContracts' => $state['recentContracts'],
            'pendingAcceptances' => $state['pendingAcceptances'],
            'commercialConfig' => $this->config->get('contracts.commercial', []),
            'emailConfig' => $this->config->get('email', []),
            'canManageContracts' => $this->canManageContracts(),
        ]);

        return Response::html($html);
    }

    public function novos(Request $request): Response
    {
        if (!$this->canAccessContracts()) {
            Flash::set('error', 'Seu usuário não possui acesso à lista de contratos.');
            return Response::redirect('/dashboard');
        }

        $state = $this->buildState();
        $filters = $this->normalizeListFilters($request);

        $html = $this->view->render('contracts/novos', [
            'pageTitle' => 'Novos Contratos',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveViewUser(),
            'moduleReady' => $state['moduleReady'],
            'moduleMessage' => $state['moduleMessage'],
            'filters' => $filters,
            'contracts' => $this->loadRecentContracts($filters, 50),
            'canManageContracts' => $this->canManageContracts(),
        ]);

        return Response::html($html);
    }

    public function aceitesPendentes(Request $request): Response
    {
        if (!$this->canAccessContracts()) {
            Flash::set('error', 'Seu usuário não possui acesso aos aceites pendentes.');
            return Response::redirect('/dashboard');
        }

        $state = $this->buildState();
        $filters = $this->normalizeListFilters($request);

        $html = $this->view->render('contracts/aceites_pendentes', [
            'pageTitle' => 'Aceites Pendentes',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveViewUser(),
            'moduleReady' => $state['moduleReady'],
            'moduleMessage' => $state['moduleMessage'],
            'filters' => $filters,
            'acceptances' => $this->loadPendingAcceptances($filters, 50),
            'canManageContracts' => $this->canManageContracts(),
        ]);

        return Response::html($html);
    }

    public function detalhe(Request $request): Response
    {
        if (!$this->canAccessContracts()) {
            Flash::set('error', 'Seu usuário não possui acesso ao detalhe de contratos.');
            return Response::redirect('/dashboard');
        }

        $state = $this->buildState();
        $contractId = (int) $request->query('id', 0);
        $detail = $contractId > 0 ? $this->loadContractDetail($contractId) : null;

        if ($contractId <= 0 || $detail === null) {
            Flash::set('error', 'Nao foi possivel localizar o contrato solicitado.');
            return Response::redirect('/contratos/novos');
        }

        $html = $this->view->render('contracts/detalhe', [
            'pageTitle' => 'Detalhe do Contrato',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveViewUser(),
            'flash' => Flash::get(),
            'moduleReady' => $state['moduleReady'],
            'moduleMessage' => $state['moduleMessage'],
            'detail' => $detail,
            'canManageContracts' => $this->canManageContracts(),
            'canManageFinancial' => $this->canManageFinancial(),
            'canManageSettings' => $this->canManageSettings(),
            'simulatedAcceptanceLink' => $this->buildSimulatedAcceptanceLink($detail),
            'integrationStatus' => $this->buildIntegrationStatus($detail),
        ]);

        return Response::html($html);
    }

    public function concluirFinanceiro(Request $request): Response
    {
        if (!$this->canManageFinancial()) {
            Flash::set('error', 'Seu usuário não possui permissão para concluir pendências financeiras.');
            return Response::redirect('/contratos');
        }

        $contractId = (int) $request->input('contract_id', 0);
        $returnTo = trim((string) $request->input('return_to', '/contratos/detalhe?id=' . $contractId));
        $task = $contractId > 0 ? $this->financialTaskRepository->findByContractId($contractId) : null;

        if (!is_array($task) || !isset($task['id'])) {
            Flash::set('error', 'Nao foi possivel localizar a tarefa financeira.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        $taskId = (int) $task['id'];
        $status = (string) ($task['status'] ?? '');
        if ($status === 'concluido') {
            Flash::set('success', 'A tarefa financeira ja estava concluida.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        $this->financialTaskRepository->updateStatus($taskId, 'concluido');
        $this->recordAudit('contract.financial_task.completed', 'financial_task', $taskId, [
            'contract_id' => $contractId,
            'status' => 'concluido',
        ], $request);

        Flash::set('success', 'Pendencia financeira marcada como concluida.');

        return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
    }

    public function cancelarFinanceiro(Request $request): Response
    {
        if (!$this->canManageFinancial()) {
            Flash::set('error', 'Seu usuário não possui permissão para cancelar pendências financeiras.');
            return Response::redirect('/contratos');
        }

        $contractId = (int) $request->input('contract_id', 0);
        $returnTo = trim((string) $request->input('return_to', '/contratos/detalhe?id=' . $contractId));
        $task = $contractId > 0 ? $this->financialTaskRepository->findByContractId($contractId) : null;

        if (!is_array($task) || !isset($task['id'])) {
            Flash::set('error', 'Nao foi possivel localizar a tarefa financeira.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        $taskId = (int) $task['id'];
        $this->financialTaskRepository->updateStatus($taskId, 'cancelado');
        $this->recordAudit('contract.financial_task.canceled', 'financial_task', $taskId, [
            'contract_id' => $contractId,
            'status' => 'cancelado',
        ], $request);

        Flash::set('success', 'Pendencia financeira cancelada com registro de auditoria.');

        return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
    }

    public function enviarAceiteWhatsapp(Request $request): Response
    {
        if (!$this->canManageContracts()) {
            Flash::set('error', 'Seu usuário não possui permissão para enviar o aceite manualmente.');
            return Response::redirect('/contratos');
        }

        $contractId = (int) $request->input('contract_id', 0);
        $returnTo = trim((string) $request->input('return_to', '/contratos/detalhe?id=' . $contractId));
        $requestId = trim((string) $request->input('send_request_id', ''));
        $sendWhatsapp = $this->normalizeBoolean((string) $request->input('send_whatsapp', '1'));
        $sendEmail = $this->normalizeBoolean((string) $request->input('send_email', '0'));
        $detail = $contractId > 0 ? $this->loadContractDetail($contractId) : null;

        if ($contractId <= 0 || $detail === null) {
            Flash::set('error', 'Nao foi possivel localizar o contrato para envio do aceite.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        $contract = is_array($detail['contract'] ?? null) ? $detail['contract'] : [];
        $acceptance = is_array($detail['acceptance'] ?? null) ? $detail['acceptance'] : [];
        $acceptanceId = (int) ($acceptance['id'] ?? 0);
        $forceResend = $this->normalizeBoolean((string) $request->input('force_resend', '0'));
        $recipientPhone = preg_replace('/\D+/', '', (string) ($acceptance['telefone_enviado'] ?? $contract['telefone_cliente'] ?? '')) ?? '';
        $emailCandidate = $this->resolveAcceptanceEmailRecipient($contract, '', false);
        $realEmailAvailable = $emailCandidate['has_real_email'];
        $acceptanceStatus = trim((string) ($acceptance['status'] ?? 'criado'));

        if ($acceptanceId <= 0 || $recipientPhone === '') {
            Flash::set('error', 'O contrato ainda nao possui aceite preparado com telefone de envio.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        if (!$sendWhatsapp && !$sendEmail) {
            Flash::set('error', $realEmailAvailable
                ? 'Selecione pelo menos um canal de envio.'
                : 'Sem e-mail real, o WhatsApp precisa ficar marcado para envio.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        if (!$realEmailAvailable && $sendEmail) {
            Flash::set('error', 'Cliente nao informou e-mail real. O envio por e-mail nao esta disponivel para este aceite.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        if (!$realEmailAvailable && !$sendWhatsapp) {
            Flash::set('error', 'Sem e-mail real, o WhatsApp precisa ficar marcado para envio.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        if ($requestId !== '' && $this->isDuplicateManualSend('contract:' . $contractId . ':whatsapp', $requestId)) {
            Flash::set('warning', 'Este clique ja foi processado. Recarregue a pagina para reenviar mesmo assim.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        if ($acceptanceStatus === 'aceito' && !$forceResend) {
            Flash::set('success', 'Este aceite ja foi confirmado pelo cliente. Use reenviar mesmo assim se precisar repetir o envio.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        try {
            $outcomes = [];
            $overallHasError = false;
            $overallHasSuccess = false;
            $overallHasWarning = false;
            $whatsappWasSent = false;

            if ($sendWhatsapp) {
                $messages = $this->buildAcceptanceWhatsappMessages($detail);
                $response = $this->evotrixService->sendMessage($recipientPhone, $messages, $contractId, $acceptanceId, $forceResend);
                $responseStatus = (string) ($response['status'] ?? 'simulado');

                if (empty($response['repeated_attempt']) && $responseStatus !== 'erro') {
                    $this->contractAcceptanceRepository->markSent(
                        $acceptanceId,
                        isset($response['message_id']) ? (string) $response['message_id'] : null,
                        (string) ($response['queued_at'] ?? date('Y-m-d H:i:s'))
                    );
                }

                $outcomes['whatsapp'] = $response;
                $whatsappWasSent = $responseStatus !== 'erro' && empty($response['repeated_attempt']);
                $overallHasSuccess = $overallHasSuccess || $responseStatus !== 'erro';
                $overallHasError = $overallHasError || $responseStatus === 'erro';
                $overallHasWarning = $overallHasWarning || (!empty($response['repeated_attempt']) && !$forceResend);

                $this->recordAudit(
                    !empty($response['repeated_attempt']) ? 'contract.acceptance.whatsapp.duplicate_attempt' : ($responseStatus === 'erro' ? 'contract.acceptance.whatsapp.failed' : 'contract.acceptance.whatsapp.sent'),
                    'contract_acceptance',
                    $acceptanceId,
                    [
                        'contract_id' => $contractId,
                        'recipient' => $recipientPhone,
                        'force_resend' => $forceResend,
                        'request_id' => $requestId,
                        'result' => $response,
                    ],
                    $request
                );
            }

            if ($sendEmail) {
                [$subject, $htmlBody, $textBody] = $this->buildAcceptanceEmailMessage($detail);
                $emailRecipient = $emailCandidate['recipient'];
                $response = $this->emailService->sendAcceptanceEmail(
                    $emailRecipient,
                    $subject,
                    $htmlBody,
                    $textBody,
                    $contractId,
                    $acceptanceId,
                    $forceResend
                );
                $responseStatus = (string) ($response['status'] ?? 'simulado');

                if (!$whatsappWasSent && empty($response['repeated_attempt']) && $responseStatus !== 'erro') {
                    $this->contractAcceptanceRepository->markSent(
                        $acceptanceId,
                        null,
                        (string) ($response['queued_at'] ?? date('Y-m-d H:i:s'))
                    );
                }

                $outcomes['email'] = $response;
                $overallHasSuccess = $overallHasSuccess || $responseStatus !== 'erro';
                $overallHasError = $overallHasError || $responseStatus === 'erro';
                $overallHasWarning = $overallHasWarning || (!empty($response['repeated_attempt']) && !$forceResend);

                $this->recordAudit(
                    !empty($response['repeated_attempt']) ? 'contract.acceptance.email.duplicate_attempt' : ($responseStatus === 'erro' ? 'contract.acceptance.email.failed' : 'contract.acceptance.email.sent'),
                    'contract_acceptance',
                    $acceptanceId,
                    [
                        'contract_id' => $contractId,
                        'recipient' => $emailRecipient,
                        'force_resend' => $forceResend,
                        'request_id' => $requestId,
                        'result' => $response,
                    ],
                    $request
                );
            }

            $summaryLabels = [];
            foreach ($outcomes as $channel => $response) {
                $statusLabel = !empty($response['repeated_attempt']) && !$forceResend
                    ? 'duplicado bloqueado'
                    : ((string) ($response['status'] ?? 'simulado'));
                $label = match ($channel) {
                    'whatsapp' => 'WhatsApp',
                    'email' => 'E-mail',
                    default => ucfirst($channel),
                };
                $summaryLabels[] = $label . ': ' . $statusLabel;
            }

            if ($overallHasError && !$overallHasSuccess) {
                Flash::set('error', implode(' · ', $summaryLabels) ?: 'O envio falhou.');
            } elseif ($overallHasError) {
                Flash::set('warning', implode(' · ', $summaryLabels) ?: 'Envio parcial concluído.');
            } elseif ($overallHasWarning) {
                Flash::set('warning', implode(' · ', $summaryLabels) ?: 'Já existe envio anterior.');
            } else {
                Flash::set('success', implode(' · ', $summaryLabels) . ' com sucesso.');
            }
        } catch (\Throwable $exception) {
            $this->recordAudit('contract.acceptance.whatsapp.failed', 'contract_acceptance', $acceptanceId, [
                'contract_id' => $contractId,
                'recipient' => $recipientPhone,
                'request_id' => $requestId,
                'error' => $exception->getMessage(),
            ], $request);
            Flash::set('error', 'Nao foi possivel enviar o aceite agora: ' . $exception->getMessage());
        }

        return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
    }

    public function enviarAceiteEmail(Request $request): Response
    {
        if (!$this->canManageContracts()) {
            Flash::set('error', 'Seu usuário não possui permissão para enviar o aceite manualmente por e-mail.');
            return Response::redirect('/contratos');
        }

        $contractId = (int) $request->input('contract_id', 0);
        $returnTo = trim((string) $request->input('return_to', '/contratos/detalhe?id=' . $contractId));
        $requestId = trim((string) $request->input('send_request_id', ''));
        $detail = $contractId > 0 ? $this->loadContractDetail($contractId) : null;

        if ($contractId <= 0 || $detail === null) {
            Flash::set('error', 'Nao foi possivel localizar o contrato para envio por e-mail.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        $contract = is_array($detail['contract'] ?? null) ? $detail['contract'] : [];
        $acceptance = is_array($detail['acceptance'] ?? null) ? $detail['acceptance'] : [];
        $acceptanceId = (int) ($acceptance['id'] ?? 0);
        $forceResend = $this->normalizeBoolean((string) $request->input('force_resend', '0'));
        $emailConfig = (array) $this->config->get('email', []);
        $emailRecipientInfo = $this->resolveAcceptanceEmailRecipient($contract, '', false);
        $recipient = $emailRecipientInfo['recipient'];
        $realEmailAvailable = $emailRecipientInfo['has_real_email'];
        $testRecipient = trim((string) ($emailConfig['test_to'] ?? ''));
        $allowOnlyTest = (bool) ($emailConfig['allow_only_test_email'] ?? true);
        $acceptanceStatus = trim((string) ($acceptance['status'] ?? 'criado'));

        if ($acceptanceId <= 0 || ($recipient === '' && !($allowOnlyTest && $testRecipient !== ''))) {
            Flash::set('error', 'Este contrato ainda nao possui aceite preparado com e-mail disponivel para envio.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        if ($recipient === '' || $recipient === 'cliente@ievo.com.br') {
            Flash::set('error', 'Nao é permitido enviar aceite para o e-mail padrão operacional.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        if ($requestId !== '' && $this->isDuplicateManualSend('contract:' . $contractId . ':email', $requestId)) {
            Flash::set('warning', 'Este clique ja foi processado. Recarregue a pagina para reenviar mesmo assim.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        if ($acceptanceStatus === 'aceito' && !$forceResend) {
            Flash::set('success', 'Este aceite ja foi confirmado pelo cliente. Use reenviar mesmo assim se precisar repetir o envio.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        try {
            [$subject, $htmlBody, $textBody] = $this->buildAcceptanceEmailMessage($detail);
            $response = $this->emailService->sendAcceptanceEmail($recipient, $subject, $htmlBody, $textBody, $contractId, $acceptanceId, $forceResend);

            $this->recordAudit(
                !empty($response['repeated_attempt']) ? 'contract.acceptance.email.duplicate_attempt' : 'contract.acceptance.email.sent',
                'contract_acceptance',
                $acceptanceId,
                [
                    'contract_id' => $contractId,
                    'recipient' => $recipient !== '' ? $recipient : $testRecipient,
                    'force_resend' => $forceResend,
                    'request_id' => $requestId,
                    'result' => $response,
                ],
                $request
            );

            Flash::set(
                !empty($response['repeated_attempt']) && !$forceResend
                    ? 'warning'
                    : (($response['dry_run'] ?? true) ? 'info' : 'success'),
                !empty($response['repeated_attempt']) && !$forceResend
                    ? 'Já existe envio anterior. Use reenviar mesmo assim.'
                    : 'Aceite por e-mail ' . (($response['dry_run'] ?? true) ? 'simulado' : 'enviado') . ' com sucesso. O registro foi gravado no histórico do contrato.'
            );
        } catch (\Throwable $exception) {
            $this->recordAudit('contract.acceptance.email.failed', 'contract_acceptance', $acceptanceId, [
                'contract_id' => $contractId,
                'recipient' => $recipient !== '' ? $recipient : $testRecipient,
                'error' => $exception->getMessage(),
            ], $request);
            Flash::set('error', 'Nao foi possivel enviar o aceite por e-mail agora: ' . $exception->getMessage());
        }

        return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
    }

    public function abrirChamadoFinanceiro(Request $request): Response
    {
        if (!$this->canManageFinancial()) {
            Flash::set('error', 'Seu usuário não possui permissão para abrir o chamado financeiro manual.');
            return Response::redirect('/contratos');
        }

        $contractId = (int) $request->input('contract_id', 0);
        $returnTo = trim((string) $request->input('return_to', '/contratos/detalhe?id=' . $contractId));
        $requestId = trim((string) $request->input('send_request_id', ''));
        $detail = $contractId > 0 ? $this->loadContractDetail($contractId) : null;

        if ($contractId <= 0 || $detail === null) {
            Flash::set('error', 'Nao foi possivel localizar o contrato financeiro solicitado.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        $contract = is_array($detail['contract'] ?? null) ? $detail['contract'] : [];
        $financialTask = is_array($detail['financialTask'] ?? null) ? $detail['financialTask'] : [];
        $taskId = (int) ($financialTask['id'] ?? 0);
        $taskStatus = trim((string) ($financialTask['status'] ?? ''));
        $forceResend = $this->normalizeBoolean((string) $request->input('force_resend', '0'));
        $lastTicketAttempt = $this->latestFinancialTicketAttempt($detail);
        $lastTicketWasReal = false;

        if (is_array($lastTicketAttempt)) {
            $decodedContext = json_decode((string) ($lastTicketAttempt['context_json'] ?? ''), true);
            if (is_array($decodedContext) && is_array($decodedContext['response'] ?? null)) {
                $lastTicketWasReal = empty($decodedContext['response']['dry_run']);
            }
        }

        if ($taskId <= 0) {
            Flash::set('error', 'Nenhuma tarefa financeira vinculada foi encontrada para este contrato.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        if (in_array($taskStatus, ['concluido', 'cancelado'], true)) {
            Flash::set('error', 'A tarefa financeira atual nao permite abrir novo chamado manual.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        if ($requestId !== '' && $this->isDuplicateManualSend('contract:' . $contractId . ':mkauth_ticket', $requestId)) {
            Flash::set('warning', 'Este clique ja foi processado. Recarregue a pagina para reenviar mesmo assim.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        if (!$forceResend && $lastTicketWasReal) {
            $this->financialTaskRepository->appendSystemNote(
                $taskId,
                '[' . date('Y-m-d H:i:s') . '] Nova tentativa ignorada: ja existe chamado/manual registrado anteriormente.'
            );
            $this->recordAudit('contract.financial_task.ticket.duplicate_attempt', 'financial_task', $taskId, [
                'contract_id' => $contractId,
                'force_resend' => $forceResend,
            ], $request);
            Flash::set('error', 'Já existe envio anterior. Use reenviar mesmo assim.');

            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        try {
            $response = $this->mkAuthTicketService->openFinancialTicket(
                $this->buildFinancialTicketPayload($detail)
            );

            $responseSummary = sprintf(
                'HTTP %s · %sms%s',
                (string) ($response['http_status'] ?? '-'),
                (string) ($response['duration_ms'] ?? 0),
                $this->resolveTicketId($response) !== null ? ' · ID ' . $this->resolveTicketId($response) : ''
            );
            $note = sprintf(
                '[%s] Chamado financeiro %s no MkAuth. Endpoint: %s. %s',
                date('Y-m-d H:i:s'),
                (($response['dry_run'] ?? true) ? 'simulado' : 'aberto'),
                (string) ($response['endpoint'] ?? '/api/chamado/inserir'),
                $responseSummary
            );

            $this->financialTaskRepository->appendSystemNote($taskId, $note, 'em_andamento');
            $this->recordAudit('contract.financial_task.ticket.created', 'financial_task', $taskId, [
                'contract_id' => $contractId,
                'force_resend' => $forceResend,
                'request_id' => $requestId,
                'response' => $response,
            ], $request);

            if (!empty($response['message_fallback_used']) && !empty($response['sis_msg_id'])) {
                $this->recordAudit('contract.financial_task.ticket.message_inserted', 'financial_task', $taskId, [
                    'contract_id' => $contractId,
                    'force_resend' => $forceResend,
                    'request_id' => $requestId,
                    'ticket_id' => $this->resolveTicketId($response),
                    'sis_msg_id' => (int) $response['sis_msg_id'],
                    'fallback_status' => (string) ($response['message_fallback_status'] ?? ''),
                ], $request);
            } elseif (($response['message_fallback_status'] ?? '') === 'failed') {
                $this->recordAudit('contract.financial_task.ticket.message_failed', 'financial_task', $taskId, [
                    'contract_id' => $contractId,
                    'force_resend' => $forceResend,
                    'request_id' => $requestId,
                    'ticket_id' => $this->resolveTicketId($response),
                    'error' => (string) ($response['message_fallback_error'] ?? ''),
                ], $request);
            }

            Flash::set(
                ($response['dry_run'] ?? true) ? 'info' : 'success',
                (($response['dry_run'] ?? true) ? 'Chamado simulado com sucesso. ' : 'Chamado aberto com sucesso no MkAuth. ')
                . 'A tarefa financeira foi atualizada localmente. ' . $responseSummary . '.'
            );
        } catch (\Throwable $exception) {
            $this->financialTaskRepository->appendSystemNote(
                $taskId,
                '[' . date('Y-m-d H:i:s') . '] Falha ao abrir chamado financeiro: ' . $exception->getMessage()
            );
            $this->recordAudit('contract.financial_task.ticket.failed', 'financial_task', $taskId, [
                'contract_id' => $contractId,
                'error' => $exception->getMessage(),
                'request_id' => $requestId,
            ], $request);
            Flash::set('error', 'Nao foi possivel abrir o chamado financeiro agora: ' . $exception->getMessage());
        }

        return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
    }

    private function resolveTicketId(array $response): ?string
    {
        foreach (['ticket_id', 'chamado', 'chamado_id'] as $key) {
            $value = $response[$key] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function buildState(): array
    {
        $missingTables = array_values(array_filter(
            self::REQUIRED_TABLES,
            fn (string $table): bool => !$this->tableExists($table)
        ));

        $moduleReady = $missingTables === [];

        if (!$moduleReady) {
            return [
                'moduleReady' => false,
                'moduleMessage' => 'O modulo Contratos e Aceites ainda aguarda as tabelas da migration 002. A interface esta pronta para quando a base estiver disponivel.',
                'summaryCards' => $this->emptySummaryCards(),
                'recentContracts' => [],
                'pendingAcceptances' => [],
                'missingTables' => $missingTables,
            ];
        }

        return [
            'moduleReady' => true,
            'moduleMessage' => '',
            'summaryCards' => [
                [
                    'label' => 'Contratos cadastrados',
                    'value' => (string) $this->countRows('client_contracts'),
                    'hint' => 'Base de contratos local do ISP Auxiliar.',
                ],
                [
                    'label' => 'Aceites pendentes',
                    'value' => (string) $this->countPendingAcceptances(),
                    'hint' => 'Aceites aguardando confirmação do cliente.',
                ],
                [
                    'label' => 'Aceites concluídos',
                    'value' => (string) $this->countRows('contract_acceptances', 'status = "aceito"'),
                    'hint' => 'Termos já aceitos com evidência registrada.',
                ],
                [
                    'label' => 'Pendências financeiras',
                    'value' => (string) $this->countRows('financial_tasks', 'status IN ("aberto", "em_andamento")'),
                    'hint' => 'Chamados financeiros ainda abertos.',
                ],
            ],
            'recentContracts' => $this->loadRecentContracts(),
            'pendingAcceptances' => $this->loadPendingAcceptances(),
        ];
    }

    private function loadRecentContracts(array $filters = [], int $limit = 8): array
    {
        if (!$this->tablesReady(['client_contracts'])) {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $filters = $this->normalizeListFiltersFromArray($filters);
        $conditions = ['1 = 1'];
        $params = [];

        if ($filters['financeiro'] !== '') {
            $conditions[] = 'c.status_financeiro = :financeiro';
            $params['financeiro'] = $filters['financeiro'];
        }

        if ($filters['adesao'] !== '') {
            $conditions[] = 'c.tipo_adesao = :adesao';
            $params['adesao'] = $filters['adesao'];
        }

        if ($filters['aceite'] !== '') {
            $conditions[] = '(
                SELECT ca.status
                FROM contract_acceptances ca
                WHERE ca.contract_id = c.id
                ORDER BY ca.updated_at DESC, ca.id DESC
                LIMIT 1
            ) = :aceite';
            $params['aceite'] = $filters['aceite'];
        }

        try {
            return $this->database->fetchAll(
                'SELECT
                    c.id,
                    c.client_id,
                    c.mkauth_login,
                    c.nome_cliente,
                    c.telefone_cliente,
                    c.tipo_adesao,
                    c.valor_adesao,
                    c.parcelas_adesao,
                    c.valor_parcela_adesao,
                    c.vencimento_primeira_parcela,
                    c.fidelidade_meses,
                    c.beneficio_valor,
                    c.multa_total,
                    c.tipo_aceite,
                    c.observacao_adesao,
                    c.status_financeiro,
                    c.created_at,
                    c.updated_at,
                    (
                        SELECT ca.id
                        FROM contract_acceptances ca
                        WHERE ca.contract_id = c.id
                        ORDER BY ca.updated_at DESC, ca.id DESC
                        LIMIT 1
                    ) AS acceptance_id,
                    (
                        SELECT ca.status
                        FROM contract_acceptances ca
                        WHERE ca.contract_id = c.id
                        ORDER BY ca.updated_at DESC, ca.id DESC
                        LIMIT 1
                    ) AS acceptance_status,
                    (
                        SELECT ca.token_expires_at
                        FROM contract_acceptances ca
                        WHERE ca.contract_id = c.id
                        ORDER BY ca.updated_at DESC, ca.id DESC
                        LIMIT 1
                    ) AS acceptance_token_expires_at,
                    (
                        SELECT ft.id
                        FROM financial_tasks ft
                        WHERE ft.contract_id = c.id
                        ORDER BY ft.updated_at DESC, ft.id DESC
                        LIMIT 1
                    ) AS financial_task_id,
                    (
                        SELECT ft.status
                        FROM financial_tasks ft
                        WHERE ft.contract_id = c.id
                        ORDER BY ft.updated_at DESC, ft.id DESC
                        LIMIT 1
                    ) AS financial_task_status,
                    (
                        SELECT COUNT(*)
                        FROM notification_logs n
                        WHERE n.contract_id = c.id
                    ) AS notification_logs_count
                 FROM client_contracts c
                 WHERE ' . implode(' AND ', $conditions) . '
                 ORDER BY c.updated_at DESC, c.id DESC
                 LIMIT ' . (int) $limit
                ,
                $params
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private function loadPendingAcceptances(array $filters = [], int $limit = 8): array
    {
        if (!$this->tablesReady(['client_contracts', 'contract_acceptances'])) {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $filters = $this->normalizeListFiltersFromArray($filters);
        $conditions = ['a.status IN ("criado", "enviado", "expirado")'];
        $params = [];

        if ($filters['financeiro'] !== '') {
            $conditions[] = 'c.status_financeiro = :financeiro';
            $params['financeiro'] = $filters['financeiro'];
        }

        if ($filters['adesao'] !== '') {
            $conditions[] = 'c.tipo_adesao = :adesao';
            $params['adesao'] = $filters['adesao'];
        }

        if ($filters['aceite'] !== '') {
            $conditions[] = 'a.status = :aceite';
            $params['aceite'] = $filters['aceite'];
        }

        try {
            return $this->database->fetchAll(
                'SELECT
                    a.id AS acceptance_id,
                    a.contract_id,
                    a.token_hash,
                    a.token_expires_at,
                    a.status AS acceptance_status,
                    a.telefone_enviado,
                    a.whatsapp_message_id,
                    a.sent_at,
                    a.accepted_at,
                    a.ip_address,
                    a.user_agent,
                    a.termo_versao,
                    a.termo_hash,
                    a.created_at,
                    a.updated_at,
                    c.client_id,
                    c.nome_cliente,
                    c.mkauth_login,
                    c.tipo_adesao,
                    c.status_financeiro,
                    c.valor_adesao,
                    c.parcelas_adesao,
                    c.valor_parcela_adesao,
                    c.fidelidade_meses,
                    c.beneficio_valor,
                    c.multa_total,
                    c.tipo_aceite,
                    (
                        SELECT ft.id
                        FROM financial_tasks ft
                        WHERE ft.contract_id = c.id
                        ORDER BY ft.updated_at DESC, ft.id DESC
                        LIMIT 1
                    ) AS financial_task_id,
                    (
                        SELECT ft.status
                        FROM financial_tasks ft
                        WHERE ft.contract_id = c.id
                        ORDER BY ft.updated_at DESC, ft.id DESC
                        LIMIT 1
                    ) AS financial_task_status,
                    (
                        SELECT COUNT(*)
                        FROM notification_logs n
                        WHERE n.contract_id = c.id OR n.acceptance_id = a.id
                    ) AS notification_logs_count
                 FROM contract_acceptances a
                 INNER JOIN client_contracts c ON c.id = a.contract_id
                 WHERE ' . implode(' AND ', $conditions) . '
                 ORDER BY a.updated_at DESC, a.id DESC
                 LIMIT ' . (int) $limit
                ,
                $params
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private function loadContractDetail(int $contractId): ?array
    {
        if ($contractId <= 0 || !$this->tablesReady(['client_contracts', 'contract_acceptances', 'financial_tasks', 'notification_logs'])) {
            return null;
        }

        try {
            $contract = $this->database->fetchOne(
                'SELECT * FROM client_contracts WHERE id = :id LIMIT 1',
                ['id' => $contractId]
            );
        } catch (\Throwable) {
            $contract = null;
        }

        if (!is_array($contract)) {
            return null;
        }

        $contract = $this->hydrateContractCommunicationData($contract);

        $acceptance = null;
        $financialTask = null;

        try {
            $acceptance = $this->database->fetchOne(
                'SELECT * FROM contract_acceptances WHERE contract_id = :contract_id ORDER BY updated_at DESC, id DESC LIMIT 1',
                ['contract_id' => $contractId]
            );
        } catch (\Throwable) {
            $acceptance = null;
        }

        try {
            $financialTask = $this->financialTaskRepository->findByContractId($contractId);
        } catch (\Throwable) {
            $financialTask = null;
        }

        $acceptanceId = is_array($acceptance) && isset($acceptance['id']) ? (int) $acceptance['id'] : null;
        $financialTaskId = is_array($financialTask) && isset($financialTask['id']) ? (int) $financialTask['id'] : null;

        try {
            $notificationLogs = $this->notificationLogRepository->listByContractId($contractId, 100);
            if ($acceptanceId !== null) {
                $acceptanceLogs = $this->notificationLogRepository->listByAcceptanceId($acceptanceId, 100);
                $indexedLogs = [];

                foreach (array_merge($notificationLogs, $acceptanceLogs) as $log) {
                    if (!is_array($log) || !isset($log['id'])) {
                        continue;
                    }

                    $indexedLogs[(int) $log['id']] = $log;
                }

                $notificationLogs = array_values($indexedLogs);
                usort(
                    $notificationLogs,
                    static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''))
                );
            }
        } catch (\Throwable) {
            $notificationLogs = [];
        }

        try {
            $auditLogs = $this->localRepository->auditLogsForContract(
                $contractId,
                $acceptanceId,
                $financialTaskId,
                isset($contract['client_id']) ? (int) $contract['client_id'] : null,
                100
            );
        } catch (\Throwable) {
            $auditLogs = [];
        }

        $checkpoint = null;
        $contactCorrections = [];
        try {
            $checkpoint = $this->localRepository->findLatestInstallationCheckpointByLogin((string) ($contract['mkauth_login'] ?? ''));
        } catch (\Throwable) {
            $checkpoint = null;
        }

        if (is_array($checkpoint) && !empty($checkpoint['payload_json'])) {
            $checkpointPayload = json_decode((string) $checkpoint['payload_json'], true);
            if (is_array($checkpointPayload)) {
                $contactCorrections = array_values(array_filter(
                    is_array($checkpointPayload['contact_corrections'] ?? null) ? $checkpointPayload['contact_corrections'] : [],
                    static fn (mixed $item): bool => is_array($item)
                ));
            }
        }

        return [
            'contract' => $contract,
            'acceptance' => $acceptance,
            'financialTask' => $financialTask,
            'notificationLogs' => $notificationLogs,
            'auditLogs' => $auditLogs,
            'checkpoint' => $checkpoint,
            'contactCorrections' => $contactCorrections,
        ];
    }

    private function hydrateContractCommunicationData(array $contract): array
    {
        $login = trim((string) ($contract['mkauth_login'] ?? ''));

        if ($login === '' || !$this->tableExists('installation_checkpoints')) {
            return $contract;
        }

        try {
            $checkpoint = $this->database->fetchOne(
                'SELECT payload_json
                 FROM installation_checkpoints
                 WHERE mkauth_login = :login
                 ORDER BY updated_at DESC, id DESC
                 LIMIT 1',
                ['login' => $login]
            );
        } catch (\Throwable) {
            $checkpoint = null;
        }

        if (!is_array($checkpoint) || empty($checkpoint['payload_json'])) {
            return $contract;
        }

        $payload = json_decode((string) $checkpoint['payload_json'], true);
        $payload = is_array($payload) ? $payload : [];
        $formData = is_array($payload['form_data'] ?? null) ? $payload['form_data'] : [];
        $emailContext = $this->resolveEmailContext(array_merge($payload, $formData));
        $phoneCurrent = preg_replace('/\D+/', '', (string) ($formData['telefone_cliente'] ?? $formData['celular'] ?? $contract['telefone_cliente'] ?? '')) ?? '';
        $phoneOriginal = preg_replace('/\D+/', '', (string) ($formData['telefone_original'] ?? $contract['telefone_cliente'] ?? $phoneCurrent)) ?? '';

        if ($phoneCurrent === '' && $phoneOriginal !== '') {
            $phoneCurrent = $phoneOriginal;
        }

        if ($phoneOriginal === '' && $phoneCurrent !== '') {
            $phoneOriginal = $phoneCurrent;
        }

        if (!isset($contract['email']) || trim((string) $contract['email']) === '') {
            $contract['email'] = trim((string) ($formData['email'] ?? ''));
        }

        if (!isset($contract['email_cliente']) || trim((string) $contract['email_cliente']) === '') {
            $contract['email_cliente'] = (string) ($emailContext['email_cliente'] ?? 'cliente@ievo.com.br');
        }

        if (!isset($contract['email_original']) || trim((string) $contract['email_original']) === '') {
            $contract['email_original'] = (string) ($emailContext['email_original'] ?? '');
        }

        if ($phoneCurrent !== '') {
            $contract['telefone_cliente'] = $phoneCurrent;
        }

        if ($phoneOriginal !== '') {
            $contract['telefone_original'] = $phoneOriginal;
        }

        $contract['has_real_email'] = (bool) ($emailContext['has_real_email'] ?? false);
        $contract['email_cliente'] = (string) ($emailContext['email_cliente'] ?? '');

        return $contract;
    }

    private function normalizeTab(string $tab): string
    {
        $tab = strtolower(trim($tab));

        return in_array($tab, ['resumo', 'novos', 'aceites-pendentes', 'configuracoes'], true) ? $tab : 'resumo';
    }

    private function normalizeCommercialSettingsFromRequest(Request $request): array
    {
        $commercial = $this->config->get('contracts.commercial', []);

        return [
            'valor_adesao_padrao' => $this->normalizeMoney((string) $request->input('valor_adesao_padrao', (string) ($commercial['valor_adesao_padrao'] ?? 0))),
            'valor_adesao_promocional' => $this->normalizeMoney((string) $request->input('valor_adesao_promocional', (string) ($commercial['valor_adesao_promocional'] ?? 0))),
            'percentual_desconto_promocional' => $this->normalizeMoney((string) $request->input('percentual_desconto_promocional', (string) ($commercial['percentual_desconto_promocional'] ?? 0))),
            'parcelas_maximas_adesao' => max(1, (int) $request->input('parcelas_maximas_adesao', (string) ($commercial['parcelas_maximas_adesao'] ?? 3))),
            'fidelidade_meses_padrao' => max(1, (int) $request->input('fidelidade_meses_padrao', (string) ($commercial['fidelidade_meses_padrao'] ?? 12))),
            'validade_link_aceite_horas' => max(1, (int) $request->input('validade_link_aceite_horas', (string) ($commercial['validade_link_aceite_horas'] ?? 48))),
            'exigir_validacao_cpf_aceite' => $this->normalizeBooleanQuery((string) $request->input('exigir_validacao_cpf_aceite', '1')),
            'quantidade_digitos_validacao_cpf' => max(1, (int) $request->input('quantidade_digitos_validacao_cpf', (string) ($commercial['quantidade_digitos_validacao_cpf'] ?? 3))),
            'multa_padrao' => $this->normalizeMoney((string) $request->input('multa_padrao', (string) ($commercial['multa_padrao'] ?? 0))),
        ];
    }

    private function normalizeEmailSettingsFromRequest(Request $request): array
    {
        $email = (array) $this->config->get('email', []);
        $existing = $this->readStoredModuleSettings();
        $existingEmail = is_array($existing['email'] ?? null) ? $existing['email'] : [];
        $storedPassword = trim((string) ($existingEmail['smtp_password'] ?? $email['smtp_password'] ?? ''));
        $incomingPassword = trim((string) $request->input('smtp_password', ''));

        return [
            'enabled' => $this->normalizeBooleanQuery((string) $request->input('email_enabled', !empty($email['enabled']) ? '1' : '0')),
            'dry_run' => $this->normalizeBooleanQuery((string) $request->input('email_dry_run', !empty($email['dry_run']) ? '1' : '0')),
            'allow_only_test_email' => $this->normalizeBooleanQuery((string) $request->input('email_allow_only_test_email', !empty($email['allow_only_test_email']) ? '1' : '0')),
            'test_to' => trim((string) $request->input('email_test_to', (string) ($email['test_to'] ?? ''))),
            'smtp_host' => trim((string) $request->input('smtp_host', (string) ($email['smtp_host'] ?? ''))),
            'smtp_port' => max(1, (int) $request->input('smtp_port', (string) ($email['smtp_port'] ?? 587))),
            'smtp_username' => trim((string) $request->input('smtp_username', (string) ($email['smtp_username'] ?? ''))),
            'smtp_password' => $incomingPassword !== '' ? $incomingPassword : $storedPassword,
            'smtp_encryption' => in_array((string) $request->input('smtp_encryption', (string) ($email['smtp_encryption'] ?? 'tls')), ['tls', 'ssl', 'none'], true)
                ? (string) $request->input('smtp_encryption', (string) ($email['smtp_encryption'] ?? 'tls'))
                : 'tls',
            'smtp_from' => trim((string) $request->input('smtp_from', (string) ($email['smtp_from'] ?? ''))),
            'smtp_from_name' => trim((string) $request->input('smtp_from_name', (string) ($email['smtp_from_name'] ?? 'nossa equipe'))),
        ];
    }

    private function saveModuleSettings(array $commercial, array $email): void
    {
        $path = $this->contractSettingsPath();
        $directory = dirname($path);

        if (!is_dir($directory)) {
            if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException('Nao foi possivel criar o diretorio de configuracoes.');
            }
        }

        if (!is_writable($directory)) {
            throw new \RuntimeException('Diretorio de configuracoes sem permissão de escrita.');
        }

        $written = file_put_contents(
            $path,
            json_encode([
                'commercial' => $commercial,
                'email' => $email,
                'saved_at' => date('Y-m-d H:i:s'),
                'saved_by' => (string) ($this->resolveUser()['login'] ?? ''),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''
        );

        if ($written === false) {
            throw new \RuntimeException('Nao foi possivel escrever o arquivo de configuracoes.');
        }
    }

    private function readStoredModuleSettings(): array
    {
        $path = $this->contractSettingsPath();

        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function contractSettingsPath(): string
    {
        return $this->projectRootPath() . '/storage/contracts/config.json';
    }

    private function projectRootPath(): string
    {
        return dirname(__DIR__, 2);
    }

    private function normalizeBooleanQuery(string $value): bool
    {
        $value = strtolower(trim($value));

        return in_array($value, ['1', 'true', 'sim', 'on', 'yes'], true);
    }

    private function normalizeBoolean(string $value): bool
    {
        return $this->normalizeBooleanQuery($value);
    }

    private function normalizeMoney(string $value): float
    {
        $value = trim($value);

        if ($value === '') {
            return 0.0;
        }

        $normalized = preg_replace('/[^\d,.\-]/', '', $value) ?? '';
        $normalized = preg_replace('/\.(?=\d{3}(?:\D|$))/', '', $normalized) ?? '';
        $normalized = str_replace(',', '.', $normalized);

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    private function countRows(string $table, string $where = '1 = 1'): int
    {
        if (!$this->tablesReady([$table])) {
            return 0;
        }

        try {
            $row = $this->database->fetchOne('SELECT COUNT(*) AS total FROM ' . $table . ' WHERE ' . $where);

            return (int) ($row['total'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function countPendingAcceptances(): int
    {
        if (!$this->tablesReady(['contract_acceptances'])) {
            return 0;
        }

        try {
            $row = $this->database->fetchOne(
                'SELECT COUNT(*) AS total FROM contract_acceptances WHERE status IN ("criado", "enviado", "expirado")'
            );

            return (int) ($row['total'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function tablesReady(array $tables): bool
    {
        foreach ($tables as $table) {
            if (!$this->tableExists($table)) {
                return false;
            }
        }

        return true;
    }

    private function tableExists(string $table): bool
    {
        try {
            $row = $this->database->fetchOne(
                'SELECT 1 AS found FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                 AND table_name = :table
                 LIMIT 1',
                ['table' => $table]
            );

            return is_array($row);
        } catch (\Throwable) {
            return false;
        }
    }

    private function emptySummaryCards(): array
    {
        return [
            [
                'label' => 'Contratos cadastrados',
                'value' => '0',
                'hint' => 'Aguarda migration 002.',
            ],
            [
                'label' => 'Aceites pendentes',
                'value' => '0',
                'hint' => 'Aguarda migration 002.',
            ],
            [
                'label' => 'Aceites concluídos',
                'value' => '0',
                'hint' => 'Aguarda migration 002.',
            ],
            [
                'label' => 'Pendências financeiras',
                'value' => '0',
                'hint' => 'Aguarda migration 002.',
            ],
        ];
    }

    private function normalizeListFilters(Request|array $request): array
    {
        if ($request instanceof Request) {
            $raw = [
                'financeiro' => trim((string) $request->query('financeiro', '')),
                'aceite' => trim((string) $request->query('aceite', '')),
                'adesao' => trim((string) $request->query('adesao', '')),
            ];
        } else {
            $raw = [
                'financeiro' => trim((string) ($request['financeiro'] ?? '')),
                'aceite' => trim((string) ($request['aceite'] ?? '')),
                'adesao' => trim((string) ($request['adesao'] ?? '')),
            ];
        }

        return $this->normalizeListFiltersFromArray($raw);
    }

    private function normalizeListFiltersFromArray(array $filters): array
    {
        $financeiro = $this->normalizeFilterOption((string) ($filters['financeiro'] ?? ''), [
            '',
            'pendente_lancamento',
            'lancado',
            'dispensado',
        ]);
        $aceite = $this->normalizeFilterOption((string) ($filters['aceite'] ?? ''), [
            '',
            'criado',
            'enviado',
            'aceito',
            'expirado',
            'cancelado',
        ]);
        $adesao = $this->normalizeFilterOption((string) ($filters['adesao'] ?? ''), [
            '',
            'cheia',
            'promocional',
            'isenta',
        ]);

        return [
            'financeiro' => $financeiro,
            'aceite' => $aceite,
            'adesao' => $adesao,
        ];
    }

    private function normalizeFilterOption(string $value, array $allowed): string
    {
        $value = trim($value);

        return in_array($value, $allowed, true) ? $value : '';
    }

    private function renderAcceptanceMessage(array $detail): string
    {
        return implode("\n\n", $this->buildAcceptanceWhatsappMessages($detail));
    }

    /**
     * @return string[]
     */
    private function buildAcceptanceWhatsappMessages(array $detail): array
    {
        $contract = is_array($detail['contract'] ?? null) ? $detail['contract'] : [];
        $variables = $this->buildAcceptanceMessageVariables($detail);
        $company = (string) ($variables['{empresa_nome}'] ?? $this->resolveProviderDisplayName());
        $name = (string) ($variables['{cliente_nome}'] ?? ($contract['nome_cliente'] ?? 'Cliente'));
        $tech = (string) ($variables['{tecnico_nome}'] ?? $this->resolveTechnicianDisplayName($contract));
        $link = trim((string) ($variables['{link_aceite}'] ?? $this->buildSimulatedAcceptanceLink($detail)));
        $ttl = (string) ($variables['{validade_horas}'] ?? $this->config->get('contracts.acceptance_ttl_hours', 48));

        $companyOpening = $company === 'nossa equipe' ? 'nossa equipe' : 'a equipe ' . $company;
        $supportLine = $company === 'nossa equipe' ? 'fale com nossa equipe' : 'fale com a equipe ' . $company;
        $messageOne = "Olá, {$name}! 👋\n\nAqui é {$companyOpening}.\nSeu cadastro foi realizado pelo técnico {$tech}.\n\nPara concluir com segurança, confira seus dados, plano contratado, valores e aceite digital no link que enviaremos a seguir.";

        $messageOne = trim($messageOne) . "\n\nEste link é pessoal, seguro e expira em {$ttl} horas.\n\nSe tiver qualquer dúvida, {$supportLine} antes de confirmar.";

        return [
            $messageOne,
            $link,
        ];
    }

    private function buildAcceptanceEmailMessage(array $detail): array
    {
        $contract = is_array($detail['contract'] ?? null) ? $detail['contract'] : [];
        $variables = $this->buildAcceptanceMessageVariables($detail);
        $link = (string) ($variables['{link_aceite}'] ?? $this->buildSimulatedAcceptanceLink($detail));
        $name = (string) ($variables['{cliente_nome}'] ?? ($contract['nome_cliente'] ?? 'Cliente'));
        $company = (string) ($variables['{empresa_nome}'] ?? $this->resolveProviderDisplayName());
        $tech = (string) ($variables['{tecnico_nome}'] ?? $this->resolveTechnicianDisplayName($contract));
        $ttl = (string) ($variables['{validade_horas}'] ?? $this->config->get('contracts.acceptance_ttl_hours', 48));
        $subject = 'Aceite digital do contrato - ' . $company;
        $companyOpening = $company === 'nossa equipe' ? 'nossa equipe' : 'a equipe ' . $company;
        $companyClosing = $company === 'nossa equipe' ? 'Nossa equipe' : 'Equipe ' . $company;
        $supportLine = $company === 'nossa equipe' ? 'fale com nossa equipe' : 'fale com a equipe ' . $company;
        $text = "Olá, {$name}!\n\nAqui é {$companyOpening}.\nSeu cadastro foi realizado pelo técnico {$tech}.\n\nPara concluir com segurança, acesse o link abaixo e confira seus dados, plano contratado, valores e aceite digital:\n\n{$link}\n\nEste link é pessoal, seguro e expira em {$ttl} horas.\n\nSe tiver qualquer dúvida, {$supportLine} antes de confirmar.\n\nAtenciosamente,\n{$companyClosing}";
        $html = '<p>Olá, <strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong>!</p>'
            . '<p>Aqui é ' . htmlspecialchars($companyOpening, ENT_QUOTES, 'UTF-8') . '.</p>'
            . '<p>Seu cadastro foi realizado pelo técnico ' . htmlspecialchars($tech, ENT_QUOTES, 'UTF-8') . '.</p>'
            . '<p>Para concluir com segurança, acesse o link abaixo e confira seus dados, plano contratado, valores e aceite digital:</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</a></p>'
            . '<p>Este link é pessoal, seguro e expira em ' . htmlspecialchars($ttl, ENT_QUOTES, 'UTF-8') . ' horas.</p>'
            . '<p>Se tiver qualquer dúvida, ' . htmlspecialchars($supportLine, ENT_QUOTES, 'UTF-8') . ' antes de confirmar.</p>'
            . '<p><strong>Atenciosamente,<br>' . htmlspecialchars($companyClosing, ENT_QUOTES, 'UTF-8') . '</strong></p>';

        return [$subject, $html, $text];
    }

    private function buildAcceptanceMessageVariables(array $detail): array
    {
        $contract = is_array($detail['contract'] ?? null) ? $detail['contract'] : [];
        $providerName = $this->resolveProviderDisplayName();
        $technicianName = $this->resolveTechnicianDisplayName($contract);

        return [
            '{nome}' => (string) ($contract['nome_cliente'] ?? 'Cliente'),
            '{cliente_nome}' => (string) ($contract['nome_cliente'] ?? 'Cliente'),
            '{empresa_nome}' => $providerName,
            '{tecnico_nome}' => $technicianName,
            '{link_aceite}' => $this->buildSimulatedAcceptanceLink($detail),
            '{validade_horas}' => (string) $this->config->get('contracts.acceptance_ttl_hours', 48),
        ];
    }

    private function resolveProviderDisplayName(): string
    {
        try {
            $provider = $this->localRepository->currentProvider();
            if (is_array($provider) && trim((string) ($provider['name'] ?? '')) !== '') {
                $providerName = trim((string) $provider['name']);
                if (!in_array(strtolower($providerName), ['isp auxiliar', 'provedor', 'nossa equipe'], true)) {
                    return $providerName;
                }
            }
        } catch (\Throwable) {
            // Fallback para o nome da aplicacao abaixo.
        }

        $appName = trim((string) $this->config->get('app.name', ''));
        if ($appName !== '' && !in_array(strtolower($appName), ['isp auxiliar', 'provedor', 'nossa equipe'], true)) {
            return $appName;
        }

        return 'nossa equipe';
    }

    private function resolveTechnicianDisplayName(array $contract): string
    {
        $candidates = [
            (string) ($contract['created_by'] ?? ''),
            (string) ($contract['tecnico_nome'] ?? ''),
            (string) ($contract['tecnico'] ?? ''),
            (string) ($contract['accepted_by'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        $userName = trim((string) ($_SESSION['user']['name'] ?? ''));

        return $userName !== '' ? $userName : 'Equipe técnica';
    }

    private function resolveAcceptanceTemplatePurpose(string $tipoAceite): string
    {
        $tipoAceite = trim($tipoAceite);

        return match ($tipoAceite) {
            'regularizacao_contrato' => 'aceite_regularizacao_contrato',
            default => 'aceite_nova_instalacao',
        };
    }

    private function latestFinancialTicketAttempt(array $detail): ?array
    {
        $auditLogs = is_array($detail['auditLogs'] ?? null) ? $detail['auditLogs'] : [];

        foreach ($auditLogs as $log) {
            if (!is_array($log)) {
                continue;
            }

            if ((string) ($log['action'] ?? '') === 'contract.financial_task.ticket.created') {
                return $log;
            }
        }

        return null;
    }

    private function buildFinancialTicketPayload(array $detail): array
    {
        $contract = is_array($detail['contract'] ?? null) ? $detail['contract'] : [];
        $financialTask = is_array($detail['financialTask'] ?? null) ? $detail['financialTask'] : [];
        $settings = (array) $this->config->get('contracts.mkauth_ticket', []);
        $providerName = $this->resolveProviderDisplayName();
        $tipoAdesao = strtolower(trim((string) ($contract['tipo_adesao'] ?? 'cheia')));
        $observacaoAdesao = trim((string) ($contract['observacao_adesao'] ?? ''));
        $autorizadoPor = trim((string) ($contract['beneficio_concedido_por'] ?? ''));
        $assunto = $tipoAdesao === 'isenta'
            ? 'Financeiro - Conferir Adesao Isenta'
            : 'Financeiro - Lancar Adesao';
        $valorAdesao = $this->normalizeMoney((string) ($contract['valor_adesao'] ?? 0));
        $parcelas = max(1, (int) ($contract['parcelas_adesao'] ?? 1));
        $valorParcela = $parcelas > 0 ? ($valorAdesao / $parcelas) : 0.0;
        $aceiteId = (int) ($contract['acceptance_id'] ?? 0);
        $observacaoNormalizada = strtolower(trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n"], ' ', $observacaoAdesao)) ?? ''));
        $autorizadoNormalizado = strtolower(trim((string) $autorizadoPor));
        $observacaoExtra = $observacaoAdesao !== '' && $observacaoNormalizada !== '' && $observacaoNormalizada !== $autorizadoNormalizado && !str_starts_with($observacaoNormalizada, 'autorizado por:') ? $observacaoAdesao : '';

        $description = implode("\n", array_filter([
            'Solicitação automática de ' . $providerName . '.',
            '',
            'Ação necessária: conferir e lançar manualmente a adesão deste cliente, se proceder.',
            '',
            'Login: ' . (string) ($contract['mkauth_login'] ?? '-'),
            'Cliente: ' . (string) ($contract['nome_cliente'] ?? '-'),
            '',
            'Tipo de adesão: ' . $tipoAdesao,
            'Valor total da adesão: R$ ' . number_format($valorAdesao, 2, ',', '.'),
            'Parcelas: ' . $parcelas . 'x de R$ ' . number_format($valorParcela, 2, ',', '.'),
            'Vencimento da primeira parcela: ' . (string) ($contract['vencimento_primeira_parcela'] ?? '-'),
            '',
            'Autorizado por: ' . ($autorizadoPor !== '' ? $autorizadoPor : $this->extractAuthorizedBy($observacaoAdesao)),
            $observacaoExtra !== '' ? 'Observação da adesão: ' . $observacaoExtra : null,
            'Contrato ID: ' . (string) ($contract['id'] ?? 0),
            'Aceite ID: ' . ($aceiteId > 0 ? (string) $aceiteId : '-'),
            'Tarefa financeira ID: ' . (string) ($financialTask['id'] ?? 0),
        ]));

        return [
            'login' => (string) ($contract['mkauth_login'] ?? ''),
            'nome' => (string) ($contract['nome_cliente'] ?? ''),
            'email' => (string) ($contract['email_cliente'] ?? $contract['email'] ?? $this->config->get('email.smtp_from', '')),
            'telefone' => (string) ($contract['telefone_cliente'] ?? ''),
            'assunto' => $assunto,
            'prioridade' => (string) ($settings['priority'] ?? 'normal'),
            'descricao' => $description,
            'msg' => $description,
            'observacao' => $description,
        ];
    }

    private function buildIntegrationStatus(array $detail): array
    {
        $notificationLogs = is_array($detail['notificationLogs'] ?? null) ? $detail['notificationLogs'] : [];
        $auditLogs = is_array($detail['auditLogs'] ?? null) ? $detail['auditLogs'] : [];

        $evotrixLast = null;
        $emailLast = null;
        foreach ($notificationLogs as $log) {
            if (!is_array($log)) {
                continue;
            }

            if ((string) ($log['provider'] ?? '') === 'evotrix' && $evotrixLast === null) {
                $evotrixLast = $log;
            }

            if ((string) ($log['provider'] ?? '') === 'smtp' && (string) ($log['channel'] ?? '') === 'email' && $emailLast === null) {
                $emailLast = $log;
            }
        }

        $ticketLast = null;
        foreach ($auditLogs as $log) {
            if (!is_array($log) || !str_starts_with((string) ($log['action'] ?? ''), 'contract.financial_task.ticket.')) {
                continue;
            }

            $ticketLast = $log;
            break;
        }

        return [
            'evotrix' => [
                'enabled' => (bool) $this->config->get('evotrix.enabled', false),
                'dry_run' => (bool) $this->config->get('evotrix.dry_run', true),
                'allow_only_test_phone' => (bool) $this->config->get('evotrix.allow_only_test_phone', true),
                'test_phone' => (string) $this->config->get('evotrix.test_phone', ''),
                'timeout_seconds' => (int) $this->config->get('evotrix.timeout_seconds', 15),
                'retry_attempts' => (int) $this->config->get('evotrix.retry_attempts', 1),
                'last' => $evotrixLast,
            ],
            'email' => [
                'enabled' => (bool) $this->config->get('email.enabled', false),
                'dry_run' => (bool) $this->config->get('email.dry_run', true),
                'allow_only_test_email' => (bool) $this->config->get('email.allow_only_test_email', true),
                'test_to' => (string) $this->config->get('email.test_to', ''),
                'last' => $emailLast,
            ],
            'mkauth_ticket' => [
                'enabled' => (bool) $this->config->get('contracts.mkauth_ticket.enabled', false),
                'dry_run' => (bool) $this->config->get('contracts.mkauth_ticket.dry_run', true),
                'auto_create' => (bool) $this->config->get('contracts.mkauth_ticket.auto_create', false),
                'endpoint' => (string) $this->config->get('contracts.mkauth_ticket.endpoint', '/api/chamado/inserir'),
                'timeout_seconds' => (int) $this->config->get('contracts.mkauth_ticket.timeout_seconds', 15),
                'last' => $ticketLast,
            ],
        ];
    }

    private function extractAuthorizedBy(string $observacao): string
    {
        if (preg_match('/Autorizado por:\s*(.+)$/mi', $observacao, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }

        $user = $this->resolveUser();

        return (string) ($user['login'] ?? $user['name'] ?? '-');
    }

    private function resolveManualRecipient(string $override, string $fallback): string
    {
        $override = trim($override);
        if ($override !== '') {
            return $override;
        }

        return trim($fallback);
    }

    /**
     * @return array{recipient:string,has_real_email:bool}
     */
    private function resolveAcceptanceEmailRecipient(array $contract, string $override, bool $allowTemporary): array
    {
        $fallbackEmail = 'cliente@ievo.com.br';
        $emailContext = $this->resolveEmailContext($contract);
        $recipient = strtolower(trim((string) ($emailContext['email_cliente'] ?? '')));
        $hasRealEmail = (bool) ($emailContext['has_real_email'] ?? false);

        if ($recipient === '' || $recipient === $fallbackEmail || !$hasRealEmail) {
            return [
                'recipient' => '',
                'has_real_email' => false,
            ];
        }

        return [
            'recipient' => $recipient,
            'has_real_email' => $hasRealEmail,
        ];
    }

    /**
     * @return array{email_original:string,email_cliente:string,has_real_email:bool}
     */
    private function resolveEmailContext(array $source): array
    {
        $fallbackEmail = 'cliente@ievo.com.br';
        $original = strtolower(trim((string) ($source['email_original'] ?? '')));
        $current = strtolower(trim((string) ($source['email_cliente'] ?? $source['email'] ?? '')));

        if ($current === '' && $original !== '') {
            $current = $original;
        }

        if (($current === '' || $current === $fallbackEmail) && $original !== '' && $original !== $fallbackEmail) {
            $current = $original;
        }

        if ($original === '' && $current !== '' && $current !== $fallbackEmail) {
            $original = $current;
        }

        if ($current === '') {
            $current = $fallbackEmail;
        }

        return [
            'email_original' => $original,
            'email_cliente' => $current,
            'has_real_email' => $current !== '' && $current !== $fallbackEmail,
        ];
    }

    private function isDuplicateManualSend(string $scope, string $requestId): bool
    {
        $requestId = trim($requestId);
        if ($requestId === '') {
            return false;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        if (!isset($_SESSION['manual_send_request_ids']) || !is_array($_SESSION['manual_send_request_ids'])) {
            $_SESSION['manual_send_request_ids'] = [];
        }

        $now = time();
        $locks = $_SESSION['manual_send_request_ids'][$scope] ?? [];
        if (!is_array($locks)) {
            $locks = [];
        }

        $pruned = [];
        foreach ($locks as $storedRequestId => $storedAt) {
            if (!is_string($storedRequestId) || trim($storedRequestId) === '') {
                continue;
            }

            $timestamp = (int) $storedAt;
            if (($now - $timestamp) <= 3600) {
                $pruned[$storedRequestId] = $timestamp;
            }
        }

        if (array_key_exists($requestId, $pruned)) {
            $_SESSION['manual_send_request_ids'][$scope] = $pruned;
            return true;
        }

        $pruned[$requestId] = $now;
        $_SESSION['manual_send_request_ids'][$scope] = $pruned;
        return false;
    }

    private function recordAudit(string $action, string $entityType, ?int $entityId, array $context, Request $request): void
    {
        $user = $this->resolveUser();

        $this->localRepository->log(
            isset($user['id']) ? (int) $user['id'] : null,
            (string) ($user['login'] ?? ''),
            $action,
            $entityType,
            $entityId,
            $context,
            (string) $request->server('REMOTE_ADDR', ''),
            (string) $request->header('User-Agent', '')
        );
    }

    private function canManageContracts(): bool
    {
        $access = $this->resolveAccessProfile();
        return $access['is_manager'] || $access['can_access_contracts'];
    }

    private function canAccessContracts(): bool
    {
        $access = $this->resolveAccessProfile();
        return $access['is_manager'] || $access['can_access_contracts'];
    }

    private function canManageFinancial(): bool
    {
        $access = $this->resolveAccessProfile();
        return $access['is_manager'] || $access['can_manage_financial'];
    }

    private function canManageSettings(): bool
    {
        $access = $this->resolveAccessProfile();
        return $access['can_manage_settings'];
    }

    private function buildSimulatedAcceptanceLink(array $detail): string
    {
        $acceptance = is_array($detail['acceptance'] ?? null) ? $detail['acceptance'] : [];
        $contract = is_array($detail['contract'] ?? null) ? $detail['contract'] : [];
        $tokenFragment = trim((string) ($acceptance['token_hash'] ?? ''));

        if ($tokenFragment === '') {
            $contractId = (int) ($contract['id'] ?? 0);
            $acceptanceId = (int) ($acceptance['id'] ?? 0);
            $tokenFragment = $acceptanceId > 0 ? (string) $acceptanceId : (string) $contractId;
        }

        return Url::absolute('/aceite/' . rawurlencode($tokenFragment));
    }

    private function resolveUser(): array
    {
        $user = $_SESSION['user'] ?? [
            'name' => 'Operador',
            'login' => '',
            'role' => 'Operacao',
            'source' => 'fallback',
        ];

        $user['login'] = $this->localRepository->normalizeLogin((string) ($user['login'] ?? ''));

        return $user;
    }

    private function resolveViewUser(): array
    {
        $user = $this->resolveUser();
        $access = $this->resolveAccessProfile();
        $user['access'] = $access;
        $user['can_manage_settings'] = $access['can_manage_settings'];
        $user['can_access_contracts'] = $access['can_access_contracts'];
        $user['can_manage_financial'] = $access['can_manage_financial'];
        $user['can_manage_users'] = $access['can_manage_users'] ?? false;
        $user['can_manage'] = !empty($user['can_manage']) || $access['is_manager'];

        if ($access['is_admin']) {
            $user['role'] = 'admin';
        } elseif ($access['is_manager']) {
            $user['role'] = 'manager';
        }

        return $user;
    }

    private function resolveAccessProfile(): array
    {
        return $this->localRepository->accessProfileForUser($this->resolveUser());
    }
}
