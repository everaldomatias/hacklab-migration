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

Importar somente a 1ª etapa (posts brutos), para um post type temporário e sem termos/mídia:

`wp run-import target_post_type=migration assign_terms=0 media=0 q:numberposts=200`

Modificar posts na importação:

- Meta extra: `wp run-import meta:campo_extra="valor"`
- Forçar post type: `wp run-import post_type:conteudo`
- Adicionar termos: `wp run-import add:category="Noticias,Eventos"`
- Substituir termos: `wp run-import set:tag="tag1,tag2"`
- Remover termos: `wp run-import rm:category="Antigo"`

Aplicar regras DE/PARA após importação (usando hacklab-dev-utils):

- Defina os caminhos no `wp-config.php`: `HACKLAB_MIGRATION_DE_CSV_PATH` e `HACKLAB_MIGRATION_PARA_CSV_PATH`
- Execute: `wp modify-posts --q:post_type=migration --fn:\\HacklabMigration\\apply_de_para_from_csv`

Atualizar autor local (a partir de `_hacklab_migration_remote_author`):

- Execute: `wp modify-posts --q:post_type=migration --q:meta_query="_hacklab_migration_source_id:415:=" --fn:\\HacklabMigration\\map_remote_author_to_local`

Rastrear execuções de importação (run_id):

- A cada `run-import` (e também `import-user` / `import-terms`), o plugin gera um ID sequencial e grava em `_hacklab_migration_import_run_id` nos itens tocados (posts, attachments, users e termos).
- Exemplo de rollback (posts): `SELECT ID FROM wp_postmeta WHERE meta_key = '_hacklab_migration_import_run_id' AND meta_value = 42;`

Importar single ➜ single junto com multisite já importados (evitar colisão de blog_id):

- Se você já usou `blog_id=1` para o site principal da rede e quer importar outro single sem colidir, use `--force_base_prefix=1` com um `blog_id` lógico diferente (ex.: 99). Exemplo:
  `wp run-import 99 --force_base_prefix=1 --q:post_type=post --post_type:migration ...`
  Isso consulta as tabelas base (`wp_posts/wp_postmeta`) e grava `_hacklab_migration_source_blog=99`, evitando conflito com importações anteriores.
- A flag `--force_base_prefix` também está disponível em `import-user` e `import-terms`.
- Atenção: termos continuam sendo deduplicados por slug/nome, e attachments são deduplicados por `_wp_attached_file` (com prefixo de blog no nome quando aplicável). O `force_base_prefix` só evita olhar tabelas `wp_X_`.

Importar por data de modificação

`wp run-import q:post_modified_gmt=2024-01-01`
