ALTER TABLE contract_acceptances
    MODIFY status ENUM('criado', 'enviado', 'assinatura_pendente', 'aceito', 'expirado', 'cancelado') NOT NULL DEFAULT 'criado',
    ADD COLUMN remote_signature_reason TEXT NULL AFTER telefone_enviado;
