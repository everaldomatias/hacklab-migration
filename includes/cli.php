<?php

namespace HacklabMigration;

if ( ! class_exists( '\WP_CLI' ) ) {
    return;
}

class Commands {
    /**
     * Registra os comandos do WP-CLI definidos nesta classe.
     * Cada método que começa com 'cmd_' será registrado como um comando do WP-CLI.
     * O nome do comando será gerado a partir do nome do método, substituindo 'cmd_' e '_' por '-'.
     */
    static function register() {
        foreach( get_class_methods( self::class ) as $method_name ) {
            if ( strpos($method_name, 'cmd_' ) === 0 ){
                $command = str_replace( ['cmd_', '_'], ['', '-'], $method_name );
                \WP_CLI::add_command( $command, [self::class, $method_name] );
            }
        }
    }

    // wp run-import q:post_type=post q:numberposts=20 dry_run=1
    static function cmd_run_import( $args, $assoc_args ) {
        // $args = argumentos posicionais
        // $assoc_args = flags: --q:*, --dry_run, --media, etc.

        $fetch   = [];
        $options = [];

        $remote_blog_id = isset( $args[0] ) ? (int) $args[0] : 1;

        $fetch_defaults = [
            'blog_id'     => $remote_blog_id,
            'post_type'   => 'post',
            'post_status' => 'publish',
            'numberposts' => 10,
            'offset'      => 0,
            'orderby'     => 'post_date',
            'order'       => 'DESC',
        ];

        $fetch = $fetch_defaults;

        $tax_query_clauses  = [];
        $tax_query_relation = 'AND';

        foreach ( $assoc_args as $argument_name => $argument_value ) {
            $argument_value = is_string( $argument_value )
                ? str_replace( '+', ' ', $argument_value )
                : $argument_value;

            if ( strpos( $argument_name, 'q:' ) === 0 ) {
                $key = substr( $argument_name, 2 ); // remove "q:"

                switch ( $key ) {
                    case 'tax_query':
                        // --q:tax_query=category:apto
                        // --q:tax_query=category:apto,terreo
                        // --q:tax_query="category:apto;post_tag:futebol"
                        $raw = (string) $argument_value;
                        $raw = trim( $raw );
                        if ( $raw === '' ) {
                            break;
                        }

                        $parts = array_filter(
                            array_map( 'trim', explode( ';', $raw ) )
                        );

                        foreach ( $parts as $tax_query_part ) {
                            [ $taxonomy, $terms_str ] = array_pad(
                                explode( ':', $tax_query_part, 2 ),
                                2,
                                ''
                            );

                            $taxonomy = trim( $taxonomy );
                            $terms    = array_filter(
                                array_map( 'trim', explode( ',', (string) $terms_str ) )
                            );

                            if ( $taxonomy === '' || ! $terms ) {
                                continue;
                            }

                            $tax_query_clauses[] = [
                                'taxonomy' => $taxonomy,
                                'field'    => 'slug',
                                'terms'    => $terms,
                            ];
                        }
                        break;

                    case 'relation':
                        $rel = strtoupper( (string) $argument_value );
                        $tax_query_relation = in_array( $rel, [ 'AND', 'OR' ], true ) ? $rel : 'AND';
                        break;

                    case 'blog_id':
                        $fetch['blog_id'] = (int) $argument_value;
                        break;

                    case 'post_type':
                    case 'post_status':
                        $fetch[ $key ] = self::csv_or_scalar( $argument_value );
                        break;

                    case 'numberposts':
                    case 'limit':
                    case 'offset':
                        $fetch[ $key ] = max( 0, (int) $argument_value );
                        break;

                    case 'orderby':
                    case 'order':
                    case 'search':
                        $fetch[ $key ] = (string) $argument_value;
                        break;

                    case 'include':
                    case 'exclude':
                        $fetch[ $key ] = self::csv_ints( $argument_value );
                        break;

                    case 'post_modified_gmt':
                        // aceita timestamp ou string; get_remote_posts trata depois
                        if ( is_numeric( $argument_value ) ) {
                            $fetch['post_modified_gmt'] = (int) $argument_value;
                        } else {
                            $fetch['post_modified_gmt'] = (string) $argument_value;
                        }
                        break;

                    case 'modified_after':
                    case 'modified_before':
                        if ( is_numeric( $argument_value ) ) {
                            $fetch[ $key ] = (int) $argument_value;
                        } else {
                            $fetch[ $key ] = (string) $argument_value;
                        }
                        break;

                    case 'id_gte': // maior ou igual  ≥ )
                    case 'id_lte': // menor ou igual ( ≤ )
                        $fetch[ $key ] = (int) $argument_value;
                        break;

                    default:
                        // passa qualquer outro q: direto
                        $fetch[ $key ] = $argument_value;
                        break;
                }

                continue;
            }

            switch ( $argument_name ) { // iniciam com "--"
                case 'dry_run':
                case 'dry-run':
                    $options['dry_run'] = self::to_bool( $argument_value ?? true );
                    break;

                case 'media':
                case 'with_media':
                    // media/with_media = 0 ou false => não processa mídia
                    $options['with_media'] = self::to_bool( $argument_value, true );
                    break;

                case 'fn_pre':
                case 'fn-pre':
                    if ( is_string( $argument_value ) && $argument_value !== '' ) {
                        $options['fn_pre'] = $argument_value;
                    }
                    break;

                case 'fn_pos':
                case 'fn-pos':
                    if ( is_string( $argument_value ) && $argument_value !== '' ) {
                        $options['fn_pos'] = $argument_value;
                    }
                    break;

                case 'uploads_base':
                case 'old-uploads-base':
                    if ( is_string( $argument_value ) && $argument_value !== '' ) {
                        $options['uploads_base'] = $argument_value;
                    }
                    break;

                case 'write_mode':
                    if ( is_string( $argument_value ) && $argument_value !== '' ) {
                        // Determina o modo de importação, insere novos posts, atualiza posts existentes ou insere e atualiza quando possível
                        $options['write_mode'] = in_array( $argument_value, [ 'insert', 'update', 'upsert' ], true ) ? $argument_value : 'upsert';
                    }

                    break;

                default:
                    break;
            }
        }

        /**
         * Retorna posts de todos status quando recebe `any` como parâmetro
         *
         * @link https://developer.wordpress.org/reference/classes/wp_query/#status-parameters
         */
        if ( $fetch['post_status'] === 'any' ) {
            $fetch['post_status'] = ['publish', 'pending', 'draft', 'future', 'private'];
        }

        if ( $tax_query_clauses ) {
            if ( count( $tax_query_clauses ) === 1 ) {
                $fetch['tax_query'] = $tax_query_clauses[0];
            } else {
                $fetch['tax_query'] = array_merge(
                    [ 'relation' => $tax_query_relation ],
                    $tax_query_clauses
                );
            }
        }

        if ( ! array_key_exists( 'with_media', $options ) ) {
            $options['with_media'] = true;
        }

        if ( ! array_key_exists( 'dry_run', $options ) ) {
            $options['dry_run'] = false;
        }

        $options['fetch'] = $fetch;

        if ( ! empty( $options['fn_pre'] ) && ! is_callable( $options['fn_pre'] ) ) {
            \WP_CLI::error( sprintf( 'callback_pre não é callable: %s', $options['fn_pre'] ) );
        }

        if ( ! empty( $options['fn_pos'] ) && ! is_callable( $options['fn_pos'] ) ) {
            \WP_CLI::error( sprintf( 'callback_pos não é callable: %s', $options['fn_pos'] ) );
        }

        \WP_CLI::line();
        \WP_CLI::log( 'Iniciando run_import()...' );
        \WP_CLI::line();

        $summary = run_import( $options );

        \WP_CLI::line();
        \WP_CLI::log( 'Posts encontrados, iniciando importação no WP local...' );
        \WP_CLI::line();

        if ( is_wp_error( $summary ) ) {
            \WP_CLI::error( $summary->get_error_message() );
        }

        if ( ! is_array( $summary ) ) {
            \WP_CLI::error( 'run_import() retornou um resultado inválido.' );
        }

        $posts       = $summary['posts'] ?? [];
        $attachments = $summary['attachments'] ?? [];

        $posts_data = [
            'found_posts' => (int) ( $posts['found_posts'] ?? 0 ),
            'imported'    => (int) ( $posts['imported'] ?? 0 ),
            'updated'     => (int) ( $posts['updated'] ?? 0 ),
            'skipped'     => (int) ( $posts['skipped'] ?? 0 ),
        ];

        $attachments_data = [
            'content_rewritten' => $attachments['content_rewritten'] ?? 0,
            'found_posts'       => $attachments['found_posts'] ?? 0,
            'registered'        => $attachments['registered'] ?? 0,
            'reused'            => $attachments['reused'] ?? 0,
            'thumbnails_set'    => $attachments['thumbnails_set'] ?? 0,
            'missing_files'     => count( (array) $attachments['missing_files'] ) ?? 0
        ];

        $separator = str_repeat( '#', 59 );

        \WP_CLI::line();
        \WP_CLI::line( $separator );
        \WP_CLI::line( 'Posts:' );

        foreach ( $posts_data as $label => $value ) {
            \WP_CLI::log( sprintf( '%s: %s', $label, $value ) );
        }

        \WP_CLI::line( $separator );
        \WP_CLI::line();
        \WP_CLI::line( $separator );
        \WP_CLI::line( 'ANEXOS:' );

        foreach ( $attachments_data as $label => $value ) {
            \WP_CLI::log( sprintf( '%s: %s', $label, $value ) );
        }

        if ( ! empty( $attachments['missing_files'] ) ) {
            \WP_CLI::line( $separator );
            \WP_CLI::line();
            \WP_CLI::line( $separator );
            \WP_CLI::line( 'ANEXOS NÃO ENCONTRADOS:' );

            foreach ( (array) $attachments['missing_files'] as $rid => $file ) {
                $rid_label = is_int( $rid ) ? "remote {$rid}" : $rid;
                \WP_CLI::warning( sprintf( '  %s => %s', $rid_label, $file ) );
            }
            \WP_CLI::line( $separator );
        }

        if ( ! empty( $posts['map'] ) && is_array( $posts['map'] ) ) {
            \WP_CLI::line();
            \WP_CLI::line( $separator );

            \WP_CLI::line( 'Local posts (ID => post_modified_gmt):' );
            foreach ( $posts['map'] as $rid => $lid ) {
                $lid = (int) $lid;
                if ( $lid <= 0 ) {
                    continue;
                }
                $modified = get_post_field( 'post_modified_gmt', $lid );
                \WP_CLI::log( sprintf( '  %d => %s', $lid, (string) $modified ) );
            }
            \WP_CLI::line( $separator );
        }

        if ( ! empty( $summary['errors'] ) ) {
            \WP_CLI::line( 'Errors:' );
            foreach ( (array) $summary['errors'] as $err ) {
                \WP_CLI::warning( (string) $err );
            }
            \WP_CLI::line( $separator );
        } else {
            \WP_CLI::line();
        }

        if ( ! empty( $options['dry_run'] ) ) {
            \WP_CLI::success( 'Dry-run concluído.' );
        } else {
            \WP_CLI::success( 'Importação concluída.' );
        }
    }

