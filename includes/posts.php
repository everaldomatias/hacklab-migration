<?php
/**
 * Utility functions for users
 *
 * @package HacklabMigration
 */

namespace HacklabMigration;

use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Importa posts do WP remoto para o site atual (single).
 */
function import_remote_posts( array $args = [] ): array {
    $defaults = [
        'fetch'             => ['numberposts' => 10],
        'media'             => true,
        'dry_run'           => false,
        'fn_pre'            => null,
        'fn_pos'            => null,
        'meta_ops'          => [],
        'term_add'          => [],
        'term_set'          => [],
        'term_rm'           => [],
        'search_replace'    => [], // Ex: ['http://old.com' => 'https://new.com']
        'target_post_type'  => '',
        'force_base_prefix' => false,
        'uploads_base'      => '',
        'run_id'            => 0,
        'lang'              => 'pt-br'
    ];

    $options = wp_parse_args( $args, $defaults );

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
        'errors'      => [],
        'rows'        => [],
        'map'         => []
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

    wp_defer_term_counting( true );
    wp_suspend_cache_invalidation( true );

    // Pré-carrega termos remotos para evitar N+1 queries
    $remote_ids = array_map( static fn( $r ) => (int) $r['ID'], $rows );
    $terms_map  = fetch_remote_terms_for_posts( $remote_ids, $blog_id, [], (bool) $options['force_base_prefix'] );

    foreach ( $rows as &$r ) {
        $r['remote_terms'] = $terms_map[ (int) $r['ID'] ] ?? [];
    }
    unset( $r );

    $summary['found_posts'] = count( $rows );
    $summary['rows'] = $rows;

    foreach ( $rows as $index => $row ) {
        $remote_id = (int) $row['ID'];
        $remote_type_raw   = (string) ( $row['post_type'] ?? '' );
        $remote_type       = sanitize_key( $remote_type_raw );
        $remote_type_saved = $remote_type !== '' ? $remote_type : 'post';

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            WP_CLI::log( sprintf( "Processando remoto ID: %d (%d/%d)", $remote_id, $index + 1, count($rows) ) );
        }

        $post_name = (string) ( $row['post_name'] ?? '' );
        if ( $post_name === '' ) {
            $post_name = sanitize_title( (string) $row['post_title'] ?: $remote_id );
        }

        $remote_status = (string) $row['post_status'];
        $post_status   = in_array( $remote_status, ['publish','draft','pending','private'], true ) ? $remote_status : 'publish';

        // Author mapping
        $post_author = 0;
        $remote_author = (int) ( $row['post_author'] ?? 0 );
        if ( $remote_author ) {
            $post_author = find_local_user( $remote_author, $blog_id );
            if ( ! $post_author ) {
                $post_author = import_remote_user( $remote_author, $blog_id, $options['dry_run'], $options['run_id'] );
            }
        }

        $post_content_raw = (string) ( $row['post_content'] ?? '' );
        $post_excerpt_raw = (string) ( $row['post_excerpt'] ?? '' );

        // Aplica filtros/search-replace de texto (replaces de URLs, etc)
        $post_content = apply_text_filters( $post_content_raw, $options['search_replace'] );
        $post_excerpt = apply_text_filters( $post_excerpt_raw, $options['search_replace'] );

        $postarr = [
            'post_title'    => $row['post_title'] ? (string) $row['post_title'] : 'Sem título',
            'post_content'  => $post_content,
            'post_excerpt'  => $post_excerpt,
            'post_status'   => $post_status,
            'post_type'     => $options['target_post_type'] ?: ($row['post_type'] ?? 'post'),
            'post_date'     => (string) $row['post_date'],
            'post_date_gmt' => (string) ( $row['post_date_gmt'] ?? '' ),
            'post_author'   => (int) $post_author,
            'post_name'     => $post_name,
            'meta_input'    => []
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
        $postarr['meta_input']['_hacklab_migration_source_content'] = $post_content_raw;
        $postarr['meta_input']['_hacklab_migration_source_excerpt'] = $post_excerpt_raw;

        if ( ! empty( $post_meta['_edit_last'] ) ) {
            $remote_user_id = (int) $post_meta['_edit_last'];
            $local_user_id = 0;

            $local_user_id = find_local_user( $remote_user_id, $blog_id );

            if ( ! $local_user_id ) {
                $local_user_id = import_remote_user( $remote_user_id, $blog_id, $options['dry_run'], $run_id );
            }

            $post_meta['_edit_last'] = (int) $local_user_id;
        }

        foreach ( $post_meta as $mkey => $mval ) {
            if ( is_array( $mval ) || is_object( $mval ) ) {
                $post_meta[ $mkey ] = maybe_serialize( $mval );
            }
        }
        $postarr['meta_input'] = array_merge( $post_meta, $postarr['meta_input'] );

        if ( $options['dry_run'] ) {
            $summary['skipped']++;
            continue;
        }

        $existing_id = find_local_post( $remote_id, $blog_id );

        $is_update   = $existing_id > 0;
        $local_id    = 0;

        // Com o parâmetro 'fn_pre' é possível alterar os dados do post antes de ser criado no WP local.
        if ( ! empty( $options['fn_pre'] ) && is_callable( $options['fn_pre'] ) ) {
            $row['blog_id'] = $blog_id;

            try {
                ( $options['fn_pre'] )( $postarr, $options, $row );
            } catch ( \Throwable $e ) {
                $summary['errors'][] = "fn_pre ({$row['ID']}): " . $e->getMessage();
            }
        }

        if ( $is_update ) {
            // Com o parâmetro 'target_post_type', atualiza o post_type do post local
            // if ( isset( $options['target_post_type'] ) && ! empty( $options['target_post_type'] ) ) {
            //     $postarr['post_type'] = $options['target_post_type'];
            // } else {
            //     unset( $postarr['post_type'] );
            // }

            // Quando está atualizando um post, não altera o post_type
            unset( $postarr['post_type'] );

            $postarr['ID'] = $existing_id;
            $local_id = wp_update_post( $postarr );
            if ( ! is_wp_error( $local_id ) ) $summary['updated']++;
        } else {
            $local_id = wp_insert_post( $postarr );
            add_post_meta( $local_id, '_hacklab_migration_source_id', $remote_id, true );
            add_post_meta( $local_id, '_hacklab_migration_source_blog', $blog_id, true );
            if ( ! is_wp_error( $local_id ) ) $summary['imported']++;
        }

        if ( is_wp_error( $local_id ) || $local_id <= 0 ) {
            $summary['errors'][] = "Erro ao salvar post remoto ID $remote_id";
            continue;
        }

        $summary['map'][ $remote_id ] = $local_id;

        $actual_post_type = get_post_type( $local_id );

        if ( $actual_post_type && ! empty( $options['lang'] ) ) {
            set_post_wpml_language( $local_id, $actual_post_type, $options['lang'] );
        }

        $remote_terms = $row['remote_terms'] ?? [];

        // Co-Authors Plus
        if ( ! empty( $remote_terms['author'] ) && function_exists( 'cap_assign_coauthors_to_post' ) ) {
            cap_assign_coauthors_to_post( $local_id, $remote_terms );
            unset( $remote_terms['author'] );
        }

        import_terms_as_tags( $local_id, $remote_terms, $blog_id );
        save_term_as_meta( $local_id, $remote_terms, $blog_id );

        // Aplica termos extras da CLI (add/set/rm)
        process_cli_terms( $local_id, $options );

        // Remove imagem do content caso seja a mesma da imagem destacada do post
        if ( class_exists( '\hacklabr\Utils\Helpers' ) && $actual_post_type !== 'attachment' ) {

            $current_thumb_id = (int) get_post_thumbnail_id( $local_id );
            $remote_thumb_id = (int) ( $post_meta['_thumbnail_id'][0] ?? 0 );

            if ( $current_thumb_id > 0 && $current_thumb_id !== $remote_thumb_id ) {
                $content_raw = $postarr['post_content'] ?? '';

                if ( ! empty( $content_raw ) ) {
                    $new_content = \hacklabr\Utils\Helpers::remove_featured_image_from_content(
                        $content_raw,
                        $current_thumb_id
                    );

                    if ( $new_content !== $content_raw ) {
                        wp_update_post( [
                            'ID'           => $local_id,
                            'post_content' => $new_content
                        ] );

                        $row['post_content'] = $new_content;
                    }
                }
            }
        }

        if ( ! empty( $options['fn_pos'] ) && is_callable( $options['fn_pos'] ) ) {
            call_user_func( $options['fn_pos'], $local_id, $row, $is_update, $options['dry_run'] );
        }

        restore_post_modification_date(
            $local_id,
            (string) ( $row['post_modified'] ?? '' ),
            (string) ( $row['post_modified_gmt'] ?? '' )
        );

        if ( $index > 0 && $index % 50 === 0 ) {
            stop_the_insanity();
        }
    }

    wp_suspend_cache_invalidation( false );
    wp_defer_term_counting( false );

    return $summary;
}

