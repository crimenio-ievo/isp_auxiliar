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
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\MkAuth\MkAuthClient;
use App\Infrastructure\MkAuth\MkAuthDatabase;

/**
 * Controla a rota publica de aceite digital.
 *
 * A implementacao valida token, prazo e uso unico sem depender do fluxo de
 * cadastro nem enviar qualquer notificacao real.
 */
final class AcceptanceController
{
    public function __construct(
        private View $view,
        private Config $config,
        private ContractAcceptanceRepository $acceptanceRepository,
        private ContractRepository $contractRepository,
        private LocalRepository $localRepository,
        private MkAuthClient $mkauthClient,
        private MkAuthDatabase $mkauthDatabase
    ) {
    }

    public function show(Request $request): Response
    {
        $token = trim((string) $request->route('token', ''));
        $context = $this->loadContextByToken($token);
        $flash = Flash::get();
        $providerName = $this->resolveProviderDisplayName();
        $centralAssinanteUrl = $this->resolveCentralAssinanteUrl();
        $termUrl = Url::to('/aceite/' . rawurlencode($token) . '/termo');

        $html = $this->view->render('contracts/acceptance', [
            'layoutMode' => 'guest',
            'pageTitle' => 'Aceite Digital',
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'providerName' => $providerName,
            'basePath' => $request->basePath(),
            'hideHeader' => true,
            'hideFooter' => true,
            'user' => [
                'name' => 'Cliente',
                'login' => '',
                'role' => 'guest',
            ],
            'token' => $token,
            'termUrl' => $termUrl,
            'centralAssinanteUrl' => $centralAssinanteUrl,
            'context' => $context,
            'documentValidationRequired' => (bool) ($context['documentValidationRequired'] ?? false),
            'documentValidationDigits' => (int) ($context['documentValidationDigits'] ?? 3),
            'documentValidationPossible' => (bool) ($context['documentValidationPossible'] ?? false),
            'errorMessage' => $flash['error'] ?? null,
            'successMessage' => $flash['success'] ?? null,
        ]);

        return Response::html($html);
    }

    public function term(Request $request): Response
    {
        $token = trim((string) $request->route('token', ''));
        $context = $this->loadContextByToken($token);
        $acceptance = is_array($context['acceptance'] ?? null) ? $context['acceptance'] : [];

        if (($acceptance['status'] ?? '') !== 'aceito') {
            Flash::set('error', 'O termo assinado fica disponível após a confirmação do aceite.');

            return Response::redirect('/aceite/' . rawurlencode($token));
        }

        $termState = $this->loadTermAccessState($token, $request);
        $documentDigits = max(3, (int) ($context['documentValidationDigits'] ?? 3));
        $termValidated = !empty($termState['validated']);
        $termLocked = !empty($termState['locked']);
        $termAttemptsRemaining = max(0, 5 - (int) ($termState['attempts'] ?? 0));
        $termValidationError = '';

        if ($request->method() === 'POST' && !$termValidated && !$termLocked) {
            $inputDigits = preg_replace('/\D+/', '', (string) $request->input('document_prefix', '')) ?? '';
            $inputDigits = substr($inputDigits, 0, $documentDigits);
            $fullDocument = preg_replace('/\D+/', '', (string) ($context['documentValidation']['document_full'] ?? '')) ?? '';
            $expectedPrefix = substr($fullDocument, 0, $documentDigits);

            if ($inputDigits !== '' && strlen($inputDigits) === $documentDigits && hash_equals($expectedPrefix, $inputDigits)) {
                $termState['validated'] = true;
                $termState['validated_at'] = date('Y-m-d H:i:s');
                $this->saveTermAccessState($token, $request, $termState);
                return Response::redirect('/aceite/' . rawurlencode($token) . '/termo');
            }

            $termState['attempts'] = (int) ($termState['attempts'] ?? 0) + 1;
            $termLocked = $termState['attempts'] >= 5;
            $termState['locked'] = $termLocked;
            $this->saveTermAccessState($token, $request, $termState);
            $termAttemptsRemaining = max(0, 5 - (int) $termState['attempts']);
            $termValidationError = $termLocked
                ? 'Limite de tentativas atingido. Solicite um novo acesso ao atendimento.'
                : 'Os 3 primeiros dígitos não conferem. Verifique e tente novamente.';
        }

        $signaturePath = trim((string) ($context['publicDetails']['assinatura_path'] ?? ''));
        $signatureRef = '';
        if ($signaturePath !== '') {
            $signatureRef = basename(dirname($signaturePath));
        }

        $html = $this->view->render('contracts/termo', [
            'layoutMode' => 'guest',
            'pageTitle' => 'Termo assinado',
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'providerName' => $this->resolveProviderDisplayName(),
            'providerLegal' => $this->resolveProviderLegalProfile(),
            'basePath' => $request->basePath(),
            'hideHeader' => true,
            'hideFooter' => true,
            'user' => [
                'name' => 'Cliente',
                'login' => '',
                'role' => 'guest',
            ],
            'token' => $token,
            'termUrl' => Url::to('/aceite/' . rawurlencode($token) . '/termo'),
            'acceptanceUrl' => Url::to('/aceite/' . rawurlencode($token)),
            'centralAssinanteUrl' => $this->resolveCentralAssinanteUrl(),
            'context' => $context,
            'signaturePath' => $signaturePath,
            'signatureRef' => $signatureRef,
            'termValidated' => $termValidated,
            'termLocked' => $termLocked,
            'termAttemptsRemaining' => $termAttemptsRemaining,
            'termValidationError' => $termValidationError,
            'termValidationDigits' => $documentDigits,
        ]);

        return Response::html($html);
    }

