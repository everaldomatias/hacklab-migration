<?php
/**
 * Utility functions for attachments
 *
 * @package HacklabMigration
 */

namespace HacklabMigration;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function fetch_remote_attachment_url_by_id( int $attachment_id, ?int $blog_id = null ): string {
    $ext = get_external_wpdb();
    if ( $attachment_id <= 0 ) return '';

    $creds = get_credentials();
    $table = resolve_remote_posts_table( $creds, $blog_id );

    $sql = "SELECT guid FROM {$table} WHERE ID = %d AND post_type = 'attachment' LIMIT 1";
    $guid = (string) $ext->get_var( $ext->prepare( $sql, $attachment_id ) );

    return esc_url_raw( $guid );
}

function ensure_featured_image_from_remote( int $local_post_id, array $post_meta, ?int $blog_id = null, bool $force = false ): array {
    $remote_thumb = $post_meta['_hacklab_migration_source_meta']['_thumbnail_id']  ?? null;

    if ( is_array( $remote_thumb ) ) $remote_thumb = reset( $remote_thumb );
    $remote_thumb = (int) $remote_thumb;

    if ( $remote_thumb <= 0 ) {
        return ['status' => 'missing', 'attachment_id' => 0, 'reason' => '_thumbnail_id ausente'];
    }

    $remote_url = fetch_remote_attachment_url_by_id( $remote_thumb, $blog_id );
    if ( ! $remote_url ) {
        return ['status' => 'missing', 'attachment_id' => 0, 'reason' => 'URL remota não encontrada'];
    }

    $current_thumb_id = (int) get_post_thumbnail_id( $local_post_id );

    if ( $current_thumb_id && ! $force ) {
        $src_meta = get_post_meta( $current_thumb_id, '_hacklab_migration_source_url', true );
        $current_url = wp_get_attachment_url( $current_thumb_id );

        if ( $src_meta && $src_meta === $remote_url ) {
            return ['status' => 'skipped', 'attachment_id' => $current_thumb_id, 'reason' => 'já definida com mesma origem'];
        }

        if ( $current_url && $remote_url && basename( $current_url ) === basename( $remote_url ) ) {
            return ['status' => 'skipped', 'attachment_id' => $current_thumb_id, 'reason' => 'já existe thumbnail com mesmo arquivo'];
        }
    }

    $existing = find_attachment_by_source_url( $remote_url );

    if ( $existing ) {
        set_post_thumbnail( $local_post_id, $existing );
        return ['status' => 'set', 'attachment_id' => (int) $existing, 'reason' => 'reutilizado attachment existente'];
    }

    $att_id = sideload_attachment( $remote_url, $local_post_id );

    if ( is_wp_error( $att_id ) || ! $att_id ) {
        return ['status' => 'missing', 'attachment_id' => 0, 'reason' => 'falha ao baixar: ' . ( is_wp_error( $att_id ) ? $att_id->get_error_message() : 'desconhecida' )];
    }

    add_post_meta( (int) $att_id, '_hacklab_migration_source_url', esc_url_raw( $remote_url ), true );
    set_post_thumbnail( $local_post_id, (int) $att_id );

    return ['status' => 'downloaded', 'attachment_id' => (int) $att_id, 'reason' => 'baixado e definido'];
}

function find_attachment_by_source_url( string $url ): int {
    $q = get_posts( [
        'post_type'              => 'attachment',
        'posts_per_page'         => 1,
        'meta_key'               => '_hacklab_migration_source_url',
        'meta_value'             => esc_url_raw( $url ),
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'update_post_term_cache' => false,
        'update_meta_cache'      => false
    ] );

    return $q ? (int)$q[0] : 0;
}

function sideload_attachment( string $url, int $post_id ) {
    if ( ! function_exists( 'download_url' ) )  require_once ABSPATH . 'wp-admin/includes/file.php';
    if ( ! function_exists( 'wp_handle_sideload' ) ) require_once ABSPATH . 'wp-admin/includes/file.php';
    if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = download_url( $url, 30 );
    if ( is_wp_error( $tmp ) ) return $tmp;

    $filename = wp_basename( parse_url( $url, PHP_URL_PATH ) ?? '' );
    $file = [
        'name'     => $filename ?: 'remote-file',
        'type'     => mime_content_type( $tmp ) ?: 'image/jpeg',
        'tmp_name' => $tmp,
        'error'    => 0,
        'size'     => filesize( $tmp )
    ];

    $overrides = ['test_form' => false];
    $sideload  = wp_handle_sideload( $file, $overrides );

    if ( isset( $sideload['error'] ) ) {
        @unlink( $tmp );
        return new \WP_Error( 'sideload', $sideload['error'] );
    }

    $attachment = [
        'post_mime_type' => $sideload['type'],
        'post_title'     => sanitize_text_field( pathinfo( $file['name'], PATHINFO_FILENAME ) ),
        'post_content'   => '',
        'post_status'    => 'inherit',
        'post_parent'    => $post_id,
    ];

    $attach_id = wp_insert_attachment( $attachment, $sideload['file'], $post_id );

    if ( is_wp_error( $attach_id ) ) {
        return $attach_id;
    }

    $metadata = wp_generate_attachment_metadata( $attach_id, $sideload['file'] );
    wp_update_attachment_metadata( $attach_id, $metadata );

    return $attach_id;
}

