-- Manual. Destroi os vinculos cliente<->CTO/ONU e as sugestoes recusadas (o historico tambem fica em audit_logs, acoes net.binding.*).
-- Faca um dump antes. Nao toca em map_nodes, map_ports nem net_onus.
DROP TABLE IF EXISTS client_network_binding_rejections;
DROP TABLE IF EXISTS client_network_bindings;
DELETE FROM schema_migrations WHERE version = '045_client_network_bindings.sql' OR filename = '045_client_network_bindings.sql';
