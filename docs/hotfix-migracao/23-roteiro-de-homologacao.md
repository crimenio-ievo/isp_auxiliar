# Terceira iteração — roteiro de homologação funcional

Pré-condições: banco local com migration 017; `MKAUTH_WRITE_ENABLED=false`;
Evotrix/e-mail/chamado em dry-run; dados sintéticos ou cliente autorizado; sem
deploy de produção.

## Perfil

1. Pesquisar cliente com dados completos e outro com campos vazios.
2. Conferir nome/status/identificação, ausência de “Novo cliente” e de caixas
   vazias.
3. Testar telefone, WhatsApp, e-mail, mapa, cópia de login e IP com perfis
   autorizado/não autorizado.
4. Abrir os quatro cartões por mouse, teclado e toque; fechar por botão, backdrop
   e Escape.
5. Conferir lazy-load de conexão/financeiro e a área de documentos/histórico.

## Migração

6. Iniciar mudança entre tecnologias e na mesma tecnologia para verificar
   migração, upgrade e downgrade.
7. Tentar o mesmo plano e uma tecnologia desconhecida.
8. Forçar erros, conferir preservação, resumo e foco.
9. Testar retenção separada e fidelidade desligada.
10. Tentar fidelidade sem benefício/valor e depois com 1–12 meses válidos.
11. Salvar para depois, retomar e abrir o checklist de 11 etapas.

## Aceite e processo

12. Coletar assinatura local, selecionar WhatsApp/e-mail/ambos e confirmar que
    os resultados são dry-run.
13. Abrir o link como cliente: documento parcial, checkbox e ausência de nova
    assinatura local.
14. Repetir com cliente ausente, motivo e assinatura remota.
15. Forçar contato/canal inválido e reenvio; confirmar histórico preservado e
    ausência de copiar link/mensagem.
16. Na tela 3, testar PPPoE online e exceção manual offline com evidência.
17. Na tela 4, revisar pré-requisitos, registrar dry-run e confirmar que plano
    não vira aplicado.
18. Simular chamado único, consulta aberta/fechada e ausência de responsável.

## Mensagens e scanner

19. Editar WhatsApp e assunto/corpo de e-mail, validar variável inválida e
    script, desabilitar canal, restaurar padrão e reativar versão histórica.
20. Confirmar prévia fictícia e snapshot no log de envio dry-run.
21. Digitalizar duas imagens: remover, ordenar, girar, confirmar e abrir PDF.
22. Testar PNG/JPEG, PDF isolado, MIME falso, executável, excesso de tamanho e
    acesso sem permissão/outro provedor.

## Layout e dispositivos

23. Entrar como administrador em `/isp-auxiliar-old-reference`, conferir banner,
    navegação read-only e sessão isolada.
24. Repetir perfil, migração, aceite e scanner em desktop, notebook, tablet,
    Android/Chrome e iOS/Safari, retrato/paisagem, zoom e teclado.
25. Registrar evidências, navegador/dispositivo, resolução e defeitos agrupados.

Critério de saída: aprovação funcional documentada sem remover os guardas. Não
habilitar produção durante esta homologação.

