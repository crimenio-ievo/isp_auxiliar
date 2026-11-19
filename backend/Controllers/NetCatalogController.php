<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AccessControl;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\Map\MapRepository;
use App\Infrastructure\Net\NetCredentialRepository;
use App\Infrastructure\Net\NetEquipmentRepository;

/**
 * Credenciais cifradas (/rede-credenciais), catálogo de modelos (/rede-modelos) e equipamento físico (/rede-inventario).
 * Só gestor/admin. Escritas: CSRF do escopo "rede" (419) e auditoria net.credential.* / net.model.* / net.equipment.*.
 * O SEGREDO de uma credencial nunca volta por API, tela, log ou auditoria: só "definida em DATA por USUÁRIO" e "substituir".
 */
final class NetCatalogController
{
    private const NO_STORE = ['Cache-Control' => 'private, no-store'];
    private const NAME_RE = '/^[\p{L}\p{N}][\p{L}\p{N} ._-]{0,79}$/u';

    public function __construct(
        private View $view,
        private Config $config,
        private LocalRepository $localRepository,
        private Database $database,
        private MapRepository $mapRepository,
        private NetCredentialRepository $credentials
    ) {
    }

    // ------------------------------------------------------------------ páginas

    public function credentialsPage(Request $request): Response
    {
        return $this->page($request, 'credenciais', 'Credenciais de equipamentos');
    }

    public function modelsPage(Request $request): Response
    {
        return $this->page($request, 'modelos', 'Catálogo de modelos');
    }

    public function equipmentPage(Request $request): Response
    {
        return $this->page($request, 'inventario', 'Inventário de equipamentos');
    }

    private function page(Request $request, string $section, string $title): Response
    {
        $user = $this->viewUser();
        if (!AccessControl::isGestor($user['access'])) {
            Flash::set('error', 'Seu usuário não possui acesso a este cadastro de rede.');

            return Response::redirect('/dashboard');
        }
        $html = $this->view->render('net/catalogo', [
            'pageTitle' => $title, 'section' => $section, 'currentPath' => $request->path(), 'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'), 'user' => $user, 'csrf' => Csrf::token('rede'),
        ]);

