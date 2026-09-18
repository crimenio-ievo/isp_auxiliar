<?php

declare(strict_types=1);

namespace App\Infrastructure\MkAuth;

interface ClientGateway
{
    public function createClient(array $payload): array;

    public function updateClient(array $payload): array;

    public function showClient(string $loginOrUuid): array;

    public function listClients(array $filters = []): array;
}
