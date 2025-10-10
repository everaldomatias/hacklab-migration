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

/**
 * Resolve o nome completo da tabela de posts remota considerando multisite.
 * - Single: "{$prefix}posts"
 * - Multisite blog 1: "{$prefix}posts"
 * - Multisite blog N>1: "{$prefix}{$blog_id}_posts"
 */
function resolve_remote_posts_table( \wpdb $ext, array $creds, ?int $blog_id ): string {
    $prefix = ! empty( $creds['prefix'] ) ? (string) $creds['prefix'] : 'wp_';
    $is_ms  = ! empty( $creds['is_multisite'] );

    if ( ! $is_ms || ! $blog_id || (int) $blog_id === 1 ) {
        return $prefix . 'posts';
    }

    return $prefix . ( (int) $blog_id ) . '_posts';
}

function resolve_remote_postmeta_table( \wpdb $ext, array $creds, ?int $blog_id): string {
    $prefix = ! empty( $creds['prefix'] ) ? (string) $creds['prefix'] : 'wp_';
    $is_ms  = ! empty( $creds['is_multisite'] );

    if ( ! $is_ms || ! $blog_id || (int) $blog_id === 1 ) {
        return $prefix . 'postmeta';
    }

    return $prefix . ( (int) $blog_id ) . '_postmeta';
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

    $postmeta = resolve_remote_postmeta_table( $ext, $creds, $blog_id );

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

    $table_posts = resolve_remote_posts_table( $ext, $creds, $a['blog_id'] );
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

    $all_fields = [
        'ID',
        'post_author',
        'post_date',
        'post_date_gmt',
        'post_content',
        'post_title',
        'post_excerpt',
        'post_status',
        'comment_status',
        'ping_status',
        'post_password',
        'post_name',
        'to_ping',
        'pinged',
        'post_modified',
        'post_modified_gmt',
        'post_content_filtered',
        'post_parent',
        'guid',
        'menu_order',
        'post_type',
        'post_mime_type',
        'comment_count',
    ];

    $select_cols = ( $fields === 'ids' )
        ? 'ID'
        : implode( ', ', $all_fields );

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
