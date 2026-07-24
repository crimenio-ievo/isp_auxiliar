<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Infrastructure\Local\LocalRepository;
use App\Services\MkAuth\MkAuthPlanChangeService;
use App\Services\Processes\OperationalProcessService;

final class OperationalProcessController
{
    public function __construct(
        private View $view,
        private Config $config,
        private LocalRepository $localRepository,
        private OperationalProcessService $processService,
        private MkAuthPlanChangeService $planChangeService
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

    public function step(Request $request): Response
    {
        $process = $this->loadAuthorizedProcess((int) $request->query('id', 0));
        if ($process instanceof Response) {
            return $process;
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
        if (!$this->canUpdateStep($process, $stepKey)) {
            Flash::set('error', 'Seu usuário não possui permissão para executar esta etapa.');
            return Response::redirect('/processos/etapa?id=' . $processId . '&step=' . rawurlencode($stepKey));
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
                return Response::redirect('/processos/etapa?id=' . $processId . '&step=' . rawurlencode($stepKey));
            }
        } elseif ($action === 'refresh_external') {
            $this->processService->synchronizeExternalState($processId);
            Flash::set('success', 'Situação atualizada pelas fontes somente leitura disponíveis.');
            return Response::redirect('/processos/etapa?id=' . $processId . '&step=' . rawurlencode($stepKey));
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
            return Response::redirect('/processos/etapa?id=' . $processId . '&step=' . rawurlencode($stepKey));
        }

        if ((string) $request->input('continue_to', '') === 'checklist') {
            return Response::redirect('/processos/detalhe?id=' . $processId);
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

        try {
            $this->processService->cancelProcess(
                $processId,
                $this->resolveUser(),
                trim((string) $request->input('reason', ''))
            );
            Flash::set('success', 'Processo cancelado. O histórico foi preservado.');
        } catch (\Throwable $exception) {
            Flash::set('error', $exception->getMessage());
        }

        return Response::redirect('/processos/detalhe?id=' . $processId);
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
