#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Env;
use App\Infrastructure\Contracts\ContractAcceptanceRepository;
use App\Infrastructure\Contracts\ContractRepository;
use App\Infrastructure\Contracts\FinancialTaskRepository;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;

require dirname(__DIR__) . '/backend/bootstrap/autoload.php';

Env::load(dirname(__DIR__) . '/.env');

$config = new Config([
    'database' => require dirname(__DIR__) . '/backend/config/database.php',
    'contracts' => require dirname(__DIR__) . '/backend/config/contracts.php',
    'app' => require dirname(__DIR__) . '/backend/config/app.php',
]);

$database = new Database($config);
$providerKey = (string) Env::get('APP_PROVIDER_KEY', 'default');
$localRepository = new LocalRepository($database, $providerKey);
$contractRepository = new ContractRepository($database);
$acceptanceRepository = new ContractAcceptanceRepository($database);
$financialTaskRepository = new FinancialTaskRepository($database);

$stamp = date('YmdHis');
$login = 'teste_isp_auxiliar_contrato_' . $stamp;
$clientName = 'TESTE ISP_AUXILIAR CONTRATO ' . $stamp;
$phone = '31999990000';
$termBody = implode("\n", [
    'CONTRATO DIGITAL ISP AUXILIAR',
    'Cliente: ' . $clientName,
    'Login: ' . $login,
    'Telefone: ' . $phone,
    'Tipo de adesão: cheia',
    'Valor da adesão: R$ 100,00',
    'Parcelas da adesão: 1',
    'Valor por parcela: R$ 100,00',
    'Fidelidade: 12 meses',
    'Multa total: R$ 200,00',
    'Observação: Teste controlado de contrato',
]);

$contractData = [
    'client_id' => null,
    'mkauth_login' => $login,
    'nome_cliente' => $clientName,
    'telefone_cliente' => $phone,
    'tipo_adesao' => 'cheia',
    'valor_adesao' => 100.00,
    'parcelas_adesao' => 1,
    'valor_parcela_adesao' => 100.00,
    'vencimento_primeira_parcela' => date('Y-m-d', strtotime('+5 days')) ?: date('Y-m-d'),
    'fidelidade_meses' => 12,
    'beneficio_valor' => 0.00,
    'multa_total' => 200.00,
    'tipo_aceite' => 'nova_instalacao',
    'observacao_adesao' => 'Teste controlado de contrato',
    'status_financeiro' => 'pendente_lancamento',
];

$created = [
    'contract_id' => null,
    'acceptance_id' => null,
    'financial_task_id' => null,
];

try {
    $pdo = $database->pdo();
    $pdo->beginTransaction();

    $contractId = $contractRepository->create($contractData);
    if ($contractId === null || $contractId <= 0) {
        throw new RuntimeException('Nao foi possivel criar o contrato de teste.');
    }
    $created['contract_id'] = $contractId;

    $acceptanceId = $acceptanceRepository->create([
        'contract_id' => $contractId,
        'token' => bin2hex(random_bytes(16)),
        'token_expires_at' => date('Y-m-d H:i:s', strtotime('+48 hours')) ?: date('Y-m-d H:i:s'),
        'status' => 'criado',
        'telefone_enviado' => $phone,
        'whatsapp_message_id' => null,
        'sent_at' => null,
        'accepted_at' => null,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'ISP_AUXILIAR_CONTROLLED_TEST',
        'termo_versao' => (string) $config->get('contracts.term_version', '2026.1'),
        'termo_hash' => hash('sha256', $termBody),
        'pdf_path' => null,
        'evidence_json_path' => null,
    ]);

    if ($acceptanceId === null || $acceptanceId <= 0) {
        throw new RuntimeException('Nao foi possivel criar a aceitacao de teste.');
    }
    $created['acceptance_id'] = $acceptanceId;

    $financialTaskId = $financialTaskRepository->create([
        'contract_id' => $contractId,
        'mkauth_login' => $login,
        'titulo' => 'Lançar adesão cliente ' . $clientName,
        'descricao' => "Valor: R$ 100,00\nParcelas: 1\nTipo: cheia\nFidelidade: 12 meses",
        'setor' => 'financeiro',
        'status' => 'aberto',
    ]);

    if ($financialTaskId === null || $financialTaskId <= 0) {
        throw new RuntimeException('Nao foi possivel criar a tarefa financeira de teste.');
    }
    $created['financial_task_id'] = $financialTaskId;

    $pdo->commit();

    echo "Teste executado com sucesso.\n";
    echo "login: {$login}\n";
    echo "contract_id: {$created['contract_id']}\n";
    echo "acceptance_id: {$created['acceptance_id']}\n";
    echo "financial_task_id: {$created['financial_task_id']}\n";
    echo "\nConsultas de validação:\n";
    echo "SELECT * FROM client_contracts WHERE mkauth_login = '{$login}' ORDER BY id DESC;\n";
    echo "SELECT * FROM contract_acceptances WHERE contract_id = {$created['contract_id']} ORDER BY id DESC;\n";
    echo "SELECT * FROM financial_tasks WHERE contract_id = {$created['contract_id']} ORDER BY id DESC;\n";
    echo "\nLimpeza manual sugerida:\n";
    echo "DELETE FROM financial_tasks WHERE contract_id = {$created['contract_id']};\n";
    echo "DELETE FROM contract_acceptances WHERE contract_id = {$created['contract_id']};\n";
    echo "DELETE FROM client_contracts WHERE id = {$created['contract_id']};\n";

    exit(0);
} catch (Throwable $exception) {
    if ($database->pdo()->inTransaction()) {
        $pdo->rollBack();
    }

    try {
        $localRepository->log(
            null,
            'script',
            'contract.test_failed',
            'contract_test',
            null,
            [
                'login' => $login,
                'error' => $exception->getMessage(),
            ],
            '127.0.0.1',
            'ISP_AUXILIAR_CONTROLLED_TEST'
        );
    } catch (Throwable) {
        // Mantemos o script sem efeito colateral se o log local nao estiver disponivel.
    }

    fwrite(STDERR, "Falha no teste controlado: {$exception->getMessage()}\n");
    exit(1);
}
