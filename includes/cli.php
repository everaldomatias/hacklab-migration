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
    static function cmd_run_import( $args, $command_args ) {
        if ( empty( $command_args ) && ! empty( $args ) ) {
            foreach ( $args as $arg ) {
                $parts = explode( '=', $arg, 2 );
                $k = $parts[0] ?? '';
                $v = $parts[1] ?? null;
                if ( $k === '' ) {
                    continue;
                }
                $command_args[ $k ] = $v;
            }
        }

        $fetch   = [];
        $options = [];

        $fetch_defaults = [
            'blog_id'     => 1,
            'post_type'   => 'post',
            'post_status' => 'publish',
            'numberposts' => 10,
            'offset'      => 0,
            'orderby'     => 'post_date',
            'order'       => 'DESC',
        ];
        $fetch = $fetch_defaults;

        foreach ( $command_args as $argument_name => $argument_value ) {
            $argument_value = is_string( $argument_value ) ? str_replace( '+', ' ', $argument_value ) : $argument_value;

            if ( strpos( $argument_name, 'q:' ) === 0 ) {
                $key = substr( $argument_name, 2 );

                switch ( $key ) {
                    case 'blog_id':
                        $fetch['blog_id'] = (int) $argument_value;
                        break;

                    case 'post_type':
                    case 'post_status':
                        $fetch[ $key ] = self::csv_or_scalar( $argument_value );
                        break;

                    case 'numberposts':
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

                    case 'with_meta':
                        $fetch['with_meta'] = self::to_bool( $argument_value );
                        break;

                    case 'meta_keys':
                        $fetch['meta_keys'] = self::csv_strs( $argument_value );
                        break;

                    case 'post_modified_gmt':
                        // aceita timestamp ou string; remote_get_posts trata depois
                        if ( is_numeric( $argument_value ) ) {
                            $fetch['post_modified_gmt'] = (int) $argument_value;
                        } else {
                            $fetch['post_modified_gmt'] = (string) $argument_value;
                        }
                        break;

                    default:
                        // passa qualquer outro q: direto
                        $fetch[ $key ] = $argument_value;
                        break;
                }

                continue;
            }

            switch ( $argument_name ) {
                case 'dry_run':
                case 'dry-run':
                    $options['dry_run'] = self::to_bool( $argument_value ?? true );
                    break;

                case 'media':
                    // media = 0 ou media = false => não processa mídia
                    $options['media'] = self::to_bool( $argument_value, true );
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

                default:
                    break;
            }
        }

        if ( ! array_key_exists( 'media', $options ) ) {
            $options['media'] = true;
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

        \WP_CLI::log( 'Iniciando run_import()...' );
        $summary = run_import( $options );

        if ( ! empty( $summary['errors'] ) && is_array( $summary['errors'] ) ) {
            foreach ( $summary['errors'] as $err ) {
                \WP_CLI::warning( (string) $err );
            }
        }

        $posts = $summary['posts'] ?? [];
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
            'missing_files'     => implode( ', ', $attachments['missing_files'] ?? [] ),
        ];

        $separator = str_repeat('#', 59);

        \WP_CLI::line();
        \WP_CLI::line( $separator );
        \WP_CLI::line('Posts:');

        foreach ( $posts_data as $label => $value ) {
            \WP_CLI::log(sprintf('%s: %s', $label, $value));
        }

        \WP_CLI::line( $separator );
        \WP_CLI::line();
        \WP_CLI::line( $separator );
        \WP_CLI::line('Attachments:');

        foreach ( $attachments_data as $label => $value ) {
            \WP_CLI::log( sprintf( '%s: %s', $label, $value ) );
        }

        \WP_CLI::line( $separator );
        \WP_CLI::line();

        if ( ! empty( $summary['map'] ) && is_array( $summary['map'] ) ) {
            \WP_CLI::log( 'Map (remote_id => local_id):' );
            foreach ( $summary['map'] as $rid => $lid ) {
                \WP_CLI::log( sprintf( '  %d => %d', (int) $rid, (int) $lid ) );
            }
        }

        if ( ! empty( $options['dry_run'] ) ) {
            \WP_CLI::success( 'Dry-run concluído.' );
        } else {
            \WP_CLI::success( 'Importação concluída.' );
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
            \WP_CLI::error( 'Você deve informar <remote_user_id>. Ex: wp run-import-user 123 --blog_id=1' );
        }

        $remote_user_id = (int) $args[0];

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
     * ## OPTIONS
     *
     * [--blog_id=<id>]
     * : ID do blog remoto em instalações multisite. Em um multisite clássico,
     *   esse valor corresponde ao número do site (ex.: 2, 3, 4). Se omitido,
     *   todos os usuários da tabela remota serão considerados (sem filtro
     *   por capabilities de blog).
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
     *     wp import-users --blog_id=4
     *
     *     # Importa apenas usuários específicos (IDs 10, 20, 30) do blog 2:
     *     wp import-users --blog_id=2 --include_ids=10,20,30
     *
     *     # Importa todos os usuários exceto os IDs 5,6,7:
     *     wp import-users --exclude_ids=5,6,7
     *
     *     # Executa em modo de simulação (sem gravar nada) para o blog 3:
     *     wp import-users --blog_id=3 --dry_run
     *
     * @param array $args         Argumentos posicionais (não utilizados neste comando).
     * @param array $command_args Argumentos nomeados/associativos (blog_id, include_ids, exclude_ids, chunk, dry_run).
     *
     * @return void
     */
    static function cmd_import_users( $args, $command_args ) {
        $defaults = [
            'blog_id'     => null,
            'include_ids' => '',
            'exclude_ids' => '',
            'chunk'       => 500,
            'dry_run'     => false
        ];

        $options = wp_parse_args( $command_args, $defaults );

        $blog_id = null;

        if ( ! empty( $options['blog_id'] ) ) {
            $blog_id = (int) $options['blog_id'];
        }

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
