ALTER TABLE notification_logs
    MODIFY channel VARCHAR(32) NOT NULL DEFAULT 'whatsapp',
    MODIFY provider VARCHAR(64) NOT NULL DEFAULT 'evotrix',
    MODIFY recipient VARCHAR(190) NOT NULL,
    MODIFY provider_response MEDIUMTEXT NULL;
