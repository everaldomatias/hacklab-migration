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

function import_remote_attachments( array $args = [] ) : array {
    $defaults = [
        'blog_id'          => 1,
        'chunk'            => 500,
        'dry_run'          => false,
        'local_map'        => [],
        'old_uploads_base' => '',
        'rows'             => []
    ];

    $options = wp_parse_args( $args, $defaults );

    $rows = is_array( $options['rows'] ) ? $options['rows'] : [];

    $summary = [
        'content_rewritten' => 0,
        'errors'            => [],
        'found_posts'       => count( $rows ),
        'map'               => [],
        'missing_files'     => [],
        'registered'        => 0,
        'reused'            => 0,
        'thumbnails_set'    => 0
    ];

    if ( ! $rows ) return $summary;

    $blog_id = (int) $options['blog_id'];

    // Registrar attachments
    if ( ! $options['dry_run'] ) {
        $att = register_attachments(
            $rows,
            $blog_id,
            ['chunk' => (int) $options['chunk']]
        );

        $summary['map']           = $att['map'] ?? [];
        $summary['registered']    = (int) ( $att['registered'] ?? 0 );
        $summary['reused']        = (int) ( $att['reused'] ?? 0 );
        $summary['missing_files'] = (array) ( $att['missing_files'] ?? [] );
        $summary['errors']        = array_merge( $summary['errors'], (array) ( $att['errors'] ?? [] ) );
    }

    // Registra featured image
    foreach( $rows as $row ) {
        $post_meta = is_array( $row['post_meta'] ?? null ) ? $row['post_meta'] : [];
        $thumb_rid = $post_meta['_thumbnail_id'] ?? null; // Remote thumbnail id

        if ( is_array( $thumb_rid ) ) {
            $thumb_rid = reset( $thumb_rid );
        }

        $thumb_rid = (int) $thumb_rid;

        if ( $thumb_rid <= 0 ) {
            continue;
        }

        $remote_post_id = (int) ( $row['ID'] ?? 0 );

        if ( $remote_post_id <= 0 ) {
            continue;
        }

        // Busca post local
        $local_post_id = 0;

        if ( ! empty( $options['local_map'] ) && is_array( $options['local_map'] ) ) {
            $local_post_id = (int) ( $options['local_map'][$remote_post_id] ?? 0 );
        } else {
            $local_post_id = find_local_post( $remote_post_id, $blog_id );
        }

        if ( $local_post_id <= 0 ) {
            continue;
        }

        // Buscar o attachment local
        $thumb_lid = (int) ( $summary['map'][$thumb_rid] ?? 0 ); // Local thumbnail id

        if ( $thumb_lid <= 0 ) {
            continue;
        }

        if ( ! $options['dry_run'] ) {
            set_post_thumbnail( $local_post_id, $thumb_lid );
        }

        $summary['thumbnails_set']++;
    }

    // Reescrever URLs do content
    $old_base = (string) $options['old_uploads_base'];

    if ( $old_base === '' ) {
        $summary['errors'][] = 'Rewrite content: old_uploads_base ausente';
    } else {
        $uploads = wp_upload_dir();

        foreach ( $rows as $row ) {
            $remote_post_id = (int) ( $row['ID'] ?? 0 );

            if ( $remote_post_id <= 0 ) {
                continue;
            }

            // Busca post local
            $local_post_id = 0;

            if ( ! empty( $options['local_map'] ) && is_array( $options['local_map'] ) ) {
                $local_post_id = (int) ( $options['local_map'][$remote_post_id] ?? 0 );
            } else {
                $local_post_id = find_local_post( $remote_post_id, $blog_id );
            }

            if ( $local_post_id <= 0 ) {
                continue;
            }

            $old_content = (string) get_post_field( 'post_content', $local_post_id );

            if ( $old_content === '' ) {
                continue;
            }

            $new_content = replace_content_urls(
                $old_content,
                rtrim( $old_base, '/' ),
                rtrim( $uploads['baseurl'], '/' ),
                $blog_id
            );

            if ( $new_content !== $old_base ) {
                if ( ! $options['dry_run'] ) {
                    wp_update_post( [
                        'ID' => $local_post_id,
                        'post_content' => $new_content
                    ] );

                    $summary['content_rewritten']++;
                }
            }
        }
    }

    return $summary;
}

/**
 * Normaliza o caminho do arquivo anexo (`attached_file`) ao migrar de um ambiente
 * multisite para um site único (single site).
 */
