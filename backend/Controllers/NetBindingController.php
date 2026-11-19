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
use App\Infrastructure\Net\BindingConflict;
use App\Infrastructure\Net\ClientDirectory;
use App\Infrastructure\Net\NetBindingRepository;
use App\Infrastructure\Net\Coordinates;
use App\Infrastructure\Net\NetCtoPicker;
use App\Infrastructure\Net\NetCtoRepository;
use App\Infrastructure\Net\OnuMatchSuggester;

/**
 * Vinculos cliente <-> CTO/porta e ONU <-> cliente.
 *  - /rede-ctos/ficha: ficha da CTO (portas, clientes) - gestor grava, tecnico (busca de clientes) so le.
 *  - /rede-vinculos: ONUs sem vinculo e sugestoes pela regra do MAC - gestor/admin. Nada e vinculado sem confirmacao.
 * O MkAuth so e LIDO (busca de cliente e radacct); o vinculo guarda so o login (+ nome como foto de exibicao).
 * Escritas: CSRF do escopo "rede" (419) e auditoria net.binding.*.
 */
final class NetBindingController
{
    private const NO_STORE = ['Cache-Control' => 'private, no-store'];
    private const CONFIDENCE = ['alta', 'media', 'baixa'];

    public function __construct(
        private View $view,
        private Config $config,
        private LocalRepository $localRepository,
        private Database $database,
        private MapRepository $mapRepository,
        private ClientDirectory $mkauth
    ) {
    }

    // ---------------------------------------------------------------- paginas

    public function fichaPage(Request $request): Response
    {
        $user = $this->viewUser();
        if (!$this->canRead($user['access'])) {
            Flash::set('error', 'Seu usuário não possui acesso às CTOs.');

            return Response::redirect('/dashboard');
        }
        $id = $request->query('id');
        $html = $this->view->render('net/cto_ficha', [
            'pageTitle' => 'Ficha da CTO',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $user,
            'csrf' => Csrf::token(NetDeviceController::CSRF_SCOPE),
            'canEdit' => AccessControl::isGestor($user['access']),
            'ctoId' => is_string($id) && ctype_digit($id) ? (int) $id : 0,
        ]);

        return Response::html($html, 200, self::NO_STORE);
    }

    public function vinculosPage(Request $request): Response
    {
        $user = $this->viewUser();
        if (!AccessControl::isGestor($user['access'])) {
            Flash::set('error', 'Seu usuário não possui acesso aos vínculos de rede.');

            return Response::redirect('/dashboard');
        }
        $html = $this->view->render('net/vinculos', [
            'pageTitle' => 'Vínculos de rede',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $user,
            'csrf' => Csrf::token(NetDeviceController::CSRF_SCOPE),
        ]);

        return Response::html($html, 200, self::NO_STORE);
    }

    // ---------------------------------------------------------------- leitura

    public function ficha(Request $request): Response
    {
        $ctx = $this->guard(false, $denied);
        if ($ctx === null) {
            return $denied;
        }
        [$providerId, $canEdit] = $ctx;
        $id = $request->query('id');
        $cto = is_string($id) && ctype_digit($id) && $id !== '0' ? (new NetCtoRepository($this->database->pdo()))->find($providerId, (int) $id) : null;
        if ($cto === null) {
            return $this->fail('CTO não encontrada.', 404);
        }
        $bindings = new NetBindingRepository($this->database->pdo());
        $byPort = [];
        $noPort = [];
        $onusNaCto = [];
        $nClientes = 0;
        foreach ($bindings->available() ? $bindings->forCto($providerId, (int) $cto['id']) : [] as $b) {
            $row = $this->bindingView($b);
            $nClientes++;
            if ($b['onu_id'] !== null) {
                $onusNaCto[(int) $b['onu_id']] = true;
            }
            $b['cto_port_id'] === null ? $noPort[] = $row : $byPort[(int) $b['cto_port_id']][] = $row;
        }
        $portas = [];
        foreach ($this->mapRepository->portsOfFamily($providerId, (int) $cto['id'], 'fo') as $p) {
            $link = $this->mapRepository->linkUsingPort($providerId, (int) $p['id']);
            $portas[] = [
                'id' => (int) $p['id'], 'num' => (int) $p['port_num'], 'rotulo' => (string) $p['label'], 'status' => (string) $p['status'],
                'ligacao' => $link === null ? null : ($link['label'] !== null && $link['label'] !== '' ? (string) $link['label'] : 'ligação #' . $link['id']),
                'cliente' => $byPort[(int) $p['id']][0] ?? null,                 // primeiro (compatibilidade)
                'clientes' => $byPort[(int) $p['id']] ?? [],                      // varios clientes quando a porta e de uma ONU compartilhada
                'onu' => ($byPort[(int) $p['id']][0]['onu'] ?? null),
            ];
        }

        return Response::json(['ok' => true, 'data' => [
            'cto' => $cto, 'portas' => $portas, 'sem_porta' => $noPort, 'pode_editar' => $canEdit,
            'contagem' => ['clientes' => $nClientes, 'onus' => count($onusNaCto), 'portas_com_cliente' => count($byPort)],
            'vinculos_disponiveis' => $bindings->available(),
        ]], 200, self::NO_STORE);
    }