    public function confirm(Request $request): Response
    {
        $token = trim((string) $request->route('token', ''));
        $context = $this->loadContextByToken($token);

        if (!empty($context['error'])) {
            Flash::set('error', (string) $context['error']);
            return Response::redirect('/aceite/' . rawurlencode($token));
        }

        $acceptance = is_array($context['acceptance'] ?? null) ? $context['acceptance'] : null;
        $contract = is_array($context['contract'] ?? null) ? $context['contract'] : null;
        $registration = is_array($context['registration'] ?? null) ? $context['registration'] : null;
        $checkpointData = is_array($context['checkpoint'] ?? null) ? $context['checkpoint'] : [];

        if (!is_array($acceptance) || !isset($acceptance['id']) || !is_array($contract)) {
            Flash::set('error', 'Nao foi possivel validar o aceite.');
            return Response::redirect('/aceite/' . rawurlencode($token));
        }

        if ((string) ($acceptance['status'] ?? '') === 'aceito') {
            Flash::set('success', 'Aceite concluído.');
            return Response::redirect('/aceite/' . rawurlencode($token));
        }

        $documentValidation = is_array($context['documentValidation'] ?? null) ? $context['documentValidation'] : [];
        $documentInput = preg_replace('/\D+/', '', (string) $request->input('document_prefix', '')) ?? '';
        $requiredDigits = max(1, (int) ($documentValidation['digits_required'] ?? $this->documentValidationDigits()));
        $documentAvailable = (bool) ($documentValidation['available'] ?? false);
        $documentRequired = (bool) ($documentValidation['required'] ?? $this->documentValidationRequired());
        $fullDocument = preg_replace('/\D+/', '', (string) ($documentValidation['document_full'] ?? '')) ?? '';

        if ($documentAvailable && $documentRequired) {
            $expectedPrefix = substr($fullDocument, 0, $requiredDigits);
            if ($documentInput === '' || strlen($documentInput) !== $requiredDigits || !hash_equals($expectedPrefix, $documentInput)) {
                $this->recordAudit('contract.acceptance.document_validation_failed', 'contract_acceptance', (int) $acceptance['id'], [
                    'contract_id' => (int) ($contract['id'] ?? 0),
                    'required_digits' => $requiredDigits,
                    'entered_digits' => $documentInput,
                    'expected_digits' => $expectedPrefix,
                    'document_available' => true,
                ], $request);

                Flash::set('error', 'Os primeiros dígitos do CPF/CNPJ não conferem. Verifique e tente novamente.');
                return Response::redirect('/aceite/' . rawurlencode($token));
            }
        } elseif ($documentRequired && !$documentAvailable) {
            $this->recordAudit('contract.acceptance.document_validation_unavailable', 'contract_acceptance', (int) $acceptance['id'], [
                'contract_id' => (int) ($contract['id'] ?? 0),
                'required_digits' => $requiredDigits,
                'document_available' => false,
            ], $request);

            Flash::set('error', 'Este aceite esta bloqueado porque o CPF/CNPJ do cliente nao foi localizado no cadastro. Solicite a correcao interna antes de reenviar o link.');
            return Response::redirect('/aceite/' . rawurlencode($token));
        }

        $acceptanceConfirmed = strtolower(trim((string) $request->input('aceite_cliente', 'nao'))) === 'sim';

        if (!$acceptanceConfirmed) {
            Flash::set('error', 'Confirme o aceite para concluir.');
            return Response::redirect('/aceite/' . rawurlencode($token));
        }

        $validationMatched = null;
        $validationResult = $documentAvailable ? 'not_required' : 'not_possible';
        if ($documentAvailable && $documentRequired) {
            $validationMatched = hash_equals(substr($fullDocument, 0, $requiredDigits), $documentInput);
            $validationResult = $validationMatched ? 'matched' : 'mismatch';
        }

        $acceptedAt = date('Y-m-d H:i:s');
        $ipAddress = (string) $request->server('REMOTE_ADDR', '');
        $userAgent = (string) $request->header('User-Agent', '');
        $termBody = $this->buildContractTermBody($contract);
        $termHash = hash('sha256', $termBody);
        $acceptanceId = (int) $acceptance['id'];
        $displayedData = $context['publicDetails'] ?? [];
        $signaturePath = $this->resolveExistingSignaturePath($acceptance, $registration, $checkpointData);

        $evidence = [
            'displayed_data' => $displayedData,
            'contract' => $this->buildContractSnapshot($contract),
            'acceptance' => [
                'id' => $acceptanceId,
                'contract_id' => (int) ($acceptance['contract_id'] ?? $contract['id'] ?? 0),
                'status' => 'aceito',
                'accepted_at' => $acceptedAt,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'termo_versao' => (string) ($acceptance['termo_versao'] ?? $this->config->get('contracts.term_version', '2026.1')),
                'termo_hash' => $termHash,
            ],
            'accepted_at' => $acceptedAt,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'term_hash' => $termHash,
            'term_version' => (string) ($acceptance['termo_versao'] ?? $this->config->get('contracts.term_version', '2026.1')),
            'token_reference' => hash('sha256', $token),
            'validation' => [
                'required' => $documentRequired,
                'available' => $documentAvailable,
                'digits_requested' => $requiredDigits,
                'document_prefix_entered' => $documentInput,
                'document_validation_possible' => $documentAvailable,
                'matched' => $validationMatched,
                'result' => $validationResult,
            ],
            'document_full' => $documentAvailable ? $fullDocument : null,
            'signature_path' => $signaturePath,
        ];

        $evidencePath = null;
        try {
            $evidencePath = $this->saveEvidenceJson($acceptanceId, $evidence);
        } catch (\Throwable $exception) {
            $this->localRepository->log(
                null,
                (string) ($contract['mkauth_login'] ?? ''),
                'contract.acceptance.evidence_failed',
                'contract_acceptance',
                $acceptanceId,
                [
                    'contract_id' => (int) ($contract['id'] ?? 0),
                    'error' => $exception->getMessage(),
                ],
                $ipAddress,
                $userAgent
            );
        }

        $updateData = array_merge($acceptance, [
            'status' => 'aceito',
            'accepted_at' => $acceptedAt,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'termo_versao' => (string) ($acceptance['termo_versao'] ?? $this->config->get('contracts.term_version', '2026.1')),
            'termo_hash' => $termHash,
            'evidence_json_path' => $evidencePath,
        ]);

        $this->acceptanceRepository->updateById($acceptanceId, $updateData);
        $this->localRepository->log(
            null,
            (string) ($contract['mkauth_login'] ?? ''),
            'contract.acceptance.accepted',
            'contract_acceptance',
            $acceptanceId,
            [
                'contract_id' => (int) ($contract['id'] ?? 0),
                'accepted_at' => $acceptedAt,
                'evidence_json_path' => $evidencePath,
            ],
            $ipAddress,
            $userAgent
        );

        Flash::set('success', 'Termos aceitos com sucesso.');

        return Response::redirect('/aceite/' . rawurlencode($token));
    }

