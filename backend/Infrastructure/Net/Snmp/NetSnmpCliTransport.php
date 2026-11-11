<?php

declare(strict_types=1);

namespace App\Infrastructure\Net\Snmp;

use RuntimeException;

/**
 * Transporte SNMP via binários net-snmp (snmpget/snmpgetnext), somente leitura.
 *
 * - A credencial NUNCA vai para a linha de comando: é gravada num snmp.conf temporário (modo 600, diretório 700)
 *   apontado por SNMPCONFPATH, e apagado (shred) em close()/destrutor.
 * - Uma OID por requisição, sem retry no net-snmp (-r 0), intervalo mínimo entre requisições (pacing).
 * - Só existem get() e getNext(): não há SET nem GETBULK.
 */
final class NetSnmpCliTransport implements SnmpTransport
{
    private ?string $dir = null;
    private float $lastRequestAt = 0.0;

    public function __construct(
        private string $host,
        string $community,
        private int $intervalMs = 500,
        private int $timeoutMs = 3000
    ) {
        if (!preg_match('/^[A-Za-z0-9.\-:]+$/', $host)) {
            throw new RuntimeException('Host de gerência inválido.');
        }
        if ($community === '' || preg_match('/[\r\n\s]/', $community)) {
            throw new RuntimeException('Credencial SNMP vazia ou com caracteres inválidos.');
        }
        $this->intervalMs = max(300, $intervalMs);
        $this->timeoutMs = max(500, min(10000, $timeoutMs));
        $this->prepareConfig($community);
    }

    public function __destruct()
    {
        $this->close();
    }

    public function get(string $oid): SnmpValue
    {
        return $this->request('snmpget', $oid);
    }

    public function getNext(string $oid): SnmpValue
    {
        return $this->request('snmpgetnext', $oid);
    }

    public function close(): void
    {
        if ($this->dir === null) {
            return;
        }
        $dir = $this->dir;
        $this->dir = null;
        $conf = $dir . '/snmp.conf';
        if (is_file($conf)) {
            $shred = is_executable('/usr/bin/shred') ? '/usr/bin/shred' : null;
            if ($shred !== null) {
                $proc = proc_open([$shred, '-u', '-z', $conf], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
                if (is_resource($proc)) {
                    proc_close($proc);
                }
            }
            if (is_file($conf)) {
                @file_put_contents($conf, str_repeat("\0", 256));
                @unlink($conf);
            }
        }
        self::removeTree($dir);
    }

    private function prepareConfig(string $community): void
    {
        $base = rtrim(sys_get_temp_dir(), '/') . '/isp-net-snmp-' . bin2hex(random_bytes(8));
        $old = umask(0077);
        try {
            if (!mkdir($base, 0700) || !mkdir($base . '/persist', 0700)) {
                throw new RuntimeException('Não foi possível criar o diretório temporário do SNMP.');
            }
            $this->dir = $base;
            $conf = $base . '/snmp.conf';
            touch($conf);
            chmod($conf, 0600);
            $content = "defVersion 2c\ndefCommunity {$community}\nmibs :\n";
            if (file_put_contents($conf, $content) === false) {
                throw new RuntimeException('Não foi possível gravar a configuração temporária do SNMP.');
            }
        } finally {
            umask($old);
        }
    }

    private function request(string $binary, string $oid): SnmpValue
    {
        if ($this->dir === null) {
            throw new RuntimeException('Transporte SNMP já encerrado.');
        }
        $oid = ltrim(trim($oid), '.');
        if (!preg_match('/^[0-9]+(\.[0-9]+)*$/', $oid)) {
            throw new RuntimeException('OID inválida: somente formato numérico.');
        }

        $elapsedMs = (microtime(true) - $this->lastRequestAt) * 1000;
        if ($this->lastRequestAt > 0 && $elapsedMs < $this->intervalMs) {
            usleep((int) (($this->intervalMs - $elapsedMs) * 1000));
        }

        $timeoutS = rtrim(rtrim(number_format($this->timeoutMs / 1000, 3, '.', ''), '0'), '.');
        $cmd = ['/usr/bin/' . $binary, '-t', $timeoutS, '-r', '0', '-On', '-Oe', '-Ot', $this->host, $oid];
        $env = [
            'SNMPCONFPATH' => $this->dir,
            'SNMP_PERSISTENT_DIR' => $this->dir . '/persist',
            'PATH' => '/usr/bin:/bin',
            'HOME' => $this->dir,
        ];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($proc)) {
            throw new RuntimeException('Falha ao executar o cliente SNMP.');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);
        $this->lastRequestAt = microtime(true);

        return self::parseOutput($stdout, $stderr, $rc, $oid);
    }

    /**
     * Interpreta a saída do net-snmp com -On -Oe -Ot. Público para teste.
     */
    public static function parseOutput(string $stdout, string $stderr, int $rc, string $requestedOid): SnmpValue
    {
        $err = strtolower($stderr . ' ' . ($rc !== 0 ? $stdout : ''));
        if (str_contains($err, 'timeout')) {
            throw new SnmpTimeoutException('Sem resposta SNMP (timeout).');
        }
        if (preg_match('/unreachable|prohibited|refused/', $err)) {
            throw new SnmpUnreachableException('Rede recusou a requisição SNMP (unreachable/prohibited/refused).');
        }
        if ($rc !== 0 && trim($stdout) === '') {
            throw new RuntimeException('Erro SNMP: ' . substr(trim(preg_replace('/\s+/', ' ', $stderr) ?? ''), 0, 200));
        }

        $text = trim($stdout);
        if (!preg_match('/^\.?([0-9.]+)\s*=\s*(.*)$/s', $text, $m)) {
            throw new RuntimeException('Resposta SNMP não reconhecida.');
        }
        $oid = ltrim($m[1], '.');
        $rest = trim($m[2]);

        if (stripos($rest, 'No more variables left') === 0) {
            return new SnmpValue($oid, 'END', '');
        }
        if (stripos($rest, 'No Such Object') === 0 || stripos($rest, 'No Such Instance') === 0) {
            return new SnmpValue($oid, 'NOSUCH', '');
        }
        if ($rest === '""') {
            return new SnmpValue($oid, 'STRING', '');
        }
        if (!preg_match('/^([A-Za-z0-9\-]+):\s*(.*)$/s', $rest, $t)) {
            return new SnmpValue($oid, 'UNKNOWN', $rest);
        }
        $type = $t[1];
        $value = trim($t[2]);
        if ($type === 'STRING' && strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            $value = stripcslashes(substr($value, 1, -1));
        } elseif ($type === 'Hex-STRING') {
            $value = strtoupper(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
        } elseif ($type === 'Timeticks' && preg_match('/\(?([0-9]+)\)?/', $value, $tt)) {
            $value = $tt[1];
        } elseif ($type === 'OID') {
            $value = ltrim($value, '.');
        }

        return new SnmpValue($oid, $type, $value);
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) && !is_link($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
