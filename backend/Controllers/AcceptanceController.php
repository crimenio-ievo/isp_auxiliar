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
use App\Infrastructure\Local\LocalRepository;
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
        private MkAuthDatabase $mkauthDatabase
    ) {
    }

    public function show(Request $request): Response
    {
        $token = trim((string) $request->route('token', ''));
        $context = $this->loadContextByToken($token);
        $flash = Flash::get();
        $providerName = $this->resolveProviderDisplayName();

        $html = $this->view->render('contracts/acceptance', [
            'layoutMode' => 'guest',
            'pageTitle' => 'Aceite Digital',
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'providerName' => $providerName,
            'basePath' => $request->basePath(),
            'user' => [
                'name' => 'Cliente',
                'login' => '',
                'role' => 'guest',
            ],
            'token' => $token,
            'context' => $context,
            'documentValidationRequired' => (bool) ($context['documentValidationRequired'] ?? false),
            'documentValidationDigits' => (int) ($context['documentValidationDigits'] ?? 3),
            'documentValidationPossible' => (bool) ($context['documentValidationPossible'] ?? false),
            'errorMessage' => $flash['error'] ?? null,
            'successMessage' => $flash['success'] ?? null,
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

        $expiresAt = trim((string) ($acceptance['token_expires_at'] ?? ''));
        if ($expiresAt !== '') {
            try {
                if (new \DateTimeImmutable($expiresAt) < new \DateTimeImmutable()) {
                    return ['error' => 'Este link de aceite expirou. Solicite novo envio.'];
                }
            } catch (\Throwable) {
                return ['error' => 'Nao foi possivel validar a validade do link.'];
            }
        }

        $status = (string) ($acceptance['status'] ?? '');
        if (!in_array($status, ['criado', 'enviado', 'aceito'], true)) {
            return ['error' => 'Este aceite nao esta disponivel para conclusao.', 'acceptance' => $acceptance, 'contract' => $contract];
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
            'error' => $status === 'aceito' ? 'Este aceite ja foi concluido.' : null,
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

        return trim(implode("\n", [
            $contractTitle,
            'Cliente: ' . $nome,
            'Login: ' . $login,
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
            'O aceite é realizado por link enviado ao telefone por WhatsApp e/ou e-mail cadastrado. A confirmação por qualquer um desses canais valida o aceite eletrônico.',
        ]));
    }

    private function buildContractSnapshot(array $contract): array
    {
        return [
            'id' => (int) ($contract['id'] ?? 0),
            'client_id' => $contract['client_id'] ?? null,
            'mkauth_login' => (string) ($contract['mkauth_login'] ?? ''),
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