    /**
     * Lista posts no remoto aplicando os mesmos filtros do run-import, sem gravar no WP local.
     *
     * Exemplos:
     *   wp list-remote-posts --q:post_type=post --q:numberposts=100 --q:order=ASC --q:orderby=ID
     *   wp list-remote-posts --q:post_type=post --q:modified_after="2024-01-01 00:00:00" --q:numberposts=50
     *
     * @param array $args
     * @param array $assoc_args
     */
    static function cmd_list_remote_posts( $args, $assoc_args ) {
        $fetch   = [];

        foreach ( $assoc_args as $argument_name => $argument_value ) {
            $argument_value = is_string( $argument_value )
                ? str_replace( '+', ' ', $argument_value )
                : $argument_value;

            if ( strpos( $argument_name, 'q:' ) === 0 ) {
                $key = substr( $argument_name, 2 ); // remove "q:"

                switch ( $key ) {
                    case 'tax_query':
                        // --q:tax_query=category:apto
                        // --q:tax_query=category:apto,terreo
                        // --q:tax_query="category:apto;post_tag:futebol"
                        $raw = (string) $argument_value;
                        $raw = trim( $raw );
                        if ( $raw === '' ) {
                            break;
                        }

                        $parts = array_filter(
                            array_map( 'trim', explode( ';', $raw ) )
                        );

                        $tax_query_clauses  = [];
                        $tax_query_relation = 'AND';

                        foreach ( $parts as $tax_query_part ) {
                            [ $taxonomy, $terms_str ] = array_pad(
                                explode( ':', $tax_query_part, 2 ),
                                2,
                                ''
                            );

                            $taxonomy = trim( $taxonomy );
                            $terms    = array_filter(
                                array_map( 'trim', explode( ',', (string) $terms_str ) )
                            );

                            if ( $taxonomy === '' || ! $terms ) {
                                continue;
                            }

                            $tax_query_clauses[] = [
                                'taxonomy' => $taxonomy,
                                'field'    => 'slug',
                                'terms'    => $terms,
                            ];
                        }

                        if ( $tax_query_clauses ) {
                            if ( count( $tax_query_clauses ) === 1 ) {
                                $fetch['tax_query'] = $tax_query_clauses[0];
                            } else {
                                $fetch['tax_query'] = array_merge(
                                    [ 'relation' => $tax_query_relation ],
                                    $tax_query_clauses
                                );
                            }
                        }
                        break;

                    case 'post_type':
                    case 'post_status':
                        $fetch[ $key ] = self::csv_or_scalar( $argument_value );
                        break;

                    case 'numberposts':
                    case 'limit':
                        $fetch[ $key ] = max( 0, (int) $argument_value );
                        break;

                    case 'offset':
                        $fetch[ $key ] = max( 0, (int) $argument_value );
                        break;

                    case 'orderby':
                    case 'order':
                    case 'search':
                    case 'modified_after':
                    case 'modified_before':
                    case 'post_modified_gmt':
                        $fetch[ $key ] = (string) $argument_value;
                        break;

                    case 'include':
                    case 'exclude':
                        $fetch[ $key ] = self::csv_ints( $argument_value );
                        break;

                    case 'id_gte':
                    case 'id_lte':
                        $fetch[ $key ] = (int) $argument_value;
                        break;

                    case 'blog_id':
                        $fetch['blog_id'] = (int) $argument_value;
                        break;

                    default:
                        $fetch[ $key ] = $argument_value;
                        break;
                }
            }
        }

        if ( empty( $fetch['post_status'] ) ) {
            $fetch['post_status'] = ['publish', 'pending', 'draft', 'future', 'private'];
        }

        $rows = get_remote_posts( $fetch );
        if ( is_wp_error( $rows ) ) {
            \WP_CLI::error( $rows->get_error_message() );
        }

        if ( ! $rows ) {
            \WP_CLI::success( 'Nenhum post encontrado.' );
            return;
        }

        \WP_CLI::log( sprintf( 'POSTS ENCONTRADOS: %d', count( $rows ) ) );

        foreach ( $rows as $row ) {
            $rid       = (int) ( $row['ID'] ?? 0 );
            $title     = (string) ( $row['post_title'] ?? '' );
            $modified  = (string) ( $row['post_modified_gmt'] ?? '' );
            \WP_CLI::line( sprintf( '%d | %s | %s', $rid, $title, $modified ) );
        }
    }

