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

/**
 * Log message with context
 */
function log_message( string $message, string $level = 'info' ) : void {}

function fetch_remote_terms_for_posts( array $post_ids, ?int $blog_id = null, array $only_tax = [] ): array {
    $ext = get_external_wpdb();
    if ( ! $ext ) return [];

    $post_ids = array_values( array_unique( array_map( 'intval', $post_ids ) ) );
    if ( ! $post_ids ) return [];

    $creds  = get_credentials();
    $tables = resolve_remote_terms_tables( $creds, $blog_id );

    $only_tax = array_values(
        array_filter(
            array_map( 'strval', $only_tax ),
            static fn( $v ) => $v !== ''
        )
    );

    $chunk_size = 500;
    $out = [];

    for ( $i = 0, $n = count( $post_ids ); $i < $n; $i += $chunk_size ) {
        $slice   = array_slice( $post_ids, $i, $chunk_size );
        $ph_ids  = implode( ',', array_fill( 0, count( $slice ), '%d' ) );
        $params  = $slice;

        $sql = "
            SELECT DISTINCT tr.object_id AS post_id, tt.taxonomy, t.term_id, t.name, t.slug
              FROM {$tables['term_taxonomy']} tt
              JOIN {$tables['term_relationships']} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
              JOIN {$tables['terms']} t ON t.term_id = tt.term_id
             WHERE tr.object_id IN ({$ph_ids})
        ";

        if ( $only_tax ) {
            $ph_tx  = implode( ',', array_fill( 0, count( $only_tax ), '%s' ) );
            $sql   .= " AND tt.taxonomy IN ({$ph_tx})";
            $params = array_merge( $params, $only_tax );
        }

        $prepared = $ext->prepare( $sql, ...$params );

        if ( $prepared === null ) {
            $ids_str = implode( ',', array_map( 'intval', $slice ) );
            $sql_fallback = "
                SELECT DISTINCT tr.object_id AS post_id, tt.taxonomy, t.term_id, t.name, t.slug
                  FROM {$tables['term_taxonomy']} tt
                  JOIN {$tables['term_relationships']} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
                  JOIN {$tables['terms']} t ON t.term_id = tt.term_id
                 WHERE tr.object_id IN ({$ids_str})
            ";

            if ( $only_tax ) {
                $tx_esc = implode( ',', array_map(
                    static fn($tx) => "'" . esc_sql( $tx ) . "'",
                    $only_tax
                ) );
                $sql_fallback .= " AND tt.taxonomy IN ({$tx_esc})";
            }

            $prepared = $sql_fallback;
        }

        $prepared .= " ORDER BY tr.object_id ASC, tt.taxonomy ASC, t.name ASC";

        $rows = $ext->get_results( $prepared, ARRAY_A ) ?: [];
        foreach ( $rows as $r ) {
            $pid = (int) ( $r['post_id'] ?? 0 );
            if ( $pid <= 0 ) { continue; }
            $tx  = (string) ( $r['taxonomy'] ?? '' );
            if ( $tx === '' ) { continue; }

            $out[ $pid ][ $tx ][] = [
                'term_id' => (int) ( $r['term_id'] ?? 0 ),
                'name'    => (string) ( $r['name']    ?? '' ),
                'slug'    => (string) ( $r['slug']    ?? '' ),
            ];
        }
    }

    return $out;
}

