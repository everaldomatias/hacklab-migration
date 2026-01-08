<?php
/**
 * Callbacks/helpers intended to be used with external CLI tools (ex.: hacklab-dev-utils modify-posts).
 *
 * @package HacklabMigration
 */

namespace HacklabMigration;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Atualiza o autor local do post a partir do autor remoto salvo em metas de migração.
 *
 * Uso (com hacklab-dev-utils):
 *   wp modify-posts --q:post_type=migration --q:meta_query="_hacklab_migration_source_id:415:=" --fn:\\HacklabMigration\\map_remote_author_to_local
 *
 * @param \WP_Post $post
 */
function map_remote_author_to_local( \WP_Post $post ): void {
    $remote_author = (int) get_post_meta( $post->ID, '_hacklab_migration_remote_author', true );

    if ( $remote_author <= 0 ) {
        $source_meta = get_post_meta( $post->ID, '_hacklab_migration_source_meta', true );
        if ( is_array( $source_meta ) ) {
            $remote_author = (int) ( $source_meta['post_author'] ?? 0 );
        }
    }

    if ( $remote_author <= 0 ) {
        return;
    }

    $blog_id = (int) get_post_meta( $post->ID, '_hacklab_migration_source_blog', true );
    if ( $blog_id <= 0 ) {
        $blog_id = 1;
    }

    $local_author = find_local_user( $remote_author, $blog_id );

    if ( $local_author <= 0 ) {
        $local_author = import_remote_user( $remote_author, $blog_id, false );
    }

    if ( $local_author > 0 && (int) $post->post_author !== $local_author ) {
        $post->post_author = $local_author;
    }
}

/**
 * Sincroniza coautores usando dados do meta de origem e CoAuthors Plus.
 *
 * Uso (com hacklab-dev-utils):
 *   wp modify-posts --q:post_type=migration --fn:\\HacklabMigration\\sync_coauthors_plus
 *
 * @param \WP_Post $post
 */
function sync_coauthors_plus( \WP_Post $post ): void {
    if ( ! cap_instance() ) {
        return;
    }

    $source_meta = get_post_meta( $post->ID, '_hacklab_migration_source_meta', true );
    $authors_raw = is_array( $source_meta ) ? ( $source_meta['authors'] ?? [] ) : [];

    if ( ! $authors_raw ) {
        return;
    }

    $authors_raw = is_array( $authors_raw ) ? $authors_raw : [ $authors_raw ];
    $authors = [];

    foreach ( $authors_raw as $a ) {
        if ( is_string( $a ) ) {
            // Permite lista simples (ex.: nomes ou slugs separados por vírgula)
            $parts = array_filter( array_map( 'trim', explode( ',', $a ) ) );
            foreach ( $parts as $part ) {
                $slug = sanitize_title( $part );
                if ( $slug === '' ) {
                    continue;
                }
                $authors[] = [
                    'slug' => $slug,
                    'name' => $part,
                ];
            }
            continue;
        }

        if ( is_array( $a ) ) {
            $slug  = sanitize_title( (string) ( $a['slug'] ?? $a['user_nicename'] ?? $a['login'] ?? '' ) );
            if ( $slug === '' && ! empty( $a['name'] ) ) {
                $slug = sanitize_title( (string) $a['name'] );
            }

            if ( $slug === '' ) {
                continue;
            }

            $authors[] = [
                'slug'  => $slug,
                'login' => (string) ( $a['login'] ?? $a['user_login'] ?? '' ),
                'email' => sanitize_email( (string) ( $a['email'] ?? $a['user_email'] ?? '' ) ),
                'name'  => (string) ( $a['name'] ?? $a['display_name'] ?? $slug ),
            ];
        }
    }

    if ( ! $authors ) {
        return;
    }

    cap_assign_coauthors_to_post( $post->ID, [ 'author' => $authors ] );
}
