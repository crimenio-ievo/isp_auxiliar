<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Infrastructure\Contracts\ContractAcceptanceRepository;
use App\Infrastructure\Contracts\ContractRepository;
use App\Infrastructure\Contracts\FinancialTaskRepository;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\MkAuth\MkAuthDatabase;
use App\Services\MkAuth\MkAuthPlanChangeService;
use App\Services\Processes\OperationalProcessService;
use App\Services\Processes\MigrationJourneyService;
use App\Services\Processes\MigrationEvidenceService;
use App\Services\Processes\MigrationFinalizationService;

final class OperationalProcessController
{
    public function __construct(
        private View $view,
        private Config $config,
        private LocalRepository $localRepository,
        private OperationalProcessService $processService,
        private MkAuthPlanChangeService $planChangeService,
        private ContractRepository $contractRepository,
        private ContractAcceptanceRepository $acceptanceRepository,
        private FinancialTaskRepository $financialTaskRepository,
        private MkAuthDatabase $mkauthDatabase,
        private ?MigrationJourneyService $journeyService = null,
        private ?MigrationEvidenceService $evidenceService = null,
        private ?MigrationFinalizationService $finalizationService = null
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->canAccess()) {
            Flash::set('error', 'Seu usuário não possui permissão para consultar processos.');
            return Response::redirect('/dashboard');
        }
        if (!$this->processService->isAvailable()) {
            Flash::set('warning', 'A estrutura de processos ainda não foi aplicada neste ambiente.');
            return Response::redirect('/clientes');
        }

        $allowedStatuses = [
            'in_progress',
            'waiting_client',
            'waiting_technician',
            'waiting_mkauth',
            'waiting_financial',
            'attention',
            'completed',
            'cancelled',
        ];
        $status = trim((string) $request->query('status', ''));
        if (!in_array($status, $allowedStatuses, true)) {
            $status = '';
        }
        $type = trim((string) $request->query('type', ''));
        if (!in_array($type, [
            OperationalProcessService::TYPE_INSTALLATION,
            OperationalProcessService::TYPE_MIGRATION,
            OperationalProcessService::TYPE_STANDALONE_SIGNATURE,
        ], true)) {
            $type = '';
        }
        $login = strtolower(trim((string) $request->query('login', '')));

