<?php

declare(strict_types=1);

namespace App\Infrastructure\MkAuth;

final class ClientPlanNotConfirmedException extends \RuntimeException
{
    public function __construct(string $message, private array $provisionResult)
    {
        parent::__construct($message);
    }

    public function provisionResult(): array
    {
        return $this->provisionResult;
    }
}
