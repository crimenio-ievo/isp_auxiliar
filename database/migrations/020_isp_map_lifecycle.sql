ALTER TABLE map_nodes
    ADD COLUMN lifecycle VARCHAR(20) NOT NULL DEFAULT 'ativo' AFTER status,
    ADD KEY map_nodes_provider_lifecycle (provider_id, lifecycle);

ALTER TABLE map_links
    ADD COLUMN lifecycle VARCHAR(20) NOT NULL DEFAULT 'ativo' AFTER status;
