<?php

declare(strict_types=1);

use App\Core\Env;

$rootPath = dirname(__DIR__, 2);
$overrideFile = $rootPath . '/storage/contracts/config.json';
$overrides = [];

if (is_file($overrideFile)) {
    $decodedOverrides = json_decode((string) file_get_contents($overrideFile), true);
    if (is_array($decodedOverrides)) {
        $overrides = $decodedOverrides;
    }
}

$commercialOverrides = is_array($overrides['commercial'] ?? null) ? $overrides['commercial'] : [];

$commercial = [
    'valor_adesao_padrao' => (float) Env::get('CONTRACT_VALOR_ADESAO_PADRAO', '0'),
    'valor_adesao_promocional' => (float) Env::get('CONTRACT_VALOR_ADESAO_PROMOCIONAL', '0'),
    'percentual_desconto_promocional' => (float) Env::get('CONTRACT_PERCENTUAL_DESCONTO_PROMOCIONAL', '0'),
    'parcelas_maximas_adesao' => max(1, (int) Env::get('CONTRACT_PARCELAS_MAXIMAS_ADESAO', '3')),
    'fidelidade_meses_padrao' => max(1, (int) Env::get('CONTRACT_FIDELIDADE_MESES_PADRAO', '12')),
    'multa_padrao' => (float) Env::get('CONTRACT_MULTA_PADRAO', '0'),
    'exigir_validacao_cpf_aceite' => filter_var(
        Env::get('CONTRACT_EXIGIR_VALIDACAO_CPF_ACEITE', '1'),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? true,
    'quantidade_digitos_validacao_cpf' => max(1, (int) Env::get('CONTRACT_QTD_DIGITOS_VALIDACAO_CPF', '3')),
    'validade_link_aceite_horas' => max(1, (int) Env::get('CONTRACT_VALIDADE_LINK_ACEITE_HORAS', '48')),
];

$commercial = array_replace($commercial, array_intersect_key($commercialOverrides, $commercial));

return [
    'term_version' => Env::get('CONTRACT_TERM_VERSION', '2026.1'),
    'term_hash' => Env::get('CONTRACT_TERM_HASH', ''),
    'acceptance_ttl_hours' => max(1, (int) Env::get('CONTRACT_ACCEPTANCE_TTL_HOURS', (string) $commercial['validade_link_aceite_horas'])),
    'commercial' => $commercial,
    'financeiro_setor' => 'financeiro',
    'mkauth_ticket' => [
        'enabled' => filter_var(
            Env::get('MKAUTH_TICKET_ENABLED', '0'),
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE
        ) ?? false,
        'dry_run' => filter_var(
            Env::get('MKAUTH_TICKET_DRY_RUN', '1'),
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE
        ) ?? true,
        'auto_create' => filter_var(
            Env::get('CONTRACT_AUTO_CREATE_FINANCIAL_TICKET', '0'),
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE
        ) ?? false,
        'endpoint' => Env::get('MKAUTH_TICKET_ENDPOINT', '/api/chamado/inserir'),
        'subject' => Env::get('MKAUTH_TICKET_SUBJECT', 'Financeiro - Boleto / Carne'),
        'priority' => Env::get('MKAUTH_TICKET_PRIORITY', 'normal'),
    ],
    'message_templates' => [
        'aceite_nova_instalacao' => [
            'channel' => 'whatsapp',
            'purpose' => 'aceite_nova_instalacao',
            'body' => "Olá, {nome}.\n\nSeu cadastro foi realizado.\n\nConfira seus dados, plano, valores e contrato no link abaixo:\n\n{link_aceite}\n\nEste link é pessoal e expira em {validade_horas} horas.",
            'variables_json' => ['nome', 'link_aceite', 'validade_horas'],
        ],
        'aceite_regularizacao_contrato' => [
            'channel' => 'whatsapp',
            'purpose' => 'aceite_regularizacao_contrato',
            'body' => "Olá, {nome}.\n\nSeu contrato precisa de regularização cadastral.\n\nConfira os dados e aceite digital no link abaixo:\n\n{link_aceite}\n\nEste link é pessoal e expira em {validade_horas} horas.",
            'variables_json' => ['nome', 'link_aceite', 'validade_horas'],
        ],
    ],
];
