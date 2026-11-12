<?php

declare(strict_types=1);

namespace App\Infrastructure\Net\Store;

final class InMemoryNetOutageStore implements NetOutageStore
{
    /** @var array<string,array<string,mixed>> "device|source|key" => linha */
    public array $rows = [];
    /** @var list<array<string,mixed>> */
    public array $audit = [];
    public bool $failAudit = false;

    public function existing(int $deviceId, string $source, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            if (isset($this->rows["{$deviceId}|{$source}|{$k}"])) {
                $out[$k] = $this->rows["{$deviceId}|{$source}|{$k}"];
            }
        }

        return $out;
    }

    public function save(array $inserts, array $updates, array $audit): void
    {
        $rows = $this->rows;
        foreach ($inserts as $r) {
            $id = "{$r['device_id']}|{$r['source']}|{$r['event_key']}";
            if (isset($rows[$id])) {
                throw new \RuntimeException('Chave duplicada.');
            }
            $rows[$id] = $r;
        }
        foreach ($updates as $r) {
            $id = "{$r['device_id']}|{$r['source']}|{$r['event_key']}";
            if (!isset($rows[$id])) {
                throw new \RuntimeException('Queda inexistente.');
            }
            $rows[$id] = array_merge($rows[$id], $r);
        }
        if ($this->failAudit) {
            throw new \RuntimeException('Auditoria falhou.');
        }
        $this->rows = $rows;
        $this->audit[] = $audit;
    }

    public function outagesOverlapping(int $deviceId, string $from, string $to): array
    {
        $out = [];
        foreach ($this->rows as $r) {
            if ((int) $r['device_id'] === $deviceId && $r['started_at'] <= $to && ($r['ended_at'] === null || $r['ended_at'] >= $from)) {
                $out[] = $r;
            }
        }
        usort($out, static fn (array $a, array $b): int => [$a['started_at'], $a['onu_identifier']] <=> [$b['started_at'], $b['onu_identifier']]);

        return $out;
    }
}