    private function loadContextByToken(string $token): array
    {
        $token = trim($token);

        if ($token === '') {
            return ['error' => 'Token invalido.'];
        }

        $acceptance = $this->acceptanceRepository->findByTokenHash($token);

        if (!is_array($acceptance) || !isset($acceptance['id'])) {
            return ['error' => 'Aceite nao localizado ou token invalido.'];
        }

        $contract = $this->contractRepository->findById((int) $acceptance['contract_id']);

        if (!is_array($contract)) {
            return ['error' => 'Contrato vinculado nao encontrado.'];
        }

        $status = (string) ($acceptance['status'] ?? '');
        if (!in_array($status, ['criado', 'enviado', 'aceito'], true)) {
            return ['error' => 'Este aceite nao esta disponivel para conclusao.', 'acceptance' => $acceptance, 'contract' => $contract];
        }

        $expiresAt = trim((string) ($acceptance['token_expires_at'] ?? ''));
        $tokenExpired = false;
        if ($expiresAt !== '') {
            try {
                $tokenExpired = new \DateTimeImmutable($expiresAt) < new \DateTimeImmutable();
                if ($tokenExpired && $status !== 'aceito') {
                    return ['error' => 'Este link de aceite expirou. Solicite novo envio.'];
                }
            } catch (\Throwable) {
                return ['error' => 'Nao foi possivel validar a validade do link.'];
            }
        }

        $registration = null;
        if (!empty($contract['client_id'])) {
            $registration = $this->localRepository->findClientRegistrationById((int) $contract['client_id']);
        }

        if (!is_array($registration) && !empty($contract['mkauth_login'])) {
            $registration = $this->localRepository->findLatestClientRegistrationByLogin((string) $contract['mkauth_login']);
        }

        $checkpoint = null;
        if (is_array($registration) && !empty($registration['id'])) {
            $checkpoint = $this->localRepository->findLatestInstallationCheckpointByRegistrationId((int) $registration['id']);

            if (!is_array($checkpoint) && !empty($registration['radius_token'])) {
                $checkpoint = $this->localRepository->findInstallationCheckpointByToken((string) $registration['radius_token']);
            }
        }

        if (!is_array($checkpoint) && !empty($contract['mkauth_login'])) {
            $checkpoint = $this->localRepository->findLatestInstallationCheckpointByLogin((string) $contract['mkauth_login']);
        }

        $checkpointData = $this->extractCheckpointData($checkpoint);
        $planSnapshot = $this->resolvePlanSnapshot((string) ($checkpointData['plano'] ?? $registration['plan_name'] ?? $contract['plan_name'] ?? ''));
        $documentDigits = $this->documentValidationDigits();
        $documentRaw = preg_replace('/\D+/', '', (string) ($registration['cpf_cnpj'] ?? $checkpointData['cpf_cnpj'] ?? '')) ?? '';
        $documentMasked = $this->maskDocument($documentRaw);
        $documentAvailable = $documentRaw !== '';
        $publicDetails = $this->buildPublicDetails($contract, $registration, $checkpointData, $planSnapshot, $documentMasked);
        $signaturePath = $this->resolveExistingSignaturePath($acceptance, $registration, $checkpointData);
        if ($signaturePath !== null) {
            $publicDetails['assinatura_path'] = $signaturePath;
        }

        return [
            'acceptance' => $acceptance,
            'contract' => $contract,
            'termBody' => $this->buildContractTermBody($contract),
            'maskedDocument' => $documentMasked,
            'publicDetails' => $publicDetails,
            'documentValidationRequired' => $this->documentValidationRequired(),
            'documentValidationDigits' => $documentDigits,
            'documentValidationPossible' => $documentAvailable,
            'documentValidation' => [
                'required' => $this->documentValidationRequired(),
                'available' => $documentAvailable,
                'digits_required' => $documentDigits,
                'document_full' => $documentRaw,
            ],
            'registration' => $registration,
            'checkpoint' => $checkpointData,
            'planSnapshot' => $planSnapshot,
            'postAcceptance' => $status === 'aceito',
            'tokenExpired' => $tokenExpired,
            'error' => null,
        ];
    }

