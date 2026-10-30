<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AccessControl;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Url;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\Map\MapRepository;

/**
 * ISP Map: serve o HTML de modules/isp_map/ (fora de public/), só para usuário autenticado com perfil
 * de gestor/admin, injetando a configuração da página (token CSRF e URL da API), e expõe a leitura
 * da topologia em GET /api/mapa.
 */
final class MapController
{
    public function __construct(private LocalRepository $localRepository, private MapRepository $mapRepository)
    {
    }

    public function data(Request $request): Response
    {
        $user = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
        $access = $this->localRepository->accessProfileForUser($user);

        if (!AccessControl::isGestor($access)) {
            return Response::json(['ok' => false, 'message' => 'Acesso negado.'], 403, ['Cache-Control' => 'private, no-store']);
        }

        $providerId = $this->localRepository->currentProviderId();
        if ($providerId === null) {
            return Response::json(['ok' => false, 'message' => 'Provedor não configurado.'], 500, ['Cache-Control' => 'private, no-store']);
        }

        try {
            $data = $this->mapRepository->topology($providerId);
        } catch (\Throwable) {
            return Response::json(['ok' => false, 'message' => 'Não foi possível ler o mapa.'], 500, ['Cache-Control' => 'private, no-store']);
        }

        return Response::json(['ok' => true, 'data' => $data], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function show(Request $request): Response
    {
        $user = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
        $access = $this->localRepository->accessProfileForUser($user);

        if (!AccessControl::isGestor($access)) {
            Flash::set('error', 'Seu usuário não possui acesso ao mapa.');

            return Response::redirect('/dashboard');
        }

        $file = dirname(__DIR__, 2) . '/modules/isp_map/isp-network-map.html';
        $html = is_file($file) ? file_get_contents($file) : false;

        if (!is_string($html)) {
            return Response::html('Mapa indisponível.', 404);
        }

        $config = json_encode(
            ['csrf' => Csrf::token('map'), 'api' => Url::to('/api/mapa')],
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        $html = preg_replace('#</head>#i', '<script>window.ISP_MAP=' . $config . ';</script></head>', $html, 1) ?? $html;

        return Response::html($html, 200, ['Cache-Control' => 'private, no-store']);
    }
}
