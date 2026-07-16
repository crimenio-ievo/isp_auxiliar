<?php

declare(strict_types=1);

namespace App\Infrastructure\MkAuth;

use RuntimeException;

/**
 * Trava central para qualquer escrita no MkAuth.
 *
 * A liberacao nunca e inferida pelo ambiente: inclusive em producao, a flag
 * MKAUTH_WRITE_ENABLED precisa estar explicitamente habilitada.
 */
final class MkAuthWriteGuard
{
    public const BLOCKED_MESSAGE = 'Operação de escrita no MkAuth bloqueada pelo ambiente.';

    public function __construct(
        private string $environment,
        private bool $writeEnabled,
        private string $logPath = ''
    ) {
    }

    public function isWriteEnabled(): bool
    {
        return $this->writeEnabled;
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function assertAllowed(string $operation): void
    {
        if ($this->writeEnabled) {
            return;
        }

        $this->recordBlockedOperation($operation);

        throw new RuntimeException(self::BLOCKED_MESSAGE);
    }

    private function recordBlockedOperation(string $operation): void
    {
        $environment = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', trim($this->environment)) ?: 'unknown';
        $operation = preg_replace('/[^a-zA-Z0-9_ .:\/-]+/', '_', trim($operation)) ?: 'unknown';
        $line = sprintf(
            "[%s] %s environment=%s operation=%s\n",
            date(DATE_ATOM),
            self::BLOCKED_MESSAGE,
            $environment,
            $operation
        );

        if ($this->logPath !== '') {
            $directory = dirname($this->logPath);
            if (is_dir($directory) && is_writable($directory) && error_log($line, 3, $this->logPath)) {
                return;
            }
        }

        error_log(rtrim($line));
    }
}
