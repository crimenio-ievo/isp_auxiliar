-- Manual. Destroi as quedas importadas do syslog (podem ser reimportadas do arquivo original). Nao toca em net_onu_events.
DROP TABLE IF EXISTS net_onu_outages;
DELETE FROM schema_migrations WHERE version = '034_net_onu_outages.sql' OR filename = '034_net_onu_outages.sql';
