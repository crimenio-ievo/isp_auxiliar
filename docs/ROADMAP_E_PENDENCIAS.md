# ISP_Auxiliar — Roadmap e pendências (fonte única)

Atualizado em 2026-10-02.

## Estado atual

- **Produção (.86, ispaux.ievo.com.br):** R5 = commit 3967195 (trilha de navegação, busca no topo, 4 blocos clicáveis, banner de testes condicional, Beta removida). Rollback: symlink de volta para a R4.
- **Teste (.89, teste.ievo.com.br):** branch feature/redesign-shell-fase1. O MkAuth de leitura do .89 aponta para o .88 (clone antigo; radacct parado em 27/04/2026).
- **Releases:** candidata -> produção (docs/fluxo-release-candidata-producao.md). No .86 o deploy é manual: pasta de release nova + troca de symlink.

## Prioridades (em ordem)

1. **Interface limpa:** barra de busca única e dominante em todas as telas (estilo Google, base da futura IA); topo mínimo; botão de IA/chat no canto superior esquerdo abrindo painel sob demanda; ISP Map como item da trilha. Implementar só a partir de mockup aprovado.
2. **Regras dos blocos da Home:** vermelho = ativo + bloqueado ou status_corte='down'; amarelo = ativo, não bloqueado, vencido há mais de N dias (padrão 5); em dia = demais ativos; desativados fora.
3. **ISP Map dentro do ISP_Auxiliar:** subir isp-network-map.html para o .89; banco próprio (NODE -> PORT -> LINK); integrar depois.
4. **Telemetria:** OLT piloto (V-SOL ou Hioso, a definir), coleta determinística a cada ~5 min, lista de ONUs offline ou com sinal ruim por PON, com o cliente. UISP para a rede de rádio.
5. **Chamados + Kanban genérico:** Pendente / Em andamento / Aguardando / Concluído. "Atrasado" é selo, não coluna; "Aguardando" é marcado manualmente, com motivo. Chamados lidos do MkAuth, estado do quadro em tabelas próprias. Substitui o painel do addon appsuporte do MkAuth.
6. **Configurações extensíveis e multiempresa** (ler docs/multiempresa.md antes).
7. **Contratos, processos e aceites** agrupados pelo fluxo real.
8. **Financeiro,** evoluindo aos poucos.
9. **Atendimento IA:** simulador primeiro; substituir o Evotrix.

## Pendências técnicas

- .86: rodar scripts/migrate_integration_settings.php (simulação, depois --apply) antes de alguém salvar Email/Evotrix pela tela.
- .86: o .env da R5 é cópia (a R4 usa symlink para o arquivo compartilhado). Consolidar.
- .86: validação funcional real da foto de instalação (técnico em campo) e do benefício (contrato existente).
- "Fluxo do dia": o status "Aguardando conexão Radius" só muda por verificação manual (14 presos no .89). Decidir entre verificação automática via radacct ou substituir esse bloco.
- AiUsagePolicy: teto de orçamento só é checado em evaluateRealCall(), mas o fluxo conversacional usa evaluate(). Bloqueador antes de AI_REAL_CALLS_ENABLED=true.
- Esconder links de /contratos/detalhe (linha do tempo e cartões) de quem não tem permissão de contratos.
- .89 sem banco de teste isolado.
- Identidade visual própria (adiada); extrair as cores reais do CSS antes de definir.
- Botão de chat/WhatsApp: hoje só existe em mockup.

## Segurança (baixa urgência, não esquecer)

- Trocar a senha SMTP (Zoho) e revogar a chave antiga do Evotrix (ambas expostas no histórico do git).
- Senha do banco do .89 exposta em relatório: trocar e verificar se o .86 usa a mesma. Remover /root/.my.cnf do .89.
- Verificar o conteúdo da pasta backups/ versionada no git.
- MariaDB do .89 escutando em 0.0.0.0:3306.

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
