ALTER TABLE financial_tasks
    ADD COLUMN mkauth_ticket_id VARCHAR(64) NULL AFTER status;

ALTER TABLE financial_tasks
    ADD COLUMN mkauth_ticket_status VARCHAR(64) NULL AFTER mkauth_ticket_id;

ALTER TABLE financial_tasks
    ADD COLUMN mkauth_ticket_checked_at DATETIME NULL AFTER mkauth_ticket_status;

ALTER TABLE financial_tasks
    ADD COLUMN completed_at DATETIME NULL AFTER mkauth_ticket_checked_at;

ALTER TABLE financial_tasks
    ADD COLUMN completed_by VARCHAR(120) NULL AFTER completed_at;
