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

$component = (string) file_get_contents($root . '/backend/Views/components/migration_actions.php');
$upgradeView = (string) file_get_contents($root . '/backend/Views/clients/upgrade.php');
$migrationView = (string) file_get_contents($root . '/backend/Views/processes/migration.php');
$clientController = (string) file_get_contents($root . '/backend/Controllers/ClientController.php');
$processController = (string) file_get_contents($root . '/backend/Controllers/OperationalProcessController.php');
$provisioner = (string) file_get_contents($root . '/backend/Infrastructure/MkAuth/ClientProvisioner.php');
$javascript = (string) file_get_contents($root . '/public/assets/js/app.js');

// Jornada e estado visual.
$assert(str_contains($component, 'migration-actions__back'), 'O componente não separa a ação Voltar.');
$assert(str_contains($component, 'migration-actions__right'), 'O componente não agrupa Continuar/Concluir e Mais ações à direita.');
$assert(str_contains($component, 'data-migration-actions-menu') && str_contains($component, 'role="menu"'), 'Mais ações não usa o componente compartilhado.');
$assert(str_contains($component, 'aria-haspopup="menu"') && str_contains($component, 'aria-expanded="false"'), 'Mais ações não expõe estado acessível.');
$assert(str_contains($javascript, "event.key !== 'Escape'") && str_contains($javascript, 'openMenu.open = false'), 'Escape não fecha Mais ações.');
$assert(str_contains($javascript, "document.addEventListener('pointerdown'") && str_contains($javascript, '!menu.contains(event.target)'), 'Clique fora não fecha Mais ações.');
$assert(str_contains($javascript, "form.dataset.submitting = '1'") || str_contains($javascript, 'let submitted = false'), 'Proteção contra submit duplicado não foi preservada.');
$assert(str_contains($upgradeView, "'value' => 'continue'") && str_contains($upgradeView, "'label' => 'Continuar para o aceite'"), 'Etapa 1 não usa Continuar como ação principal.');
$assert(str_contains($clientController, "&step=confirm_acceptance'"), 'Continuar da Etapa 1 não direciona explicitamente ao aceite.');
$assert(str_contains($upgradeView, "'label' => 'Corrigir nova condição'") && str_contains($upgradeView, "'more' => array_values"), 'Corrigir nova condição não foi movido para Mais ações.');
$assert(str_contains($migrationView, "'name' => 'acceptance_action'") && str_contains($migrationView, "'label' => 'Continuar'"), 'Etapa 2 não usa Continuar como ação contextual.');
$assert(strpos($clientController, 'dispatchAcceptanceChannels') > strpos($clientController, "\$acceptanceAction = trim"), 'Primeiro Continuar não alcança o envio após as validações.');
$assert(str_contains($clientController, "if (\$acceptanceSent && \$acceptanceAction !== 'resend')"), 'Continuar pendente não possui barreira contra reenvio.');
$assert(str_contains($clientController, 'Nenhuma mensagem foi reenviada.'), 'Continuar pendente não informa o resultado seguro.');
$assert(str_contains($clientController, "&step=technical_execution'"), 'Aceite confirmado não avança para a execução.');
$assert(str_contains($migrationView, "'value' => 'verify'") && str_contains($migrationView, 'Verificar confirmação'), 'Verificar confirmação não está em Mais ações.');
$assert(str_contains($migrationView, "'value' => 'resend'") && str_contains($clientController, "\$resendOnly = \$acceptanceAction === 'resend'"), 'Reenvio explícito não reutiliza o fluxo controlado.');
$assert(str_contains($migrationView, "'label' => 'Voltar para o aceite'"), 'Etapa 3 não volta ao aceite.');
$assert(str_contains($migrationView, "'label' => 'Continuar para finalização'") && !str_contains($migrationView, 'data-continue-finalization'), 'Etapa 3 ainda bloqueia o avanço por PPPoE.');
$assert(str_contains($migrationView, 'Verificar conexão agora') && str_contains($javascript, "cache: 'no-store'"), 'Consulta nova de conexão não está em Mais ações ou pode usar cache.');
$assert(!str_contains($processController, '$advanceToFinalization') && str_contains($processController, "&step=change_plan'"), 'PPPoE pendente ainda impede a saída da execução.');
$assert(str_contains($migrationView, "'label' => 'Concluir migração'") && str_contains($migrationView, "'disabled' => \$finalizationPending !== []") && str_contains($migrationView, 'Atualizar verificações'), 'Finalização não bloqueia conclusão e mantém atualização em Mais ações.');

