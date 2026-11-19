<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

use App\Core\Env;
use App\Infrastructure\Net\Snmp\NetSnmpCliTransport;
use App\Infrastructure\Net\Snmp\SnmpTimeoutException;
use App\Infrastructure\Net\Snmp\SnmpTransport;
use App\Infrastructure\Net\Snmp\SnmpUnreachableException;

/**
 * "Testar conexao": no maximo TRES SNMP GET de leitura (sysName, sysDescr, sysUpTime), timeout curto, sem retry.
 * Se o primeiro nao responde, para (nao insiste). Nunca devolve nem registra a credencial.
 * Usa o mesmo lock por equipamento da coleta, para nunca competir com um ciclo em andamento na OLT.
 */
final class NetConnectionTester
{
    public const OIDS = [
        'sysName' => '1.3.6.1.2.1.1.5.0',
        'sysDescr' => '1.3.6.1.2.1.1.1.0',
        'sysUpTime' => '1.3.6.1.2.1.1.3.0',
    ];
    public const TIMEOUT_MS = 3000;
    public const INTERVAL_MS = 500;

    /** @var callable(string,string):SnmpTransport */
    private $factory;

    /**
     * @param (callable(string,string):SnmpTransport)|null $transportFactory recebe (host, credencial) e devolve o transporte (so para teste)
     */
    public function __construct(private NetDeviceLock $lock, ?callable $transportFactory = null)
    {
        $this->factory = $transportFactory ?? static fn (string $host, string $secret): SnmpTransport => new NetSnmpCliTransport($host, $secret, self::INTERVAL_MS, self::TIMEOUT_MS);
    }

    /**
     * @param array<string,mixed> $device linha de net_devices (mgmt_host, credential_ref, id)
     * @return array{ok:bool,codigo:string,mensagem:string,leituras:array<string,string>,duracao_ms:int}
     */
    public function test(array $device): array
    {
        $t0 = microtime(true);
        $fail = static fn (string $code, string $msg): array => ['ok' => false, 'codigo' => $code, 'mensagem' => $msg, 'leituras' => [], 'duracao_ms' => (int) round((microtime(true) - $t0) * 1000)];

        $ref = (string) ($device['credential_ref'] ?? '');
        if (!NetTransportFactory::isValidCredentialRef($ref)) {
            return $fail('credencial_invalida', 'Referência de credencial inválida.');
        }
        $secret = (string) (Env::get($ref, '') ?? '');
        if ($secret === '') {
            return $fail('credencial_ausente', 'A credencial ' . $ref . ' não está definida no .env deste servidor.');
        }
        $host = (string) ($device['mgmt_host'] ?? '');
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return $fail('host_invalido', 'IP de gerência inválido.');
        }

        $deviceId = (int) ($device['id'] ?? 0);
        if ($deviceId < 1 || !$this->lock->acquire($deviceId)) {
            return $fail('coleta_em_andamento', 'Há uma coleta deste equipamento em andamento. Tente de novo em instantes.');
        }
        $transport = null;
        try {
            $transport = ($this->factory)($host, $secret);
            $reads = [];
            foreach (self::OIDS as $name => $oid) {
                $value = $transport->get($oid);
                if (in_array($value->type, ['NOSUCH', 'END'], true)) {
                    $reads[$name] = '(não informado pelo equipamento)';
                    continue;
                }
                $reads[$name] = $name === 'sysUpTime' ? self::uptimeText($value->value) : self::clean($value->value);
            }

            return ['ok' => true, 'codigo' => 'ok', 'mensagem' => 'O equipamento respondeu.', 'leituras' => $reads, 'duracao_ms' => (int) round((microtime(true) - $t0) * 1000)];
        } catch (SnmpTimeoutException) {
            return $fail('sem_resposta', 'O equipamento não respondeu a tempo (' . (self::TIMEOUT_MS / 1000) . ' s). Confira o IP, a rede e a credencial.');
        } catch (SnmpUnreachableException) {
            return $fail('inalcancavel', 'A rede recusou ou não alcançou o IP informado.');
        } catch (\Throwable) {
            // Mensagens do transporte podem conter detalhes do ambiente: nao repassar.
            return $fail('erro', 'Não foi possível concluir o teste.');
        } finally {
            if ($transport !== null) {
                $transport->close();
            }
            $this->lock->release();
        }
    }

    private static function clean(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';

        return mb_substr(trim($value), 0, 200);
    }

    private static function uptimeText(string $ticks): string
    {
        if (!ctype_digit($ticks)) {
            return self::clean($ticks);
        }
        $s = intdiv((int) $ticks, 100);

        return sprintf('%dd %02dh %02dmin', intdiv($s, 86400), intdiv($s % 86400, 3600), intdiv($s % 3600, 60));
    }
}