function ensure_terms_and_assign( int $post_id, string $post_type, array $terms_by_tax, array $tax_map = [] ) : void {
    if ( ! $terms_by_tax ) return;

    $allowed_tax = array_fill_keys( get_object_taxonomies( $post_type, 'names' ), true );

    static $term_cache = [];

    foreach ( $terms_by_tax as $remote_tax => $term_list ) {
        $local_tax = $tax_map[$remote_tax] ?? $remote_tax;

        if ( ! taxonomy_exists( $local_tax ) || empty( $allowed_tax[ $local_tax ] ) ) {
            continue;
        }

        if ( ! is_array( $term_list ) || ! $term_list ) {
            continue;
        }

        $to_set = [];
        foreach ( $term_list as $t ) {
            $name = (string) ( $t['name'] ?? '' );
            $slug = (string) ( $t['slug'] ?? '' );

            if ( $slug === '' && $name !== '' ) {
                $slug = sanitize_title( $name );
            }
            if ( $slug === '' && $name === '' ) {
                continue;
            }

            if ( isset( $term_cache[ $local_tax ][ $slug ] ) ) {
                $to_set[] = (int) $term_cache[ $local_tax ][ $slug ];
                continue;
            }

            $exists = term_exists( $slug, $local_tax );

            if ( ! $exists && $name !== '' ) {
                $exists = term_exists( $name, $local_tax );
            }

            if ( $exists && ! is_wp_error( $exists ) ) {
                $term_id = is_array( $exists ) ? (int) ( $exists['term_id'] ?? 0 ) : (int) $exists;
                if ( $term_id > 0 ) {
                    $term_cache[ $local_tax ][ $slug ] = $term_id;
                    $to_set[] = $term_id;
                    continue;
                }
            }

            $insert_args = [];

            if ( $slug !== '' ) {
                $insert_args['slug'] = $slug;
            }

            if ( ! empty( $t['parent_slug'] ) || ! empty( $t['parent_name'] ) ) {
                $parent_slug = (string) ( $t['parent_slug'] ?? '' );
                $parent_name = (string) ( $t['parent_name'] ?? '' );
                $parent_id   = 0;

                if ( $parent_slug !== '' ) {
                    $parent_exists = term_exists( $parent_slug, $local_tax );
                    if ( ! $parent_exists && $parent_name !== '' ) {
                        $parent_exists = term_exists( $parent_name, $local_tax );
                    }
                    if ( $parent_exists && ! is_wp_error( $parent_exists ) ) {
                        $parent_id = is_array( $parent_exists ) ? (int) ( $parent_exists['term_id'] ?? 0 ) : (int) $parent_exists;
                    } elseif ( $parent_name !== '' ) {
                        $parent_ins = wp_insert_term( $parent_name, $local_tax, [ 'slug' => sanitize_title( $parent_slug ?: $parent_name ) ] );
                        if ( ! is_wp_error( $parent_ins ) ) {
                            $parent_id = (int) ( $parent_ins['term_id'] ?? 0 );
                        }
                    }
                }
                if ( $parent_id > 0 ) {
                    $insert_args['parent'] = $parent_id;
                }
            }

            $ins = wp_insert_term( $name !== '' ? $name : $slug, $local_tax, $insert_args );
            if ( ! is_wp_error( $ins ) && ! empty( $ins['term_id'] ) ) {
                $term_id = (int) $ins['term_id'];
                $term_cache[ $local_tax ][ $slug ] = $term_id;
                $to_set[] = $term_id;
            }
        }

        if ( $to_set ) {
            $to_set = array_values( array_unique( array_map( 'intval', $to_set ) ) );
            sort( $to_set );

            $current_ids = wp_get_object_terms( $post_id, $local_tax, [ 'fields' => 'ids' ] );
            if ( is_wp_error( $current_ids ) ) {
                $current_ids = [];
            } else {
                $current_ids = array_map( 'intval', (array) $current_ids );
                sort( $current_ids );
            }

            if ( $current_ids !== $to_set ) {
                wp_set_object_terms( $post_id, $to_set, $local_tax, false );
            }
        }
    }
}

function attach_meta_to_rows( \wpdb $ext, array $creds, array $rows, ?int $blog_id, array $meta_keys = [] ) {
    if ( ! $rows ) {
        return $rows;
    }

    $post_ids = array_map( static fn( $r ) => (int) $r['ID'], $rows );
    $post_ids = array_values( array_unique( array_filter( $post_ids, static fn( $v ) => $v > 0 ) ) );

    if ( ! $post_ids ) {
        return $rows;
    }

    $postmeta = resolve_remote_postmeta_table( $creds, $blog_id );

    $ph_ids = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
    $params = $post_ids;

    $sql = "SELECT post_id, meta_key, meta_value FROM {$postmeta} WHERE post_id IN ($ph_ids)";

    if ( $meta_keys ) {
        $meta_keys = array_values( array_filter( array_map( 'strval', (array) $meta_keys ), static fn( $k ) => $k !== '' ) );

        if ( $meta_keys ) {
            $ph_keys = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
            $sql .= " AND meta_key IN ($ph_keys)";
            $params = array_merge( $params, $meta_keys );
        }
    }

    $prepared = $ext->prepare( $sql, $params );
    $meta_rows = $ext->get_results( $prepared, ARRAY_A ) ?: [];

    $by_post = [];

    foreach ( $meta_rows as $m ) {
        $pid = (int) $m['post_id'];
        $k   = (string) $m['meta_key'];
        $v   = maybe_unserialize( $m['meta_value'] );

        if ( ! isset( $by_post[$pid][$k] ) ) {
            $by_post[$pid][$k] = $v;
        } else {
            $cur = $by_post[$pid][$k];
            $by_post[$pid][$k] = is_array( $cur ) ? array_merge( $cur, [$v] ) : [$cur, $v];
        }
    }

    foreach ( $rows as &$r ) {
        $pid = (int) $r['ID'];
        $r['post_meta'] = $by_post[$pid] ?? [];
    }

    return $rows;
}

