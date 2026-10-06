<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AccessControl;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Local\LocalRepository;

/**
 * ISP Map, fase 0 (apresentação): serve o protótipo estático de modules/isp_map/ (fora de public/),
 * só para usuário autenticado com perfil de gestor/admin. O HTML é entregue sem alterações.
 */
final class MapController
{
    public function __construct(private LocalRepository $localRepository)
    {
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

        return Response::html($html, 200, ['Cache-Control' => 'private, no-store']);
    }
}
