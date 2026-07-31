<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Infrastructure\Contracts\ClientDocumentRepository;
use App\Infrastructure\Contracts\ContractRepository;
use App\Infrastructure\Local\LocalRepository;
use App\Services\Contracts\ContractScannerService;

final class ClientDocumentController
{
    public function __construct(
        private View $view,
        private Config $config,
        private LocalRepository $localRepository,
        private ContractRepository $contractRepository,
        private ClientDocumentRepository $documentRepository,
        private ContractScannerService $scannerService
    ) {
    }

    public function scanner(Request $request): Response
    {
        $login = strtolower(trim((string) $request->query('login', $request->input('login', ''))));
        if (!$this->canManageDocuments() || $login === '') {
            Flash::set('error', 'Cliente ou permissão inválida para digitalização.');
            return Response::redirect('/clientes');
        }

        if ($request->method() === 'POST') {
            if (!Csrf::verify($request, $this->csrfScope($login))) {
                Flash::set('error', 'A sessão do formulário expirou.');
                return Response::redirect('/clientes/documentos/digitalizar?login=' . rawurlencode($login));
            }
            $absolute = '';
            $documentStored = false;
            try {
                $contractId = (int) $request->input('contract_id', 0);
                $contract = $contractId > 0 ? $this->contractRepository->findById($contractId) : null;
                if ($contractId > 0 && (!is_array($contract) || strcasecmp((string) ($contract['mkauth_login'] ?? ''), $login) !== 0)) {
                    throw new \RuntimeException('O contrato selecionado não pertence a este cliente.');
                }
                $order = json_decode((string) $request->input('page_order', '[]'), true);
                $rotations = json_decode((string) $request->input('page_rotations', '{}'), true);
                $order = is_array($order) ? array_map('intval', $order) : [];
                $rotations = is_array($rotations) ? $rotations : [];
                $safeLogin = preg_replace('/[^a-z0-9_.-]/i', '_', $login) ?: 'cliente';
                $relative = 'storage/contracts/scanned/' . (int) ($this->localRepository->currentProviderId() ?? 0) . '/' . $safeLogin . '/contract_' . bin2hex(random_bytes(12)) . '.pdf';
                $absolute = dirname(__DIR__, 2) . '/' . $relative;
                $result = $this->scannerService->process($_FILES['pages'] ?? [], $order, $rotations, $absolute);
                $operator = $this->resolveUser();
                $documentId = $this->documentRepository->create([
                    'mkauth_login' => $login,
                    'contract_id' => $contractId > 0 ? $contractId : null,
                    'document_type' => 'printed_contract',
                    'status' => 'final',
                    'original_name' => (string) ($result['original_name'] ?? ''),
                    'storage_path' => $relative,
                    'mime_type' => 'application/pdf',
                    'size_bytes' => filesize($absolute) ?: 0,
                    'sha256' => hash_file('sha256', $absolute) ?: '',
                    'page_count' => (int) ($result['page_count'] ?? 1),
                    'metadata' => ['source_mimes' => $result['source_mimes'] ?? [], 'order' => $order, 'rotations' => $rotations, 'legibility_confirmed' => true],
                    'created_by_user_id' => $operator['id'] ?? null,
                    'created_by_login' => $operator['login'] ?? null,
                ]);
                $documentStored = true;
                $this->localRepository->log($operator['id'] ?? null, (string) ($operator['login'] ?? ''), 'client.document.scanned', 'client_document', $documentId, ['login' => $login, 'contract_id' => $contractId, 'sha256' => hash_file('sha256', $absolute), 'pages' => $result['page_count'] ?? 1], (string) $request->server('REMOTE_ADDR', ''), (string) $request->header('User-Agent', ''));
                Flash::set('success', 'Contrato digitalizado, padronizado e vinculado ao cliente.');
            } catch (\Throwable $exception) {
                if (!$documentStored && $absolute !== '' && is_file($absolute)) {
                    @unlink($absolute);
                }
                Flash::set('error', 'Não foi possível digitalizar o contrato: ' . $exception->getMessage());
            }
            return Response::redirect('/clientes/documentos/digitalizar?login=' . rawurlencode($login));
        }

        return Response::html($this->view->render('clients/document_scanner', [
            'pageTitle' => 'Digitalizar contrato', 'currentPath' => $request->path(), 'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'), 'user' => $this->viewUser(), 'flash' => Flash::get(),
            'login' => $login, 'contracts' => $this->contractRepository->listByLogin($login, 30),
            'documents' => $this->documentRepository->listByLogin($login), 'csrfToken' => Csrf::token($this->csrfScope($login)),
        ]));
    }

    public function download(Request $request): Response
    {
        if (!$this->canManageDocuments()) return Response::html('Acesso negado.', 403);
        $document = $this->documentRepository->findById((int) $request->query('id', 0));
        $storageRoot = realpath(dirname(__DIR__, 2) . '/storage/contracts/scanned');
        $candidate = is_array($document) ? dirname(__DIR__, 2) . '/' . ltrim((string) ($document['storage_path'] ?? ''), '/') : '';
        $path = $candidate !== '' ? realpath($candidate) : false;
        if (!is_array($document)
            || $storageRoot === false
            || $path === false
            || !str_starts_with($path, $storageRoot . DIRECTORY_SEPARATOR)
            || !is_file($path)
            || strtolower((string) ($document['mime_type'] ?? '')) !== 'application/pdf'
        ) {
            return Response::html('Documento não localizado.', 404);
        }
        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="contrato-digitalizado-' . (int) $document['id'] . '.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function canManageDocuments(): bool
    {
        $access = $this->localRepository->accessProfileForUser($this->resolveUser());
        return !empty($access['can_manage_contracts']) || !empty($access['can_upgrade_request']) || !empty($access['is_admin']);
    }

    private function resolveUser(): array { return is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : []; }
    private function viewUser(): array { $user = $this->resolveUser(); $user['access'] = $this->localRepository->accessProfileForUser($user); return $user; }
    private function csrfScope(string $login): string { return 'client_document_scanner:' . hash('sha256', $login); }
}
