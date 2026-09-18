<?php

declare(strict_types=1);

namespace App\Services\Clients;

final class ClientCompletionValidationException extends \InvalidArgumentException
{
    public function __construct(private array $errors)
    {
        parent::__construct(implode(' ', array_values($errors)));
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
