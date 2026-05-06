<?php

declare(strict_types=1);

namespace App\Infrastructure\Contracts;

use App\Infrastructure\Database\Database;

/**
 * Repositorio base para contratos do ISP Auxiliar.
 *
 * Nesta fase ele apenas concentra as operacoes de leitura e escrita dos
 * contratos, sem acoplar o fluxo atual de cadastro.
 */
final class ContractRepository
{
    public function __construct(private Database $database)
    {
    }

    public function create(array $data): ?int
    {
        $this->database->execute(
            'INSERT INTO client_contracts
                (client_id, mkauth_login, nome_cliente, telefone_cliente, tipo_adesao, valor_adesao, parcelas_adesao, valor_parcela_adesao, vencimento_primeira_parcela, fidelidade_meses, beneficio_valor, multa_total, tipo_aceite, observacao_adesao, status_financeiro, created_at, updated_at)
             VALUES
                (:client_id, :mkauth_login, :nome_cliente, :telefone_cliente, :tipo_adesao, :valor_adesao, :parcelas_adesao, :valor_parcela_adesao, :vencimento_primeira_parcela, :fidelidade_meses, :beneficio_valor, :multa_total, :tipo_aceite, :observacao_adesao, :status_financeiro, NOW(), NOW())',
            $this->normalizeData($data)
        );

        return $this->database->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM client_contracts WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    public function findByClientId(int $clientId): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM client_contracts WHERE client_id = :client_id ORDER BY updated_at DESC, id DESC LIMIT 1',
            ['client_id' => $clientId]
        );
    }

    public function findByLogin(string $login): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM client_contracts WHERE LOWER(mkauth_login) = LOWER(:login) ORDER BY updated_at DESC, id DESC LIMIT 1',
            ['login' => trim($login)]
        );
    }

    public function updateById(int $id, array $data): int
    {
        return $this->database->execute(
            'UPDATE client_contracts
             SET client_id = :client_id,
                 mkauth_login = :mkauth_login,
                 nome_cliente = :nome_cliente,
                 telefone_cliente = :telefone_cliente,
                 tipo_adesao = :tipo_adesao,
                 valor_adesao = :valor_adesao,
                 parcelas_adesao = :parcelas_adesao,
                 valor_parcela_adesao = :valor_parcela_adesao,
                 vencimento_primeira_parcela = :vencimento_primeira_parcela,
                 fidelidade_meses = :fidelidade_meses,
                 beneficio_valor = :beneficio_valor,
                 multa_total = :multa_total,
                 tipo_aceite = :tipo_aceite,
                 observacao_adesao = :observacao_adesao,
                 status_financeiro = :status_financeiro,
                 updated_at = NOW()
             WHERE id = :id',
            array_merge(['id' => $id], $this->normalizeData($data))
        );
    }

    public function listByStatus(string $status, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));

        return $this->database->fetchAll(
            'SELECT * FROM client_contracts WHERE status_financeiro = :status ORDER BY updated_at DESC, id DESC LIMIT ' . (int) $limit,
            ['status' => $status]
        );
    }

    public function updateStatus(int $id, string $status): int
    {
        return $this->database->execute(
            'UPDATE client_contracts SET status_financeiro = :status, updated_at = NOW() WHERE id = :id',
            [
                'id' => $id,
                'status' => $status,
            ]
        );
    }

    private function normalizeData(array $data): array
    {
        return [
            'client_id' => $data['client_id'] ?? null,
            'mkauth_login' => (string) ($data['mkauth_login'] ?? ''),
            'nome_cliente' => (string) ($data['nome_cliente'] ?? ''),
            'telefone_cliente' => (string) ($data['telefone_cliente'] ?? ''),
            'tipo_adesao' => (string) ($data['tipo_adesao'] ?? 'cheia'),
            'valor_adesao' => (string) ($data['valor_adesao'] ?? '0.00'),
            'parcelas_adesao' => (string) ($data['parcelas_adesao'] ?? '1'),
            'valor_parcela_adesao' => (string) ($data['valor_parcela_adesao'] ?? '0.00'),
            'vencimento_primeira_parcela' => $data['vencimento_primeira_parcela'] ?? null,
            'fidelidade_meses' => (string) ($data['fidelidade_meses'] ?? '12'),
            'beneficio_valor' => (string) ($data['beneficio_valor'] ?? '0.00'),
            'multa_total' => (string) ($data['multa_total'] ?? '0.00'),
            'tipo_aceite' => (string) ($data['tipo_aceite'] ?? 'nova_instalacao'),
            'observacao_adesao' => (string) ($data['observacao_adesao'] ?? ''),
            'status_financeiro' => (string) ($data['status_financeiro'] ?? 'pendente_lancamento'),
        ];
    }
}
