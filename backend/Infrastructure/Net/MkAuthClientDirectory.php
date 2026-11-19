<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

use App\Infrastructure\MkAuth\MkAuthDatabase;

/** Adaptador SOMENTE LEITURA sobre MkAuthDatabase (so chama metodos de consulta; nenhum insert/update/delete). */
final class MkAuthClientDirectory implements ClientDirectory
{
    public function __construct(private MkAuthDatabase $mkauth)
    {
    }

    public function search(string $term, int $limit): array
    {
        return $this->mkauth->searchClients($term, $limit);
    }

    public function find(string $login): ?array
    {
        return $this->mkauth->findClientProfile($login);
    }

    public function recentCallerMacs(int $days): array
    {
        return $this->mkauth->recentCallerMacs($days);
    }

    public function namesByLogins(array $logins): array
    {
        return $this->mkauth->clientNamesByLogins($logins);
    }

    public function briefByLogins(array $logins): array
    {
        return $this->mkauth->clientBriefByLogins($logins);
    }

    public function latestVisibleDate(): array
    {
        return $this->mkauth->latestVisibleDate();
    }
}