function normalize_attached_file_for_single( string $attached_file, ?int $remote_blog_id ): string {
    $attached_file = ltrim( $attached_file, '/' );
    if ( $remote_blog_id && $remote_blog_id > 1 ) {
        $prefix = 'sites/' . (int) $remote_blog_id . '/';
        if ( strpos( $attached_file, $prefix ) === 0 ) {
            $attached_file = substr( $attached_file, strlen( $prefix ) );
        }
    }
    return $attached_file;
}

/**
 * Tenta deduzir o caminho relativo (`attached_file`) de um anexo a partir de uma URL completa.
 */
function guess_attached_file_from_url( string $url, ?int $remote_blog_id ): string {
    $path = (string) wp_parse_url( $url, PHP_URL_PATH );
    if ( ! $path ) {
        return '';
    }

    $pos = strpos( $path, '/uploads/' );
    if ( $pos === false ) {
        return '';
    }

    $rel = ltrim( substr( $path, $pos + 9 ), '/' );

    return $rel;
}

/**
 * Recupera anexos (posts do tipo `attachment`) de um banco de dados remoto do WordPress,
 * a partir de uma lista de IDs.
 */
function fetch_remote_attachments_by_ids( array $remote_ids, ?int $blog_id = null ): array {
    $remote_ids = array_values(
        array_unique(
            array_filter(
                array_map( 'intval', $remote_ids ),
                static fn( $v ) => $v > 0
            )
        )
    );

    if ( ! $remote_ids ) {
        return [];
    }

    $ext = get_external_wpdb();
    if ( ! $ext ) {
        return [];
    }

    $creds  = get_credentials();
    $t_posts = resolve_remote_posts_table( $creds, $blog_id );
    $t_meta  = resolve_remote_postmeta_table( $creds, $blog_id );

    $out   = [];
    $chunk = 1000;

    for ($i = 0; $i < count( $remote_ids ); $i += $chunk ) {
        $ids = array_slice( $remote_ids, $i, $chunk );
        $ph  = implode( ',', array_fill( 0, count( $ids), '%d' ) );

        $sql = "
            SELECT p.ID, p.post_title, p.post_name, p.post_mime_type, p.guid
              FROM {$t_posts} p
             WHERE p.ID IN ({$ph}) AND p.post_type='attachment'
        ";

        $rows = $ext->get_results( $ext->prepare( $sql, $ids ), ARRAY_A) ?: [];
        if ( ! $rows ) {
            continue;
        }

        $map = [];
        foreach ( $rows as $r ) {
            $map[(int)$r['ID']] = $r;
        }

        $meta_keys = ['_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_image_alt'];
        $phk = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
        $sqlm = "
            SELECT post_id, meta_key, meta_value
              FROM {$t_meta}
             WHERE post_id IN ({$ph}) AND meta_key IN ({$phk})
        ";

        $meta_rows = $ext->get_results( $ext->prepare( $sqlm, array_merge( $ids, $meta_keys ) ), ARRAY_A ) ?: [];
        $by_post = [];
        foreach ( $meta_rows as $m ) {
            $pid = (int) $m['post_id'];
            $k   = (string) $m['meta_key'];
            $v   = maybe_unserialize( $m['meta_value'] );
            $by_post[$pid][$k] = $v;
        }

        foreach ( $map as $rid => $rpost ) {
            $out[$rid] = [
                'post' => $rpost,
                'meta' => $by_post[$rid] ?? []
            ];
        }
    }

    return $out;
}

function register_local_attachments( array $rpost, array $rmeta, ?int $remote_blog_id ): int {
    $uploads = wp_upload_dir();
    $remote_attached = (string) ($rmeta['_wp_attached_file'] ?? '');
    if ($remote_attached === '') return 0;

    $attached_file = normalize_attached_file_for_single( $remote_attached, $remote_blog_id );
    $file_abs = trailingslashit( $uploads['basedir'] ) . $attached_file;

    if ( ! file_exists( $file_abs ) ) {
        return 0;
    }

    // evita duplicar por attached_file
    $existing = get_posts( [
        'post_type'      => 'attachment',
        'posts_per_page' => 1,
        'post_status'    => 'any',
        'meta_key'       => '_wp_attached_file',
        'meta_value'     => $attached_file,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'update_post_term_cache' => false,
        'update_post_meta_cache' => false,
    ] );

    if ( $existing ) {
        $att_id = (int) $existing[0];
    } else {
        $mime  = (string) ($rpost['post_mime_type'] ?? wp_check_filetype( $file_abs )['type'] ?? 'application/octet-stream' );
        $title = (string) ($rpost['post_title'] ?? pathinfo( $file_abs, PATHINFO_FILENAME ) );
        $slug  = (string) ($rpost['post_name'] ?? sanitize_title( $title ) );
        $guid  = trailingslashit($uploads['baseurl']) . $attached_file;

        $att_id = wp_insert_attachment( [
            'post_mime_type' => $mime,
            'post_title'     => $title,
            'post_name'      => $slug,
            'post_content'   => '',
            'post_status'    => 'inherit',
            'guid'           => esc_url_raw( $guid ),
        ], $file_abs );

        if ( is_wp_error( $att_id ) || ! $att_id ) return 0;
    }

    update_post_meta( $att_id, '_wp_attached_file', $attached_file );

    if ( isset( $rmeta['_wp_attachment_metadata'] ) ) {
        update_post_meta( $att_id, '_wp_attachment_metadata', $rmeta['_wp_attachment_metadata'] );
    }

    if ( ! empty( $rmeta['_wp_attachment_image_alt'] ) ) {
        update_post_meta( $att_id, '_wp_attachment_image_alt', (string) $rmeta['_wp_attachment_image_alt'] );
    }

    if ( ! empty( $rpost['ID'] ) )   update_post_meta( $att_id, '_hacklab_migration_source_id', (int) $rpost['ID'] );
    if ( ! empty( $rpost['guid'] ) ) update_post_meta( $att_id, '_hacklab_migration_source_url', esc_url_raw( (string) $rpost['guid'] ) );
    if ( $remote_blog_id )           update_post_meta( $att_id, '_hacklab_migration_source_blog', (int) $remote_blog_id );

    return (int) $att_id;
}

