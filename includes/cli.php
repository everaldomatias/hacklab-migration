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

                case 'old_uploads_base':
                case 'old-uploads-base':
                    if ( is_string( $argument_value ) && $argument_value !== '' ) {
                        $options['old_uploads_base'] = $argument_value;
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
