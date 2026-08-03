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
        $login = $this->localRepository->normalizeLogin((string) ($user['login'] ?? ''));
        if ($login === '') {
            return 'stable';
        }
        $saved = $this->localRepository->providerSetting($this->preferenceKey($login), 'stable');

        return $saved === 'beta' && $this->canUseBeta($user) ? 'beta' : 'stable';
    }

    public function canUseBeta(array $user): bool
    {
        $access = $this->localRepository->accessProfileForUser($user);

        return !empty($access['can_use_beta']);
    }

    public function savePreference(array $user, string $channel): array
    {
        $channel = strtolower(trim($channel));
        if (!in_array($channel, ['stable', 'beta'], true)) {
            throw new \InvalidArgumentException('Canal de versão inválido.');
        }
        if ($channel === 'beta' && !$this->canUseBeta($user)) {
            throw new \RuntimeException('Seu usuário não possui permissão para acessar o canal Beta.');
        }
        $login = $this->localRepository->normalizeLogin((string) ($user['login'] ?? ''));
        if ($login === '') {
            throw new \RuntimeException('Usuário autenticado não identificado.');
        }

        $previous = $this->preference($user);
        $this->localRepository->saveProviderSettings([$this->preferenceKey($login) => $channel]);
        $this->localRepository->log(
            isset($user['id']) ? (int) $user['id'] : null,
            $login,
            'release.channel.preference_changed',
            'release_channel',
            null,
            ['previous' => $previous, 'selected' => $channel, 'destination_configured' => $this->destination($channel) !== '']
        );

        return ['channel' => $channel, 'destination' => $this->destination($channel)];
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

    private function preferenceKey(string $login): string
    {
        return 'release_channel_user_' . hash('sha256', $login);
    }
}
