<?php
declare(strict_types=1);
namespace App\Infrastructure\Net;

/** Mesmo arquivo/flock usado por net_collect.php e net_alerts.php. Nunca remover arquivo de lock ativo. */
final class NetDeviceLock
{
    private $handle = null;
    public function __construct(private string $directory) {}
    public function acquire(int $deviceId): bool
    {
        if ($this->handle !== null) { throw new \LogicException('Lock ja adquirido.'); }
        $handle = @fopen($this->directory . "/net_collect_device_{$deviceId}.lock", 'c');
        if (!$handle) { throw new \RuntimeException('Diretorio de locks indisponivel.'); }
        if (!flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); return false; }
        $this->handle = $handle;
        return true;
    }
    public function release(): void
    {
        if ($this->handle !== null) { flock($this->handle, LOCK_UN); fclose($this->handle); $this->handle=null; }
    }
    public function __destruct() { $this->release(); }
}
