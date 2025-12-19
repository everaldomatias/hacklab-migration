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

