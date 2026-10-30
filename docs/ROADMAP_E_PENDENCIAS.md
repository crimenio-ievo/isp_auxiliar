# ISP_Auxiliar — Roadmap e pendências (fonte única)

Atualizado em 2026-10-02.

## Estado atual

- **Produção (.86, ispaux.ievo.com.br):** R5 = commit 3967195 (trilha de navegação, busca no topo, 4 blocos clicáveis, banner de testes condicional, Beta removida). Rollback: symlink de volta para a R4.
- **Teste (.89, teste.ievo.com.br):** branch feature/redesign-shell-fase1. O MkAuth de leitura do .89 aponta para o .88 (clone antigo; radacct parado em 27/04/2026).
- **Releases:** candidata -> produção (docs/fluxo-release-candidata-producao.md). No .86 o deploy é manual: pasta de release nova + troca de symlink.

## Prioridades (em ordem)

1. **Interface limpa (casca feita: sem barra superior, busca grande na Home e compacta nas demais, Ctrl K, trilha com usuário/sair; botão de IA com painel "Assistente — em breve" (só interface, sem chamada de IA); login limpo e centralizado):** barra de busca única e dominante em todas as telas (estilo Google, base da futura IA); topo mínimo; botão de IA/chat no canto superior esquerdo abrindo painel sob demanda; ISP Map como item da trilha. Implementar só a partir de mockup aprovado.
2. **FEITO (2faa26b) — Regras dos blocos da Home:** vermelho = ativo + bloqueado ou status_corte='down'; amarelo = ativo, não bloqueado, vencido há mais de N dias (padrão 5); em dia = demais ativos; desativados fora.
3. **ISP Map dentro do ISP_Auxiliar:** fase 0 feita (protótipo estático, dados fictícios, em modules/isp_map/ servido por /mapa só para gestor/admin; sem persistência). **Fase 1B feita (feature/mapa-fase1, só .89):** migration 019 (map_nodes/map_ports/map_links, aplicada no .89) e leitura via GET /api/mapa; o mapa já carrega do banco (vazio por enquanto). **Fase 1C feita:** nós (criar/editar/mover/excluir) gravados pela interface via POST /api/mapa/nos[/editar|/excluir] (gestor + CSRF escopo map, auditoria map.node.*); ligações, portas e diagrama CTO/CEO continuam só em memória. **1C+ feita:** busca por coordenadas no campo de busca (decimal, graus/minutos/segundos, link do Google Maps) com marcador temporário e "Adicionar elemento aqui" (só frontend, sem serviço externo). **Fase 1D feita:** ligações nó→nó (criar/editar/excluir) via POST /api/mapa/links[/editar|/excluir] (medium derivado do tipo, duplicata 409, auditoria map.link.*); sangria desabilitada ("disponível em breve") até existir gravação de caixa+trechos; portas (from/to_port_id), traçado e diagrama CTO/CEO seguem fora. **Fase 1D+ feita:** overzoom por camada (maxNativeZoom: Esri 17, OSM 19), nome automático PREFIXO-NNN no cadastro de nós, e situação (lifecycle projeto/ativo/desativado, migration 020 aplicada no .89) em nós e trechos, com filtro na barra lateral e KPIs só de elementos ativos. "Mapa escuro" agora é OSM com filtro CSS (o Carto passou a exigir chave de API; se um dia houver chave, pode voltar). Próximo: 1E (vínculo cliente/equipamento) e portas. **Pendência futura:** busca por endereço/cidade via geocodificação — decidir o serviço e testar a qualidade em área rural antes.
4. **Telemetria:** OLT piloto (V-SOL ou Hioso, a definir), coleta determinística a cada ~5 min, lista de ONUs offline ou com sinal ruim por PON, com o cliente. UISP para a rede de rádio.
5. **Chamados + Kanban genérico:** Pendente / Em andamento / Aguardando / Concluído. "Atrasado" é selo, não coluna; "Aguardando" é marcado manualmente, com motivo. Chamados lidos do MkAuth, estado do quadro em tabelas próprias. Substitui o painel do addon appsuporte do MkAuth.
6. **Configurações extensíveis e multiempresa** (ler docs/multiempresa.md antes).
7. **Contratos, processos e aceites** agrupados pelo fluxo real.
8. **Financeiro,** evoluindo aos poucos.
9. **Atendimento IA:** simulador primeiro; substituir o Evotrix.
10. **Gestão de equipamentos (registro genérico):** equipamento + perfil por modelo (protocolo, OIDs/escalas, ritmo seguro, confirmação de identidade, capacidades) + coletores genéricos por protocolo. Modelo novo = perfil novo, não código. Configuração de equipamentos só depois da leitura segura, com permissão, aprovação, backup e reversão.
11. **Gráfico de tráfego por interface/rota (MikroTik)** em tempo quase real. Ranking de consumo por cliente já é possível pelo Radius; não é prioridade.
12. **ISP Map:** frente própria em chat separado, branch feature/mapa-fase1 (persistência NODE->PORT->LINK, cadastro pela interface, depois vínculo com cliente e equipamento).

