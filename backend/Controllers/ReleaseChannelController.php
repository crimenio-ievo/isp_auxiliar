<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Services\Releases\ReleaseChannelService;

final class ReleaseChannelController
{
    public function __construct(private ReleaseChannelService $releaseChannelService)
    {
    }

    public function save(Request $request): Response
    {
        $user = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
        if ($user === []) {
            return Response::redirect('/login');
        }
        if (!Csrf::verify($request, 'release_channel')) {
            Flash::set('error', 'A sessão do seletor de versão expirou.');
            return Response::redirect('/dashboard');
        }

        try {
            $result = $this->releaseChannelService->resolveSwitch(
                $user,
                (string) $request->input('release_channel', '')
            );
            if (!empty($result['redirect']) && (string) ($result['destination'] ?? '') !== '') {
                return Response::redirect((string) $result['destination']);
            }
            Flash::set('info', (string) ($result['message'] ?? 'Você já está neste ambiente.'));
        } catch (\Throwable $exception) {
            Flash::set('error', $exception->getMessage());
        }

        return Response::redirect('/dashboard');
    }
}
