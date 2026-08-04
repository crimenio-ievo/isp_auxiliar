<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Url;
use App\Core\View;
use App\Infrastructure\Contracts\ContractAcceptanceRepository;
use App\Infrastructure\Contracts\ContractRepository;
use App\Infrastructure\Contracts\FinancialTaskRepository;
use App\Infrastructure\Contracts\MessageTemplateRepository;
use App\Infrastructure\Contracts\ClientDocumentRepository;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\MkAuth\MkAuthDatabase;
use App\Infrastructure\MkAuth\ClientProvisioner;
use App\Infrastructure\MkAuth\MkAuthTicketService;
use App\Infrastructure\MkAuth\MkAuthWriteGuard;
use App\Infrastructure\MkAuth\TechnologyMapper;
use App\Infrastructure\Notifications\EmailService;
use App\Infrastructure\Notifications\EvotrixService;
use App\Services\Contracts\AcceptanceWorkflowService;
use App\Services\Contracts\AcceptanceEvidenceService;
use App\Services\Processes\OperationalProcessService;
use App\Services\Notifications\NotificationTemplateService;

/**
 * Fluxo de cadastro de novo cliente.
 *
 * Mantem rascunho, evidencias locais e envio final ao MkAuth separados para
 * evitar perda de dados quando houver erro de validacao, upload ou API.
 */
final class ClientController
{
    public function __construct(
        private View $view,
        private Config $config,
        private Database $database,
        private ClientProvisioner $provisioner,
        private MkAuthDatabase $mkauthDatabase,
        private LocalRepository $localRepository,
        private ContractRepository $contractRepository,
        private ContractAcceptanceRepository $contractAcceptanceRepository,
        private FinancialTaskRepository $financialTaskRepository,
        private MessageTemplateRepository $messageTemplateRepository,
        private EmailService $emailService,
        private EvotrixService $evotrixService,
        private MkAuthTicketService $mkAuthTicketService,
        private AcceptanceWorkflowService $acceptanceWorkflowService,
        private OperationalProcessService $operationalProcessService,
        private TechnologyMapper $technologyMapper,
        private AcceptanceEvidenceService $acceptanceEvidenceService,
        private NotificationTemplateService $notificationTemplateService,
        private ClientDocumentRepository $clientDocumentRepository
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->canSearchClients()) {
            Flash::set('error', 'Seu usuário não possui permissão para consultar clientes.');
            return Response::redirect('/dashboard');
        }

        $query = trim((string) $request->query('q', $request->input('q', '')));
        $searchMode = $this->detectClientSearchMode($query);
        $results = [];

        if ($query !== '') {
            try {
                $results = $this->mkauthDatabase->searchClients($query, 50);
            } catch (\Throwable $exception) {
                Flash::set('error', 'Nao foi possivel consultar o MkAuth agora. Tente novamente.');
            }
        }

        try {
            $recentRegistrations = $this->localRepository->recentClientRegistrations(8);
        } catch (\Throwable) {
            $recentRegistrations = [];
        }

        $html = $this->view->render('clients/index', [
            'pageTitle' => 'Clientes',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveViewUser(),
            'flash' => Flash::get(),
            'query' => $query,
            'searchMode' => $searchMode,
            'results' => $this->buildClientHubSearchResults($results),
            'recentRegistrations' => $this->buildRecentClientItems($recentRegistrations),
            'canCreateClient' => $this->canCreateClient(),
            'canSearchClients' => $this->canSearchClients(),
        ]);