## Pendências técnicas

- .86: rodar scripts/migrate_integration_settings.php (simulação, depois --apply) antes de alguém salvar Email/Evotrix pela tela.
- .86: o .env da R5 é cópia (a R4 usa symlink para o arquivo compartilhado). Consolidar.
- .86: validação funcional real da foto de instalação (técnico em campo) e do benefício (contrato existente).
- "Fluxo do dia" (agora em /instalacoes, fora da Home): o status "Aguardando conexão Radius" só muda por verificação manual (14 presos no .89). Decidir entre verificação automática via radacct ou substituir esse bloco.
- AiUsagePolicy: teto de orçamento só é checado em evaluateRealCall(), mas o fluxo conversacional usa evaluate(). Bloqueador antes de AI_REAL_CALLS_ENABLED=true.
- Esconder links de /contratos/detalhe (linha do tempo e cartões) de quem não tem permissão de contratos.
- .89 sem banco de teste isolado.
- Identidade visual própria (adiada); extrair as cores reais do CSS antes de definir.
- Botão de IA: painel lateral/tela cheia existe, sem conteúdo (nenhuma chamada de IA). Botão de chat/WhatsApp: ainda só em mockup.
- Expor em Configurações a tolerância de dias da fatura vencida (home_overdue_grace_days, padrão 5).
- OLT Hioso 192.168.0.88: houve dois equipamentos respondendo no mesmo IP (sysDescr 'Epon 7104' vs 'unknown'); uma volta ao estado anterior após reboot (provável configuração não salva). Investigar a causa e SALVAR a configuração de IP. Dados coletados antes da correção são inválidos.
- Regras de coleta: confirmar identidade (sysName 'EPON HIOSO 1' + sysDescr 'unknown') antes de gravar; uma OID por pedido com intervalo; distinguir 'equipamento sem resposta' de 'ONU offline'; ifPhysAddress dessa OLT não traz MAC válido.

## Segurança (baixa urgência, não esquecer)

- Trocar a senha SMTP (Zoho) e revogar a chave antiga do Evotrix (ambas expostas no histórico do git).
- Senha do banco do .89 exposta em relatório: trocar e verificar se o .86 usa a mesma. Remover /root/.my.cnf do .89.
- Verificar o conteúdo da pasta backups/ versionada no git.
- MariaDB do .89 escutando em 0.0.0.0:3306.
- OLT Hioso com comunidades SNMP e senhas v3 de fábrica (a de escrita permite alterar a OLT): trocar. Não reutilizar senhas do sistema em equipamentos.

## Regras permanentes

- .89 = teste; .86 = produção com integrações reais ligadas. Validar no .89 não garante o comportamento no .86.
- No .86, /var/www/html/isp_auxiliar (checkout antigo) é a raiz dos dados compartilhados (.env, storage, config.json). Nunca apagar.
- .88 = clone do MkAuth só para análise. Não modificar.
- MkAuth é a fonte da verdade: gravar, reler e confirmar. Nunca criar colunas em tabelas do MkAuth.
- Nunca rm -rf em worktree. Worktrees protegidos: _isp_auxiliar_ia_integracao e _isp_auxiliar_monitoramento_cliente.
- Nunca promover sem confirmação explícita; nunca criar usuário de teste; nunca imprimir segredos.
- Telemetria determinística, sem IA no ciclo de coleta. ONU identificada pelo serial; vínculo em client_network_bindings; onu_rx_dbm e olt_rx_from_onu_dbm separados.
- Rollback: falha da ferramenta de verificação não é motivo de rollback; falha da aplicação é.

## Visão de futuro

Ecossistema único (agenda, execução de serviço, projetos, atendimento, financeiro), genérico e multiempresa, exportável para outros negócios. Alertas automáticos determinísticos separados da IA como agente.

Blocos da Home com estado de rede (depende de telemetria): vermelho = desconectado ou atraso grave; amarelo = atraso leve ou sinal ruim; azul = pendência para acompanhar (chamado, aceite); verde = em dia e conectado. Blocos personalizáveis por perfil no futuro.
