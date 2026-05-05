<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'term_version' => Env::get('CONTRACT_TERM_VERSION', '2026.1'),
    'term_hash' => Env::get('CONTRACT_TERM_HASH', ''),
    'acceptance_ttl_hours' => (int) Env::get('CONTRACT_ACCEPTANCE_TTL_HOURS', '48'),
    'default_type_adesao' => Env::get('CONTRACT_DEFAULT_TYPE_ADESAO', 'cheia'),
    'default_tipo_aceite' => Env::get('CONTRACT_DEFAULT_TIPO_ACEITE', 'nova_instalacao'),
    'financeiro_setor' => 'financeiro',
    'message_templates' => [
        'aceite_nova_instalacao' => [
            'channel' => 'whatsapp',
            'purpose' => 'aceite_nova_instalacao',
        ],
        'aceite_regularizacao_contrato' => [
            'channel' => 'whatsapp',
            'purpose' => 'aceite_regularizacao_contrato',
        ],
    ],
];