        return Response::html($html, 200, self::NO_STORE);
    }

    // ------------------------------------------------------------------ credenciais

    public function credentials(Request $request): Response
    {
        $providerId = $this->guard($denied);
        if ($providerId === null) {
            return $denied;
        }
        try {
            $ready = $this->credentials->available();

            return Response::json(['ok' => true, 'data' => [
                'chave_disponivel' => $this->credentials->box()->available(),
                'tabela_disponivel' => $ready,
                'tipos' => NetCredentialRepository::KINDS,
                'credenciais' => $ready ? $this->credentials->list($providerId) : [],
            ]], 200, self::NO_STORE);
        } catch (\Throwable) {
            return $this->fail('Não foi possível ler as credenciais.', 500);
        }
    }

    public function credentialCreate(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard instanceof Response) {
            return $guard;
        }
        [$providerId, $userId, $actor] = $guard;
        if (!$this->credentials->box()->available()) {
            return $this->fail('Cadastro bloqueado: a chave de cifragem NET_SECRET_KEY não está definida neste servidor. Rode scripts/net_secret_key.php.', 409);
        }
        if (!$this->credentials->available()) {
            return $this->fail('A tabela de credenciais ainda não foi criada neste ambiente (migration 046).', 409);
        }
        $name = trim((string) $request->input('nome', ''));
        $kind = (string) $request->input('tipo', 'snmp_v2c');
        $user = trim((string) $request->input('usuario', ''));
        $secret = (string) $request->input('segredo', '');
        $errors = [];
        if (preg_match(self::NAME_RE, $name) !== 1) {
            $errors[] = 'Nome inválido (1 a 80 caracteres: letras, números, espaço, ponto, hífen, sublinhado).';
        }
        if (!in_array($kind, NetCredentialRepository::KINDS, true)) {
            $errors[] = 'Tipo inválido.';
        }
        if (mb_strlen($user) > 120) {
            $errors[] = 'Usuário deve ter até 120 caracteres.';
        }
        if ($secret === '' || strlen($secret) > 500) {
            $errors[] = 'Informe o segredo (até 500 caracteres).';
        }
        if ($errors === [] && $this->credentials->nameExists($providerId, $name)) {
            $errors[] = 'Já existe uma credencial com esse nome.';
        }
        if ($errors !== []) {
            return $this->fail(implode(' ', $errors), 422);
        }
        try {
            $id = $this->credentials->create($providerId, $name, $kind, $user === '' ? null : $user, $secret, $actor);
        } catch (\Throwable) {
            return $this->fail('Não foi possível salvar a credencial.', 500);
        }
        $this->audit($userId, $actor, 'net.credential.create', $id, ['nome' => $name, 'tipo' => $kind], 'net_credential');

        return Response::json(['ok' => true, 'message' => 'Credencial salva (cifrada). O segredo não será exibido de novo.', 'data' => ['id' => $id]], 200, self::NO_STORE);
    }

    public function credentialReplace(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard instanceof Response) {
            return $guard;
        }
        [$providerId, $userId, $actor] = $guard;
        if (!$this->credentials->box()->available()) {
            return $this->fail('Substituição bloqueada: a chave NET_SECRET_KEY não está definida neste servidor.', 409);
        }
        $id = $this->idOrNull((string) $request->input('id', ''));
        $secret = (string) $request->input('segredo', '');
        $user = trim((string) $request->input('usuario', ''));
        $current = $id === null || !$this->credentials->available() ? null : $this->credentials->find($providerId, $id);
        if ($current === null) {
            return $this->fail('Credencial não encontrada.', 404);
        }
        if ($secret === '' || strlen($secret) > 500 || mb_strlen($user) > 120) {
            return $this->fail('Informe o novo segredo (até 500 caracteres).', 422);
        }
        try {
            $this->credentials->replaceSecret($providerId, (int) $id, $secret, $user === '' ? $current['usuario'] : $user, $actor);
        } catch (\Throwable) {
            return $this->fail('Não foi possível substituir a credencial.', 500);
        }
        $this->audit($userId, $actor, 'net.credential.replace', (int) $id, ['nome' => $current['nome']], 'net_credential');

        return Response::json(['ok' => true, 'message' => 'Segredo substituído. Teste a conexão dos equipamentos que usam esta credencial.', 'data' => ['id' => (int) $id]], 200, self::NO_STORE);
    }

    public function credentialDelete(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard instanceof Response) {
            return $guard;
        }
        [$providerId, $userId, $actor] = $guard;
        $id = $this->idOrNull((string) $request->input('id', ''));
        $current = $id === null || !$this->credentials->available() ? null : $this->credentials->find($providerId, $id);
        if ($current === null) {
            return $this->fail('Credencial não encontrada.', 404);
        }
        if ((int) $current['equipamentos'] > 0) {
            return $this->fail('Há ' . $current['equipamentos'] . ' equipamento(s) usando esta credencial. Troque a credencial deles antes de excluir.', 409);
        }
        $this->credentials->delete($providerId, (int) $id);
        $this->audit($userId, $actor, 'net.credential.delete', (int) $id, ['nome' => $current['nome']], 'net_credential');

        return Response::json(['ok' => true, 'message' => 'Credencial excluída.', 'data' => ['id' => (int) $id]], 200, self::NO_STORE);
    }

    // ------------------------------------------------------------------ modelos

    public function models(Request $request): Response
    {
        $providerId = $this->guard($denied);
        if ($providerId === null) {
            return $denied;
        }
        $repo = $this->catalog();
        if (!$repo->available()) {
            return $this->fail('O catálogo ainda não foi criado neste ambiente (migration 047).', 409);
        }

        $repo->seedFacts($providerId);

        return Response::json(['ok' => true, 'data' => [
            'modelos' => $repo->models($providerId), 'tipos' => NetEquipmentRepository::TYPES, 'familias_portas' => NetEquipmentRepository::PORT_FAMILIES,
        ]], 200, self::NO_STORE);
    }

    public function modelSave(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard instanceof Response) {
            return $guard;
        }
        [$providerId, $userId, $actor] = $guard;
        $repo = $this->catalog();
        if (!$repo->available()) {
            return $this->fail('O catálogo ainda não foi criado neste ambiente (migration 047).', 409);
        }
        $id = $this->idOrNull((string) $request->input('id', ''));
        $spec = null;
        $rawSpec = trim((string) $request->input('ficha', ''));
        if ($rawSpec !== '') {
            $spec = json_decode($rawSpec, true);
            if (!is_array($spec)) {
                return $this->fail('Ficha técnica: informe um JSON válido (objeto).', 422);
            }
        }
        $ports = [];
        foreach (NetEquipmentRepository::PORT_FAMILIES as $f) {
            $ports[$f] = $request->input('portas_' . $f);
        }
        [$savedId, $errors] = $repo->saveModel($providerId, $id, [
            'vendor' => $request->input('fabricante', ''), 'model' => $request->input('modelo', ''), 'type' => $request->input('tipo', 'outro'),
            'technology' => $request->input('tecnologia', ''), 'ports' => $ports, 'default_profile_code' => $request->input('perfil_padrao', ''),
            'spec' => $spec, 'notes' => $request->input('observacao', ''),
        ]);
        if ($savedId === null) {
            return $this->fail(implode(' ', $errors), 422);
        }
        $this->audit($userId, $actor, $id === null ? 'net.model.create' : 'net.model.update', $savedId, ['fabricante' => (string) $request->input('fabricante', ''), 'modelo' => (string) $request->input('modelo', '')], 'net_equipment_model');

        return Response::json(['ok' => true, 'message' => $id === null ? 'Modelo cadastrado.' : 'Modelo atualizado.', 'data' => ['id' => $savedId]], 200, self::NO_STORE);
    }

    public function modelDelete(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard instanceof Response) {
            return $guard;
        }
        [$providerId, $userId, $actor] = $guard;
        $repo = $this->catalog();
        $id = $this->idOrNull((string) $request->input('id', ''));
        $model = $id === null || !$repo->available() ? null : $repo->model($providerId, $id);
        if ($model === null) {
            return $this->fail('Modelo não encontrado.', 404);
        }
        if ($repo->modelInUse($providerId, (int) $id)) {
            return $this->fail('Há equipamentos cadastrados com este modelo. Não é possível excluir.', 409);
        }
        $repo->deleteModel($providerId, (int) $id);
        $this->audit($userId, $actor, 'net.model.delete', (int) $id, ['fabricante' => $model['fabricante'], 'modelo' => $model['modelo']], 'net_equipment_model');

        return Response::json(['ok' => true, 'message' => 'Modelo excluído.', 'data' => ['id' => (int) $id]], 200, self::NO_STORE);
    }

    // ------------------------------------------------------------------ equipamento físico

    public function equipment(Request $request): Response
    {
        $providerId = $this->guard($denied);
        if ($providerId === null) {
            return $denied;
        }
        $repo = $this->catalog();
        if (!$repo->available()) {
            return $this->fail('O catálogo ainda não foi criado neste ambiente (migration 047).', 409);
        }
        $nodes = [];
        foreach ($this->mapRepository->topology($providerId)['nodes'] as $n) {
            if (in_array($n['node_type'], ['olt', 'switch', 'router', 'radio', 'server', 'other'], true)) {
                $nodes[] = ['id' => (int) $n['id'], 'nome' => (string) $n['name'], 'tipo' => (string) $n['node_type']];
            }
        }
        $repo->seedFacts($providerId);
        $models = $repo->models($providerId);
        $histId = $this->idOrNull((string) $request->query('historico', ''));

        return Response::json(['ok' => true, 'data' => [
            'equipamentos' => $repo->equipment($providerId),
            'modelos' => array_map(static fn (array $m): array => ['id' => $m['id'], 'rotulo' => $m['fabricante'] . ' ' . $m['modelo'], 'tipo' => $m['tipo']], $models),
            'nos' => $nodes, 'situacoes' => NetEquipmentRepository::STATUSES, 'locais' => NetEquipmentRepository::LOCATIONS,
            'historico' => $histId === null ? null : $repo->movements($providerId, $histId),
        ]], 200, self::NO_STORE);
    }

    public function equipmentSave(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard instanceof Response) {
            return $guard;
        }
        [$providerId, $userId, $actor] = $guard;
        $repo = $this->catalog();
        if (!$repo->available()) {
            return $this->fail('O catálogo ainda não foi criado neste ambiente (migration 047).', 409);
        }
        $id = $this->idOrNull((string) $request->input('id', ''));
        [$savedId, $errors] = $repo->saveEquipment($providerId, $id, [
            'model_id' => $request->input('modelo_id', ''), 'serial' => $request->input('serie', ''), 'mac' => $request->input('mac', ''),
            'asset_tag' => $request->input('patrimonio', ''), 'status' => $request->input('situacao', 'instalado'),
            'location_kind' => $request->input('local_tipo', 'no_mapa'), 'location_node_id' => $request->input('no_id', ''),
            'location_note' => $request->input('local_obs', ''), 'notes' => $request->input('observacao', ''),
        ], $actor);
        if ($savedId === null) {
            return $this->fail(implode(' ', $errors), $errors === ['Equipamento não encontrado.'] ? 404 : 422);
        }
        $this->audit($userId, $actor, $id === null ? 'net.equipment.create' : 'net.equipment.update', $savedId, ['modelo_id' => (int) $request->input('modelo_id', 0), 'situacao' => (string) $request->input('situacao', '')], 'net_equipment');

        return Response::json(['ok' => true, 'message' => $id === null ? 'Equipamento cadastrado.' : 'Equipamento atualizado.', 'data' => ['id' => $savedId]], 200, self::NO_STORE);
    }

    // ------------------------------------------------------------------ apoio

    private function catalog(): NetEquipmentRepository
    {
        return new NetEquipmentRepository($this->database->pdo());
    }

    private function idOrNull(string $raw): ?int
    {
        return preg_match('/^[1-9][0-9]{0,14}$/', $raw) === 1 ? (int) $raw : null;
    }

    /** @param Response|null $denied */
    private function guard(?Response &$denied): ?int
    {
        $user = $this->viewUser();
        if (!AccessControl::isGestor($user['access'])) {
            $denied = $this->fail('Acesso negado.', 403);

            return null;
        }
        $providerId = $this->localRepository->currentProviderId();
        if ($providerId === null) {
            $denied = $this->fail('Provedor não configurado.', 500);

            return null;
        }

        return $providerId;
    }

    /** @return array{0:int,1:?int,2:string}|Response */
    private function guardWrite(Request $request): array|Response
    {
        $providerId = $this->guard($denied);
        if ($providerId === null) {
            return $denied;
        }
        if (!Csrf::verify($request, 'rede')) {
            return $this->fail('Sessão expirada. Recarregue a página.', 419);
        }
        $user = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
        $userId = (int) ($user['id'] ?? 0);
        try {
            $userId = $userId > 0 && $this->mapRepository->userExists($providerId, $userId) ? $userId : 0;
        } catch (\Throwable) {
            $userId = 0;
        }

        return [$providerId, $userId > 0 ? $userId : null, (string) ($user['login'] ?? '')];
    }

    /** @param array<string,mixed> $context nunca contém segredo */
    private function audit(?int $userId, string $actor, string $action, int $entityId, array $context, string $entityType): void
    {
        $this->localRepository->log($userId, $actor, $action, $entityType, $entityId, $context, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255));
    }

    private function fail(string $message, int $status): Response
    {
        return Response::json(['ok' => false, 'message' => $message], $status, self::NO_STORE);
    }

    /** @return array<string,mixed> */
    private function viewUser(): array
    {
        $user = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
        $user['login'] = $this->localRepository->normalizeLogin((string) ($user['login'] ?? ''));
        $access = $this->localRepository->accessProfileForUser($user);
        $user['access'] = $access;
        $user['can_manage_settings'] = $access['can_manage_settings'];
        $user['can_access_contracts'] = $access['can_access_contracts'];
        if ($access['is_admin']) {
            $user['role'] = 'admin';
        } elseif ($access['is_manager']) {
            $user['role'] = 'manager';
        }

        return $user;
    }
}