/**
 * Busca posts no WordPress remoto (via \wpdb do banco externo), sem usar o $wpdb global.
 *
 * @param array $args
 * @return array<int,mixed>|\WP_Error
 */
function remote_get_posts( array $args = [] ) {
    $ext = get_external_wpdb();

    if ( ! $ext ) {
        return new \WP_Error( 'no_connection', __( 'Sem conexão com o banco remoto.', 'hacklabr' ) );
    }

    $creds = get_credentials();

    $map_args = [
        'blog'           => 'blog_id',
        'id'             => 'ID',
        'number'         => 'numberposts',
        'order_by'       => 'orderby',
        'posts_per_page' => 'numberposts'
    ];

    $defaults = [
        'post_type'   => 'post',
        'post_status' => 'publish',
        'numberposts' => 5,
        'offset'      => 0,
        'orderby'     => 'post_date',
        'order'       => 'DESC',
        'include'     => [],
        'exclude'     => [],
        'search'      => '',
        'fields'      => 'all',
        'blog_id'     => null,
        'with_meta'   => false,
        'meta_keys'   => []
    ];

    foreach ( $args as $key => $value ) {
        if ( isset( $map_args[$key] ) ) {
            $canonical = $map_args[$key];
            if ( ! array_key_exists( $canonical, $args ) ) {
                $args[$canonical] = $value;
            }
        }
    }

    $a = wp_parse_args( $args, $defaults );


    $numberposts = max( 1, min( 100000, (int) $a['numberposts'] ) );
    $offset = max( 0, (int) $a['offset'] );

    $allowed_orderby = ['ID', 'post_date', 'post_title'];
    $orderby = in_array( $a['orderby'], $allowed_orderby, true ) ? $a['orderby'] : 'post_date';
    $order   = strtoupper( (string) $a['order'] ) === 'ASC' ? 'ASC' : 'DESC';

    $fields = ( $a['fields'] === 'ids' ) ? 'ids' : 'all';

    $table_posts = resolve_remote_posts_table( $creds, $a['blog_id'] );
    $table_posts_quoted = $table_posts;

    $where_sql = [];
    $params    = [];

    $build_in = static function (string $field, $value, array &$params): string {
        if ( is_array( $value ) ) {
            $value = array_values( array_filter( array_map( 'strval', $value ), static fn( $v ) => $v !== '' ) );
            if ( ! $value ) {
                return '1=0';
            }

            $ph = implode( ',', array_fill( 0, count( $value ), '%s' ) );
            foreach ( $value as $v ) $params[] = $v;
            return "{$field} IN ($ph)";
        }
        $params[] = (string)$value;
        return "{$field} = %s";
    };

    $where_sql[] = $build_in( 'post_type',   $a['post_type'],   $params );
    $where_sql[] = $build_in( 'post_status', $a['post_status'], $params );

    if ( ! empty( $a['include'] ) ) {
        $ids = array_values( array_filter( array_map( 'intval', (array) $a['include'] ), static fn( $v ) => $v > 0 ) );
        if ( $ids ) {
            $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            foreach ( $ids as $id ) $params[] = $id;
            $where_sql[] = "ID IN ($ph)";
        } else {
            $where_sql[] = '1=0';
        }
    }

    if ( ! empty( $a['exclude'] ) ) {
        $ids = array_values( array_filter( array_map( 'intval', (array) $a['exclude'] ), static fn( $v ) => $v > 0 ) );
        if ( $ids ) {
            $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            foreach ( $ids as $id ) $params[] = $id;
            $where_sql[] = "ID NOT IN ($ph)";
        }
    }

    // Busca simples (title + content)
    if ( ! empty( $a['search'] ) ) {
        $like = '%' . $ext->esc_like( (string) $a['search'] ) . '%';
        $where_sql[] = '(post_title LIKE %s OR post_content LIKE %s)';
        $params[] = $like;
        $params[] = $like;
    }

    $where = $where_sql ? ( 'WHERE ' . implode( ' AND ', $where_sql ) ) : '';

    $select_cols = ( $fields === 'ids' )
        ? 'ID'
        : '*';

    $sql = "
        SELECT {$select_cols}
          FROM {$table_posts_quoted}
          {$where}
      ORDER BY {$orderby} {$order}
         LIMIT {$numberposts} OFFSET {$offset}
    ";

    $prepared = $ext->prepare( $sql, $params );

    if ( $fields === 'ids' ) {
        $ids = $ext->get_col( $prepared );
        return array_map( 'intval', $ids ?: [] );
    }

    $rows = $ext->get_results( $prepared, ARRAY_A );

    if ( $rows && $fields !== 'ids' && ! empty( $a['with_meta'] ) ) {
        $wanted_keys = is_string( $a['meta_keys'] ) ? [$a['meta_keys']] : (array) $a['meta_keys'];
        $rows = attach_meta_to_rows( $ext, $creds, $rows, $a['blog_id'], $wanted_keys );
    }

    return $rows ?: [];
}

