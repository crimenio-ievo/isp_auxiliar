<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Infrastructure\Contracts\ContractAcceptanceRepository;
use App\Infrastructure\Contracts\ContractRepository;
use App\Infrastructure\Contracts\FinancialTaskRepository;
use App\Infrastructure\Contracts\MessageTemplateRepository;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\MkAuth\MkAuthDatabase;
use App\Infrastructure\MkAuth\ClientProvisioner;

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
        private ClientProvisioner $provisioner,
        private MkAuthDatabase $mkauthDatabase,
        private LocalRepository $localRepository,
        private ContractRepository $contractRepository,
        private ContractAcceptanceRepository $contractAcceptanceRepository,
        private FinancialTaskRepository $financialTaskRepository,
        private MessageTemplateRepository $messageTemplateRepository
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

        $html = $this->view->render('clients/create', [
            'pageTitle' => 'Novo Cliente',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveUser(),
            'flash' => Flash::get(),
            'cities' => $cities,
            'form' => $formData,
            'draftId' => $draftId,
            'checkpointToken' => $checkpointToken,
            'draftMedia' => $draftMedia,
            'draftKey' => $draftKey,
            'clearDraftKeys' => $this->consumeClearDraftKeys(),
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
        $this->storeDraftPhotos($draftId, $request, $uploadedPhotoCount > 0 && ($existingDraftPhotos > 0 || $editingCheckpoint));

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
            'user' => $this->resolveUser(),
            'flash' => Flash::get(),
            'draftId' => $draftId,
            'draft' => $draft,
            'draftJson' => json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'checkpointToken' => $checkpointToken,
            'acceptanceDateTime' => date('d/m/Y H:i'),
            'acceptanceDateIso' => date('Y-m-d H:i:s'),
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
        $errors = $this->validateAcceptance($data, $request);

        if ($errors !== []) {
            Flash::set('error', implode(' ', $errors));
            $redirect = '/clientes/novo/aceite?draft=' . rawurlencode($draftId);
            if ($editingCheckpoint) {
                $redirect .= '&token=' . rawurlencode($checkpointToken);
            }

            return Response::redirect($redirect);
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
            $this->recordAudit('client.provision_failed', 'client_registration', null, [
                'login' => (string) ($data['login'] ?? ''),
                'error' => $exception->getMessage(),
            ], $request);
            Flash::set(
                'error',
                'Nao foi possivel concluir o envio ao MkAuth agora: ' . $exception->getMessage() . ' Seus dados foram mantidos para nova tentativa.'
            );

            return Response::redirect('/clientes/novo/aceite?draft=' . rawurlencode($draftId));
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
        $connectionToken = $this->saveInstallationCheckpoint([
            'status' => 'awaiting_connection',
            'login' => (string) ($payload['login'] ?? ''),
            'client_name' => (string) ($payload['nome'] ?? $data['nome_completo'] ?? ''),
            'plan' => (string) ($payload['plano'] ?? $data['plano'] ?? ''),
            'form_data' => $draft,
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => (string) ($this->resolveUser()['name'] ?? 'Operador'),
            'evidence_ref' => (string) ($data['evidence_ref'] ?? ''),
            'mkauth_message' => (string) $message,
        ], $registrationId, $connectionToken);
        $this->recordAudit('client.provisioned', 'client_registration', $registrationId, [
            'login' => (string) ($payload['login'] ?? ''),
            'action' => $action,
            'mkauth_status' => (string) ($response['status'] ?? ''),
        ], $request);

        Flash::set(
            ($response['status'] ?? 'sucesso') === 'sucesso' || ($response['status'] ?? '') === 'simulado' ? 'success' : 'error',
            $successLabel . ' ' . $message . ' Login: ' . ($payload['login'] ?? '-') . ' Agora valide a conexão do equipamento.' . ($evidence['summary'] !== '' ? ' ' . $evidence['summary'] : '')
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
            'user' => $this->resolveUser(),
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
        $canBypassAcceptance = $this->canManageContracts();

        if ($acceptanceState !== 'aceito' && !$canBypassAcceptance) {
            $statusLabel = (string) ($acceptanceStatus['label'] ?? 'pendente');
            Flash::set('error', 'Status do aceite: ' . $statusLabel . '. Aguarde a confirmação do cliente antes de finalizar.');
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
        if ($acceptanceState !== 'aceito' && $canBypassAcceptance) {
            $this->recordAudit('installation.completed_with_acceptance_override', 'installation_checkpoint', null, [
                'token' => $token,
                'login' => (string) ($record['login'] ?? ''),
                'acceptance_status' => $acceptanceState,
            ], $request);
        }
        $this->recordAudit('installation.completed', 'installation_checkpoint', null, [
            'token' => $token,
            'login' => (string) ($record['login'] ?? ''),
        ], $request);

        Flash::set('success', 'Instalação finalizada. O login está conectado no Radius.');

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
            'user' => $this->resolveUser(),
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
        return $_SESSION['user'] ?? [
            'name' => 'Operador',
            'login' => '',
            'role' => 'Operação',
            'source' => 'fallback',
        ];
    }

    private function canManageContracts(): bool
    {
        $user = $this->resolveUser();
        $role = strtolower(trim((string) ($user['role'] ?? '')));

        return in_array($role, ['manager', 'platform_admin', 'gestor', 'admin', 'administrador'], true)
            || !empty($user['can_manage']);
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
            $adhesionType = $installType === 'fibra' ? 'isenta' : 'cheia';
        }

        $maxInstallments = max(1, (int) ($commercial['parcelas_maximas_adesao'] ?? 3));
        $installments = (int) $request->input('parcelas_adesao', 1);
        $installments = max(1, min($maxInstallments, $installments > 0 ? $installments : 1));

        $baseValue = (float) ($commercial['valor_adesao_padrao'] ?? 0);
        $promoValue = (float) ($commercial['valor_adesao_promocional'] ?? 0);
        $discountPercent = (float) ($commercial['percentual_desconto_promocional'] ?? 0);
        $defaultValue = $this->resolveAdhesionValue($adhesionType, $baseValue, $promoValue, $discountPercent);
        $adhesionValue = $this->normalizeMoney((string) $request->input('valor_adesao', ''));
        if ($adhesionValue <= 0) {
            $adhesionValue = $defaultValue;
        }

        if ($adhesionType === 'isenta') {
            $adhesionValue = 0.0;
        }

        $benefitValue = $this->normalizeMoney((string) $request->input('beneficio_valor', ''));
        if ($benefitValue <= 0) {
            if ($adhesionType === 'isenta') {
                $benefitValue = $baseValue;
            } elseif ($adhesionType === 'promocional') {
                $benefitValue = max(0.0, $baseValue - $adhesionValue);
            } else {
                $benefitValue = max(0.0, $baseValue - $adhesionValue);
            }
        }

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
        $email = strtolower(trim((string) $request->input('email', '')));
        if ($email === '') {
            $email = 'cliente@ievo.com.br';
        }
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

        return [
            'pessoa' => $person,
            'nome_completo' => $request->input('nome_completo', ''),
            'nome_resumido' => $request->input('nome_resumido', ''),
            'email' => $email,
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
            'numero' => $request->input('numero', 'SN'),
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
            'recebe_emails' => 'sim',
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
            'user' => $this->resolveUser(),
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

            if ($uploadError !== UPLOAD_ERR_OK || !is_uploaded_file($tmpName)) {
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

            if ($uploadError !== UPLOAD_ERR_OK || !is_uploaded_file($tmpName)) {
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
        return (bool) preg_match('/^[a-z0-9_-]+$/', $login);
    }

    private function sanitizeLogin(string $login): string
    {
        $login = trim($login);
        $login = $this->removeAccents($login);
        $login = strtolower($login);
        $login = preg_replace('/[^a-z0-9_-]+/', '_', $login) ?? '';
        $login = preg_replace('/_+/', '_', $login) ?? $login;

        return trim($login, '_');
    }

    private function removeAccents(string $value): string
    {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $converted === false ? $value : $converted;
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

            $termBody = $this->buildContractTermBody($contractData);
            $termHash = hash('sha256', $termBody);
            $acceptanceData = $this->buildAcceptanceData($contractId, $data, $contractData, $termHash, $request);

            $acceptance = $this->contractAcceptanceRepository->findLatestByContractId($contractId);
            if (is_array($acceptance) && isset($acceptance['id'])) {
                $existingTokenHash = trim((string) ($acceptance['token_hash'] ?? ''));
                if ($existingTokenHash !== '') {
                    $acceptanceData['token_hash'] = $existingTokenHash;
                }
                $this->contractAcceptanceRepository->updateById((int) $acceptance['id'], $acceptanceData);
                $this->recordAudit('contract.acceptance.updated', 'contract_acceptance', (int) $acceptance['id'], [
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

            if (($contractData['status_financeiro'] ?? 'pendente_lancamento') === 'pendente_lancamento') {
                $financialTaskData = $this->buildFinancialTaskData($contractId, $contractData);
                $financialTask = $this->financialTaskRepository->findByContractId($contractId);

                if (is_array($financialTask) && isset($financialTask['id'])) {
                    $this->financialTaskRepository->updateById((int) $financialTask['id'], $financialTaskData);
                    $this->recordAudit('contract.financial_task.updated', 'financial_task', (int) $financialTask['id'], [
                        'login' => (string) $contractData['mkauth_login'],
                        'contract_id' => $contractId,
                        'status' => (string) $financialTaskData['status'],
                    ], $request);
                } else {
                    $taskId = $this->financialTaskRepository->create($financialTaskData) ?? 0;
                    if ($taskId > 0) {
                        $this->recordAudit('contract.financial_task.created', 'financial_task', $taskId, [
                            'login' => (string) $contractData['mkauth_login'],
                            'contract_id' => $contractId,
                            'status' => (string) $financialTaskData['status'],
                        ], $request);
                    }
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
            $tipoAdesao = $tipoInstalacao === 'fibra' ? 'isenta' : 'cheia';
        }

        $parcelasMaximas = max(1, (int) ($commercial['parcelas_maximas_adesao'] ?? 3));
        $parcelasAdesao = max(1, min($parcelasMaximas, (int) ($data['parcelas_adesao'] ?? 1)));
        $valorBase = (float) ($commercial['valor_adesao_padrao'] ?? 0);
        $valorPromocional = (float) ($commercial['valor_adesao_promocional'] ?? 0);
        $descontoPromocional = (float) ($commercial['percentual_desconto_promocional'] ?? 0);
        $valorAdesao = $this->normalizeMoney((string) ($data['valor_adesao'] ?? '0'));
        if ($valorAdesao <= 0) {
            $valorAdesao = $this->resolveAdhesionValue($tipoAdesao, $valorBase, $valorPromocional, $descontoPromocional);
        }

        if ($tipoAdesao === 'isenta') {
            $valorAdesao = 0.0;
        }

        $valorParcela = $this->normalizeMoney((string) ($data['valor_parcela_adesao'] ?? '0'));
        if ($valorParcela <= 0) {
            $valorParcela = $parcelasAdesao > 0 ? round($valorAdesao / $parcelasAdesao, 2) : 0.0;
        }

        $fidelidade = (int) ($data['fidelidade_meses'] ?? 0);
        if ($fidelidade <= 0) {
            $fidelidade = max(1, (int) ($commercial['fidelidade_meses_padrao'] ?? 12));
        }
        $beneficioConcedidoPor = trim((string) ($data['beneficio_concedido_por'] ?? ''));
        $beneficioValor = $tipoAdesao === 'isenta'
            ? $valorBase
            : max(0.0, $valorBase - $valorAdesao);
        $multaTotal = max(0.0, (float) ($commercial['multa_padrao'] ?? 0));

        $vencimentoPrimeiraParcela = $this->calculateFirstBillingDate((string) ($data['vencimento'] ?? ''));
        if ($vencimentoPrimeiraParcela === null) {
            $vencimentoPrimeiraParcela = $this->normalizeDateInput((string) ($data['vencimento_primeira_parcela'] ?? ''));
        }

        $observacaoAdesao = trim((string) ($data['observacao_adesao'] ?? $data['observacao'] ?? $data['observacao_aceite'] ?? ''));
        $observacaoAdesaoLines = [];

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
            'nome_cliente' => $nome,
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
        $acceptanceData = [
            'contract_id' => $contractId,
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

        return trim(implode("\n", [
            'CONTRATO DIGITAL ISP AUXILIAR',
            'Cliente: ' . $nome,
            'Login: ' . $login,
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
                'body' => "Olá, {nome} 👋\n\nSeu cadastro foi realizado.\n\nConfira seus dados, plano, valores e contrato no link abaixo:\n\n{link_aceite}\n\nEste link é pessoal e expira em {$ttlHours} horas.",
                'variables_json' => ['nome', 'link_aceite'],
                'active' => 1,
            ],
            'aceite_regularizacao_contrato' => [
                'channel' => 'whatsapp',
                'purpose' => 'aceite_regularizacao_contrato',
                'body' => "Olá, {nome} 👋\n\nSeu contrato foi preparado para regularização.\n\nConfira os dados e aceite no link abaixo:\n\n{link_aceite}\n\nEste link é pessoal e expira em {$ttlHours} horas.",
                'variables_json' => ['nome', 'link_aceite'],
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

        $normalized = str_replace(['.', ','], ['', '.'], $value);

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

        return array_replace($payload, [
            'token' => (string) ($checkpoint['token'] ?? ($payload['token'] ?? '')),
            'status' => (string) ($checkpoint['status'] ?? ($payload['status'] ?? 'awaiting_connection')),
            'login' => (string) ($checkpoint['mkauth_login'] ?? ($payload['login'] ?? '')),
            'updated_at' => (string) ($checkpoint['updated_at'] ?? ($payload['updated_at'] ?? '')),
            'created_at' => (string) ($checkpoint['created_at'] ?? ($payload['created_at'] ?? '')),
        ]);
    }

    private function projectRootPath(): string
    {
        return dirname(__DIR__, 2);
    }
}
