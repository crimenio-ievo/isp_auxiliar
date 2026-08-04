<?php

declare(strict_types=1);

namespace App\Infrastructure\MkAuth;

interface ClientPlanReadRepository
{
    public function listPlans(int $limit = 300): array;

    public function findClientProfile(string $loginOrCpfCnpj): ?array;
}
