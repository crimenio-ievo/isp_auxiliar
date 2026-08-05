<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Core\Config;
use App\Infrastructure\Local\LocalRepository;

final class ReleaseChannelService
{
    public function __construct(private Config $config, private LocalRepository $localRepository)
    {
    }

    public function currentChannel(): string
    {
        return (string) $this->config->get('app.release.channel', 'stable') === 'beta' ? 'beta' : 'stable';
    }

    public function preference(array $user): string
    {
        return $this->currentChannel();
    }

    public function canUseBeta(array $user): bool
    {
        $access = $this->localRepository->accessProfileForUser($user);

        return !empty($access['can_use_beta']);
    }

    public function savePreference(array $user, string $channel): array
    {
        return $this->resolveSwitch($user, $channel);
    }

    public function resolveSwitch(array $user, string $channel): array
    {
        $channel = strtolower(trim($channel));
        if (!in_array($channel, ['stable', 'beta'], true)) {
            throw new \InvalidArgumentException('Canal de versão inválido.');
        }
        if ($channel === 'beta' && !$this->canUseBeta($user)) {
            throw new \RuntimeException('Seu usuário não possui permissão para acessar o canal Beta.');
        }
        $current = $this->currentChannel();
        if ($channel === $current) {
            return [
                'channel' => $channel,
                'current_channel' => $current,
                'destination' => '',
                'redirect' => false,
                'message' => 'Você já está no ambiente ' . ($current === 'beta' ? 'Beta' : 'Stable') . '.',
            ];
        }

        $destination = $this->destination($channel);
        if ($destination === '') {
            throw new \RuntimeException('O destino do canal selecionado não está configurado com uma URL segura.');
        }

        return [
            'channel' => $channel,
            'current_channel' => $current,
            'destination' => $destination,
            'redirect' => true,
            'message' => 'Abrindo o ambiente ' . ($channel === 'beta' ? 'Beta' : 'Stable') . '.',
        ];
    }

    public function destination(string $channel): string
    {
        $key = $channel === 'beta' ? 'beta_base_url' : 'stable_base_url';
        $url = trim((string) $this->config->get('app.release.' . $key, ''));
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return '';
        }

        return rtrim($url, '/');
    }
}
