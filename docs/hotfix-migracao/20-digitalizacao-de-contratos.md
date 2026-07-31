# Terceira iteração — digitalização de contratos

O MVP aceita câmera/galeria/arquivo para JPEG, PNG ou um PDF isolado. No
navegador, o técnico pré-visualiza, reordena, gira e remove imagens, depois
confirma legibilidade antes do envio.

## Processamento

- até 20 páginas;
- até 12 MiB por arquivo e 40 MiB no conjunto;
- MIME real validado por `finfo` e imagem decodificada por GD;
- imagens redimensionadas para limite legível, convertidas em JPEG de qualidade
  moderada e reunidas em PDF A4 local;
- PDF de entrada exige assinatura `%PDF-`, MIME real e contagem máxima de
  páginas; é armazenado sem serviço externo;
- nomes aleatórios, login sanitizado e diretório fora de `public`;
- SHA-256, tamanho, páginas, fontes, ordem, rotações, operador e data persistidos;
- temporários normalizados são removidos mesmo em falha; PDF órfão é removido se
  a gravação do banco falhar.

`client_documents` é escopada por provedor e pode vincular um contrato do mesmo
login. Download exige permissão, busca escopada, caminho real contido em
`storage/contracts/scanned`, MIME PDF, `nosniff`, cache privado e CSP sandbox.

Limitação: não há OCR, assinatura criptográfica de PDF nem saneamento/recriação
do conteúdo de um PDF já pronto. Por isso o visualizador é sandboxed e o arquivo
deve ser validado funcionalmente com amostras autorizadas antes de release.

Política do MVP: imagens temporárias são mantidas pelo PHP até a conclusão do
request e removidas depois; somente o PDF final e seus metadados permanecem.

