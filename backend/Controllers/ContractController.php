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
        $detail = $contractId > 0 ? $this->loadContractDetail($contractId) : null;

        if ($contractId <= 0 || $detail === null) {
            Flash::set('error', 'Nao foi possivel localizar o contrato para envio do aceite.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        $contract = is_array($detail['contract'] ?? null) ? $detail['contract'] : [];
        $acceptance = is_array($detail['acceptance'] ?? null) ? $detail['acceptance'] : [];
        $acceptanceId = (int) ($acceptance['id'] ?? 0);
        $recipient = $this->resolveManualRecipient(
            trim((string) $request->input('recipient_phone_override', '')),
            (string) ($acceptance['telefone_enviado'] ?? $contract['telefone_cliente'] ?? '')
        );
        $acceptanceStatus = trim((string) ($acceptance['status'] ?? 'criado'));

        if ($acceptanceId <= 0 || $recipient === '') {
            Flash::set('error', 'O contrato ainda nao possui aceite preparado com telefone de envio.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        if ($acceptanceStatus === 'aceito') {
            Flash::set('success', 'Este aceite ja foi confirmado pelo cliente e nao precisa de novo envio.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        try {
            $message = $this->renderAcceptanceMessage($detail);
            $response = $this->evotrixService->sendMessage($recipient, $message, $contractId, $acceptanceId);

            if (empty($response['repeated_attempt'])) {
                $this->contractAcceptanceRepository->markSent(
                    $acceptanceId,
                    isset($response['message_id']) ? (string) $response['message_id'] : null,
                    (string) ($response['queued_at'] ?? date('Y-m-d H:i:s'))
                );
            }

            $this->recordAudit(
                !empty($response['repeated_attempt']) ? 'contract.acceptance.whatsapp.duplicate_attempt' : 'contract.acceptance.whatsapp.sent',
                'contract_acceptance',
                $acceptanceId,
                [
                'contract_id' => $contractId,
                    'recipient' => $recipient,
                    'result' => $response,
                ],
                $request
            );

            Flash::set(
                'success',
                !empty($response['repeated_attempt'])
                    ? 'Este aceite ja possui um envio/manual registrado anteriormente.'
                    : ((($response['dry_run'] ?? true) ? 'Envio simulado com sucesso. ' : 'Aceite enviado com sucesso. ')
                    . 'O registro foi gravado no historico do contrato.')
            );
        } catch (\Throwable $exception) {
            $this->recordAudit('contract.acceptance.whatsapp.failed', 'contract_acceptance', $acceptanceId, [
                'contract_id' => $contractId,
                'recipient' => $recipient,
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
        $detail = $contractId > 0 ? $this->loadContractDetail($contractId) : null;

        if ($contractId <= 0 || $detail === null) {
            Flash::set('error', 'Nao foi possivel localizar o contrato para envio por e-mail.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        $contract = is_array($detail['contract'] ?? null) ? $detail['contract'] : [];
        $acceptance = is_array($detail['acceptance'] ?? null) ? $detail['acceptance'] : [];
        $acceptanceId = (int) ($acceptance['id'] ?? 0);
        $emailConfig = (array) $this->config->get('email', []);
        $recipient = $this->resolveManualRecipient(
            trim((string) $request->input('recipient_email_override', '')),
            (string) ($contract['email_cliente'] ?? $contract['email'] ?? '')
        );
        $testRecipient = trim((string) ($emailConfig['test_to'] ?? ''));
        $allowOnlyTest = (bool) ($emailConfig['allow_only_test_email'] ?? true);
        $acceptanceStatus = trim((string) ($acceptance['status'] ?? 'criado'));

        if ($acceptanceId <= 0 || ($recipient === '' && !($allowOnlyTest && $testRecipient !== ''))) {
            Flash::set('error', 'Este contrato ainda nao possui aceite preparado com e-mail disponivel para envio.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        if ($acceptanceStatus === 'aceito') {
            Flash::set('success', 'Este aceite ja foi confirmado pelo cliente e nao precisa de novo envio.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        try {
            [$subject, $htmlBody, $textBody] = $this->buildAcceptanceEmailMessage($detail);
            $response = $this->emailService->sendAcceptanceEmail($recipient, $subject, $htmlBody, $textBody, $contractId, $acceptanceId);

            $this->recordAudit(
                !empty($response['repeated_attempt']) ? 'contract.acceptance.email.duplicate_attempt' : 'contract.acceptance.email.sent',
                'contract_acceptance',
                $acceptanceId,
                [
                    'contract_id' => $contractId,
                    'recipient' => $recipient !== '' ? $recipient : $testRecipient,
                    'result' => $response,
                ],
                $request
            );

            Flash::set(
                'success',
                !empty($response['repeated_attempt'])
                    ? 'Este aceite ja possui um envio/manual por e-mail registrado anteriormente.'
                    : ((($response['dry_run'] ?? true) ? 'Envio de e-mail simulado com sucesso. ' : 'Aceite enviado por e-mail com sucesso. ')
                    . 'O registro foi gravado no historico do contrato.')
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
        $detail = $contractId > 0 ? $this->loadContractDetail($contractId) : null;

        if ($contractId <= 0 || $detail === null) {
            Flash::set('error', 'Nao foi possivel localizar o contrato financeiro solicitado.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        $contract = is_array($detail['contract'] ?? null) ? $detail['contract'] : [];
        $financialTask = is_array($detail['financialTask'] ?? null) ? $detail['financialTask'] : [];
        $taskId = (int) ($financialTask['id'] ?? 0);
        $taskStatus = trim((string) ($financialTask['status'] ?? ''));

        if ($taskId <= 0) {
            Flash::set('error', 'Nenhuma tarefa financeira vinculada foi encontrada para este contrato.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        if (in_array($taskStatus, ['concluido', 'cancelado'], true)) {
            Flash::set('error', 'A tarefa financeira atual nao permite abrir novo chamado manual.');
            return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
        }

        if ($this->hasFinancialTicketAttempt($detail)) {
            $this->financialTaskRepository->appendSystemNote(
                $taskId,
                '[' . date('Y-m-d H:i:s') . '] Nova tentativa ignorada: ja existe chamado/manual registrado anteriormente.'
            );
            $this->recordAudit('contract.financial_task.ticket.duplicate_attempt', 'financial_task', $taskId, [
                'contract_id' => $contractId,
            ], $request);
            Flash::set('success', 'Ja existe um chamado financeiro preparado anteriormente para esta tarefa.');

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
                isset($response['ticket_id']) && $response['ticket_id'] !== null ? ' · ID ' . (string) $response['ticket_id'] : ''
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
                'response' => $response,
            ], $request);

            Flash::set(
                'success',
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
            ], $request);
            Flash::set('error', 'Nao foi possivel abrir o chamado financeiro agora: ' . $exception->getMessage());
        }

        return Response::redirect($returnTo !== '' ? $returnTo : '/contratos');
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

        return [
            'contract' => $contract,
            'acceptance' => $acceptance,
            'financialTask' => $financialTask,
            'notificationLogs' => $notificationLogs,
            'auditLogs' => $auditLogs,
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
        $formData = is_array($payload['form_data'] ?? null) ? $payload['form_data'] : [];

        if (!isset($contract['email']) || trim((string) $contract['email']) === '') {
            $contract['email'] = trim((string) ($formData['email'] ?? ''));
        }

        if (!isset($contract['email_cliente']) || trim((string) $contract['email_cliente']) === '') {
            $contract['email_cliente'] = trim((string) ($formData['email'] ?? ''));
        }

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
            'smtp_from_name' => trim((string) $request->input('smtp_from_name', (string) ($email['smtp_from_name'] ?? 'ISP Auxiliar'))),
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
        $contract = is_array($detail['contract'] ?? null) ? $detail['contract'] : [];
        $purpose = $this->resolveAcceptanceTemplatePurpose((string) ($contract['tipo_aceite'] ?? 'nova_instalacao'));
        $template = $this->messageTemplateRepository->findByPurpose(
            $purpose,
            'whatsapp'
        );

        $templateBody = trim((string) ($template['body'] ?? ''));
        if ($templateBody === '') {
            $defaults = (array) $this->config->get('contracts.message_templates.' . $purpose, []);
            $templateBody = (string) ($defaults['body'] ?? '');
        }

        if ($templateBody === '') {
            $templateBody = "Olá, {nome}.\n\nConfira seu contrato no link abaixo:\n\n{link_aceite}";
        }

        $variables = [
            '{nome}' => (string) ($contract['nome_cliente'] ?? 'Cliente'),
            '{link_aceite}' => $this->buildSimulatedAcceptanceLink($detail),
            '{validade_horas}' => (string) $this->config->get('contracts.acceptance_ttl_hours', 48),
        ];

        return strtr($templateBody, $variables);
    }

    private function buildAcceptanceEmailMessage(array $detail): array
    {
        $contract = is_array($detail['contract'] ?? null) ? $detail['contract'] : [];
        $link = $this->buildSimulatedAcceptanceLink($detail);
        $name = (string) ($contract['nome_cliente'] ?? 'Cliente');
        $ttl = (string) $this->config->get('contracts.acceptance_ttl_hours', 48);
        $subject = 'Aceite digital do contrato - ' . $name;
        $text = "Olá, {$name}.\n\nSeu cadastro foi realizado.\n\nConfira seus dados, plano, valores e contrato no link abaixo:\n\n{$link}\n\nEste link é pessoal e expira em {$ttl} horas.";
        $html = '<p>Olá, <strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
            . '<p>Seu cadastro foi realizado.</p>'
            . '<p>Confira seus dados, plano, valores e contrato no link abaixo:</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</a></p>'
            . '<p>Este link é pessoal e expira em ' . htmlspecialchars($ttl, ENT_QUOTES, 'UTF-8') . ' horas.</p>';

        return [$subject, $html, $text];
    }

    private function resolveAcceptanceTemplatePurpose(string $tipoAceite): string
    {
        $tipoAceite = trim($tipoAceite);

        return match ($tipoAceite) {
            'regularizacao_contrato' => 'aceite_regularizacao_contrato',
            default => 'aceite_nova_instalacao',
        };
    }

    private function hasFinancialTicketAttempt(array $detail): bool
    {
        $auditLogs = is_array($detail['auditLogs'] ?? null) ? $detail['auditLogs'] : [];

        foreach ($auditLogs as $log) {
            if (!is_array($log)) {
                continue;
            }

            if ((string) ($log['action'] ?? '') === 'contract.financial_task.ticket.created') {
                return true;
            }
        }

        return false;
    }

    private function buildFinancialTicketPayload(array $detail): array
    {
        $contract = is_array($detail['contract'] ?? null) ? $detail['contract'] : [];
        $financialTask = is_array($detail['financialTask'] ?? null) ? $detail['financialTask'] : [];
        $settings = (array) $this->config->get('contracts.mkauth_ticket', []);
        $tipoAdesao = strtolower(trim((string) ($contract['tipo_adesao'] ?? 'cheia')));
        $observacaoAdesao = trim((string) ($contract['observacao_adesao'] ?? ''));
        $autorizadoPor = trim((string) ($contract['beneficio_concedido_por'] ?? ''));
        $assunto = $tipoAdesao === 'isenta'
            ? 'Financeiro outros'
            : (string) ($settings['subject'] ?? 'Financeiro - Boleto / Carne');
        $instruction = $tipoAdesao === 'isenta'
            ? 'Instrução: validar internamente se a adesão isenta procede. Não gerar cobrança automática.'
            : 'Instrução: lançar ou conferir manualmente a adesão no financeiro. Não gerar cobrança automática pelo ISP Auxiliar.';

        $description = implode("\n", array_filter([
            'Cliente: ' . (string) ($contract['nome_cliente'] ?? '-'),
            'Login: ' . (string) ($contract['mkauth_login'] ?? '-'),
            'Telefone: ' . (string) ($contract['telefone_cliente'] ?? '-'),
            'Tipo adesao: ' . (string) ($contract['tipo_adesao'] ?? '-'),
            'Valor adesao: R$ ' . number_format((float) ($contract['valor_adesao'] ?? 0), 2, ',', '.'),
            'Parcelas: ' . (string) ($contract['parcelas_adesao'] ?? '1'),
            'Valor da parcela: R$ ' . number_format((float) ($contract['valor_parcela_adesao'] ?? 0), 2, ',', '.'),
            'Vencimento da primeira parcela: ' . (string) ($contract['vencimento_primeira_parcela'] ?? '-'),
            'Autorizado por: ' . ($autorizadoPor !== '' ? $autorizadoPor : $this->extractAuthorizedBy($observacaoAdesao)),
            'Observacoes da adesao: ' . ($observacaoAdesao !== '' ? $observacaoAdesao : '-'),
            'Contrato ID: ' . (string) ($contract['id'] ?? 0),
            'Tarefa financeira ID: ' . (string) ($financialTask['id'] ?? 0),
            $instruction,
        ]));

        return [
            'login' => (string) ($contract['mkauth_login'] ?? ''),
            'nome' => (string) ($contract['nome_cliente'] ?? ''),
            'telefone' => (string) ($contract['telefone_cliente'] ?? ''),
            'assunto' => $assunto,
            'prioridade' => (string) ($settings['priority'] ?? 'normal'),
            'descricao' => $description,
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

        return Url::to('/aceite/' . rawurlencode($tokenFragment));
    }

    private function resolveUser(): array
    {
        return $_SESSION['user'] ?? [
            'name' => 'Operador',
            'login' => '',
            'role' => 'Operacao',
            'source' => 'fallback',
        ];
    }

    private function resolveViewUser(): array
    {
        $user = $this->resolveUser();
        $access = $this->resolveAccessProfile();
        $user['can_manage_settings'] = $access['can_manage_settings'];
        $user['can_access_contracts'] = $access['can_access_contracts'];
        $user['can_manage_financial'] = $access['can_manage_financial'];
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
        $user = $this->resolveUser();
        $login = strtolower(trim((string) ($user['login'] ?? '')));
        $role = strtolower(trim((string) ($user['role'] ?? '')));

        $managerLogins = $this->localRepository->providerSettingList('mkauth_manager_logins');
        $singleManager = strtolower(trim($this->localRepository->providerSetting('mkauth_manager_login', '')));
        if ($singleManager !== '') {
            $managerLogins[] = $singleManager;
        }

        $adminLogins = $this->localRepository->providerSettingList('admin_access_logins');
        $settingsLogins = $this->localRepository->providerSettingList('settings_access_logins');
        $contractLogins = $this->localRepository->providerSettingList('contract_access_logins');
        $financialLogins = $this->localRepository->providerSettingList('financial_access_logins');

        $isAdminRole = in_array($role, ['platform_admin', 'admin', 'administrador'], true);
        $isManagerRole = in_array($role, ['manager', 'gestor'], true) || !empty($user['can_manage']);

        $isAdmin = $isAdminRole || ($login !== '' && in_array($login, $adminLogins, true));
        $isManager = $isAdmin || $isManagerRole || ($login !== '' && in_array($login, $managerLogins, true));

        return [
            'is_admin' => $isAdmin,
            'is_manager' => $isManager,
            'can_manage_settings' => $isManager || ($login !== '' && in_array($login, $settingsLogins, true)),
            'can_access_contracts' => $isManager || ($login !== '' && in_array($login, $contractLogins, true)),
            'can_manage_financial' => $isManager || ($login !== '' && in_array($login, $financialLogins, true)),
        ];
    }
}