        return Response::html($this->view->render('processes/index', [
            'pageTitle' => 'Processos operacionais',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveViewUser(),
            'flash' => Flash::get(),
            'filters' => ['status' => $status, 'type' => $type, 'login' => $login],
            'processes' => $this->processService->list(array_filter([
                'status' => $status,
                'type' => $type,
                'login' => $login,
            ], static fn (string $value): bool => $value !== '')),
        ]));
    }

    public function detail(Request $request): Response
    {
        $process = $this->loadAuthorizedProcess((int) $request->query('id', 0));
        if ($process instanceof Response) {
            return $process;
        }
        if ((string) ($process['process_type'] ?? '') === OperationalProcessService::TYPE_MIGRATION) {
            return Response::redirect((string) ($process['resume_url'] ?? '/processos/migracao?id=' . (int) $process['id']));
        }

        return Response::html($this->view->render('processes/detail', [
            'pageTitle' => (string) ($process['type_label'] ?? 'Processo operacional'),
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveViewUser(),
            'flash' => Flash::get(),
            'process' => $process,
            'csrfToken' => Csrf::token($this->csrfScope((int) $process['id'])),
            'canOverride' => $this->canOverride(),
        ]));
    }

    public function migration(Request $request): Response
    {
        $process = $this->loadAuthorizedProcess((int) $request->query('id', 0));
        if ($process instanceof Response) {
            return $process;
        }
        if ((string) ($process['process_type'] ?? '') !== OperationalProcessService::TYPE_MIGRATION) {
            return Response::redirect('/processos/detalhe?id=' . (int) ($process['id'] ?? 0));
        }

        $stepKey = trim((string) $request->query('step', ''));
        if ($stepKey === '') {
            $stepKey = (string) ($process['next_pending_key'] ?? $process['current_step_key'] ?? 'migration_data');
        } elseif (in_array($stepKey, ['prepare_document', 'send_acceptance', 'confirm_acceptance'], true)
            && (string) ($process['next_pending_key'] ?? '') === 'technical_execution'
        ) {
            // O aceite confirmado avança no mesmo workspace, sem uma segunda tela redundante.
            $stepKey = 'technical_execution';
        }
        $steps = array_values((array) ($process['steps'] ?? []));
        $activeStep = null;
        $activeIndex = 0;
        foreach ($steps as $index => $candidate) {
            if (is_array($candidate) && (string) ($candidate['step_key'] ?? '') === $stepKey) {
                $activeStep = $candidate;
                $activeIndex = $index;
                break;
            }
        }
        if (!is_array($activeStep)) {
            $activeStep = $steps[0] ?? [];
            $activeIndex = 0;
        }
        $previousStep = $activeIndex > 0 ? ($steps[$activeIndex - 1] ?? null) : null;
        $nextStep = $steps[$activeIndex + 1] ?? null;
        $contract = $this->contractRepository->findById((int) ($process['contract_id'] ?? 0)) ?? [];
        $acceptance = $this->acceptanceRepository->findById((int) ($process['acceptance_id'] ?? 0)) ?? [];
        $financialTask = $this->financialTaskRepository->findByContractId((int) ($process['contract_id'] ?? 0)) ?? [];
        $connection = [];
        if (in_array((string) ($activeStep['step_key'] ?? ''), ['technical_execution', 'confirm_equipment', 'validate_connection'], true)) {
            try {
                $connection = $this->mkauthDatabase->radiusConnectionStatus((string) ($process['mkauth_login'] ?? ''));
            } catch (\Throwable) {
                $connection = ['available' => false, 'online' => false, 'session' => null];
            }
        }

        $dryRun = null;
        if ((string) ($activeStep['step_key'] ?? '') === 'change_plan') {
            $migration = is_array($process['metadata']['migration'] ?? null) ? $process['metadata']['migration'] : [];
            $targetPlan = trim((string) ($migration['new_plan_id'] ?? $migration['new_plan_name'] ?? ''));
            if ($targetPlan !== '') {
                try {
                    $dryRun = $this->planChangeService->prepareDryRun((string) ($process['mkauth_login'] ?? ''), $targetPlan);
                } catch (\Throwable $exception) {
                    $dryRun = ['status' => 'unavailable', 'dry_run' => true, 'write_enabled' => false, 'message' => $exception->getMessage()];
                }
            }
        }

        return Response::html($this->view->render('processes/migration', [
            'pageTitle' => 'Upgrade / Migração',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveViewUser(),
            'flash' => Flash::get(),
            'process' => $process,
            'activeStep' => $activeStep,
            'previousStep' => $previousStep,
            'nextStep' => $nextStep,
            'contract' => $contract,
            'acceptance' => $acceptance,
            'financialTask' => $financialTask,
            'connection' => $connection,
            'dryRun' => $dryRun,
            'csrfToken' => Csrf::token($this->csrfScope((int) $process['id'])),
            'canOverride' => $this->canOverride(),
            'journey' => ($this->journeyService ?? new MigrationJourneyService())->project($process, $stepKey),
            'editContact' => (string) $request->query('edit_contact', '') === '1',
            'notificationDryRun' => [
                'whatsapp' => (bool) $this->config->get('evotrix.dry_run', true),
                'email' => (bool) $this->config->get('email.dry_run', true),
            ],
        ]));
    }

    public function step(Request $request): Response
    {
        $process = $this->loadAuthorizedProcess((int) $request->query('id', 0));
        if ($process instanceof Response) {
            return $process;
        }
        if ((string) ($process['process_type'] ?? '') === OperationalProcessService::TYPE_MIGRATION) {
            $stepKey = trim((string) $request->query('step', ''));
            return Response::redirect('/processos/migracao?id=' . (int) $process['id']
                . ($stepKey !== '' ? '&step=' . rawurlencode($stepKey) : ''));
        }

        $stepKey = trim((string) $request->query('step', ''));
        $step = null;
        foreach ((array) ($process['steps'] ?? []) as $candidate) {
            if (is_array($candidate) && (string) ($candidate['step_key'] ?? '') === $stepKey) {
                $step = $candidate;
                break;
            }
        }
        if (!is_array($step)) {
            $step = $this->processService->nextStep((int) $process['id']);
        }
        if (!is_array($step)) {
            return Response::redirect('/processos/detalhe?id=' . (int) $process['id']);
        }

        $dryRun = null;
        if ((string) ($step['step_key'] ?? '') === 'change_plan') {
            $migration = is_array($process['metadata']['migration'] ?? null)
                ? $process['metadata']['migration']
                : [];
            $targetPlan = trim((string) (
                $migration['new_plan_id']
                ?? $migration['new_plan_name']
                ?? $migration['novo_plano']
                ?? ''
            ));
            if ($targetPlan !== '') {
                try {
                    $dryRun = $this->planChangeService->prepareDryRun(
                        (string) ($process['mkauth_login'] ?? ''),
                        $targetPlan
                    );
                } catch (\Throwable $exception) {
                    $dryRun = [
                        'status' => 'unavailable',
                        'dry_run' => true,
                        'write_enabled' => false,
                        'message' => $exception->getMessage(),
                    ];
                }
            }
        }

        $steps = array_values((array) ($process['steps'] ?? []));
        $index = array_search((int) ($step['id'] ?? 0), array_column($steps, 'id'), true);
        $previous = is_int($index) && $index > 0 ? $steps[$index - 1] : null;
        $next = is_int($index) && isset($steps[$index + 1]) ? $steps[$index + 1] : null;

        return Response::html($this->view->render('processes/step', [
            'pageTitle' => (string) ($step['label'] ?? 'Etapa'),
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $this->resolveViewUser(),
            'flash' => Flash::get(),
            'process' => $process,
            'step' => $step,
            'previousStep' => $previous,
            'nextStep' => $next,
            'dryRun' => $dryRun,
            'csrfToken' => Csrf::token($this->csrfScope((int) $process['id'])),
        ]));
    }

    public function updateStep(Request $request): Response
    {
        $processId = (int) $request->input('process_id', 0);
        $process = $this->loadAuthorizedProcess($processId);
        if ($process instanceof Response) {
            return $process;
        }
        if (!Csrf::verify($request, $this->csrfScope($processId))) {
            Flash::set('error', 'A sessão do formulário expirou. Reabra a etapa e tente novamente.');
            return Response::redirect('/processos/detalhe?id=' . $processId);
        }

        $stepKey = trim((string) $request->input('step_key', ''));
        $continueTo = (string) $request->input('continue_to', '');
        $returnUrl = $continueTo === 'migration_workspace'
            ? '/processos/migracao?id=' . $processId . '&step=' . rawurlencode($stepKey)
            : '/processos/etapa?id=' . $processId . '&step=' . rawurlencode($stepKey);
        if (!$this->canUpdateStep($process, $stepKey)) {
            Flash::set('error', 'Seu usuário não possui permissão para executar esta etapa.');
            return Response::redirect($returnUrl);
        }
        $action = trim((string) $request->input('action', 'save'));
        $operator = $this->resolveUser();
        $input = [
            'observation' => (string) $request->input('observation', ''),
            'pending_reason' => (string) $request->input('pending_reason', ''),
            'next_action' => (string) $request->input('next_action', ''),
            'responsible_login' => (string) $request->input('responsible_login', ''),
            'external_reference' => (string) $request->input('external_reference', ''),
            'evidence' => [],
        ];

        if ($action === 'simulate_plan') {
            try {
                $migration = is_array($process['metadata']['migration'] ?? null)
                    ? $process['metadata']['migration']
                    : [];
                $targetPlan = trim((string) (
                    $migration['new_plan_id']
                    ?? $migration['new_plan_name']
                    ?? $migration['novo_plano']
                    ?? ''
                ));
                $input['evidence'] = $this->planChangeService->prepareDryRun(
                    (string) ($process['mkauth_login'] ?? ''),
                    $targetPlan
                );
                $input['pending_reason'] = 'Escrita no MkAuth bloqueada; dry-run registrado sem aplicar o plano.';
                $input['next_action'] = 'Alterar o plano no MkAuth e confirmar manualmente a execução real.';
                $action = 'defer';
            } catch (\Throwable $exception) {
                Flash::set('error', 'Não foi possível preparar o dry-run: ' . $exception->getMessage());
                return Response::redirect($returnUrl);
            }
        } elseif ($action === 'refresh_external') {
            $this->processService->synchronizeExternalState($processId);
            Flash::set('success', 'Situação atualizada pelas fontes somente leitura disponíveis.');
            return Response::redirect($returnUrl);
        }

        try {
            $this->processService->updateStep($processId, $stepKey, $action, $input, $operator);
            Flash::set(
                'success',
                $action === 'complete'
                    ? 'Etapa concluída com evidência manual.'
                    : ($action === 'defer' ? 'Pendência preservada para retomada.' : 'Etapa salva.')
            );
        } catch (\Throwable $exception) {
            Flash::set('error', $exception->getMessage());
            return Response::redirect($returnUrl);
        }

        if ((string) $request->input('continue_to', '') === 'checklist') {
            return Response::redirect('/processos/detalhe?id=' . $processId);
        }
        if ($continueTo === 'migration_workspace') {
            $updated = $this->processService->detail($processId);
            $nextKey = (string) ($updated['next_pending_key'] ?? '');
            return Response::redirect('/processos/migracao?id=' . $processId
                . ($nextKey !== '' ? '&step=' . rawurlencode($nextKey) : ''));
        }
        if ($continueTo === 'migration_exit') {
            return Response::redirect('/clientes/detalhe?login=' . rawurlencode((string) ($process['mkauth_login'] ?? '')));
        }
        $next = $this->processService->nextStep($processId);
        if (is_array($next)) {
            return Response::redirect('/processos/etapa?id=' . $processId . '&step=' . rawurlencode((string) $next['step_key']));
        }

        return Response::redirect('/processos/detalhe?id=' . $processId);
    }

    public function complete(Request $request): Response
    {
        $processId = (int) $request->input('process_id', 0);
        $process = $this->loadAuthorizedProcess($processId);
        if ($process instanceof Response) {
            return $process;
        }
        if (!Csrf::verify($request, $this->csrfScope($processId))) {
            Flash::set('error', 'A sessão do formulário expirou. Reabra o processo.');
            return Response::redirect('/processos/detalhe?id=' . $processId);
        }
        if (!$this->canCompleteProcess()) {
            Flash::set('error', 'Seu usuário não possui permissão para concluir este processo.');
            return Response::redirect('/processos/detalhe?id=' . $processId);
        }

        $override = (string) $request->input('override', '') === '1' && $this->canOverride();
        try {
            $this->processService->completeProcess(
                $processId,
                $this->resolveUser(),
                $override,
                trim((string) $request->input('justification', ''))
            );
            Flash::set('success', 'Processo concluído e auditado.');
        } catch (\Throwable $exception) {
            Flash::set('error', $exception->getMessage());
        }

        return Response::redirect('/processos/detalhe?id=' . $processId);
    }

    public function completeTechnicalExecution(Request $request): Response
    {
        $processId = (int) $request->input('process_id', 0);
        $process = $this->loadAuthorizedProcess($processId);
        if ($process instanceof Response) {
            return $process;
        }
        if (!Csrf::verify($request, $this->csrfScope($processId)) || !$this->canUpdateStep($process, 'technical_execution')) {
            Flash::set('error', 'Sessão expirada ou usuário sem permissão para concluir a execução técnica.');
            return Response::redirect('/processos/migracao?id=' . $processId . '&step=technical_execution');
        }

        $serviceExecuted = trim((string) $request->input('service_executed', ''));
        $equipmentInstalled = trim((string) $request->input('equipment_installed', ''));
        $equipmentRemoved = trim((string) $request->input('equipment_removed', ''));
        $equipmentReference = trim((string) $request->input('equipment_reference', ''));
        $observation = trim((string) $request->input('observation', ''));
        $pendingReason = trim((string) $request->input('pending_reason', ''));
        $nextAction = trim((string) $request->input('next_action', ''));
        $responsibleLogin = trim((string) $request->input('responsible_login', ''));
        $pendingDueDate = trim((string) $request->input('pending_due_date', ''));
        if ($serviceExecuted === '' || $equipmentInstalled === '') {
            Flash::set('error', 'Informe o serviço executado e o equipamento instalado.');
            return Response::redirect('/processos/migracao?id=' . $processId . '&step=technical_execution');
        }

        $operator = $this->resolveUser();
        $evidenceService = $this->evidenceService ?? new MigrationEvidenceService($this->config);
        $advanceToFinalization = true;
        try {
            $files = $evidenceService->store($processId, 'technical_execution', (array) ($_FILES['evidence_files'] ?? []), $operator);
            $technicalStep = $this->findStep($process, 'technical_execution');
            $existingEvidence = is_array($technicalStep['evidence'] ?? null) ? $technicalStep['evidence'] : [];
            $evidence = array_replace($existingEvidence, [
                'service_executed' => $serviceExecuted,
                'equipment_installed' => $equipmentInstalled,
                'equipment_removed' => $equipmentRemoved,
                'equipment_reference' => $equipmentReference,
                'pending_due_date' => $pendingDueDate,
                'files' => array_values(array_merge((array) ($existingEvidence['files'] ?? []), $files)),
            ]);
            $this->processService->updateStep($processId, 'technical_execution', 'complete', [
                'observation' => $observation !== '' ? $observation : $serviceExecuted,
                'external_reference' => $equipmentReference,
                'evidence' => $evidence,
            ], $operator);
            $this->processService->updateStep($processId, 'confirm_equipment', $pendingReason === '' ? 'complete' : 'defer', [
                'observation' => 'Equipamento conferido na execução técnica unificada.',
                'pending_reason' => $pendingReason,
                'next_action' => $nextAction,
                'responsible_login' => $responsibleLogin,
                'external_reference' => $equipmentReference,
                'evidence' => [
                    'equipment_installed' => $equipmentInstalled,
                    'equipment_removed' => $equipmentRemoved,
                    'equipment_reference' => $equipmentReference,
                    'source_step' => 'technical_execution',
                    'pending_due_date' => $pendingDueDate,
                ],
            ], $operator);

            try {
                $connection = $this->mkauthDatabase->radiusConnectionStatus((string) ($process['mkauth_login'] ?? ''));
            } catch (\Throwable) {
                $connection = ['available' => false, 'online' => false];
            }
            if (!empty($connection['online'])) {
                $this->processService->updateStep($processId, 'validate_connection', 'complete', [
                    'observation' => 'PPPoE online confirmado por consulta somente leitura.',
                    'evidence' => ['online' => true, 'source' => 'radius_readback', 'checked_at' => date('Y-m-d H:i:s')],
                ], $operator);
                Flash::set('success', 'Execução técnica e equipamento concluídos. PPPoE online confirmado.');
            } else {
                $offlineJustification = trim((string) $request->input('offline_justification', ''));
                $authorized = (string) $request->input('offline_override', '') === '1' && $this->canOverride();
                $advanceToFinalization = $authorized && $offlineJustification !== '';
                $this->processService->updateStep($processId, 'validate_connection', $authorized && $offlineJustification !== '' ? 'complete' : 'attention', [
                    'observation' => $offlineJustification,
                    'pending_reason' => 'PPPoE offline ou indisponível após a execução técnica.',
                    'next_action' => 'Reconectar o equipamento e atualizar a situação.',
                    'evidence' => [
                        'online' => false,
                        'available' => (bool) ($connection['available'] ?? false),
                        'authorized_exception' => $authorized && $offlineJustification !== '',
                        'checked_at' => date('Y-m-d H:i:s'),
                    ],
                ], $operator);
                Flash::set('warning', 'Execução técnica salva. O PPPoE continua offline ou indisponível e ficará destacado na Finalização.');
            }
        } catch (\Throwable $exception) {
            Flash::set('error', $exception->getMessage());
            return Response::redirect('/processos/migracao?id=' . $processId . '&step=technical_execution');
        }

        if (!$advanceToFinalization) {
            return Response::redirect('/processos/migracao?id=' . $processId . '&step=technical_execution');
        }

        return (string) $request->input('continue_to', '') === 'exit'
            ? Response::redirect('/clientes/detalhe?login=' . rawurlencode((string) ($process['mkauth_login'] ?? '')))
            : Response::redirect('/processos/migracao?id=' . $processId . '&step=change_plan');
    }

    public function finalizeTechnicalService(Request $request): Response
    {
        $processId = (int) $request->input('process_id', 0);
        $process = $this->loadAuthorizedProcess($processId);
        if ($process instanceof Response) {
            return $process;
        }
        if (!Csrf::verify($request, $this->csrfScope($processId)) || !$this->canCompleteProcess()) {
            Flash::set('error', 'Sessão expirada ou usuário sem permissão para finalizar o atendimento.');
            return Response::redirect('/processos/migracao?id=' . $processId . '&step=change_plan');
        }
        if (!$this->finalizationService instanceof MigrationFinalizationService) {
            Flash::set('error', 'Orquestração da finalização indisponível.');
            return Response::redirect('/processos/migracao?id=' . $processId . '&step=change_plan');
        }
        try {
            $result = $this->finalizationService->run(
                $processId,
                $this->resolveUser(),
                trim((string) $request->input('request_id', ''))
            );
            Flash::set(in_array((string) ($result['status'] ?? ''), ['attention', 'waiting_mkauth'], true) ? 'warning' : 'success', (string) ($result['message'] ?? 'Finalização atualizada.'));
        } catch (\Throwable $exception) {
            Flash::set('error', $exception->getMessage());
        }

        return Response::redirect('/processos/migracao?id=' . $processId . '&step=change_plan');
    }

    public function correctMigrationContact(Request $request): Response
    {
        $processId = (int) $request->input('process_id', 0);
        $returnTo = '/processos/migracao?id=' . $processId . '&step=confirm_acceptance';
        $process = $this->loadAuthorizedProcess($processId);
        if ($process instanceof Response) {
            return $process;
        }
        if (!Csrf::verify($request, $this->csrfScope($processId)) || !$this->canUpdateStep($process, 'confirm_acceptance')) {
            Flash::set('error', 'Sessão expirada ou usuário sem permissão para corrigir o contato.');
            return Response::redirect($returnTo);
        }

        try {
            $this->processService->updateMigrationContacts(
                $processId,
                (string) $request->input('phone', ''),
                (string) $request->input('email', ''),
                (string) $request->input('reason', ''),
                $this->resolveUser()
            );
            Flash::set('success', 'Contato corrigido com auditoria. O mesmo processo e aceite foram preservados.');
        } catch (\Throwable $exception) {
            Flash::set('error', $exception->getMessage());
            return Response::redirect($returnTo . '&edit_contact=1');
        }

        return Response::redirect($returnTo);
    }

    public function evidenceFile(Request $request): Response
    {
        $process = $this->loadAuthorizedProcess((int) $request->query('process_id', 0));
        if ($process instanceof Response) {
            return $process;
        }
        $step = $this->findStep($process, 'technical_execution');
        $file = ($this->evidenceService ?? new MigrationEvidenceService($this->config))->resolve(
            is_array($step['evidence'] ?? null) ? $step['evidence'] : [],
            trim((string) $request->query('evidence_id', ''))
        );
        if (!is_array($file)) {
            return Response::html('Evidência não localizada.', 404);
        }

        return new Response((string) file_get_contents((string) $file['absolute_path']), 200, [
            'Content-Type' => (string) $file['mime_type'],
            'Content-Disposition' => 'inline; filename="' . addslashes((string) $file['original_name']) . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function removeEvidence(Request $request): Response
    {
        $processId = (int) $request->input('process_id', 0);
        $process = $this->loadAuthorizedProcess($processId);
        if ($process instanceof Response) {
            return $process;
        }
        if (!Csrf::verify($request, $this->csrfScope($processId)) || !$this->canUpdateStep($process, 'technical_execution')) {
            Flash::set('error', 'Não foi possível autorizar a remoção da evidência.');
            return Response::redirect('/processos/migracao?id=' . $processId . '&step=technical_execution');
        }
        $step = $this->findStep($process, 'technical_execution');
        $evidence = is_array($step['evidence'] ?? null) ? $step['evidence'] : [];
        $updated = ($this->evidenceService ?? new MigrationEvidenceService($this->config))->remove(
            $evidence,
            trim((string) $request->input('evidence_id', ''))
        );
        $this->processService->replaceStepEvidence($processId, 'technical_execution', $updated, $this->resolveUser());
        Flash::set('success', 'Evidência removida antes da conclusão.');

        return Response::redirect('/processos/migracao?id=' . $processId . '&step=technical_execution');
    }

    public function cancel(Request $request): Response
    {
        $processId = (int) $request->input('process_id', 0);
        $process = $this->loadAuthorizedProcess($processId);
        if ($process instanceof Response) {
            return $process;
        }
        if (!Csrf::verify($request, $this->csrfScope($processId))) {
            Flash::set('error', 'A sessão do formulário expirou. Reabra o processo.');
            return Response::redirect('/processos/detalhe?id=' . $processId);
        }
        if (!$this->canCancelProcess()) {
            Flash::set('error', 'Seu usuário não possui permissão para cancelar este processo.');
            return Response::redirect('/processos/detalhe?id=' . $processId);
        }

        $cancelled = false;
        try {
            $acceptance = (int) ($process['acceptance_id'] ?? 0) > 0
                ? $this->acceptanceRepository->findById((int) $process['acceptance_id'])
                : null;
            $accepted = is_array($acceptance)
                && (string) ($acceptance['status'] ?? '') === 'aceito'
                && trim((string) ($acceptance['revoked_at'] ?? '')) === '';
            $externalActions = ($this->journeyService ?? new MigrationJourneyService())->hasExternalActions($process);
            if ($accepted && (string) $request->input('confirm_accepted', '') !== '1') {
                throw new \RuntimeException('Confirme que o documento aceito será preservado como cancelado ou substituído.');
            }
            if ($externalActions) {
                if (!$this->canOverride() || (string) $request->input('confirm_external', '') !== '1') {
                    throw new \RuntimeException('Ações externas já executadas exigem confirmação e permissão gerencial.');
                }
                if (trim((string) $request->input('reversal_justification', '')) === '') {
                    throw new \RuntimeException('Informe a pendência de correção ou reversão das ações externas.');
                }
            }
            $this->processService->cancelProcess(
                $processId,
                $this->resolveUser(),
                trim((string) $request->input('reason', ''))
                    . ($externalActions ? ' Pendência de correção/reversão: ' . trim((string) $request->input('reversal_justification', '')) : '')
            );
            $cancelled = true;
            Flash::set('success', 'Processo cancelado. O histórico foi preservado.');
        } catch (\Throwable $exception) {
            Flash::set('error', $exception->getMessage());
        }

        if ($cancelled && (string) $request->input('after_cancel', 'client') === 'restart'
            && (string) ($process['process_type'] ?? '') === OperationalProcessService::TYPE_MIGRATION
            && (string) ($process['status'] ?? '') !== 'completed'
        ) {
            return Response::redirect('/clientes/upgrade?login=' . rawurlencode((string) ($process['mkauth_login'] ?? '')) . '&new_after_cancel=1');
        }

        return (string) ($process['mkauth_login'] ?? '') !== ''
            ? Response::redirect('/clientes/detalhe?login=' . rawurlencode((string) $process['mkauth_login']))
            : Response::redirect('/processos/detalhe?id=' . $processId);
    }

    private function loadAuthorizedProcess(int $processId): array|Response
    {
        if (!$this->canAccess()) {
            Flash::set('error', 'Seu usuário não possui permissão para acessar processos.');
            return Response::redirect('/dashboard');
        }
        if ($processId > 0) {
            $this->processService->synchronizeExternalState($processId);
        }
        if ($processId <= 0 || !is_array($process = $this->processService->detail($processId))) {
            Flash::set('error', 'Processo operacional não localizado.');
            return Response::redirect('/processos');
        }

        return $process;
    }

    private function canAccess(): bool
    {
        $access = $this->access();

        return !empty($access['can_search_clients'])
            && (!empty($access['can_view_contracts']) || !empty($access['can_upgrade_request']));
    }

    private function findStep(array $process, string $stepKey): array
    {
        foreach ((array) ($process['steps'] ?? []) as $step) {
            if (is_array($step) && (string) ($step['step_key'] ?? '') === $stepKey) {
                return $step;
            }
        }

        return [];
    }

    private function canOverride(): bool
    {
        $access = $this->access();

        return !empty($access['is_manager']) || !empty($access['is_admin']);
    }

    private function canUpdateStep(array $process, string $stepKey): bool
    {
        $access = $this->access();
        $step = null;
        foreach ((array) ($process['steps'] ?? []) as $candidate) {
            if (is_array($candidate) && (string) ($candidate['step_key'] ?? '') === $stepKey) {
                $step = $candidate;
                break;
            }
        }
        if (!is_array($step)) {
            return false;
        }

        $responsible = (string) ($step['responsible_role'] ?? 'technician');
        if ($responsible === 'financial') {
            return !empty($access['can_manage_financial']);
        }
        if ($responsible === 'mkauth') {
            return !empty($access['is_manager'])
                || !empty($access['is_admin'])
                || !empty($access['can_upgrade_commercial']);
        }

        return !empty($access['can_complete_upgrade_technical'])
            || !empty($access['can_create_client'])
            || !empty($access['is_manager'])
            || !empty($access['is_admin']);
    }

    private function canCompleteProcess(): bool
    {
        $access = $this->access();

        return !empty($access['can_complete_upgrade_technical'])
            || !empty($access['is_manager'])
            || !empty($access['is_admin']);
    }

    private function canCancelProcess(): bool
    {
        $access = $this->access();

        return !empty($access['can_cancel_pending_contracts'])
            || !empty($access['is_manager'])
            || !empty($access['is_admin']);
    }

    private function access(): array
    {
        return $this->localRepository->accessProfileForUser($this->resolveUser());
    }

    private function resolveUser(): array
    {
        return is_array($_SESSION['user'] ?? null)
            ? $_SESSION['user']
            : ['id' => null, 'login' => 'operador', 'name' => 'Operador', 'role' => 'technician'];
    }

    private function resolveViewUser(): array
    {
        $user = $this->resolveUser();
        $access = $this->localRepository->accessProfileForUser($user);
        $user['access'] = $access;
        $user['can_manage_settings'] = $access['can_manage_settings'] ?? false;
        $user['can_access_contracts'] = $access['can_access_contracts'] ?? false;
        $user['can_manage_financial'] = $access['can_manage_financial'] ?? false;
        $user['can_manage'] = !empty($user['can_manage']) || !empty($access['is_manager']);

        return $user;
    }

    private function csrfScope(int $processId): string
    {
        return 'operational_process:' . $processId;
    }
}