        return Response::html($html);
    }

    public function search(Request $request): Response
    {
        if (!$this->canSearchClients()) {
            return Response::json([
                'status' => 'error',
                'message' => 'Seu usuário não possui permissão para consultar clientes.',
                'results' => [],
            ], 403);
        }

        $query = trim((string) $request->query('q', $request->input('q', '')));

        if ($query === '' || mb_strlen($query) < 3) {
            return Response::json([
                'status' => 'success',
                'query' => $query,
                'results' => [],
            ]);
        }

        try {
            $results = $this->mkauthDatabase->searchClients($query, 10);
        } catch (\Throwable $exception) {
            return Response::json([
                'status' => 'success',
                'query' => $query,
                'results' => [],
            ]);
        }

        $payload = [];
        foreach ($this->buildClientHubSearchResults($results) as $item) {
            $statusVisual = is_array($item['status_visual'] ?? null) ? $item['status_visual'] : [];
            $payload[] = [
                'name' => (string) ($item['name'] ?? ''),
                'login' => (string) ($item['login'] ?? ''),
                'document' => (string) ($item['document'] ?? ''),
                'phone' => (string) ($item['phone'] ?? ''),
                'plan' => (string) ($item['plan'] ?? ''),
                'technology' => (string) ($item['technology'] ?? ''),
                'city' => trim((string) ($item['city'] ?? '')),
                'neighborhood' => trim((string) ($item['neighborhood'] ?? '')),
                'status' => (string) ($item['status'] ?? ''),
                'status_class' => (string) ($statusVisual['class'] ?? 'client-status-other'),
                'status_label' => (string) ($statusVisual['label'] ?? 'Outro'),
                'status_note' => trim((string) ($statusVisual['note'] ?? '')),
                'card_class' => in_array((string) ($statusVisual['class'] ?? ''), ['client-status-blocked', 'client-status-cancelled'], true)
                    ? 'client-result-card ' . (
                        (string) ($statusVisual['class'] ?? '') === 'client-status-blocked'
                            ? 'client-result-card--blocked'
                            : 'client-result-card--cancelled'
                    )
                    : 'client-result-card',
                'url' => (string) ($item['detail_url'] ?? ''),
            ];
        }

        return Response::json([
            'status' => 'success',
            'query' => $query,
            'results' => $payload,
        ]);
    }

    public function detail(Request $request): Response
    {
        if (!$this->canSearchClients()) {
            Flash::set('error', 'Seu usuário não possui permissão para abrir o detalhe de clientes.');
            return Response::redirect('/dashboard');
        }

        $login = $this->sanitizeLogin((string) $request->query('login', $request->input('login', '')));

        if ($login === '') {
            Flash::set('error', 'Informe o login do cliente para abrir o detalhe.');
            return Response::redirect('/clientes');
        }

        try {
            $clientProfile = $this->mkauthDatabase->findClientProfile($login);
        } catch (\Throwable $exception) {
            $clientProfile = null;
            Flash::set('warning', 'Nao foi possivel consultar o MkAuth agora. Mostrando apenas dados locais.');
        }

        try {
            $contract = $this->contractRepository->findByLogin($login);
        } catch (\Throwable) {
            $contract = null;
        }

        try {
            $registrations = $this->localRepository->findClientRegistrationsByLogin($login, 10);
            $registration = $registrations[0] ?? null;
            $checkpoints = $this->localRepository->findInstallationCheckpointsByLogin($login, 10);
        } catch (\Throwable) {
            $registration = null;
            $checkpoints = [];
        }

        try {
            $acceptance = is_array($contract) && isset($contract['id'])
                ? $this->contractAcceptanceRepository->findLatestByContractId((int) $contract['id'])
                : null;
        } catch (\Throwable) {
            $acceptance = null;
        }

        try {
            $financialTask = is_array($contract) && isset($contract['id'])
                ? $this->financialTaskRepository->findByContractId((int) $contract['id'])
                : null;
        } catch (\Throwable) {
            $financialTask = null;
        }

        try {
            $auditLogs = $this->localRepository->auditLogsForContract(
                is_array($contract) && isset($contract['id']) ? (int) $contract['id'] : null,
                is_array($acceptance) && isset($acceptance['id']) ? (int) $acceptance['id'] : null,
                is_array($financialTask) && isset($financialTask['id']) ? (int) $financialTask['id'] : null,
                is_array($registration) && isset($registration['id']) ? (int) $registration['id'] : null,
                100
            );
        } catch (\Throwable) {
            $auditLogs = [];
        }

        $detail = $this->buildClientDetail(
            $login,
            is_array($clientProfile) ? $clientProfile : [],
            is_array($contract) ? $contract : [],
            is_array($registration) ? $registration : [],
            is_array($acceptance) ? $acceptance : [],
            is_array($financialTask) ? $financialTask : [],
            is_array($checkpoints) ? $checkpoints : [],
            is_array($auditLogs) ? $auditLogs : []
        );
        try {
            $detail['scannedDocuments'] = $this->clientDocumentRepository->listByLogin($login);
        } catch (\Throwable) {
            $detail['scannedDocuments'] = [];
        }

        $html = $this->view->render('clients/detail', [
            'pageTitle' => 'Cliente',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveViewUser(),
            'flash' => Flash::get(),
            'detail' => $detail,
            'canCreateClient' => $this->canCreateClient(),
            'canSearchClients' => $this->canSearchClients(),
            'canManageContracts' => $this->canManageContracts(),
            'canManageFinancial' => $this->canManageFinancial(),
            'canManageSettings' => $this->canManageSettings(),
            'canRequestUpgrade' => $this->canRequestUpgrade(),
            'canRequestContractSignature' => $this->canRequestContractSignature(),
            'canCompleteUpgradeTechnical' => $this->canCompleteUpgradeTechnical(),
            'canCorrectUpgrade' => $this->canCorrectUpgrade(),
            'canCancelPendingContract' => $this->canCancelPendingContract(),
            'canSupersedeContract' => $this->canSupersedeContract(),
            'contractSignatureCsrfToken' => Csrf::token('client_contract_signature:' . $login),
        ]);

        return Response::html($html);
    }

    public function connectionDetails(Request $request): Response
    {
        if (!$this->canSearchClients()) {
            return Response::json(['status' => 'error', 'message' => 'Acesso negado.'], 403);
        }

        $login = $this->sanitizeLogin((string) $request->query('login', ''));
        if ($login === '') {
            return Response::json(['status' => 'error', 'message' => 'Login inválido.'], 422);
        }

        try {
            $profile = $this->mkauthDatabase->findClientProfile($login) ?? [];
            $connection = $this->mkauthDatabase->radiusConnectionStatus($login);
        } catch (\Throwable) {
            return Response::json(['status' => 'error', 'message' => 'Consulta de conexão indisponível.'], 503);
        }

        $session = is_array($connection['session'] ?? null) ? $connection['session'] : [];
        $technology = $this->technologyMapper->describe((string) ($profile['plano_tecnologia'] ?? ''));
        $canViewIp = $this->canViewClientIp();

        return Response::json([
            'status' => 'success',
            'data' => [
                'online' => !empty($connection['online']),
                'login' => $login,
                'plan' => (string) ($profile['plano_nome'] ?? $profile['plano'] ?? ''),
                'technology' => $technology,
                'ip' => $canViewIp && filter_var((string) ($session['framedipaddress'] ?? ''), FILTER_VALIDATE_IP)
                    ? (string) $session['framedipaddress']
                    : '',
                'mac' => trim((string) ($session['callingstationid'] ?? $profile['user_mac'] ?? '')),
                'nas' => trim((string) ($session['nasipaddress'] ?? '')),
                'started_at' => trim((string) ($session['acctstarttime'] ?? '')),
                'updated_at' => trim((string) ($session['acctupdatetime'] ?? '')),
                'connected_seconds' => $connection['connected_seconds'] ?? null,
                'equipment' => trim((string) ($profile['equipamento'] ?? '')),
                'onu_ont' => trim((string) ($profile['onu_ont'] ?? '')),
                'interface' => trim((string) ($profile['interface'] ?? '')),
                'source' => 'MkAuth/RADIUS (somente leitura)',
            ],
        ]);
    }

    public function financialDetails(Request $request): Response
    {
        if (!$this->canManageFinancial()) {
            return Response::json(['status' => 'error', 'message' => 'Detalhes financeiros restritos.'], 403);
        }

        $login = $this->sanitizeLogin((string) $request->query('login', ''));
        if ($login === '') {
            return Response::json(['status' => 'error', 'message' => 'Login inválido.'], 422);
        }

        try {
            $summary = $this->mkauthDatabase->clientFinancialSummary($login);
        } catch (\Throwable) {
            return Response::json(['status' => 'error', 'message' => 'Consulta financeira indisponível.'], 503);
        }

        return Response::json(['status' => 'success', 'data' => $summary]);
    }

    public function upgrade(Request $request): Response
    {
        if (!$this->canRequestUpgrade()) {
            Flash::set('error', 'Seu usuário não possui permissão para iniciar Upgrade / Migração.');
            return Response::redirect('/clientes');
        }

        $login = $this->sanitizeLogin((string) $request->query('login', $request->input('login', '')));

        if ($login === '') {
            Flash::set('error', 'Informe o login do cliente para iniciar o upgrade.');
            return Response::redirect('/clientes');
        }

        $correctionOf = (int) $request->query('correction_of', $request->input('correction_of', 0));
        $processId = (int) $request->query('process_id', $request->input('process_id', 0));
        $correctionContract = $correctionOf > 0 ? $this->loadCorrectableUpgrade($correctionOf, $login, $processId) : null;

        if ($correctionOf > 0 && !is_array($correctionContract)) {
            Flash::set('error', 'A correção informada não está disponível ou não pertence a este cliente.');
            return Response::redirect('/clientes/detalhe?login=' . rawurlencode($login));
        }

        if ($correctionOf > 0 && !$this->canCorrectUpgradeContract($correctionContract)) {
            Flash::set('error', 'Seu usuário não possui permissão para corrigir este Upgrade / Migração.');
            return Response::redirect('/clientes/detalhe?login=' . rawurlencode($login));
        }

        if ($correctionOf <= 0 && $this->hasOpenUpgradeProcess($login)) {
            Flash::set('warning', 'Já existe um Upgrade / Migração em andamento. Retome o processo existente.');
            return Response::redirect('/clientes/detalhe?login=' . rawurlencode($login) . '#upgrade-process');
        }

        $context = $this->loadUpgradeContext($login);
        if ($context === null) {
            Flash::set('error', 'Nao foi possivel localizar o cliente para upgrade.');
            return Response::redirect('/clientes/detalhe?login=' . rawurlencode($login));
        }

        if (is_array($correctionContract)) {
            $context = $this->applyCorrectionContext($context, $correctionContract);
        }

        return $this->renderUpgradeForm(
            $request,
            $context,
            [],
            [],
            $correctionOf,
            is_array($correctionContract) ? (string) ($correctionContract['cancellation_reason'] ?? '') : '',
            $processId,
            is_array($correctionContract) && (string) ($correctionContract['lifecycle_status'] ?? '') === 'active'
                ? 'pending'
                : ($correctionOf > 0 ? 'substitution' : '')
        );
    }

    private function renderUpgradeForm(
        Request $request,
        array $context,
        array $form = [],
        array $errors = [],
        int $correctionOf = 0,
        string $correctionReason = '',
        int $processId = 0,
        string $revisionMode = ''
    ): Response {
        $html = $this->view->render('clients/upgrade', [
            'pageTitle' => 'Upgrade / Migração',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveViewUser(),
            'flash' => Flash::get(),
            'context' => $context,
            'canCreateClient' => $this->canCreateClient(),
            'canSearchClients' => $this->canSearchClients(),
            'canUpgradeCommercial' => $this->canUpgradeCommercial(),
            'currentLogin' => (string) ($context['login'] ?? ''),
            'form' => $form,
            'errors' => $errors,
            'correctionOf' => $correctionOf,
            'correctionReason' => $correctionReason,
            'processId' => $processId,
            'revisionMode' => $revisionMode,
            'csrfToken' => Csrf::token('client_upgrade:' . (string) ($context['login'] ?? '')),
        ]);

        return Response::html($html, $errors === [] ? 200 : 422);
    }

    public function storeUpgrade(Request $request): Response
    {
        $login = $this->sanitizeLogin((string) $request->input('login', $request->query('login', '')));
        if ($login === '') {
            Flash::set('error', 'Informe o login do cliente para concluir o upgrade.');
            return Response::redirect('/clientes');
        }

        if (!$this->canRequestUpgrade()) {
            Flash::set('error', 'Seu usuário não possui permissão para iniciar Upgrade / Migração.');
            return Response::redirect('/clientes/detalhe?login=' . rawurlencode($login));
        }

        if (!Csrf::verify($request, 'client_upgrade:' . $login)) {
            Flash::set('error', 'A sessão do formulário expirou. Reabra a nova condição e tente novamente.');
            return Response::redirect('/clientes/upgrade?login=' . rawurlencode($login));
        }


        $correctionOf = (int) $request->input('correction_of', 0);
        $processId = (int) $request->input('process_id', 0);
        $revisionMode = trim((string) $request->input('revision_mode', ''));
        $correctionReason = trim((string) $request->input('correction_reason', ''));
        $correctionContract = $correctionOf > 0 ? $this->loadCorrectableUpgrade($correctionOf, $login, $processId) : null;

        if ($correctionOf > 0 && (!is_array($correctionContract) || !$this->canCorrectUpgradeContract($correctionContract))) {
            Flash::set('error', 'A correção informada não está disponível para este usuário.');
            return Response::redirect('/clientes/detalhe?login=' . rawurlencode($login));
        }

        if ($correctionOf <= 0 && $this->hasOpenUpgradeProcess($login)) {
            Flash::set('warning', 'Já existe um Upgrade / Migração em andamento. Retome o processo existente.');
            return Response::redirect('/clientes/detalhe?login=' . rawurlencode($login) . '#upgrade-process');
        }

        $context = $this->loadUpgradeContext($login);
        if ($context === null) {
            Flash::set('error', 'Nao foi possivel localizar o cliente para gerar o upgrade.');
            return Response::redirect('/clientes/detalhe?login=' . rawurlencode($login));
        }

        if (is_array($correctionContract)) {
            $context = $this->applyCorrectionContext($context, $correctionContract);
        }

        $data = $this->collectUpgradeFormData($request, $context);
        $errors = $this->validateUpgrade($data, $context);
        if ($correctionOf > 0 && $correctionReason === '') {
            $errors['correction_reason'] = 'Informe o motivo da correção antes de gerar a nova versão.';
        }

        if ($errors !== []) {
            return $this->renderUpgradeForm($request, $context, $data, $errors, $correctionOf, $correctionReason, $processId, $revisionMode);
        }

        $pdo = $this->database->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        $upgradeLockName = 'isp_aux:upgrade:' . sha1(strtolower($login));
        $upgradeLockAcquired = false;
        try {
            $lockResult = $this->database->fetchOne(
                'SELECT GET_LOCK(:lock_name, 5) AS acquired',
                ['lock_name' => $upgradeLockName]
            );
            $upgradeLockAcquired = (int) ($lockResult['acquired'] ?? 0) === 1;
            if (!$upgradeLockAcquired) {
                throw new \RuntimeException('Outro operador está alterando este Upgrade / Migração. Tente novamente em alguns instantes.');
            }

            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }

            $this->assertUpgradeCreationAllowed($login, $correctionOf);
            $pendingRevision = is_array($correctionContract)
                && (string) ($correctionContract['lifecycle_status'] ?? '') === 'active'
                && $revisionMode === 'pending';
            $result = $pendingRevision
                ? $this->revisePendingUpgradeArtifacts($processId, $data, $context, $request, $correctionContract, $correctionReason)
                : $this->syncUpgradeContractArtifacts($data, $context, $request, false, $correctionContract, $processId);
            if (is_array($correctionContract)) {
                if ($pendingRevision) {
                    if ($ownsTransaction) {
                        $pdo->commit();
                    }
                    $this->database->fetchOne('SELECT RELEASE_LOCK(:lock_name) AS released', ['lock_name' => $upgradeLockName]);
                    $upgradeLockAcquired = false;
                    Flash::set('success', 'Condição corrigida no mesmo processo. O aceite anterior foi invalidado e a nova revisão está pronta.');
                    return Response::redirect('/processos/migracao?id=' . (int) ($result['process_id'] ?? $processId));
                }
                $operator = $this->resolveUser();
                $oldAcceptance = $this->contractAcceptanceRepository->findLatestByContractId($correctionOf);
                if (is_array($oldAcceptance) && (int) ($oldAcceptance['id'] ?? 0) > 0
                    && trim((string) ($oldAcceptance['revoked_at'] ?? '')) === ''
                ) {
                    $this->contractAcceptanceRepository->revoke(
                        (int) $oldAcceptance['id'],
                        $correctionReason,
                        isset($operator['id']) ? (int) $operator['id'] : null,
                        (string) ($operator['login'] ?? ''),
                        true
                    );
                }
                $oldTask = $this->financialTaskRepository->findByContractId($correctionOf);
                if (is_array($oldTask) && (int) ($oldTask['id'] ?? 0) > 0
                    && (string) ($oldTask['status'] ?? '') !== 'concluido'
                ) {
                    $this->financialTaskRepository->updateStatus((int) $oldTask['id'], 'cancelado');
                }
                $this->contractRepository->markLifecycle(
                    $correctionOf,
                    'superseded',
                    $correctionReason,
                    isset($operator['id']) ? (int) $operator['id'] : null,
                    (string) ($operator['login'] ?? ''),
                    (int) ($result['contract_id'] ?? 0)
                );
                $this->recordAudit('contract.upgrade.superseded', 'client_contract', $correctionOf, [
                    'login' => $login,
                    'previous_contract_id' => $correctionOf,
                    'new_contract_id' => (int) ($result['contract_id'] ?? 0),
                    'reason' => $correctionReason,
                    'operator_id' => isset($operator['id']) ? (int) $operator['id'] : null,
                    'operator_login' => (string) ($operator['login'] ?? ''),
                    'operator_name' => (string) ($operator['name'] ?? ''),
                    'before' => $this->extractUpgradeSnapshot($correctionContract),
                    'after' => $this->extractUpgradeSnapshot((array) ($result['contract'] ?? [])),
                ], $request);
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }
            $this->database->fetchOne('SELECT RELEASE_LOCK(:lock_name) AS released', ['lock_name' => $upgradeLockName]);
            $upgradeLockAcquired = false;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($upgradeLockAcquired) {
                try {
                    $this->database->fetchOne('SELECT RELEASE_LOCK(:lock_name) AS released', ['lock_name' => $upgradeLockName]);
                } catch (\Throwable) {
                }
            }
            Flash::set('error', 'Nao foi possivel concluir o upgrade agora: ' . $exception->getMessage());
            return Response::redirect('/clientes/upgrade?login=' . rawurlencode($login)
                . ($correctionOf > 0 ? '&correction_of=' . $correctionOf : '')
                . ($processId > 0 ? '&process_id=' . $processId : ''));
        }

        Flash::set('success', $correctionOf > 0
            ? 'Correção criada. Confira as condições e prepare o envio do novo aceite.'
            : 'Nova condição salva. Confira o documento, colete a assinatura e escolha os canais.');

        $processId = (int) ($result['process_id'] ?? 0);
        if ((string) $request->input('next_action', '') === 'later') {
            Flash::set('success', 'Nova condição salva. O processo pode ser retomado pelo perfil do cliente.');
            return Response::redirect('/clientes/detalhe?login=' . rawurlencode($login));
        }
        return Response::redirect($processId > 0
            ? '/processos/migracao?id=' . $processId
            : '/clientes/detalhe?login=' . rawurlencode($login));
    }

    public function cancelUpgradeRequest(Request $request): Response
    {
        $contractId = (int) $request->input('contract_id', 0);
        $processId = (int) $request->input('process_id', 0);
        $reason = trim((string) $request->input('cancellation_reason', ''));
        $contract = $contractId > 0 ? $this->contractRepository->findById($contractId) : null;
        $login = $this->sanitizeLogin((string) ($contract['mkauth_login'] ?? $request->input('login', '')));
        $returnTo = '/clientes/detalhe?login=' . rawurlencode($login);

        if (!is_array($contract) || (string) ($contract['tipo_aceite'] ?? '') !== 'upgrade_migracao') {
            Flash::set('error', 'Upgrade / Migração não localizado.');
            return Response::redirect($login !== '' ? $returnTo : '/clientes');
        }
        if (!Csrf::verify($request, 'client_upgrade_cancel:' . $contractId)) {
            Flash::set('error', 'A sessão do cancelamento expirou. Reabra o processo e tente novamente.');
            return Response::redirect($returnTo . '#upgrade-process');
        }
        if (!$this->canCancelPendingContract()) {
            Flash::set('error', 'Seu usuário não possui permissão para cancelar esta solicitação.');
            return Response::redirect($returnTo);
        }
        if ($reason === '') {
            Flash::set('error', 'Informe o motivo do cancelamento.');
            return Response::redirect($returnTo . '#upgrade-process');
        }
        if ($this->upgradeTechnicalExecutionCompleted($contract)) {
            Flash::set('error', 'Este processo já possui execução técnica concluída. É necessário abrir um processo corretivo.');
            return Response::redirect($returnTo . '#upgrade-process');
        }

        $acceptance = $this->contractAcceptanceRepository->findLatestByContractId($contractId);
        if ((string) ($acceptance['status'] ?? '') === 'aceito') {
            Flash::set('error', 'Este contrato já foi aceito. Use Corrigir e reenviar com autorização administrativa ou comercial.');
            return Response::redirect($returnTo . '#upgrade-process');
        }

        try {
            $this->transitionUpgradeForCorrection($contract, $acceptance, 'cancelled', $reason, $request);
            $this->operationalProcessService->cancelForContract(
                OperationalProcessService::TYPE_MIGRATION,
                $contractId,
                $this->resolveUser(),
                $reason
            );
            Flash::set('success', 'Solicitação cancelada. O link antigo foi invalidado e a pendência encerrada.');
        } catch (\Throwable $exception) {
            Flash::set('error', 'Não foi possível cancelar a solicitação: ' . $exception->getMessage());
        }

        return Response::redirect($returnTo . '#upgrade-process');
    }

    public function prepareMigrationAcceptance(Request $request): Response
    {
        $processId = (int) $request->input('process_id', 0);
        $returnTo = '/processos/migracao?id=' . $processId . '&step=confirm_acceptance';
        $process = $processId > 0 ? $this->operationalProcessService->detail($processId) : null;
        if (!is_array($process)
            || (string) ($process['process_type'] ?? '') !== OperationalProcessService::TYPE_MIGRATION
            || !$this->canRequestUpgrade()
        ) {
            Flash::set('error', 'Processo de migração indisponível para este usuário.');
            return Response::redirect('/processos');
        }
        if (!Csrf::verify($request, 'operational_process:' . $processId)) {
            Flash::set('error', 'A sessão do formulário expirou. Reabra a tela e tente novamente.');
            return Response::redirect($returnTo);
        }

        $contract = $this->contractRepository->findById((int) ($process['contract_id'] ?? 0));
        $acceptance = $this->contractAcceptanceRepository->findById((int) ($process['acceptance_id'] ?? 0));
        if (!is_array($contract) || !is_array($acceptance)
            || (int) ($acceptance['contract_id'] ?? 0) !== (int) ($contract['id'] ?? 0)
            || trim((string) ($acceptance['revoked_at'] ?? '')) !== ''
        ) {
            Flash::set('error', 'Contrato ou aceite ativo não localizado.');
            return Response::redirect($returnTo);
        }

        $remote = (string) $request->input('client_absent', '0') === '1';
        $remoteReason = trim((string) $request->input('remote_signature_reason', ''));
        $phoneOriginal = preg_replace('/\D+/', '', (string) ($acceptance['telefone_enviado'] ?? $contract['telefone_cliente'] ?? '')) ?? '';
        $phone = preg_replace('/\D+/', '', (string) $request->input('phone', $phoneOriginal)) ?? '';
        if (in_array(strlen($phone), [10, 11], true)) {
            $phone = '55' . $phone;
        }
        $emailOriginal = strtolower(trim((string) ($process['metadata']['client']['email'] ?? '')));
        $email = strtolower(trim((string) $request->input('email', $emailOriginal)));
        $sendWhatsapp = (string) $request->input('channel_whatsapp', '0') === '1';
        $sendEmail = (string) $request->input('channel_email', '0') === '1';
        $signatureData = trim((string) $request->input('assinatura_cliente', ''));

        $errors = [];
        if (!$sendWhatsapp && !$sendEmail) {
            $errors[] = 'Selecione pelo menos um canal válido.';
        }
        if ($sendWhatsapp && (strlen($phone) < 12 || strlen($phone) > 13)) {
            $errors[] = 'Informe um WhatsApp com DDD válido.';
        }
        if ($sendEmail && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Informe um e-mail válido.';
        }
        if ($remote && $remoteReason === '') {
            $errors[] = 'Informe por que o cliente não está presente.';
        }
        if (!$remote && $signatureData === '') {
            $errors[] = 'Colete a assinatura local antes de enviar.';
        }
        if ($errors !== []) {
            Flash::set('error', implode(' ', $errors));
            return Response::redirect($returnTo);
        }

        try {
            $operator = $this->resolveUser();
            $signaturePath = null;
            if (!$remote) {
                $signaturePath = $this->acceptanceEvidenceService->saveSignature((int) $acceptance['id'], $signatureData, 'local');
            }
            $evidencePath = $this->acceptanceEvidenceService->saveEvidence((int) $acceptance['id'], [
                'event' => 'local_signature_and_channels_prepared',
                'acceptance_id' => (int) $acceptance['id'],
                'contract_id' => (int) $contract['id'],
                'process_id' => $processId,
                'signature_mode' => $remote ? 'remote' : 'local',
                'signature_path' => $signaturePath,
                'remote_reason' => $remote ? $remoteReason : null,
                'channels' => ['whatsapp' => $sendWhatsapp, 'email' => $sendEmail],
                'contacts' => [
                    'phone' => $phone,
                    'email' => $email,
                    'phone_changed' => $phone !== $phoneOriginal,
                    'email_changed' => $email !== $emailOriginal,
                ],
                'operator' => ['id' => $operator['id'] ?? null, 'login' => $operator['login'] ?? '', 'name' => $operator['name'] ?? ''],
                'recorded_at' => date('Y-m-d H:i:s'),
                'ip_address' => (string) $request->server('REMOTE_ADDR', ''),
                'user_agent' => (string) $request->header('User-Agent', ''),
            ]);

            $updated = array_merge($acceptance, [
                'status' => $remote ? 'assinatura_pendente' : 'criado',
                'telefone_enviado' => $phone,
                'remote_signature_reason' => $remote ? $remoteReason : null,
                'evidence_json_path' => $evidencePath,
            ]);
            if ($this->contractAcceptanceRepository->updateById((int) $acceptance['id'], $updated) !== 1) {
                throw new \RuntimeException('O aceite foi alterado durante a preparação.');
            }

            $notificationDraft = [
                'nome_completo' => (string) ($contract['nome_cliente'] ?? $process['client_name'] ?? 'Cliente'),
                'telefone_cliente' => $phone,
                'celular' => $phone,
                'email' => $email,
                'email_original' => $email,
                'has_real_email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
            ];
            $results = $this->dispatchAcceptanceChannels($contract, array_merge($updated, ['id' => (int) $acceptance['id']]), $notificationDraft, $sendWhatsapp, $sendEmail, true, $request);
            $this->recordAudit('contract.migration.acceptance.prepared', 'contract_acceptance', (int) $acceptance['id'], [
                'process_id' => $processId,
                'signature_mode' => $remote ? 'remote' : 'local',
                'channels' => array_keys($results),
                'dry_run' => (bool) $this->config->get('evotrix.dry_run', true) || (bool) $this->config->get('email.dry_run', true),
                'contact_changed' => $phone !== $phoneOriginal || $email !== $emailOriginal,
                'evidence_json_path' => $evidencePath,
            ], $request);
            $this->operationalProcessService->synchronizeAcceptance((int) $acceptance['id']);
            Flash::set('success', 'Assinatura e canais registrados. Em homologação, os envios permanecem em dry-run.');
        } catch (\Throwable $exception) {
            Flash::set('error', 'Não foi possível preparar o aceite: ' . $exception->getMessage());
        }

        return Response::redirect($returnTo);
    }

    public function startUpgradeCorrection(Request $request): Response
    {
        $contractId = (int) $request->input('contract_id', 0);
        $processId = (int) $request->input('process_id', 0);
        $reason = trim((string) $request->input('correction_reason', ''));
        $contract = $contractId > 0 ? $this->contractRepository->findById($contractId) : null;
        $login = $this->sanitizeLogin((string) ($contract['mkauth_login'] ?? $request->input('login', '')));
        $returnTo = '/clientes/detalhe?login=' . rawurlencode($login);

        if (!is_array($contract) || (string) ($contract['tipo_aceite'] ?? '') !== 'upgrade_migracao') {
            Flash::set('error', 'Upgrade / Migração não localizado.');
            return Response::redirect($login !== '' ? $returnTo : '/clientes');
        }
        if (!Csrf::verify($request, 'client_upgrade_correct:' . $contractId)) {
            Flash::set('error', 'A sessão da correção expirou. Reabra o processo e tente novamente.');
            return Response::redirect($returnTo . '#upgrade-process');
        }
        if ($reason === '') {
            Flash::set('error', 'Informe o motivo da correção.');
            return Response::redirect($returnTo . '#upgrade-process');
        }

        $acceptance = $this->contractAcceptanceRepository->findLatestByContractId($contractId);
        if (!$this->canCorrectUpgradeContract($contract, $acceptance)) {
            Flash::set('error', 'Seu usuário não possui permissão para corrigir este processo.');
            return Response::redirect($returnTo . '#upgrade-process');
        }

        if ($processId <= 0) {
            foreach ($this->operationalProcessService->listByLogin($login) as $candidate) {
                if ((int) ($candidate['contract_id'] ?? 0) === $contractId
                    && (string) ($candidate['process_type'] ?? '') === OperationalProcessService::TYPE_MIGRATION
                    && !in_array((string) ($candidate['status'] ?? ''), ['completed', 'cancelled'], true)
                ) {
                    $processId = (int) ($candidate['id'] ?? 0);
                    break;
                }
            }
        }

        try {
            $this->transitionUpgradeForCorrection($contract, $acceptance, 'correction_pending', $reason, $request);
            Flash::set('warning', 'Correção de Upgrade / Migração iniciada. O link antigo foi invalidado; finalize e envie a nova versão.');
        } catch (\Throwable $exception) {
            Flash::set('error', 'Não foi possível iniciar a correção: ' . $exception->getMessage());
            return Response::redirect($returnTo . '#upgrade-process');
        }

        return Response::redirect('/clientes/upgrade?login=' . rawurlencode($login)
            . '&correction_of=' . $contractId
            . ($processId > 0 ? '&process_id=' . $processId : ''));
    }

    public function requestDigitalContractSignature(Request $request): Response
    {
        $login = $this->sanitizeLogin((string) $request->input('login', $request->query('login', '')));
        if ($login === '') {
            Flash::set('error', 'Informe o login do cliente para solicitar o contrato digital.');
            return Response::redirect('/clientes');
        }

        if (!$this->canRequestContractSignature()) {
            Flash::set('error', 'Usuario sem permissao para solicitar contrato digital.');
            return Response::redirect('/clientes/detalhe?login=' . rawurlencode($login));
        }

        if (!Csrf::verify($request, 'client_contract_signature:' . $login)) {
            Flash::set('error', 'A sessão do formulário expirou. Reabra o cliente e tente novamente.');
            return Response::redirect('/clientes/detalhe?login=' . rawurlencode($login));
        }

        if ((string) $request->input('confirm_send', '') !== '1') {
            Flash::set('error', 'Confirme o envio do contrato digital antes de continuar.');
            return Response::redirect('/clientes/detalhe?login=' . rawurlencode($login));
        }


        $signatureMode = (string) $request->input('signature_mode', 'remote') === 'local' ? 'local' : 'remote';
        $remoteSignatureReason = trim((string) $request->input('remote_signature_reason', ''));
        if ($signatureMode === 'remote' && $remoteSignatureReason === '') {
            Flash::set('error', 'Informe o motivo da assinatura remota.');
            return Response::redirect('/clientes/detalhe?login=' . rawurlencode($login));
        }

        try {
            $clientProfile = $this->mkauthDatabase->findClientProfile($login);
        } catch (\Throwable $exception) {
            $clientProfile = null;
        }

        if (!is_array($clientProfile) || $clientProfile === []) {
            Flash::set('error', 'Nao foi possivel localizar o cliente no MkAuth para gerar o contrato digital.');
            return Response::redirect('/clientes/detalhe?login=' . rawurlencode($login));
        }

        try {
            $contracts = $this->contractRepository->listByLogin($login, 20);
        } catch (\Throwable) {
            $contracts = [];
        }

        $pending = $this->findPendingDigitalContractAcceptance($contracts);
        if ($pending !== null) {
            try {
                $pendingContractId = (int) ($pending['contract']['id'] ?? 0);
                $pendingAcceptanceId = (int) ($pending['acceptance']['id'] ?? 0);
                $this->operationalProcessService->ensureForContract(
                    OperationalProcessService::TYPE_STANDALONE_SIGNATURE,
                    $pending['contract'],
                    $pending['acceptance'],
                    [
                        'login' => $login,
                        'client_name' => (string) ($clientProfile['nome'] ?? ''),
                        'phone' => (string) ($clientProfile['celular'] ?? $clientProfile['fone'] ?? ''),
                        'email' => (string) ($clientProfile['email'] ?? ''),
                        'signature_mode' => $signatureMode,
                    ],
                    $this->resolveUser()
                );
                if ($signatureMode === 'local') {
                    $termBody = $this->buildContractTermBody($pending['contract']);
                    $localAcceptance = $this->buildDigitalContractAcceptanceData(
                        $pendingContractId,
                        $pending['contract'],
                        hash('sha256', $termBody),
                        $request,
                        '',
                        'local'
                    );
                    $localAcceptanceId = $this->contractAcceptanceRepository->create($localAcceptance) ?? 0;
                    if ($localAcceptanceId <= 0) {
                        throw new \RuntimeException('O aceite local não pôde ser preparado.');
                    }
                    if ($pendingAcceptanceId > 0) {
                        $this->contractAcceptanceRepository->cancel($pendingAcceptanceId);
                    }
                    $localAcceptanceRecord = $this->contractAcceptanceRepository->findById($localAcceptanceId)
                        ?? array_merge($localAcceptance, ['id' => $localAcceptanceId]);
                    $this->operationalProcessService->ensureForContract(
                        OperationalProcessService::TYPE_STANDALONE_SIGNATURE,
                        $pending['contract'],
                        $localAcceptanceRecord,
                        [
                            'login' => $login,
                            'client_name' => (string) ($clientProfile['nome'] ?? ''),
                            'phone' => (string) ($clientProfile['celular'] ?? $clientProfile['fone'] ?? ''),
                            'email' => (string) ($clientProfile['email'] ?? ''),
                            'signature_mode' => 'local',
                        ],
                        $this->resolveUser()
                    );
                    $this->recordAudit('contract.acceptance.local_prepared', 'contract_acceptance', $localAcceptanceId, [
                        'login' => $login,
                        'contract_id' => $pendingContractId,
                        'replaced_acceptance_id' => $pendingAcceptanceId,
                    ], $request);
                    Flash::set('success', 'Aceite local preparado. Colha agora a assinatura digital do titular.');
                    return Response::redirect('/aceite/' . rawurlencode((string) $localAcceptance['token']));
                }

                $pending['acceptance']['remote_signature_reason'] = $remoteSignatureReason;
                if ($pendingAcceptanceId > 0) {
                    $this->contractAcceptanceRepository->updateById($pendingAcceptanceId, $pending['acceptance']);
                }
                if ($this->hasRecentDigitalContractNotification($pendingContractId, $pendingAcceptanceId)) {
                    Flash::set('success', 'Envio do contrato digital ja processado ha poucos segundos. O aceite pendente foi mantido.');
                } else {
                    $this->dispatchDigitalContractAcceptance($pending['contract'], $pending['acceptance'], $clientProfile, true, $request);
                    Flash::set('success', 'Aceite do contrato digital reenviado ao cliente.');
                }
            } catch (\Throwable $exception) {
                Flash::set('error', 'Contrato digital pendente localizado, mas nao foi possivel reenviar agora: ' . $exception->getMessage());
            }

            return Response::redirect('/clientes/detalhe?login=' . rawurlencode($login));
        }

        $localAcceptanceToken = '';
        $contractData = [];
        $acceptanceRecord = [];
        $pdo = $this->database->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        try {
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }
            $contractData = $this->buildDigitalContractData($login, $clientProfile);
            $contractId = $this->contractRepository->create($contractData) ?? 0;
            if ($contractId <= 0) {
                throw new \RuntimeException('Contrato digital nao pôde ser gravado.');
            }

            $contractData['id'] = $contractId;
            $contractData['contract_id'] = $contractId;
            $termBody = $this->buildContractTermBody($contractData);
            $acceptanceData = $this->buildDigitalContractAcceptanceData(
                $contractId,
                $contractData,
                hash('sha256', $termBody),
                $request,
                $remoteSignatureReason,
                $signatureMode
            );
            $acceptanceId = $this->contractAcceptanceRepository->create($acceptanceData) ?? 0;
            if ($acceptanceId <= 0) {
                throw new \RuntimeException('Aceite do contrato digital nao pôde ser gravado.');
            }

            $acceptanceRecord = $this->contractAcceptanceRepository->findById($acceptanceId) ?? array_merge($acceptanceData, ['id' => $acceptanceId]);
            $this->operationalProcessService->ensureForContract(
                OperationalProcessService::TYPE_STANDALONE_SIGNATURE,
                $contractData,
                is_array($acceptanceRecord) ? $acceptanceRecord : array_merge($acceptanceData, ['id' => $acceptanceId]),
                [
                    'login' => $login,
                    'client_name' => (string) ($clientProfile['nome'] ?? ''),
                    'phone' => (string) ($clientProfile['celular'] ?? $clientProfile['fone'] ?? ''),
                    'email' => (string) ($clientProfile['email'] ?? ''),
                    'signature_mode' => $signatureMode,
                ],
                $this->resolveUser()
            );
            $this->recordAudit('contract.digital.created', 'client_contract', $contractId, [
                'login' => $login,
                'contract_id' => $contractId,
                'acceptance_id' => $acceptanceId,
                'status' => 'assinatura_pendente',
                'tipo_aceite' => 'contrato_digital',
            ], $request);
            $this->recordAudit('contract.acceptance.created', 'contract_acceptance', $acceptanceId, [
                'login' => $login,
                'contract_id' => $contractId,
                'status' => 'assinatura_pendente',
                'tipo_aceite' => 'contrato_digital',
            ], $request);
            if ($signatureMode !== 'remote') {
                $localAcceptanceToken = (string) ($acceptanceData['token'] ?? '');
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Flash::set('error', 'Nao foi possivel solicitar o contrato digital agora: ' . $exception->getMessage());
            return Response::redirect('/clientes/detalhe?login=' . rawurlencode($login));
        }

        if ($signatureMode === 'remote') {
            try {
                $this->dispatchDigitalContractAcceptance($contractData, $acceptanceRecord, $clientProfile, false, $request);
            } catch (\Throwable $exception) {
                $this->recordAudit('contract.digital.dispatch_failed', 'contract_acceptance', (int) ($acceptanceRecord['id'] ?? 0), [
                    'contract_id' => (int) ($contractData['id'] ?? 0),
                    'login' => $login,
                    'error' => $exception->getMessage(),
                    'requeue_required' => true,
                ], $request);
                Flash::set('warning', 'Contrato e aceite foram preparados, mas um canal de envio falhou. Retome o processo para tentar novamente.');
                return Response::redirect('/clientes/detalhe?login=' . rawurlencode($login));
            }
        }

        if ($signatureMode === 'local' && $localAcceptanceToken !== '') {
            Flash::set('success', 'Contrato digital gerado. Colha agora a assinatura digital do titular.');
            return Response::redirect('/aceite/' . rawurlencode($localAcceptanceToken));
        }

        Flash::set('success', 'Contrato digital gerado com assinatura remota pendente.');

        return Response::redirect('/clientes/detalhe?login=' . rawurlencode($login));
    }

    public function storeUpgradeTechnicalExecution(Request $request): Response
    {
        $login = $this->sanitizeLogin((string) $request->input('login', ''));
        $contractId = (int) $request->input('contract_id', 0);
        $returnTo = '/clientes/detalhe?login=' . rawurlencode($login);

        if (!$this->canCompleteUpgradeTechnical()) {
            Flash::set('error', 'Seu usuário não possui permissão para registrar a execução técnica do upgrade.');
            return Response::redirect($login !== '' ? $returnTo : '/clientes');
        }

        $contract = $contractId > 0 ? $this->contractRepository->findById($contractId) : null;
        if (!is_array($contract)
            || (string) ($contract['tipo_aceite'] ?? '') !== 'upgrade_migracao'
            || $this->sanitizeLogin((string) ($contract['mkauth_login'] ?? '')) !== $login
        ) {
            Flash::set('error', 'Não foi possível localizar o Upgrade / Migração informado.');
            return Response::redirect($login !== '' ? $returnTo : '/clientes');
        }

        if ((string) ($contract['lifecycle_status'] ?? 'active') !== 'active') {
            Flash::set('error', 'A execução técnica está bloqueada porque este processo foi cancelado ou substituído.');
            return Response::redirect($returnTo);
        }

        $acceptance = $this->contractAcceptanceRepository->findLatestByContractId($contractId);
        if (!is_array($acceptance)
            || (string) ($acceptance['status'] ?? '') !== 'aceito'
            || trim((string) ($acceptance['revoked_at'] ?? '')) !== ''
        ) {
            Flash::set('error', 'A execução técnica só pode ser confirmada após o aceite digital válido do cliente.');
            return Response::redirect($returnTo);
        }

        $checklistKeys = [
            'plan_checked',
            'technology_changed',
            'pppoe_validated',
            'client_connected',
            'speed_checked',
            'monthly_value_checked',
        ];
        $checklist = ['acceptance_completed' => true];
        foreach ($checklistKeys as $key) {
            $checklist[$key] = (string) $request->input($key, '') === '1';
        }

        $checkedCount = count(array_filter($checklistKeys, static fn (string $key): bool => !empty($checklist[$key])));
        $allChecked = $checkedCount === count($checklistKeys);
        $requestedCompletion = (string) $request->input('action', 'save') === 'complete';
        if ($requestedCompletion && !$allChecked) {
            Flash::set('error', 'Conclua todos os itens do checklist antes de confirmar a execução técnica.');
            return Response::redirect($returnTo);
        }

        $snapshot = $this->extractUpgradeSnapshot($contract);
        $technician = $this->resolveTechnicianIdentity();
        $now = date('Y-m-d H:i:s');
        $technicalStatus = $allChecked ? 'concluido' : ($checkedCount > 0 ? 'execucao_tecnica_parcial' : 'aguardando_execucao_tecnica');
        $snapshot['technical_status'] = $technicalStatus;
        $snapshot['technical_checklist'] = $checklist;
        $snapshot['technical_observation'] = trim((string) $request->input('technical_observation', ''));
        $snapshot['technical_updated_at'] = $now;
        $snapshot['technical_updated_by'] = $technician['name'];
        $snapshot['technical_updated_by_login'] = $technician['login'];
        if ($allChecked) {
            $snapshot['technical_completed_at'] = $now;
        } else {
            unset($snapshot['technical_completed_at']);
        }

        $this->contractRepository->updateUpgradeSnapshot($contractId, $snapshot);

        try {
            $task = $this->financialTaskRepository->findByContractId($contractId);
            if (is_array($task) && isset($task['id'])) {
                $taskId = (int) $task['id'];
                $this->financialTaskRepository->updateStatus($taskId, $allChecked ? 'concluido' : 'em_andamento');
                if ($allChecked) {
                    $this->financialTaskRepository->updateTicketMetadata($taskId, [
                        'completed_at' => $now,
                        'completed_by' => $technician['login'],
                    ]);
                }
            }
        } catch (\Throwable $exception) {
            $this->recordAudit('contract.upgrade.technical_task_sync_failed', 'client_contract', $contractId, [
                'login' => $login,
                'error' => $exception->getMessage(),
            ], $request);
        }

        $this->recordAudit(
            $allChecked ? 'contract.upgrade.technical_completed' : 'contract.upgrade.technical_partial',
            'client_contract',
            $contractId,
            [
                'login' => $login,
                'acceptance_id' => (int) ($acceptance['id'] ?? 0),
                'technical_status' => $technicalStatus,
                'checklist' => $checklist,
                'observation' => $snapshot['technical_observation'],
                'responsible_name' => $technician['name'],
                'responsible_login' => $technician['login'],
            ],
            $request
        );

        Flash::set(
            $allChecked ? 'success' : 'warning',
            $allChecked
                ? 'Execução técnica concluída e registrada. Nenhuma alteração automática foi feita no MkAuth.'
                : 'Checklist parcial salvo. O Upgrade / Migração continua pendente.'
        );

        return Response::redirect($returnTo);
    }

    public function create(Request $request): Response
    {
        if (($denied = $this->guardClientCreationAccess()) !== null) {
            return $denied;
        }

        $cities = $this->loadCities();
        $draftId = trim((string) $request->query('draft', ''));
        $checkpointToken = trim((string) $request->query('token', ''));
        $formData = $this->loadFormDraft();
        $draftMedia = [];

        if ($draftId !== '') {
            $draftRecord = $this->loadDraftRecord($draftId);

            if (is_array($draftRecord)) {
                $formData = is_array($draftRecord['data'] ?? null) ? $draftRecord['data'] : $formData;
                $draftMedia = is_array($draftRecord['media'] ?? null) ? $draftRecord['media'] : [];
                $checkpointToken = trim((string) ($draftRecord['checkpoint_token'] ?? $checkpointToken));
            }
        } elseif ($checkpointToken !== '' && $this->isValidCheckpointToken($checkpointToken)) {
            $checkpoint = $this->loadInstallationCheckpoint($checkpointToken);
            if (is_array($checkpoint)) {
                $checkpointFormData = $this->extractFormDataFromCheckpoint($checkpoint);
                if ($checkpointFormData !== []) {
                    $formData = $checkpointFormData;
                }
                $draftId = $checkpointToken;
            }
        }

        $dueDays = $this->loadDueDays();
        $draftKey = $draftId !== '' ? 'client-create-' . $draftId : 'client-create';
        $clearDraftKeys = $this->consumeClearDraftKeys();
        $skipDraftRestore = $draftId === '' && $checkpointToken === '' && $clearDraftKeys !== [];

        $html = $this->view->render('clients/create', [
            'pageTitle' => 'Novo Cliente',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveViewUser(),
            'flash' => Flash::get(),
            'cities' => $cities,
            'form' => $formData,
            'draftId' => $draftId,
            'checkpointToken' => $checkpointToken,
            'draftMedia' => $draftMedia,
            'draftKey' => $draftKey,
            'clearDraftKeys' => $clearDraftKeys,
            'skipDraftRestore' => $skipDraftRestore,
            'plans' => $this->loadPlans(),
            'dueDays' => $dueDays,
            'defaultDueDay' => $this->suggestDueDay($dueDays),
            'defaultPassword' => '13v0',
            'defaultLocalDici' => 'r',
            'defaultInstallType' => 'fibra',
            'contractCommercial' => $this->contractCommercialConfig(),
        ]);

        return Response::html($html);
    }

    public function store(Request $request): Response
    {
        if (($denied = $this->guardClientCreationAccess()) !== null) {
            return $denied;
        }

        $draftId = trim((string) $request->input('draft_id', ''));
        $checkpointToken = trim((string) $request->input('checkpoint_token', $request->query('token', '')));
        $editingCheckpoint = $checkpointToken !== '' && $this->isValidCheckpointToken($checkpointToken);
        $originalCheckpoint = $editingCheckpoint ? $this->loadInstallationCheckpoint($checkpointToken) : null;
        $originalFormData = is_array($originalCheckpoint['form_data'] ?? null) ? $originalCheckpoint['form_data'] : [];
        $existingDraftRecord = $draftId !== '' ? $this->loadDraftRecord($draftId) : null;
        $existingDraftPhotos = 0;

        if (is_array($existingDraftRecord) && is_array($existingDraftRecord['media']['photos'] ?? null)) {
            $existingDraftPhotos = count($existingDraftRecord['media']['photos']);
        }

        $uploadedPhotoCount = $this->countUploadedPhotos($request);
        $hasExistingPhotos = $existingDraftPhotos > 0 || ($editingCheckpoint && $this->hasStoredEvidencePhotos((string) ($originalCheckpoint['evidence_ref'] ?? '')));
        $data = $this->collectFormData($request);
        $this->saveFormDraft($data, $draftId !== '' ? $draftId : null, $editingCheckpoint ? $checkpointToken : null);
        $errors = $this->validateDraft($data, $request, $originalFormData, $editingCheckpoint, $hasExistingPhotos);

        if ($errors !== []) {
            Flash::set('error', implode(' ', $errors));
            $redirect = '/clientes/novo';
            if ($draftId !== '') {
                $redirect .= '?draft=' . rawurlencode($draftId);
            } elseif ($editingCheckpoint) {
                $redirect .= '?token=' . rawurlencode($checkpointToken);
            }

            return Response::redirect($redirect);
        }

        $draftId = $this->saveDraft($data, $draftId !== '' ? $draftId : null, $editingCheckpoint ? $checkpointToken : null);
        try {
            $this->storeDraftPhotos($draftId, $request, $uploadedPhotoCount > 0 && ($existingDraftPhotos > 0 || $editingCheckpoint));
        } catch (\Throwable $exception) {
            Flash::set('error', 'Nao foi possivel salvar as fotos agora: ' . $exception->getMessage());
            return Response::redirect('/clientes/novo?draft=' . rawurlencode($draftId));
        }

        Flash::set('success', 'Dados iniciais e fotos salvos. Agora confirme o aceite e conclua a assinatura local ou remota.');

        $redirect = '/clientes/novo/aceite?draft=' . rawurlencode($draftId);
        if ($editingCheckpoint) {
            $redirect .= '&token=' . rawurlencode($checkpointToken);
        }

        return Response::redirect($redirect);
    }

    public function clearDraft(Request $request): Response
    {
        if (($denied = $this->guardClientCreationAccess(true)) !== null) {
            return $denied;
        }

        $key = trim((string) $request->input('key', ''));

        if (str_starts_with($key, 'client-create') || str_starts_with($key, 'client-acceptance') || str_starts_with($key, 'client-edit')) {
            $this->clearFormDraft();
        }

        return Response::json([
            'status' => 'success',
            'key' => $key,
        ]);
    }

    public function acceptance(Request $request): Response
    {
        if (($denied = $this->guardClientCreationAccess()) !== null) {
            return $denied;
        }

        $draftId = trim((string) $request->query('draft', ''));
        $draftRecord = $this->loadDraftRecord($draftId);
        $draft = $this->loadDraft($draftId);
        $checkpointToken = '';
        $detectedEmail = strtolower(trim((string) ($draft['email_original'] ?? $draft['email'] ?? '')));
        $hasRealEmail = $detectedEmail !== '' && $detectedEmail !== 'cliente@ievo.com.br';
        $detectedPhone = preg_replace('/\D+/', '', (string) ($draft['celular'] ?? '')) ?? '';

        if (is_array($draftRecord)) {
            $checkpointToken = trim((string) ($draftRecord['checkpoint_token'] ?? ''));
        }

        if ($draft === null) {
            Flash::set('error', 'Nao foi possivel localizar os dados iniciais do cliente.');
            return Response::redirect('/clientes/novo');
        }

        $html = $this->view->render('clients/acceptance', [
            'pageTitle' => 'Aceite do Cliente',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveViewUser(),
            'flash' => Flash::get(),
            'draftId' => $draftId,
            'draft' => $draft,
            'draftJson' => json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'checkpointToken' => $checkpointToken,
            'acceptanceDateTime' => date('d/m/Y H:i'),
            'acceptanceDateIso' => date('Y-m-d H:i:s'),
            'detectedEmail' => $detectedEmail,
            'hasRealEmail' => $hasRealEmail,
            'detectedPhone' => $detectedPhone,
            'providerName' => $this->resolveProviderDisplayName(),
        ]);

        return Response::html($html);
    }

    public function finalize(Request $request): Response
    {
        if (($denied = $this->guardClientCreationAccess()) !== null) {
            return $denied;
        }

        $draftId = trim((string) $request->input('draft_id', $request->query('draft', '')));
        $checkpointToken = trim((string) $request->input('checkpoint_token', $request->query('token', '')));
        $editingCheckpoint = $checkpointToken !== '' && $this->isValidCheckpointToken($checkpointToken);
        $existingCheckpoint = $editingCheckpoint ? $this->loadInstallationCheckpoint($checkpointToken) : null;
        $existingEvidenceRef = is_array($existingCheckpoint) ? trim((string) ($existingCheckpoint['evidence_ref'] ?? '')) : '';
        $draft = $this->loadDraft($draftId);

        if ($draft === null) {
            Flash::set('error', 'A sessão do aceite expirou. Refaça o cadastro inicial.');
            return Response::redirect('/clientes/novo');
        }

        $acceptanceData = $this->collectAcceptanceData($request);
        $data = array_merge($draft, $acceptanceData);
        $emailContext = $this->resolveEmailContext($draft);
        $data = array_merge($data, $emailContext);
        $errors = $this->validateAcceptance($data, $request);
        $sendWhatsapp = $this->normalizeBoolean((string) $request->input('send_whatsapp', '1'));
        $sendEmail = $this->normalizeBoolean((string) $request->input('send_email', '0'));
        $sendRequestId = trim((string) $request->input('send_request_id', ''));

        if ($errors !== []) {
            Flash::set('error', implode(' ', $errors));
            $redirect = '/clientes/novo/aceite?draft=' . rawurlencode($draftId);
            if ($editingCheckpoint) {
                $redirect .= '&token=' . rawurlencode($checkpointToken);
            }

            return Response::redirect($redirect);
        }

        $hasRealEmail = (bool) ($data['has_real_email'] ?? false);
        if (!$hasRealEmail) {
            $sendEmail = false;
            $sendWhatsapp = true;
        }

        if (!$sendWhatsapp && !$sendEmail) {
            Flash::set('error', $hasRealEmail
                ? 'Selecione pelo menos um canal de envio.'
                : 'Sem e-mail real, o WhatsApp precisa ficar marcado para envio.');
            return Response::redirect('/clientes/novo/aceite?draft=' . rawurlencode($draftId));
        }

        if ($sendEmail && !$hasRealEmail) {
            Flash::set('error', 'Cliente nao informou e-mail real. O envio por e-mail nao esta disponivel para este aceite.');
            return Response::redirect('/clientes/novo/aceite?draft=' . rawurlencode($draftId));
        }

        if ($sendRequestId !== '' && $this->isDuplicateManualSend('client:' . $draftId . ':acceptance', $sendRequestId)) {
            Flash::set('warning', 'Este clique ja foi processado. Recarregue a pagina para reenviar mesmo assim.');
            return Response::redirect('/clientes/novo/aceite?draft=' . rawurlencode($draftId));
        }

        try {
            $evidence = $this->storeAcceptanceEvidence($draftId, $data, $request, $existingEvidenceRef !== '' ? $existingEvidenceRef : null);
            $data['evidence_ref'] = basename((string) ($evidence['folder'] ?? ''));
            $data['evidence_url'] = $this->absoluteUrl($request, '/clientes/evidencias?ref=' . rawurlencode((string) $data['evidence_ref']));
            $data['cadastro'] = date('Y-m-d');
            $provisionResult = $this->provisioner->provision($data);
            $response = $provisionResult['response'];
            $payload = $provisionResult['payload'];
            $action = (string) ($provisionResult['action'] ?? 'create');
        } catch (\Throwable $exception) {
            $loginValue = (string) ($data['login'] ?? '');
            $cpfValue = (string) ($data['cpf_cnpj'] ?? '');

            if ($exception->getMessage() === MkAuthWriteGuard::BLOCKED_MESSAGE) {
                $this->recordAudit('client.provision_blocked', 'client_registration', null, [
                    'login' => $loginValue,
                    'error' => $exception->getMessage(),
                ], $request);
                Flash::set('error', MkAuthWriteGuard::BLOCKED_MESSAGE . ' Os dados locais foram mantidos para nova tentativa.');

                return Response::redirect('/clientes/novo/aceite?draft=' . rawurlencode($draftId));
            }

            $clientAlreadyExists = false;

            try {
                $clientAlreadyExists = $this->hasClientMatchByApi('login', $loginValue) || ($cpfValue !== '' && $this->hasClientMatchByApi('cpf_cnpj', $cpfValue));
            } catch (\Throwable) {
                $clientAlreadyExists = false;
            }

            if (!$clientAlreadyExists) {
                $this->recordAudit('client.provision_failed', 'client_registration', null, [
                    'login' => $loginValue,
                    'error' => $exception->getMessage(),
                ], $request);
                Flash::set(
                    'error',
                    'Nao foi possivel concluir o envio ao MkAuth agora: ' . $exception->getMessage() . ' Seus dados foram mantidos para nova tentativa.'
                );

                return Response::redirect('/clientes/novo/aceite?draft=' . rawurlencode($draftId));
            }

            $data['evidence_ref'] = $data['evidence_ref'] ?? $existingEvidenceRef;
            $data['evidence_url'] = $data['evidence_url'] ?? ($existingEvidenceRef !== ''
                ? $this->absoluteUrl($request, '/clientes/evidencias?ref=' . rawurlencode($existingEvidenceRef))
                : '');
            $data['cadastro'] = date('Y-m-d');
            $response = [
                'status' => 'sucesso',
                'mensagem' => 'Cliente já localizado no MkAuth. O fluxo local foi retomado após o aviso remoto.',
            ];
            $payload = [
                'login' => $loginValue,
                'nome' => (string) ($data['nome_completo'] ?? ''),
                'cpf_cnpj' => $cpfValue,
                'plano' => (string) ($data['plano'] ?? ''),
            ];
            $action = 'create';
            $postProvisionWarnings[] = 'MkAuth retornou aviso, mas o cliente já existe no remoto e o fluxo foi retomado.';
            $this->recordAudit('client.provision_recovered', 'client_registration', null, [
                'login' => $loginValue,
                'error' => $exception->getMessage(),
            ], $request);
        }

        $this->deleteDraftMedia($draftId);
        $this->clearClientDraft($draftId);
        $this->clearFormDraft();
        $_SESSION['clear_client_drafts'] = ['client-create', 'client-create-' . $draftId, 'client-acceptance-' . $draftId];

        $message = $response['mensagem'] ?? 'Cliente provisionado com sucesso.';
        $successLabel = $action === 'update' ? 'Cliente atualizado com sucesso.' : 'Cliente cadastrado com sucesso.';
        $connectionToken = $editingCheckpoint ? $checkpointToken : bin2hex(random_bytes(16));
        $this->syncContractArtifacts($data, $payload, null, $request);
        $registrationId = $this->recordClientRegistration($data, $payload, $connectionToken);
        $this->recordEvidenceFiles($registrationId, (string) ($data['evidence_ref'] ?? ''), (string) ($evidence['folder'] ?? ''));
        $this->syncContractArtifacts($data, $payload, $registrationId, $request);
        $contractRecord = $registrationId !== null ? $this->contractRepository->findByClientId($registrationId) : null;
        $acceptanceRecord = is_array($contractRecord) && isset($contractRecord['id'])
            ? $this->contractAcceptanceRepository->findLatestByContractId((int) $contractRecord['id'])
            : null;
        $integrationResults = [];
        $postProvisionWarnings = [];

        if ($contractRecord !== null && $acceptanceRecord !== null) {
            try {
                $integrationResults = $this->dispatchAcceptanceChannels(
                    $contractRecord,
                    $acceptanceRecord,
                    $data,
                    $sendWhatsapp,
                    $sendEmail,
                    false,
                    $request
                );
            } catch (\Throwable $exception) {
                $integrationResults = [];
                $postProvisionWarnings[] = 'Falha ao registrar o envio do aceite: ' . $exception->getMessage();
                $this->recordAudit('client.acceptance.dispatch_failed', 'client_acceptance', (int) ($acceptanceRecord['id'] ?? null), [
                    'contract_id' => (int) ($contractRecord['id'] ?? 0),
                    'error' => $exception->getMessage(),
                ], $request);
            }
        }

        $acceptanceSendSummary = '';
        $acceptanceSendHasError = false;
        if ($integrationResults !== []) {
            $summaryParts = [];
            foreach ($integrationResults as $channel => $result) {
                $label = match ($channel) {
                    'whatsapp' => 'WhatsApp',
                    'email' => 'E-mail',
                    default => ucfirst((string) $channel),
                };
                $status = !empty($result['repeated_attempt']) ? 'duplicado bloqueado' : (string) ($result['status'] ?? 'simulado');
                if ($status === 'erro') {
                    $acceptanceSendHasError = true;
                }
                $summaryParts[] = $label . ': ' . $status;
            }

            $acceptanceSendSummary = ' Envios do aceite: ' . implode(' · ', $summaryParts);
        }

        try {
            $connectionToken = $this->saveInstallationCheckpoint([
                'status' => 'awaiting_connection',
                'login' => (string) ($payload['login'] ?? ''),
                'client_name' => (string) ($payload['nome'] ?? $data['nome_completo'] ?? ''),
                'plan' => (string) ($payload['plano'] ?? $data['plano'] ?? ''),
                'form_data' => $draft,
                'telefone_original' => (string) ($data['telefone_original'] ?? $data['celular'] ?? ''),
                'telefone_cliente' => (string) ($data['telefone_cliente'] ?? $data['celular'] ?? ''),
                'email_original' => (string) ($data['email_original'] ?? ''),
                'email_cliente' => (string) ($data['email_cliente'] ?? $data['email'] ?? ''),
                'has_real_email' => (bool) ($data['has_real_email'] ?? false),
                'contact_corrections' => [],
                'created_at' => date('Y-m-d H:i:s'),
                'created_by' => (string) ($this->resolveUser()['name'] ?? 'Operador'),
                'evidence_ref' => (string) ($data['evidence_ref'] ?? ''),
                'mkauth_message' => (string) $message,
            ], $registrationId, $connectionToken);
        } catch (\Throwable $exception) {
            $postProvisionWarnings[] = 'Nao foi possivel salvar o checkpoint local agora: ' . $exception->getMessage();
            $this->recordAudit('client.checkpoint.save_failed', 'installation_checkpoint', null, [
                'login' => (string) ($payload['login'] ?? ''),
                'error' => $exception->getMessage(),
            ], $request);
        }
        $this->recordAudit('client.provisioned', 'client_registration', $registrationId, [
            'login' => (string) ($payload['login'] ?? ''),
            'action' => $action,
            'mkauth_status' => (string) ($response['status'] ?? ''),
            'acceptance_send' => $integrationResults,
        ], $request);

        $flashType = ($response['status'] ?? 'sucesso') === 'sucesso' || ($response['status'] ?? '') === 'simulado' ? 'success' : 'error';
        if ($acceptanceSendHasError && $flashType === 'success') {
            $flashType = 'warning';
        }

        Flash::set(
            $flashType,
            $successLabel . ' ' . $message . ' Login: ' . ($payload['login'] ?? '-') . ' Agora valide a conexão do equipamento.' . ($evidence['summary'] !== '' ? ' ' . $evidence['summary'] : '') . $acceptanceSendSummary . (empty($postProvisionWarnings) ? '' : ' ' . implode(' ', $postProvisionWarnings))
        );

        return Response::redirect('/clientes/conexao?token=' . rawurlencode($connectionToken));
    }

    public function connection(Request $request): Response
    {
        if (($denied = $this->guardClientCreationAccess()) !== null) {
            return $denied;
        }

        $token = trim((string) $request->query('token', ''));
        $record = $this->loadInstallationCheckpoint($token);

        if ($record === null) {
            Flash::set('error', 'Nao foi possivel localizar a instalação para validar conexão.');
            return Response::redirect('/clientes/novo');
        }

        $connection = $this->resolveRadiusConnection((string) ($record['login'] ?? ''));
        $acceptanceStatus = $this->resolveAcceptanceStatusForLogin((string) ($record['login'] ?? ''));
        $statusMessage = 'Status do aceite: ' . (string) ($acceptanceStatus['label'] ?? 'pendente') . '.';
        $flash = Flash::get();

        if (is_array($flash)) {
            $flash['message'] = trim((string) ($flash['message'] ?? '') . ' ' . $statusMessage);
        } else {
            $flash = [
                'type' => !empty($acceptanceStatus['accepted']) ? 'success' : 'error',
                'message' => $statusMessage,
            ];
        }

        $html = $this->view->render('clients/connection', [
            'pageTitle' => 'Validar Conexão',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveViewUser(),
            'flash' => $flash,
            'token' => $token,
            'record' => $record,
            'connection' => $connection,
            'acceptanceStatus' => $acceptanceStatus,
        ]);

        return Response::html($html);
    }

    public function completeConnection(Request $request): Response
    {
        if (($denied = $this->guardClientCreationAccess()) !== null) {
            return $denied;
        }

        $token = trim((string) $request->input('token', ''));
        $record = $this->loadInstallationCheckpoint($token);

        if ($record === null) {
            Flash::set('error', 'Nao foi possivel localizar a instalação para finalizar.');
            return Response::redirect('/clientes/novo');
        }

        $acceptanceStatus = $this->resolveAcceptanceStatusForLogin((string) ($record['login'] ?? ''));
        $acceptanceState = (string) ($acceptanceStatus['status'] ?? 'pendente');

        if ($acceptanceState !== 'aceito') {
            $statusLabel = (string) ($acceptanceStatus['label'] ?? 'pendente');
            Flash::set('error', 'Cliente ainda não concluiu o aceite. Status atual: ' . $statusLabel . '.');
            return Response::redirect('/clientes/conexao?token=' . rawurlencode($token));
        }

        $connection = $this->resolveRadiusConnection((string) ($record['login'] ?? ''));
        $this->updateInstallationCheckpoint($token, [
            'last_connection_check_at' => date('Y-m-d H:i:s'),
            'last_connection' => $connection,
        ]);

        if (empty($connection['online'])) {
            Flash::set('error', 'O login ainda não aparece conectado no Radius. Confira o equipamento do cliente e tente novamente.');
            return Response::redirect('/clientes/conexao?token=' . rawurlencode($token));
        }

        $this->updateInstallationCheckpoint($token, [
            'status' => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
            'completed_by' => (string) ($this->resolveUser()['name'] ?? 'Operador'),
        ]);
        $this->recordAudit('installation.completed', 'installation_checkpoint', null, [
            'token' => $token,
            'login' => (string) ($record['login'] ?? ''),
        ], $request);

        Flash::set('success', 'Instalação finalizada. O login está conectado no Radius.');

        return Response::redirect('/clientes/conexao?token=' . rawurlencode($token));
    }

    public function correctContactAndResend(Request $request): Response
    {
        if (($denied = $this->guardClientCreationAccess()) !== null) {
            return $denied;
        }

        $token = trim((string) $request->input('token', $request->query('token', '')));
        $record = $this->loadInstallationCheckpoint($token);

        if ($record === null) {
            Flash::set('error', 'Nao foi possivel localizar a instalação para corrigir o contato.');
            return Response::redirect('/clientes/novo');
        }

        $login = trim((string) ($record['login'] ?? ''));
        $acceptanceStatus = $this->resolveAcceptanceStatusForLogin($login);
        $acceptanceState = (string) ($acceptanceStatus['status'] ?? 'pendente');
        $acceptance = is_array($acceptanceStatus['acceptance'] ?? null) ? $acceptanceStatus['acceptance'] : null;
        $contract = is_array($acceptanceStatus['contract'] ?? null) ? $acceptanceStatus['contract'] : null;

        if ($acceptanceState === 'aceito') {
            Flash::set('error', 'O aceite já foi concluído. A correção de contato fica bloqueada após a assinatura.');
            return Response::redirect('/clientes/conexao?token=' . rawurlencode($token));
        }

        if (!is_array($acceptance) || !isset($acceptance['id']) || !is_array($contract) || !isset($contract['id'])) {
            Flash::set('error', 'Nao foi possivel localizar o contrato ou o aceite para essa instalação.');
            return Response::redirect('/clientes/conexao?token=' . rawurlencode($token));
        }

        $contactCorrections = $this->extractContactCorrections($record);
        $user = $this->resolveViewUser();

        if (count($contactCorrections) >= 2) {
            Flash::set('error', 'Limite de correções atingido. Solicite apoio de um gestor.');
            return Response::redirect('/clientes/conexao?token=' . rawurlencode($token));
        }

        $currentDraft = is_array($record['form_data'] ?? null) ? $record['form_data'] : [];
        $currentPhone = preg_replace('/\D+/', '', (string) ($record['telefone_cliente'] ?? $currentDraft['telefone_cliente'] ?? $currentDraft['celular'] ?? $contract['telefone_cliente'] ?? '')) ?? '';
        $currentEmailContext = $this->resolveEmailContext(array_merge($contract, $currentDraft, $record));
        $currentEmail = (string) ($currentEmailContext['email_cliente'] ?? '');
        $currentEmailOriginal = (string) ($currentEmailContext['email_original'] ?? '');

        $newPhoneInput = trim((string) $request->input('new_whatsapp', ''));
        $newEmailInput = strtolower(trim((string) $request->input('new_email', '')));
        $reason = trim((string) $request->input('correction_reason', ''));
        $sendWhatsapp = $this->normalizeBoolean((string) $request->input('send_whatsapp', '0'));
        $sendEmail = $this->normalizeBoolean((string) $request->input('send_email', '0'));

        if ($reason === '') {
            Flash::set('error', 'Informe o motivo da correção antes de reenviar.');
            return Response::redirect('/clientes/conexao?token=' . rawurlencode($token));
        }

        if (!$sendWhatsapp && !$sendEmail) {
            Flash::set('error', 'Selecione WhatsApp, e-mail ou ambos antes de corrigir o contato.');
            return Response::redirect('/clientes/conexao?token=' . rawurlencode($token));
        }

        $updatedPhone = $currentPhone;
        $updatedEmail = $currentEmail !== '' ? $currentEmail : 'cliente@ievo.com.br';
        $phoneWillChange = false;
        $emailWillChange = false;

        if ($newPhoneInput !== '') {
            $phoneDigits = preg_replace('/\D+/', '', $newPhoneInput) ?? '';
            if (!$this->isValidPhone($phoneDigits)) {
                Flash::set('error', 'Informe um novo WhatsApp válido com DDD.');
                return Response::redirect('/clientes/conexao?token=' . rawurlencode($token));
            }

            if ($phoneDigits === $currentPhone) {
                Flash::set('error', 'O novo WhatsApp precisa ser diferente do contato atual.');
                return Response::redirect('/clientes/conexao?token=' . rawurlencode($token));
            }

            $updatedPhone = $phoneDigits;
            $phoneWillChange = true;
        } elseif ($sendWhatsapp) {
            Flash::set('error', 'Informe um novo WhatsApp válido para reenviar o aceite.');
            return Response::redirect('/clientes/conexao?token=' . rawurlencode($token));
        }

        if ($newEmailInput !== '') {
            if (!$this->isValidEmail($newEmailInput) || $newEmailInput === 'cliente@ievo.com.br') {
                Flash::set('error', 'Informe um novo e-mail válido para o aceite.');
                return Response::redirect('/clientes/conexao?token=' . rawurlencode($token));
            }

            if ($newEmailInput === $currentEmail) {
                Flash::set('error', 'O novo e-mail precisa ser diferente do contato atual.');
                return Response::redirect('/clientes/conexao?token=' . rawurlencode($token));
            }

            $updatedEmail = $newEmailInput;
            $emailWillChange = true;
        } elseif ($sendEmail) {
            Flash::set('error', 'Informe um novo e-mail válido para reenviar o aceite.');
            return Response::redirect('/clientes/conexao?token=' . rawurlencode($token));
        }

        if ($updatedEmail === '' || $updatedEmail === 'cliente@ievo.com.br') {
            if (!$sendWhatsapp) {
                Flash::set('error', 'Sem e-mail real, o WhatsApp precisa ficar marcado para envio.');
                return Response::redirect('/clientes/conexao?token=' . rawurlencode($token));
            }
        }

        if (!$phoneWillChange && !$emailWillChange) {
            Flash::set('error', 'Altere ao menos um contato para registrar a correção.');
            return Response::redirect('/clientes/conexao?token=' . rawurlencode($token));
        }

        $correctedDraft = $currentDraft;
        $correctedDraft['telefone_original'] = (string) ($record['telefone_original'] ?? $currentPhone);
        $correctedDraft['telefone_cliente'] = $updatedPhone;
        $correctedDraft['celular'] = $updatedPhone;
        $correctedDraft['email_original'] = (string) ($record['email_original'] ?? $currentEmailOriginal);
        $correctedDraft['email_cliente'] = $updatedEmail;
        $correctedDraft['email'] = $updatedEmail;
        $correctedDraft['has_real_email'] = $updatedEmail !== '' && $updatedEmail !== 'cliente@ievo.com.br';

        $correctionEntry = [
            'original_whatsapp' => $currentPhone,
            'corrected_whatsapp' => $updatedPhone,
            'original_email' => $currentEmail,
            'corrected_email' => $updatedEmail,
            'channels' => array_values(array_filter([
                $sendWhatsapp ? 'whatsapp' : null,
                $sendEmail ? 'email' : null,
            ])),
            'reason' => $reason,
            'technician' => (string) ($user['name'] ?? $user['login'] ?? 'Operador'),
            'technician_login' => (string) ($user['login'] ?? ''),
            'login' => $login,
            'contract_id' => (int) $contract['id'],
            'acceptance_id' => (int) $acceptance['id'],
            'ip_address' => (string) $request->server('REMOTE_ADDR', ''),
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $contactCorrections[] = $correctionEntry;

        $record = array_replace($record, [
            'telefone_original' => (string) ($record['telefone_original'] ?? $currentPhone),
            'telefone_cliente' => $updatedPhone,
            'email_original' => (string) ($record['email_original'] ?? $currentEmailOriginal),
            'email_cliente' => $updatedEmail,
            'has_real_email' => $updatedEmail !== '' && $updatedEmail !== 'cliente@ievo.com.br',
            'form_data' => $correctedDraft,
            'contact_corrections' => $contactCorrections,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        try {
            $this->saveInstallationCheckpoint(
                $record,
                isset($record['registration_id']) ? (int) $record['registration_id'] : null,
                $token
            );
        } catch (\Throwable $exception) {
            Flash::set('error', 'Nao foi possivel salvar a correção local agora: ' . $exception->getMessage());
            return Response::redirect('/clientes/conexao?token=' . rawurlencode($token));
        }

        $this->recordAudit('client.contact.corrected', 'installation_checkpoint', null, [
            'token' => $token,
            'login' => $login,
            'contract_id' => (int) $contract['id'],
            'acceptance_id' => (int) $acceptance['id'],
            'original_whatsapp' => $currentPhone,
            'corrected_whatsapp' => $updatedPhone,
            'original_email' => $currentEmail,
            'corrected_email' => $updatedEmail,
            'channels' => $correctionEntry['channels'],
            'reason' => $reason,
            'technician' => $correctionEntry['technician'],
            'ip_address' => $correctionEntry['ip_address'],
        ], $request);

        $correctionTicketSummary = null;
        try {
            $ticketResponse = $this->mkAuthTicketService->openFinancialTicket(
                $this->buildCorrectionTicketPayload($correctedDraft, $contract, $correctionEntry)
            );
            $correctionTicketSummary = sprintf(
                'HTTP %s · %sms%s',
                (string) ($ticketResponse['http_status'] ?? '-'),
                (string) ($ticketResponse['duration_ms'] ?? 0),
                $this->resolveTicketId($ticketResponse) !== null ? ' · ID ' . $this->resolveTicketId($ticketResponse) : ''
            );
            $this->recordAudit('client.contact.correction.ticket.created', 'installation_checkpoint', null, [
                'token' => $token,
                'login' => $login,
                'contract_id' => (int) $contract['id'],
                'acceptance_id' => (int) $acceptance['id'],
                'response' => $ticketResponse,
            ], $request);

            if (!empty($ticketResponse['message_fallback_used']) && !empty($ticketResponse['sis_msg_id'])) {
                $this->recordAudit('client.contact.correction.ticket.message_inserted', 'installation_checkpoint', null, [
                    'token' => $token,
                    'login' => $login,
                    'contract_id' => (int) $contract['id'],
                    'acceptance_id' => (int) $acceptance['id'],
                    'ticket_id' => $this->resolveTicketId($ticketResponse),
                    'sis_msg_id' => (int) $ticketResponse['sis_msg_id'],
                    'fallback_status' => (string) ($ticketResponse['message_fallback_status'] ?? ''),
                ], $request);
            } elseif (($ticketResponse['message_fallback_status'] ?? '') === 'failed') {
                $this->recordAudit('client.contact.correction.ticket.message_failed', 'installation_checkpoint', null, [
                    'token' => $token,
                    'login' => $login,
                    'contract_id' => (int) $contract['id'],
                    'acceptance_id' => (int) $acceptance['id'],
                    'ticket_id' => $this->resolveTicketId($ticketResponse),
                    'error' => (string) ($ticketResponse['message_fallback_error'] ?? ''),
                ], $request);
            }
        } catch (\Throwable $exception) {
            $this->recordAudit('client.contact.correction.ticket.failed', 'installation_checkpoint', null, [
                'token' => $token,
                'login' => $login,
                'contract_id' => (int) $contract['id'],
                'acceptance_id' => (int) $acceptance['id'],
                'error' => $exception->getMessage(),
            ], $request);
        }

        $integrationResults = [];
        try {
            $integrationResults = $this->dispatchAcceptanceChannels(
                $contract,
                $acceptance,
                $correctedDraft,
                $sendWhatsapp,
                $sendEmail,
                true,
                $request
            );
        } catch (\Throwable $exception) {
            $this->recordAudit('client.contact.correction.dispatch_failed', 'installation_checkpoint', null, [
                'token' => $token,
                'login' => $login,
                'contract_id' => (int) $contract['id'],
                'acceptance_id' => (int) $acceptance['id'],
                'error' => $exception->getMessage(),
            ], $request);
            Flash::set('warning', 'A correção foi salva, mas o reenvio apresentou um aviso: ' . $exception->getMessage());
            return Response::redirect('/clientes/conexao?token=' . rawurlencode($token));
        }

        $summaryParts = [];
        foreach ($integrationResults as $channel => $result) {
            $label = match ($channel) {
                'whatsapp' => 'WhatsApp',
                'email' => 'E-mail',
                default => ucfirst((string) $channel),
            };
            $status = !empty($result['repeated_attempt']) ? 'duplicado bloqueado' : (string) ($result['status'] ?? 'simulado');
            $summaryParts[] = $label . ': ' . $status;
        }

        $statusMessage = 'Correção registrada com sucesso.';
        if ($correctionTicketSummary !== null) {
            $statusMessage .= ' Chamado MkAuth: ' . $correctionTicketSummary . '.';
        }
        if ($summaryParts !== []) {
            $statusMessage .= ' Reenvio: ' . implode(' · ', $summaryParts) . '.';
        }

        Flash::set('success', $statusMessage);

        return Response::redirect('/clientes/conexao?token=' . rawurlencode($token));
    }

    public function resume(Request $request): Response
    {
        if (($denied = $this->guardClientCreationAccess()) !== null) {
            return $denied;
        }

        $token = trim((string) $request->query('token', $request->input('token', '')));
        $login = $this->sanitizeLogin((string) $request->query('login', $request->input('login', '')));

        if ($token !== '') {
            $record = $this->loadInstallationCheckpoint($token);

            if ($record === null) {
                $record = $this->localRepository->findInstallationCheckpointByToken($token);
            }

            if (is_array($record)) {
                return Response::redirect('/clientes/conexao?token=' . rawurlencode($token));
            }
        }

        if ($login === '') {
            Flash::set('error', 'Informe o login do cliente para retomar a pendência.');
            return Response::redirect('/instalacoes');
        }

        $draft = $this->findDraftRecordByLogin($login);
        if (is_array($draft)) {
            $draftId = (string) ($draft['draft_id'] ?? '');
            if ($draftId !== '') {
                return Response::redirect('/clientes/novo/aceite?draft=' . rawurlencode($draftId));
            }
        }

        $checkpoint = $this->findCheckpointRecordByLogin($login);
        if (is_array($checkpoint)) {
            $checkpointToken = trim((string) ($checkpoint['token'] ?? ''));
            if ($checkpointToken !== '') {
                return Response::redirect('/clientes/conexao?token=' . rawurlencode($checkpointToken));
            }
        }

        $checkpoint = $this->localRepository->findLatestInstallationCheckpointByLogin($login);
        if (is_array($checkpoint)) {
            $checkpointToken = trim((string) ($checkpoint['token'] ?? ''));
            if ($checkpointToken !== '') {
                $decodedPayload = json_decode((string) ($checkpoint['payload_json'] ?? ''), true);
                $payload = is_array($decodedPayload) ? $decodedPayload : [];

                $this->saveInstallationCheckpoint(
                    array_replace(
                        [
                            'status' => (string) ($checkpoint['status'] ?? 'awaiting_connection'),
                            'login' => (string) ($checkpoint['mkauth_login'] ?? $login),
                            'client_name' => (string) ($checkpoint['mkauth_login'] ?? $login),
                            'plan' => '',
                            'created_at' => (string) ($checkpoint['created_at'] ?? date('Y-m-d H:i:s')),
                            'created_by' => (string) ($this->resolveUser()['name'] ?? 'Operador'),
                            'evidence_ref' => '',
                            'mkauth_message' => 'Cadastro retomado a partir do banco local.',
                        ],
                        $payload
                    ),
                    isset($checkpoint['registration_id']) ? (int) $checkpoint['registration_id'] : null,
                    $checkpointToken
                );

                return Response::redirect('/clientes/conexao?token=' . rawurlencode($checkpointToken));
            }
        }

        $registration = $this->localRepository->findLatestClientRegistrationByLogin($login);
        if (is_array($registration)) {
            $restoredToken = trim((string) ($registration['radius_token'] ?? ''));
            if ($restoredToken === '') {
                $restoredToken = bin2hex(random_bytes(16));
            }

            $record = [
                'status' => (string) ($registration['status'] ?? 'awaiting_connection'),
                'login' => (string) ($registration['mkauth_login'] ?? $login),
                'client_name' => (string) ($registration['client_name'] ?? ''),
                'plan' => (string) ($registration['plan_name'] ?? ''),
                'created_at' => (string) ($registration['created_at'] ?? date('Y-m-d H:i:s')),
                'created_by' => (string) ($this->resolveUser()['name'] ?? 'Operador'),
                'evidence_ref' => (string) ($registration['evidence_ref'] ?? ''),
                'mkauth_message' => 'Cadastro retomado após timeout.',
            ];

            $this->saveInstallationCheckpoint($record, isset($registration['id']) ? (int) $registration['id'] : null, $restoredToken);

            Flash::set('success', 'Cadastro localizado e retomado. Agora valide a conexão do cliente.');

            return Response::redirect('/clientes/conexao?token=' . rawurlencode($restoredToken));
        }

        Flash::set('error', 'Nao foi possivel localizar uma pendência para esse login.');

        return Response::redirect('/instalacoes');
    }

    public function checkConnection(Request $request): Response
    {
        if (($denied = $this->guardClientCreationAccess(true)) !== null) {
            return $denied;
        }

        $token = trim((string) $request->query('token', ''));
        $login = trim((string) $request->query('login', ''));

        if ($token !== '') {
            $record = $this->loadInstallationCheckpoint($token);
            if ($record !== null) {
                $login = (string) ($record['login'] ?? $login);
            }
        }

        if ($login === '') {
            return Response::json([
                'status' => 'error',
                'message' => 'Informe o login para consultar conexão.',
            ], 422);
        }

        $connection = $this->resolveRadiusConnection($login);

        return Response::json([
            'status' => 'success',
            'login' => $login,
            'online' => (bool) ($connection['online'] ?? false),
            'connection' => $connection,
        ]);
    }

    public function evidence(Request $request): Response
    {
        $ref = trim((string) $request->query('ref', ''));
        $folder = $this->evidenceFolderPath($ref);

        if ($folder === null) {
            return Response::html('Evidências não encontradas.', 404);
        }

        $metadataPath = $folder . '/aceite.json';
        $metadata = is_file($metadataPath)
            ? json_decode((string) file_get_contents($metadataPath), true)
            : [];

        if (!is_array($metadata)) {
            $metadata = [];
        }

        $files = array_values(array_filter(
            scandir($folder) ?: [],
            static fn (string $file): bool => !in_array($file, ['.', '..', 'aceite.json'], true)
        ));

        $html = $this->view->render('clients/evidence', [
            'pageTitle' => 'Evidências do Cadastro',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'layoutMode' => 'guest',
            'user' => $this->resolveViewUser(),
            'ref' => $ref,
            'metadata' => $metadata,
            'files' => $files,
        ]);

        return Response::html($html);
    }

    public function evidenceFile(Request $request): Response
    {
        $ref = trim((string) $request->query('ref', ''));
        $file = basename(trim((string) $request->query('file', '')));
        $folder = $this->evidenceFolderPath($ref);

        if ($folder === null || $file === '' || !is_file($folder . '/' . $file)) {
            return new Response('Arquivo não encontrado.', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $path = $folder . '/' . $file;
        $mime = $this->detectResponseMimeType($path);

        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . addslashes($file) . '"',
        ]);
    }

    public function lookupCep(Request $request): Response
    {
        if (($denied = $this->guardClientCreationAccess(true)) !== null) {
            return $denied;
        }

        $cep = preg_replace('/\D+/', '', (string) $request->query('cep', '')) ?? '';

        if (strlen($cep) !== 8) {
            return Response::json([
                'status' => 'error',
                'message' => 'CEP invalido.',
            ], 422);
        }

        $lookup = $this->resolveCep($cep);

        if ($lookup === null) {
            return Response::json([
                'status' => 'error',
                'message' => 'Nao foi possivel localizar o CEP.',
                'cep' => $cep,
            ], 404);
        }

        return Response::json([
            'status' => 'success',
            'cep' => $cep,
            'cidade' => $lookup['cidade'],
            'estado' => $lookup['uf'],
            'ibge' => $lookup['ibge'],
            'logradouro' => $lookup['logradouro'],
            'bairro' => $lookup['bairro'],
        ]);
    }

    public function validateClientField(Request $request): Response
    {
        if (($denied = $this->guardClientCreationAccess(true)) !== null) {
            return $denied;
        }

        $type = strtolower(trim((string) $request->query('type', '')));
        $value = trim((string) $request->query('value', ''));

        if ($type === '' || $value === '') {
            return Response::json([
                'status' => 'error',
                'message' => 'Informe o tipo e o valor para validacao.',
            ], 422);
        }

        if (!in_array($type, ['login', 'cpf_cnpj'], true)) {
            return Response::json([
                'status' => 'error',
                'message' => 'Tipo de validacao invalido.',
            ], 422);
        }

        if ($type === 'login') {
            $value = $this->normalizeLoginInput($value);
        } else {
            $value = preg_replace('/\D+/', '', $value) ?? '';
        }

        try {
            if ($this->mkauthDatabase->isConfigured()) {
                try {
                    $hasMatch = $type === 'login'
                        ? $this->mkauthDatabase->clientExistsByLogin($value)
                        : $this->mkauthDatabase->clientExistsByCpfCnpj($value);
                } catch (\Throwable $exception) {
                    $hasMatch = $this->hasClientMatchByApi($type, $value);
                }
            } else {
                $hasMatch = $this->hasClientMatchByApi($type, $value);
            }
        } catch (\Throwable $exception) {
            return Response::json([
                'status' => 'error',
                'message' => 'Nao foi possivel consultar o MkAuth agora.',
            ], 503);
        }

        return Response::json([
            'status' => 'success',
            'exists' => $hasMatch,
            'type' => $type,
            'value' => $value,
            'message' => $hasMatch
                ? ($type === 'login' ? 'Login ja existe no MkAuth.' : 'CPF/CNPJ ja existe no MkAuth.')
                : 'Disponivel para uso.',
        ]);
    }

    private function resolveUser(): array
    {
        $user = $_SESSION['user'] ?? [
            'name' => 'Operador',
            'login' => '',
            'role' => 'Operação',
            'source' => 'fallback',
        ];

        $user['login'] = $this->localRepository->normalizeLogin((string) ($user['login'] ?? ''));

        return $user;
    }

    private function resolveViewUser(): array
    {
        $user = $this->resolveUser();
        $access = $this->localRepository->accessProfileForUser($user);
        $user['access'] = $access;
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

    private function canManageContracts(): bool
    {
        $access = $this->localRepository->accessProfileForUser($this->resolveUser());

        return $access['is_manager'] || $access['is_admin'] || !empty($access['can_access_contracts']);
    }

    private function canCreateClient(): bool
    {
        $access = $this->localRepository->accessProfileForUser($this->resolveUser());

        return $access['is_manager'] || $access['is_admin'] || !empty($access['can_create_client']);
    }

    private function guardClientCreationAccess(bool $json = false): ?Response
    {
        if ($this->canCreateClient()) {
            return null;
        }

        $message = 'Seu usuário não possui permissão para iniciar ou retomar o cadastro de clientes.';
        if ($json) {
            return Response::json([
                'status' => 'error',
                'message' => $message,
            ], 403);
        }

        Flash::set('error', $message);

        return Response::redirect('/clientes');
    }

    private function canSearchClients(): bool
    {
        $access = $this->localRepository->accessProfileForUser($this->resolveUser());

        return $access['is_manager'] || $access['is_admin'] || !empty($access['can_search_clients']);
    }

    private function canManageFinancial(): bool
    {
        $access = $this->localRepository->accessProfileForUser($this->resolveUser());

        return $access['is_manager'] || $access['is_admin'] || !empty($access['can_manage_financial']);
    }

    private function canManageSettings(): bool
    {
        $access = $this->localRepository->accessProfileForUser($this->resolveUser());

        return $access['is_manager'] || $access['is_admin'] || !empty($access['can_manage_settings']);
    }

    private function canRequestUpgrade(): bool
    {
        $access = $this->localRepository->accessProfileForUser($this->resolveUser());

        return $access['is_manager'] || $access['is_admin'] || !empty($access['can_upgrade_request']);
    }

    private function canRequestContractSignature(): bool
    {
        $access = $this->localRepository->accessProfileForUser($this->resolveUser());

        return $access['is_manager']
            || $access['is_admin']
            || !empty($access['can_request_contract_signature'])
            || !empty($access['can_resend_contract_acceptance']);
    }

    private function canCompleteUpgradeTechnical(): bool
    {
        $access = $this->localRepository->accessProfileForUser($this->resolveUser());

        return $access['is_manager'] || $access['is_admin'] || !empty($access['can_complete_upgrade_technical']);
    }

    private function canUpgradeCommercial(): bool
    {
        $access = $this->localRepository->accessProfileForUser($this->resolveUser());

        return $access['is_manager'] || $access['is_admin'] || !empty($access['can_upgrade_commercial']);
    }

    private function canCorrectUpgrade(): bool
    {
        $access = $this->localRepository->accessProfileForUser($this->resolveUser());

        return $access['is_manager'] || $access['is_admin'] || !empty($access['can_upgrade_correct']);
    }

    private function canCancelPendingContract(): bool
    {
        $access = $this->localRepository->accessProfileForUser($this->resolveUser());

        return $access['is_manager'] || $access['is_admin'] || !empty($access['can_cancel_pending_contracts']);
    }

    private function canSupersedeContract(): bool
    {
        $access = $this->localRepository->accessProfileForUser($this->resolveUser());

        return $access['is_manager'] || $access['is_admin'] || !empty($access['can_supersede_contracts']);
    }

    private function buildClientHubSearchResults(array $rows): array
    {
        $results = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $login = $this->sanitizeLogin((string) ($row['login'] ?? ''));
            if ($login === '') {
                continue;
            }

            $cpfCnpj = trim((string) ($row['cpf_cnpj'] ?? ''));
            $phone = trim((string) ($row['celular'] ?? $row['fone'] ?? ''));
            $planValue = trim((string) ($row['plano_nome'] ?? $row['plano'] ?? ''));
            $statusVisual = $this->resolveClientStatusVisual($row);
            $results[] = [
                'name' => (string) ($row['nome'] ?? '-'),
                'login' => $login,
                'document' => $cpfCnpj !== '' ? $cpfCnpj : '-',
                'phone' => $phone !== '' ? $phone : '-',
                'plan' => $planValue !== '' ? $planValue : '-',
                'status' => (string) ($statusVisual['label'] ?? 'Outro'),
                'status_visual' => $statusVisual,
                'city' => trim((string) ($row['cidade'] ?? '')),
                'neighborhood' => trim((string) ($row['bairro'] ?? '')),
                'due_day' => trim((string) ($row['venc'] ?? '')),
                'technology' => $this->technologyMapper->label((string) ($row['plano_tecnologia'] ?? '')),
                'email' => trim((string) ($row['email'] ?? '')),
                'address' => trim((string) ($row['endereco'] ?? '')),
                'contract' => trim((string) ($row['contrato'] ?? '')),
                'onu' => trim((string) ($row['onu'] ?? $row['olt'] ?? '')),
                'mac' => trim((string) ($row['mac'] ?? $row['user_mac'] ?? '')),
                'detail_url' => Url::to('/clientes/detalhe?login=' . rawurlencode($login)),
            ];
        }

        return $results;
    }

    private function buildRecentClientItems(array $rows): array
    {
        $items = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $login = $this->sanitizeLogin((string) ($row['login'] ?? $row['mkauth_login'] ?? ''));
            if ($login === '') {
                continue;
            }

            $items[] = [
                'name' => (string) ($row['client_name'] ?? $row['nome'] ?? 'Cliente'),
                'login' => $login,
                'plan' => (string) ($row['plan_name'] ?? $row['plano'] ?? '-'),
                'status' => (string) ($row['status'] ?? 'registrado'),
                'updated_at' => (string) ($row['updated_at'] ?? $row['created_at'] ?? '-'),
                'detail_url' => Url::to('/clientes/detalhe?login=' . rawurlencode($login)),
            ];
        }

        return $items;
    }

    private function buildClientDetail(
        string $login,
        array $clientProfile,
        array $contract,
        array $registration,
        array $acceptance,
        array $financialTask,
        array $checkpoints,
        array $auditLogs
    ): array {
        try {
            $contracts = $this->contractRepository->listByLogin($login, 20);
        } catch (\Throwable) {
            $contracts = [];
        }

        if ($contract !== [] && $contracts === []) {
            $contracts[] = $contract;
        }

        $primaryContract = $contract !== [] ? $contract : ($contracts[0] ?? []);
        $acceptanceRecords = [];

        if (is_array($primaryContract) && isset($primaryContract['id'])) {
            try {
                $acceptanceRecords = $this->contractAcceptanceRepository->listByContractId((int) $primaryContract['id']);
            } catch (\Throwable) {
                $acceptanceRecords = [];
            }
        }

        $timeline = $this->buildClientTimeline(
            $registration,
            $checkpoints,
            $contracts,
            $acceptanceRecords,
            $financialTask,
            $auditLogs
        );

        $address = trim(implode(', ', array_filter([
            trim((string) ($clientProfile['endereco'] ?? $primaryContract['endereco'] ?? '')),
            trim((string) ($clientProfile['numero'] ?? $primaryContract['numero'] ?? '')),
            trim((string) ($clientProfile['complemento'] ?? $primaryContract['complemento'] ?? '')),
            trim((string) ($clientProfile['bairro'] ?? $primaryContract['bairro'] ?? '')),
            trim((string) ($clientProfile['cidade'] ?? $primaryContract['cidade'] ?? '')),
            trim((string) ($clientProfile['estado'] ?? $primaryContract['estado'] ?? '')),
            trim((string) ($clientProfile['cep'] ?? $primaryContract['cep'] ?? '')),
        ], static fn (string $value): bool => $value !== '')));

        $statusVisual = $this->resolveClientStatusVisual($clientProfile);
        $digitalContract = $this->buildDigitalContractSummary($login, $clientProfile, $contracts);
        $upgradeProcess = $this->buildUpgradeProcessSummary($login, $contracts);
        try {
            $operationalProcesses = $this->operationalProcessService->listByLogin($login);
        } catch (\Throwable) {
            $operationalProcesses = [];
        }
        $activeProcess = $this->resolveActiveClientProcess($operationalProcesses);
        $activeFinancialTask = [];
        $activeContractId = is_array($activeProcess) ? (int) ($activeProcess['contract_id'] ?? 0) : 0;
        if ($activeContractId > 0) {
            try {
                $candidateTask = $this->financialTaskRepository->findByContractId($activeContractId);
            } catch (\Throwable) {
                $candidateTask = null;
            }
            if (is_array($candidateTask)
                && !in_array(strtolower((string) ($candidateTask['status'] ?? '')), ['concluido', 'cancelado', 'substituido', 'revogado', 'encerrado', 'dispensado'], true)
            ) {
                $activeFinancialTask = $candidateTask;
            }
        }

        $technology = $this->technologyMapper->describe((string) ($clientProfile['plano_tecnologia'] ?? ''));
        $phones = is_array($clientProfile['phones'] ?? null) ? $clientProfile['phones'] : [];
        if ($phones === []) {
            $phones = array_values(array_filter([
                trim((string) ($clientProfile['celular'] ?? '')),
                trim((string) ($clientProfile['fone'] ?? $primaryContract['telefone_cliente'] ?? '')),
            ]));
        }
        $emails = is_array($clientProfile['emails'] ?? null) ? $clientProfile['emails'] : [];
        $phoneContacts = [];
        foreach ($phones as $index => $phone) {
            $phoneContacts[] = [
                'label' => $index === 0 ? 'Principal' : 'Alternativo ' . $index,
                'value' => (string) $phone,
            ];
        }
        $profile = [
            'name' => trim((string) ($clientProfile['nome'] ?? $primaryContract['nome_cliente'] ?? $registration['client_name'] ?? '-')),
            'short_name' => trim((string) ($clientProfile['nome_resumido'] ?? '')),
            'login' => $login,
            'document' => trim((string) ($clientProfile['cpf_cnpj'] ?? $registration['cpf_cnpj'] ?? '')),
            'phone' => (string) ($phones[0] ?? ''),
            'phones' => $phones,
            'phone_contacts' => $phoneContacts,
            'email' => ($email = trim((string) ($clientProfile['email'] ?? ''))) !== '' ? $email : '',
            'emails' => $emails,
            'address' => $address,
            'plan' => trim((string) ($clientProfile['plano_nome'] ?? $clientProfile['plano'] ?? $registration['plan_name'] ?? '-')),
            'status' => (string) ($statusVisual['label'] ?? 'Outro'),
            'status_visual' => $statusVisual,
            'due_day' => trim((string) ($clientProfile['venc'] ?? '')),
            'technology' => (string) $technology['label'],
            'technology_detail' => $technology,
            'coordinates' => trim((string) ($clientProfile['coordinates'] ?? '')),
            'reference' => trim((string) ($clientProfile['reference'] ?? '')),
            'monthly_value' => trim((string) ($clientProfile['plano_valor'] ?? '')),
            'billing_type' => trim((string) ($clientProfile['tipo_cob'] ?? '')),
            'open_titles' => $clientProfile['tit_abertos'] ?? null,
            'overdue_titles' => $clientProfile['tit_vencidos'] ?? null,
            'last_update' => trim((string) ($clientProfile['last_update'] ?? '')),
        ];
        $profile['actions'] = $this->buildClientQuickActions($profile, $clientProfile);
        $migrationAction = $this->buildMigrationAction($upgradeProcess, $operationalProcesses, $login);

        return [
            'login' => $login,
            'profile' => $profile,
            'clientProfile' => $clientProfile,
            'contract' => is_array($primaryContract) ? $primaryContract : [],
            'contracts' => array_values(array_map(fn (array $item): array => $this->normalizeContractSummary($item), $contracts)),
            'digitalContract' => $digitalContract,
            'upgradeProcess' => $upgradeProcess,
            'operationalProcesses' => $operationalProcesses,
            'activeProcess' => $activeProcess,
            'migrationAction' => $migrationAction,
            'acceptance' => $acceptance,
            'acceptanceHistory' => is_array($acceptanceRecords) ? $acceptanceRecords : [],
            'financialTask' => $financialTask,
            'activeFinancialTask' => $activeFinancialTask,
            'registration' => $registration,
            'checkpoints' => $checkpoints,
            'auditLogs' => $auditLogs,
            'timeline' => $timeline,
            'source' => [
                'mkauth' => $clientProfile !== [],
                'local' => $registration !== [] || $checkpoints !== [] || $contracts !== [],
            ],
        ];
    }

    private function resolveActiveClientProcess(array $processes): ?array
    {
        $terminalStatuses = ['completed', 'cancelled', 'superseded', 'revoked', 'closed', 'waived', 'concluida', 'cancelada', 'substituida', 'revogada', 'encerrada', 'dispensada'];
        foreach ($processes as $candidate) {
            if (!is_array($candidate)
                || in_array(strtolower(trim((string) ($candidate['status'] ?? ''))), $terminalStatuses, true)
            ) {
                continue;
            }

            $contractId = (int) ($candidate['contract_id'] ?? 0);
            if ($contractId > 0) {
                try {
                    $contract = $this->contractRepository->findById($contractId);
                } catch (\Throwable) {
                    $contract = null;
                }
                if (is_array($contract)
                    && in_array(strtolower((string) ($contract['lifecycle_status'] ?? 'active')), ['cancelled', 'superseded'], true)
                ) {
                    continue;
                }
            }

            $acceptanceId = (int) ($candidate['acceptance_id'] ?? 0);
            if ($acceptanceId > 0) {
                try {
                    $acceptance = $this->contractAcceptanceRepository->findById($acceptanceId);
                } catch (\Throwable) {
                    $acceptance = null;
                }
                if (is_array($acceptance)
                    && (trim((string) ($acceptance['revoked_at'] ?? '')) !== '' || (string) ($acceptance['status'] ?? '') === 'cancelado')
                ) {
                    continue;
                }
            }

            return $candidate;
        }

        return null;
    }

    private function buildClientQuickActions(array $profile, array $clientProfile): array
    {
        $actions = [];
        $phoneContacts = is_array($profile['phone_contacts'] ?? null) ? $profile['phone_contacts'] : [];
        if ($phoneContacts === []) {
            foreach ((array) ($profile['phones'] ?? []) as $index => $phone) {
                $phoneContacts[] = ['label' => $index === 0 ? 'Principal' : 'Alternativo ' . $index, 'value' => $phone];
            }
        }
        foreach ($phoneContacts as $index => $contact) {
            $phone = trim((string) ($contact['value'] ?? ''));
            $contactLabel = trim((string) ($contact['label'] ?? '')) ?: ($index === 0 ? 'Principal' : 'Alternativo ' . $index);
            $digits = preg_replace('/\D+/', '', $phone) ?? '';
            if (strlen($digits) < 10 || strlen($digits) > 13) {
                continue;
            }
            $international = str_starts_with($digits, '55') ? $digits : '55' . $digits;
            $actions[] = ['type' => 'phone', 'label' => 'Ligar · ' . $contactLabel, 'contact_label' => $contactLabel, 'value' => $phone, 'url' => 'tel:+' . $international];
            $actions[] = ['type' => 'whatsapp', 'label' => 'WhatsApp · ' . $contactLabel, 'contact_label' => $contactLabel, 'value' => $phone, 'url' => 'https://wa.me/' . $international];
        }

        foreach ((array) ($profile['emails'] ?? []) as $email) {
            $email = trim((string) $email);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $actions[] = ['type' => 'email', 'label' => 'E-mail', 'value' => $email, 'url' => 'mailto:' . rawurlencode($email)];
            }
        }

        $mapQuery = $this->normalizeMapQuery((string) ($profile['coordinates'] ?? ''), (string) ($profile['address'] ?? ''));
        if ($mapQuery !== '') {
            $actions[] = ['type' => 'map', 'label' => 'Abrir mapa', 'value' => '', 'url' => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($mapQuery)];
        }

        $actions[] = ['type' => 'copy', 'label' => 'Copiar login', 'value' => (string) ($profile['login'] ?? ''), 'url' => ''];

        $ip = trim((string) ($clientProfile['ip'] ?? ''));
        if ($this->canViewClientIp() && filter_var($ip, FILTER_VALIDATE_IP)) {
            $actions[] = ['type' => 'ip', 'label' => 'Acessar IP', 'value' => $ip, 'url' => 'http://' . $ip . '/'];
        }

        return $actions;
    }

    private function normalizeMapQuery(string $coordinates, string $address): string
    {
        if (preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $coordinates, $matches) === 1) {
            $lat = (float) $matches[1];
            $lng = (float) $matches[2];
            if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
                return $lat . ',' . $lng;
            }
        }

        return mb_strlen(trim($address)) >= 8 ? trim($address) : '';
    }

    private function buildMigrationAction(array $upgradeProcess, array $operationalProcesses, string $login): array
    {
        $process = null;
        foreach ($operationalProcesses as $candidate) {
            if (is_array($candidate) && (string) ($candidate['process_type'] ?? '') === 'migration') {
                $process = $candidate;
                if (!in_array((string) ($candidate['status'] ?? ''), ['completed', 'cancelled'], true)) {
                    break;
                }
            }
        }

        if (!is_array($process)) {
            return ['label' => 'Iniciar Upgrade / Migração', 'tone' => 'start', 'url' => '/clientes/upgrade?login=' . rawurlencode($login)];
        }

        $status = (string) ($process['status'] ?? 'in_progress');
        $url = '/processos/migracao?id=' . (int) ($process['id'] ?? 0);
        if ($status === 'completed') {
            return ['label' => 'Ver histórico da migração', 'tone' => 'complete', 'url' => $url];
        }
        if ($status === 'attention') {
            return ['label' => 'Resolver pendência da migração', 'tone' => 'attention', 'url' => $url];
        }
        if (str_starts_with($status, 'waiting_') || in_array((string) ($process['next_pending_key'] ?? ''), ['send_acceptance', 'confirm_acceptance'], true)) {
            return ['label' => 'Aguardando confirmação do cliente', 'tone' => 'waiting', 'url' => $url];
        }

        return [
            'label' => 'Continuar processo — ' . (int) ($process['progress_completed'] ?? 0) . ' de ' . (int) ($process['progress_total'] ?? 11) . ' etapas',
            'tone' => 'progress',
            'url' => $url,
        ];
    }

    private function canViewClientIp(): bool
    {
        $access = $this->localRepository->accessProfileForUser($this->resolveUser());

        return !empty($access['is_manager']) || !empty($access['is_admin']) || !empty($access['can_manage_settings']);
    }

    private function buildClientTimeline(
        array $registration,
        array $checkpoints,
        array $contracts,
        array $acceptances,
        array $financialTask,
        array $auditLogs
    ): array {
        $events = [];

        if ($registration !== []) {
            $events[] = [
                'group' => 'Cadastro local',
                'label' => 'Registro MkAuth/local',
                'description' => (string) ($registration['client_name'] ?? ''),
                'time' => (string) ($registration['created_at'] ?? ''),
            ];
        }

        foreach ($checkpoints as $checkpoint) {
            if (!is_array($checkpoint)) {
                continue;
            }
            $events[] = [
                'group' => 'Instalacao',
                'label' => 'Checkpoint',
                'description' => (string) ($checkpoint['status'] ?? 'awaiting_connection'),
                'time' => (string) ($checkpoint['updated_at'] ?? $checkpoint['created_at'] ?? ''),
            ];
        }

        foreach ($contracts as $contractItem) {
            if (!is_array($contractItem)) {
                continue;
            }
            $events[] = [
                'group' => 'Contrato',
                'label' => 'Contrato ' . (string) ($contractItem['id'] ?? '-'),
                'description' => (string) ($contractItem['status_financeiro'] ?? '-'),
                'time' => (string) ($contractItem['updated_at'] ?? $contractItem['created_at'] ?? ''),
            ];
        }

        foreach ($acceptances as $acceptanceItem) {
            if (!is_array($acceptanceItem)) {
                continue;
            }
            $events[] = [
                'group' => 'Aceite',
                'label' => 'Aceite ' . (string) ($acceptanceItem['status'] ?? '-'),
                'description' => 'Protocolo ' . (string) ($acceptanceItem['protocolo'] ?? ($acceptanceItem['id'] ?? '-')),
                'time' => (string) ($acceptanceItem['accepted_at'] ?? $acceptanceItem['created_at'] ?? ''),
            ];
        }

        if ($financialTask !== []) {
            $events[] = [
                'group' => 'Financeiro',
                'label' => 'Pendencia financeira',
                'description' => (string) ($financialTask['status'] ?? 'pendente'),
                'time' => (string) ($financialTask['updated_at'] ?? $financialTask['created_at'] ?? ''),
            ];
        }

        foreach ($auditLogs as $log) {
            if (!is_array($log)) {
                continue;
            }
            $translated = $this->translateAuditEvent((string) ($log['action'] ?? ''), $log);
            $events[] = [
                'group' => 'Auditoria',
                'label' => $translated['label'],
                'description' => $translated['description'],
                'time' => (string) ($log['created_at'] ?? ''),
                'responsible' => trim((string) ($log['actor_login'] ?? '')),
            ];
        }

        usort($events, static function (array $a, array $b): int {
            return strcmp((string) ($b['time'] ?? ''), (string) ($a['time'] ?? ''));
        });

        return array_slice($events, 0, 50);
    }

    private function translateAuditEvent(string $action, array $log = []): array
    {
        $label = match ($action) {
            'contract.acceptance.accepted', 'contract.acceptance.confirmed' => 'Cliente confirmou o aceite.',
            'contract.acceptance.created' => 'Aceite preparado.',
            'contract.upgrade.operational_task.created' => 'Pendência operacional criada.',
            'contract.financial_task.created' => 'Pendência financeira criada.',
            'contract.financial_task.completed' => 'Pendência financeira concluída.',
            'contract.financial_task.canceled' => 'Pendência financeira cancelada.',
            'contract.financial.closed', 'financial_task.mkauth_ticket.closed' => 'Chamado financeiro encerrado.',
            'contract.upgrade.created' => 'Nova condição de migração preparada.',
            'contract.upgrade.pending_condition_revised' => 'Condição pendente corrigida.',
            'contract.upgrade.correction_started' => 'Substituição da condição iniciada.',
            'contract.upgrade.superseded' => 'Condição anterior substituída.',
            'contract.upgrade.cancelled', 'operational_process.cancelled' => 'Migração cancelada.',
            'operational_process.completed' => 'Migração concluída.',
            'operational_process.migration.revised' => 'Condição da migração revisada.',
            'contract.migration.acceptance.prepared' => 'Assinatura e confirmação preparadas.',
            default => str_starts_with($action, 'operational_process.step.')
                ? 'Etapa operacional atualizada.'
                : 'Registro operacional atualizado.',
        };
        $actor = trim((string) ($log['actor_login'] ?? ''));

        return [
            'label' => $label,
            'description' => $actor !== '' ? 'Responsável: ' . $actor : '',
        ];
    }

    private function normalizeContractSummary(array $contract): array
    {
        return [
            'id' => (int) ($contract['id'] ?? 0),
            'login' => (string) ($contract['mkauth_login'] ?? ''),
            'name' => (string) ($contract['nome_cliente'] ?? '-'),
            'status_financeiro' => (string) ($contract['status_financeiro'] ?? '-'),
            'tipo_aceite' => (string) ($contract['tipo_aceite'] ?? '-'),
            'tipo_adesao' => (string) ($contract['tipo_adesao'] ?? '-'),
            'valor_adesao' => (float) ($contract['valor_adesao'] ?? 0),
            'parcelas_adesao' => (int) ($contract['parcelas_adesao'] ?? 0),
            'valor_parcela_adesao' => (float) ($contract['valor_parcela_adesao'] ?? 0),
            'created_at' => (string) ($contract['created_at'] ?? ''),
            'updated_at' => (string) ($contract['updated_at'] ?? ''),
        ];
    }

    private function buildDigitalContractSummary(string $login, array $clientProfile, array $contracts): array
    {
        $latestDigitalContract = null;
        $latestAcceptance = null;
        $digitalContractTypes = [
            'contrato_digital',
            'nova_instalacao',
            'regularizacao_contrato',
            'alteracao_plano',
            'renovacao_fidelidade',
        ];

        foreach ($contracts as $contract) {
            if (!is_array($contract) || !in_array((string) ($contract['tipo_aceite'] ?? ''), $digitalContractTypes, true)) {
                continue;
            }

            $latestDigitalContract = $contract;
            $contractId = (int) ($contract['id'] ?? 0);
            if ($contractId > 0) {
                try {
                    $latestAcceptance = $this->contractAcceptanceRepository->findLatestByContractId($contractId);
                } catch (\Throwable) {
                    $latestAcceptance = null;
                }
            }
            break;
        }

        if (!is_array($latestDigitalContract)) {
            return [
                'status' => 'none',
                'label' => 'Nao possui contrato digital',
                'description' => 'Solicite a assinatura remota do contrato atual do cliente.',
                'contract_id' => null,
                'acceptance_id' => null,
                'accepted_at' => '',
                'pending' => false,
                'signed' => false,
                'stale' => false,
                'request_url' => '/clientes/contrato/solicitar',
                'detail_url' => '',
                'login' => $login,
            ];
        }

        $acceptanceStatus = is_array($latestAcceptance) ? (string) ($latestAcceptance['status'] ?? '') : '';
        $acceptedAt = is_array($latestAcceptance) ? trim((string) ($latestAcceptance['accepted_at'] ?? '')) : '';
        $currentPlan = trim((string) ($clientProfile['plano_nome'] ?? $clientProfile['plano'] ?? ''));
        $storedNotes = trim((string) ($latestDigitalContract['observacao_adesao'] ?? ''));
        $stale = $acceptanceStatus === 'aceito'
            && (string) ($latestDigitalContract['tipo_aceite'] ?? '') === 'contrato_digital'
            && $currentPlan !== ''
            && $storedNotes !== ''
            && !str_contains($storedNotes, 'Plano atual: ' . $currentPlan);
        $pending = in_array($acceptanceStatus, ['criado', 'enviado', 'assinatura_pendente'], true);

        if ($pending) {
            $label = 'Aceite pendente';
            $status = 'pending';
            $description = 'Link gerado. Use reenviar se o cliente ainda nao assinou.';
        } elseif ($acceptanceStatus === 'aceito' && $stale) {
            $label = 'Contrato desatualizado';
            $status = 'stale';
            $description = 'O plano atual do MkAuth parece diferente do contrato digital assinado.';
        } elseif ($acceptanceStatus === 'aceito') {
            $label = 'Contrato assinado';
            $status = 'signed';
            $description = $acceptedAt !== '' ? 'Ultima assinatura: ' . $acceptedAt : 'Contrato digital aceito.';
        } else {
            $label = 'Nao possui contrato digital vigente';
            $status = 'none';
            $description = 'Existe contrato local, mas sem aceite digital concluido.';
        }

        return [
            'status' => $status,
            'label' => $label,
            'description' => $description,
            'contract_id' => (int) ($latestDigitalContract['id'] ?? 0),
            'acceptance_id' => is_array($latestAcceptance) ? (int) ($latestAcceptance['id'] ?? 0) : null,
            'accepted_at' => $acceptedAt,
            'pending' => $pending,
            'signed' => $acceptanceStatus === 'aceito',
            'stale' => $stale,
            'request_url' => '/clientes/contrato/solicitar',
            'detail_url' => (int) ($latestDigitalContract['id'] ?? 0) > 0 ? Url::to('/contratos/detalhe?id=' . rawurlencode((string) $latestDigitalContract['id'])) : '',
            'login' => $login,
        ];
    }

    private function buildUpgradeProcessSummary(string $login, array $contracts): array
    {
        $upgradeContract = null;
        $latestUpgradeContract = null;
        foreach ($contracts as $contract) {
            if (is_array($contract) && (string) ($contract['tipo_aceite'] ?? '') === 'upgrade_migracao') {
                $latestUpgradeContract ??= $contract;
                if (in_array((string) ($contract['lifecycle_status'] ?? 'active'), ['active', 'correction_pending'], true)) {
                    $upgradeContract = $contract;
                    break;
                }
            }
        }
        $upgradeContract ??= $latestUpgradeContract;

        if (!is_array($upgradeContract)) {
            return [
                'exists' => false,
                'open' => false,
                'active' => false,
                'status' => 'none',
                'status_label' => 'Nenhum processo iniciado',
                'contract_status_label' => 'Novo aceite obrigatório ao iniciar',
                'technical_status_label' => 'Não iniciado',
                'pending_label' => 'Iniciar Upgrade / Migração',
                'priority' => 'normal',
                'resume_url' => '/clientes/upgrade?login=' . rawurlencode($login),
            ];
        }

        $contractId = (int) ($upgradeContract['id'] ?? 0);
        try {
            $acceptance = $contractId > 0 ? $this->contractAcceptanceRepository->findLatestByContractId($contractId) : null;
        } catch (\Throwable) {
            $acceptance = null;
        }

        $acceptanceStatus = strtolower(trim((string) ($acceptance['status'] ?? '')));
        $acceptanceRevoked = trim((string) ($acceptance['revoked_at'] ?? '')) !== '';
        $lifecycleStatus = (string) ($upgradeContract['lifecycle_status'] ?? 'active');
        $revisionNumber = max(1, (int) ($upgradeContract['revision_number'] ?? 1));
        $snapshot = $this->extractUpgradeSnapshot($upgradeContract);
        $checklist = is_array($snapshot['technical_checklist'] ?? null) ? $snapshot['technical_checklist'] : [];
        $manualChecklistKeys = [
            'plan_checked',
            'technology_changed',
            'pppoe_validated',
            'client_connected',
            'speed_checked',
            'monthly_value_checked',
        ];
        $checkedCount = count(array_filter($manualChecklistKeys, static fn (string $key): bool => !empty($checklist[$key])));
        $allChecked = $checkedCount === count($manualChecklistKeys);

        $status = 'aguardando_aceite';
        $statusLabel = 'Aguardando aceite';
        $contractStatusLabel = 'Assinatura pendente';
        $technicalStatusLabel = 'Bloqueado até o aceite';
        $pendingLabel = 'Cliente precisa assinar o aditivo';
        $priority = 'attention';

        if ($lifecycleStatus === 'correction_pending') {
            $status = 'correcao_nao_finalizada';
            $statusLabel = 'Correção não finalizada';
            $contractStatusLabel = 'Versão anterior invalidada';
            $technicalStatusLabel = 'Bloqueado até a nova versão';
            $pendingLabel = 'Correção de Upgrade / Migração não finalizada.';
            $priority = 'attention';
        } elseif (in_array($lifecycleStatus, ['cancelled', 'superseded'], true)) {
            $status = $lifecycleStatus === 'cancelled' ? 'cancelado' : 'substituido';
            $statusLabel = $lifecycleStatus === 'cancelled' ? 'Cancelado' : 'Substituído por correção';
            $contractStatusLabel = $statusLabel;
            $technicalStatusLabel = 'Bloqueado';
            $pendingLabel = 'Nenhuma pendência ativa nesta versão';
            $priority = 'normal';
        } elseif ($acceptanceStatus === 'aceito' && !$acceptanceRevoked) {
            $contractStatusLabel = 'Aceite concluído';
            if ($allChecked && trim((string) ($snapshot['technical_completed_at'] ?? '')) !== '') {
                $status = 'concluido';
                $statusLabel = 'Concluído';
                $technicalStatusLabel = 'Checklist concluído';
                $pendingLabel = 'Nenhuma pendência';
                $priority = 'normal';
            } elseif ($checkedCount > 0) {
                $planPending = empty($checklist['plan_checked']) || empty($checklist['monthly_value_checked']);
                $status = $planPending ? 'aguardando_confirmacao_plano' : 'execucao_tecnica_parcial';
                $statusLabel = $planPending ? 'Aguardando confirmação de plano/valor' : 'Execução técnica parcial';
                $technicalStatusLabel = $checkedCount . '/6 itens técnicos conferidos';
                $pendingLabel = $revisionNumber > 1
                    ? 'Cliente aceitou a correção; execução técnica pendente.'
                    : 'Cliente já aceitou a alteração; execução técnica pendente.';
                $priority = 'urgent';
            } else {
                $status = 'aguardando_execucao_tecnica';
                $statusLabel = 'Aguardando execução técnica';
                $technicalStatusLabel = 'Ainda não confirmada';
                $pendingLabel = $revisionNumber > 1
                    ? 'Cliente aceitou a correção; execução técnica pendente.'
                    : 'Cliente já aceitou a alteração; execução técnica pendente.';
                $priority = 'urgent';
            }
        } elseif (in_array($acceptanceStatus, ['cancelado', 'expirado'], true)) {
            $status = $acceptanceStatus === 'cancelado' ? 'cancelado' : 'erro';
            $statusLabel = $acceptanceStatus === 'cancelado' ? 'Cancelado' : 'Aceite expirado';
            $contractStatusLabel = $statusLabel;
            $technicalStatusLabel = 'Bloqueado';
            $pendingLabel = 'Solicitar novo aceite';
            $priority = $acceptanceStatus === 'expirado' ? 'urgent' : 'attention';
        }

        if ($lifecycleStatus === 'active' && $revisionNumber > 1
            && in_array($acceptanceStatus, ['criado', 'enviado', 'assinatura_pendente'], true)
        ) {
            $pendingLabel = 'Aguardando aceite corrigido.';
        }

        return [
            'exists' => true,
            'open' => in_array($lifecycleStatus, ['active', 'correction_pending'], true) && $status !== 'concluido',
            'active' => $lifecycleStatus === 'active',
            'contract_id' => $contractId,
            'acceptance_id' => is_array($acceptance) ? (int) ($acceptance['id'] ?? 0) : 0,
            'acceptance_status' => $acceptanceStatus,
            'accepted' => $acceptanceStatus === 'aceito' && !$acceptanceRevoked && $lifecycleStatus === 'active',
            'acceptance_revoked' => $acceptanceRevoked,
            'lifecycle_status' => $lifecycleStatus,
            'completed' => $status === 'concluido',
            'status' => $status,
            'status_label' => $statusLabel,
            'contract_status_label' => $contractStatusLabel,
            'technical_status_label' => $technicalStatusLabel,
            'pending_label' => $pendingLabel,
            'priority' => $priority,
            'checklist' => array_merge(array_fill_keys($manualChecklistKeys, false), $checklist, [
                'acceptance_completed' => $acceptanceStatus === 'aceito' && !$acceptanceRevoked && $lifecycleStatus === 'active',
            ]),
            'snapshot' => $snapshot,
            'operation_type' => (string) ($snapshot['operation_type'] ?? ''),
            'current_plan' => (string) ($snapshot['current_plan_name'] ?? $snapshot['current_plan'] ?? ''),
            'new_plan' => (string) ($snapshot['new_plan_name'] ?? $snapshot['new_plan'] ?? ''),
            'current_technology' => (string) ($snapshot['current_technology'] ?? ''),
            'new_technology' => (string) ($snapshot['new_technology'] ?? ''),
            'revision_number' => $revisionNumber,
            'supersedes_contract_id' => (int) ($upgradeContract['supersedes_contract_id'] ?? 0),
            'superseded_by_contract_id' => (int) ($upgradeContract['superseded_by_contract_id'] ?? 0),
            'cancellation_reason' => (string) ($upgradeContract['cancellation_reason'] ?? ''),
            'technical_observation' => (string) ($snapshot['technical_observation'] ?? ''),
            'technical_updated_at' => (string) ($snapshot['technical_updated_at'] ?? ''),
            'technical_updated_by' => (string) ($snapshot['technical_updated_by'] ?? ''),
            'resume_url' => $lifecycleStatus === 'correction_pending'
                ? '/clientes/upgrade?login=' . rawurlencode($login) . '&correction_of=' . $contractId
                : '/clientes/detalhe?login=' . rawurlencode($login) . '#upgrade-process',
            'detail_url' => $contractId > 0 ? Url::to('/contratos/detalhe?id=' . rawurlencode((string) $contractId)) : '',
        ];
    }

    private function hasOpenUpgradeProcess(string $login): bool
    {
        try {
            $contracts = $this->contractRepository->listByLogin($login, 20);
        } catch (\Throwable) {
            return false;
        }

        $summary = $this->buildUpgradeProcessSummary($login, $contracts);
        return !empty($summary['open']);
    }

    private function detectClientSearchMode(string $query): string
    {
        $query = trim($query);

        if ($query === '') {
            return 'none';
        }

        $digits = preg_replace('/\D+/', '', $query) ?? '';

        if ($digits !== '' && in_array(strlen($digits), [11, 14], true)) {
            return 'document';
        }

        if ($digits !== '' && strlen($digits) >= 8) {
            return 'phone_or_document';
        }

        if (str_contains($query, ' ')) {
            return 'name';
        }

        if (strlen($query) <= 24) {
            return 'login';
        }

        return 'name';
    }

    private function resolveClientStatusLabel(array $row): string
    {
        $visual = $this->resolveClientStatusVisual($row);

        return (string) ($visual['label'] ?? '-');
    }

    private function resolveClientStatusVisual(array $row): array
    {
        $rawStatus = strtolower(trim((string) ($row['status'] ?? '')));
        $blocked = strtolower(trim((string) ($row['bloqueado'] ?? '')));
        $active = strtolower(trim((string) ($row['cli_ativado'] ?? '')));
        $statusCut = strtolower(trim((string) ($row['status_corte'] ?? '')));
        $blockType = strtolower(trim((string) ($row['tipobloq'] ?? '')));
        $blockedAt = trim((string) ($row['data_bloq'] ?? ''));
        $deactivatedAt = trim((string) ($row['data_desativacao'] ?? ''));

        $cancelledValues = ['cancelado', 'cancelled', 'canceled', 'c'];
        $blockedValues = ['bloqueado', 'suspenso', 'suspension', 'b', 's'];
        $activeValues = ['ativo', 'active', 'liberado', 'online', 'a'];

        if (in_array($rawStatus, $cancelledValues, true)) {
            return [
                'label' => 'Cancelado',
                'slug' => 'cancelled',
                'class' => 'client-status-cancelled',
                'raw' => $rawStatus,
                'note' => $deactivatedAt !== '' ? 'Desativado em ' . $this->formatDateShort($deactivatedAt) : 'Cliente cancelado',
            ];
        }

        if (
            ($active !== '' && !in_array($active, ['s', 'sim', '1', 'true', 'yes', 'on'], true))
            || ($blocked !== '' && !in_array($blocked, ['nao', 'não', 'n', '0', 'false', 'off'], true) && !in_array($blocked, ['sim', 's', '1', 'true', 'yes', 'on'], true))
            || $deactivatedAt !== ''
        ) {
            return [
                'label' => 'Cancelado',
                'slug' => 'cancelled',
                'class' => 'client-status-cancelled',
                'raw' => $rawStatus !== '' ? $rawStatus : ($active !== '' ? $active : $blocked),
                'note' => $deactivatedAt !== '' ? 'Desativado em ' . $this->formatDateShort($deactivatedAt) : 'Cliente cancelado',
            ];
        }

        if (
            in_array($rawStatus, $blockedValues, true)
            || in_array($blocked, ['sim', 's', '1', 'true', 'yes', 'on'], true)
            || $statusCut === 'bloq'
        ) {
            return [
                'label' => 'Suspenso/Bloqueado',
                'slug' => 'blocked',
                'class' => 'client-status-blocked',
                'raw' => $rawStatus !== '' ? $rawStatus : ($blocked !== '' ? $blocked : $active),
                'note' => trim(implode(' · ', array_filter([
                    $blockType === 'aut' ? 'Bloqueio automático' : ($blockType !== '' ? 'Bloqueio manual' : ''),
                    $blockedAt !== '' ? 'Desde ' . $this->formatDateShort($blockedAt) : '',
                ]))) ?: 'Cliente bloqueado',
            ];
        }

        if (
            in_array($rawStatus, $activeValues, true)
            || in_array($active, ['s', 'sim', '1', 'true', 'yes', 'on'], true)
            || ($blocked === 'nao' && $statusCut !== 'bloq')
        ) {
            return [
                'label' => 'Ativo',
                'slug' => 'active',
                'class' => 'client-status-active',
                'raw' => $rawStatus !== '' ? $rawStatus : ($active !== '' ? $active : $blocked),
                'note' => 'Cliente ativo',
            ];
        }

        return [
            'label' => 'Outro',
            'slug' => 'other',
            'class' => 'client-status-other',
            'raw' => $rawStatus !== '' ? $rawStatus : ($blocked !== '' ? $blocked : ($active !== '' ? $active : '-')),
            'note' => 'Estado indefinido',
        ];
    }

    private function formatDateShort(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '-';
        }

        try {
            $date = new \DateTimeImmutable($value);
            return $date->format('d/m/Y');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function collectFormData(Request $request): array
    {
        $commercial = $this->contractCommercialConfig();
        $document = preg_replace('/\D+/', '', (string) $request->input('cpf_cnpj', '')) ?? '';
        $login = $this->normalizeLoginInput((string) $request->input('login', ''));
        $person = $this->inferPersonFromDocument($document);
        $installType = strtolower(trim((string) $request->input('tipo_instalacao', 'fibra')));
        $installType = in_array($installType, ['fibra', 'radio'], true) ? $installType : 'fibra';
        $adhesionType = strtolower(trim((string) $request->input('tipo_adesao', '')));
        if (!in_array($adhesionType, ['cheia', 'promocional', 'isenta'], true)) {
            $adhesionType = 'cheia';
        }

        $maxInstallments = max(1, (int) ($commercial['parcelas_maximas_adesao'] ?? 3));
        $installments = (int) $request->input('parcelas_adesao', 1);
        $installments = max(1, min($maxInstallments, $installments > 0 ? $installments : 1));

        $baseValue = (float) ($commercial['valor_adesao_padrao'] ?? 0);
        $promoValue = (float) ($commercial['valor_adesao_promocional'] ?? 0);
        $discountPercent = (float) ($commercial['percentual_desconto_promocional'] ?? 0);
        $defaultValue = $this->resolveAdhesionValue($adhesionType, $baseValue, $promoValue, $discountPercent);
        $adhesionValue = $this->normalizeMoney((string) $request->input('valor_adesao', ''));
        if ($adhesionType === 'cheia') {
            $adhesionValue = $baseValue;
        } elseif ($adhesionType === 'isenta') {
            $adhesionValue = 0.0;
        } elseif ($adhesionValue <= 0) {
            $adhesionValue = $defaultValue;
        }

        $benefitValue = $adhesionType === 'isenta'
            ? $baseValue
            : max(0.0, $baseValue - $adhesionValue);

        $penaltyValue = $this->normalizeMoney((string) $request->input('multa_total', ''));
        if ($penaltyValue <= 0) {
            $penaltyValue = (float) ($commercial['multa_padrao'] ?? 0);
        }

        $firstBillingDate = $this->normalizeDateInput((string) $request->input('vencimento_primeira_parcela', ''));
        if ($firstBillingDate === null) {
            $firstBillingDate = $this->calculateFirstBillingDate((string) $request->input('vencimento', ''));
        }

        $city = $this->resolveCity(
            (string) $request->input('cidade', ''),
            (string) $request->input('estado', ''),
            (string) $request->input('codigo_ibge', '')
        );
        $emailOriginal = strtolower(trim((string) $request->input('email', '')));
        $hasRealEmail = $emailOriginal !== '' && $emailOriginal !== 'cliente@ievo.com.br';
        $email = $hasRealEmail ? $emailOriginal : 'cliente@ievo.com.br';
        $phone = preg_replace('/\D+/', '', (string) $request->input('celular', '')) ?? '';
        $cep = preg_replace('/\D+/', '', (string) $request->input('cep', '')) ?? '';
        $operator = $this->resolveUser();
        $operatorLogin = $this->sanitizeLogin((string) ($operator['login'] ?? ''));

        if ($operatorLogin === '' && isset($operator['source']) && (string) $operator['source'] !== 'fallback') {
            $operatorLogin = $this->sanitizeLogin((string) ($operator['name'] ?? ''));
        }

        if ($operatorLogin === '') {
            $operatorLogin = 'full_users';
        }

        $defaults = $this->resolveMkAuthDefaults();

        $number = strtoupper(trim((string) $request->input('numero', 'SN')));
        if ($number === '') {
            $number = 'SN';
        }

        return [
            'pessoa' => $person,
            'nome_completo' => $request->input('nome_completo', ''),
            'nome_resumido' => $request->input('nome_resumido', ''),
            'email' => $email,
            'email_original' => $emailOriginal,
            'email_cliente' => $email,
            'has_real_email' => $hasRealEmail,
            'telefone_original' => $phone,
            'telefone_cliente' => $phone,
            'cadastro' => date('Y-m-d'),
            'cpf_cnpj' => $document,
            'celular' => $phone,
            'tags_imprime' => $request->input('tags_imprime', 'nao'),
            'aceite_cliente' => $request->input('aceite_cliente', 'nao'),
            'assinatura_cliente' => (string) $request->input('assinatura_cliente', ''),
            'login' => $login,
            'senha' => $request->input('senha', '13v0'),
            'tipo_instalacao' => $installType,
            'plano' => $request->input('plano', ''),
            'cep' => $cep,
            'endereco' => $request->input('endereco', ''),
            'numero' => $number,
            'bairro' => $request->input('bairro', ''),
            'complemento' => $request->input('complemento', ''),
            'cidade' => (string) $request->input('cidade', ''),
            'estado' => $city['uf'],
            'codigo_ibge' => $city['ibge'],
            'coordenadas' => $this->normalizeCoordinates((string) $request->input('coordenadas', '')),
            'coordenadas_precisao' => $request->input('coordenadas_precisao', ''),
            'coordenadas_capturadas_em' => $request->input('coordenadas_capturadas_em', ''),
            'local_dici' => $request->input('local_dici', 'r'),
            'tipo_adesao' => $adhesionType,
            'valor_adesao' => number_format($adhesionValue, 2, '.', ''),
            'parcelas_adesao' => (string) $installments,
            'valor_parcela_adesao' => number_format($installments > 0 ? ($adhesionValue / $installments) : 0, 2, '.', ''),
            'vencimento_primeira_parcela' => $firstBillingDate ?? '',
            'fidelidade_meses' => (string) (($requestedFidelity = trim((string) $request->input('fidelidade_meses', ''))) !== ''
                ? max(1, (int) $requestedFidelity)
                : (int) ($commercial['fidelidade_meses_padrao'] ?? 12)),
            'beneficio_concedido_por' => trim((string) $request->input('beneficio_concedido_por', '')),
            'beneficio_valor' => number_format($benefitValue, 2, '.', ''),
            'multa_total' => number_format($penaltyValue, 2, '.', ''),
            'observacao' => $request->input('observacao', ''),
            'observacao_adesao' => $request->input('observacao_adesao', ''),
            'vencimento' => $request->input('vencimento', '05'),
            'conta_boleto' => $defaults['billing_account_id'],
            'contrato' => $defaults['contract_code'],
            'recebe_emails' => $hasRealEmail ? 'sim' : 'nao',
            'recebe_sms' => 'sim',
            'recebe_whatsapp' => 'sim',
            'pgcorte' => 'sim',
            'pgaviso' => 'sim',
            'tecnico' => $operatorLogin,
            'login_atend' => $operatorLogin,
        ];
    }

    private function validateDraft(array $data, Request $request, array $originalData = [], bool $editingCheckpoint = false, bool $hasExistingPhotos = false): array
    {
        $errors = [];
        $commercial = $this->contractCommercialConfig();

        if (trim((string) $data['nome_completo']) === '') {
            $errors[] = 'Informe o nome completo do cliente.';
        }

        if (trim((string) $data['cpf_cnpj']) === '') {
            $errors[] = 'Informe CPF ou CNPJ.';
        } elseif (!$this->isValidCpfCnpj((string) $data['cpf_cnpj'])) {
            $errors[] = 'CPF/CNPJ invalido.';
        }

        if (trim((string) $data['login']) === '') {
            $errors[] = 'Informe o login do cliente.';
        } elseif (!$this->isValidLogin((string) $data['login'])) {
            $errors[] = 'Login invalido. Use letras minusculas, numeros, ponto, hifen ou underscore.';
        }

        if (!in_array((string) ($data['tipo_instalacao'] ?? ''), ['fibra', 'radio'], true)) {
            $errors[] = 'Selecione o tipo de instalação.';
        }

        if (trim((string) $data['plano']) === '') {
            $errors[] = 'Selecione um plano.';
        } elseif (!$this->isPlanAllowedForSelection(
            (string) $data['plano'],
            (string) ($data['tipo_instalacao'] ?? ''),
            (string) ($data['local_dici'] ?? 'r')
        )) {
            $errors[] = 'O plano selecionado não corresponde ao tipo de instalação e Local DICI escolhidos.';
        }

        if (trim((string) $data['vencimento']) === '') {
            $errors[] = 'Selecione o vencimento.';
        } elseif (!$this->isDueDayAllowed((string) $data['vencimento'])) {
            $errors[] = 'Selecione um vencimento disponível no MkAuth.';
        }

        if (trim((string) $data['cidade']) === '') {
            $errors[] = 'Informe a cidade.';
        }

        $tipoAdesao = strtolower(trim((string) ($data['tipo_adesao'] ?? '')));
        if (!in_array($tipoAdesao, ['cheia', 'promocional', 'isenta'], true)) {
            $errors[] = 'Selecione um tipo de adesão válido.';
        }

        if (in_array($tipoAdesao, ['promocional', 'isenta'], true) && trim((string) ($data['beneficio_concedido_por'] ?? '')) === '') {
            $errors[] = 'Informe quem autorizou esta condição comercial.';
        }

        $valorAdesaoPadrao = (float) ($commercial['valor_adesao_padrao'] ?? 0);
        $valorAdesao = $this->normalizeMoney((string) ($data['valor_adesao'] ?? '0'));

        if ($tipoAdesao === 'cheia' && abs($valorAdesao - $valorAdesaoPadrao) > 0.009) {
            $errors[] = 'A adesão cheia deve usar exatamente o valor padrão configurado.';
        }

        if ($tipoAdesao === 'isenta' && abs($valorAdesao) > 0.009) {
            $errors[] = 'A adesão isenta deve ter valor R$ 0,00.';
        }

        $parcelasMaximas = max(1, (int) ($commercial['parcelas_maximas_adesao'] ?? 3));
        $parcelasAdesao = (int) ($data['parcelas_adesao'] ?? 1);
        if ($parcelasAdesao < 1 || $parcelasAdesao > $parcelasMaximas) {
            $errors[] = 'As parcelas de adesão não podem ultrapassar o máximo configurado.';
        }

        $fidelidadeMeses = (int) ($data['fidelidade_meses'] ?? 0);
        if ($fidelidadeMeses < 1) {
            $errors[] = 'Informe a fidelidade em meses.';
        }

        if (!$this->isValidEmail((string) $data['email'])) {
            $errors[] = 'E-mail invalido.';
        }

        if (!$this->isValidPhone((string) $data['celular'])) {
            $errors[] = 'Telefone invalido. Use DDD + numero com 10 ou 11 digitos.';
        }

        $originalLogin = $this->normalizeLoginInput((string) ($originalData['login'] ?? ''));
        $currentLogin = $this->normalizeLoginInput((string) $data['login']);

        if (!$editingCheckpoint || $originalLogin !== $currentLogin) {
            if ($this->clientFieldExists('login', (string) $data['login'])) {
                $errors[] = 'Login já existe no MkAuth. Informe outro login.';
            }
        }

        $originalDocument = preg_replace('/\D+/', '', (string) ($originalData['cpf_cnpj'] ?? '')) ?? '';
        $currentDocument = preg_replace('/\D+/', '', (string) ($data['cpf_cnpj'] ?? '')) ?? '';

        if (!$editingCheckpoint || $originalDocument !== $currentDocument) {
            if ($this->clientFieldExists('cpf_cnpj', (string) $data['cpf_cnpj'])) {
                $errors[] = 'CPF/CNPJ já existe no MkAuth.';
            }
        }

        if (!$this->isValidCoordinates((string) ($data['coordenadas'] ?? ''))) {
            $errors[] = 'Capture as coordenadas pelo celular antes de prosseguir.';
        }

        if ($this->countUploadedPhotos($request) < 1 && !$hasExistingPhotos) {
            $errors[] = 'Envie ao menos uma foto da instalacao.';
        }

        return $errors;
    }

    private function validateAcceptance(array $data, Request $request): array
    {
        $errors = [];
        $remoteSignature = $this->normalizeBoolean((string) ($data['assinatura_remota'] ?? '0'));
        $remoteReason = trim((string) ($data['assinatura_remota_motivo'] ?? ''));

        if (strtolower(trim((string) ($data['aceite_cliente'] ?? 'nao'))) !== 'sim') {
            $errors[] = 'Confirme o aceite do cliente antes de concluir.';
        }

        if ($remoteSignature) {
            if ($remoteReason === '') {
                $errors[] = 'Informe o motivo da ausência da assinatura para concluir o aceite remoto.';
            }
        } elseif (trim((string) ($data['assinatura_cliente'] ?? '')) === '') {
            $errors[] = 'Registre a assinatura do cliente antes de concluir.';
        }

        return $errors;
    }

    private function collectAcceptanceData(Request $request): array
    {
        return [
            'aceite_cliente' => $request->input('aceite_cliente', 'nao'),
            'assinatura_cliente' => (string) $request->input('assinatura_cliente', ''),
            'assinatura_remota' => $request->input('assinatura_remota', '0'),
            'assinatura_remota_motivo' => (string) $request->input('assinatura_remota_motivo', ''),
            'observacao_aceite' => (string) $request->input('observacao_aceite', ''),
        ];
    }

    private function cityDirectory(): array
    {
        return [
            ['name' => 'Coimbra', 'uf' => 'MG', 'ibge' => '3116704'],
            ['name' => 'Acrelandia', 'uf' => 'AC', 'ibge' => '1200013'],
            ['name' => 'Fortaleza', 'uf' => 'CE', 'ibge' => '2304400'],
        ];
    }

    private function loadPlans(): array
    {
        try {
            $plans = $this->mkauthDatabase->isConfigured() ? $this->mkauthDatabase->listPlans() : [];
        } catch (\Throwable $exception) {
            $plans = [];
        }

        if ($plans === []) {
            return [];
        }

        $normalized = array_map(function (array $plan): array {
            $name = trim((string) ($plan['nome'] ?? ''));
            $uuid = trim((string) ($plan['uuid_plano'] ?? ''));
            $value = trim((string) ($plan['valor'] ?? ''));
            $technologyCode = trim((string) ($plan['tecnologia'] ?? ''));
            $technology = $this->technologyMapper->describe($technologyCode);
            $speedRaw = trim((string) ($plan['veldown'] ?? ''));
            $speedMbps = $this->normalizePlanSpeed($speedRaw);
            if ($speedMbps !== null && preg_match('/^[0-9]+(?:[.,][0-9]+)?$/', $speedRaw) === 1 && $speedMbps >= 1000) {
                $speedMbps /= 1000;
            }
            $speedLabel = $speedMbps !== null
                ? rtrim(rtrim(number_format($speedMbps, 2, ',', ''), '0'), ',') . ' Mbps'
                : '';
            $label = $name;

            if ($speedLabel !== '' && !str_contains(strtolower($name), strtolower((string) floor($speedMbps ?? 0)))) {
                $label .= ' — ' . $speedLabel;
            }

            if ($value !== '') {
                $label .= ' — R$ ' . number_format((float) str_replace(',', '.', $value), 2, ',', '.');
            }

            return [
                'id' => $uuid !== '' ? $uuid : $name,
                'name' => $name,
                'label' => $label,
                'value' => $value,
                'technology' => $technologyCode,
                'technology_label' => (string) $technology['label'],
                'install_type' => (string) $technology['family'],
                'technology_verified' => (bool) $technology['verified'],
                'speed_down' => $speedRaw,
                'speed_mbps' => $speedMbps,
                'speed_label' => $speedLabel,
                'speed_up' => trim((string) ($plan['velup'] ?? '')),
                'description' => trim((string) ($plan['descricao'] ?? '')),
            ];
        }, array_values(array_filter($plans, static fn (array $plan): bool => trim((string) ($plan['nome'] ?? '')) !== '')));

        usort($normalized, static function (array $left, array $right): int {
            return [
                (string) ($left['install_type'] ?? ''),
                (float) ($left['speed_mbps'] ?? PHP_FLOAT_MAX),
                (float) ($left['value'] ?? PHP_FLOAT_MAX),
                (string) ($left['name'] ?? ''),
            ] <=> [
                (string) ($right['install_type'] ?? ''),
                (float) ($right['speed_mbps'] ?? PHP_FLOAT_MAX),
                (float) ($right['value'] ?? PHP_FLOAT_MAX),
                (string) ($right['name'] ?? ''),
            ];
        });

        return $normalized;
    }

    private function loadDueDays(): array
    {
        try {
            $days = $this->mkauthDatabase->isConfigured() ? $this->mkauthDatabase->listDueDays() : [];
        } catch (\Throwable $exception) {
            $days = [];
        }

        if ($days === []) {
            $days = array_map(
                static fn (string $day): array => ['day' => $day, 'total' => 0],
                ['01', '05', '10', '15', '20', '25', '30']
            );
        }

        return $days;
    }

    private function suggestDueDay(array $days): string
    {
        $available = array_map(static fn (array $row): int => (int) ($row['day'] ?? 0), $days);
        $available = array_values(array_filter($available, static fn (int $day): bool => $day > 0));

        if ($available === []) {
            return '05';
        }

        $today = (int) date('d');
        $best = $available[0];
        $bestDiff = 99;

        foreach ($available as $day) {
            $diff = abs($day - $today);

            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $day;
            }
        }

        return str_pad((string) $best, 2, '0', STR_PAD_LEFT);
    }

    private function loadCities(): array
    {
        try {
            $cities = $this->mkauthDatabase->isConfigured() ? $this->mkauthDatabase->listKnownCities() : [];
        } catch (\Throwable $exception) {
            $cities = [];
        }

        $normalized = [];

        foreach (array_merge($this->cityDirectory(), $cities) as $city) {
            $name = trim((string) ($city['name'] ?? ''));
            $uf = strtoupper(trim((string) ($city['uf'] ?? '')));
            $ibge = preg_replace('/\D+/', '', (string) ($city['ibge'] ?? '')) ?? '';

            if ($name === '' || $uf === '') {
                continue;
            }

            $normalized[strtolower($name . '|' . $uf)] = [
                'name' => $name,
                'uf' => $uf,
                'ibge' => $ibge,
            ];
        }

        uasort($normalized, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return array_values($normalized);
    }

    private function resolveMkAuthDefaults(): array
    {
        $defaults = [
            'billing_account_id' => '1',
            'contract_code' => '1b8e10ae245d7',
        ];

        if (!$this->mkauthDatabase->isConfigured()) {
            return $defaults;
        }

        try {
            $account = $this->mkauthDatabase->defaultBillingAccount();
            if (is_array($account) && trim((string) ($account['id'] ?? '')) !== '') {
                $defaults['billing_account_id'] = trim((string) $account['id']);
            }

            $contract = $this->mkauthDatabase->defaultContract();
            if (is_array($contract) && trim((string) ($contract['codigo'] ?? '')) !== '') {
                $defaults['contract_code'] = trim((string) $contract['codigo']);
            }
        } catch (\Throwable $exception) {
            return $defaults;
        }

        return $defaults;
    }

    private function isPlanAllowedForSelection(string $planName, string $installType, string $localDici): bool
    {
        $planName = trim($planName);

        if ($planName === '') {
            return false;
        }

        foreach ($this->loadPlans() as $plan) {
            if ((string) ($plan['id'] ?? '') !== $planName) {
                continue;
            }

            return ((string) ($plan['install_type'] ?? '') === $installType)
                && ((string) ($plan['local_dici'] ?? '') === $localDici);
        }

        return false;
    }

    private function isDueDayAllowed(string $day): bool
    {
        $day = str_pad((string) ((int) preg_replace('/\D+/', '', $day)), 2, '0', STR_PAD_LEFT);

        if ($day === '00') {
            return false;
        }

        foreach ($this->loadDueDays() as $availableDay) {
            if ($day === str_pad((string) ($availableDay['day'] ?? ''), 2, '0', STR_PAD_LEFT)) {
                return true;
            }
        }

        return false;
    }

    private function clientFieldExists(string $type, string $value): bool
    {
        $value = trim($value);

        if ($value === '') {
            return false;
        }

        if ($type === 'login') {
            $value = $this->normalizeLoginInput($value);
        } else {
            $value = preg_replace('/\D+/', '', $value) ?? '';
        }

        try {
            if ($this->mkauthDatabase->isConfigured()) {
                return $type === 'login'
                    ? $this->mkauthDatabase->clientExistsByLogin($value)
                    : $this->mkauthDatabase->clientExistsByCpfCnpj($value);
            }

            return $this->hasClientMatchByApi($type, $value);
        } catch (\Throwable $exception) {
            return true;
        }
    }

    private static function normalizeTextForMatch(string $value): string
    {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = $converted === false ? $value : $converted;

        return strtolower($value);
    }

    private function resolveCity(string $cityName, string $uf = '', string $ibge = ''): array
    {
        foreach ($this->cityDirectory() as $city) {
            if (strcasecmp($city['name'], $cityName) === 0) {
                return $city;
            }
        }

        return [
            'name' => trim($cityName),
            'uf' => strtoupper(trim($uf)),
            'ibge' => preg_replace('/\D+/', '', $ibge) ?? '',
        ];
    }

    private function resolveCep(string $cep): ?array
    {
        $cacheKey = 'cep_lookup_' . $cep;
        $cached = $_SESSION[$cacheKey] ?? null;

        if (is_array($cached)) {
            return $cached;
        }

        $lookupUrl = 'https://viacep.com.br/ws/' . rawurlencode($cep) . '/json/';
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $response = @file_get_contents($lookupUrl, false, $context);

        if ($response === false) {
            return null;
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded) || !empty($decoded['erro'])) {
            return null;
        }

        $lookup = [
            'cidade' => (string) ($decoded['localidade'] ?? ''),
            'uf' => (string) ($decoded['uf'] ?? ''),
            'ibge' => (string) ($decoded['ibge'] ?? ''),
            'logradouro' => (string) ($decoded['logradouro'] ?? ''),
            'bairro' => (string) ($decoded['bairro'] ?? ''),
        ];

        $_SESSION[$cacheKey] = $lookup;

        return $lookup;
    }

    private function saveDraft(array $data, ?string $draftId = null, ?string $checkpointToken = null): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $draftId = $this->normalizeDraftId($draftId);

        if ($draftId === '') {
            $draftId = bin2hex(random_bytes(8));
        }

        $existing = $this->loadDraftRecord($draftId);
        $record = is_array($existing) ? $existing : [];
        $record = array_replace($record, [
            'data' => $data,
            'checkpoint_token' => trim((string) ($checkpointToken ?? ($record['checkpoint_token'] ?? ''))),
            'created_at' => (string) ($record['created_at'] ?? date('Y-m-d H:i:s')),
            'updated_at' => date('Y-m-d H:i:s'),
            'user' => $this->resolveViewUser(),
        ]);
        $_SESSION['client_drafts'][$draftId] = $record;
        $this->persistDraftRecord($draftId, $record);

        return $draftId;
    }

    private function storeDraftPhotos(string $draftId, Request $request, bool $replaceExisting = false): array
    {
        if ($draftId === '' || !isset($_FILES['fotos_instalacao']) || !is_array($_FILES['fotos_instalacao']['name'] ?? null)) {
            return [];
        }

        $baseDir = $this->projectRootPath() . '/storage/uploads/clientes/drafts/' . $draftId;

        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0775, true);
        } elseif ($replaceExisting) {
            $this->clearDirectoryContents($baseDir);
        }

        $saved = [];
        $names = $_FILES['fotos_instalacao']['name'];
        $tmpNames = $_FILES['fotos_instalacao']['tmp_name'];
        $errors = $_FILES['fotos_instalacao']['error'];
        $types = $_FILES['fotos_instalacao']['type'];

        foreach ($names as $index => $originalName) {
            $uploadError = (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE);
            $tmpName = (string) ($tmpNames[$index] ?? '');

            if ($uploadError !== UPLOAD_ERR_OK) {
                if ($uploadError !== UPLOAD_ERR_NO_FILE) {
                    throw new \RuntimeException($this->describeUploadError($uploadError));
                }

                continue;
            }

            if (!is_uploaded_file($tmpName)) {
                continue;
            }

            $extension = $this->detectImageExtension((string) ($types[$index] ?? ''), (string) $originalName);
            $filename = 'foto_' . ($index + 1) . '.' . $extension;
            $destination = $baseDir . '/' . $filename;

            if (move_uploaded_file($tmpName, $destination)) {
                $this->optimizeStoredImage($destination);
                $saved[] = $filename;
            }
        }

        if (isset($_SESSION['client_drafts'][$draftId]) && is_array($_SESSION['client_drafts'][$draftId])) {
            $_SESSION['client_drafts'][$draftId]['media']['photos'] = $saved;
            $_SESSION['client_drafts'][$draftId]['media']['folder'] = $baseDir;
            $this->persistDraftRecord($draftId, $_SESSION['client_drafts'][$draftId]);
        }

        return $saved;
    }

    private function loadDraft(string $draftId): ?array
    {
        if ($draftId === '') {
            return null;
        }

        if (isset($_SESSION['client_drafts'][$draftId]) && is_array($_SESSION['client_drafts'][$draftId])) {
            $draft = $_SESSION['client_drafts'][$draftId];
            return is_array($draft['data'] ?? null) ? $draft['data'] : null;
        }

        $draft = $this->loadDraftRecord($draftId);

        if (is_array($draft)) {
            $_SESSION['client_drafts'][$draftId] = $draft;
            return is_array($draft['data'] ?? null) ? $draft['data'] : null;
        }

        return null;
    }

    private function loadDraftMedia(string $draftId): array
    {
        if ($draftId === '') {
            return [];
        }

        if (isset($_SESSION['client_drafts'][$draftId]) && is_array($_SESSION['client_drafts'][$draftId])) {
            $draft = $_SESSION['client_drafts'][$draftId];
            $media = $draft['media'] ?? [];

            return is_array($media) ? $media : [];
        }

        $draft = $this->loadDraftRecord($draftId);

        if (is_array($draft)) {
            $_SESSION['client_drafts'][$draftId] = $draft;
            $media = $draft['media'] ?? [];

            return is_array($media) ? $media : [];
        }

        return [];
    }

    private function clearClientDraft(string $draftId): void
    {
        if (isset($_SESSION['client_drafts'][$draftId])) {
            unset($_SESSION['client_drafts'][$draftId]);
        }

        $path = $this->draftRecordPath($draftId, false);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function saveFormDraft(array $data, ?string $draftId = null, ?string $checkpointToken = null): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION['client_form_draft'] = [
            'data' => $data,
            'draft_id' => $draftId,
            'checkpoint_token' => $checkpointToken,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function loadFormDraft(): array
    {
        $draft = $_SESSION['client_form_draft']['data'] ?? [];

        return is_array($draft) ? $draft : [];
    }

    private function clearFormDraft(): void
    {
        unset($_SESSION['client_form_draft']);
    }

    private function consumeClearDraftKeys(): array
    {
        $keys = $_SESSION['clear_client_drafts'] ?? [];
        unset($_SESSION['clear_client_drafts']);

        return is_array($keys) ? $keys : [];
    }

    private function storeAcceptanceEvidence(string $draftId, array $data, Request $request, ?string $evidenceRef = null): array
    {
        $summary = '';
        $savedItems = [];
        $signature = trim((string) ($data['assinatura_cliente'] ?? ''));
        $remoteSignature = $this->normalizeBoolean((string) ($data['assinatura_remota'] ?? '0'));
        $remoteReason = trim((string) ($data['assinatura_remota_motivo'] ?? ''));
        $acceptance = strtolower(trim((string) ($data['aceite_cliente'] ?? 'nao')));
        $login = trim((string) ($data['login'] ?? 'cliente'));
        $folderName = trim((string) ($evidenceRef ?? '')) !== ''
            ? trim((string) $evidenceRef)
            : $this->safeFilename($login) . '_' . date('YmdHis');
        $baseDir = $this->projectRootPath() . '/storage/uploads/clientes/' . $folderName;
        $draftMedia = $this->loadDraftMedia($draftId);
        $draftPhotos = is_array($draftMedia['photos'] ?? null) ? $draftMedia['photos'] : [];

        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0775, true);
        } elseif ($folderName === '' || $draftPhotos !== []) {
            $this->clearDirectoryContents($baseDir);
        }

        if ($acceptance === 'sim') {
            $savedItems[] = 'aceite';
        }

        if ($signature !== '' && str_starts_with($signature, 'data:image/')) {
            $signaturePath = $baseDir . '/assinatura.png';
            $binary = $this->decodeDataUrl($signature);

            if ($binary !== null && file_put_contents($signaturePath, $binary) !== false) {
                $savedItems[] = 'assinatura';
            }
        }

        $draftPhotos = $this->copyDraftPhotos($draftMedia, $baseDir);
        $savedItems = array_merge($savedItems, $draftPhotos);

        $metadata = [
            'client' => [
                'nome' => $data['nome_completo'] ?? '',
                'login' => $data['login'] ?? '',
                'cpf_cnpj' => $data['cpf_cnpj'] ?? '',
                'cidade' => $data['cidade'] ?? '',
                'estado' => $data['estado'] ?? '',
            ],
            'accepted_at' => date('Y-m-d H:i:s'),
            'accepted_by' => $this->resolveUser()['name'] ?? 'Operador',
            'ip' => (string) $request->server('REMOTE_ADDR', ''),
            'user_agent' => (string) $request->header('User-Agent', ''),
            'acceptance' => $acceptance,
            'signature_mode' => $remoteSignature ? 'remote' : 'local',
            'remote_signature' => [
                'enabled' => $remoteSignature,
                'reason' => $remoteReason,
            ],
            'observacao_aceite' => (string) ($data['observacao_aceite'] ?? ''),
            'files' => $savedItems,
        ];

        file_put_contents(
            $baseDir . '/aceite.json',
            json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''
        );

        if ($savedItems !== []) {
            $summary = 'Evidências salvas: ' . implode(', ', $savedItems) . '.';
        }

        return [
            'summary' => $summary,
            'items' => $savedItems,
            'folder' => $baseDir,
        ];
    }

    private function copyDraftPhotos(array $draftMedia, string $baseDir): array
    {
        $saved = [];
        $folder = (string) ($draftMedia['folder'] ?? '');
        $photos = $draftMedia['photos'] ?? [];

        if ($folder === '' || !is_dir($folder) || !is_array($photos)) {
            return [];
        }

        foreach ($photos as $photoName) {
            $photoName = (string) $photoName;
            $source = $folder . '/' . $photoName;
            $destination = $baseDir . '/' . $photoName;

            if (is_file($source) && @copy($source, $destination)) {
                $this->optimizeStoredImage($destination);
                $saved[] = $photoName;
            }
        }

        return $saved;
    }

    private function deleteDraftMedia(string $draftId): void
    {
        $draftMedia = $this->loadDraftMedia($draftId);
        $folder = (string) ($draftMedia['folder'] ?? '');

        if ($folder === '' || !is_dir($folder)) {
            return;
        }

        $items = array_diff(scandir($folder) ?: [], ['.', '..']);

        foreach ($items as $item) {
            $path = $folder . '/' . $item;
            if (is_file($path)) {
                @unlink($path);
            }
        }

        @rmdir($folder);
    }

    private function persistDraftRecord(string $draftId, array $record): void
    {
        $path = $this->draftRecordPath($draftId);

        file_put_contents(
            $path,
            json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''
        );
    }

    private function extractFormDataFromCheckpoint(?array $checkpoint): array
    {
        if (!is_array($checkpoint)) {
            return [];
        }

        $formData = $checkpoint['form_data'] ?? null;
        if (is_array($formData)) {
            return $formData;
        }

        $payload = $checkpoint['payload_json'] ?? '';
        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);
            if (is_array($decoded) && is_array($decoded['form_data'] ?? null)) {
                return $decoded['form_data'];
            }
        }

        $registration = $this->localRepository->findClientRegistrationByRadiusToken((string) ($checkpoint['token'] ?? ''));
        if (!is_array($registration)) {
            return [];
        }

        return [
            'nome_completo' => (string) ($registration['client_name'] ?? ''),
            'login' => (string) ($registration['mkauth_login'] ?? ''),
            'cpf_cnpj' => (string) ($registration['cpf_cnpj'] ?? ''),
            'plano' => (string) ($registration['plan_name'] ?? ''),
        ];
    }

    private function loadDraftRecord(string $draftId): ?array
    {
        $path = $this->draftRecordPath($draftId, false);

        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function normalizeDraftId(?string $draftId): string
    {
        $draftId = trim((string) $draftId);
        $draftId = preg_replace('/[^a-f0-9]/i', '', $draftId) ?? '';

        return $draftId;
    }

    private function draftRecordPath(string $draftId, bool $createDirectory = true): string
    {
        $draftId = $this->normalizeDraftId($draftId);

        if ($draftId === '') {
            $draftId = 'draft';
        }

        $directory = $this->projectRootPath() . '/storage/cache/client_drafts';

        if ($createDirectory && !is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        return $directory . '/' . $draftId . '.json';
    }

    private function hasStoredEvidencePhotos(string $ref): bool
    {
        $folder = $this->evidenceFolderPath($ref);

        if ($folder === null) {
            return false;
        }

        return count(array_diff(scandir($folder) ?: [], ['.', '..', 'aceite.json'])) > 0;
    }

    private function clearDirectoryContents(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . '/*') ?: [] as $item) {
            if (is_file($item)) {
                @unlink($item);
            }
        }
    }

    private function storeUploadedPhotos(Request $request, string $baseDir): array
    {
        if (!isset($_FILES['fotos_instalacao']) || !is_array($_FILES['fotos_instalacao']['name'] ?? null)) {
            return [];
        }

        $saved = [];
        $names = $_FILES['fotos_instalacao']['name'];
        $tmpNames = $_FILES['fotos_instalacao']['tmp_name'];
        $errors = $_FILES['fotos_instalacao']['error'];
        $types = $_FILES['fotos_instalacao']['type'];

        foreach ($names as $index => $originalName) {
            $uploadError = (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE);
            $tmpName = (string) ($tmpNames[$index] ?? '');

            if ($uploadError !== UPLOAD_ERR_OK) {
                if ($uploadError !== UPLOAD_ERR_NO_FILE) {
                    throw new \RuntimeException($this->describeUploadError($uploadError));
                }

                continue;
            }

            if (!is_uploaded_file($tmpName)) {
                continue;
            }

            $extension = $this->detectImageExtension((string) ($types[$index] ?? ''), (string) $originalName);
            $filename = 'foto_' . ($index + 1) . '.' . $extension;
            $destination = $baseDir . '/' . $filename;

            if (move_uploaded_file($tmpName, $destination)) {
                $this->optimizeStoredImage($destination);
                $saved[] = $filename;
            }
        }

        return $saved;
    }

    private function countUploadedPhotos(Request $request): int
    {
        if (!isset($_FILES['fotos_instalacao']) || !is_array($_FILES['fotos_instalacao']['name'] ?? null)) {
            return 0;
        }

        $count = 0;

        foreach ((array) $_FILES['fotos_instalacao']['error'] as $index => $error) {
            $tmpName = (string) ($_FILES['fotos_instalacao']['tmp_name'][$index] ?? '');

            if ((int) $error === UPLOAD_ERR_OK && is_uploaded_file($tmpName)) {
                $count++;
            }
        }

        return $count;
    }

    private function inferPersonFromDocument(string $document): string
    {
        return strlen($document) === 14 ? 'juridica' : 'fisica';
    }

    private function isValidCpfCnpj(string $document): bool
    {
        $digits = preg_replace('/\D+/', '', $document) ?? '';

        if (strlen($digits) === 11) {
            return $this->validateCpf($digits);
        }

        if (strlen($digits) === 14) {
            return $this->validateCnpj($digits);
        }

        return false;
    }

    private function validateCpf(string $cpf): bool
    {
        if (preg_match('/^(\\d)\\1{10}$/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += ((int) $cpf[$i]) * (($t + 1) - $i);
            }
            $digit = ((10 * $sum) % 11) % 10;
            if ((int) $cpf[$t] !== $digit) {
                return false;
            }
        }

        return true;
    }

    private function validateCnpj(string $cnpj): bool
    {
        if (preg_match('/^(\\d)\\1{13}$/', $cnpj)) {
            return false;
        }

        $length = strlen($cnpj) - 2;
        $numbers = substr($cnpj, 0, $length);
        $digits = substr($cnpj, $length);
        $sum = 0;
        $pos = $length - 7;

        for ($i = $length; $i >= 1; $i--) {
            $sum += ((int) $numbers[$length - $i]) * $pos--;
            if ($pos < 2) {
                $pos = 9;
            }
        }

        $result = $sum % 11 < 2 ? 0 : 11 - ($sum % 11);
        if ((int) $digits[0] !== $result) {
            return false;
        }

        $length++;
        $numbers = substr($cnpj, 0, $length);
        $sum = 0;
        $pos = $length - 7;

        for ($i = $length; $i >= 1; $i--) {
            $sum += ((int) $numbers[$length - $i]) * $pos--;
            if ($pos < 2) {
                $pos = 9;
            }
        }

        $result = $sum % 11 < 2 ? 0 : 11 - ($sum % 11);
        return (int) $digits[1] === $result;
    }

    private function isValidEmail(string $email): bool
    {
        return $email === '' ? true : filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function isValidPhone(string $phone): bool
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return in_array(strlen($digits), [10, 11], true);
    }

    private function isValidCoordinates(string $coordinates): bool
    {
        $coordinates = $this->normalizeCoordinates($coordinates);

        if ($coordinates === '') {
            return false;
        }

        [$latitude, $longitude] = array_map('trim', explode(',', $coordinates, 2));
        $lat = (float) $latitude;
        $lng = (float) $longitude;

        return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
    }

    private function normalizeCoordinates(string $coordinates): string
    {
        $coordinates = trim(str_replace(';', ',', $coordinates));

        if ($coordinates === '') {
            return '';
        }

        if (!preg_match('/^\s*(-?\d+(?:[\.,]\d+)?)\s*,\s*(-?\d+(?:[\.,]\d+)?)\s*$/', $coordinates, $matches)) {
            return '';
        }

        $lat = str_replace(',', '.', $matches[1]);
        $lng = str_replace(',', '.', $matches[2]);

        return $lat . ',' . $lng;
    }

    private function isValidLogin(string $login): bool
    {
        return (bool) preg_match('/^[a-z0-9_.-]+$/', $login);
    }

    private function normalizeLoginInput(string $login): string
    {
        $login = trim($login);
        $login = $this->removeAccents($login);
        $login = strtolower($login);

        return $login;
    }

    private function maskDocument(string $document): string
    {
        $digits = preg_replace('/\D+/', '', $document) ?? '';

        if ($digits === '') {
            return '-';
        }

        if (strlen($digits) <= 6) {
            return $digits;
        }

        return substr($digits, 0, 3) . '.' . substr($digits, 3, 3) . '.***-**';
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return '-';
        }

        if (strlen($digits) <= 4) {
            return $digits;
        }

        return '(' . substr($digits, 0, 2) . ') ' . substr($digits, 2, 5) . '-****';
    }

    private function formatPublicPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return '-';
        }

        if (strlen($digits) === 11) {
            return '(' . substr($digits, 0, 2) . ') ' . substr($digits, 2, 5) . '-' . substr($digits, 7, 4);
        }

        if (strlen($digits) === 10) {
            return '(' . substr($digits, 0, 2) . ') ' . substr($digits, 2, 4) . '-' . substr($digits, 6, 4);
        }

        return $digits;
    }

    private function sanitizeLogin(string $login): string
    {
        return $this->normalizeLoginInput($login);
    }

    private function removeAccents(string $value): string
    {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $converted === false ? $value : $converted;
    }

    private function describeUploadError(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE => 'A foto excede o limite permitido pelo servidor.',
            UPLOAD_ERR_FORM_SIZE => 'A foto excede o limite permitido pelo formulario.',
            UPLOAD_ERR_PARTIAL => 'A foto foi enviada parcialmente. Tente novamente.',
            UPLOAD_ERR_NO_FILE => 'Nenhuma foto foi enviada.',
            UPLOAD_ERR_NO_TMP_DIR => 'O servidor nao encontrou a pasta temporaria de upload.',
            UPLOAD_ERR_CANT_WRITE => 'O servidor nao conseguiu salvar a foto recebida.',
            UPLOAD_ERR_EXTENSION => 'Um recurso do servidor bloqueou o upload da foto.',
            default => 'Nao foi possivel processar a foto enviada.',
        };
    }

    private function decodeDataUrl(string $dataUrl): ?string
    {
        if (!preg_match('#^data:image/([a-zA-Z0-9.+-]+);base64,(.+)$#', $dataUrl, $matches)) {
            return null;
        }

        $decoded = base64_decode($matches[2], true);

        return $decoded === false ? null : $decoded;
    }

    private function detectImageExtension(string $mimeType, string $originalName): string
    {
        $mimeType = strtolower(trim($mimeType));
        $originalName = strtolower(trim($originalName));

        return match (true) {
            str_contains($mimeType, 'png') || str_ends_with($originalName, '.png') => 'png',
            str_contains($mimeType, 'webp') || str_ends_with($originalName, '.webp') => 'webp',
            str_contains($mimeType, 'gif') || str_ends_with($originalName, '.gif') => 'gif',
            default => 'jpg',
        };
    }

    private function detectResponseMimeType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'json' => 'application/json; charset=UTF-8',
            default => 'image/jpeg',
        };
    }

    private function optimizeStoredImage(string $path): void
    {
        if (!is_file($path) || !function_exists('getimagesize') || !function_exists('imagecreatefromjpeg')) {
            return;
        }

        $info = @getimagesize($path);

        if (!is_array($info) || empty($info[0]) || empty($info[1]) || empty($info['mime'])) {
            return;
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        $mime = strtolower((string) $info['mime']);
        $maxWidth = 1280;
        $maxHeight = 1280;

        if ($width <= $maxWidth && $height <= $maxHeight && filesize($path) !== false && filesize($path) <= 1500000) {
            return;
        }

        $source = match ($mime) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            'image/gif' => @imagecreatefromgif($path),
            default => false,
        };

        if (!$source) {
            return;
        }

        $ratio = min($maxWidth / $width, $maxHeight / $height, 1);
        $targetWidth = max(1, (int) round($width * $ratio));
        $targetHeight = max(1, (int) round($height * $ratio));

        if ($targetWidth === $width && $targetHeight === $height) {
            imagedestroy($source);
            return;
        }

        $target = imagecreatetruecolor($targetWidth, $targetHeight);

        if (!$target) {
            imagedestroy($source);
            return;
        }

        if ($mime === 'image/png' || $mime === 'image/webp') {
            imagealphablending($target, false);
            imagesavealpha($target, true);
        }

        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        switch ($mime) {
            case 'image/png':
                imagepng($target, $path, 6);
                break;
            case 'image/webp':
                if (function_exists('imagewebp')) {
                    imagewebp($target, $path, 78);
                }
                break;
            case 'image/gif':
                imagegif($target, $path);
                break;
            default:
                imagejpeg($target, $path, 78);
                break;
        }

        imagedestroy($source);
        imagedestroy($target);
    }

    private function safeFilename(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_-]+/i', '_', $value) ?? 'cliente';

        return trim($value, '_') ?: 'cliente';
    }

    private function hasClientMatch(array $response): bool
    {
        $total = (int) ($response['total_registros'] ?? $response['Total'] ?? 0);

        if ($total > 0) {
            return true;
        }

        foreach (['clientes', 'cliente', 'dados'] as $collectionKey) {
            $collection = $response[$collectionKey] ?? null;

            if (is_array($collection) && $collection !== []) {
                return true;
            }
        }

        return false;
    }

    private function hasClientMatchByApi(string $type, string $value): bool
    {
        $response = $this->provisioner->listClients([
            $type === 'login' ? 'login' : 'cpf_cnpj' => $value,
            'limite' => 1,
        ]);

        return $this->hasClientMatch($response);
    }

    private function evidenceFolderPath(string $ref): ?string
    {
        if (!preg_match('/^[a-z0-9_-]+$/i', $ref)) {
            return null;
        }

        $path = $this->projectRootPath() . '/storage/uploads/clientes/' . $ref;

        return is_dir($path) ? $path : null;
    }

    private function absoluteUrl(Request $request, string $path): string
    {
        $scheme = ((string) $request->server('HTTPS', '') !== '' && (string) $request->server('HTTPS', '') !== 'off') ? 'https' : 'http';
        $host = (string) $request->server('HTTP_HOST', 'localhost');
        $basePath = rtrim($request->basePath(), '/');
        $normalizedPath = '/' . ltrim($path, '/');

        return $scheme . '://' . $host . $basePath . $normalizedPath;
    }

    private function resolveRadiusConnection(string $login): array
    {
        $login = trim($login);

        if ($login === '') {
            return [
                'online' => false,
                'message' => 'Login vazio.',
            ];
        }

        try {
            if (!$this->mkauthDatabase->isConfigured()) {
                return [
                    'online' => false,
                    'message' => 'Banco MkAuth não configurado.',
                ];
            }

            return $this->mkauthDatabase->radiusConnectionStatus($login);
        } catch (\Throwable $exception) {
            return [
                'online' => false,
                'message' => 'Não foi possível consultar o Radius agora: ' . $exception->getMessage(),
            ];
        }
    }

    private function resolveAcceptanceStatusForLogin(string $login): array
    {
        $login = trim($login);

        if ($login === '') {
            return [
                'status' => 'pendente',
                'label' => 'pendente',
                'accepted' => false,
                'acceptance' => null,
                'contract' => null,
            ];
        }

        $contract = null;

        try {
            $contract = $this->contractRepository->findByLogin($login);
        } catch (\Throwable) {
            $contract = null;
        }

        if (!is_array($contract) || !isset($contract['id'])) {
            return [
                'status' => 'pendente',
                'label' => 'pendente',
                'accepted' => false,
                'acceptance' => null,
                'contract' => null,
            ];
        }

        $contract = $this->hydrateContractCommunicationDataByLogin($contract);

        $acceptance = null;

        try {
            $acceptance = $this->contractAcceptanceRepository->findLatestByContractId((int) $contract['id']);
        } catch (\Throwable) {
            $acceptance = null;
        }

        $status = strtolower((string) ($acceptance['status'] ?? 'pendente'));
        $label = match ($status) {
            'aceito' => 'aceite aceito',
            'enviado' => 'aceite enviado',
            'assinatura_pendente' => 'assinatura pendente',
            'expirado' => 'aceite expirado',
            'cancelado' => 'aceite cancelado',
            default => 'aceite pendente',
        };

        return [
            'status' => $status,
            'label' => $label,
            'accepted' => $status === 'aceito',
            'acceptance' => $acceptance,
            'contract' => $contract,
        ];
    }

    private function hydrateContractCommunicationDataByLogin(array $contract): array
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

    private function recordClientRegistration(array $data, array $payload, string $connectionToken): ?int
    {
        $user = $this->resolveUser();

        try {
            return $this->localRepository->upsertClientRegistrationByRadiusToken([
                'mkauth_uuid' => (string) ($payload['uuid_cliente'] ?? $payload['uuid'] ?? ''),
                'mkauth_login' => (string) ($payload['login'] ?? $data['login'] ?? ''),
                'client_name' => (string) ($payload['nome'] ?? $data['nome_completo'] ?? ''),
                'cpf_cnpj' => (string) ($payload['cpf_cnpj'] ?? $data['cpf_cnpj'] ?? ''),
                'plan_name' => (string) ($payload['plano'] ?? $data['plano'] ?? ''),
                'status' => 'awaiting_connection',
                'evidence_ref' => (string) ($data['evidence_ref'] ?? ''),
                'evidence_url' => (string) ($data['evidence_url'] ?? ''),
                'radius_token' => $connectionToken,
                'created_by_user_id' => isset($user['id']) ? (int) $user['id'] : null,
                'created_by_login' => (string) ($user['login'] ?? ''),
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function recordEvidenceFiles(?int $registrationId, string $evidenceRef, string $folder): void
    {
        try {
            $this->localRepository->deleteEvidenceFilesByRegistrationId($registrationId);
            $this->localRepository->registerEvidenceFiles($registrationId, $evidenceRef, $folder);
        } catch (\Throwable) {
            // As evidencias ja estao salvas em disco; o indice local pode ser reprocessado depois.
        }
    }

    private function syncContractArtifacts(array $data, array $payload, ?int $registrationId, Request $request): void
    {
        try {
            $this->messageTemplateRepository->ensureDefaults($this->defaultMessageTemplates());
        } catch (\Throwable $exception) {
            $this->recordAudit('contract.templates.sync_failed', 'message_template', null, [
                'login' => (string) ($payload['login'] ?? $data['login'] ?? ''),
                'error' => $exception->getMessage(),
            ], $request);
        }

        $contractData = $this->buildContractData($data, $payload, $registrationId);

        try {
            $contract = $this->resolveContractRecord($contractData, $registrationId);
            $contractId = (int) ($contract['id'] ?? 0);

            if ($contractId <= 0) {
                $contractId = $this->contractRepository->create($contractData) ?? 0;
                if ($contractId > 0) {
                    $this->recordAudit('contract.created', 'client_contract', $contractId, [
                        'login' => (string) $contractData['mkauth_login'],
                        'client_id' => $registrationId,
                        'status_financeiro' => (string) $contractData['status_financeiro'],
                    ], $request);
                }
            } else {
                $this->contractRepository->updateById($contractId, $contractData);
                $this->recordAudit('contract.updated', 'client_contract', $contractId, [
                    'login' => (string) $contractData['mkauth_login'],
                    'client_id' => $registrationId,
                    'status_financeiro' => (string) $contractData['status_financeiro'],
                ], $request);
            }

            if ($contractId <= 0) {
                throw new \RuntimeException('Contrato não pôde ser identificado após a gravação.');
            }

            $contractData['contract_id'] = $contractId;

            $termBody = $this->buildContractTermBody($contractData);
            $termHash = hash('sha256', $termBody);
            $acceptanceData = $this->buildAcceptanceData($contractId, $data, $contractData, $termHash, $request);

            $acceptance = $this->contractAcceptanceRepository->findLatestByContractId($contractId);
            $acceptanceId = 0;
            if (is_array($acceptance) && isset($acceptance['id'])) {
                $acceptanceId = (int) $acceptance['id'];
                $existingTokenHash = trim((string) ($acceptance['token_hash'] ?? ''));
                if ($existingTokenHash !== '') {
                    $acceptanceData['token_hash'] = $existingTokenHash;
                }
                $this->contractAcceptanceRepository->updateById($acceptanceId, $acceptanceData);
                $this->recordAudit('contract.acceptance.updated', 'contract_acceptance', $acceptanceId, [
                    'login' => (string) $contractData['mkauth_login'],
                    'contract_id' => $contractId,
                    'status' => (string) $acceptanceData['status'],
                ], $request);
            } else {
                $acceptanceId = $this->contractAcceptanceRepository->create($acceptanceData) ?? 0;
                if ($acceptanceId > 0) {
                    $this->recordAudit('contract.acceptance.created', 'contract_acceptance', $acceptanceId, [
                        'login' => (string) $contractData['mkauth_login'],
                        'contract_id' => $contractId,
                        'status' => (string) $acceptanceData['status'],
                    ], $request);
                }
            }

            if ($acceptanceId > 0) {
                $contractData['acceptance_id'] = $acceptanceId;
            }

            $financialTaskCreated = false;
            $financialTaskId = null;

            if (($contractData['status_financeiro'] ?? 'pendente_lancamento') === 'pendente_lancamento') {
                $financialTaskData = $this->buildFinancialTaskData($contractId, $contractData);
                $financialTask = $this->financialTaskRepository->findByContractId($contractId);

                if (is_array($financialTask) && isset($financialTask['id'])) {
                    $financialTaskId = (int) $financialTask['id'];
                    if ($this->hasAutomaticFinancialTicket($contractId, $financialTaskId)) {
                        $existingDescription = trim((string) ($financialTask['descricao'] ?? ''));
                        $existingStatus = trim((string) ($financialTask['status'] ?? ''));

                        if ($existingStatus !== '') {
                            $financialTaskData['status'] = $existingStatus;
                        }

                        if ($existingDescription !== '' && str_contains($existingDescription, 'Chamado financeiro automatizado')) {
                            $baseDescription = trim((string) ($financialTaskData['descricao'] ?? ''));
                            $financialTaskData['descricao'] = $baseDescription === ''
                                ? $existingDescription
                                : $baseDescription . "\n\n" . $existingDescription;
                        }
                    }
                    $this->financialTaskRepository->updateById($financialTaskId, $financialTaskData);
                    $this->recordAudit('contract.financial_task.updated', 'financial_task', $financialTaskId, [
                        'login' => (string) $contractData['mkauth_login'],
                        'contract_id' => $contractId,
                        'status' => (string) $financialTaskData['status'],
                    ], $request);
                } else {
                    $taskId = $this->financialTaskRepository->create($financialTaskData) ?? 0;
                    if ($taskId > 0) {
                        $financialTaskCreated = true;
                        $financialTaskId = $taskId;
                        $this->recordAudit('contract.financial_task.created', 'financial_task', $taskId, [
                            'login' => (string) $contractData['mkauth_login'],
                            'contract_id' => $contractId,
                            'status' => (string) $financialTaskData['status'],
                        ], $request);
                    }
                }
            }

            if ($registrationId !== null && $this->normalizeBoolean((string) $this->config->get('contracts.mkauth_ticket.auto_create', '0'))) {
                $financialTask = $financialTaskId !== null
                    ? $this->financialTaskRepository->findById($financialTaskId)
                    : $this->financialTaskRepository->findByContractId($contractId);

                if (is_array($financialTask) && isset($financialTask['id']) && !$this->hasAutomaticFinancialTicket($contractId, (int) $financialTask['id'])) {
                    $this->dispatchAutomaticFinancialTicket($contractId, $contractData, (int) $financialTask['id'], $request);
                }
            }

            if ($acceptanceId > 0) {
                $contractData['id'] = $contractId;
                $acceptanceRecord = $this->contractAcceptanceRepository->findById($acceptanceId)
                    ?? array_merge($acceptanceData, ['id' => $acceptanceId]);
                $this->operationalProcessService->ensureForContract(
                    OperationalProcessService::TYPE_INSTALLATION,
                    $contractData,
                    $acceptanceRecord,
                    [
                        'login' => (string) $contractData['mkauth_login'],
                        'client_name' => (string) ($contractData['nome_cliente'] ?? ''),
                        'phone' => (string) ($contractData['telefone_cliente'] ?? ''),
                        'email' => (string) ($data['email_original'] ?? $data['email'] ?? ''),
                        'registration_id' => $registrationId,
                        'signature_mode' => $this->normalizeBoolean((string) ($data['assinatura_remota'] ?? '0')) ? 'remote' : 'local',
                    ],
                    $this->resolveUser()
                );
            }
        } catch (\Throwable $exception) {
            $this->recordAudit('contract.flow_failed', 'client_contract', $registrationId, [
                'login' => (string) ($payload['login'] ?? $data['login'] ?? ''),
                'client_id' => $registrationId,
                'error' => $exception->getMessage(),
            ], $request);
        }
    }

    private function resolveContractRecord(array $contractData, ?int $registrationId): ?array
    {
        if ($registrationId !== null) {
            try {
                $contract = $this->contractRepository->findByClientId($registrationId);
                if (is_array($contract)) {
                    return $contract;
                }
            } catch (\Throwable) {
            }
        }

        $login = trim((string) ($contractData['mkauth_login'] ?? ''));
        if ($login !== '') {
            try {
                $contract = $this->contractRepository->findByLogin($login);
                if (is_array($contract)) {
                    return $contract;
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function buildContractData(array $data, array $payload, ?int $registrationId): array
    {
        $login = trim((string) ($payload['login'] ?? $data['login'] ?? ''));
        $nome = trim((string) ($payload['nome'] ?? $data['nome_completo'] ?? ''));
        $telefone = preg_replace('/\D+/', '', (string) ($payload['celular'] ?? $data['celular'] ?? '')) ?? '';
        $commercial = $this->contractCommercialConfig();
        $tipoInstalacao = strtolower(trim((string) ($data['tipo_instalacao'] ?? 'fibra')));
        $tipoInstalacao = in_array($tipoInstalacao, ['fibra', 'radio'], true) ? $tipoInstalacao : 'fibra';
        $tipoAdesao = strtolower(trim((string) ($data['tipo_adesao'] ?? '')));
        if (!in_array($tipoAdesao, ['cheia', 'promocional', 'isenta'], true)) {
            $tipoAdesao = 'cheia';
        }

        $parcelasMaximas = max(1, (int) ($commercial['parcelas_maximas_adesao'] ?? 3));
        $parcelasAdesao = max(1, min($parcelasMaximas, (int) ($data['parcelas_adesao'] ?? 1)));
        $valorBase = (float) ($commercial['valor_adesao_padrao'] ?? 0);
        $valorPromocional = (float) ($commercial['valor_adesao_promocional'] ?? 0);
        $descontoPromocional = (float) ($commercial['percentual_desconto_promocional'] ?? 0);
        $valorAdesao = $this->normalizeMoney((string) ($data['valor_adesao'] ?? '0'));
        if ($tipoAdesao === 'cheia') {
            $valorAdesao = $valorBase;
        } elseif ($tipoAdesao === 'isenta') {
            $valorAdesao = 0.0;
        } elseif ($valorAdesao <= 0) {
            $valorAdesao = $this->resolveAdhesionValue($tipoAdesao, $valorBase, $valorPromocional, $descontoPromocional);
        }

        $valorParcela = $parcelasAdesao > 0 ? round($valorAdesao / $parcelasAdesao, 2) : 0.0;

        $fidelidade = (int) ($data['fidelidade_meses'] ?? 0);
        if ($fidelidade <= 0) {
            $fidelidade = max(1, (int) ($commercial['fidelidade_meses_padrao'] ?? 12));
        }
        $beneficioConcedidoPor = trim((string) ($data['beneficio_concedido_por'] ?? ''));
        $beneficioValor = $tipoAdesao === 'isenta'
            ? $valorBase
            : max(0.0, $valorBase - $valorAdesao);
        $multaTotal = max(0.0, (float) ($commercial['multa_padrao'] ?? 0));
        $statusFinanceiro = 'pendente_lancamento';

        $vencimentoPrimeiraParcela = $this->calculateFirstBillingDate((string) ($data['vencimento'] ?? ''));
        if ($vencimentoPrimeiraParcela === null) {
            $vencimentoPrimeiraParcela = $this->normalizeDateInput((string) ($data['vencimento_primeira_parcela'] ?? ''));
        }

        $observacaoAdesao = trim((string) ($data['observacao_adesao'] ?? $data['observacao'] ?? $data['observacao_aceite'] ?? ''));
        $observacaoAdesaoLines = [];
        $technician = $this->resolveTechnicianIdentity();

        if ($beneficioConcedidoPor !== '') {
            $observacaoAdesaoLines[] = 'Autorizado por: ' . $beneficioConcedidoPor;
        }

        if ($observacaoAdesao !== '') {
            $observacaoAdesaoLines[] = 'Observação: ' . $observacaoAdesao;
        }

        $observacaoAdesao = trim(implode("\n", $observacaoAdesaoLines));

        return [
            'client_id' => $registrationId,
            'mkauth_login' => $login,
            'technician_name' => $technician['name'],
            'technician_login' => $technician['login'],
            'nome_cliente' => $nome,
            'email_cliente' => (string) ($data['email_original'] ?? $data['email'] ?? ''),
            'has_real_email' => (bool) ($data['has_real_email'] ?? false),
            'telefone_cliente' => $telefone,
            'tipo_adesao' => $tipoAdesao,
            'valor_adesao' => $valorAdesao,
            'parcelas_adesao' => $parcelasAdesao,
            'valor_parcela_adesao' => $valorParcela,
            'vencimento_primeira_parcela' => $vencimentoPrimeiraParcela,
            'fidelidade_meses' => $fidelidade,
            'beneficio_valor' => $beneficioValor,
            'multa_total' => $multaTotal,
            'beneficio_concedido_por' => $beneficioConcedidoPor,
            'tipo_aceite' => trim((string) ($data['tipo_aceite'] ?? $this->config->get('contracts.default_tipo_aceite', 'nova_instalacao'))),
            'observacao_adesao' => $observacaoAdesao,
            'status_financeiro' => $statusFinanceiro,
        ];
    }

    private function loadCorrectableUpgrade(int $contractId, string $login, int $processId = 0): ?array
    {
        if ($contractId <= 0 || !$this->canCorrectUpgrade()) {
            return null;
        }

        $contract = $this->contractRepository->findById($contractId);
        if (!is_array($contract)
            || (string) ($contract['tipo_aceite'] ?? '') !== 'upgrade_migracao'
            || $this->sanitizeLogin((string) ($contract['mkauth_login'] ?? '')) !== $this->sanitizeLogin($login)
            || !in_array((string) ($contract['lifecycle_status'] ?? 'active'), ['active', 'correction_pending'], true)
        ) {
            return null;
        }

        if ((string) ($contract['lifecycle_status'] ?? 'active') === 'active') {
            $acceptance = $this->contractAcceptanceRepository->findLatestByContractId($contractId);
            if (!is_array($acceptance)
                || !in_array((string) ($acceptance['status'] ?? ''), ['criado', 'enviado', 'assinatura_pendente'], true)
                || trim((string) ($acceptance['revoked_at'] ?? '')) !== ''
            ) {
                return null;
            }
            $matchingProcess = null;
            foreach ($this->operationalProcessService->listByLogin($login) as $process) {
                if ((int) ($process['contract_id'] ?? 0) === $contractId
                    && (int) ($process['id'] ?? 0) === $processId
                    && !in_array((string) ($process['status'] ?? ''), ['completed', 'cancelled'], true)
                ) {
                    $matchingProcess = $process;
                    break;
                }
            }
            if (!is_array($matchingProcess)) {
                return null;
            }
        }

        return $contract;
    }

    private function assertUpgradeCreationAllowed(string $login, int $correctionOf = 0): void
    {
        $rows = $this->database->fetchAll(
            'SELECT id, lifecycle_status, upgrade_snapshot_json
             FROM client_contracts
             WHERE LOWER(mkauth_login) = LOWER(:login)
               AND tipo_aceite = "upgrade_migracao"
               AND lifecycle_status IN ("active", "correction_pending")
             ORDER BY id DESC
             FOR UPDATE',
            ['login' => trim($login)]
        );

        $targetFound = $correctionOf <= 0;
        foreach ($rows as $row) {
            $contractId = (int) ($row['id'] ?? 0);
            if ($correctionOf > 0 && $contractId === $correctionOf) {
                $targetFound = true;
                continue;
            }

            $snapshot = json_decode((string) ($row['upgrade_snapshot_json'] ?? ''), true);
            $snapshot = is_array($snapshot) ? $snapshot : [];
            $completed = (string) ($snapshot['technical_status'] ?? '') === 'concluido'
                || trim((string) ($snapshot['technical_completed_at'] ?? '')) !== '';

            if (!$completed) {
                throw new \RuntimeException('Já existe outro Upgrade / Migração ativo para este cliente.');
            }
        }

        if (!$targetFound) {
            throw new \RuntimeException('O processo que seria corrigido não está mais ativo.');
        }
    }

    private function canCorrectUpgradeContract(array $contract, ?array $acceptance = null): bool
    {
        if (!$this->canCorrectUpgrade()
            || (string) ($contract['tipo_aceite'] ?? '') !== 'upgrade_migracao'
            || !in_array((string) ($contract['lifecycle_status'] ?? 'active'), ['active', 'correction_pending'], true)
        ) {
            return false;
        }

        if (!is_array($acceptance)) {
            try {
                $acceptance = $this->contractAcceptanceRepository->findLatestByContractId((int) ($contract['id'] ?? 0));
            } catch (\Throwable) {
                $acceptance = null;
            }
        }

        if ($this->upgradeTechnicalExecutionCompleted($contract)
            || (string) ($acceptance['status'] ?? '') === 'aceito'
        ) {
            return $this->canSupersedeContract();
        }

        return $this->canCancelPendingContract();
    }

    private function applyCorrectionContext(array $context, array $contract): array
    {
        $snapshot = $this->extractUpgradeSnapshot($contract);
        $context['contract'] = $contract;
        $context['new_plan'] = (string) ($snapshot['new_plan_id'] ?? $snapshot['new_plan'] ?? $context['new_plan'] ?? '');
        $context['new_technology'] = (string) ($snapshot['new_technology'] ?? $context['new_technology'] ?? '');
        $context['new_technology_family'] = (string) ($snapshot['new_technology_family'] ?? $context['new_technology_family'] ?? '');
        $context['new_monthly_value'] = (float) ($snapshot['new_monthly_value'] ?? $context['new_monthly_value'] ?? 0);
        $context['benefit_flags'] = $this->normalizeUpgradeBenefitFlags($snapshot['benefit_flags'] ?? null);
        $context['benefit_description'] = (string) ($snapshot['benefit_description'] ?? $context['benefit_description'] ?? '');
        $context['benefit_value'] = (float) ($snapshot['benefit_value'] ?? $context['benefit_value'] ?? 0);
        $context['fidelity_months'] = max(0, min(12, (int) ($snapshot['fidelity_months'] ?? $context['fidelity_months'] ?? 0)));
        $context['apply_fidelity'] = $context['fidelity_months'] > 0;
        $context['fidelity_benefit_description'] = (string) ($snapshot['fidelity_benefit_description'] ?? $context['fidelity_benefit_description'] ?? '');
        $context['observacao'] = (string) ($snapshot['observacao'] ?? $snapshot['observation'] ?? $context['observacao'] ?? '');
        $context['operation_type'] = (string) ($snapshot['operation_type'] ?? 'upgrade');
        $context['supersedes_contract_id'] = (int) ($contract['id'] ?? 0);
        $context['revision_number'] = max(2, (int) ($contract['revision_number'] ?? 1) + 1);

        return $context;
    }

    private function upgradeTechnicalExecutionCompleted(array $contract): bool
    {
        $snapshot = $this->extractUpgradeSnapshot($contract);

        return (string) ($snapshot['technical_status'] ?? '') === 'concluido'
            || trim((string) ($snapshot['technical_completed_at'] ?? '')) !== '';
    }

    private function transitionUpgradeForCorrection(
        array $contract,
        ?array $acceptance,
        string $lifecycleStatus,
        string $reason,
        Request $request
    ): void {
        if (!in_array($lifecycleStatus, ['cancelled', 'correction_pending'], true)) {
            throw new \InvalidArgumentException('Estado de correção inválido.');
        }

        $contractId = (int) ($contract['id'] ?? 0);
        if ($contractId <= 0 || (string) ($contract['lifecycle_status'] ?? 'active') !== 'active') {
            throw new \RuntimeException('Este processo não está mais ativo para cancelamento ou correção.');
        }

        $operator = $this->resolveUser();
        $operatorId = isset($operator['id']) && (int) $operator['id'] > 0 ? (int) $operator['id'] : null;
        $operatorLogin = (string) ($operator['login'] ?? '');
        $pdo = $this->database->pdo();
        $ownsTransaction = !$pdo->inTransaction();

        try {
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }

            $currentContract = $this->database->fetchOne(
                'SELECT * FROM client_contracts WHERE id = :id FOR UPDATE',
                ['id' => $contractId]
            );
            if (!is_array($currentContract) || (string) ($currentContract['lifecycle_status'] ?? 'active') !== 'active') {
                throw new \RuntimeException('Este processo não está mais ativo para cancelamento ou correção.');
            }

            if (is_array($acceptance) && (int) ($acceptance['id'] ?? 0) > 0) {
                $acceptance = $this->database->fetchOne(
                    'SELECT * FROM contract_acceptances WHERE id = :id AND contract_id = :contract_id FOR UPDATE',
                    ['id' => (int) $acceptance['id'], 'contract_id' => $contractId]
                );
            }

            if ($lifecycleStatus === 'cancelled' && (string) ($acceptance['status'] ?? '') === 'aceito') {
                throw new \RuntimeException('Este contrato já foi aceito. Use Corrigir e reenviar com autorização administrativa ou comercial.');
            }
            if ($lifecycleStatus === 'cancelled' && $this->upgradeTechnicalExecutionCompleted($currentContract)) {
                throw new \RuntimeException('Este processo já possui execução técnica concluída. É necessário abrir um processo corretivo.');
            }
            if ($lifecycleStatus === 'correction_pending'
                && ((string) ($acceptance['status'] ?? '') === 'aceito' || $this->upgradeTechnicalExecutionCompleted($currentContract))
                && !$this->canSupersedeContract()
            ) {
                throw new \RuntimeException('A substituição deste contrato exige permissão administrativa ou comercial.');
            }

            $contract = $currentContract;

            $this->contractRepository->markLifecycle(
                $contractId,
                $lifecycleStatus,
                $reason,
                $operatorId,
                $operatorLogin
            );

            if (is_array($acceptance) && (int) ($acceptance['id'] ?? 0) > 0) {
                $this->contractAcceptanceRepository->revoke(
                    (int) $acceptance['id'],
                    $reason,
                    $operatorId,
                    $operatorLogin,
                    true
                );
            }

            $financialTask = $this->financialTaskRepository->findByContractId($contractId);
            if (is_array($financialTask) && (int) ($financialTask['id'] ?? 0) > 0
                && (string) ($financialTask['status'] ?? '') !== 'concluido'
            ) {
                $this->financialTaskRepository->updateStatus((int) $financialTask['id'], 'cancelado');
            }

            $this->recordAudit(
                $lifecycleStatus === 'cancelled' ? 'contract.upgrade.cancelled' : 'contract.upgrade.correction_started',
                'client_contract',
                $contractId,
                [
                    'login' => (string) ($contract['mkauth_login'] ?? ''),
                    'contract_id' => $contractId,
                    'acceptance_id' => is_array($acceptance) ? (int) ($acceptance['id'] ?? 0) : null,
                    'previous_lifecycle_status' => (string) ($contract['lifecycle_status'] ?? 'active'),
                    'new_lifecycle_status' => $lifecycleStatus,
                    'reason' => $reason,
                    'operator_id' => $operatorId,
                    'operator_login' => $operatorLogin,
                    'operator_name' => (string) ($operator['name'] ?? ''),
                    'before' => $this->extractUpgradeSnapshot($contract),
                ],
                $request
            );

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function loadUpgradeContext(string $login): ?array
    {
        $login = $this->sanitizeLogin($login);
        if ($login === '') {
            return null;
        }

        try {
            $clientProfile = $this->mkauthDatabase->findClientProfile($login);
        } catch (\Throwable) {
            $clientProfile = null;
        }

        try {
            $contract = $this->contractRepository->findByLogin($login);
        } catch (\Throwable) {
            $contract = null;
        }

        try {
            $registration = $this->localRepository->findLatestClientRegistrationByLogin($login);
        } catch (\Throwable) {
            $registration = null;
        }

        if (!is_array($clientProfile) || $clientProfile === []) {
            return null;
        }

        $planOptions = $this->loadPlans();
        $currentPlan = trim((string) ($clientProfile['plano_nome'] ?? $clientProfile['plano'] ?? $registration['plan_name'] ?? $contract['plan_name'] ?? ''));
        $currentPlanOption = $this->findPlanOptionByName($currentPlan, $planOptions);
        $currentTechnology = $this->resolveTechnologyLabel(
            (string) ($clientProfile['plano_tecnologia'] ?? $contract['plano_tecnologia'] ?? ''),
            is_array($currentPlanOption) ? $currentPlanOption : [],
            $currentPlan
        );
        $upgradeSnapshot = $this->extractUpgradeSnapshot(is_array($contract) ? $contract : []);
        $defaultNewPlan = trim((string) ($upgradeSnapshot['new_plan_id'] ?? $upgradeSnapshot['new_plan'] ?? ''));
        $defaultNewPlanOption = $this->findPlanOptionByName($defaultNewPlan, $planOptions);
        $defaultNewTechnology = $this->resolveTechnologyLabelForPlan(is_array($defaultNewPlanOption) ? $defaultNewPlanOption : []);
        $currentMonthlyValue = $this->resolvePlanMonthlyValue($currentPlan, $planOptions);
        if ($currentMonthlyValue === null) {
            $currentMonthlyValue = $this->resolvePlanMonthlyValue($defaultNewPlan, $planOptions);
        }
        $defaultNewMonthlyValue = $this->resolvePlanMonthlyValue($defaultNewPlan, $planOptions);
        $defaultNewMonthlyValue ??= 0.0;
        $defaultBenefitDefaults = $this->resolveUpgradeBenefitDefaults(
            $currentTechnology,
            $defaultNewTechnology,
            $currentPlan,
            $defaultNewPlan,
            (float) ($currentMonthlyValue ?? 0),
            (float) ($defaultNewMonthlyValue ?? ($currentMonthlyValue ?? 0))
        );
        $currentTechnologyFamily = $this->resolveTechnologyFamily(is_array($currentPlanOption) ? $currentPlanOption : [], $currentTechnology, $currentPlan);
        $defaultNewTechnologyFamily = $this->resolveTechnologyFamily(is_array($defaultNewPlanOption) ? $defaultNewPlanOption : [], $defaultNewTechnology, $defaultNewPlan);
        $fidelityMonths = max(0, min(12, (int) ($upgradeSnapshot['fidelity_months'] ?? 0)));
        $originalContractReference = '';
        if (is_array($contract) && isset($contract['id']) && (int) $contract['id'] > 0) {
            $originalContractReference = 'Contrato local #' . (int) $contract['id'];
        } else {
            $originalContractReference = 'Cadastro localizado apenas no MkAuth, sem contrato local vinculado.';
        }

        return [
            'login' => $login,
            'clientProfile' => is_array($clientProfile) ? $clientProfile : [],
            'contract' => is_array($contract) ? $contract : [],
            'registration' => is_array($registration) ? $registration : [],
            'current_plan' => $currentPlan,
            'current_technology' => $currentTechnology,
            'current_technology_family' => $currentTechnologyFamily,
            'current_monthly_value' => $currentMonthlyValue,
            'new_plan' => $defaultNewPlan,
            'operation_type' => in_array((string) ($upgradeSnapshot['operation_type'] ?? ''), ['upgrade', 'migration', 'downgrade'], true)
                ? (string) ($upgradeSnapshot['operation_type'] ?? '')
                : '',
            'new_technology' => trim((string) ($upgradeSnapshot['new_technology'] ?? $defaultNewTechnology)),
            'new_technology_family' => $defaultNewTechnologyFamily,
            'benefit_flags' => $this->normalizeUpgradeBenefitFlags($upgradeSnapshot['benefit_flags'] ?? null) ?: $defaultBenefitDefaults['flags'],
            'benefit_description' => trim((string) ($upgradeSnapshot['benefit_description'] ?? $defaultBenefitDefaults['description'])),
            'benefit_value' => isset($upgradeSnapshot['benefit_value']) ? (float) $upgradeSnapshot['benefit_value'] : (float) $defaultBenefitDefaults['value'],
            'new_monthly_value' => isset($upgradeSnapshot['new_monthly_value']) ? (float) $upgradeSnapshot['new_monthly_value'] : $defaultNewMonthlyValue,
            'fidelity_months' => $fidelityMonths,
            'apply_fidelity' => $fidelityMonths > 0,
            'fidelity_benefit_description' => trim((string) ($upgradeSnapshot['fidelity_benefit_description'] ?? '')),
            'retention_condition' => !empty($upgradeSnapshot['retention_condition']),
            'observacao' => trim((string) ($upgradeSnapshot['observation'] ?? '')),
            'multa_proporcional' => isset($upgradeSnapshot['multa_proporcional']) ? (float) $upgradeSnapshot['multa_proporcional'] : (float) ($contract['multa_total'] ?? 0),
            'original_contract_reference' => $originalContractReference,
            'planOptions' => $planOptions,
            'adhesion_default_value' => (float) $this->config->get('contracts.commercial.valor_adesao_padrao', 0),
            'adhesion_waiver_mode' => $this->radioFiberAdhesionWaiverMode(),
        ];
    }

    private function collectUpgradeFormData(Request $request, array $context): array
    {
        $planOptions = is_array($context['planOptions'] ?? null) ? $context['planOptions'] : [];
        $currentPlan = trim((string) ($context['current_plan'] ?? ''));
        $selectedPlan = trim((string) $request->input('novo_plano', $context['new_plan'] ?? $currentPlan));
        $selectedPlanOption = $this->findPlanOptionByName($selectedPlan, $planOptions);
        $currentPlanOption = $this->findPlanOptionByName($currentPlan, $planOptions);
        $currentTechnology = $this->resolveTechnologyLabel(
            (string) ($context['current_technology'] ?? ''),
            is_array($currentPlanOption) ? $currentPlanOption : [],
            $currentPlan
        );
        $selectedTechnology = $this->resolveTechnologyLabelForPlan(is_array($selectedPlanOption) ? $selectedPlanOption : []);
        $selectedTechnologyFamily = $this->resolveTechnologyFamily(is_array($selectedPlanOption) ? $selectedPlanOption : [], $selectedTechnology, $selectedPlan);
        $currentTechnologyFamily = $this->resolveTechnologyFamily(is_array($currentPlanOption) ? $currentPlanOption : [], $currentTechnology, $currentPlan);
        $monthlyValue = $this->resolvePlanMonthlyValue($selectedPlan, $planOptions);
        if ($monthlyValue === null) {
            $monthlyValue = (float) ($context['new_monthly_value'] ?? 0);
        }
        $benefitDefaults = $this->resolveUpgradeBenefitDefaults(
            $currentTechnology,
            $selectedTechnology,
            $currentPlan,
            $selectedPlan,
            (float) ($context['current_monthly_value'] ?? 0),
            $monthlyValue
        );
        // Classificação técnica e isenção são sempre derivadas no servidor.
        // O formulário só pode acrescentar retenção, benefício livre e a
        // confirmação manual prevista pela configuração comercial.
        $benefitFlags = $benefitDefaults['flags'];
        $benefitFlags['retention'] = (string) $request->input('retention_condition', '0') === '1';
        $benefitDescription = trim((string) $request->input('beneficio_concedido', ''));
        if ($benefitDescription === '') {
            $benefitDescription = $benefitDefaults['description'];
        }
        $benefitValue = $this->normalizeMoney((string) $request->input('valor_beneficio', (string) $benefitDefaults['value']));
        $benefitOtherText = trim((string) $request->input('beneficio_outro_text', ''));
        $benefitFlags['other_benefit'] = $benefitOtherText !== '';
        $waiverMode = $this->radioFiberAdhesionWaiverMode();
        if (!empty($benefitFlags['radio_to_fiber'])) {
            if ($waiverMode === 'manual') {
                $benefitFlags['adhesion_waiver'] = (string) $request->input('manual_adhesion_waiver', '0') === '1';
            } elseif ($waiverMode === 'disabled') {
                $benefitFlags['adhesion_waiver'] = false;
            } else {
                $benefitFlags['adhesion_waiver'] = true;
            }
        }
        if (!empty($benefitFlags['adhesion_waiver'])) {
            $benefitValue = (float) $this->config->get('contracts.commercial.valor_adesao_padrao', 0);
        }
        $applyFidelity = (string) $request->input('apply_fidelity', '0') === '1';
        $fidelityMonths = $applyFidelity ? (int) $request->input('fidelidade_meses', '12') : 0;
        $fidelityBenefitDescription = trim((string) $request->input('fidelity_benefit_description', ''));

        if (!$this->canUpgradeCommercial()) {
            $benefitFlags = $benefitDefaults['flags'];
            $benefitDescription = $benefitDefaults['description'];
            $benefitValue = (float) $benefitDefaults['value'];
            $benefitOtherText = '';
        }

        return [
            'login' => $this->sanitizeLogin((string) ($context['login'] ?? $request->input('login', ''))),
            'operation_type' => $this->determineUpgradeOperation(
                (string) ($currentPlanOption['id'] ?? $currentPlan),
                (string) ($selectedPlanOption['id'] ?? $selectedPlan),
                $currentTechnologyFamily,
                $selectedTechnologyFamily,
                is_array($currentPlanOption) ? $currentPlanOption : [],
                is_array($selectedPlanOption) ? $selectedPlanOption : [],
                (float) ($context['current_monthly_value'] ?? 0),
                $monthlyValue
            ),
            'plano_atual' => trim((string) $request->input('plano_atual', $context['current_plan'] ?? '')),
            'current_plan_id' => trim((string) ($currentPlanOption['id'] ?? $currentPlan)),
            'current_plan_name' => trim((string) ($currentPlanOption['name'] ?? $currentPlan)),
            'tecnologia_atual' => $this->resolveTechnologyLabel(
                (string) $request->input('tecnologia_atual', $currentTechnology),
                is_array($currentPlanOption) ? $currentPlanOption : [],
                $currentPlan
            ),
            'novo_plano' => $selectedPlan,
            'new_plan_id' => trim((string) ($selectedPlanOption['id'] ?? $selectedPlan)),
            'new_plan_name' => trim((string) ($selectedPlanOption['name'] ?? $selectedPlan)),
            'nova_tecnologia' => $this->resolveTechnologyLabel(
                (string) $request->input('nova_tecnologia', $selectedTechnology),
                is_array($selectedPlanOption) ? $selectedPlanOption : [],
                $selectedPlan
            ),
            'nova_tecnologia_family' => $selectedTechnologyFamily,
            'benefit_flags' => $benefitFlags,
            'beneficio_concedido' => $benefitDescription,
            'beneficio_outro_text' => $benefitOtherText,
            'valor_beneficio' => $benefitValue,
            'novo_valor_mensal' => $this->normalizeMoney((string) $request->input('novo_valor_mensal', (string) $monthlyValue)),
            'retention_condition' => !empty($benefitFlags['retention']),
            'apply_fidelity' => $applyFidelity,
            'fidelity_benefit_description' => $fidelityBenefitDescription,
            'adhesion_waiver_mode' => $waiverMode,
            'manual_adhesion_waiver' => $waiverMode === 'manual' && !empty($benefitFlags['adhesion_waiver']),
            'fidelidade_meses' => $fidelityMonths,
            'observacao' => trim((string) $request->input('observacao', $context['observacao'] ?? '')),
            'signature_mode' => 'local',
            'remote_signature_reason' => '',
            'review_confirmed' => true,
        ];
    }

    private function validateUpgrade(array $data, array $context): array
    {
        $errors = [];

        $operationType = (string) ($data['operation_type'] ?? '');
        if (!in_array($operationType, ['upgrade', 'migration', 'downgrade'], true)) {
            $errors['novo_plano'] = 'O plano selecionado não produz uma mudança efetiva ou não pôde ser classificado.';
        }

        if (trim((string) ($data['plano_atual'] ?? '')) === '') {
            $errors['plano_atual'] = 'Não foi possível identificar o plano atual.';
        }

        if (trim((string) ($data['novo_plano'] ?? '')) === '') {
            $errors['novo_plano'] = 'Selecione o novo plano.';
        }

        if (trim((string) ($data['tecnologia_atual'] ?? '')) === '') {
            $errors['tecnologia_atual'] = 'Não foi possível identificar a tecnologia atual.';
        }

        if ($this->normalizeMoney((string) ($data['novo_valor_mensal'] ?? '0')) <= 0) {
            $errors['novo_plano'] = 'O plano selecionado não possui valor mensal válido.';
        }

        if (trim((string) ($context['current_plan'] ?? '')) === '') {
            $errors['plano_atual'] = 'Não foi possível identificar o plano atual do cliente.';
        }

        $currentPlanId = trim((string) ($data['current_plan_id'] ?? $data['plano_atual'] ?? ''));
        $newPlanId = trim((string) ($data['new_plan_id'] ?? $data['novo_plano'] ?? ''));
        $currentFamily = $this->resolveTechnologyFamily(
            $this->findPlanOptionByName($currentPlanId, is_array($context['planOptions'] ?? null) ? $context['planOptions'] : []) ?? [],
            (string) ($data['tecnologia_atual'] ?? ''),
            (string) ($data['current_plan_name'] ?? $data['plano_atual'] ?? '')
        );
        $newFamily = $this->resolveTechnologyFamily(
            $this->findPlanOptionByName($newPlanId, is_array($context['planOptions'] ?? null) ? $context['planOptions'] : []) ?? [],
            (string) ($data['nova_tecnologia'] ?? ''),
            (string) ($data['new_plan_name'] ?? $data['novo_plano'] ?? '')
        );

        if ($operationType === 'migration') {
            if ($currentFamily === '' || $newFamily === '') {
                $errors['novo_plano'] = 'Não foi possível confirmar as tecnologias da migração.';
            } elseif ($currentFamily === $newFamily) {
                $errors['novo_plano'] = 'A operação foi classificada incorretamente: tecnologias iguais não são migração.';
            }
        }

        if ($currentPlanId !== '' && $newPlanId !== '' && strcasecmp($currentPlanId, $newPlanId) === 0) {
            $errors['novo_plano'] = 'Selecione um plano diferente do atual.';
        }

        if (!empty($data['retention_condition']) && trim((string) ($data['observacao'] ?? '')) === '') {
            $errors['observacao'] = 'Justifique a condição comercial de retenção.';
        }

        if (!empty($data['apply_fidelity'])) {
            if ((float) ($data['valor_beneficio'] ?? 0) <= 0) {
                $errors['valor_beneficio'] = 'A fidelidade exige benefício real com valor informado.';
            }
            if (trim((string) ($data['fidelity_benefit_description'] ?? '')) === '') {
                $errors['fidelity_benefit_description'] = 'Descreva o benefício que justifica a fidelidade.';
            }
            if ((int) ($data['fidelidade_meses'] ?? 0) < 1 || (int) ($data['fidelidade_meses'] ?? 0) > 12) {
                $errors['fidelidade_meses'] = 'Informe prazo entre 1 e 12 meses.';
            }
        }

        return $errors;
    }

    private function syncUpgradeContractArtifacts(
        array $data,
        array $context,
        Request $request,
        bool $dispatchNotifications = true,
        ?array $supersededContract = null,
        int $existingProcessId = 0
    ): array
    {
        try {
            $this->messageTemplateRepository->ensureDefaults($this->defaultMessageTemplates());
        } catch (\Throwable $exception) {
            $this->recordAudit('contract.templates.sync_failed', 'message_template', null, [
                'login' => (string) ($context['login'] ?? ''),
                'error' => $exception->getMessage(),
            ], $request);
        }

        if (is_array($supersededContract)) {
            $context['supersedes_contract_id'] = (int) ($supersededContract['id'] ?? 0);
            $context['revision_number'] = max(2, (int) ($supersededContract['revision_number'] ?? 1) + 1);
        }

        $contractData = $this->buildUpgradeContractData($data, $context, $request);
        $contractId = $this->contractRepository->create($contractData) ?? 0;
        if ($contractId <= 0) {
            throw new \RuntimeException('Contrato de upgrade nao pôde ser gravado.');
        }

        $contractData['id'] = $contractId;
        $contractData['contract_id'] = $contractId;
        $termBody = $this->buildContractTermBody($contractData);
        $termHash = hash('sha256', $termBody);
        $acceptanceData = $this->buildUpgradeAcceptanceData($contractId, $data, $contractData, $termHash, $request);
        $acceptanceId = $this->contractAcceptanceRepository->create($acceptanceData) ?? 0;
        if ($acceptanceId <= 0) {
            throw new \RuntimeException('Aceite de upgrade nao pôde ser gravado.');
        }

        $acceptanceRecord = $this->contractAcceptanceRepository->findById($acceptanceId) ?? array_merge($acceptanceData, ['id' => $acceptanceId]);
        $contractData['acceptance_id'] = $acceptanceId;
        $processContext = [
            'login' => (string) $contractData['mkauth_login'],
            'client_name' => (string) ($contractData['nome_cliente'] ?? ''),
            'email' => (string) ($context['clientProfile']['email'] ?? ''),
            'upgrade_snapshot' => $this->extractUpgradeSnapshot($contractData),
            'signature_mode' => (string) ($data['signature_mode'] ?? 'remote'),
            'revision_reason' => (string) ($context['correction_reason'] ?? $supersededContract['cancellation_reason'] ?? ''),
        ];
        $process = $existingProcessId > 0
            ? $this->operationalProcessService->reviseMigration(
                $existingProcessId,
                $contractData,
                is_array($acceptanceRecord) ? $acceptanceRecord : array_merge($acceptanceData, ['id' => $acceptanceId]),
                $processContext,
                $this->resolveUser()
            )
            : $this->operationalProcessService->ensureForContract(
                OperationalProcessService::TYPE_MIGRATION,
                $contractData,
                is_array($acceptanceRecord) ? $acceptanceRecord : array_merge($acceptanceData, ['id' => $acceptanceId]),
                $processContext,
                $this->resolveUser()
            );
        $this->recordAudit('contract.upgrade.created', 'client_contract', $contractId, [
            'login' => (string) $contractData['mkauth_login'],
            'contract_id' => $contractId,
            'acceptance_id' => $acceptanceId,
            'status' => 'assinatura_pendente',
            'tipo_aceite' => 'upgrade_migracao',
        ], $request);
        $this->recordAudit('contract.acceptance.created', 'contract_acceptance', $acceptanceId, [
            'login' => (string) $contractData['mkauth_login'],
            'contract_id' => $contractId,
            'status' => 'assinatura_pendente',
        ], $request);

        $notificationDraft = [
            'nome_completo' => (string) ($context['clientProfile']['nome'] ?? $contractData['nome_cliente'] ?? 'Cliente'),
            'celular' => (string) ($contractData['telefone_cliente'] ?? ''),
            'email' => (string) ($context['clientProfile']['email'] ?? ''),
            'email_original' => (string) ($context['clientProfile']['email'] ?? ''),
            'has_real_email' => trim((string) ($context['clientProfile']['email'] ?? '')) !== '' && strtolower(trim((string) ($context['clientProfile']['email'] ?? ''))) !== 'cliente@ievo.com.br',
        ];

        $sendWhatsapp = trim((string) ($notificationDraft['celular'] ?? '')) !== '';
        $sendEmail = (bool) ($notificationDraft['has_real_email'] ?? false);

        if ($dispatchNotifications && ($data['signature_mode'] ?? 'remote') === 'remote' && ($sendWhatsapp || $sendEmail)) {
            try {
                $this->dispatchAcceptanceChannels($contractData, is_array($acceptanceRecord) ? $acceptanceRecord : $acceptanceData, $notificationDraft, $sendWhatsapp, $sendEmail, false, $request);
            } catch (\Throwable $exception) {
                $this->recordAudit('contract.upgrade.dispatch_failed', 'contract_acceptance', $acceptanceId, [
                    'contract_id' => $contractId,
                    'error' => $exception->getMessage(),
                ], $request);
            }
        }

        return [
            'process_id' => (int) ($process['id'] ?? 0),
            'contract_id' => $contractId,
            'acceptance_id' => $acceptanceId,
            'signature_mode' => (string) ($data['signature_mode'] ?? 'remote'),
            'token' => (string) ($acceptanceData['token'] ?? ''),
            'contract' => $contractData,
            'acceptance' => is_array($acceptanceRecord) ? $acceptanceRecord : $acceptanceData,
            'notification_draft' => $notificationDraft,
            'send_whatsapp' => $sendWhatsapp,
            'send_email' => $sendEmail,
        ];
    }

    private function revisePendingUpgradeArtifacts(
        int $processId,
        array $data,
        array $context,
        Request $request,
        array $contract,
        string $reason
    ): array {
        if ($processId <= 0 || trim($reason) === '') {
            throw new \RuntimeException('O processo e o motivo da correção são obrigatórios.');
        }

        $contractId = (int) ($contract['id'] ?? 0);
        $oldAcceptance = $this->contractAcceptanceRepository->findLatestByContractId($contractId);
        if (!is_array($oldAcceptance)
            || !in_array((string) ($oldAcceptance['status'] ?? ''), ['criado', 'enviado', 'assinatura_pendente'], true)
            || trim((string) ($oldAcceptance['revoked_at'] ?? '')) !== ''
        ) {
            throw new \RuntimeException('A condição só pode ser editada no processo atual enquanto o aceite estiver pendente.');
        }

        $context['revision_number'] = max(2, (int) ($contract['revision_number'] ?? 1) + 1);
        $context['correction_reason'] = $reason;
        $contractData = $this->buildUpgradeContractData($data, $context, $request);
        $contractData['id'] = $contractId;
        $contractData['contract_id'] = $contractId;
        $this->contractRepository->updateById($contractId, $contractData);

        $operator = $this->resolveUser();
        $this->contractAcceptanceRepository->revoke(
            (int) $oldAcceptance['id'],
            $reason,
            isset($operator['id']) ? (int) $operator['id'] : null,
            (string) ($operator['login'] ?? ''),
            false
        );

        $termBody = $this->buildContractTermBody($contractData);
        $acceptanceData = $this->buildUpgradeAcceptanceData($contractId, $data, $contractData, hash('sha256', $termBody), $request);
        $acceptanceId = (int) ($this->contractAcceptanceRepository->create($acceptanceData) ?? 0);
        if ($acceptanceId <= 0) {
            throw new \RuntimeException('O novo aceite da revisão não pôde ser criado.');
        }
        $acceptance = $this->contractAcceptanceRepository->findById($acceptanceId) ?? array_merge($acceptanceData, ['id' => $acceptanceId]);
        $process = $this->operationalProcessService->reviseMigration(
            $processId,
            $contractData,
            $acceptance,
            [
                'login' => (string) $contractData['mkauth_login'],
                'client_name' => (string) ($contractData['nome_cliente'] ?? ''),
                'email' => (string) ($context['clientProfile']['email'] ?? ''),
                'upgrade_snapshot' => $this->extractUpgradeSnapshot($contractData),
                'signature_mode' => (string) ($data['signature_mode'] ?? 'local'),
                'revision_reason' => $reason,
            ],
            $operator
        );
        $this->recordAudit('contract.upgrade.pending_condition_revised', 'client_contract', $contractId, [
            'process_id' => $processId,
            'previous_acceptance_id' => (int) $oldAcceptance['id'],
            'acceptance_id' => $acceptanceId,
            'revision' => (int) ($contractData['revision_number'] ?? 1),
            'reason' => $reason,
            'before' => $this->extractUpgradeSnapshot($contract),
            'after' => $this->extractUpgradeSnapshot($contractData),
        ], $request);

        return [
            'process_id' => (int) ($process['id'] ?? $processId),
            'contract_id' => $contractId,
            'acceptance_id' => $acceptanceId,
            'contract' => $contractData,
            'acceptance' => $acceptance,
        ];
    }

    private function dispatchUpgradeAcceptanceAfterCommit(array $result, Request $request): void
    {
        if (($result['signature_mode'] ?? 'remote') !== 'remote') {
            return;
        }

        $sendWhatsapp = !empty($result['send_whatsapp']);
        $sendEmail = !empty($result['send_email']);
        if (!$sendWhatsapp && !$sendEmail) {
            return;
        }

        $this->dispatchAcceptanceChannels(
            is_array($result['contract'] ?? null) ? $result['contract'] : [],
            is_array($result['acceptance'] ?? null) ? $result['acceptance'] : [],
            is_array($result['notification_draft'] ?? null) ? $result['notification_draft'] : [],
            $sendWhatsapp,
            $sendEmail,
            false,
            $request
        );
    }

    private function findPendingDigitalContractAcceptance(array $contracts): ?array
    {
        $eligibleTypes = [
            'contrato_digital',
            'nova_instalacao',
            'regularizacao_contrato',
            'alteracao_plano',
            'renovacao_fidelidade',
        ];

        foreach ($contracts as $contract) {
            if (!is_array($contract) || !in_array((string) ($contract['tipo_aceite'] ?? ''), $eligibleTypes, true)) {
                continue;
            }

            $contractId = (int) ($contract['id'] ?? 0);
            if ($contractId <= 0) {
                continue;
            }

            try {
                $acceptance = $this->contractAcceptanceRepository->findLatestByContractId($contractId);
            } catch (\Throwable) {
                $acceptance = null;
            }

            if (!is_array($acceptance)) {
                continue;
            }

            if (in_array((string) ($acceptance['status'] ?? ''), ['criado', 'enviado', 'assinatura_pendente'], true)) {
                return [
                    'contract' => $contract,
                    'acceptance' => $acceptance,
                ];
            }

            return null;
        }

        return null;
    }

    private function dispatchDigitalContractAcceptance(
        array $contract,
        array $acceptance,
        array $clientProfile,
        bool $forceResend,
        Request $request
    ): void {
        $notificationDraft = [
            'nome_completo' => (string) ($clientProfile['nome'] ?? $contract['nome_cliente'] ?? 'Cliente'),
            'celular' => (string) ($clientProfile['celular'] ?? $clientProfile['fone'] ?? $contract['telefone_cliente'] ?? ''),
            'telefone_cliente' => (string) ($clientProfile['celular'] ?? $clientProfile['fone'] ?? $contract['telefone_cliente'] ?? ''),
            'email' => (string) ($clientProfile['email'] ?? ''),
            'email_original' => (string) ($clientProfile['email'] ?? ''),
            'has_real_email' => trim((string) ($clientProfile['email'] ?? '')) !== '' && strtolower(trim((string) ($clientProfile['email'] ?? ''))) !== 'cliente@ievo.com.br',
        ];

        $sendWhatsapp = preg_replace('/\D+/', '', (string) ($notificationDraft['celular'] ?? '')) !== '';
        $sendEmail = (bool) ($notificationDraft['has_real_email'] ?? false);

        if (!$sendWhatsapp && !$sendEmail) {
            return;
        }

        $this->dispatchAcceptanceChannels($contract, $acceptance, $notificationDraft, $sendWhatsapp, $sendEmail, $forceResend, $request);
    }

    private function hasRecentDigitalContractNotification(int $contractId, int $acceptanceId, int $seconds = 10): bool
    {
        if ($contractId <= 0 || $acceptanceId <= 0) {
            return false;
        }

        $seconds = max(1, min(60, $seconds));

        try {
            $row = $this->database->fetchOne(
                'SELECT id
                 FROM notification_logs
                 WHERE contract_id = :contract_id
                   AND acceptance_id = :acceptance_id
                   AND created_at >= DATE_SUB(NOW(), INTERVAL ' . (int) $seconds . ' SECOND)
                 ORDER BY id DESC
                 LIMIT 1',
                [
                    'contract_id' => $contractId,
                    'acceptance_id' => $acceptanceId,
                ]
            );
        } catch (\Throwable) {
            return false;
        }

        return is_array($row) && isset($row['id']);
    }

    private function buildDigitalContractData(string $login, array $clientProfile): array
    {
        $operator = $this->resolveUser();
        $operatorLogin = $this->sanitizeLogin((string) ($operator['login'] ?? ''));
        if ($operatorLogin === '') {
            $operatorLogin = 'full_users';
        }

        try {
            $registration = $this->localRepository->findLatestClientRegistrationByLogin($login);
        } catch (\Throwable) {
            $registration = null;
        }

        $planName = trim((string) ($clientProfile['plano_nome'] ?? $clientProfile['plano'] ?? ''));
        $planValue = $this->normalizeMoney((string) ($clientProfile['plano_valor'] ?? '0'));
        $technology = trim((string) ($clientProfile['plano_tecnologia'] ?? ''));
        $dueDay = trim((string) ($clientProfile['venc'] ?? ''));
        $address = $this->formatClientProfileAddress($clientProfile);

        $observacao = trim(implode("\n", array_filter([
            'Contrato digital independente gerado a partir do cadastro atual no MkAuth.',
            $planName !== '' ? 'Plano atual: ' . $planName : null,
            $planValue > 0 ? 'Valor mensal atual: R$ ' . number_format($planValue, 2, ',', '.') : null,
            $technology !== '' ? 'Tecnologia atual: ' . $technology : null,
            $dueDay !== '' ? 'Vencimento: dia ' . $dueDay : null,
            $address !== '' ? 'Endereco cadastrado: ' . $address : null,
        ], static fn (?string $value): bool => $value !== null && trim($value) !== '')));

        return [
            'client_id' => is_array($registration) && isset($registration['id']) ? (int) $registration['id'] : null,
            'mkauth_login' => $login,
            'technician_name' => (string) ($operator['name'] ?? $operatorLogin),
            'technician_login' => $operatorLogin,
            'nome_cliente' => (string) ($clientProfile['nome'] ?? 'Cliente'),
            'telefone_cliente' => (string) ($clientProfile['celular'] ?? $clientProfile['fone'] ?? ''),
            'tipo_adesao' => 'isenta',
            'valor_adesao' => '0.00',
            'parcelas_adesao' => '1',
            'valor_parcela_adesao' => '0.00',
            'vencimento_primeira_parcela' => null,
            'fidelidade_meses' => (string) max(1, (int) $this->config->get('contracts.commercial.fidelidade_meses_padrao', 12)),
            'beneficio_valor' => '0.00',
            'multa_total' => '0.00',
            'tipo_aceite' => 'contrato_digital',
            'observacao_adesao' => $observacao,
            'upgrade_snapshot_json' => null,
            'status_financeiro' => 'dispensado',
        ];
    }

    private function buildDigitalContractAcceptanceData(
        int $contractId,
        array $contractData,
        string $termHash,
        Request $request,
        string $remoteSignatureReason,
        string $signatureMode = 'remote'
    ): array
    {
        $technician = $this->resolveTechnicianIdentity();

        return $this->acceptanceWorkflowService->prepare([
            'contract_id' => $contractId,
            'technician_name' => $technician['name'],
            'technician_login' => $technician['login'],
            'status' => 'assinatura_pendente',
            'phone' => (string) ($contractData['telefone_cliente'] ?? ''),
            'signature_mode' => $signatureMode,
            'remote_signature_reason' => $signatureMode === 'remote' ? trim($remoteSignatureReason) : null,
            'ip_address' => (string) $request->server('REMOTE_ADDR', ''),
            'user_agent' => (string) $request->header('User-Agent', ''),
            'document_version' => (string) $this->config->get('contracts.term_version', '2026.1')
                . '-r' . max(1, (int) ($contractData['revision_number'] ?? 1)),
            'term_hash' => $termHash,
        ]);
    }

    private function formatClientProfileAddress(array $clientProfile): string
    {
        return trim(implode(', ', array_filter([
            trim((string) ($clientProfile['endereco'] ?? '')),
            trim((string) ($clientProfile['numero'] ?? '')),
            trim((string) ($clientProfile['complemento'] ?? '')),
            trim((string) ($clientProfile['bairro'] ?? '')),
            trim((string) ($clientProfile['cidade'] ?? '')),
            trim((string) ($clientProfile['estado'] ?? '')),
            trim((string) ($clientProfile['cep'] ?? '')),
        ], static fn (string $value): bool => $value !== '')));
    }

    private function buildUpgradeContractData(array $data, array $context, Request $request): array
    {
        $operator = $this->resolveUser();
        $operatorLogin = $this->sanitizeLogin((string) ($operator['login'] ?? ''));
        if ($operatorLogin === '' && isset($operator['source']) && (string) $operator['source'] !== 'fallback') {
            $operatorLogin = $this->sanitizeLogin((string) ($operator['name'] ?? ''));
        }
        if ($operatorLogin === '') {
            $operatorLogin = 'full_users';
        }

        $clientProfile = is_array($context['clientProfile'] ?? null) ? $context['clientProfile'] : [];
        $contract = is_array($context['contract'] ?? null) ? $context['contract'] : [];
        $registration = is_array($context['registration'] ?? null) ? $context['registration'] : [];
        $currentPlan = (string) ($data['plano_atual'] ?? $context['current_plan'] ?? '');
        $newPlan = (string) ($data['novo_plano'] ?? $context['new_plan'] ?? '');
        $currentPlanOption = $this->findPlanOptionByName($currentPlan, is_array($context['planOptions'] ?? null) ? $context['planOptions'] : []);
        $newPlanOption = $this->findPlanOptionByName($newPlan, is_array($context['planOptions'] ?? null) ? $context['planOptions'] : []);
        $currentPlanName = trim((string) ($currentPlanOption['name'] ?? $data['current_plan_name'] ?? $currentPlan));
        $newPlanName = trim((string) ($newPlanOption['name'] ?? $data['new_plan_name'] ?? $newPlan));
        $currentTechnology = $this->resolveTechnologyLabel(
            (string) ($data['tecnologia_atual'] ?? $context['current_technology'] ?? ''),
            is_array($currentPlanOption) ? $currentPlanOption : [],
            $currentPlan
        );
        $newTechnology = $this->resolveTechnologyLabel(
            (string) ($data['nova_tecnologia'] ?? $context['new_technology'] ?? ''),
            is_array($newPlanOption) ? $newPlanOption : [],
            $newPlan
        );
        $originalContractReference = trim((string) ($context['original_contract_reference'] ?? ''));
        $newTechnologyFamily = $this->resolveTechnologyFamily(is_array($newPlanOption) ? $newPlanOption : [], $newTechnology, $newPlan);
        $benefitDefaults = $this->resolveUpgradeBenefitDefaults(
            $currentTechnology,
            $newTechnology,
            $currentPlanName,
            $newPlanName,
            (float) ($data['valor_mensal_atual'] ?? ($context['current_monthly_value'] ?? 0)),
            (float) ($data['novo_valor_mensal'] ?? ($context['new_monthly_value'] ?? 0))
        );
        $currentMonthlyValue = (float) ($data['valor_mensal_atual'] ?? ($context['current_monthly_value'] ?? 0));
        $newMonthlyValue = $this->resolvePlanMonthlyValue($newPlan, is_array($context['planOptions'] ?? null) ? $context['planOptions'] : []);
        if ($newMonthlyValue === null) {
            $newMonthlyValue = (float) ($data['novo_valor_mensal'] ?? ($context['new_monthly_value'] ?? 0));
        }
        $benefitFlags = $this->normalizeUpgradeBenefitFlags($data['benefit_flags'] ?? null);
        if ($benefitFlags === []) {
            $benefitFlags = $benefitDefaults['flags'];
        }
        $benefitOtherText = trim((string) ($data['beneficio_outro_text'] ?? ''));
        $benefitDescription = $this->buildUpgradeBenefitDescription($benefitFlags, $benefitOtherText);
        if ($benefitDescription === '') {
            $benefitDescription = $benefitDefaults['description'];
        }
        $snapshot = [
            'operation_type' => (string) ($data['operation_type'] ?? 'upgrade'),
            'current_plan_id' => (string) ($currentPlanOption['id'] ?? $data['current_plan_id'] ?? $currentPlan),
            'current_plan_name' => $currentPlanName,
            'current_plan' => $currentPlanName,
            'current_technology' => $currentTechnology,
            'current_technology_family' => $this->resolveTechnologyFamily(is_array($currentPlanOption) ? $currentPlanOption : [], $currentTechnology, $currentPlan),
            'current_monthly_value' => $currentMonthlyValue,
            'new_plan_id' => (string) ($newPlanOption['id'] ?? $data['new_plan_id'] ?? $newPlan),
            'new_plan_name' => $newPlanName,
            'new_plan' => $newPlanName,
            'new_technology' => $newTechnology,
            'new_technology_family' => $newTechnologyFamily,
            'benefit_flags' => $benefitFlags,
            'benefit_description' => $benefitDescription !== '' ? $benefitDescription : (string) ($data['beneficio_concedido'] ?? $context['benefit_description'] ?? ''),
            'benefit_other_text' => $benefitOtherText,
            'benefit_value' => (float) ($data['valor_beneficio'] ?? $benefitDefaults['value']),
            'adhesion_default_value' => (float) $this->config->get('contracts.commercial.valor_adesao_padrao', 0),
            'adhesion_charged_value' => !empty($benefitFlags['adhesion_waiver'])
                ? 0.0
                : (float) $this->config->get('contracts.commercial.valor_adesao_padrao', 0),
            'adhesion_waiver_mode' => $this->radioFiberAdhesionWaiverMode(),
            'new_monthly_value' => (float) ($newMonthlyValue ?? 0),
            'retention_condition' => !empty($data['retention_condition']),
            'commercial_reason' => !empty($data['retention_condition']) ? 'retention' : 'standard_change',
            'apply_fidelity' => !empty($data['apply_fidelity']),
            'fidelity_benefit_description' => (string) ($data['fidelity_benefit_description'] ?? ''),
            'fidelity_months' => !empty($data['apply_fidelity']) ? (int) ($data['fidelidade_meses'] ?? 0) : 0,
            'observacao' => (string) ($data['observacao'] ?? ''),
            'multa_proporcional' => (float) ($context['multa_proporcional'] ?? 0),
            'original_contract_reference' => $originalContractReference,
            'manual_mkauth' => 'Ajuste operacional manual após aceite.',
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => (string) ($operator['name'] ?? $operatorLogin),
            'created_by_login' => $operatorLogin,
            'captured_at' => date('Y-m-d H:i:s'),
            'captured_by' => (string) ($operator['name'] ?? $operatorLogin),
            'captured_by_login' => $operatorLogin,
            'review_confirmed' => true,
        ];

        $observacaoLines = [];
        if ($snapshot['benefit_description'] !== '') {
            $observacaoLines[] = 'Benefício concedido: ' . $snapshot['benefit_description'];
        }
        if ($snapshot['observacao'] !== '') {
            $observacaoLines[] = 'Observação: ' . $snapshot['observacao'];
        }
        $adhesionChargedValue = !empty($benefitFlags['adhesion_waiver'])
            ? 0.0
            : (float) $this->config->get('contracts.commercial.valor_adesao_padrao', 0);

        return [
            'client_id' => isset($registration['id']) ? (int) $registration['id'] : null,
            'mkauth_login' => (string) ($context['login'] ?? ''),
            'technician_name' => (string) ($operator['name'] ?? $operatorLogin),
            'technician_login' => $operatorLogin,
            'nome_cliente' => (string) ($clientProfile['nome'] ?? $contract['nome_cliente'] ?? '-'),
            'telefone_cliente' => (string) ($clientProfile['celular'] ?? $clientProfile['fone'] ?? $contract['telefone_cliente'] ?? ''),
            'tipo_adesao' => !empty($benefitFlags['adhesion_waiver']) ? 'isenta' : 'cheia',
            'valor_adesao' => number_format($adhesionChargedValue, 2, '.', ''),
            'parcelas_adesao' => '1',
            'valor_parcela_adesao' => number_format($adhesionChargedValue, 2, '.', ''),
            'vencimento_primeira_parcela' => null,
            'fidelidade_meses' => (string) (!empty($data['apply_fidelity']) ? max(1, min(12, (int) ($data['fidelidade_meses'] ?? 0))) : 0),
            'beneficio_valor' => $this->normalizeMoney((string) ($data['valor_beneficio'] ?? '0')),
            'multa_total' => $this->normalizeMoney((string) ($context['multa_proporcional'] ?? 0)),
            'beneficio_concedido_por' => (string) ($operator['name'] ?? $operatorLogin),
            'tipo_aceite' => 'upgrade_migracao',
            'observacao_adesao' => trim(implode("\n", $observacaoLines)),
            'upgrade_snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'status_financeiro' => 'dispensado',
            'lifecycle_status' => 'active',
            'supersedes_contract_id' => isset($context['supersedes_contract_id']) && (int) $context['supersedes_contract_id'] > 0
                ? (int) $context['supersedes_contract_id']
                : null,
            'superseded_by_contract_id' => null,
            'revision_number' => max(1, (int) ($context['revision_number'] ?? 1)),
            'cancellation_reason' => null,
            'cancelled_at' => null,
            'cancelled_by_user_id' => null,
            'cancelled_by_login' => null,
        ];
    }

    private function buildUpgradeAcceptanceData(int $contractId, array $data, array $contractData, string $termHash, Request $request): array
    {
        $technician = $this->resolveTechnicianIdentity();

        return $this->acceptanceWorkflowService->prepare([
            'contract_id' => $contractId,
            'technician_name' => $technician['name'],
            'technician_login' => $technician['login'],
            'status' => 'assinatura_pendente',
            'phone' => (string) ($contractData['telefone_cliente'] ?? ''),
            'signature_mode' => (string) ($data['signature_mode'] ?? 'remote'),
            'remote_signature_reason' => ($data['signature_mode'] ?? 'remote') === 'remote'
                ? trim((string) ($data['remote_signature_reason'] ?? ''))
                : null,
            'ip_address' => (string) $request->server('REMOTE_ADDR', ''),
            'user_agent' => (string) $request->header('User-Agent', ''),
            'document_version' => (string) $this->config->get('contracts.term_version', '2026.1'),
            'term_hash' => $termHash,
        ]);
    }

    private function extractUpgradeSnapshot(array $contract): array
    {
        $raw = trim((string) ($contract['upgrade_snapshot_json'] ?? ''));
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function resolvePlanMonthlyValue(string $planName, array $plans = []): ?float
    {
        $planName = trim($planName);
        if ($planName === '') {
            return null;
        }

        if ($plans === []) {
            $plans = $this->loadPlans();
        }

        foreach ($plans as $plan) {
            $id = trim((string) ($plan['id'] ?? ''));
            $name = trim((string) ($plan['name'] ?? ''));
            $label = trim((string) ($plan['label'] ?? ''));
            $value = trim((string) ($plan['value'] ?? ''));

            if (($id !== '' && strcasecmp($id, $planName) === 0) || ($name !== '' && strcasecmp($name, $planName) === 0)) {
                return $value !== '' ? (float) str_replace(',', '.', $value) : null;
            }

            if ($label !== '' && strcasecmp(strtok($label, ' -'), $planName) === 0) {
                return $value !== '' ? (float) str_replace(',', '.', $value) : null;
            }
        }

        return null;
    }

    private function findPlanOptionByName(string $planName, array $plans): ?array
    {
        $planName = trim($planName);
        if ($planName === '') {
            return null;
        }

        foreach ($plans as $plan) {
            if (!is_array($plan)) {
                continue;
            }

            $id = trim((string) ($plan['id'] ?? ''));
            $name = trim((string) ($plan['name'] ?? ''));
            $label = trim((string) ($plan['label'] ?? ''));

            if (($id !== '' && strcasecmp($id, $planName) === 0)
                || ($name !== '' && strcasecmp($name, $planName) === 0)
                || ($label !== '' && strcasecmp($label, $planName) === 0)
                || ($label !== '' && strcasecmp(trim((string) strtok($label, ' -')), $planName) === 0)
            ) {
                return $plan;
            }
        }

        return null;
    }

    private function resolveTechnologyLabelForPlan(array $plan): string
    {
        return $this->technologyMapper->label((string) ($plan['technology'] ?? $plan['tecnologia'] ?? ''));
    }

    private function resolveTechnologyFamily(array $plan = [], string $technology = '', string $planName = ''): string
    {
        $raw = trim((string) ($plan['technology'] ?? $plan['tecnologia'] ?? ''));
        return $this->technologyMapper->family($raw !== '' ? $raw : $technology);
    }

    private function resolveTechnologyLabel(string $rawTechnology, array $plan = [], string $planName = ''): string
    {
        $raw = trim((string) ($plan['technology'] ?? $plan['tecnologia'] ?? ''));
        return $this->technologyMapper->label($raw !== '' ? $raw : $rawTechnology);
    }

    private function determineUpgradeOperation(
        string $currentPlanId,
        string $newPlanId,
        string $currentFamily,
        string $newFamily,
        array $currentPlan,
        array $newPlan,
        float $currentMonthlyValue,
        float $newMonthlyValue
    ): string {
        if ($currentPlanId === '' || $newPlanId === '' || strcasecmp($currentPlanId, $newPlanId) === 0) {
            return '';
        }

        if ($currentFamily !== '' && $newFamily !== '' && $currentFamily !== $newFamily) {
            return 'migration';
        }

        if ($currentFamily === '' || $newFamily === '') {
            return '';
        }

        $currentSpeed = $this->normalizePlanSpeed((string) ($currentPlan['speed_down'] ?? ''));
        $newSpeed = $this->normalizePlanSpeed((string) ($newPlan['speed_down'] ?? ''));
        if ($currentSpeed !== null && $newSpeed !== null && abs($currentSpeed - $newSpeed) > 0.001) {
            return $newSpeed > $currentSpeed ? 'upgrade' : 'downgrade';
        }

        if (abs($currentMonthlyValue - $newMonthlyValue) > 0.005) {
            return $newMonthlyValue > $currentMonthlyValue ? 'upgrade' : 'downgrade';
        }

        return '';
    }

    private function normalizePlanSpeed(string $rawSpeed): ?float
    {
        $rawSpeed = strtolower(trim($rawSpeed));
        if ($rawSpeed === '' || !preg_match('/([0-9]+(?:[.,][0-9]+)?)\s*([kmg])?/', $rawSpeed, $matches)) {
            return null;
        }

        $value = (float) str_replace(',', '.', $matches[1]);
        return match ($matches[2] ?? '') {
            'g' => $value * 1000,
            'k' => $value / 1000,
            default => $value,
        };
    }

    private function resolveUpgradeBenefitDefaults(string $currentTechnology, string $newTechnology, string $currentPlan = '', string $newPlan = '', float $currentMonthlyValue = 0.0, float $newMonthlyValue = 0.0): array
    {
        $currentTechnologyNormalized = self::normalizeTextForMatch($currentTechnology);
        $newTechnologyNormalized = self::normalizeTextForMatch($newTechnology);
        $movingFromRadioToFiber = (
            str_contains($currentTechnologyNormalized, 'radio')
            && str_contains($newTechnologyNormalized, 'fibra')
        );

        $waiverMode = $this->radioFiberAdhesionWaiverMode();
        $adhesionWaiver = $movingFromRadioToFiber && $waiverMode === 'automatic';
        $flags = [
            'radio_to_fiber' => $movingFromRadioToFiber,
            'adhesion_waiver' => $adhesionWaiver,
            'plan_upgrade' => !$movingFromRadioToFiber && $newMonthlyValue > 0.0 && $currentMonthlyValue > 0.0 && $newMonthlyValue >= $currentMonthlyValue,
            'retention' => false,
            'other_benefit' => false,
        ];

        if ($flags['radio_to_fiber']) {
            return [
                'flags' => $flags,
                'description' => $adhesionWaiver
                    ? 'migração de tecnologia de rádio para fibra óptica, com isenção da taxa de adesão/instalação conforme regra configurada'
                    : 'migração de tecnologia de rádio para fibra óptica',
                'value' => $adhesionWaiver
                    ? (float) $this->config->get('contracts.commercial.valor_adesao_padrao', 0)
                    : 0.00,
            ];
        }

        return [
            'flags' => $flags,
            'description' => !empty($flags['plan_upgrade']) ? 'upgrade de plano' : '',
            'value' => 0.00,
        ];
    }

    private function radioFiberAdhesionWaiverMode(): string
    {
        $mode = strtolower(trim((string) $this->config->get(
            'contracts.commercial.modo_isencao_adesao_migracao_radio_fibra',
            ''
        )));
        if (in_array($mode, ['automatic', 'disabled', 'manual'], true)) {
            return $mode;
        }

        return (bool) $this->config->get('contracts.commercial.isentar_adesao_migracao_radio_fibra', false)
            ? 'automatic'
            : 'disabled';
    }

    private function normalizeUpgradeBenefitFlags(mixed $rawFlags): array
    {
        if (is_string($rawFlags) && trim($rawFlags) !== '') {
            $decoded = json_decode($rawFlags, true);
            if (is_array($decoded)) {
                $rawFlags = $decoded;
            }
        }

        if (!is_array($rawFlags)) {
            return [];
        }

        $map = [
            'radio_to_fiber' => false,
            'adhesion_waiver' => false,
            'plan_upgrade' => false,
            'retention' => false,
            'other_benefit' => false,
        ];

        foreach ($map as $key => $default) {
            $value = $rawFlags[$key] ?? $rawFlags[str_replace('_', '-', $key)] ?? null;
            $map[$key] = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            $map[$key] = $map[$key] ?? in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
        }

        return $map;
    }

    private function buildUpgradeBenefitDescription(array $flags, string $otherText = ''): string
    {
        $activeFlags = array_filter($flags, static fn ($value): bool => filter_var($value, FILTER_VALIDATE_BOOL));

        if (count($activeFlags) === 2 && !empty($flags['radio_to_fiber']) && !empty($flags['adhesion_waiver'])) {
            return 'migração de tecnologia de rádio para fibra óptica, com isenção da taxa de adesão/instalação';
        }

        if (count($activeFlags) === 2 && !empty($flags['plan_upgrade']) && !empty($flags['adhesion_waiver'])) {
            return 'upgrade de plano, com isenção da taxa de adesão/instalação';
        }

        $parts = [];

        if (!empty($flags['radio_to_fiber'])) {
            $parts[] = 'migração de tecnologia de rádio para fibra óptica';
        }

        if (!empty($flags['adhesion_waiver'])) {
            $parts[] = 'isenção da taxa de adesão/instalação';
        }

        if (!empty($flags['plan_upgrade'])) {
            $parts[] = 'upgrade de plano';
        }

        if (!empty($flags['retention'])) {
            $parts[] = 'condição comercial especial para retenção do cliente';
        }

        if (!empty($flags['other_benefit'])) {
            $otherText = trim($otherText);
            $parts[] = $otherText !== '' ? $otherText : 'outro benefício';
        }

        $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $value): bool => $value !== ''));
        if ($parts === []) {
            return '';
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        $last = array_pop($parts);
        return implode(', ', $parts) . ' e ' . $last;
    }

    private function buildAcceptanceData(int $contractId, array $data, array $contractData, string $termHash, Request $request): array
    {
        $technician = $this->resolveTechnicianIdentity();
        $remoteSignature = $this->normalizeBoolean((string) ($data['assinatura_remota'] ?? '0'));
        $remoteReason = trim((string) ($data['assinatura_remota_motivo'] ?? ''));

        return $this->acceptanceWorkflowService->prepare([
            'contract_id' => $contractId,
            'technician_name' => $technician['name'],
            'technician_login' => $technician['login'],
            'status' => $remoteSignature ? 'assinatura_pendente' : 'criado',
            'phone' => (string) ($contractData['telefone_cliente'] ?? ''),
            'signature_mode' => $remoteSignature ? 'remote' : 'local',
            'remote_signature_reason' => $remoteSignature ? $remoteReason : null,
            'ip_address' => (string) $request->server('REMOTE_ADDR', ''),
            'user_agent' => (string) $request->header('User-Agent', ''),
            'document_version' => (string) $this->config->get('contracts.term_version', '2026.1'),
            'term_hash' => $termHash,
        ]);
    }

    private function buildFinancialTaskData(int $contractId, array $contractData): array
    {
        $valorAdesao = $this->normalizeMoney((string) ($contractData['valor_adesao'] ?? '0'));
        $parcelas = max(1, (int) ($contractData['parcelas_adesao'] ?? 1));
        $tipoAdesao = (string) ($contractData['tipo_adesao'] ?? 'cheia');
        $fidelidade = max(0, (int) ($contractData['fidelidade_meses'] ?? 12));

        return [
            'contract_id' => $contractId,
            'mkauth_login' => (string) ($contractData['mkauth_login'] ?? ''),
            'titulo' => 'Lançar adesão cliente ' . (string) ($contractData['nome_cliente'] ?? ''),
            'descricao' => sprintf(
                "Valor: R$ %s\nParcelas: %d\nTipo: %s\nFidelidade: %d meses",
                number_format($valorAdesao, 2, ',', '.'),
                $parcelas,
                $tipoAdesao,
                $fidelidade
            ),
            'setor' => 'financeiro',
            'status' => 'aberto',
        ];
    }

    private function buildCorrectionTicketPayload(array $draft, array $contract, array $correction): array
    {
        $login = trim((string) ($contract['mkauth_login'] ?? $draft['login'] ?? ''));
        $clientName = trim((string) ($contract['nome_cliente'] ?? $draft['nome_completo'] ?? 'Cliente'));
        $phoneOld = trim((string) ($correction['original_whatsapp'] ?? ''));
        $phoneNew = trim((string) ($correction['corrected_whatsapp'] ?? ''));
        $emailOld = trim((string) ($correction['original_email'] ?? ''));
        $emailNew = trim((string) ($correction['corrected_email'] ?? ''));
        $reason = trim((string) ($correction['reason'] ?? ''));
        $channels = is_array($correction['channels'] ?? null) ? $correction['channels'] : [];
        $channelLabel = $channels !== []
            ? implode(', ', array_map(static fn (string $channel): string => ucfirst($channel), $channels))
            : '-';

        $providerName = $this->resolveProviderDisplayName();
        $description = implode("\n", array_filter([
            'Solicitação automática de ' . $providerName . '.',
            '',
            'O técnico corrigiu o contato durante a etapa de aceite.',
            '',
            'Login: ' . $login,
            'Cliente: ' . $clientName,
            '',
            'WhatsApp anterior: ' . ($phoneOld !== '' ? $phoneOld : '-'),
            'WhatsApp corrigido: ' . ($phoneNew !== '' ? $phoneNew : '-'),
            '',
            'E-mail anterior: ' . ($emailOld !== '' ? $emailOld : '-'),
            'E-mail corrigido: ' . ($emailNew !== '' ? $emailNew : '-'),
            '',
            'Canal de reenvio: ' . $channelLabel,
            'Motivo informado pelo técnico:',
            $reason !== '' ? $reason : '-',
            '',
            'Ação solicitada:',
            'Conferir e atualizar o cadastro no MkAuth, se proceder.',
            '',
            'Importante:',
            'O aceite foi reenviado para o contato corrigido, mantendo o mesmo token e evidência da alteração.',
        ]));

        return [
            'login' => $login,
            'nome' => $clientName,
            'email' => (string) ($emailNew !== '' ? $emailNew : ($contract['email_cliente'] ?? $contract['email'] ?? $this->config->get('email.smtp_from', ''))),
            'telefone' => $phoneNew !== '' ? $phoneNew : (string) ($contract['telefone_cliente'] ?? ''),
            'assunto' => 'Cadastro - Corrigir Dados',
            'prioridade' => (string) $this->config->get('contracts.mkauth_ticket.priority', 'normal'),
            'descricao' => $description,
            'observacao' => $description,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractContactCorrections(array $record): array
    {
        $corrections = is_array($record['contact_corrections'] ?? null) ? $record['contact_corrections'] : [];

        return array_values(array_filter($corrections, static fn (mixed $item): bool => is_array($item)));
    }

    private function countContactCorrections(array $record): int
    {
        return count($this->extractContactCorrections($record));
    }

    private function dispatchAcceptanceChannels(
        array $contract,
        array $acceptance,
        array $draft,
        bool $sendWhatsapp,
        bool $sendEmail,
        bool $forceResend,
        Request $request
    ): array {
        $results = [];
        $contractId = (int) ($contract['id'] ?? 0);
        $acceptanceId = (int) ($acceptance['id'] ?? 0);
        $phone = preg_replace('/\D+/', '', (string) ($draft['telefone_cliente'] ?? $draft['celular'] ?? $contract['telefone_cliente'] ?? '')) ?? '';
        $emailContext = $this->resolveEmailContext(array_merge($contract, $draft));
        $emailRecipient = strtolower(trim((string) ($emailContext['email_cliente'] ?? '')));
        $hasRealEmail = (bool) ($emailContext['has_real_email'] ?? false);
        $acceptanceType = (string) ($contract['tipo_aceite'] ?? 'nova_instalacao');
        $templatePurpose = match ($acceptanceType) {
            'upgrade_migracao' => $forceResend ? 'migracao_reenviar_aceite' : 'migracao_solicitar_aceite',
            'contrato_digital' => $forceResend ? 'assinatura_avulsa_reenviar' : 'assinatura_avulsa_solicitar',
            default => $forceResend ? 'instalacao_reenviar_aceite' : 'instalacao_solicitar_aceite',
        };
        $templateValues = $this->migrationTemplateValues($contract, $acceptance, $draft);
        try {
            $this->notificationTemplateService->seedDefaults();
        } catch (\Throwable) {
            // Mantém compatibilidade com bancos ainda sem a migration 017.
        }

        if ($sendWhatsapp && $phone !== '') {
            $templateSnapshot = null;
            $template = $templatePurpose !== '' ? $this->messageTemplateRepository->findByPurpose($templatePurpose, 'whatsapp') : null;
            $enabled = is_array($template) ? json_decode((string) ($template['enabled_channels_json'] ?? '[]'), true) : [];
            if (is_array($template) && !empty($template['active']) && is_array($enabled) && in_array('whatsapp', $enabled, true)) {
                $rendered = $this->notificationTemplateService->render($template, $templateValues);
                $messages = [(string) $rendered['body']];
                $templateSnapshot = $rendered['template_snapshot'];
            } elseif ($templatePurpose !== '' && is_array($template)) {
                $results['whatsapp'] = ['status' => 'disabled', 'message' => 'Canal desabilitado no template.'];
                $messages = [];
            } else {
                $messages = $this->buildAcceptanceWhatsappMessages($draft, $contract, $acceptance);
            }
            if ($messages === []) {
                $sendWhatsapp = false;
            }
        }

        if ($sendWhatsapp && $phone !== '') {
            $response = $this->evotrixService->sendMessage($phone, $messages, $contractId, $acceptanceId, $forceResend);
            $results['whatsapp'] = $response;
            $this->recordAudit(
                !empty($response['repeated_attempt']) ? 'client.acceptance.whatsapp.duplicate_attempt' : ((string) ($response['status'] ?? '') === 'erro' ? 'client.acceptance.whatsapp.failed' : 'client.acceptance.whatsapp.sent'),
                'client_acceptance',
                $acceptanceId,
                [
                    'contract_id' => $contractId,
                    'recipient' => $phone,
                    'result' => $response,
                    'template_snapshot' => $templateSnapshot,
                ],
                $request
            );
        }

        if ($sendEmail && $hasRealEmail) {
            $templateSnapshot = null;
            $template = $templatePurpose !== '' ? $this->messageTemplateRepository->findByPurpose($templatePurpose, 'email') : null;
            $enabled = is_array($template) ? json_decode((string) ($template['enabled_channels_json'] ?? '[]'), true) : [];
            if (is_array($template) && !empty($template['active']) && is_array($enabled) && in_array('email', $enabled, true)) {
                $rendered = $this->notificationTemplateService->render($template, $templateValues);
                $subject = (string) $rendered['subject'];
                $textBody = (string) $rendered['body'];
                $htmlBody = '<p>' . nl2br(htmlspecialchars($textBody, ENT_QUOTES, 'UTF-8')) . '</p>';
                $templateSnapshot = $rendered['template_snapshot'];
            } elseif ($templatePurpose !== '' && is_array($template)) {
                $results['email'] = ['status' => 'disabled', 'message' => 'Canal desabilitado no template.'];
                $sendEmail = false;
            } else {
                [$subject, $htmlBody, $textBody] = $this->buildAcceptanceEmailMessage($draft, $contract, $acceptance);
            }
        }

        if ($sendEmail && $hasRealEmail) {
            $response = $this->emailService->sendAcceptanceEmail($emailRecipient, $subject, $htmlBody, $textBody, $contractId, $acceptanceId, $forceResend);
            $results['email'] = $response;
            $this->recordAudit(
                !empty($response['repeated_attempt']) ? 'client.acceptance.email.duplicate_attempt' : ((string) ($response['status'] ?? '') === 'erro' ? 'client.acceptance.email.failed' : 'client.acceptance.email.sent'),
                'client_acceptance',
                $acceptanceId,
                [
                    'contract_id' => $contractId,
                    'recipient' => $emailRecipient,
                    'result' => $response,
                    'template_snapshot' => $templateSnapshot,
                ],
                $request
            );
        }

        return $results;
    }

    private function migrationTemplateValues(array $contract, array $acceptance, array $draft): array
    {
        $snapshot = $this->extractUpgradeSnapshot($contract);
        return [
            'nomecliente' => (string) ($draft['nome_completo'] ?? $contract['nome_cliente'] ?? ''),
            'nomeresumido' => (string) ($draft['nome_resumido'] ?? ''),
            'documentocliente' => '',
            'logincliente' => (string) ($contract['mkauth_login'] ?? ''),
            'telefonecliente' => (string) ($draft['telefone_cliente'] ?? $draft['celular'] ?? $contract['telefone_cliente'] ?? ''),
            'emailcliente' => (string) ($draft['email'] ?? ''),
            'planoatual' => (string) ($snapshot['current_plan_name'] ?? $snapshot['current_plan'] ?? ''),
            'novoplano' => (string) ($snapshot['new_plan_name'] ?? $snapshot['new_plan'] ?? ''),
            'valoratual' => isset($snapshot['current_monthly_value']) ? 'R$ ' . number_format((float) $snapshot['current_monthly_value'], 2, ',', '.') : '',
            'novovalor' => isset($snapshot['new_monthly_value']) ? 'R$ ' . number_format((float) $snapshot['new_monthly_value'], 2, ',', '.') : '',
            'tecnologiaatual' => (string) ($snapshot['current_technology'] ?? ''),
            'novatecnologia' => (string) ($snapshot['new_technology'] ?? ''),
            'beneficio' => (string) ($snapshot['benefit_description'] ?? ''),
            'fidelidade' => (int) ($snapshot['fidelity_months'] ?? 0) > 0 ? (int) $snapshot['fidelity_months'] . ' meses' : 'não aplicada',
            'linkaceite' => $this->buildAcceptanceLink($acceptance),
            'data' => date('d/m/Y'),
            'nomeprovedor' => $this->resolveProviderDisplayName(),
            'protocoloprocesso' => 'MIG-' . str_pad((string) ((int) ($contract['id'] ?? 0)), 6, '0', STR_PAD_LEFT),
        ];
    }

    private function dispatchAutomaticFinancialTicket(int $contractId, array $contractData, int $taskId, Request $request): void
    {
        try {
            $response = $this->mkAuthTicketService->openFinancialTicket(
                $this->buildFinancialTicketPayload($contractData, $taskId)
            );
            $ticketId = $this->resolveTicketId($response);
            if ($ticketId !== null) {
                try {
                    $this->financialTaskRepository->updateTicketMetadata($taskId, [
                        'mkauth_ticket_id' => $ticketId,
                        'mkauth_ticket_status' => (string) ($response['status'] ?? 'aberto'),
                    ]);
                } catch (\Throwable) {
                }
            }

            $summary = sprintf(
                'HTTP %s · %sms%s',
                (string) ($response['http_status'] ?? '-'),
                (string) ($response['duration_ms'] ?? 0),
                $this->resolveTicketId($response) !== null ? ' · ID ' . $this->resolveTicketId($response) : ''
            );
            $this->financialTaskRepository->appendSystemNote(
                $taskId,
                '[' . date('Y-m-d H:i:s') . '] Chamado financeiro automatizado ' . (($response['dry_run'] ?? true) ? 'simulado' : 'aberto') . ' no MkAuth. Endpoint: ' . (string) ($response['endpoint'] ?? '/api/chamado/inserir') . '. ' . $summary,
                'em_andamento'
            );
            $this->recordAudit('client.financial_task.ticket.auto_created', 'financial_task', $taskId, [
                'contract_id' => $contractId,
                'ticket_id' => $ticketId ?? null,
                'response' => $response,
            ], $request);

            if (!empty($response['message_fallback_used']) && !empty($response['sis_msg_id'])) {
                $this->recordAudit('client.financial_task.ticket.message_inserted', 'financial_task', $taskId, [
                    'contract_id' => $contractId,
                    'ticket_id' => $this->resolveTicketId($response),
                    'sis_msg_id' => (int) $response['sis_msg_id'],
                    'fallback_status' => (string) ($response['message_fallback_status'] ?? ''),
                ], $request);
            } elseif (($response['message_fallback_status'] ?? '') === 'failed') {
                $this->recordAudit('client.financial_task.ticket.message_failed', 'financial_task', $taskId, [
                    'contract_id' => $contractId,
                    'ticket_id' => $this->resolveTicketId($response),
                    'error' => (string) ($response['message_fallback_error'] ?? ''),
                ], $request);
            }
        } catch (\Throwable $exception) {
            $this->financialTaskRepository->appendSystemNote(
                $taskId,
                '[' . date('Y-m-d H:i:s') . '] Falha ao abrir chamado financeiro automatico: ' . $exception->getMessage()
            );
            $this->recordAudit('client.financial_task.ticket.auto_failed', 'financial_task', $taskId, [
                'contract_id' => $contractId,
                'error' => $exception->getMessage(),
            ], $request);
        }
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

    private function hasAutomaticFinancialTicket(int $contractId, ?int $taskId = null): bool
    {
        try {
            $logs = $this->localRepository->auditLogsForContract($contractId, null, $taskId, null, 100);
        } catch (\Throwable) {
            return false;
        }

        foreach ($logs as $log) {
            if ((string) ($log['action'] ?? '') === 'client.financial_task.ticket.auto_created') {
                return true;
            }
        }

        return false;
    }

    private function buildAcceptanceWhatsappMessages(array $draft, array $contract, array $acceptance): array
    {
        $company = $this->resolveProviderDisplayName();
        $customer = trim((string) ($draft['nome_completo'] ?? $contract['nome_cliente'] ?? 'Cliente'));
        $technician = $this->resolveTechnicianDisplayName($contract, $draft);
        $link = $this->buildAcceptanceLink($acceptance);
        $ttl = (string) $this->config->get('contracts.commercial.validade_link_aceite_horas', 48);
        $centralAssinanteUrl = $this->resolveCentralAssinanteUrl();
        $companyOpening = $company === 'nossa equipe' ? 'nossa equipe' : 'a equipe ' . $company;
        $supportLine = $company === 'nossa equipe' ? 'fale com nossa equipe' : 'fale com a equipe ' . $company;
        $isUpgrade = (string) ($contract['tipo_aceite'] ?? '') === 'upgrade_migracao';
        $isDigitalContract = (string) ($contract['tipo_aceite'] ?? '') === 'contrato_digital';
        if ($isDigitalContract) {
            $first = "Olá, {$customer}.\n\nA equipe iEvo Technology preparou seu contrato digital para assinatura.\n\nConfira o termo e conclua a assinatura pelo link abaixo:\n\n{$link}\n\nEste link é pessoal, seguro e expira em {$ttl} horas.\n\nSe tiver qualquer dúvida, fale com a equipe iEvo Technology antes de confirmar.";

            return [trim($first)];
        }

        if ($isUpgrade) {
            $first = "Olá, {$customer}! 👋\n\nAqui é {$companyOpening}.\nO upgrade / migração do seu contrato foi preparado pelo técnico {$technician}.\n\nConfira o novo termo e conclua a assinatura remota pelo link que enviamos a seguir.\n\nDepois da confirmação, a alteração no MkAuth será aplicada manualmente pela operação.\n\nBoletos, faturas, notas e segunda via continuam disponíveis na Central do Assinante:\n{$centralAssinanteUrl}\n\nEste link é pessoal, seguro e expira em {$ttl} horas.\n\nSe tiver qualquer dúvida, {$supportLine} antes de confirmar.";
        } else {
            $first = "Olá, {$customer}! 👋\n\nAqui é {$companyOpening}.\nSeu cadastro foi realizado pelo técnico {$technician}.\n\nPara concluir com segurança, confira seus dados, plano contratado, valores e aceite digital pelo link que enviaremos a seguir.\n\nApós a confirmação, você poderá acessar pelo mesmo link a cópia do termo assinado.\n\nBoletos, faturas, notas e segunda via ficam disponíveis na Central do Assinante:\n{$centralAssinanteUrl}\n\nEste link é pessoal, seguro e expira em {$ttl} horas.\n\nSe tiver qualquer dúvida, {$supportLine} antes de confirmar.";
        }

        return [
            trim($first),
            $link,
        ];
    }

    private function buildAcceptanceEmailMessage(array $draft, array $contract, array $acceptance): array
    {
        $company = $this->resolveProviderDisplayName();
        $customer = trim((string) ($draft['nome_completo'] ?? $contract['nome_cliente'] ?? 'Cliente'));
        $technician = $this->resolveTechnicianDisplayName($contract, $draft);
        $link = $this->buildAcceptanceLink($acceptance);
        $ttl = (string) $this->config->get('contracts.commercial.validade_link_aceite_horas', 48);
        $centralAssinanteUrl = $this->resolveCentralAssinanteUrl();
        $companyOpening = $company === 'nossa equipe' ? 'nossa equipe' : 'a equipe ' . $company;
        $companyClosing = $company === 'nossa equipe' ? 'Nossa equipe' : 'Equipe ' . $company;
        $supportLine = $company === 'nossa equipe' ? 'fale com nossa equipe' : 'fale com a equipe ' . $company;
        $isUpgrade = (string) ($contract['tipo_aceite'] ?? '') === 'upgrade_migracao';
        $isDigitalContract = (string) ($contract['tipo_aceite'] ?? '') === 'contrato_digital';
        if ($isDigitalContract) {
            $subject = 'Contrato digital para assinatura - iEvo Technology';
            $text = "Olá, {$customer}.\n\nA equipe iEvo Technology preparou seu contrato digital para assinatura.\n\nConfira o termo e conclua a assinatura pelo link abaixo:\n\n{$link}\n\nEste link é pessoal, seguro e expira em {$ttl} horas.\n\nSe tiver qualquer dúvida, fale com a equipe iEvo Technology antes de confirmar.";
            $html = '<p>Olá, <strong>' . htmlspecialchars($customer, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
                . '<p>A equipe iEvo Technology preparou seu contrato digital para assinatura.</p>'
                . '<p>Confira o termo e conclua a assinatura pelo link abaixo:</p>'
                . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</a></p>'
                . '<p>Este link é pessoal, seguro e expira em ' . htmlspecialchars($ttl, ENT_QUOTES, 'UTF-8') . ' horas.</p>'
                . '<p>Se tiver qualquer dúvida, fale com a equipe iEvo Technology antes de confirmar.</p>';
        } elseif ($isUpgrade) {
            $subject = 'Upgrade / Migração - aceite digital - ' . $company;
            $text = "Olá, {$customer}!\n\nAqui é {$companyOpening}.\n\nO upgrade / migração do seu contrato foi preparado pelo técnico {$technician}.\n\nAcesse o link abaixo para conferir o termo e concluir a assinatura remota:\n\n{$link}\n\nApós a confirmação, a alteração no MkAuth será aplicada manualmente pela operação.\n\nBoletos, faturas, notas e segunda via continuam disponíveis na Central do Assinante:\n{$centralAssinanteUrl}\n\nEste link é pessoal, seguro e expira em {$ttl} horas.\n\nSe tiver qualquer dúvida, {$supportLine} antes de confirmar.\n\nAtenciosamente,\n{$companyClosing}";
            $html = '<p>Olá, <strong>' . htmlspecialchars($customer, ENT_QUOTES, 'UTF-8') . '</strong>!</p>'
                . '<p>Aqui é ' . htmlspecialchars($companyOpening, ENT_QUOTES, 'UTF-8') . '.</p>'
                . '<p>O upgrade / migração do seu contrato foi preparado pelo técnico ' . htmlspecialchars($technician, ENT_QUOTES, 'UTF-8') . '.</p>'
                . '<p>Acesse o link abaixo para conferir o termo e concluir a assinatura remota:</p>'
                . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</a></p>'
                . '<p>Após a confirmação, a alteração no MkAuth será aplicada manualmente pela operação.</p>'
                . '<p>Boletos, faturas, notas e segunda via continuam disponíveis na Central do Assinante:<br>'
                . htmlspecialchars($centralAssinanteUrl, ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p>Este link é pessoal, seguro e expira em ' . htmlspecialchars($ttl, ENT_QUOTES, 'UTF-8') . ' horas.</p>'
                . '<p>Se tiver qualquer dúvida, ' . htmlspecialchars($supportLine, ENT_QUOTES, 'UTF-8') . ' antes de confirmar.</p>'
                . '<p><strong>Atenciosamente,<br>' . htmlspecialchars($companyClosing, ENT_QUOTES, 'UTF-8') . '</strong></p>';
        } else {
            $subject = 'Aceite digital do contrato - ' . $company;
            $text = "Olá, {$customer}!\n\nAqui é {$companyOpening}.\n\nSeu cadastro foi realizado pelo técnico {$technician}.\n\nPara concluir com segurança, acesse o link abaixo e confira seus dados, plano contratado, valores e aceite digital:\n\n{$link}\n\nApós a confirmação, você poderá acessar pelo mesmo link a cópia do termo assinado.\n\nBoletos, faturas, notas e segunda via ficam disponíveis na Central do Assinante:\n{$centralAssinanteUrl}\n\nEste link é pessoal, seguro e expira em {$ttl} horas.\n\nSe tiver qualquer dúvida, {$supportLine} antes de confirmar.\n\nAtenciosamente,\n{$companyClosing}";
            $html = '<p>Olá, <strong>' . htmlspecialchars($customer, ENT_QUOTES, 'UTF-8') . '</strong>!</p>'
                . '<p>Aqui é ' . htmlspecialchars($companyOpening, ENT_QUOTES, 'UTF-8') . '.</p>'
                . '<p>Seu cadastro foi realizado pelo técnico ' . htmlspecialchars($technician, ENT_QUOTES, 'UTF-8') . '.</p>'
                . '<p>Para concluir com segurança, acesse o link abaixo e confira seus dados, plano contratado, valores e aceite digital:</p>'
                . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</a></p>'
                . '<p>Após a confirmação, você poderá acessar pelo mesmo link a cópia do termo assinado.</p>'
                . '<p>Boletos, faturas, notas e segunda via ficam disponíveis na Central do Assinante:<br>'
                . htmlspecialchars($centralAssinanteUrl, ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p>Este link é pessoal, seguro e expira em ' . htmlspecialchars($ttl, ENT_QUOTES, 'UTF-8') . ' horas.</p>'
                . '<p>Se tiver qualquer dúvida, ' . htmlspecialchars($supportLine, ENT_QUOTES, 'UTF-8') . ' antes de confirmar.</p>'
                . '<p><strong>Atenciosamente,<br>' . htmlspecialchars($companyClosing, ENT_QUOTES, 'UTF-8') . '</strong></p>';
        }

        return [$subject, $html, $text];
    }

    private function buildAcceptanceLink(array $acceptance): string
    {
        $tokenHash = trim((string) ($acceptance['token_hash'] ?? ''));

        if ($tokenHash === '') {
            return Url::absolute('/aceite/indisponivel');
        }

        return Url::absolute('/aceite/' . rawurlencode($tokenHash));
    }

    private function buildFinancialTicketPayload(array $contractData, ?int $taskId = null): array
    {
        $providerName = $this->resolveProviderDisplayName();
        $tipoAdesao = strtolower(trim((string) ($contractData['tipo_adesao'] ?? 'cheia')));
        $observacaoAdesao = trim((string) ($contractData['observacao_adesao'] ?? ''));
        $autorizadoPor = trim((string) ($contractData['beneficio_concedido_por'] ?? ''));
        $assunto = $tipoAdesao === 'isenta'
            ? 'Financeiro - Conferir Adesao Isenta'
            : 'Financeiro - Lancar Adesao';
        $valorAdesao = $this->normalizeMoney((string) ($contractData['valor_adesao'] ?? '0'));
        $parcelas = max(1, (int) ($contractData['parcelas_adesao'] ?? 1));
        $valorParcela = $parcelas > 0 ? ($valorAdesao / $parcelas) : 0.0;
        $aceiteId = (int) ($contractData['acceptance_id'] ?? 0);
        $observacaoNormalizada = strtolower(trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n"], ' ', $observacaoAdesao)) ?? ''));
        $autorizadoNormalizado = strtolower(trim((string) $autorizadoPor));
        $observacaoExtra = $observacaoAdesao !== '' && $observacaoNormalizada !== '' && $observacaoNormalizada !== $autorizadoNormalizado && !str_starts_with($observacaoNormalizada, 'autorizado por:') ? $observacaoAdesao : '';

        $description = implode("\n", array_filter([
            'Solicitação automática de ' . $providerName . '.',
            '',
            'Ação necessária: conferir e lançar manualmente a adesão deste cliente, se proceder.',
            '',
            'Login: ' . (string) ($contractData['mkauth_login'] ?? '-'),
            'Cliente: ' . (string) ($contractData['nome_cliente'] ?? '-'),
            '',
            'Tipo de adesão: ' . $tipoAdesao,
            'Valor total da adesão: R$ ' . number_format($valorAdesao, 2, ',', '.'),
            'Parcelas: ' . $parcelas . 'x de R$ ' . number_format($valorParcela, 2, ',', '.'),
            'Vencimento da primeira parcela: ' . (string) ($contractData['vencimento_primeira_parcela'] ?? '-'),
            '',
            'Autorizado por: ' . ($autorizadoPor !== '' ? $autorizadoPor : '-'),
            $observacaoExtra !== '' ? 'Observação da adesão: ' . $observacaoExtra : null,
            'Contrato ID: ' . (string) ($contractData['contract_id'] ?? 0),
            'Aceite ID: ' . ($aceiteId > 0 ? (string) $aceiteId : '-'),
            'Tarefa financeira ID: ' . (string) ($taskId ?? 0),
        ]));

        return [
            'login' => (string) ($contractData['mkauth_login'] ?? ''),
            'nome' => (string) ($contractData['nome_cliente'] ?? ''),
            'email' => (string) ($contractData['email_cliente'] ?? $contractData['email'] ?? $this->config->get('email.smtp_from', '')),
            'telefone' => (string) ($contractData['telefone_cliente'] ?? ''),
            'assunto' => $assunto,
            'prioridade' => (string) $this->config->get('contracts.mkauth_ticket.priority', 'normal'),
            'descricao' => $description,
            'msg' => $description,
            'observacao' => $description,
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
            // Fallback abaixo.
        }

        $appName = trim((string) $this->config->get('app.name', ''));
        if ($appName !== '' && !in_array(strtolower($appName), ['isp auxiliar', 'provedor', 'nossa equipe'], true)) {
            return $appName;
        }

        return 'nossa equipe';
    }

    private function resolveTechnicianDisplayName(array $contract, array $draft = []): string
    {
        $candidates = [
            (string) ($contract['technician_name'] ?? ''),
            (string) ($draft['technician_name'] ?? ''),
            (string) ($contract['created_by'] ?? ''),
            (string) ($contract['tecnico_nome'] ?? ''),
            (string) ($contract['tecnico'] ?? ''),
            (string) ($contract['accepted_by'] ?? ''),
            (string) ($draft['beneficio_concedido_por'] ?? ''),
            (string) ($contract['technician_login'] ?? ''),
            (string) ($draft['technician_login'] ?? ''),
        ];

        $genericNames = [
            'administrador local',
            'admin local',
            'administrador',
            'local',
            'operador',
            'usuario',
            'usuário',
            'equipe técnica',
            'equipe tecnica',
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && !in_array(strtolower($candidate), $genericNames, true)) {
                return $candidate;
            }
        }

        $user = $this->resolveUser();
        $userName = trim((string) ($user['name'] ?? ''));

        if ($userName !== '' && !in_array(strtolower($userName), $genericNames, true)) {
            return $userName;
        }

        $userLogin = trim((string) ($user['login'] ?? ''));

        return $userLogin !== '' && !in_array(strtolower($userLogin), $genericNames, true)
            ? $userLogin
            : 'Equipe iEvo Technology';
    }

    private function resolveTechnicianIdentity(): array
    {
        $user = $this->resolveUser();
        $name = trim((string) ($user['name'] ?? ''));
        $login = trim((string) ($user['login'] ?? ''));
        $genericNames = ['administrador local', 'admin local', 'administrador', 'local', 'operador', 'usuario', 'usuário', 'equipe técnica', 'equipe tecnica'];

        if (($name === '' || in_array(strtolower($name), $genericNames, true)) && $login !== '' && !in_array(strtolower($login), $genericNames, true)) {
            $name = $login;
        }

        if ($name === '' || in_array(strtolower($name), $genericNames, true)) {
            $name = 'Equipe iEvo Technology';
        }

        return [
            'name' => $name,
            'login' => $login,
        ];
    }

    private function resolveCentralAssinanteUrl(): string
    {
        $url = '';

        try {
            $url = trim((string) $this->localRepository->providerSetting('central_assinante_url', ''));
        } catch (\Throwable) {
            $url = '';
        }

        if ($url === '') {
            $url = trim((string) $this->config->get('contracts.commercial.central_assinante_url', 'https://sistema.ievo.com.br/central'));
        }

        return $url !== '' ? $url : 'https://sistema.ievo.com.br/central';
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

    private function buildContractTermBody(array $contractData): string
    {
        $upgradeSnapshot = $this->extractUpgradeSnapshot($contractData);
        $nome = (string) ($contractData['nome_cliente'] ?? '');
        $login = (string) ($contractData['mkauth_login'] ?? '');
        $telefone = (string) ($contractData['telefone_cliente'] ?? '');
        $tipoAdesao = (string) ($contractData['tipo_adesao'] ?? 'cheia');
        $valorAdesao = number_format((float) ($contractData['valor_adesao'] ?? 0), 2, ',', '.');
        $parcelas = (int) ($contractData['parcelas_adesao'] ?? 1);
        $valorParcela = number_format((float) ($contractData['valor_parcela_adesao'] ?? 0), 2, ',', '.');
        $fidelidade = (int) ($contractData['fidelidade_meses'] ?? 12);
        $autorizadoPor = trim((string) ($contractData['beneficio_concedido_por'] ?? ''));
        $observacao = (string) ($contractData['observacao_adesao'] ?? '');

        $providerName = $this->resolveProviderDisplayName();
        $contractTitle = $providerName === 'nossa equipe' ? 'Contrato digital da nossa equipe' : 'Contrato digital ' . $providerName;
        $technician = $this->resolveTechnicianIdentity();
        $centralAssinanteUrl = $this->resolveCentralAssinanteUrl();

        if ((string) ($contractData['tipo_aceite'] ?? '') === 'contrato_digital') {
            return trim(implode("\n", [
                'Contrato Digital de Prestação de Serviço',
                '',
                'Contratada: ' . $contractTitle,
                'Contratante: ' . $nome,
                'Login do cliente: ' . $login,
                'Telefone: ' . $telefone,
                'Responsável pela operação: ' . $this->resolveTechnicianDisplayName($contractData),
                '',
                'Resumo do contrato atual',
                $observacao !== '' ? $observacao : 'Dados atuais consultados no cadastro do cliente.',
                'Fidelidade: ' . $fidelidade . ' meses, quando aplicável ao plano contratado.',
                '',
                'Condições gerais',
                'O cliente confirma a ciência e concordância com o contrato de prestação de serviço vigente.',
                'O contrato digital não altera plano, valor, tecnologia, login, senha, roteador ou titularidade no MkAuth.',
                'Boletos, faturas, notas e segunda via podem ser consultados pela Central do Assinante:',
                $centralAssinanteUrl,
                '',
                'Assinatura eletrônica/remota',
                'O aceite eletrônico deste termo é realizado por link pessoal enviado ao cliente, com registro de IP, data, hora e dispositivo.',
            ]));
        }

        if ((string) ($contractData['tipo_aceite'] ?? '') === 'upgrade_migracao') {
            $currentPlan = trim((string) ($upgradeSnapshot['current_plan_name'] ?? $upgradeSnapshot['current_plan'] ?? ''));
            $currentTechnology = trim((string) ($upgradeSnapshot['current_technology'] ?? ''));
            $newPlan = trim((string) ($upgradeSnapshot['new_plan_name'] ?? $upgradeSnapshot['new_plan'] ?? ''));
            $newTechnology = trim((string) ($upgradeSnapshot['new_technology'] ?? ''));
            $originalContractReference = trim((string) ($upgradeSnapshot['original_contract_reference'] ?? ''));
            $benefitFlags = $this->normalizeUpgradeBenefitFlags($upgradeSnapshot['benefit_flags'] ?? null);
            $benefitDescription = trim((string) ($upgradeSnapshot['benefit_description'] ?? ''));
            $benefitValue = number_format((float) ($upgradeSnapshot['benefit_value'] ?? 0), 2, ',', '.');
            $monthlyValue = number_format((float) ($upgradeSnapshot['new_monthly_value'] ?? 0), 2, ',', '.');
            $fidelityMonths = max(0, min(12, (int) ($upgradeSnapshot['fidelity_months'] ?? 0)));
            $observation = trim((string) ($upgradeSnapshot['observacao'] ?? $observacao));
            $waiverApplied = !empty($benefitFlags['adhesion_waiver']);
            $benefitSentence = $waiverApplied
                ? 'Foi concedida a isenção da taxa de adesão/instalação, avaliada em R$ ' . $benefitValue . '.'
                : 'Benefício comercial concedido: ' . ($benefitDescription !== '' ? $benefitDescription : '-');
            $fidelityBenefit = trim((string) ($upgradeSnapshot['fidelity_benefit_description'] ?? ''));
            if ($fidelityBenefit === '') {
                $fidelityBenefit = $benefitDescription;
            }
            $fidelityLines = $fidelityMonths > 0
                ? [
                    'Cláusula Segunda: nova fidelidade expressamente aceita',
                    'Fidelidade: ' . $fidelityMonths . ' meses',
                    'Benefício vinculado à fidelidade: ' . ($fidelityBenefit !== '' ? $fidelityBenefit : '-'),
                    'A eventual multa observará o benefício registrado e o período restante, conforme as condições documentadas.',
                ]
                : [
                    'Cláusula Segunda: fidelidade',
                    'Nova fidelidade: não aplicada.',
                    'Esta alteração não renova automaticamente prazo de permanência.',
                ];

            return trim(implode("\n", [
                'Termo Aditivo ao Contrato de Prestação de Serviço',
                '',
                'Contratada: ' . $contractTitle,
                'Contratante: ' . $nome,
                'Técnico responsável: ' . $technician['name'],
                'Telefone: ' . $telefone,
                $originalContractReference !== '' ? 'Referência original: ' . $originalContractReference : null,
                '',
                'Cláusula Primeira: objeto e benefício',
                'Plano atual: ' . ($currentPlan !== '' ? $currentPlan : '-'),
                'Tecnologia atual: ' . ($currentTechnology !== '' ? $currentTechnology : '-'),
                'Novo plano: ' . ($newPlan !== '' ? $newPlan : '-'),
                'Nova tecnologia: ' . ($newTechnology !== '' ? $newTechnology : '-'),
                $benefitSentence,
                'Novo valor mensal: R$ ' . $monthlyValue,
                '',
                ...$fidelityLines,
                'As demais condições comerciais permanecem válidas, exceto o que este aditivo alterar expressamente.',
                '',
                'Cláusula Terceira: disposições gerais',
                'A alteração operacional no MkAuth será aplicada manualmente após a confirmação do aceite.',
                'As demais cláusulas do contrato original permanecem vigentes.',
                'Observação: ' . ($observation !== '' ? $observation : '-'),
                '',
                'Assinatura eletrônica/remota',
                'O aceite eletrônico deste termo é realizado por link enviado ao telefone cadastrado, com registro de IP, data, hora e dispositivo.',
                'A cópia do termo e os documentos de cobrança podem ser consultados pela Central do Assinante:',
                $centralAssinanteUrl,
            ]));
        }

        return trim(implode("\n", [
            $contractTitle,
            'Cliente: ' . $nome,
            'Técnico responsável: ' . $technician['name'],
            'Telefone: ' . $telefone,
            'Tipo de adesão: ' . $tipoAdesao,
            'Valor da adesão: R$ ' . $valorAdesao,
            'Parcelas da adesão: ' . $parcelas,
            'Valor por parcela: R$ ' . $valorParcela,
            'Fidelidade: ' . $fidelidade . ' meses',
            $autorizadoPor !== '' ? 'Autorizado por: ' . $autorizadoPor : 'Autorizado por: -',
            'Observação: ' . $observacao,
            '',
            'A taxa de adesão poderá ser concedida com desconto, isenção ou parcelamento, condicionada à fidelidade de 12 meses. O cancelamento antes do fim da fidelidade poderá gerar cobrança proporcional conforme condições contratadas.',
            'O aceite eletrônico deste termo é realizado por link enviado ao telefone cadastrado, com registro de IP, data, hora e dispositivo.',
            'A cópia do termo e os documentos de cobrança podem ser consultados pela Central do Assinante:',
            $centralAssinanteUrl,
            'O aceite é realizado por link enviado ao telefone por WhatsApp e/ou e-mail cadastrado. A confirmação por qualquer um desses canais valida o aceite eletrônico.',
        ]));
    }

    private function defaultMessageTemplates(): array
    {
        $ttlHours = max(1, (int) $this->config->get('contracts.commercial.validade_link_aceite_horas', 48));

        return [
            'aceite_nova_instalacao' => [
                'channel' => 'whatsapp',
                'purpose' => 'aceite_nova_instalacao',
                'body' => "Olá, {nome} 👋\n\nSeu cadastro foi realizado pelo técnico {tecnico_nome}.\n\nConfira seus dados, plano, valores e aceite digital no link que enviaremos a seguir.\n\nApós a confirmação, você poderá acessar pelo mesmo link a cópia do termo assinado.\n\nBoletos, faturas, notas e segunda via ficam disponíveis na Central do Assinante:\n{central_assinante_url}\n\nEste link é pessoal e expira em {$ttlHours} horas.",
                'variables_json' => ['nome', 'tecnico_nome', 'central_assinante_url', 'link_aceite'],
                'active' => 1,
            ],
            'aceite_regularizacao_contrato' => [
                'channel' => 'whatsapp',
                'purpose' => 'aceite_regularizacao_contrato',
                'body' => "Olá, {nome} 👋\n\nSeu contrato foi preparado para regularização pelo técnico {tecnico_nome}.\n\nConfira os dados e aceite digital no link que enviaremos a seguir.\n\nApós a confirmação, você poderá acessar pelo mesmo link a cópia do termo assinado.\n\nBoletos, faturas, notas e segunda via ficam disponíveis na Central do Assinante:\n{central_assinante_url}\n\nEste link é pessoal e expira em {$ttlHours} horas.",
                'variables_json' => ['nome', 'tecnico_nome', 'central_assinante_url', 'link_aceite'],
                'active' => 1,
            ],
        ];
    }

    private function calculateFirstBillingDate(string $dueDay): ?string
    {
        $dueDay = preg_replace('/\D+/', '', $dueDay) ?? '';
        $day = (int) $dueDay;

        if ($day < 1 || $day > 31) {
            return null;
        }

        $today = new \DateTimeImmutable('today');
        $currentMonth = $today->modify(sprintf('first day of this month'))->setDate(
            (int) $today->format('Y'),
            (int) $today->format('m'),
            min($day, (int) $today->format('t'))
        );

        if ($currentMonth >= $today) {
            return $currentMonth->format('Y-m-d');
        }

        $nextMonth = $today->modify('first day of next month')->setDate(
            (int) $today->modify('first day of next month')->format('Y'),
            (int) $today->modify('first day of next month')->format('m'),
            min($day, (int) $today->modify('last day of next month')->format('t'))
        );

        return $nextMonth->format('Y-m-d');
    }

    private function normalizeMoney(string $value): float
    {
        $value = trim($value);

        if ($value === '') {
            return 0.0;
        }

        $normalized = preg_replace('/[^\d,.\-]/', '', $value) ?? '';

        if ($normalized === '' || $normalized === '-' || $normalized === ',' || $normalized === '.') {
            return 0.0;
        }

        $lastComma = strrpos($normalized, ',');
        $lastDot = strrpos($normalized, '.');

        if ($lastComma !== false && $lastDot !== false) {
            $decimalSeparator = $lastComma > $lastDot ? ',' : '.';
            $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';
            $normalized = str_replace($thousandsSeparator, '', $normalized);

            if ($decimalSeparator === ',') {
                $normalized = str_replace(',', '.', $normalized);
            }
        } elseif ($lastComma !== false) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif ($lastDot !== false) {
            $dotCount = substr_count($normalized, '.');
            $decimalDigits = strlen(substr($normalized, $lastDot + 1));

            if ($dotCount > 1 || $decimalDigits === 3) {
                $normalized = str_replace('.', '', $normalized);
            }
        }

        return (float) $normalized;
    }

    private function normalizeDateInput(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        if (!$date || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $value;
    }

    private function resolveAdhesionValue(string $tipoAdesao, float $baseValue, float $promoValue, float $discountPercent): float
    {
        return match ($tipoAdesao) {
            'isenta' => 0.0,
            'promocional' => $promoValue > 0
                ? $promoValue
                : max(0.0, $baseValue - ($baseValue * max(0.0, $discountPercent) / 100)),
            default => max(0.0, $baseValue),
        };
    }

    private function contractCommercialConfig(): array
    {
        $commercial = $this->config->get('contracts.commercial', []);

        return [
            'valor_adesao_padrao' => (float) ($commercial['valor_adesao_padrao'] ?? 0),
            'valor_adesao_promocional' => (float) ($commercial['valor_adesao_promocional'] ?? 0),
            'percentual_desconto_promocional' => (float) ($commercial['percentual_desconto_promocional'] ?? 0),
            'parcelas_maximas_adesao' => max(1, (int) ($commercial['parcelas_maximas_adesao'] ?? 3)),
            'fidelidade_meses_padrao' => max(1, (int) ($commercial['fidelidade_meses_padrao'] ?? 12)),
            'multa_padrao' => (float) ($commercial['multa_padrao'] ?? 0),
            'exigir_validacao_cpf_aceite' => (bool) ($commercial['exigir_validacao_cpf_aceite'] ?? true),
            'quantidade_digitos_validacao_cpf' => max(1, (int) ($commercial['quantidade_digitos_validacao_cpf'] ?? 4)),
            'validade_link_aceite_horas' => max(1, (int) ($commercial['validade_link_aceite_horas'] ?? 48)),
        ];
    }

    private function recordAudit(string $action, string $entityType, ?int $entityId, array $context, Request $request): void
    {
        $user = $this->resolveUser();

        try {
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
        } catch (\Throwable) {
            // O fluxo principal nao deve falhar por indisponibilidade do log local.
        }
    }

    private function saveInstallationCheckpoint(array $record, ?int $registrationId = null, ?string $token = null): string
    {
        if ($token === null || !$this->isValidCheckpointToken($token)) {
            $token = bin2hex(random_bytes(16));
        }

        $record['token'] = $token;
        $record['updated_at'] = date('Y-m-d H:i:s');

        file_put_contents(
            $this->installationCheckpointPath($token),
            json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''
        );

        try {
            $this->localRepository->createInstallationCheckpoint($registrationId, $token, $record);
        } catch (\Throwable) {
            // O arquivo JSON continua sendo a trilha minima caso o banco local esteja indisponivel.
        }

        return $token;
    }

    private function findDraftRecordByLogin(string $login): ?array
    {
        $login = $this->sanitizeLogin($login);

        if ($login === '') {
            return null;
        }

        $directory = $this->projectRootPath() . '/storage/cache/client_drafts';

        if (!is_dir($directory)) {
            return null;
        }

        $latest = null;
        $latestTimestamp = 0;

        foreach (glob($directory . '/*.json') ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);

            if (!is_array($decoded)) {
                continue;
            }

            $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
            $draftLogin = $this->sanitizeLogin((string) ($data['login'] ?? ''));

            if ($draftLogin !== $login) {
                continue;
            }

            $timestamp = strtotime((string) ($decoded['created_at'] ?? $decoded['updated_at'] ?? '')) ?: filemtime($file) ?: time();

            if ($timestamp >= $latestTimestamp) {
                $latestTimestamp = $timestamp;
                $latest = [
                    'draft_id' => basename($file, '.json'),
                    'data' => $data,
                    'created_at' => (string) ($decoded['created_at'] ?? ''),
                    'updated_at' => (string) ($decoded['updated_at'] ?? ''),
                ];
            }
        }

        return $latest;
    }

    private function findCheckpointRecordByLogin(string $login): ?array
    {
        $login = $this->sanitizeLogin($login);

        if ($login === '') {
            return null;
        }

        $directory = $this->projectRootPath() . '/storage/installations';

        if (!is_dir($directory)) {
            return null;
        }

        $latest = null;
        $latestTimestamp = 0;

        foreach (glob($directory . '/*.json') ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);

            if (!is_array($decoded)) {
                continue;
            }

            $checkpointLogin = $this->sanitizeLogin((string) ($decoded['login'] ?? ''));

            if ($checkpointLogin !== $login) {
                continue;
            }

            $timestamp = strtotime((string) ($decoded['updated_at'] ?? $decoded['created_at'] ?? '')) ?: filemtime($file) ?: time();

            if ($timestamp >= $latestTimestamp) {
                $latestTimestamp = $timestamp;
                $latest = $decoded;
            }
        }

        return $latest;
    }

    private function loadInstallationCheckpoint(string $token): ?array
    {
        if (!$this->isValidCheckpointToken($token)) {
            return null;
        }

        $path = $this->installationCheckpointPath($token, false);

        if (!is_file($path)) {
            $checkpoint = $this->localRepository->findInstallationCheckpointByToken($token);

            return $this->hydrateCheckpointRecord($checkpoint);
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $checkpoint = $this->localRepository->findInstallationCheckpointByToken($token);

        return $this->hydrateCheckpointRecord($checkpoint);
    }

    private function updateInstallationCheckpoint(string $token, array $updates): void
    {
        $record = $this->loadInstallationCheckpoint($token);

        if ($record === null) {
            return;
        }

        $record = array_replace($record, $updates);
        $record['updated_at'] = date('Y-m-d H:i:s');

        $path = $this->installationCheckpointPath($token);
        $encoded = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

        if (file_put_contents($path, $encoded) === false) {
            // Mantem a trilha no banco local mesmo se o arquivo do checkpoint falhar.
        }

        try {
            $this->localRepository->updateInstallationCheckpoint($token, $record);
        } catch (\Throwable) {
            // Mantem compatibilidade com a trilha em arquivo.
        }
    }

    private function installationCheckpointPath(string $token, bool $createDirectory = true): string
    {
        $directory = $this->projectRootPath() . '/storage/installations';

        if ($createDirectory && !is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        return $directory . '/' . $token . '.json';
    }

    private function isValidCheckpointToken(string $token): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}$/', $token);
    }

    private function hydrateCheckpointRecord(?array $checkpoint): ?array
    {
        if (!is_array($checkpoint)) {
            return null;
        }

        $payload = json_decode((string) ($checkpoint['payload_json'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $formData = is_array($payload['form_data'] ?? null) ? $payload['form_data'] : [];
        $emailContext = $this->resolveEmailContext($formData);

        return array_replace($payload, [
            'token' => (string) ($checkpoint['token'] ?? ($payload['token'] ?? '')),
            'status' => (string) ($checkpoint['status'] ?? ($payload['status'] ?? 'awaiting_connection')),
            'login' => (string) ($checkpoint['mkauth_login'] ?? ($payload['login'] ?? '')),
            'updated_at' => (string) ($checkpoint['updated_at'] ?? ($payload['updated_at'] ?? '')),
            'created_at' => (string) ($checkpoint['created_at'] ?? ($payload['created_at'] ?? '')),
            'email_original' => (string) ($emailContext['email_original'] ?? ''),
            'email_cliente' => (string) ($emailContext['email_cliente'] ?? 'cliente@ievo.com.br'),
            'has_real_email' => (bool) ($emailContext['has_real_email'] ?? false),
        ]);
    }

    private function tableExists(string $table): bool
    {
        $table = trim($table);

        if ($table === '') {
            return false;
        }

        try {
            $result = $this->database->fetchOne('SHOW TABLES LIKE :table_name', ['table_name' => $table]);
        } catch (\Throwable) {
            return false;
        }

        return is_array($result);
    }

    private function normalizeBoolean(string $value): bool
    {
        $value = strtolower(trim($value));

        return in_array($value, ['1', 'true', 'yes', 'sim', 'on'], true);
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

    private function projectRootPath(): string
    {
        return dirname(__DIR__, 2);
    }
}
