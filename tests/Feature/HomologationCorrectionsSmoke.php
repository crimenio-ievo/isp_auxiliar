<?php

declare(strict_types=1);

use App\Core\Config;
use App\Services\Clients\ClientCompletionValidator;
use App\Services\Commercial\UpgradeBenefitService;
use App\Services\Processes\MigrationJourneyService;

require dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';

$root = dirname(__DIR__, 2);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

$validator = new ClientCompletionValidator();
$valid = [
    'pessoa' => 'fisica',
    'cpf_cnpj' => '529.982.247-25',
    'nome_completo' => 'Cliente sintético',
    'login' => 'cliente_teste',
    'plano' => 'PLANO-UUID-OFICIAL',
    'celular' => '(31) 99999-9999',
    'endereco' => 'Rua Teste',
    'numero' => '10',
    'bairro' => 'Centro',
    'cidade' => 'Coimbra',
    'estado' => 'MG',
    'vencimento' => '10',
    'tipo_instalacao' => 'fibra',
    'local_dici' => 'r',
    'coordenadas' => '-20.850552,-42.803886',
    'tipo_adesao' => 'cheia',
    'parcelas_adesao' => '1',
    'fidelidade_meses' => '12',
];
$normalized = $validator->assertComplete($valid);
$assert($normalized['cpf_cnpj'] === '52998224725', 'CPF válido não foi normalizado.');
$assert(isset($validator->validate(array_replace($valid, ['cpf_cnpj' => '']))['cpf_cnpj']), 'Pessoa física sem CPF não foi bloqueada.');
$assert(isset($validator->validate(array_replace($valid, ['cpf_cnpj' => '11111111111']))['cpf_cnpj']), 'Sequência inválida de CPF foi aceita.');
$assert(isset($validator->validate(array_replace($valid, ['pessoa' => 'juridica', 'cpf_cnpj' => '']))['cpf_cnpj']), 'Pessoa jurídica sem CNPJ não foi bloqueada.');
$validCompany = array_replace($valid, ['pessoa' => 'juridica', 'cpf_cnpj' => '04.252.011/0001-10']);
$assert($validator->assertComplete($validCompany)['cpf_cnpj'] === '04252011000110', 'CNPJ válido não foi normalizado.');
$assert(isset($validator->validate(array_replace($validCompany, ['cpf_cnpj' => '04.252.011/0001-11']))['cpf_cnpj']), 'CNPJ inválido foi aceito.');
foreach (['nome_completo', 'login', 'plano', 'celular', 'endereco', 'bairro', 'cidade', 'estado', 'vencimento'] as $field) {
    $assert(isset($validator->validate(array_replace($valid, [$field => '']))[$field]), "Campo obrigatório {$field} não foi bloqueado.");
}

$benefits = new UpgradeBenefitService(new Config(['contracts' => ['commercial' => [
    'valor_adesao_padrao' => 1200,
    'modo_isencao_adesao_migracao_radio_fibra' => 'automatic',
    'fidelidade_automatica_migracao' => true,
    'fidelidade_automatica_upgrade' => false,
]]]));
$migration = $benefits->calculate('Rádio fixo', 'Fibra FTTH', 'Rádio 10 Mbps', 'Fibra 100 Mbps', 80, 149.90);
$assert(!empty($migration['flags']['radio_to_fiber']) && !empty($migration['flags']['adhesion_waiver']), 'Rádio para fibra não marcou migração e isenção.');
$assert(!empty($migration['flags']['plan_upgrade']), 'Migração com aumento não marcou também o upgrade.');
$assert((float) $migration['value'] === 1200.0 && !empty($migration['automatic_fidelity']), 'Benefício configurado não sustentou fidelidade automática.');
$sameTechnology = $benefits->calculate('Rádio', 'Rádio', 'Rádio 10 Mbps', 'Rádio 20 Mbps', 80, 100);
$assert(!empty($sameTechnology['flags']['plan_upgrade']) && empty($sameTechnology['flags']['adhesion_waiver']) && (float) $sameTechnology['value'] === 0.0, 'Upgrade na mesma tecnologia herdou a isenção da migração.');
$assert($benefits->normalizeFlags(['radio_to_fiber', 'other_benefit'])['other_benefit'], 'Lista de checkboxes não foi normalizada no servidor.');

$keys = ['migration_data', 'prepare_document', 'send_acceptance', 'confirm_acceptance', 'technical_execution', 'confirm_equipment', 'change_plan', 'validate_connection', 'open_financial_ticket', 'follow_financial_ticket', 'complete_migration'];
$journey = new MigrationJourneyService();
foreach (['migration_data' => 1, 'confirm_acceptance' => 2, 'technical_execution' => 3, 'change_plan' => 4] as $requested => $expectedStage) {
    $steps = array_map(static fn (string $key): array => [
        'step_key' => $key,
        'status' => $key === 'migration_data' ? 'completed' : 'not_started',
    ], $keys);
    $projection = $journey->project(['id' => 77, 'status' => 'in_progress', 'steps' => $steps], $requested);
    $current = array_values(array_filter($projection['visible_steps'], static fn (array $stage): bool => $stage['visual_state'] === 'current'));
    $assert((int) $projection['active_stage'] === $expectedStage && count($current) === 1, "Etapa {$expectedStage} não foi a única visualmente atual.");
    if ($expectedStage === 4) {
        $assert($projection['visible_steps'][0]['visual_state'] === 'completed', 'Etapa 1 concluída continuou parecendo ativa na etapa 4.');
    }
}

$migrationView = (string) file_get_contents($root . '/backend/Views/processes/migration.php');
$actionComponent = (string) file_get_contents($root . '/backend/Views/components/migration_actions.php');
$upgradeView = (string) file_get_contents($root . '/backend/Views/clients/upgrade.php');
$createView = (string) file_get_contents($root . '/backend/Views/clients/create.php');
$assert(str_contains($actionComponent, 'migration-actions__back') && str_contains($actionComponent, 'migration-actions__right') && str_contains($migrationView, '$renderMigrationActions'), 'Componente compartilhado de ações não foi aplicado.');
$assert(str_contains($migrationView, "'name' => 'acceptance_action'") && str_contains($migrationView, 'Aguardando confirmação do cliente'), 'Aceite não mantém o Continuar contextual na etapa pendente.');
$assert(str_contains($migrationView, 'capture="environment"') && str_contains($migrationView, 'data-migration-evidence-preview'), 'Execução não oferece câmera e preview.');
$assert(str_contains($migrationView, 'Atualizar verificações') && str_contains($migrationView, 'Fechamento financeiro confirmado'), 'Finalização não atualiza toda a matriz de pendências.');
$assert(str_contains($upgradeView, 'benefit_flags[]') && str_contains($upgradeView, 'Descrição do outro benefício'), 'Checkboxes e outro benefício não aparecem na Nova condição.');
$assert(str_contains($createView, 'name="_csrf"') && str_contains($createView, 'data-focus-field'), 'Cadastro não possui CSRF e foco de erro.');

echo 'HomologationCorrectionsSmoke OK - ' . $checks . " verificações; nenhuma escrita externa.\n";