    private function resolveProviderDisplayName(): string
    {
        $providerProfile = $this->resolveProviderLegalProfile();
        if (trim((string) ($providerProfile['brand_name'] ?? '')) !== '') {
            return trim((string) $providerProfile['brand_name']);
        }

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

    private function resolveTechnicianDisplayName(array $contract): string
    {
        $candidates = [
            (string) ($contract['technician_name'] ?? ''),
            (string) ($contract['created_by'] ?? ''),
            (string) ($contract['tecnico_nome'] ?? ''),
            (string) ($contract['tecnico'] ?? ''),
            (string) ($contract['accepted_by'] ?? ''),
            (string) ($contract['technician_login'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        $user = $_SESSION['user'] ?? [];
        $userName = trim((string) ($user['name'] ?? ''));
        if ($userName !== '') {
            return $userName;
        }

        $userLogin = trim((string) ($user['login'] ?? ''));

        return $userLogin !== '' ? $userLogin : 'Equipe técnica';
    }

    private function resolveCentralAssinanteUrl(): string
    {
        $providerProfile = $this->resolveProviderLegalProfile();
        $url = trim((string) ($providerProfile['central_assinante_url'] ?? ''));
        if ($url === '') {
            $url = trim((string) $this->config->get('contracts.commercial.central_assinante_url', 'https://sistema.ievo.com.br/central'));
        }

        return $url !== '' ? $url : 'https://sistema.ievo.com.br/central';
    }

    private function resolveProviderLegalProfile(): array
    {
        try {
            $provider = (array) ($this->localRepository->currentProvider() ?? []);
            $settings = $this->localRepository->providerSettings();
        } catch (\Throwable) {
            $provider = [];
            $settings = [];
        }

        $setting = static function (array $settings, string $key, string $fallback = ''): string {
            $value = trim((string) ($settings[$key] ?? ''));

            return $value !== '' ? $value : $fallback;
        };

        $providerName = trim((string) ($provider['name'] ?? ''));
        $providerDocument = trim((string) ($provider['document'] ?? ''));
        $providerDomain = trim((string) ($provider['domain'] ?? ''));
        $providerBasePath = trim((string) ($provider['base_path'] ?? ''));

        $localValues = [
            'brand_name' => $setting($settings, 'provider_name', $providerName),
            'legal_name' => $setting($settings, 'provider_legal_name', $providerName),
            'document' => $setting($settings, 'provider_cnpj', $providerDocument),
            'address' => $setting($settings, 'provider_address', ''),
            'neighborhood' => $setting($settings, 'provider_neighborhood', ''),
            'city' => $setting($settings, 'provider_city', ''),
            'state' => $setting($settings, 'provider_state', ''),
            'zip' => $setting($settings, 'provider_zip', ''),
            'phone' => $setting($settings, 'provider_phone', ''),
            'site' => $setting($settings, 'provider_site', ''),
            'email' => $setting($settings, 'provider_email', ''),
            'anatel_process' => $setting($settings, 'provider_anatel_process', ''),
            'central_assinante_url' => $setting(
                $settings,
                'central_assinante_url',
                (string) $this->config->get('contracts.commercial.central_assinante_url', 'https://sistema.ievo.com.br/central')
            ),
        ];

        $needMkAuth = false;
        foreach (['brand_name', 'legal_name', 'document', 'address', 'neighborhood', 'city', 'state', 'zip', 'phone', 'site', 'email', 'anatel_process'] as $field) {
            if (trim((string) ($localValues[$field] ?? '')) === '') {
                $needMkAuth = true;
                break;
            }
        }

        $mkauthCompany = [];
        $mkauthSource = 'fallback';
        if ($needMkAuth && $this->mkauthClient->isConfigured()) {
            try {
                $mkauthCompany = $this->extractMkAuthCompanyProfile($this->mkauthClient->listCompany());
                if ($mkauthCompany !== []) {
                    $mkauthSource = 'mkauth';
                }
            } catch (\Throwable) {
                $mkauthCompany = [];
            }
        }

        if ($needMkAuth && $mkauthCompany === []) {
            try {
                $mkauthProfile = $this->mkauthDatabase->companyLegalProfile();
                if (is_array($mkauthProfile) && !empty($mkauthProfile['data']) && is_array($mkauthProfile['data'])) {
                    $mkauthCompany = $mkauthProfile['data'];
                    $mkauthSource = (string) ($mkauthProfile['source'] ?? 'mkauth');
                }
            } catch (\Throwable) {
                $mkauthCompany = [];
            }
        }

        $mkAuthValues = [
            'brand_name' => trim((string) ($mkauthCompany['nome'] ?? '')),
            'legal_name' => trim((string) (($mkauthCompany['razao'] ?? '') ?: ($mkauthCompany['nome'] ?? ''))),
            'document' => trim((string) ($mkauthCompany['cnpj'] ?? '')),
            'address' => trim((string) ($mkauthCompany['endereco'] ?? '')),
            'neighborhood' => trim((string) ($mkauthCompany['bairro'] ?? '')),
            'city' => trim((string) ($mkauthCompany['cidade'] ?? '')),
            'state' => trim((string) ($mkauthCompany['estado'] ?? '')),
            'zip' => trim((string) ($mkauthCompany['cep'] ?? '')),
            'phone' => trim((string) (($mkauthCompany['telefone'] ?? '') ?: ($mkauthCompany['fone'] ?? '') ?: ($mkauthCompany['celular'] ?? ''))),
            'site' => trim((string) ($mkauthCompany['site'] ?? '')),
            'email' => trim((string) ($mkauthCompany['email'] ?? '')),
            'anatel_process' => trim((string) (($mkauthCompany['autorizacao_anatel'] ?? '') ?: ($mkauthCompany['processo_scm'] ?? ''))),
        ];

        $resolved = [];
        $usedLocal = false;
        $usedMkAuth = false;
        foreach ($localValues as $field => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $resolved[$field] = $value;
                $usedLocal = true;
                continue;
            }

            $mkValue = trim((string) ($mkAuthValues[$field] ?? ''));
            if ($mkValue !== '') {
                $resolved[$field] = $mkValue;
                $usedMkAuth = true;
                continue;
            }

            $resolved[$field] = '';
        }

        $brandName = $resolved['brand_name'] !== '' ? $resolved['brand_name'] : (trim((string) $this->config->get('app.name', '')) ?: 'nossa equipe');

        if ($resolved['legal_name'] === '') {
            $resolved['legal_name'] = $brandName;
        }

        $resolved['central_assinante_url'] = $resolved['central_assinante_url'] !== ''
            ? $resolved['central_assinante_url']
            : 'https://sistema.ievo.com.br/central';

        if (trim((string) ($resolved['anatel_process'] ?? '')) === '') {
            $providerSlug = strtolower(trim((string) ($provider['slug'] ?? '')));
            $providerDisplay = strtolower(trim((string) ($resolved['brand_name'] ?? $providerName ?? '')));
            if ($providerSlug === 'ievo' || str_contains($providerDisplay, 'ievo')) {
                $resolved['anatel_process'] = 'Processo nº 53500.292642/2022-7';
            }
        }

        if ($mkauthCompany !== [] && $usedMkAuth) {
            $source = $mkauthSource;
        } elseif ($usedLocal) {
            $source = 'local';
        } elseif ($providerName !== '') {
            $source = 'providers';
        } else {
            $source = 'fallback';
        }

        return [
            'brand_name' => $brandName,
            'legal_name' => $resolved['legal_name'],
            'document' => $resolved['document'],
            'address' => $resolved['address'],
            'neighborhood' => $resolved['neighborhood'],
            'city' => $resolved['city'],
            'state' => $resolved['state'],
            'zip' => $resolved['zip'],
            'phone' => $resolved['phone'],
            'site' => $resolved['site'],
            'email' => $resolved['email'],
            'anatel_process' => $resolved['anatel_process'],
            'central_assinante_url' => $resolved['central_assinante_url'],
            'source' => $source,
        ];
    }

    private function extractMkAuthCompanyProfile(array $response): array
    {
        $candidates = [
            $response['data'] ?? null,
            $response['dados'] ?? null,
            $response['empresa'] ?? null,
            $response['empresas'] ?? null,
            $response['result'] ?? null,
            $response['items'] ?? null,
            $response['records'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && $candidate !== []) {
                if (array_is_list($candidate)) {
                    $first = $candidate[0] ?? null;
                    if (is_array($first)) {
                        return $first;
                    }
                } else {
                    return $candidate;
                }
            }
        }

        foreach ($response as $value) {
            if (is_array($value) && $value !== []) {
                if (array_is_list($value)) {
                    $first = $value[0] ?? null;
                    if (is_array($first)) {
                        return $first;
                    }
                } else {
                    return $value;
                }
            }
        }

        return [];
    }

    private function loadTermAccessState(string $token, Request $request): array
    {
        $sessionKey = $this->termAccessSessionKey($token, $request);
        $state = $_SESSION['acceptance_term_validation'][$sessionKey] ?? [];

        if (!is_array($state)) {
            $state = [];
        }

        $state['attempts'] = max(0, (int) ($state['attempts'] ?? 0));
        $state['validated'] = !empty($state['validated']);
        $state['locked'] = !empty($state['locked']) || $state['attempts'] >= 5;

        return $state;
    }

    private function saveTermAccessState(string $token, Request $request, array $state): void
    {
        $sessionKey = $this->termAccessSessionKey($token, $request);
        if (!isset($_SESSION['acceptance_term_validation']) || !is_array($_SESSION['acceptance_term_validation'])) {
            $_SESSION['acceptance_term_validation'] = [];
        }

        $_SESSION['acceptance_term_validation'][$sessionKey] = [
            'attempts' => max(0, (int) ($state['attempts'] ?? 0)),
            'validated' => !empty($state['validated']),
            'validated_at' => (string) ($state['validated_at'] ?? ''),
            'locked' => !empty($state['locked']),
        ];
    }

    private function termAccessSessionKey(string $token, Request $request): string
    {
        $ip = (string) $request->server('REMOTE_ADDR', '');
        $sessionId = session_id() ?: 'guest';

        return hash('sha256', $token . '|' . $ip . '|' . $sessionId);
    }

    private function buildContractTermBody(array $contract): string
    {
        $nome = (string) ($contract['nome_cliente'] ?? '');
        $login = (string) ($contract['mkauth_login'] ?? '');
        $telefone = (string) ($contract['telefone_cliente'] ?? '');
        $tipoAdesao = (string) ($contract['tipo_adesao'] ?? 'cheia');
        $valorAdesao = number_format((float) ($contract['valor_adesao'] ?? 0), 2, ',', '.');
        $parcelas = (int) ($contract['parcelas_adesao'] ?? 1);
        $valorParcela = number_format((float) ($contract['valor_parcela_adesao'] ?? 0), 2, ',', '.');
        $fidelidade = (int) ($contract['fidelidade_meses'] ?? 12);
        $observacao = (string) ($contract['observacao_adesao'] ?? '');

        $providerName = $this->resolveProviderDisplayName();
        $contractTitle = $providerName === 'nossa equipe'
            ? 'Contrato digital da nossa equipe'
            : 'Contrato digital ' . $providerName;
        $technicianName = trim((string) ($contract['technician_name'] ?? ''));
        $technicianLogin = trim((string) ($contract['technician_login'] ?? ''));
        $centralAssinanteUrl = $this->resolveCentralAssinanteUrl();

        return trim(implode("\n", [
            $contractTitle,
            'Cliente: ' . $nome,
            'Login: ' . $login,
            'Técnico responsável: ' . ($technicianName !== '' ? $technicianName : 'Equipe técnica'),
            'Login do técnico: ' . ($technicianLogin !== '' ? $technicianLogin : '-'),
            'Telefone: ' . $telefone,
            'Tipo de adesão: ' . $tipoAdesao,
            'Valor da adesão: R$ ' . $valorAdesao,
            'Parcelas da adesão: ' . $parcelas,
            'Valor por parcela: R$ ' . $valorParcela,
            'Fidelidade: ' . $fidelidade . ' meses',
            'Observação: ' . $observacao,
            '',
            'A taxa de adesão poderá ser concedida com desconto, isenção ou parcelamento, condicionada à fidelidade de 12 meses. O cancelamento antes do fim da fidelidade poderá gerar cobrança proporcional conforme condições contratadas.',
            'O aceite eletrônico deste termo é realizado por link enviado ao telefone cadastrado, com registro de IP, data, hora e dispositivo.',
            'A cópia do termo e os documentos de cobrança podem ser consultados pela Central do Assinante:',
            $centralAssinanteUrl,
            'O aceite é realizado por link enviado ao telefone por WhatsApp e/ou e-mail cadastrado. A confirmação por qualquer um desses canais valida o aceite eletrônico.',
        ]));
    }

    private function buildContractSnapshot(array $contract): array
    {
        return [
            'id' => (int) ($contract['id'] ?? 0),
            'client_id' => $contract['client_id'] ?? null,
            'mkauth_login' => (string) ($contract['mkauth_login'] ?? ''),
            'technician_name' => (string) ($contract['technician_name'] ?? ''),
            'technician_login' => (string) ($contract['technician_login'] ?? ''),
            'nome_cliente' => (string) ($contract['nome_cliente'] ?? ''),
            'telefone_cliente' => (string) ($contract['telefone_cliente'] ?? ''),
            'tipo_adesao' => (string) ($contract['tipo_adesao'] ?? ''),
            'valor_adesao' => (float) ($contract['valor_adesao'] ?? 0),
            'parcelas_adesao' => (int) ($contract['parcelas_adesao'] ?? 1),
            'valor_parcela_adesao' => (float) ($contract['valor_parcela_adesao'] ?? 0),
            'vencimento_primeira_parcela' => (string) ($contract['vencimento_primeira_parcela'] ?? ''),
            'fidelidade_meses' => (int) ($contract['fidelidade_meses'] ?? 12),
            'beneficio_valor' => (float) ($contract['beneficio_valor'] ?? 0),
            'beneficio_concedido_por' => (string) ($contract['beneficio_concedido_por'] ?? ''),
            'multa_total' => (float) ($contract['multa_total'] ?? 0),
            'tipo_aceite' => (string) ($contract['tipo_aceite'] ?? ''),
            'observacao_adesao' => (string) ($contract['observacao_adesao'] ?? ''),
            'status_financeiro' => (string) ($contract['status_financeiro'] ?? ''),
        ];
    }

    private function maskDocument(string $document): string
    {
        $document = preg_replace('/\D+/', '', $document) ?? '';

        if ($document === '') {
            return '-';
        }

        if (strlen($document) <= 11) {
            return '***.***.***-' . substr($document, -2);
        }

        return '**.***.***/****-' . substr($document, -2);
    }

    private function extractCheckpointData(?array $checkpoint): array
    {
        if (!is_array($checkpoint)) {
            return [];
        }

        $payload = json_decode((string) ($checkpoint['payload_json'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $formData = is_array($payload['form_data'] ?? null) ? $payload['form_data'] : [];

        return array_replace($payload, $formData, [
            'token' => (string) ($checkpoint['token'] ?? ($payload['token'] ?? '')),
            'status' => (string) ($checkpoint['status'] ?? ($payload['status'] ?? 'awaiting_connection')),
            'login' => (string) ($checkpoint['mkauth_login'] ?? ($payload['login'] ?? '')),
            'updated_at' => (string) ($checkpoint['updated_at'] ?? ($payload['updated_at'] ?? '')),
            'created_at' => (string) ($checkpoint['created_at'] ?? ($payload['created_at'] ?? '')),
        ]);
    }

    private function resolvePlanSnapshot(string $planName): array
    {
        $planName = trim($planName);

        if ($planName === '') {
            return [
                'name' => '',
                'label' => '-',
                'value' => null,
                'source' => 'unknown',
            ];
        }

        $planRows = [];
        try {
            if ($this->mkauthDatabase->isConfigured()) {
                $planRows = $this->mkauthDatabase->listPlans();
            }
        } catch (\Throwable) {
            $planRows = [];
        }

        foreach ($planRows as $plan) {
            $name = trim((string) ($plan['nome'] ?? ''));
            if ($name === '') {
                continue;
            }

            if (strcasecmp($name, $planName) !== 0) {
                continue;
            }

            $value = trim((string) ($plan['valor'] ?? ''));

            return [
                'name' => $name,
                'label' => $name . ($value !== '' ? ' - R$ ' . number_format((float) str_replace(',', '.', $value), 2, ',', '.') : ''),
                'value' => $value !== '' ? (float) str_replace(',', '.', $value) : null,
                'source' => 'mkauth',
            ];
        }

        return [
            'name' => $planName,
            'label' => $planName,
            'value' => null,
            'source' => 'fallback',
        ];
    }

    private function buildPublicDetails(array $contract, ?array $registration, array $checkpointData, array $planSnapshot, string $maskedDocument): array
    {
        $addressParts = array_filter([
            (string) ($checkpointData['endereco'] ?? ''),
            (string) ($checkpointData['numero'] ?? ''),
            (string) ($checkpointData['bairro'] ?? ''),
            (string) ($checkpointData['cidade'] ?? ''),
            (string) ($checkpointData['estado'] ?? ''),
        ], static fn (string $value): bool => trim($value) !== '');

        $installationAddress = trim(implode(' · ', $addressParts));
        $installationNotes = trim((string) ($checkpointData['observacao'] ?? ''));
        $equipmentNotes = trim((string) ($checkpointData['comodato'] ?? $checkpointData['equipamentos'] ?? ''));

        return [
            'cliente' => [
                'nome' => (string) ($contract['nome_cliente'] ?? $registration['client_name'] ?? '-'),
                'login' => (string) ($contract['mkauth_login'] ?? $registration['mkauth_login'] ?? '-'),
                'cpf_cnpj' => $maskedDocument,
                'telefone' => (string) ($contract['telefone_cliente'] ?? '-'),
            ],
            'instalacao' => [
                'cep' => (string) ($checkpointData['cep'] ?? '-'),
                'endereco' => $installationAddress !== '' ? $installationAddress : '-',
                'coordenadas' => (string) ($checkpointData['coordenadas'] ?? '-'),
                'tipo_instalacao' => (string) ($checkpointData['tipo_instalacao'] ?? '-'),
                'local_dici' => (string) ($checkpointData['local_dici'] ?? '-'),
                'observacao' => $installationNotes !== '' ? $installationNotes : '-',
            ],
            'plano' => [
                'nome' => (string) ($planSnapshot['label'] ?? $contract['plan_name'] ?? '-'),
                'valor_mensal' => $planSnapshot['value'] !== null ? (float) $planSnapshot['value'] : null,
                'origem' => (string) ($planSnapshot['source'] ?? 'unknown'),
            ],
            'contrato' => [
                'vencimento' => (string) ($checkpointData['vencimento'] ?? '-'),
                'valor_mensal' => $planSnapshot['value'] !== null ? (float) $planSnapshot['value'] : null,
                'tipo_adesao' => (string) ($contract['tipo_adesao'] ?? '-'),
                'valor_adesao' => (float) ($contract['valor_adesao'] ?? 0),
                'parcelas_adesao' => (int) ($contract['parcelas_adesao'] ?? 1),
                'valor_parcela_adesao' => (float) ($contract['valor_parcela_adesao'] ?? 0),
                'vencimento_primeira_parcela' => (string) ($contract['vencimento_primeira_parcela'] ?? ''),
                'beneficio_valor' => (float) ($contract['beneficio_valor'] ?? 0),
                'fidelidade_meses' => (int) ($contract['fidelidade_meses'] ?? 12),
                'multa_total' => (float) ($contract['multa_total'] ?? 0),
                'beneficio_concedido_por' => (string) ($contract['beneficio_concedido_por'] ?? ''),
                'tipo_aceite' => (string) ($contract['tipo_aceite'] ?? '-'),
                'observacao_adesao' => (string) ($contract['observacao_adesao'] ?? '-'),
                'equipamentos_comodato' => $equipmentNotes !== '' ? $equipmentNotes : '-',
            ],
            'termo_versao' => (string) ($contract['termo_versao'] ?? $this->config->get('contracts.term_version', '2026.1')),
        ];
    }

    private function resolveExistingSignaturePath(?array $acceptance, ?array $registration = null, array $checkpointData = []): ?string
    {
        $candidates = [];

        if (is_array($registration) && trim((string) ($registration['evidence_ref'] ?? '')) !== '') {
            $candidates[] = trim((string) $registration['evidence_ref']);
        }

        if (trim((string) ($checkpointData['evidence_ref'] ?? '')) !== '') {
            $candidates[] = trim((string) $checkpointData['evidence_ref']);
        }

        if (is_array($acceptance) && trim((string) ($acceptance['evidence_json_path'] ?? '')) !== '') {
            $evidencePath = $this->projectRootPath() . '/' . trim((string) $acceptance['evidence_json_path']);

            if (is_file($evidencePath)) {
                $payload = json_decode((string) file_get_contents($evidencePath), true);
                if (is_array($payload) && trim((string) ($payload['signature_path'] ?? '')) !== '') {
                    $signaturePath = trim((string) $payload['signature_path']);
                    if (is_file($this->projectRootPath() . '/' . ltrim($signaturePath, '/'))) {
                        return $signaturePath;
                    }
                }
            }
        }

        foreach (array_unique($candidates) as $ref) {
            $signaturePath = 'storage/uploads/clientes/' . $ref . '/assinatura.png';
            if (is_file($this->projectRootPath() . '/' . $signaturePath)) {
                return $signaturePath;
            }
        }

        return null;
    }

    private function projectRootPath(): string
    {
        return dirname(__DIR__, 2);
    }

    private function saveSignatureFile(int $acceptanceId, string $signatureDataUrl): ?string
    {
        $signatureDataUrl = trim($signatureDataUrl);

        if ($signatureDataUrl === '') {
            return null;
        }

        $binary = $this->decodeDataUrl($signatureDataUrl);
        if ($binary === null) {
            throw new \RuntimeException('A assinatura informada não pôde ser processada.');
        }

        $rootPath = dirname(__DIR__, 2);
        $directory = $rootPath . '/storage/contracts/acceptances';
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $fileName = 'signature_' . $acceptanceId . '_' . date('Ymd_His') . '.png';
        $path = $directory . '/' . $fileName;

        if (file_put_contents($path, $binary) === false) {
            throw new \RuntimeException('Nao foi possivel salvar a assinatura.');
        }

        return 'storage/contracts/acceptances/' . $fileName;
    }

    private function decodeDataUrl(string $dataUrl): ?string
    {
        if (!preg_match('#^data:image/([a-zA-Z0-9.+-]+);base64,(.+)$#', $dataUrl, $matches)) {
            return null;
        }

        $decoded = base64_decode($matches[2], true);

        return $decoded === false ? null : $decoded;
    }

    private function documentValidationRequired(): bool
    {
        return (bool) $this->config->get('contracts.commercial.exigir_validacao_cpf_aceite', true);
    }

    private function documentValidationDigits(): int
    {
        return max(1, (int) $this->config->get('contracts.commercial.quantidade_digitos_validacao_cpf', 3));
    }

    private function recordAudit(string $action, string $entityType, ?int $entityId, array $context, Request $request): void
    {
        try {
            $this->localRepository->log(
                null,
                'guest',
                $action,
                $entityType,
                $entityId,
                $context,
                (string) $request->server('REMOTE_ADDR', ''),
                (string) $request->header('User-Agent', '')
            );
        } catch (\Throwable) {
            // Log administrativo nao pode impedir o aceite.
        }
    }

    private function saveEvidenceJson(int $acceptanceId, array $evidence): string
    {
        $rootPath = dirname(__DIR__, 2);
        $directory = $rootPath . '/storage/contracts/acceptances';

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $fileName = 'acceptance_' . $acceptanceId . '_' . date('Ymd_His') . '.json';
        $path = $directory . '/' . $fileName;

        $written = file_put_contents(
            $path,
            json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
        );

        if ($written === false) {
            throw new \RuntimeException('Nao foi possivel salvar a evidência do aceite.');
        }

        return 'storage/contracts/acceptances/' . $fileName;
    }
}
