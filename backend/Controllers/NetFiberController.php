<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AccessControl;
use App\Core\Config;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;
use App\Core\Csrf;
use App\Infrastructure\Net\NetFiberReader;

/**
 * Tela "Rede de fibra" (somente leitura): página + endpoints JSON. Só gestor/admin (a busca do topo também atende o
 * técnico com clients.view, em modo só leitura). Lê apenas o banco de
 * telemetria; não acessa a OLT nem o MkAuth e não grava nada (sem CSRF/auditoria porque não há escrita).
 */
final class NetFiberController
{
    private const NO_STORE = ['Cache-Control' => 'private, no-store'];

    public function __construct(
        private View $view,
        private Config $config,
        private LocalRepository $localRepository,
        private Database $database
    ) {
    }

    public function page(Request $request): Response
    {
        $user = $this->viewUser();
        if (!AccessControl::isGestor($user['access'])) {
            Flash::set('error', 'Seu usuário não possui acesso à rede de fibra.');

            return Response::redirect('/dashboard');
        }

        $html = $this->view->render('net/fiber', [
            'pageTitle' => 'Rede de fibra',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $user,
            'csrf' => Csrf::token(NetDeviceController::CSRF_SCOPE),
        ]);

        return Response::html($html, 200, self::NO_STORE);
    }

    public function tree(Request $request): Response
    {
        $providerId = $this->guard($denied);
        if ($providerId === null) {
            return $denied;
        }
        $device = $request->query('dispositivo');
        try {
            $data = (new NetFiberReader($this->database->pdo()))->tree($providerId, is_string($device) && ctype_digit($device) && $device !== '0' ? (int) $device : null);
        } catch (\Throwable) {
            return Response::json(['ok' => false, 'message' => 'Não foi possível ler a rede de fibra.'], 500, self::NO_STORE);
        }

        return Response::json(['ok' => true, 'data' => $data], 200, self::NO_STORE);
    }

    public function search(Request $request): Response
    {
        // Técnico (clients.view) também busca, só leitura: o leitor devolve apenas destinos que ele pode abrir sem editar.
        $user=$this->viewUser();
        $isGestor=AccessControl::isGestor($user['access']);
        if (!$isGestor && !AccessControl::can($user['access'],'clients.view')) { return Response::json(['ok'=>false,'message'=>'Acesso negado.'],403,self::NO_STORE); }
        $providerId=$this->localRepository->currentProviderId();
        if ($providerId===null) { return Response::json(['ok'=>false,'message'=>'Provedor não configurado.'],500,self::NO_STORE); }
        $q=$request->query('q','');
        if (!is_string($q)) { return Response::json(['ok'=>false,'message'=>'Busca inválida.'],422,self::NO_STORE); }
        $results=(new \App\Infrastructure\Net\NetSearchReader($this->database->pdo()))->search($providerId,$q,!$isGestor);
        foreach($results as &$result) { $result['url']=\App\Core\Url::to($result['url']); }
        return Response::json(['ok'=>true,'data'=>$results],200,self::NO_STORE);
    }

    public function onu(Request $request): Response
    {
        $providerId = $this->guard($denied);
        if ($providerId === null) {
            return $denied;
        }
        $id = $request->query('id');
        if (!is_string($id) || !ctype_digit($id) || $id === '0' || strlen($id) > 15) {
            return Response::json(['ok' => false, 'message' => 'ONU inválida.'], 422, self::NO_STORE);
        }
        $period = $this->periodParams($request, $bad);
        if ($period === null) {
            return $bad;
        }
        try {
            $data = (new NetFiberReader($this->database->pdo()))->sheet($providerId, (int) $id, $period[0], $period[1], $period[2]);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'message' => 'Período personalizado inválido (início antes do fim, até 366 dias).'], 422, self::NO_STORE);
        } catch (\Throwable) {
            return Response::json(['ok' => false, 'message' => 'Não foi possível ler a ficha da ONU.'], 500, self::NO_STORE);
        }
        if ($data === null) {
            return Response::json(['ok' => false, 'message' => 'ONU não encontrada.'], 404, self::NO_STORE);
        }

        return Response::json(['ok' => true, 'data' => $data], 200, self::NO_STORE);
    }

    /** GET /api/rede-fibra/eventos?periodo=1h|24h|7d|custom[&de=&ate=][&dispositivo=ID]: quedas correlacionadas por PON. */
    public function events(Request $request): Response
    {
        $providerId = $this->guard($denied);
        if ($providerId === null) {
            return $denied;
        }
        $period = $this->periodParams($request, $bad);
        if ($period === null) {
            return $bad;
        }
        $device = $request->query('dispositivo');
        try {
            $data = (new NetFiberReader($this->database->pdo()))->events($providerId, $period[0], $period[1], $period[2], is_string($device) && ctype_digit($device) && $device !== '0' ? (int) $device : null);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'message' => 'Período personalizado inválido (início antes do fim, até 366 dias).'], 422, self::NO_STORE);
        } catch (\Throwable) {
            return Response::json(['ok' => false, 'message' => 'Não foi possível ler os eventos.'], 500, self::NO_STORE);
        }

        return Response::json(['ok' => true, 'data' => $data], 200, self::NO_STORE);
    }

    /**
     * Validação comum de período (usada por /onu e /eventos).
     *
     * @param Response|null $bad
     * @return array{0:string,1:?string,2:?string}|null
     */
    private function periodParams(Request $request, ?Response &$bad): ?array
    {
        $kind = (string) $request->query('periodo', '24h');
        if (!in_array($kind, ['1h', '24h', '7d', 'custom'], true)) {
            $bad = Response::json(['ok' => false, 'message' => 'Período inválido.'], 422, self::NO_STORE);

            return null;
        }
        $from = $this->dateParam($request->query('de'));
        $to = $this->dateParam($request->query('ate'));
        if ($kind === 'custom' && $from === null) {
            $bad = Response::json(['ok' => false, 'message' => 'Informe o início do período personalizado.'], 422, self::NO_STORE);

            return null;
        }

        return [$kind, $from, $to];
    }

    /** Aceita "AAAA-MM-DD hh:mm:ss" ou "AAAA-MM-DDThh:mm" (campo datetime-local). */
    private function dateParam(mixed $v): ?string
    {
        if (!is_string($v) || $v === '') {
            return null;
        }
        $v = str_replace('T', ' ', trim($v));
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $v) === 1) {
            $v .= ':00';
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $v);

        return $d !== false && $d->format('Y-m-d H:i:s') === $v ? $v : null;
    }

    /** @param Response|null $denied */
    private function guard(?Response &$denied): ?int
    {
        $user = $this->viewUser();
        if (!AccessControl::isGestor($user['access'])) {
            $denied = Response::json(['ok' => false, 'message' => 'Acesso negado.'], 403, self::NO_STORE);

            return null;
        }
        $providerId = $this->localRepository->currentProviderId();
        if ($providerId === null) {
            $denied = Response::json(['ok' => false, 'message' => 'Provedor não configurado.'], 500, self::NO_STORE);

            return null;
        }

        return $providerId;
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
