# Seletor Stable/Beta real

## Resultado

O componente administrativo identifica o canal atual pela configuração e oferece somente o canal oposto. Na Beta, a ação é `Abrir Stable`; na futura Stable promovida, será `Abrir Beta`.

- Canais aceitos: `stable` e `beta`.
- Destinos: `APP_STABLE_BASE_URL` e `APP_BETA_BASE_URL`.
- A URL não vem do navegador.
- A seleção do próprio canal não redireciona nem recarrega.
- Não há preferência gravada em sessão, arquivo ou banco.
- Não há cópia de cookie, token em URL ou SSO improvisado.
- URLs sem HTTP/HTTPS, sem host ou com credencial são rejeitadas.

Na Beta de testes foram configurados:

- canal `beta`;
- Stable `https://teste.ievo.com.br/isp_auxiliar_stable/public/`;
- Beta `https://teste.ievo.com.br/isp_auxiliar/public/`;
- release `beta-test-final-2026.08`.

A Stable antiga permanece intocada e, por isso, não contém o seletor. Ela continua acessível diretamente por sua URL.

## Release administrativa

O cabeçalho e o rodapé administrativo mostram apenas canal, release ID e hash curto. Páginas públicas continuam sem identificação de release. `GET /api/release` exige administrador e retorna apenas metadados não sensíveis e os estados de segurança.