function collect_needed_remote_attachments( array $rows, ?int $remote_blog_id ): array {
    $ids   = [];
    $files = [];
    $urls  = [];

    foreach ( $rows as $row ) {
        $meta = is_array( $row['post_meta'] ?? null ) ? $row['post_meta'] : [];

        $thumb = $meta['_thumbnail_id'] ?? null;

        if ( is_array( $thumb ) ) {
            $thumb = reset( $thumb );
        }

        $thumb = (int) $thumb;

        if ( $thumb > 0 ) {
            $ids[$thumb] = true;
        }

        $content = (string) ( $row['post_content'] ?? '' );

        foreach ( extract_image_urls( $content ) as $url ) {
            $urls[$url] = true;
            $rel = guess_attached_file_from_url( $url, $remote_blog_id );

            if ( $rel ) {
                $files[$rel] = true;
            }
        }
    }

    return [
        'attachment_ids' => array_keys( $ids ),
        'files'          => array_keys( $files ),
        'urls'           => array_keys( $urls )
    ];
}

function register_attachments( array $rows, int $remote_blog_id, array $opts = [] ) : array {
    $opts = wp_parse_args( $opts, [
        'chunk'    => 500,
    ] );

    $summary = [
        'map'           => [],
        'registered'    => 0,
        'reused'        => 0,
        'missing_files' => [],
        'errors'        => []
    ];

    $need = collect_needed_remote_attachments( $rows, $remote_blog_id );
    $remote_ids = $need['attachment_ids'];

    if ( ! $remote_ids ) {
        return $summary;
    }

    $chunk = max( 50, (int) $opts['chunk'] );

    for ( $i = 0; $i < count( $remote_ids ); $i += $chunk ) {
        $ids  = array_slice( $remote_ids, $i, $chunk );
        $info = fetch_remote_attachments_by_ids( $ids, $remote_blog_id );

        if ( ! $info ) {
            continue;
        }

        foreach ( $ids as $rid ) {
            if ( empty( $info[$rid] ) ) {
                continue;
            }

            $rpost = $info[$rid]['post'] ?? [];
            $rmeta = $info[$rid]['meta'] ?? [];

            // Registra os attachments localmente
            $att_id = register_local_attachments(
                $rpost,
                $rmeta,
                $remote_blog_id
            );

            if ( $att_id > 0 ) {
                $attached_file = normalize_attached_file_for_single( (string) ( $rmeta['_wp_attached_file'] ?? '' ), $remote_blog_id );
                $existing = get_posts( [
                    'post_type'      => 'attachment',
                    'posts_per_page' => 1,
                    'post_status'    => 'any',
                    'meta_key'       => '_wp_attached_file',
                    'meta_value'     => $attached_file,
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                ]);

                if ( $existing && (int) $existing[0] === $att_id ) {
                    $summary['registered']++;
                } else {
                    $summary['reused']++;
                }

                $summary['map'][$rid] = $att_id;
            } else {
                $summary['missing_files'][] = (string) ( $rmeta['_wp_attached_file'] ?? '' );
            }
        }
    }

    return $summary;
}

function replace_content_urls( string $html, string $old_uploads_base, string $new_uploads_base, ?int $remote_blog_id ): string {
    $map = build_uploads_url_map( $old_uploads_base, $new_uploads_base, $remote_blog_id );
    return replace_urls_in_content( $html, $map );
}
