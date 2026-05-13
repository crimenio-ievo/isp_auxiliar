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
use App\Infrastructure\Contracts\ContractRepository;
use App\Infrastructure\Contracts\FinancialTaskRepository;
use App\Infrastructure\Contracts\MessageTemplateRepository;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\MkAuth\MkAuthDatabase;
use App\Infrastructure\MkAuth\ClientProvisioner;
use App\Infrastructure\MkAuth\MkAuthTicketService;
use App\Infrastructure\Notifications\EmailService;
use App\Infrastructure\Notifications\EvotrixService;

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
        private MkAuthTicketService $mkAuthTicketService
    ) {
    }

    public function create(Request $request): Response
    {
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

        Flash::set('success', 'Dados iniciais e fotos salvos. Agora confirme o aceite e registre a assinatura.');

        $redirect = '/clientes/novo/aceite?draft=' . rawurlencode($draftId);
        if ($editingCheckpoint) {
            $redirect .= '&token=' . rawurlencode($checkpointToken);
        }

        return Response::redirect($redirect);
    }

    public function clearDraft(Request $request): Response
    {
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
            $value = $this->sanitizeLogin($value);
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

    private function collectFormData(Request $request): array
    {
        $commercial = $this->contractCommercialConfig();
        $document = preg_replace('/\D+/', '', (string) $request->input('cpf_cnpj', '')) ?? '';
        $login = $this->sanitizeLogin((string) $request->input('login', ''));
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
            $errors[] = 'Login invalido. Use apenas letras minusculas, numeros, underscore ou hifen, sem acentos ou espacos.';
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

        $originalLogin = $this->sanitizeLogin((string) ($originalData['login'] ?? ''));
        $currentLogin = $this->sanitizeLogin((string) $data['login']);

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

        if (strtolower(trim((string) ($data['aceite_cliente'] ?? 'nao'))) !== 'sim') {
            $errors[] = 'Confirme o aceite do cliente antes de concluir.';
        }

        if (trim((string) ($data['assinatura_cliente'] ?? '')) === '') {
            $errors[] = 'Registre a assinatura do cliente antes de concluir.';
        }

        return $errors;
    }

    private function collectAcceptanceData(Request $request): array
    {
        return [
            'aceite_cliente' => $request->input('aceite_cliente', 'nao'),
            'assinatura_cliente' => (string) $request->input('assinatura_cliente', ''),
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
            return [
                ['id' => 'FibraRural_40mbpsPromo', 'label' => 'FibraRural_40mbpsPromo', 'value' => '', 'install_type' => 'fibra', 'local_dici' => 'r'],
                ['id' => 'FibraUrbano_100mbps', 'label' => 'FibraUrbano_100mbps', 'value' => '', 'install_type' => 'fibra', 'local_dici' => 'u'],
                ['id' => 'RadioRural_10mbps', 'label' => 'RadioRural_10mbps', 'value' => '', 'install_type' => 'radio', 'local_dici' => 'r'],
            ];
        }

        return array_map(static function (array $plan): array {
            $name = trim((string) ($plan['nome'] ?? ''));
            $value = trim((string) ($plan['valor'] ?? ''));
            $normalized = self::normalizeTextForMatch($name);
            $installType = str_contains($normalized, 'radio') ? 'radio' : (str_contains($normalized, 'fibra') ? 'fibra' : '');
            $localDici = str_contains($normalized, 'rural') ? 'r' : (str_contains($normalized, 'urbano') ? 'u' : '');
            $label = $name;

            if ($value !== '') {
                $label .= ' - R$ ' . number_format((float) str_replace(',', '.', $value), 2, ',', '.');
            }

            return [
                'id' => $name,
                'label' => $label,
                'value' => $value,
                'install_type' => $installType,
                'local_dici' => $localDici,
            ];
        }, array_values(array_filter($plans, static fn (array $plan): bool => trim((string) ($plan['nome'] ?? '')) !== '')));
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

    private function sanitizeLogin(string $login): string
    {
        $login = trim($login);
        $login = $this->removeAccents($login);
        $login = strtolower($login);
        $login = preg_replace('/[^a-z0-9_.-]+/', '_', $login) ?? '';
        $login = preg_replace('/_+/', '_', $login) ?? $login;

        return trim($login, '_');
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

    private function buildAcceptanceData(int $contractId, array $data, array $contractData, string $termHash, Request $request): array
    {
        $ttlHours = max(1, (int) $this->config->get('contracts.commercial.validade_link_aceite_horas', 48));
        $expiresAt = (new \DateTimeImmutable())->modify('+' . $ttlHours . ' hours')->format('Y-m-d H:i:s');
        $plainToken = bin2hex(random_bytes(16));
        $technician = $this->resolveTechnicianIdentity();
        $acceptanceData = [
            'contract_id' => $contractId,
            'technician_name' => $technician['name'],
            'technician_login' => $technician['login'],
            'token' => $plainToken,
            'token_expires_at' => $expiresAt,
            'status' => 'criado',
            'telefone_enviado' => (string) ($contractData['telefone_cliente'] ?? ''),
            'whatsapp_message_id' => null,
            'sent_at' => null,
            'accepted_at' => null,
            'ip_address' => (string) $request->server('REMOTE_ADDR', ''),
            'user_agent' => (string) $request->header('User-Agent', ''),
            'termo_versao' => (string) $this->config->get('contracts.term_version', '2026.1'),
            'termo_hash' => $termHash,
            'pdf_path' => null,
            'evidence_json_path' => null,
        ];

        return $acceptanceData;
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

        if ($sendWhatsapp && $phone !== '') {
            $messages = $this->buildAcceptanceWhatsappMessages($draft, $contract, $acceptance);
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
                ],
                $request
            );
        }

        if ($sendEmail && $hasRealEmail) {
            [$subject, $htmlBody, $textBody] = $this->buildAcceptanceEmailMessage($draft, $contract, $acceptance);
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
                ],
                $request
            );
        }

        return $results;
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

        $first = "Olá, {$customer}! 👋\n\nAqui é {$companyOpening}.\nSeu cadastro foi realizado pelo técnico {$technician}.\n\nPara concluir com segurança, confira seus dados, plano contratado, valores e aceite digital pelo link que enviaremos a seguir.\n\nApós a confirmação, você poderá acessar pelo mesmo link a cópia do termo assinado.\n\nBoletos, faturas, notas e segunda via ficam disponíveis na Central do Assinante:\n{$centralAssinanteUrl}\n\nEste link é pessoal, seguro e expira em {$ttl} horas.\n\nSe tiver qualquer dúvida, {$supportLine} antes de confirmar.";

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
        $subject = 'Aceite digital do contrato - ' . $company;
        $companyOpening = $company === 'nossa equipe' ? 'nossa equipe' : 'a equipe ' . $company;
        $companyClosing = $company === 'nossa equipe' ? 'Nossa equipe' : 'Equipe ' . $company;
        $supportLine = $company === 'nossa equipe' ? 'fale com nossa equipe' : 'fale com a equipe ' . $company;
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

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        $user = $this->resolveUser();
        $userName = trim((string) ($user['name'] ?? ''));

        if ($userName !== '') {
            return $userName;
        }

        $userLogin = trim((string) ($user['login'] ?? ''));

        return $userLogin !== '' ? $userLogin : 'Equipe técnica';
    }

    private function resolveTechnicianIdentity(): array
    {
        $user = $this->resolveUser();
        $name = trim((string) ($user['name'] ?? ''));
        $login = trim((string) ($user['login'] ?? ''));

        if ($name === '' && $login !== '') {
            $name = $login;
        }

        if ($name === '') {
            $name = 'Equipe técnica';
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

        return trim(implode("\n", [
            $contractTitle,
            'Cliente: ' . $nome,
            'Login: ' . $login,
            'Técnico responsável: ' . $technician['name'],
            'Login do técnico: ' . $technician['login'],
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
