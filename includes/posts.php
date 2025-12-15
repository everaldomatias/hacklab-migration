<?php
/**
 * Utility functions for users
 *
 * @package HacklabMigration
 */

namespace HacklabMigration;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Importa posts do WP remoto para o site atual (single).
 */
function import_remote_posts( array $args = [] ): array {
    $defaults = [
        'fetch'      => ['numberposts' => 10],
        'media'      => true,
        'dry_run'    => false,
        'fn_pre'     => null,
        'fn_pos'     => null,
        'write_mode' => 'upsert'
    ];

    $options = wp_parse_args( $args, $defaults );

    $fetch = (array) ( $options['fetch'] ?? [] );
    unset( $fetch['fields'] );
    $fetch['fields'] = 'all';

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

    $blog_id = (int) ( $options['fetch']['blog_id'] ?? ( $args['blog_id'] ?? 1 ) );

    $remote_ids = array_map( static fn( $r ) => (int) $r['ID'], $rows );
    $terms_map  = fetch_remote_terms_for_posts( $remote_ids, $blog_id );

    foreach ( $rows as &$r ) {
        $rid = (int) $r['ID'];
        $r['remote_terms'] = $terms_map[ $rid ] ?? [];
    }
    unset( $r );

    $summary['found_posts'] = count( $rows );
    $summary['rows'] = $rows;

    foreach ( $rows as $row ) {
        $remote_id     = (int) $row['ID'];
        $remote_type   = post_type_exists( $row['post_type'] ) ? (string) $row['post_type'] : 'post';
        $remote_status = (string) $row['post_status'];

        // Verifica se já foi importado
        $existing = find_local_post( $remote_id, $blog_id );
        $is_update = $existing > 0;

        $post_status = in_array( $remote_status, ['publish','draft','pending','private'], true ) ? $remote_status : 'publish';
        $post_author = $row['post_author'] ? find_local_user( $row['post_author'], $blog_id ) : 0;
        $post_name   = (string) ( $row['post_name'] ?? '' );

        if ( $post_name === '' ) {
            $post_name = sanitize_title( (string) $row['post_title'] ?: $remote_id );
        }

        $postarr = [
            'post_title'    => $row['post_title'] ? (string) $row['post_title'] : 'Sem título',
            'post_content'  => (string) ( $row['post_content'] ? apply_text_filters( $row['post_content'], $row, $options ) : '' ),
            'post_excerpt'  => (string) ( $row['post_excerpt'] ? apply_text_filters( $row['post_excerpt'], $row, $options ) : '' ),
            'post_status'   => $post_status,
            'post_type'     => $remote_type,
            'post_date'     => (string) $row['post_date'],
            'post_date_gmt' => (string) ( $row['post_date_gmt'] ?? '' ),
            'post_author'   => (int) $post_author,
            'post_name'     => $post_name
        ];

        $post_meta = is_array( $row['post_meta'] ?? null ) ? $row['post_meta'] : [];

        $postarr['meta_input'] = [];
        $postarr['meta_input']['_hacklab_migration_source_meta'] = $post_meta;
        $postarr['meta_input']['_hacklab_migration_source_meta']['post_type'] = $remote_type;
        $postarr['meta_input']['_hacklab_migration_last_updated'] = time();

        if ( ! empty( $post_meta['_edit_last'] ) ) {
            $remote_user_id = (int) $post_meta['_edit_last'];
            $local_user_id = find_local_user( $remote_user_id, $blog_id );

            if ( ! $local_user_id ) {
                $local_user_id = import_remote_user( $remote_user_id, $blog_id, $options['dry_run'] );
            }

            $post_meta['_edit_last'] = (int) $local_user_id;
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

        $remote_terms = $row['remote_terms'] ?? [];
        $local_terms = $row['local_terms'] ?? [];

        $row_terms = array_merge( $remote_terms, $local_terms );

        // Co Authors Plus
        if ( cap_instance() && ! empty ( $row_terms['author'] ) ) {
            cap_assign_coauthors_to_post( $local_id, $row_terms );
            unset( $row_terms['author'] );
        }

        ensure_terms_and_assign( $local_id, get_post_type( $local_id ), $row_terms, [], $blog_id );

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
        'post_type'      => 'any',
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
