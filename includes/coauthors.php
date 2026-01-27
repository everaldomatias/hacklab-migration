<?php
/**
 * Utility functions for Hacklab Migration plugin
 *
 * @package HacklabMigration
 */

namespace HacklabMigration;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function cap_instance() {
    global $coauthors_plus;
    return ( is_object( $coauthors_plus ) && method_exists( $coauthors_plus, 'add_coauthors' ) ) ? $coauthors_plus : null;
}

function cap_create_guest_author( string $slug, string $display_name, ?string $email = null ): string {
    $slug = sanitize_title( $slug );
    if ( $slug === '' ) return '';

    $user_login = sanitize_user( $slug, true ) ?: sanitize_key( $slug );
    $display    = $display_name !== '' ? $display_name : $slug;

    $ga_id = wp_insert_post( [
        'post_type'   => 'guest-author',
        'post_status' => 'publish',
        'post_title'  => $display,
        'post_name'   => $slug
    ], true);

    if ( is_wp_error($ga_id) || $ga_id <= 0 ) {
        return '';
    }

    update_post_meta( $ga_id, 'cap-display_name', $display );
    update_post_meta( $ga_id, 'cap-user_login',   $user_login );

    if ( $email ) {
        update_post_meta( $ga_id, 'cap-user_email', sanitize_email( $email ) );
    }

    cap_ensure_guest_author_term( (int) $ga_id, $slug, $display );

    return $user_login;
}

function cap_coauthor_taxonomy(): string {
    $cap = cap_instance();
    if ( $cap && property_exists( $cap, 'coauthor_taxonomy' ) && is_string( $cap->coauthor_taxonomy ) ) {
        return $cap->coauthor_taxonomy;
    }
    return 'author';
}

function cap_ensure_guest_author_term( int $guest_author_id, string $slug, string $display ): void {
    $slug = sanitize_title( $slug );
    if ( $slug === '' ) return;

    $tax = cap_coauthor_taxonomy();
    if ( ! taxonomy_exists( $tax ) ) return;

    $display = trim( $display ) !== '' ? $display : $slug;

    $term = get_term_by( 'slug', $slug, $tax );
    if ( ! $term ) {
        $res = wp_insert_term( $display, $tax, [ 'slug' => $slug ] );
        if ( is_wp_error( $res ) || empty( $res['term_id'] ) ) {
            return;
        }
        $term_id = (int) $res['term_id'];
    } else {
        $term_id = (int) ( $term->term_id ?? 0 );
    }

    if ( $term_id > 0 ) {
        update_post_meta( $guest_author_id, 'cap-term-id', $term_id );
    }
}

function cap_assign_coauthors_to_post( int $post_id, array $row_terms ): void {
    $cap = cap_instance();
    if ( ! $cap || empty( $row_terms['author'] ) ) {
        return;
    }

    $ga_enabled = method_exists( $cap, 'is_guest_authors_enabled' )
        ? (bool) $cap->is_guest_authors_enabled()
        : true;

    $idents = [];

    foreach ( (array) $row_terms['author'] as $a ) {
        $slug  = (string) ( $a['slug']  ?? '' );
        $login = (string) ( $a['login'] ?? '' );
        $email = (string) ( $a['email'] ?? '' );
        $name  = (string) ( $a['name']  ?? $slug );

        if ( $slug === '' ) continue;

        $co = $cap->get_coauthor_by( 'user_nicename', $slug );

        if ( ! $co && $login !== '' ) {
            $co = $cap->get_coauthor_by( 'login', $login );
        }

        if ( ! $co && $email !== '' ) {
            $co = $cap->get_coauthor_by( 'user_email', $email );
        }

        if ( ! $co && $ga_enabled ) {
            $created_login = cap_create_guest_author( $slug, $name, ( $email !== '' ? $email : null ) );
            if ( $created_login !== '' ) {
                $co = $cap->get_coauthor_by( 'user_nicename', $slug );
                if ( ! $co ) {
                    $idents[] = $created_login;
                    continue;
                }
            }
        }

        if ( $co && ! empty( $co->user_login ) ) {
            $idents[] = $co->user_login;
        }
    }

    $idents = array_values( array_unique( array_filter( $idents ) ) );
    if ( ! $idents ) return;

    $cap->add_coauthors( $post_id, $idents, false );
}

/**
 * Callback para run-import: converte um post importado para guest-author do CoAuthors Plus.
 *
 * Uso sugerido:
 *   wp run-import --post_type=guest-author --fn_pos=\\HacklabMigration\\cap_convert_post_to_guest_author ...
 */
