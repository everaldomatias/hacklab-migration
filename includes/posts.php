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
        'assign_terms'      => true,
        'map_users'         => true,
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
        if ( $options['map_users'] && $remote_author ) {
            $post_author = find_local_user( $remote_author, $blog_id );
            // Se não achar o autor localmente, importa
            if ( ! $post_author ) {
                $post_author = import_remote_user( $remote_author, $blog_id, $options['dry_run'], $options['run_id'] );
            }
        }

        // Search & Replace + Limpeza de Conteúdo
        $post_content = (string) ( $row['post_content'] ?? '' );
        $post_excerpt = (string) ( $row['post_excerpt'] ?? '' );

        // Aplica filtros de texto (replaces de URLs, etc)
        $post_content = apply_text_filters( $post_content, $options['search_replace'] );
        $post_excerpt = apply_text_filters( $post_excerpt, $options['search_replace'] );

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

        $postarr['meta_input']['_hacklab_migration_source_id']   = $remote_id;
        $postarr['meta_input']['_hacklab_migration_source_blog'] = $blog_id;

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

        if ( $is_update ) {
            unset( $postarr['post_type'] );
            $postarr['ID'] = $existing_id;
            $local_id = wp_update_post( $postarr );
            if ( ! is_wp_error( $local_id ) ) $summary['updated']++;
        } else {
            $local_id = wp_insert_post( $postarr );
            if ( ! is_wp_error( $local_id ) ) $summary['imported']++;
        }

        if ( is_wp_error( $local_id ) || $local_id <= 0 ) {
            $summary['errors'][] = "Erro ao salvar post remoto ID $remote_id";
            continue;
        }

        $actual_post_type = get_post_type( $local_id );

        if ( $actual_post_type && ! empty( $options['lang'] ) ) {
            set_post_wpml_language( $local_id, $actual_post_type, $options['lang'] );
        }

        if ( $options['assign_terms'] ) {
            $remote_terms = $r['remote_terms'] ?? [];

            // Co-Authors Plus
            if ( ! empty( $remote_terms['author'] ) && function_exists( 'cap_assign_coauthors_to_post' ) ) {
                cap_assign_coauthors_to_post( $local_id, $remote_terms );
                unset( $remote_terms['author'] );
            }

            ensure_terms_and_assign( $local_id, get_post_type( $local_id ), $remote_terms, [], $blog_id );
        }

        // Aplica termos extras da CLI (add/set/rm)
        process_cli_terms( $local_id, $options );

        // Reattach de thumbnail
        if ( $options['media'] ) {
            $remote_thumb_id = (int) ($post_meta['_thumbnail_id'][0] ?? 0);

            if ( $remote_thumb_id > 0 ) {
                process_post_thumbnail_import( $local_id, $remote_thumb_id, $blog_id, $options );
            }
        }

        if ( ! empty( $options['fn_pos'] ) && is_callable( $options['fn_pos'] ) ) {
            call_user_func( $options['fn_pos'], $local_id, $row, $is_update, $options['dry_run'] );
        }

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
    $q = get_posts( [
        'post_type'      => get_supported_post_types(),
        'posts_per_page' => 1,
        'post_status'    => 'any',
        'meta_query'     => [
            ['key' => '_hacklab_migration_source_id',   'value' => $remote_id, 'compare' => '='],
            ['key' => '_hacklab_migration_source_blog', 'value' => $blog_id,   'compare' => '='],
        ],
        'fields'                => 'ids',
        'no_found_rows'         => true,
        'update_meta_cache'     => false,
        'update_post_term_cache'=> false,
    ] );
    return $q ? (int) $q[0] : 0;
}
