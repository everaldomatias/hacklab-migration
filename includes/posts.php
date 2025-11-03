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
        'fetch'   => ['numberposts' => 10],
        'media'   => true,
        'dry_run' => false,
        'fn_pre'  => null,
        'fn_pos'  => null
    ];

    $options = wp_parse_args( $args, $defaults );

    $summary = [
        'found_posts' => 0,
        'imported'    => 0,
        'updated'     => 0,
        'skipped'     => 0,
        'attachments' => 0,
        'map'         => [],
        'errors'      => [],
        'args'        => $options['fetch'],
        'rows'        => []
    ];

    $rows = remote_get_posts( (array) $options['fetch'] );

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

        $postarr = [
            'post_title'    => (string) $row['post_title'],
            'post_content'  => (string) $row['post_content'],
            'post_excerpt'  => (string) ( $row['post_excerpt'] ?? '' ),
            'post_status'   => in_array( $remote_status, ['publish','draft','pending','private'], true ) ? $remote_status : 'draft',
            'post_type'     => $remote_type,
            'post_date'     => (string) $row['post_date'],
            'post_date_gmt' => (string) ( $row['post_date_gmt'] ?? '' ),
            'post_author'   => (string) ( $row['post_author'] ? get_user_by_meta_data( '_hacklab_migration_source_id', $row['post_author'] ) : '' ),
            'post_name'     => (string) ( $row['post_name'] ?? sanitize_title( (string) $row['post_title'] ) )
        ];

        $post_meta = is_array( $row['post_meta'] ?? null ) ? $row['post_meta'] : [];

        $postarr['meta_input'] = $post_meta;
        $postarr['meta_input']['_hacklab_migration_source_meta'] = $post_meta;
        $postarr['meta_input']['_hacklab_migration_source_meta']['post_type'] = $remote_type;
        $postarr['meta_input']['_hacklab_migration_last_update'] = time();

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
                ( $options['fn_pre'] )( $postarr, $options );
            } catch (\Throwable $e) {
                $summary['errors'][] = "fn_pre ({$row['ID']}): " . $e->getMessage();
            }
        }

        if ( $is_update ) {
            $postarr['ID'] = $existing;
            $local_id = (int) wp_update_post( $postarr, true );

            if ( is_wp_error( $local_id ) ) {
                $summary['errors'][] = 'update: ' . $local_id->get_error_message();
                $summary['skipped']++;
                continue;
            }

            $summary['updated']++;
        } else {
            $local_id = (int) wp_insert_post( $postarr, true );

            if ( is_wp_error( $local_id ) || $local_id <= 0 ) {
                $summary['errors'][] = 'insert: ' . ( is_wp_error( $local_id ) ? $local_id->get_error_message() : 'unknown' );
                $summary['skipped']++;
                continue;
            }

            add_post_meta( $local_id, '_hacklab_migration_source_id', $remote_id, true );
            add_post_meta( $local_id, '_hacklab_migration_source_blog', (int) ( $args['fetch']['blog_id'] ?? $args['blog_id'] ?? $row['blog_id'] ?? 1 ), true );
            $summary['imported']++;
        }

        $row_terms = $row['remote_terms'] ?? [];
        ensure_terms_and_assign( $local_id, get_post_type( $local_id ), $row_terms );

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