    /**
     * Importa um único usuário do banco remoto para o WordPress local.
     *
     * Essa subcomando é útil para casos em que você precisa garantir que um usuário
     * específico exista no ambiente local — por exemplo, ao mapear autores ou o
     * metadado `_edit_last` durante a migração de posts.
     *
     * ## OPTIONS
     *
     * <remote_user_id>
     * : ID do usuário no banco remoto. Obrigatório.
     *
     * [--blog_id=<id>]
     * : ID do blog remoto em instalações multisite. Em um multisite clássico,
     *   esse valor corresponde ao número do site (ex.: 2, 3, 4).
     *   Default: 1.
     *
     * [--dry_run]
     * : Executa em modo de simulação. Nenhuma alteração será gravada no banco
     *   local; o comando apenas resolve o usuário e retorna o ID que seria
     *   utilizado.
     *
     * ## EXAMPLES
     *
     *     # Importa o usuário remoto de ID 123 do blog 1 (single site ou blog principal):
     *     wp import-user 123
     *
     *     # Importa o usuário remoto de ID 456 do blog 4 em um multisite:
     *     wp import-user 456 --blog_id=4
     *
     *     # Simula a importação do usuário remoto 789, sem gravar nada:
     *     wp import-user 789 --blog_id=2 --dry_run
     *
     * @param array $args         Argumentos posicionais (ex.: [ <remote_user_id> ]).
     * @param array $command_args Argumentos nomeados/associativos (ex.: [ 'blog_id' => 2, 'dry_run' => true ]).
     *
     * @return void
     */
    static function cmd_import_user( $args, $command_args ) {
        $defaults = [
            'blog_id'        => 1,
            'dry_run'        => false
        ];

        if ( empty( $args[0] ) ) {
            \WP_CLI::error( 'Você deve informar <remote_user_id>. Ex: wp import-user 123 --blog_id=1' );
        }

        $remote_user_id = (int) $args[0];

        if ( $remote_user_id <= 0 ) {
            \WP_CLI::error( 'O <remote_user_id> precisa ser um inteiro positivo.' );
        }

        $options = wp_parse_args( $command_args, $defaults );

        $blog_id = (int) $options['blog_id'];
        $dry_run = \WP_CLI\Utils\get_flag_value( $command_args, 'dry_run', false );

        $result = import_remote_user( $remote_user_id, $blog_id, $dry_run );

        if ( $result ) {
            \WP_CLI::success( "Usuário importado/atualizado com sucesso! ID: $result" );
        } else {
            \WP_CLI::error( 'Não foi possível importar o usuário.' );
        }
    }

