<?php
declare(strict_types=1);
namespace App\Infrastructure\Net;

/**
 * Modo `net_tick.php --all`: percorre os equipamentos ativos, um de cada vez. O lock por equipamento continua dentro
 * do NetTick (um ciclo em andamento vira "locked" e e pulado); falha de um equipamento nunca interrompe os outros.
 */
final class NetTickAll
{
    /** Resultados que NAO contam como falha do ciclo (mesma regra do exit code do --device). */
    private const OK_RESULTS = ['ok', 'idle', 'dry_run', 'backoff', 'locked'];

    private $ids;
    private $runOne;

    /**
     * @param callable():list<int> $enabledDeviceIds ids de net_devices com enabled = 1, em ordem estavel
     * @param callable(int):array<string,mixed> $runOne executa um equipamento e devolve o resumo do NetTick
     */
    public function __construct(callable $enabledDeviceIds, callable $runOne)
    {
        $this->ids = $enabledDeviceIds;
        $this->runOne = $runOne;
    }

    /** @return array{result:string,mode:string,devices:list<array<string,mixed>>,counts:array<string,int>} */
    public function run(): array
    {
        $devices = [];
        $counts = [];
        foreach (array_values(array_unique(array_map('intval', ($this->ids)()))) as $id) {
            if ($id < 1) {
                continue;
            }
            try {
                $summary = ($this->runOne)($id);
                $result = (string) ($summary['result'] ?? 'error');
            } catch (\Throwable) {
                // Mensagens de excecao podem trazer detalhes de ambiente/credencial: nunca repassar.
                $summary = ['device_id' => $id, 'result' => 'error'];
                $result = 'error';
            }
            $counts[$result] = ($counts[$result] ?? 0) + 1;
            $devices[] = ['device_id' => $id, 'result' => $result];
        }
        $failed = count(array_filter($devices, static fn (array $d): bool => !in_array($d['result'], self::OK_RESULTS, true)));

        return ['result' => $failed === 0 ? 'ok' : 'partial', 'mode' => 'all', 'devices' => $devices, 'counts' => $counts];
    }
}