function extract_image_urls( string $html ): array {
    $urls = [];

    // src="..."/src='...'
    if ( preg_match_all( '#\s(?:src)=["\']([^"\']+\.(?:png|jpe?g|gif|webp|svg))["\']#i', $html, $m ) ) {
        $urls = array_merge( $urls, $m[1] );
    }
    // srcset="url w, url2 w"
    if ( preg_match_all( '#\s(?:srcset)=["\']([^"\']+)["\']#i', $html, $m ) ) {
        foreach ( $m[1] as $list ) {
            foreach ( preg_split('/\s*,\s*/', $list ) as $entry ) {
                $parts = preg_split( '/\s+/', trim( $entry ) );
                $u = $parts[0] ?? '';
                if ( $u && preg_match( '#\.(png|jpe?g|gif|webp|svg)(\?.*)?$#i', $u ) ) {
                    $urls[] = $u;
                }
            }
        }
    }

    // <a href="...img.ext">
    if ( preg_match_all( '#<a[^>]+href=["\']([^"\']+\.(?:png|jpe?g|gif|webp|svg))["\']#i', $html, $m ) ) {
        $urls = array_merge( $urls, $m[1] );
    }

    $urls = array_values( array_unique( array_map( 'esc_url_raw', $urls ) ) );
    return $urls;
}

/** Substitui URLs no conteúdo (inclusive dentro de srcset). */
function replace_urls_in_content( string $html, array $map ): string {
    if ( ! $map || $html === '' ) return $html;
    return strtr( $html, $map );
}

function build_uploads_url_map( string $old_base, string $new_base, ?int $remote_blog_id ): array {
    $old = rtrim( $old_base );
    $new = rtrim( $new_base );

    if ( $old === '' || $new === '' || $old === $new ) {
        return [];
    }

    $pairs = [];

    if ( $remote_blog_id && $remote_blog_id > 1 ) {
        $pairs["{$old}/sites/{$remote_blog_id}"] = $new;

        if ( str_starts_with( $old, 'http://' ) ) {
            $pairs[preg_replace( '#^http://#', '//', "{$old}/sites/{$remote_blog_id}", 1 )] = $new;
        } elseif ( str_starts_with( $old, 'https://' ) ) {
            $pairs[preg_replace( '#^https://#', '//', "{$old}/sites/{$remote_blog_id}", 1 )] = $new;
        }
    }

    $pairs[$old] = $new;

    if ( str_starts_with( $old, 'http://' ) ) {
        $pairs[preg_replace( '#^http://#', '//', $old, 1 )] = $new;
    } elseif ( str_starts_with( $old, 'https://' ) ) {
        $pairs[preg_replace( '#^https://#', '//', $old, 1 )] = $new;
    }

    return $pairs;
}