function cap_convert_post_to_guest_author( int $local_id, array $row, bool $is_update, bool $dry_run ): void {
    if ( $dry_run ) {
        return;
    }

    $post = get_post( $local_id );
    if ( ! $post instanceof \WP_Post ) {
        return;
    }

    $source_meta = get_post_meta( $local_id, '_hacklab_migration_source_meta', true );
    if ( ! is_array( $source_meta ) ) {
        $source_meta = is_array( $row['post_meta'] ?? null ) ? $row['post_meta'] : [];
    }

    $source_id = (int) ( $row['ID'] ?? 0 );
    $source_blog = (int) ( $row['blog_id'] ?? 1 );

    if ( $source_id ) {
        update_post_meta( $local_id, '_hacklab_migration_source_id', $source_id );
    }

    update_post_meta( $local_id, '_hacklab_migration_source_blog', $source_blog );

    // Preenche metas esperadas pelo CoAuthors Plus para guest-author.
    if ( function_exists( __NAMESPACE__ . '\\ensure_guest_author_post' ) && $post instanceof \WP_Post ) {
        ensure_guest_author_post( $post, $source_meta );
    }

    $post = get_post( $local_id ); // recarrega para usar título/slug possivelmente ajustados

    $slug = sanitize_title(
        $post instanceof \WP_Post
            ? ( $post->post_name !== '' ? $post->post_name : ( $post->post_title !== '' ? $post->post_title : $post->ID ) )
            : $local_id
    );

    if ( $post instanceof \WP_Post ) {
        cap_ensure_guest_author_term( $local_id, $slug, (string) $post->post_title );
    }

    wp_update_post( [
        'ID'          => $local_id,
        'post_type'   => 'guest-author',
        'post_status' => 'publish',
        'post_name'   => $slug,
    ] );
}

/**
 * Importa coautores (guest-authors do CoAuthors Plus) do banco remoto.
 *
 * @param array $args {
 *     @type int      $blog_id           ID do blog remoto. Default: 1.
 *     @type int[]    $include_ids       IDs remotos a incluir.
 *     @type int[]    $exclude_ids       IDs remotos a excluir.
 *     @type int|null $limit             Limite de registros a buscar (null/0 para todos).
 *     @type int      $offset            Offset usado junto com limit.
 *     @type bool     $dry_run           Simula sem gravar.
 *     @type bool     $force_base_prefix Usa as tabelas base em instalações single.
 *     @type int      $run_id            ID do run de importação (gerado automaticamente se omitido).
 * }
 *
 * @return array Resumo retornado por import_remote_posts().
 */