    /**
     * Importa usuários em lote do banco remoto para o WordPress local.
     *
     * Esse comando consome a função import_remote_users() e permite importar
     * usuários de forma massiva, com filtros opcionais por blog (em multisite),
     * lista de IDs a incluir e lista de IDs a excluir.
     *
     * [<blog_id>]
     * : ID do blog remoto em instalações multisite. Se omitido, importa de todos.
     *
     * ## OPTIONS
     *
     * [--include_ids=<ids>]
     * : Lista de IDs remotos a incluir, separados por vírgula. Ex.: "10,20,30".
     *   Se informado, apenas esses IDs serão considerados.
     *
     * [--exclude_ids=<ids>]
     * : Lista de IDs remotos a excluir, separados por vírgula. Ex.: "5,6,7".
     *
     * [--chunk=<n>]
     * : Tamanho do lote (chunk) de usuários processados por iteração. Útil
     *   para controlar o consumo de memória e o tempo de execução.
     *   Default: 500.
     *
     * [--dry_run]
     * : Executa em modo de simulação. Nenhuma alteração será gravada no banco
     *   local; o comando apenas calcula os usuários que seriam importados/
     *   atualizados e retorna o resumo.
     *
     * ## EXAMPLES
     *
     *     # Importa todos os usuários do blog 4 (multisite remoto):
     *     wp import-users 4
     *
     *     # Importa apenas usuários específicos (IDs 10, 20, 30) do blog 2:
     *     wp import-users 2 --include_ids=10,20,30
     *
     *     # Importa todos os usuários exceto os IDs 5,6,7:
     *     wp import-users --exclude_ids=5,6,7
     *
     *     # Executa em modo de simulação (sem gravar nada) para o blog 3:
     *     wp import-users 3 --dry_run
     *
     * @param array $args         Argumentos posicionais ([<blog_id>]).
     * @param array $command_args Argumentos nomeados/associativos (include_ids, exclude_ids, chunk, dry_run).
     *
     * @return void
     */
    static function cmd_import_users( $args, $command_args ) {
        $defaults = [
            'include_ids' => '',
            'exclude_ids' => '',
            'chunk'       => 500,
            'dry_run'     => false
        ];

        $options = wp_parse_args( $command_args, $defaults );

        $blog_id = isset( $args[0] ) ? (int) $args[0] : 1;

        $include_ids = [];

        if ( ! empty( $options['include_ids'] ) ) {
            $include_ids = explode( ',', $options['include_ids'] );
            $include_ids = array_map( 'intval', $include_ids );
        }

        $exclude_ids = [];

        if ( ! empty( $options['exclude_ids'] ) ) {
            $exclude_ids = explode( ',', $options['exclude_ids'] );
            $exclude_ids = array_map( 'intval', $exclude_ids );
        }

        $chunk = max( 1, (int) $options['chunk'] );
        $dry_run = \WP_CLI\Utils\get_flag_value( $command_args, 'dry_run', false );

        \WP_CLI::log( 'Iniciando importação de usuários remotos...' );

        if ( $dry_run ) {
            \WP_CLI::log( 'Modo: DRY RUN (simulação, nenhuma alteração será gravada).' );
        }

        $result = import_remote_users( [
            'blog_id'     => $blog_id,
            'include_ids' => $include_ids,
            'exclude_ids' => $exclude_ids,
            'chunk'       => $chunk,
            'dry_run'     => $dry_run
        ] );

        \WP_CLI::log( '' );
        \WP_CLI::log( 'Resumo da importação:' );
        \WP_CLI::log( '  Usuários encontrados no remoto: ' . (int) $result['found_users'] );
        \WP_CLI::log( '  Usuários importados (novos):   ' . (int) $result['imported'] );
        \WP_CLI::log( '  Usuários atualizados:          ' . (int) $result['updated'] );

        if ( ! empty( $result['errors'] ) && is_array( $result['errors'] ) ) {
            \WP_CLI::log( '' );
            \WP_CLI::warning( 'Alguns usuários não puderam ser processados:' );

            foreach ( $result['errors'] as $remote_id => $message ) {
                // $remote_id pode ser numérico ou string, dependendo de como você preenche
                \WP_CLI::warning( sprintf( '  [Remoto %s] %s', $remote_id, $message ) );
            }
        }

        if ( $dry_run ) {
            \WP_CLI::success( 'Simulação concluída. Nenhuma alteração foi gravada no banco local.' );
        } else {
            \WP_CLI::success( 'Importação de usuários concluída.' );
        }
    }

