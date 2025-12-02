# Hacklab Migration

## Changelog

0.0.1 - Versão inicial

## Compilação dos assets

Na pasta do plugin instale as dependências com `npm install`. Para rodar o compilador em formato de desenvolvimento, execute `npm run dev`. E para compilar os assets para produção, execute `npm run production`.

## Sugestão de fluxo de importação

- Importar arquivos da pasta /uploads, via rSync ou FTP;
- Importar taxonomias e seus respectivos termos;
- Importar usuários;
- Importar CoAuthors;
- Importar posts;
- Rodar o corretor de relacionamento de imagens;
- Rodar o search/replace.

## Comandos WP CLI disponíveis

### wp run-import

Executa o fluxo completo de importação: busca posts no ambiente remoto, importa/atualiza localmente, processa mídia (opcional) e executa callbacks pré/pós (opcionais).
Os argumentos de busca usam o prefixo q: — os demais controlam o comportamento da importação.

#### Exemplos

Importar 50 posts publicados

`wp run-import q:numberposts=50 q:post_status=publish`

Importar posts de tipos específicos

`wp run-import q:post_type=post,page q:numberposts=100`

Simular a importação (sem gravar nada)

`wp run-import dry_run=1 q:numberposts=200`

Importar apenas IDs específicos

`wp run-import q:include=10,20,30`

Executar callbacks antes e depois da importação

`wp run-import fn_pre=\\Utils\\change_post_type fn_pos=fn_pre=\\Utils\\fix_metadata`

Reescrever URLs de mídia antigas durante a importação

`wp run-import uploads_base=https://site-antigo.com/wp-content/uploads`

Importar por data de modificação

`wp run-import q:post_modified_gmt=2024-01-01`