$journey = new MigrationJourneyService();
$stepKeys = ['migration_data', 'prepare_document', 'send_acceptance', 'confirm_acceptance', 'technical_execution', 'confirm_equipment', 'change_plan', 'validate_connection', 'open_financial_ticket', 'follow_financial_ticket', 'complete_migration'];
$steps = array_map(static fn (string $key): array => ['step_key' => $key, 'status' => $key === 'migration_data' ? 'completed' : 'not_started'], $stepKeys);
$projection = $journey->project(['id' => 812, 'status' => 'in_progress', 'steps' => $steps], 'change_plan');
$currentStages = array_filter($projection['visible_steps'], static fn (array $stage): bool => $stage['visual_state'] === 'current');
$assert(count($currentStages) === 1 && $projection['visible_steps'][0]['visual_state'] === 'completed', 'Mais de uma etapa parece ativa na Finalização.');

// Novo cliente.
$validator = new ClientCompletionValidator();
$validClient = [
    'pessoa' => 'fisica',
    'cpf_cnpj' => '529.982.247-25',
    'nome_completo' => 'Cliente sintético',
    'login' => 'cliente_candidato',
    'plano' => 'PLANO-OFICIAL-UUID',
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
$assert(isset($validator->validate(array_replace($validClient, ['cpf_cnpj' => '']))['cpf_cnpj']), 'PF sem CPF não foi bloqueada.');
$assert(isset($validator->validate(array_replace($validClient, ['pessoa' => 'juridica', 'cpf_cnpj' => '']))['cpf_cnpj']), 'PJ sem CNPJ não foi bloqueada.');
$assert(isset($validator->validate(array_replace($validClient, ['cpf_cnpj' => '11111111111']))['cpf_cnpj']), 'Documento inválido não foi bloqueado.');
$assert(substr_count($clientController, 'clientCompletionValidator()->validate($data)') >= 2, 'POST de conclusão não reutiliza a validação completa.');
$assert(strpos($provisioner, 'assertComplete($formData)') < strpos($provisioner, 'createClient($payload)'), 'A validação não ocorre antes da chamada MkAuth.');
$assert(isset($validator->validate(array_replace($validClient, ['plano' => '']))['plano']) && str_contains($provisioner, 'resolveExpectedPlan'), 'Plano obrigatório/oficial não foi preservado.');
$assert(str_contains($provisioner, '$this->planConfirmation->confirm'), 'Plano não é relido depois da criação.');
$assert(str_contains($provisioner, 'throwPartial') && str_contains($provisioner, 'plano não foi confirmado'), 'Divergência de plano pode aparecer como sucesso total.');
$assert(str_contains($clientController, 'external_created_local_pending') && str_contains($provisioner, 'create_recovered_by_readback'), 'Retry não preserva UUID/request_id para evitar duplicidade.');

// Benefícios.
$benefits = new UpgradeBenefitService(new Config(['contracts' => ['commercial' => [
    'valor_adesao_padrao' => 1200,
    'modo_isencao_adesao_migracao_radio_fibra' => 'automatic',
    'fidelidade_automatica_migracao' => true,
    'fidelidade_automatica_upgrade' => false,
]]]));
$radioToFiber = $benefits->calculate('Rádio', 'Fibra', 'Rádio 10 Mbps', 'Fibra 100 Mbps', 80, 149.90);
$assert(!empty($radioToFiber['flags']['radio_to_fiber']) && !empty($radioToFiber['flags']['adhesion_waiver']), 'Rádio para Fibra não calcula migração e isenção.');
$sameTechnology = $benefits->calculate('Rádio', 'Rádio', 'Rádio 10 Mbps', 'Rádio 20 Mbps', 80, 100);
$assert(!empty($sameTechnology['flags']['plan_upgrade']) && empty($sameTechnology['flags']['adhesion_waiver']) && (float) $sameTechnology['value'] === 0.0, 'Upgrade herdou benefício de migração.');
$assert(str_contains($upgradeView, 'Descrição do outro benefício') && str_contains($clientController, 'beneficio_outro_text'), 'Outro benefício não exige descrição no backend.');
$assert(str_contains($upgradeView, 'Justificativa do ajuste') && str_contains($clientController, 'benefit_adjustment_reason'), 'Alteração manual não exige justificativa auditável.');
$assert(!empty($radioToFiber['automatic_fidelity']) && str_contains($clientController, 'fidelity_benefit_description'), 'Fidelidade automática não exige benefício elegível e descrito.');

echo 'BetaProductionCandidateSmoke OK - ' . $checks . " verificações; nenhuma escrita externa.\n";