    static function cmd_import_terms( $args, $command_args ) {
        $options = wp_parse_args( $command_args, [
            'blog_id'     => null,
            'taxonomies'  => '',
            'include_ids' => '',
            'exclude_ids' => '',
            'chunk'       => 500,
            'dry_run'     => false,
            'fn_pre'      => '',
            'fn_pos'      => '',
        ] );

        $taxonomies = [];
        if ( ! empty( $options['taxonomies'] ) ) {
            $taxonomies = array_map( 'sanitize_key', explode( ',', $options['taxonomies'] ) );
        }

        $include_ids = [];
        if ( ! empty( $options['include_ids'] ) ) {
            $include_ids = array_map( 'intval', explode( ',', $options['include_ids'] ) );
        }

        $exclude_ids = [];
        if ( ! empty( $options['exclude_ids'] ) ) {
            $exclude_ids = array_map( 'intval', explode( ',', $options['exclude_ids'] ) );
        }

        $fn_pre = ! empty( $options['fn_pre'] ) ? (string) $options['fn_pre'] : null;
        $fn_pos = ! empty( $options['fn_pos'] ) ? (string) $options['fn_pos'] : null;

        if ( $fn_pre && ! is_callable( $fn_pre ) ) {
            \WP_CLI::error( "A função fn_pre informada não é callable: {$fn_pre}" );
        }

        if ( $fn_pos && ! is_callable( $fn_pos ) ) {
            \WP_CLI::error( "A função fn_pos informada não é callable: {$fn_pos}" );
        }

        $args_import = [
            'blog_id'     => $options['blog_id'] ? (int) $options['blog_id'] : null,
            'taxonomies'  => $taxonomies,
            'include_ids' => $include_ids,
            'exclude_ids' => $exclude_ids,
            'chunk'       => (int) $options['chunk'],
            'dry_run'     => \WP_CLI\Utils\get_flag_value( $options, 'dry_run', false ),
            'fn_pre'      => $fn_pre,
            'fn_pos'      => $fn_pos
        ];

        \WP_CLI::log( "Iniciando importação de termos..." );
        $result = import_remote_terms( $args_import );

        $errors_total = 0;
        foreach ( $result['errors'] as $rid => $msgs ) {
            if ( is_array( $msgs ) ) {
                $errors_total += count( $msgs );
            } elseif ( ! empty( $msgs ) ) {
                $errors_total++;
            }
        }

        \WP_CLI::log( "" );
        \WP_CLI::line( "====================== RESULTADO ======================" );
        \WP_CLI::line( "Termos encontrados: " . $result['found_terms'] );
        \WP_CLI::line( "Importados:         " . $result['imported'] );
        \WP_CLI::line( "Atualizados:        " . $result['updated'] );
        \WP_CLI::line( "--------------------------------------------------------" );
        \WP_CLI::line( "Erros (mensagens):  " . $errors_total );
        \WP_CLI::line( "========================================================" );

        if ( ! empty( $result['errors'] ) ) {
            \WP_CLI::warning( "Erros ocorreram durante a importação:" );

            foreach ( $result['errors'] as $rid => $msgs ) {
                if ( ! is_array( $msgs ) ) {
                    $msg = (string) $msgs;
                    if ( $msg === '' ) {
                        continue;
                    }

                    \WP_CLI::warning( $msg );
                    continue;
                }

                // Caso de lista de erros para um termo remoto específico
                foreach ( $msgs as $msg ) {
                    $msg = (string) $msg;
                    if ( $msg === '' ) {
                        continue;
                    }

                    \WP_CLI::warning( "Termo remoto {$rid}: {$msg}" );
                }
            }
        }

        \WP_CLI::success( "Processo concluído." );
    }

    // Helpers
    private static function csv_or_scalar( $value ) {
        if ( is_array( $value ) ) return $value;
        if ( is_string( $value ) && strpos( $value, ',' ) !== false ) {
            return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), static fn( $v ) => $v !== '' ) );
        }
        return $value;
    }

    private static function csv_ints( $value ): array {
        if ( is_array( $value ) ) {
            $vals = $value;
        } else {
            $vals = explode( ',', (string) $value );
        }
        return array_values(
            array_filter(
                array_map( 'intval', array_map( 'trim', $vals ) ),
                static fn( $v ) => $v > 0
            )
        );
    }

    private static function csv_strs( $value ): array {
        if ( is_array( $value ) ) {
            $vals = $value;
        } else {
            $vals = explode( ',', (string) $value );
        }
        return array_values(
            array_filter(
                array_map( 'trim', $vals ),
                static fn( $v ) => $v !== ''
            )
        );
    }

    private static function to_bool( $value, bool $default = false ): bool {
        if ( $value === null ) return $default;
        if ( is_bool( $value ) ) return $value;
        $v = strtolower( (string) $value );
        if ( $v === '' ) return $default;
        return in_array( $v, [ '1', 'true', 'yes', 'y', 'on' ], true );
    }
}

Commands::register();