function import_remote_coauthors( array $args = [] ): array {
    $defaults = [
        'fetch'        => [
            'blog_id'     => isset( $args['blog_id'] ) ? (int) $args['blog_id'] : 1,
            'post_type'   => 'guest-author',
            'numberposts' => isset( $args['limit'] ) ? (int) $args['limit'] : 10,
            'post_status' => 'any'
        ],
        'media'        => true,
        'dry_run'      => false,
        'fn_pre'       => null,
        'fn_pos'       => null,
        'assign_terms' => true,
        'map_users'    => true,
        'meta_ops'     => [],
        'term_add'     => [],
        'term_set'     => [],
        'term_rm'      => [],
        'target_post_type' => '',
        'force_base_prefix' => false,
        'uploads_base' => '',
        'write_mode'   => 'upsert',
        'run_id'       => 0
    ];

    $options = wp_parse_args( $args, $defaults );

    if ( $options['uploads_base'] === '' ) {
        $creds = get_credentials();
        if ( ! empty( $creds['uploads_base'] ) ) {
            $options['uploads_base'] = (string) $creds['uploads_base'];
        }
    }

    $fetch = (array) ( $options['fetch'] ?? [] );
    unset( $fetch['fields'] );
    $fetch['fields'] = 'all';
    $fetch['force_base_prefix'] = $options['force_base_prefix'];

    $options['fetch'] = $fetch;

    $summary = [
        'found_posts' => 0,
        'imported'    => 0,
        'updated'     => 0,
        'skipped'     => 0,
        'attachments' => 0,
        'map'         => [],
        'errors'      => [],
        'args'        => $fetch,
        'rows'        => []
    ];

    $rows = get_remote_posts( $fetch );

    if ( is_wp_error( $rows ) ) {
        $summary['errors'][] = $rows->get_error_message();
        return $summary;
    }

    if ( ! $rows ) {
        return $summary;
    }

    $blog_id = max( 1, (int) ( $options['fetch']['blog_id'] ?? ( $args['blog_id'] ?? 1 ) ) );

    $remote_ids = array_map( static fn( $r ) => (int) $r['ID'], $rows );
    $terms_map  = fetch_remote_terms_for_posts( $remote_ids, $blog_id, [], (bool) $options['force_base_prefix'] );

    foreach ( $rows as &$r ) {
        $rid = (int) $r['ID'];
        $r['remote_terms'] = $terms_map[ $rid ] ?? [];
    }
    unset( $r );

    $summary['found_posts'] = count( $rows );
    $summary['rows'] = $rows;

    foreach ( $rows as $row ) {
        $remote_id         = (int) $row['ID'];
        $remote_type_raw   = (string) ( $row['post_type'] ?? '' );
        $remote_type       = sanitize_key( $remote_type_raw );
        $remote_type_saved = $remote_type !== '' ? $remote_type : 'post';
        $post_name         = (string) ( $row['post_name'] ?? '' );

        $target_type = sanitize_key( (string) ( $options['target_post_type'] ?? '' ) );
        $post_type_remote_for_wp = post_type_exists( $remote_type ) ? $remote_type : 'post';
        $post_type = $target_type !== '' ? $target_type : $post_type_remote_for_wp;
        $remote_status = (string) $row['post_status'];

        // Verifica se já foi importado
        $existing = find_local_post( $remote_id, $blog_id );

        $is_update = $existing > 0;

        $post_status = in_array( $remote_status, ['publish','draft','pending','private'], true ) ? $remote_status : 'publish';
        $remote_author = (int) ( $row['post_author'] ?? 0 );
        $post_author = 0;

        if ( $options['map_users'] ) {
            $post_author = $remote_author ? find_local_user( $remote_author, $blog_id ) : 0;
        }

        if ( $post_name === '' ) {
            $post_name = sanitize_title( (string) $row['post_title'] ?: $remote_id );
        }

        $postarr = [
            'post_title'    => $row['post_title'] ? (string) $row['post_title'] : 'Sem título',
            'post_content'  => (string) ( $row['post_content'] ? apply_text_filters( $row['post_content'], $row, $options ) : '' ),
            'post_excerpt'  => (string) ( $row['post_excerpt'] ? apply_text_filters( $row['post_excerpt'], $row, $options ) : '' ),
            'post_status'   => $post_status,
            'post_type'     => $post_type,
            'post_date'     => (string) $row['post_date'],
            'post_date_gmt' => (string) ( $row['post_date_gmt'] ?? '' ),
            'post_author'   => (int) $post_author,
            'post_name'     => $post_name
        ];

        $post_meta = is_array( $row['post_meta'] ?? null ) ? $row['post_meta'] : [];

        $postarr['meta_input'] = [];
        $remote_parent = isset( $row['post_parent'] ) ? (int) $row['post_parent'] : 0;

        if ( $remote_author > 0 ) {
            $postarr['meta_input']['_hacklab_migration_remote_author'] = $remote_author;
        }

        if ( $remote_parent >= 0 ) {
            $postarr['meta_input']['_hacklab_migration_remote_parent'] = $remote_parent;
        }

        $run_id = (int) ( $options['run_id'] ?? 0 );
        if ( $run_id > 0 ) {
            $postarr['meta_input'][ '_hacklab_migration_import_run_id' ] = $run_id;
        }

        $uploads_base = (string) ( $options['uploads_base'] ?? '' );
        if ( $uploads_base !== '' ) {
            $postarr['meta_input']['_hacklab_migration_uploads_base'] = $uploads_base;
        }

        $postarr['meta_input']['_hacklab_migration_source_meta'] = $post_meta;
        $postarr['meta_input']['_hacklab_migration_source_post_type'] = $remote_type_saved;
        $postarr['meta_input']['_hacklab_migration_last_updated'] = time();

        if ( ! empty( $post_meta['_edit_last'] ) ) {
            $remote_user_id = (int) $post_meta['_edit_last'];
            $local_user_id = 0;

            if ( $options['map_users'] ) {
                $local_user_id = find_local_user( $remote_user_id, $blog_id );

                if ( ! $local_user_id ) {
                    $local_user_id = import_remote_user( $remote_user_id, $blog_id, $options['dry_run'], $run_id );
                }
            }

            $post_meta['_edit_last'] = (int) $local_user_id;
        }

        if ( $post_meta ) {
            foreach ( $post_meta as $mkey => $mval ) {
                if ( is_array( $mval ) || is_object( $mval ) ) {
                    $post_meta[ $mkey ] = maybe_serialize( $mval );
                }
            }
        }

        $postarr['meta_input'] = array_merge( $post_meta, $postarr['meta_input'] );

        // Dry-run
        if ( $options['dry_run'] ) {
            $summary['skipped']++;
            $summary['map'][$remote_id] = 0;
            continue;
        }

        // Com o parâmetro 'fn_pre' é possível alterar os dados do post antes de ser criado no WP local.
        if ( ! empty( $options['fn_pre'] ) && is_callable( $options['fn_pre'] ) ) {
            $row['blog_id'] = $blog_id;

            try {
                ( $options['fn_pre'] )( $postarr, $options, $row );
            } catch ( \Throwable $e ) {
                $summary['errors'][] = "fn_pre ({$row['ID']}): " . $e->getMessage();
            }
        }

        if ( $is_update ) { // Post já existe, tenta atualizar

            if ( $options['write_mode'] != 'insert' ) { // Atualiza apenas quando `write_mode` for `update` ou `upsert`
                $postarr['ID'] = $existing;
                $local_id = (int) wp_update_post( $postarr, true );

                if ( is_wp_error( $local_id ) ) {
                    $summary['errors'][] = 'update: ' . $local_id->get_error_message();
                    $summary['skipped']++;
                    continue;
                }

                $summary['updated']++;
            } else {
                $summary['skipped']++;
            }

        } else {

            if ( $options['write_mode'] != 'update' ) { // Cria apenas quando `write_mode` for `insert` ou `upsert`

                $local_id = (int) wp_insert_post( $postarr, true );

                if ( is_wp_error( $local_id ) || $local_id <= 0 ) {
                    $summary['errors'][] = 'insert: ' . ( is_wp_error( $local_id ) ? $local_id->get_error_message() : 'unknown' );
                    $summary['skipped']++;
                    continue;
                }

                add_post_meta( $local_id, '_hacklab_migration_source_id', $remote_id, true );
                add_post_meta( $local_id, '_hacklab_migration_source_blog', $blog_id, true );
                $summary['imported']++;
            } else {
                $summary['skipped']++;
            }

        }

        if ( ! isset( $local_id ) ) {
            continue;
        }

        if ( get_post_meta( $local_id, '_hacklab_migration_source_id', true ) === '' ) {
            update_post_meta( $local_id, '_hacklab_migration_source_id', $remote_id );
        }

        if ( get_post_meta( $local_id, '_hacklab_migration_source_blog', true ) === '' ) {
            update_post_meta( $local_id, '_hacklab_migration_source_blog', $blog_id );
        }

        if ( get_post_meta( $local_id, '_hacklab_migration_source_post_type', true ) === '' ) {
            update_post_meta( $local_id, '_hacklab_migration_source_post_type', $remote_type );
        }

        $remote_terms = is_array( $row['remote_terms'] ?? null ) ? $row['remote_terms'] : [];
        $local_terms  = is_array( $row['local_terms'] ?? null ) ? $row['local_terms'] : [];

        update_post_meta( $local_id, '_hacklab_migration_remote_terms', $remote_terms );

        if ( $options['assign_terms'] ) {
            $row_terms = array_merge( $remote_terms, $local_terms );

            // Co Authors Plus
            if ( cap_instance() && ! empty ( $row_terms['author'] ) ) {
                cap_assign_coauthors_to_post( $local_id, $row_terms );
                unset( $row_terms['author'] );
            }

            ensure_terms_and_assign( $local_id, get_post_type( $local_id ), $row_terms, [], $blog_id, $run_id );
        }

        if ( ! $options['dry_run'] && ( ! empty( $options['term_set'] ) || ! empty( $options['term_add'] ) || ! empty( $options['term_rm'] ) ) ) {
            $prepare_terms = static function ( $value ): array {
                $arr = is_array( $value ) ? $value : explode( ',', (string) $value );
                $arr = array_map( 'trim', $arr );
                $arr = array_filter( $arr, static fn( $v ) => $v !== '' );
                return array_values( array_unique( $arr ) );
            };

            $resolve_term_id = static function ( string $tax, string $term ) {
                $exists = term_exists( $term, $tax );
                if ( $exists && ! is_wp_error( $exists ) ) {
                    return is_array( $exists ) ? (int) ( $exists['term_id'] ?? 0 ) : (int) $exists;
                }
                return 0;
            };

            foreach ( (array) $options['term_set'] as $tax => $terms ) {
                $tax = sanitize_key( $tax );
                if ( $tax === '' || ! taxonomy_exists( $tax ) ) continue;
                $list = $prepare_terms( $terms );
                if ( ! $list ) continue;
                $term_ids = [];
                foreach ( $list as $term ) {
                    $tid = $resolve_term_id( $tax, $term );
                    if ( $tid <= 0 ) {
                        $insert = wp_insert_term( $term, $tax, [ 'slug' => sanitize_title( $term ) ] );
                        if ( ! is_wp_error( $insert ) ) {
                            $tid = (int) ( $insert['term_id'] ?? 0 );
                        }
                    }
                    if ( $tid > 0 ) {
                        $term_ids[] = $tid;
                    }
                }
                if ( $term_ids ) {
                    wp_set_object_terms( $local_id, array_values( array_unique( $term_ids ) ), $tax, false );
                }
            }

            foreach ( (array) $options['term_add'] as $tax => $terms ) {
                $tax = sanitize_key( $tax );
                if ( $tax === '' || ! taxonomy_exists( $tax ) ) continue;
                $list = $prepare_terms( $terms );
                if ( ! $list ) continue;
                $term_ids = [];
                foreach ( $list as $term ) {
                    $tid = $resolve_term_id( $tax, $term );
                    if ( $tid <= 0 ) {
                        $insert = wp_insert_term( $term, $tax, [ 'slug' => sanitize_title( $term ) ] );
                        if ( ! is_wp_error( $insert ) ) {
                            $tid = (int) ( $insert['term_id'] ?? 0 );
                        }
                    }
                    if ( $tid > 0 ) {
                        $term_ids[] = $tid;
                    }
                }
                if ( $term_ids ) {
                    wp_set_object_terms( $local_id, array_values( array_unique( $term_ids ) ), $tax, true );
                }
            }

            foreach ( (array) $options['term_rm'] as $tax => $terms ) {
                $tax = sanitize_key( $tax );
                if ( $tax === '' || ! taxonomy_exists( $tax ) ) continue;
                $list = $prepare_terms( $terms );
                if ( ! $list ) continue;
                $remove_ids = [];
                foreach ( $list as $term ) {
                    $tid = $resolve_term_id( $tax, $term );
                    if ( $tid > 0 ) {
                        $remove_ids[] = $tid;
                    }
                }
                if ( $remove_ids ) {
                    wp_remove_object_terms( $local_id, array_values( array_unique( $remove_ids ) ), $tax );
                }
            }
        }

        if ( ! empty( $options['meta_ops'] ) && ! $options['dry_run'] ) {
            foreach ( $options['meta_ops'] as $mkey => $mvalue ) {
                $mkey = sanitize_key( (string) $mkey );
                if ( $mkey === '' ) {
                    continue;
                }
                update_post_meta( $local_id, $mkey, $mvalue );
            }
        }

        // Com o parâmetro 'fn_pos' é possível alterar os dados do post depois de ser criado no WP local.
        if ( ! empty( $options['fn_pos'] ) && is_callable( $options['fn_pos'] ) ) {
            $row['blog_id'] = $blog_id;

            try {
                ( $options['fn_pos'])( $local_id, $row, $is_update, $options['dry_run'] );
            } catch (\Throwable $e) {
                $summary['errors'][] = "fn_pos ({$local_id}): " . $e->getMessage();
            }
        }

        $summary['map'][$remote_id] = $local_id;

        // Preserva post_modified e post_modified_gmt do remoto.
        $remote_modified     = (string) ( $row['post_modified']     ?? '' );
        $remote_modified_gmt = (string) ( $row['post_modified_gmt'] ?? '' );

        if ( $remote_modified !== '' || $remote_modified_gmt !== '' ) {
            global $wpdb;
            $wpdb->update(
                $wpdb->posts,
                [
                    'post_modified'     => $remote_modified     ?: null,
                    'post_modified_gmt' => $remote_modified_gmt ?: null,
                ],
                [ 'ID' => $local_id ],
                ['%s','%s'],
                ['%d']
            );
            clean_post_cache( $local_id );
        }
    }

    return $summary;
}
