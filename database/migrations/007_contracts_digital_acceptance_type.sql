ALTER TABLE client_contracts
    MODIFY tipo_aceite ENUM(
        'nova_instalacao',
        'regularizacao_contrato',
        'alteracao_plano',
        'renovacao_fidelidade',
        'aceite_promocao',
        'upgrade_migracao',
        'contrato_digital'
    ) NOT NULL DEFAULT 'nova_instalacao';