    /** Busca de cliente no MkAuth (somente leitura) por nome, login ou CPF/CNPJ. */
    public function searchClients(Request $request): Response
    {
        $ctx = $this->guard(true, $denied);
        if ($ctx === null) {
            return $denied;
        }
        [$providerId] = $ctx;
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 3 || mb_strlen($q) > 80) {
            return Response::json(['ok' => true, 'data' => []], 200, self::NO_STORE);
        }
        try {
            $rows = $this->mkauth->search($q, 8);
        } catch (\Throwable) {
            return $this->fail('Não foi possível consultar o MkAuth agora.', 503);
        }
        $bindings = new NetBindingRepository($this->database->pdo());
        $out = [];
        foreach ($rows as $r) {
            $login = (string) $r['login'];
            $b = $bindings->available() ? $bindings->active($providerId, $login) : null;
            $out[] = [
                'login' => $login, 'nome' => (string) $r['nome'], 'bairro' => (string) $r['bairro'], 'cidade' => (string) $r['cidade'],
                'status' => (string) $r['status'], 'vinculo' => $b === null ? null : $this->bindingView($b),
            ];
        }

        return Response::json(['ok' => true, 'data' => $out], 200, self::NO_STORE);
    }

    /** ONUs sem vinculo com a melhor sugestao pelo MAC (somente leitura no MkAuth). */
    public function suggestions(Request $request): Response
    {
        $ctx = $this->guard(true, $denied);
        if ($ctx === null) {
            return $denied;
        }
        [$providerId] = $ctx;
        $bindings = new NetBindingRepository($this->database->pdo());
        if (!$bindings->available()) {
            return $this->fail('A tabela de vínculos ainda não foi criada neste ambiente (migration 045).', 409);
        }
        try {
            $mk = $this->mkauth->recentCallerMacs(30);
            $suggester = new OnuMatchSuggester($this->database->pdo(), $bindings);
            $first = $suggester->suggest($providerId, $mk['callers'], []);
            $logins = [];
            foreach ($first['sugestoes'] as $s) {
                foreach ($s['candidatos'] as $c) {
                    $logins[] = $c['login'];
                }
            }
            $names = $this->mkauth->namesByLogins($logins);
            $result = $suggester->suggest($providerId, $mk['callers'], $names);
            try { $base = $this->mkauth->latestVisibleDate(); } catch (\Throwable) { $base = ['data' => null, 'origem' => null]; }
        } catch (\Throwable) {
            return $this->fail('Não foi possível consultar o MkAuth agora.', 503);
        }

        return Response::json(['ok' => true, 'data' => $result + [
            'base_mkauth_ate' => $base['data'], 'base_mkauth_origem' => $base['origem'], 'janela_dias' => 30, 'radacct_referencia' => $mk['referencia'], 'janela_inicio' => $mk['inicio'],
            'regra' => 'MAC do PPPoE igual ao da ONU nos 5 primeiros octetos; diferença de até 1 no último octeto = alta, até 16 = média, demais = baixa',
        ]], 200, self::NO_STORE);
    }

    // ---------------------------------------------------------------- escrita

    /** Liga (ou move) o cliente a uma porta de CTO. */
    public function bindCto(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard instanceof Response) {
            return $guard;
        }
        [$providerId, $userId, $actor] = $guard;
        $bindings = new NetBindingRepository($this->database->pdo());
        if (!$bindings->available()) {
            return $this->fail('A tabela de vínculos ainda não foi criada neste ambiente (migration 045).', 409);
        }
        $ctoId = $this->idOrNull((string) $request->input('cto_id', ''));
        $portId = $this->idOrNull((string) $request->input('port_id', ''));
        $login = trim((string) $request->input('login', ''));
        $cto = $ctoId === null ? null : $this->mapRepository->findNode($providerId, $ctoId);
        if ($cto === null || $cto['node_type'] !== 'cto') {
            return $this->fail('CTO não encontrada.', 404);
        }
        $port = $portId === null ? null : $this->mapRepository->findPort($providerId, $portId);
        if ($port === null || (int) $port['node_id'] !== (int) $cto['id'] || $port['port_role'] !== 'fo') {
            return $this->fail('A porta não pertence a esta CTO.', 422);
        }
        if ($port['status'] === 'defeito') {
            return $this->fail($port['label'] . ' está com defeito e não pode receber cliente.', 422);
        }
        if ($this->mapRepository->linkUsingPort($providerId, (int) $port['id']) !== null) {
            return $this->fail($port['label'] . ' está em uma ligação do mapa (alimentação/derivação) e não pode receber cliente.', 422);
        }
        if ($login === '' || mb_strlen($login) > 120 || preg_match('/[\x00-\x1F\x7F]/', $login) === 1) {
            return $this->fail('Escolha o cliente.', 422);
        }
        try {
            $client = $this->mkauth->find($login);
        } catch (\Throwable) {
            return $this->fail('Não foi possível consultar o MkAuth agora.', 503);
        }
        if ($client === null || strcasecmp((string) $client['login'], $login) !== 0) {
            return $this->fail('Cliente não encontrado no MkAuth.', 404);
        }
        $login = (string) $client['login'];
        $nome = mb_substr((string) $client['nome'], 0, 160);

        // Porta ocupada: so vale se for da MESMA ONU do cliente (a regra completa, travada em transacao, esta no repositorio).
        $occupant = null;
        foreach ($bindings->activeListByPort($providerId, (int) $port['id']) as $o) {
            if (strcasecmp((string) $o['mkauth_login'], $login) !== 0) {
                $occupant = $o;
                break;
            }
        }
        $current = $bindings->active($providerId, $login);
        if ($current !== null && $current['cto_port_id'] !== null && (int) $current['cto_port_id'] === (int) $port['id']) {
            return Response::json(['ok' => true, 'message' => 'O cliente já está nesta porta.', 'data' => ['id' => (int) $current['id']]], 200, self::NO_STORE);
        }
        if ($occupant !== null && ($current === null || $current['onu_id'] === null || $occupant['onu_id'] === null || (int) $occupant['onu_id'] !== (int) $current['onu_id'])) {
            return $this->fail($port['label'] . ' já tem o cliente ' . ($occupant['cliente_nome'] ?: $occupant['mkauth_login']) . ' vinculado' . ($occupant['onu_id'] !== null ? ' (ONU de outro cliente)' : '') . '.', 409);
        }
        if ($current !== null && $current['cto_port_id'] !== null && (string) $request->input('mover', '') !== '1') {
            $outros = $current['onu_id'] === null ? 0 : count($bindings->activeListByOnu($providerId, (int) $current['onu_id'])) - 1;

            return Response::json([
                'ok' => false, 'codigo' => 'mover',
                'message' => $nome . ' já está em ' . $current['cto_nome'] . ', ' . $current['porta_rotulo'] . '. Confirme para mover para ' . $cto['name'] . ', ' . $port['label'] . '.'
                    . ($outros > 0 ? ' A porta é da ONU: os outros ' . $outros . ' cliente(s) dessa ONU vão junto.' : ''),
            ], 409, self::NO_STORE);
        }
        try {
            $res = $bindings->bindCto($providerId, $login, $nome, (int) $cto['id'], (int) $port['id'], $actor);
        } catch (BindingConflict $e) {
            return $this->fail($e->getMessage(), 409);
        } catch (\Throwable) {
            return $this->fail('Não foi possível salvar o vínculo.', 500);
        }
        $moved = $res['antes'] !== null && $res['antes']['cto_port_id'] !== null;
        $this->audit($userId, $actor, $moved ? 'net.binding.move' : 'net.binding.create', $res['id'], [
            'login' => $login, 'cto' => $cto['name'], 'cto_id' => (int) $cto['id'], 'porta' => $port['label'], 'porta_id' => (int) $port['id'],
            'antes' => $moved ? ['cto_id' => (int) $res['antes']['cto_node_id'], 'porta_id' => (int) $res['antes']['cto_port_id']] : null,
            'linhas_movidas' => $res['movidos'],
        ]);

        return Response::json(['ok' => true, 'message' => $moved ? 'Cliente movido para ' . $port['label'] . '.' : 'Cliente vinculado a ' . $port['label'] . '.', 'data' => ['id' => $res['id']]], 200, self::NO_STORE);
    }

    /** Desvincula: parte = cto | onu | tudo. */
    public function remove(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard instanceof Response) {
            return $guard;
        }
        [$providerId, $userId, $actor] = $guard;
        $bindings = new NetBindingRepository($this->database->pdo());
        $part = (string) $request->input('parte', 'tudo');
        if (!in_array($part, ['cto', 'onu', 'tudo'], true) || !$bindings->available()) {
            return $this->fail('Pedido inválido.', 422);
        }
        $id = $this->idOrNull((string) $request->input('id', ''));
        $before = $id === null ? null : $bindings->remove($providerId, $id, $part, $actor);
        if ($before === null) {
            return $this->fail('Vínculo não encontrado.', 404);
        }
        $this->audit($userId, $actor, 'net.binding.remove', (int) $before['id'], [
            'login' => $before['mkauth_login'], 'parte' => $part, 'cto_id' => $before['cto_node_id'] === null ? null : (int) $before['cto_node_id'],
            'porta_id' => $before['cto_port_id'] === null ? null : (int) $before['cto_port_id'], 'onu_id' => $before['onu_id'] === null ? null : (int) $before['onu_id'],
        ]);

        return Response::json(['ok' => true, 'message' => 'Vínculo removido.', 'data' => ['id' => (int) $before['id']]], 200, self::NO_STORE);
    }

    /** Confirma ou recusa uma sugestao ONU <-> cliente. Nada e vinculado sem este POST. */
    public function onu(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard instanceof Response) {
            return $guard;
        }
        [$providerId, $userId, $actor] = $guard;
        $bindings = new NetBindingRepository($this->database->pdo());
        if (!$bindings->available()) {
            return $this->fail('A tabela de vínculos ainda não foi criada neste ambiente (migration 045).', 409);
        }
        $action = (string) $request->input('acao', '');
        $onuId = $this->idOrNull((string) $request->input('onu_id', ''));
        $login = trim((string) $request->input('login', ''));
        if (!in_array($action, ['confirmar', 'recusar', 'adicionar'], true) || $login === '' || mb_strlen($login) > 120) {
            return $this->fail('Pedido inválido.', 422);
        }
        $modoRaw = trim((string) $request->input('modo', ''));
        if ($modoRaw !== '' && !in_array($modoRaw, NetBindingRepository::MODOS, true)) {
            return $this->fail('Modo inválido (pppoe_na_onu ou atras_da_onu; vazio = não verificado).', 422);
        }
        $modo = $modoRaw === '' ? null : $modoRaw;
        $st = $this->database->pdo()->prepare('SELECT id, removed_at FROM net_onus WHERE id = ? AND provider_id = ?');
        $st->execute([$onuId ?? 0, $providerId]);
        $onu = $st->fetch(\PDO::FETCH_ASSOC);
        if ($onu === false || $onu['removed_at'] !== null) {
            return $this->fail('ONU não encontrada.', 404);
        }

        if ($action === 'recusar') {
            $bindings->reject($providerId, (int) $onu['id'], $login, $actor);
            $this->audit($userId, $actor, 'net.binding.onu.reject', (int) $onu['id'], ['login' => $login, 'onu_id' => (int) $onu['id']]);

            return Response::json(['ok' => true, 'message' => 'Sugestão recusada: ela não aparece mais.', 'data' => ['onu_id' => (int) $onu['id']]], 200, self::NO_STORE);
        }

        try {
            $client = $this->mkauth->find($login);
        } catch (\Throwable) {
            return $this->fail('Não foi possível consultar o MkAuth agora.', 503);
        }
        if ($client === null || strcasecmp((string) $client['login'], $login) !== 0) {
            return $this->fail('Cliente não encontrado no MkAuth.', 404);
        }
        $login = (string) $client['login'];
        // Varios clientes por ONU: ONU ja vinculada recebe mais um (acao "adicionar"); a sugestao pelo MAC ("confirmar") segue
        // valendo so para ONU sem vinculo, para nao empilhar clientes por engano.
        $already = $bindings->activeListByOnu($providerId, (int) $onu['id']);
        if ($already !== [] && $action === 'confirmar') {
            return $this->fail('Essa ONU já está vinculada a ' . ($already[0]['cliente_nome'] ?: $already[0]['mkauth_login']) . '. Use "adicionar cliente" para incluir mais um.', 409);
        }
        $current = $bindings->active($providerId, $login);
        if ($current !== null && $current['onu_id'] !== null) {
            return $this->fail('Esse cliente já tem outra ONU vinculada.', 409);
        }
        $conf = (string) $request->input('confianca', '');
        $conf = in_array($conf, self::CONFIDENCE, true) ? $conf : null;
        try {
            $res = $bindings->bindOnu($providerId, $login, mb_substr((string) $client['nome'], 0, 160), (int) $onu['id'], $action === 'adicionar' ? 'manual' : 'sugestao_mac', $action === 'adicionar' ? null : $conf, $actor, $modo);
        } catch (BindingConflict $e) {
            return $this->fail($e->getMessage(), 409);
        } catch (\Throwable) {
            return $this->fail('Não foi possível salvar o vínculo.', 500);
        }
        $this->audit($userId, $actor, $action === 'adicionar' ? 'net.binding.onu.add' : 'net.binding.onu.confirm', $res['id'], [
            'login' => $login, 'onu_id' => (int) $onu['id'], 'confianca' => $conf, 'origem' => $action === 'adicionar' ? 'manual' : 'sugestao_mac', 'modo_pppoe' => $modo, 'clientes_na_onu' => count($already) + 1,
        ]);

        return Response::json(['ok' => true, 'message' => ($action === 'adicionar' ? 'Cliente ' : 'ONU vinculada a ') . $client['nome'] . ($action === 'adicionar' ? ' adicionado à ONU.' : '.'), 'data' => ['id' => $res['id']]], 200, self::NO_STORE);
    }

    /**
     * Ficha da ONU: os clientes vinculados com os dados do MkAuth que ajudam a localizar o assinante, SEM abrir o cliente.
     * Somente leitura; lista fechada de campos (nunca CPF/CNPJ, telefone nem e-mail). MkAuth fora do ar nao derruba a ficha:
     * os campos do MkAuth vem null e `mkauth_ok=false`.
     */
    public function onuClients(Request $request): Response
    {
        $ctx = $this->guard(true, $denied);
        if ($ctx === null) {
            return $denied;
        }
        [$providerId] = $ctx;
        $onuId = $this->idOrNull((string) $request->query('id', ''));
        $bindings = new NetBindingRepository($this->database->pdo());
        if ($onuId === null || !$bindings->available()) {
            return $this->fail('ONU inválida.', 422);
        }
        $rows = $bindings->activeListByOnu($providerId, $onuId);
        $briefs = [];
        $ok = true;
        if ($rows !== []) {
            try {
                $briefs = $this->mkauth->briefByLogins(array_map(static fn (array $r): string => (string) $r['mkauth_login'], $rows));
            } catch (\Throwable) {
                $ok = false;
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $b = $briefs[strtolower((string) $r['mkauth_login'])] ?? null;
            $pair = $b !== null && $b['coordenadas'] !== '' ? Coordinates::parsePair($b['coordenadas']) : null;
            $street = $b === null ? '' : trim($b['endereco'] . ($b['numero'] !== '' ? ', ' . $b['numero'] : ''));
            $out[] = [
                'vinculo_id' => (int) $r['id'], 'login' => (string) $r['mkauth_login'], 'nome' => $b['nome'] ?? ($r['cliente_nome'] === null ? null : (string) $r['cliente_nome']),
                'modo_pppoe' => $r['modo_pppoe'] ?? null,
                'endereco' => $b === null ? null : ($street !== '' ? $street : null), 'bairro' => $b === null ? null : ($b['bairro'] !== '' ? $b['bairro'] : null),
                'cidade' => $b === null ? null : ($b['cidade'] !== '' ? $b['cidade'] : null),
                'coordenadas' => $pair === null ? null : ['lat' => $pair[0], 'lng' => $pair[1]],
                'plano' => $b === null || $b['plano'] === '' ? null : $b['plano'], 'situacao_mkauth' => $b['situacao'] ?? null,
                'encontrado_no_mkauth' => $b !== null,
            ];
        }

        return Response::json(['ok' => true, 'data' => ['clientes' => $out, 'mkauth_ok' => $ok]], 200, self::NO_STORE);
    }

    /** Bairro/cidade (e login) dos clientes com ONU, numa consulta so, para as colunas opcionais de /rede-fibra. Somente leitura. */
    public function clientsSummary(Request $request): Response
    {
        $ctx = $this->guard(true, $denied);
        if ($ctx === null) {
            return $denied;
        }
        [$providerId] = $ctx;
        $bindings = new NetBindingRepository($this->database->pdo());
        if (!$bindings->available()) {
            return $this->fail('A tabela de vínculos ainda não foi criada neste ambiente (migration 045).', 409);
        }
        $st = $this->database->pdo()->prepare('SELECT DISTINCT mkauth_login FROM client_network_bindings WHERE provider_id = ? AND ativo = 1 AND onu_id IS NOT NULL');
        $st->execute([$providerId]);
        $logins = array_map('strval', $st->fetchAll(\PDO::FETCH_COLUMN));
        try {
            $briefs = $logins === [] ? [] : $this->mkauth->briefByLogins($logins);
        } catch (\Throwable) {
            return $this->fail('Não foi possível consultar o MkAuth agora.', 503);
        }
        $out = [];
        foreach ($briefs as $key => $b) {
            $out[$key] = ['bairro' => $b['bairro'] !== '' ? $b['bairro'] : null, 'cidade' => $b['cidade'] !== '' ? $b['cidade'] : null];
        }

        return Response::json(['ok' => true, 'data' => ['clientes' => $out]], 200, self::NO_STORE);
    }

    /**
     * "Definir CTO" de uma ONU: CTOs por nome (q) ou, sem q, as MAIS PROXIMAS das coordenadas do cliente (primeiro cliente da ONU
     * com coordenada no MkAuth), cada uma com as portas livres. Somente leitura.
     */
    public function nearbyCtos(Request $request): Response
    {
        $ctx = $this->guard(true, $denied);
        if ($ctx === null) {
            return $denied;
        }
        [$providerId] = $ctx;
        $onuId = $this->idOrNull((string) $request->query('onu_id', ''));
        $q = trim((string) $request->query('q', ''));
        $bindings = new NetBindingRepository($this->database->pdo());
        if ($onuId === null || !$bindings->available() || mb_strlen($q) > 80) {
            return $this->fail('Pedido inválido.', 422);
        }
        $rows = $bindings->activeListByOnu($providerId, $onuId);
        $near = null;
        $ref = null;
        $aviso = null;
        if ($rows === []) {
            $aviso = 'Esta ONU ainda não tem cliente vinculado: vincule um cliente antes de definir a CTO.';
        } else {
            try {
                $briefs = $this->mkauth->briefByLogins(array_map(static fn (array $r): string => (string) $r['mkauth_login'], $rows));
                foreach ($rows as $r) {
                    $b = $briefs[strtolower((string) $r['mkauth_login'])] ?? null;
                    $pair = $b !== null && $b['coordenadas'] !== '' ? Coordinates::parsePair($b['coordenadas']) : null;
                    if ($pair !== null) {
                        $near = [$pair[0], $pair[1]];
                        $ref = ['login' => (string) $r['mkauth_login'], 'lat' => $pair[0], 'lng' => $pair[1]];
                        break;
                    }
                }
                if ($near === null) {
                    $aviso = 'Nenhum cliente desta ONU tem coordenadas no MkAuth: a lista está por nome.';
                }
            } catch (\Throwable) {
                $aviso = 'Não foi possível consultar o MkAuth agora: a lista está por nome, sem a distância.';
            }
        }
        $cur = null;
        foreach ($rows as $r) {
            if ($r['cto_port_id'] !== null) {
                $cur = ['cto_id' => (int) $r['cto_node_id'], 'cto' => (string) $r['cto_nome'], 'porta_id' => (int) $r['cto_port_id'], 'porta' => (string) $r['porta_rotulo']];
                break;
            }
        }

        return Response::json(['ok' => true, 'data' => [
            'ctos' => (new NetCtoPicker($this->database->pdo()))->search($providerId, $q, $near, 8),
            'referencia' => $ref, 'aviso' => $aviso, 'n_clientes' => count($rows), 'atual' => $cur,
        ]], 200, self::NO_STORE);
    }

    /**
     * Define (ou troca) a CTO/porta de uma ONU. Regras da 048: a porta e da ONU, entao TODOS os clientes da ONU vao juntos; a porta so
     * aceita a mesma ONU. Quando a ONU ja esta numa porta, pede confirmacao (codigo "mover") dizendo quantos clientes vao junto.
     */
    public function bindOnuCto(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard instanceof Response) {
            return $guard;
        }
        [$providerId, $userId, $actor] = $guard;
        $bindings = new NetBindingRepository($this->database->pdo());
        if (!$bindings->available()) {
            return $this->fail('A tabela de vínculos ainda não foi criada neste ambiente (migration 045).', 409);
        }
        $onuId = $this->idOrNull((string) $request->input('onu_id', ''));
        $ctoId = $this->idOrNull((string) $request->input('cto_id', ''));
        $portId = $this->idOrNull((string) $request->input('port_id', ''));
        $rows = $onuId === null ? [] : $bindings->activeListByOnu($providerId, $onuId);
        if ($rows === []) {
            return $this->fail('Vincule um cliente a esta ONU antes de definir a CTO.', 409);
        }
        $cto = $ctoId === null ? null : $this->mapRepository->findNode($providerId, $ctoId);
        if ($cto === null || $cto['node_type'] !== 'cto') {
            return $this->fail('CTO não encontrada.', 404);
        }
        $port = $portId === null ? null : $this->mapRepository->findPort($providerId, $portId);
        if ($port === null || (int) $port['node_id'] !== (int) $cto['id'] || $port['port_role'] !== 'fo') {
            return $this->fail('A porta não pertence a esta CTO.', 422);
        }
        if ($port['status'] === 'defeito') {
            return $this->fail($port['label'] . ' está com defeito e não pode receber cliente.', 422);
        }
        if ($this->mapRepository->linkUsingPort($providerId, (int) $port['id']) !== null) {
            return $this->fail($port['label'] . ' está em uma ligação do mapa (alimentação/derivação) e não pode receber cliente.', 422);
        }
        foreach ($bindings->activeListByPort($providerId, (int) $port['id']) as $o) {
            if ($o['onu_id'] === null || (int) $o['onu_id'] !== $onuId) {
                return $this->fail($port['label'] . ' já está ocupada por ' . ($o['cliente_nome'] ?: $o['mkauth_login']) . ($o['onu_id'] !== null ? ' (outra ONU)' : '') . '.', 409);
            }
        }
        $n = count($rows);
        $current = null;
        foreach ($rows as $r) {
            if ($r['cto_port_id'] !== null) {
                $current = $r;
                break;
            }
        }
        if ($current !== null && (int) $current['cto_port_id'] === (int) $port['id']) {
            return Response::json(['ok' => true, 'message' => 'A ONU já está nesta porta.', 'data' => ['onu_id' => $onuId]], 200, self::NO_STORE);
        }
        if ($current !== null && (string) $request->input('mover', '') !== '1') {
            return Response::json([
                'ok' => false, 'codigo' => 'mover',
                'message' => 'A ONU já está em ' . $current['cto_nome'] . ', ' . $current['porta_rotulo'] . '. Confirme para mover para ' . $cto['name'] . ', ' . $port['label'] . ($n > 1 ? ': os ' . $n . ' clientes dessa ONU vão juntos.' : '.'),
            ], 409, self::NO_STORE);
        }
        try {
            $res = $bindings->bindCto($providerId, (string) $rows[0]['mkauth_login'], null, (int) $cto['id'], (int) $port['id'], $actor);
        } catch (BindingConflict $e) {
            return $this->fail($e->getMessage(), 409);
        } catch (\Throwable) {
            return $this->fail('Não foi possível salvar o vínculo.', 500);
        }
        $this->audit($userId, $actor, 'net.binding.onu.cto', (int) $rows[0]['id'], [
            'onu_id' => $onuId, 'clientes' => $n, 'linhas_movidas' => $res['movidos'], 'cto' => $cto['name'], 'cto_id' => (int) $cto['id'], 'porta' => $port['label'], 'porta_id' => (int) $port['id'],
            'antes' => $current === null ? null : ['cto_id' => (int) $current['cto_node_id'], 'porta_id' => (int) $current['cto_port_id']],
        ]);

        return Response::json(['ok' => true, 'message' => ($n > 1 ? 'ONU com ' . $n . ' clientes ligada a ' : 'ONU ligada a ') . $cto['name'] . ', ' . $port['label'] . '.', 'data' => ['onu_id' => $onuId, 'clientes' => $n]], 200, self::NO_STORE);
    }

    /** Define ou limpa o modo PPPoE de um vinculo ativo (modo vazio = nao verificado). */
    public function mode(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard instanceof Response) {
            return $guard;
        }
        [$providerId, $userId, $actor] = $guard;
        $bindings = new NetBindingRepository($this->database->pdo());
        $id = $this->idOrNull((string) $request->input('id', ''));
        $raw = trim((string) $request->input('modo', ''));
        if (!$bindings->available() || $id === null || ($raw !== '' && !in_array($raw, NetBindingRepository::MODOS, true))) {
            return $this->fail('Pedido inválido.', 422);
        }
        $modo = $raw === '' ? null : $raw;
        $before = $bindings->setMode($providerId, $id, $modo);
        if ($before === null) {
            return $this->fail('Vínculo não encontrado.', 404);
        }
        $this->audit($userId, $actor, 'net.binding.mode', (int) $before['id'], ['login' => $before['mkauth_login'], 'antes' => $before['modo_pppoe'], 'depois' => $modo]);

        return Response::json(['ok' => true, 'message' => 'Modo atualizado.', 'data' => ['id' => (int) $before['id'], 'modo_pppoe' => $modo]], 200, self::NO_STORE);
    }

    /** ONUs que ja tem cliente, com a lista de clientes e o modo de cada um (para adicionar mais um / ajustar o modo). */
    public function boundOnus(Request $request): Response
    {
        $ctx = $this->guard(true, $denied);
        if ($ctx === null) {
            return $denied;
        }
        [$providerId] = $ctx;
        $bindings = new NetBindingRepository($this->database->pdo());
        if (!$bindings->available()) {
            return $this->fail('A tabela de vínculos ainda não foi criada neste ambiente (migration 045).', 409);
        }
        $st = $this->database->pdo()->prepare(
            "SELECT DISTINCT o.id FROM net_onus o JOIN client_network_bindings b ON b.onu_id = o.id AND b.provider_id = o.provider_id AND b.ativo = 1
              WHERE o.provider_id = ? AND o.removed_at IS NULL ORDER BY o.pon_index, o.onu_index, o.id"
        );
        $st->execute([$providerId]);
        $ids = array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
        $out = [];
        foreach ($bindings->forOnus($providerId, $ids) as $onuId => $rows) {
            $out[] = [
                'onu' => ['id' => $onuId, 'pon' => (int) $rows[0]['pon_index'], 'onu_index' => (int) $rows[0]['onu_index']],
                'cto' => $rows[0]['cto_node_id'] === null ? null : ['id' => (int) $rows[0]['cto_node_id'], 'nome' => (string) $rows[0]['cto_nome'], 'porta' => $rows[0]['porta_rotulo']],
                'clientes' => array_map(fn (array $b): array => $this->bindingView($b), $rows),
            ];
        }

        return Response::json(['ok' => true, 'data' => ['onus' => $out, 'modos' => NetBindingRepository::MODOS]], 200, self::NO_STORE);
    }

    // ---------------------------------------------------------------- apoio

    /** @param array<string,mixed> $b @return array<string,mixed> */
    private function bindingView(array $b): array
    {
        return [
            'id' => (int) $b['id'], 'login' => (string) $b['mkauth_login'], 'nome' => $b['cliente_nome'] === null ? null : (string) $b['cliente_nome'],
            'origem' => (string) $b['origem'], 'confianca' => $b['confianca'], 'modo_pppoe' => $b['modo_pppoe'] ?? null, 'confirmado_por' => $b['confirmado_por'], 'confirmado_em' => $b['confirmado_em'],
            'cto' => $b['cto_node_id'] === null ? null : ['id' => (int) $b['cto_node_id'], 'nome' => (string) $b['cto_nome'], 'porta' => $b['porta_rotulo']],
            'onu' => $b['onu_id'] === null ? null : ['id' => (int) $b['onu_id'], 'pon' => (int) $b['pon_index'], 'onu_index' => (int) $b['onu_index']],
        ];
    }

    private function idOrNull(string $raw): ?int
    {
        return preg_match('/^[1-9][0-9]{0,14}$/', $raw) === 1 ? (int) $raw : null;
    }

    /** @param array<string,mixed> $access */
    private function canRead(array $access): bool
    {
        return AccessControl::isGestor($access) || AccessControl::can($access, 'clients.view');
    }

    /** @return array<string,mixed> */
    private function viewUser(): array
    {
        $user = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
        $user['login'] = $this->localRepository->normalizeLogin((string) ($user['login'] ?? ''));
        $user['access'] = $this->localRepository->accessProfileForUser($user);

        return $user;
    }

    /** @return array{0:int,1:bool}|null */
    private function guard(bool $gestorOnly, ?Response &$denied): ?array
    {
        $user = $this->viewUser();
        $isGestor = AccessControl::isGestor($user['access']);
        if (!$isGestor && ($gestorOnly || !$this->canRead($user['access']))) {
            $denied = $this->fail('Acesso negado.', 403);

            return null;
        }
        $providerId = $this->localRepository->currentProviderId();
        if ($providerId === null) {
            $denied = $this->fail('Provedor não configurado.', 500);

            return null;
        }

        return [$providerId, $isGestor];
    }

    /** @return array{0:int,1:?int,2:string}|Response */
    private function guardWrite(Request $request): array|Response
    {
        $ctx = $this->guard(true, $denied);
        if ($ctx === null) {
            return $denied;
        }
        if (!Csrf::verify($request, NetDeviceController::CSRF_SCOPE)) {
            return $this->fail('Sessão expirada. Recarregue a página.', 419);
        }
        $raw = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
        $userId = (int) ($raw['id'] ?? 0);
        try {
            $userId = $userId > 0 && $this->mapRepository->userExists($ctx[0], $userId) ? $userId : 0;
        } catch (\Throwable) {
            $userId = 0;
        }

        return [$ctx[0], $userId > 0 ? $userId : null, (string) ($raw['login'] ?? '')];
    }

    /** @param array<string,mixed> $context */
    private function audit(?int $userId, string $actor, string $action, int $entityId, array $context): void
    {
        $this->localRepository->log($userId, $actor, $action, 'client_network_binding', $entityId, $context, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255));
    }

    private function fail(string $message, int $status): Response
    {
        return Response::json(['ok' => false, 'message' => $message], $status, self::NO_STORE);
    }
}
