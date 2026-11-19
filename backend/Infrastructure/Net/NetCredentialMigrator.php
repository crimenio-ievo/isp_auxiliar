<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

use PDO;

/**
 * Migra uma credencial NET_CRED_* do .env para net_credentials e aponta os equipamentos que a usam (credential_id).
 * Simulação por padrão; só grava com $apply. Não toca em credential_ref (continua como alternativa) e nunca devolve o segredo.
 */
final class NetCredentialMigrator
{
    public function __construct(private PDO $pdo, private NetCredentialRepository $repo)
    {
    }

    /**
     * @param callable(string):string $envSecret devolve o valor da variável (só para gravar; não aparece no relatório)
     * @return array{ok:bool,aplicado:bool,mensagem:string,provedores:list<array<string,mixed>>}
     */
    public function run(string $ref, string $name, bool $apply, callable $envSecret): array
    {
        $report = ['ok' => false, 'aplicado' => false, 'mensagem' => '', 'provedores' => []];
        if (!NetTransportFactory::isValidCredentialRef($ref)) {
            $report['mensagem'] = 'Referência inválida (esperado NET_CRED_*).';

            return $report;
        }
        if (preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._-]{0,79}$/u', $name) !== 1) {
            $report['mensagem'] = 'Nome inválido (1 a 80 caracteres: letras, números, espaço, ponto, hífen, sublinhado).';

            return $report;
        }
        $secret = $envSecret($ref);
        if ($secret === '') {
            $report['mensagem'] = "A variável {$ref} não está definida no .env deste servidor.";

            return $report;
        }
        if ($apply && (!$this->repo->box()->available() || !$this->repo->available())) {
            $report['mensagem'] = 'Chave NET_SECRET_KEY ausente/ inválida ou migration 046 não aplicada: nada foi gravado.';

            return $report;
        }
        $st = $this->pdo->prepare('SELECT id, provider_id FROM net_devices WHERE credential_ref = ? AND credential_id IS NULL ORDER BY id');
        $st->execute([$ref]);
        $byProvider = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byProvider[(int) $row['provider_id']][] = (int) $row['id'];
        }
        if ($byProvider === []) {
            $report['ok'] = true;
            $report['mensagem'] = "Nenhum equipamento usa {$ref} sem credencial do banco: nada a migrar.";

            return $report;
        }
        foreach ($byProvider as $providerId => $deviceIds) {
            $exists = $this->repo->available() && $this->repo->nameExists($providerId, $name);
            $item = ['provedor' => $providerId, 'credencial' => $name, 'criar_credencial' => !$exists, 'equipamentos' => $deviceIds];
            if ($apply) {
                $this->pdo->beginTransaction();
                try {
                    $id = $exists ? $this->idByName($providerId, $name) : $this->repo->create($providerId, $name, 'snmp_v2c', null, $secret, 'script net_migrate_credentials');
                    $in = implode(',', array_fill(0, count($deviceIds), '?'));
                    $this->pdo->prepare("UPDATE net_devices SET credential_id = ?, updated_at = NOW() WHERE provider_id = ? AND id IN ({$in})")->execute(array_merge([$id, $providerId], $deviceIds));
                    $this->pdo->commit();
                    $item['credential_id'] = $id;
                } catch (\Throwable) {
                    $this->pdo->rollBack();
                    $report['mensagem'] = 'Falha ao gravar; nada foi alterado para o provedor ' . $providerId . '.';
                    $report['provedores'][] = $item;

                    return $report;
                }
            }
            $report['provedores'][] = $item;
        }
        $report['ok'] = true;
        $report['aplicado'] = $apply;
        $report['mensagem'] = $apply ? 'Credencial migrada. O .env continua como alternativa (credential_ref preservado).' : 'SIMULAÇÃO: nada foi gravado. Use --apply para aplicar.';

        return $report;
    }

    private function idByName(int $providerId, string $name): int
    {
        $st = $this->pdo->prepare('SELECT id FROM net_credentials WHERE provider_id = ? AND name = ?');
        $st->execute([$providerId, $name]);

        return (int) $st->fetchColumn();
    }
}