/**
 * Localiza o post local correspondente a um post remoto, baseado em metadados de migração.
 *
 * @since 1.0.0
 * @version 1.0.0
 *
 * @param int $remote_id  ID do post na instalação de origem (remota).
 * @param int $blog_id    ID do blog de origem (no multisite). Padrão: 1.
 *
 * @return int            ID do post local correspondente, ou 0 se não encontrado.
 *
 */
function find_local_post( int $remote_id, int $blog_id = 1 ): int {
    global $wpdb;
    static $cache = [];

    if ( $remote_id <= 0 ) {
        return 0;
    }

    $cache_key = $remote_id . '|' . $blog_id;

    if ( isset( $cache[ $cache_key ] ) ) {
        return $cache[ $cache_key ];
    }

    $sql = "
        SELECT p.ID
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id
        INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id
    ";

    if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
        $sql .= " LEFT JOIN {$wpdb->prefix}icl_translations icl ON icl.element_id = p.ID AND icl.element_type = CONCAT('post_', p.post_type) ";
    }

    $sql .= "
        WHERE pm1.meta_key = '_hacklab_migration_source_id' AND pm1.meta_value = %d
          AND pm2.meta_key = '_hacklab_migration_source_blog' AND pm2.meta_value = %d
    ";

    if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
        $sql .= " AND (icl.language_code IS NULL OR icl.language_code = 'pt-br') ";
    }

    $sql .= " ORDER BY p.ID ASC LIMIT 1";

    $local_id = (int) $wpdb->get_var( $wpdb->prepare( $sql, $remote_id, $blog_id ) );

    if ( $local_id > 0 ) {
        $cache[ $cache_key ] = $local_id;
    }

    return $local_id;
}
