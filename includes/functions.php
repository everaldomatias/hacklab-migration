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

function run_import( array $args = [] ) : array {
    $defaults = [
        'attachments_chunk' => 500,
        'blog_id'           => null,
        'dry_run'           => false,
        'fetch'             => ['numberposts' => 10],
        'fn_pos'            => null,
        'fn_pre'            => null,
        'assign_terms'      => true,
        'map_users'         => true,
        'meta_ops'          => [],
        'term_add'          => [],
        'term_set'          => [],
        'term_rm'           => [],
        'target_post_type'  => '',
        'with_media'        => true,
        'write_mode'        => 'upsert',
        'run_id'            => 0
    ];

    $options = wp_parse_args( $args, $defaults );

    if ( (int) $options['run_id'] <= 0 && ! $options['dry_run'] ) {
        $options['run_id'] = next_import_run_id();
    }

    if ( empty( $options['uploads_base'] ) ) {
        $creds = get_credentials();
        if ( ! empty( $creds['uploads_base'] ) ) {
            $options['uploads_base'] = (string) $creds['uploads_base'];
        }
    }

    $posts_summary = import_remote_posts( [
        'fetch'            => $options['fetch'],
        'media'            => $options['with_media'],
        'dry_run'          => $options['dry_run'],
        'fn_pre'           => $options['fn_pre'],
        'fn_pos'           => $options['fn_pos'],
        'assign_terms'     => (bool) $options['assign_terms'],
        'map_users'        => (bool) $options['map_users'],
        'meta_ops'         => (array) $options['meta_ops'],
        'term_add'         => (array) $options['term_add'],
        'term_set'         => (array) $options['term_set'],
        'term_rm'          => (array) $options['term_rm'],
        'target_post_type' => (string) $options['target_post_type'],
        'uploads_base'     => (string) $options['uploads_base'],
        'write_mode'       => $options['write_mode'],
        'run_id'           => (int) $options['run_id']
    ] );

    $rows = $posts_summary['rows'] ?? [];
    $map  = $posts_summary['map'] ?? [];

    $blog_id = (int) ( $options['blog_id'] ?? ( $options['fetch']['blog_id'] ?? 1 ) );

    $attachments_summary = [
        'content_rewritten' => 0,
        'errors'            => [],
        'found_posts'       => 0,
        'map'               => [],
        'missing_files'     => [],
        'registered'        => 0,
        'reused'            => 0,
        'thumbnails_set'    => 0
    ];

    if ( $options['with_media'] && $rows && ! $options['dry_run'] ) {
        $attachments_summary = import_remote_attachments( [
            'blog_id'      => $blog_id,
            'chunk'        => (int) $options['attachments_chunk'],
            'dry_run'      => $options['dry_run'],
            'local_map'    => $map,
            'uploads_base' => (string) $options['uploads_base'],
            'rows'         => $rows,
            'run_id'       => (int) $options['run_id']
        ] );
    }

    return [
        'posts'       => $posts_summary,
        'attachments' => $attachments_summary,
        'run_id'      => (int) $options['run_id'],
        'errors'      => array_merge(
            (array) ( $posts_summary['errors'] ?? [] ),
            (array) ( $attachments_summary['errors'] ?? [] )
        )
    ];
}

/**
 * Log message with context
 */
function log_message( string $message, string $level = 'info' ) : void {}

function fetch_remote_terms_for_posts( array $post_ids, ?int $blog_id = null, array $only_tax = [] ): array {
    $ext = get_external_wpdb();
    if ( ! $ext instanceof \wpdb ) return [];

    $post_ids = array_values( array_unique( array_map( 'intval', $post_ids ) ) );
    if ( ! $post_ids ) return [];

    $creds  = get_credentials();
    $tables = resolve_remote_terms_tables( $creds, $blog_id );

    if (
        empty( $tables['term_taxonomy'] )
        || empty( $tables['term_relationships'] )
        || empty( $tables['terms'] )
    ) {
        return [];
    }

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
            SELECT DISTINCT tr.object_id AS post_id, tt.taxonomy, t.term_id, t.name, t.slug, tt.parent
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
                SELECT DISTINCT tr.object_id AS post_id, tt.taxonomy, t.term_id, t.name, t.slug, tt.parent
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

        $meta_by_term = [];
        if ( $rows && ! empty( $tables['termmeta'] ) ) {
            $term_ids = array_values( array_unique( array_map(
                static fn( $row ) => (int) ( $row['term_id'] ?? 0 ),
                $rows
            ) ) );

            if ( $term_ids ) {
                $ph_meta   = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
                $meta_sql  = "
                    SELECT term_id, meta_key, meta_value
                      FROM {$tables['termmeta']}
                     WHERE term_id IN ({$ph_meta})
                     ORDER BY meta_id ASC
                ";
                $meta_stmt = $ext->prepare( $meta_sql, ...$term_ids );

                if ( $meta_stmt === null ) {
                    $ids_str = implode( ',', array_map( 'intval', $term_ids ) );
                    $meta_stmt = "
                        SELECT term_id, meta_key, meta_value
                          FROM {$tables['termmeta']}
                         WHERE term_id IN ({$ids_str})
                         ORDER BY meta_id ASC
                    ";
                }

                $meta_rows = $ext->get_results( $meta_stmt, ARRAY_A ) ?: [];
                foreach ( $meta_rows as $meta_row ) {
                    $tid = (int) ( $meta_row['term_id'] ?? 0 );
                    if ( $tid <= 0 ) {
                        continue;
                    }

                    $meta_by_term[ $tid ][] = [
                        'meta_key'   => (string) ( $meta_row['meta_key']   ?? '' ),
                        'meta_value' => (string) ( $meta_row['meta_value'] ?? '' ),
                    ];
                }
            }
        }

        foreach ( $rows as $r ) {
            $pid = (int) ( $r['post_id'] ?? 0 );
            if ( $pid <= 0 ) { continue; }
            $tx  = (string) ( $r['taxonomy'] ?? '' );
            if ( $tx === '' ) { continue; }
            $rid = (int) ( $r['term_id'] ?? 0 );
            $parent_id = (int) ( $r['parent'] ?? 0 );

            $out[ $pid ][ $tx ][] = [
                'term_id'    => $rid,
                'name'       => (string) ( $r['name']    ?? '' ),
                'slug'       => (string) ( $r['slug']    ?? '' ),
                'parent_id'  => $parent_id,
                'meta'       => $meta_by_term[ $rid ] ?? [],
            ];
        }
    }

    return $out;
}

function ensure_terms_and_assign( int $post_id, string $post_type, array $terms_by_tax, array $tax_map = [], ?int $blog_id = null, int $run_id = 0 ) : void {
    if ( ! $terms_by_tax ) return;

    $allowed_tax = array_fill_keys( get_object_taxonomies( $post_type, 'names' ), true );

    static $term_cache = [];
    static $meta_synced = [];

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
            $remote_term_id = (int) ( $t['term_id'] ?? 0 );
            $term_meta      = is_array( $t['meta'] ?? null ) ? $t['meta'] : [];
            $remote_parent_id = (int) ( $t['parent_id'] ?? 0 );
            $parent_slug = (string) ( $t['parent_slug'] ?? '' );
            $parent_name = (string) ( $t['parent_name'] ?? '' );

            if ( $slug === '' && $name !== '' ) {
                $slug = sanitize_title( $name );
            }
            if ( $slug === '' && $name === '' ) {
                continue;
            }

            $local_term_id = 0;

            if ( isset( $term_cache[ $local_tax ][ $slug ] ) ) {
                $local_term_id = (int) $term_cache[ $local_tax ][ $slug ];
            }

            if ( $local_term_id <= 0 ) {
                $exists = term_exists( $slug, $local_tax );

                if ( ! $exists && $name !== '' ) {
                    $exists = term_exists( $name, $local_tax );
                }

                if ( $exists && ! is_wp_error( $exists ) ) {
                    $local_term_id = is_array( $exists ) ? (int) ( $exists['term_id'] ?? 0 ) : (int) $exists;
                }
            }

            $created_term = false;

            if ( $local_term_id <= 0 ) {
                $insert_args = [];

                if ( $slug !== '' ) {
                    $insert_args['slug'] = $slug;
                }

                if ( $remote_parent_id > 0 || $parent_slug !== '' || $parent_name !== '' ) {
                    $parent_id   = 0;

                    if ( $parent_slug === '' && $parent_name === '' && $remote_parent_id > 0 ) {
                        $parent_existing = term_exists( (int) $remote_parent_id, $local_tax );
                        if ( $parent_existing && ! is_wp_error( $parent_existing ) ) {
                            $parent_id = is_array( $parent_existing ) ? (int) ( $parent_existing['term_id'] ?? 0 ) : (int) $parent_existing;
                        }
                    }

                    if ( $parent_id <= 0 && $parent_slug !== '' ) {
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
                    $local_term_id = (int) $ins['term_id'];
                    $created_term = true;
                }
            }

            if ( $local_term_id <= 0 ) {
                continue;
            }

            $term_cache[ $local_tax ][ $slug ] = $local_term_id;
            $to_set[] = $local_term_id;

            $meta_sync_key = $local_term_id . '|' . ( $blog_id ?? 1 );
            if ( ! isset( $meta_synced[ $meta_sync_key ] ) ) {
                if ( $term_meta ) {
                    foreach ( $term_meta as $m ) {
                        $k = $m['meta_key']   ?? '';
                        $v = $m['meta_value'] ?? '';

                        if ( $k === '' ) {
                            continue;
                        }

                        if ( $k === '_hacklab_migration_source_id' || $k === '_hacklab_migration_source_blog' ) {
                            continue;
                        }

                        update_term_meta( $local_term_id, (string) $k, maybe_unserialize( $v ) );
                    }
                }

                if ( $remote_term_id > 0 ) {
                    update_term_meta( $local_term_id, '_hacklab_migration_source_id', $remote_term_id );
                    if ( $blog_id ) {
                        update_term_meta( $local_term_id, '_hacklab_migration_source_blog', $blog_id );
                    }
                }

                if ( $run_id > 0 && $created_term ) {
                    update_term_meta( $local_term_id, '_hacklab_migration_import_run_id', $run_id );
                }

                $meta_synced[ $meta_sync_key ] = true;
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

function attach_meta_to_rows( \wpdb $ext, array $creds, array $rows, ?int $blog_id ) {
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

    $prepared = $ext->prepare( $sql, ...$params );
    $meta_rows = $ext->get_results( $prepared, ARRAY_A ) ?: [];

    $contains_object = static function ( $val ) use ( &$contains_object ): bool {
        if ( is_object( $val ) ) {
            return true;
        }

        if ( is_array( $val ) ) {
            foreach ( $val as $v ) {
                if ( $contains_object( $v ) ) {
                    return true;
                }
            }
        }

        return false;
    };

    $safe_unserialize = static function ( $value ) use ( $contains_object ) {
        if ( is_object( $value ) ) {
            return maybe_serialize( $value );
        }

        if ( is_array( $value ) && $contains_object( $value ) ) {
            return maybe_serialize( $value );
        }

        if ( ! is_string( $value ) ) {
            return $value;
        }

        if ( ! is_serialized( $value ) ) {
            return $value;
        }

        if ( preg_match( '/^[OCais]:/i', ltrim( $value ) ) ) {
            $un = @unserialize( $value, ['allowed_classes' => false] );
            if ( $un !== false || $value === 'b:0;' ) {
                if ( $contains_object( $un ) ) {
                    return $value;
                }
                return $un;
            }
        }

        $un = @unserialize( $value, ['allowed_classes' => false] );

        if ( $un !== false || $value === 'b:0;' ) {
            if ( $contains_object( $un ) ) {
                return $value;
            }
            return $un;
        }

        return $value;
    };

    $by_post = [];

    foreach ( $meta_rows as $m ) {
        $pid = (int) $m['post_id'];
        $k   = (string) $m['meta_key'];
        $v   = $safe_unserialize( $m['meta_value'] );

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
function get_remote_posts( array $args = [] ) {
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
        'post_modified'  => 'post_modified_gmt',
        'posts_per_page' => 'numberposts'
    ];

    $defaults = [
        'post_type'         => 'post',
        'post_status'       => 'publish',
        'numberposts'       => 10,
        'limit'             => null,
        'offset'            => 0,
        'orderby'           => 'post_date',
        'order'             => 'DESC',
        'include'           => [],
        'exclude'           => [],
        'search'            => '',
        'fields'            => 'all',
        'blog_id'           => null,
        'post_modified_gmt' => null,
        'modified_after'    => null,
        'modified_before'   => null,
        'id_gte'            => null,
        'id_lte'            => null,
        'tax_query'         => []
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

    if ( ! empty( $a['post_modified'] ) && empty( $a['post_modified_gmt'] ) ) {
        if ( is_int( $a['post_modified'] ) ) {
            $a['post_modified_gmt'] = gmdate( 'Y-m-d H:i:s', (int) $a['post_modified'] );
        } else {
            $ts = strtotime( (string) $a['post_modified'] );
            if ( $ts ) $a['post_modified_gmt'] = gmdate( 'Y-m-d H:i:s', $ts );
        }
    }

    $to_gmt = static function ( $value ): ?string {
        if ( $value === null || $value === '' ) {
            return null;
        }
        if ( is_int( $value ) || ctype_digit( (string) $value ) ) {
            return gmdate( 'Y-m-d H:i:s', (int) $value );
        }
        $ts = strtotime( (string) $value );
        return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
    };

    $modified_after  = $to_gmt( $a['modified_after'] );
    $modified_before = $to_gmt( $a['modified_before'] );

    $limit_raw = $a['limit'] ?? $a['numberposts'];
    $limit = ( $limit_raw === null || $limit_raw === '' ) ? null : max( 0, (int) $limit_raw );
    $offset = ( $limit !== null && isset( $args['offset'] ) ) ? max( 0, (int) $a['offset'] ) : 0;
    $post_status = $a['post_status'] == 'any' ? ['publish', 'pending', 'draft', 'future', 'private'] : $a['post_status'];

    $allowed_orderby = ['ID', 'post_date', 'post_title', 'post_modified', 'post_modified_gmt'];
    $orderby_default = ( $modified_after || $modified_before || ! empty( $a['post_modified_gmt'] ) ) ? 'post_modified_gmt' : 'post_date';
    $orderby = in_array( $a['orderby'], $allowed_orderby, true ) ? $a['orderby'] : $orderby_default;
    $order   = strtoupper( (string) $a['order'] ) === 'ASC' ? 'ASC' : 'DESC';

    $fields = ( $a['fields'] === 'ids' ) ? 'ids' : 'all';

    $table_posts = resolve_remote_posts_table( $creds, $a['blog_id'] );
    $table_posts_quoted = $table_posts;

    $where_sql = [];
    $params    = [];

    $join_tax = '';
    $tax_query = is_array( $a['tax_query'] ?? [] ) ? $a['tax_query'] : [];

    $tax_relation = 'AND';
    $tax_queries  = [];

    if ( $tax_query ) {
        if ( isset( $tax_query['taxonomy'] ) ) {
            $tax_relation = 'AND';
            $tax_queries  = [ $tax_query ];
        } else {
            $tax_relation = strtoupper( (string) ( $tax_query['relation'] ?? 'AND' ) );
            $tax_relation = in_array( $tax_relation, [ 'AND', 'OR' ], true ) ? $tax_relation : 'AND';

            foreach ( $tax_query as $k => $clause ) {
                if ( $k === 'relation' ) {
                    continue;
                }

                if ( ! is_array( $clause ) ) {
                    continue;
                }

                $tax_queries[] = $clause;
            }
        }
    }

    if ( $tax_queries ) {
        $tables = resolve_remote_terms_tables( $creds, $a['blog_id'] );

        $table_terms              = $tables['terms'];
        $table_term_taxonomy      = $tables['term_taxonomy'];
        $table_term_relationships = $tables['term_relationships'];

        $join_index      = 0;
        $tax_where_parts = [];

        foreach ( $tax_queries as $clause ) {
            $taxonomy = trim( (string) ( $clause['taxonomy'] ?? '' ) );
            $field    = (string) ( $clause['field'] ?? 'slug' );
            $terms    = (array) ( $clause['terms'] ?? [] );

            $terms = array_values( array_filter( array_map( 'trim', $terms ) ) );

            if ( $taxonomy === '' || ! $terms ) {
                continue;
            }

            $join_index++;
            $tr_alias = 'tr' . $join_index;
            $tt_alias = 'tt' . $join_index;
            $t_alias  = 't' . $join_index;

            $join_tax .= "
                INNER JOIN {$table_term_relationships} {$tr_alias} ON {$tr_alias}.object_id = {$table_posts_quoted}.ID
                INNER JOIN {$table_term_taxonomy} {$tt_alias} ON {$tt_alias}.term_taxonomy_id = {$tr_alias}.term_taxonomy_id
                INNER JOIN {$table_terms} {$t_alias} ON {$t_alias}.term_id = {$tt_alias}.term_id
            ";

            $clause_sql_parts   = [];
            $clause_sql_parts[] = "{$tt_alias}.taxonomy = %s";
            $params[]           = $taxonomy;

            $field_expr = "{$t_alias}.slug";
            $ph         = implode( ',', array_fill( 0, count( $terms ), '%s' ) );

            foreach ( $terms as $term ) {
                $params[] = $term;
            }

            $clause_sql_parts[] = "{$field_expr} IN ({$ph})";

            $tax_where_parts[] = '( ' . implode( ' AND ', $clause_sql_parts ) . ' )';
        }

        if ( $tax_where_parts ) {
            $where_sql[] = '( ' . implode( " {$tax_relation} ", $tax_where_parts ) . ' )';
        }
    }

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

    $where_sql[] = $build_in( "{$table_posts_quoted}.post_type",   $a['post_type'],   $params );
    $where_sql[] = $build_in( "{$table_posts_quoted}.post_status", $post_status, $params );

    if ( ! empty( $a['include'] ) ) {
        $ids = array_values( array_filter( array_map( 'intval', (array) $a['include'] ), static fn( $v ) => $v > 0 ) );
        if ( $ids ) {
            $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            foreach ( $ids as $id ) $params[] = $id;
            $where_sql[] = "{$table_posts_quoted}.ID IN ($ph)";
        } else {
            $where_sql[] = '1=0';
        }
    }

    if ( ! empty( $a['exclude'] ) ) {
        $ids = array_values( array_filter( array_map( 'intval', (array) $a['exclude'] ), static fn( $v ) => $v > 0 ) );
        if ( $ids ) {
            $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            foreach ( $ids as $id ) $params[] = $id;
            $where_sql[] = "{$table_posts_quoted}.ID NOT IN ($ph)";
        }
    }

    // Busca simples (title + content)
    if ( ! empty( $a['search'] ) ) {
        $like = '%' . $ext->esc_like( (string) $a['search'] ) . '%';
        $where_sql[] = "({$table_posts_quoted}.post_title LIKE %s OR {$table_posts_quoted}.post_content LIKE %s)";
        $params[] = $like;
        $params[] = $like;
    }

    if ( ! empty( $a['post_modified_gmt'] ) ) {
        $after = is_int( $a['post_modified_gmt'] ) ? gmdate( 'Y-m-d H:i:s', (int) $a['post_modified_gmt'] ) : (string) $a['post_modified_gmt'];
        $where_sql[] = "{$table_posts_quoted}.post_modified_gmt >= %s";
        $params[]    = $after;
    }

    if ( $modified_after ) {
        $where_sql[] = "{$table_posts_quoted}.post_modified_gmt >= %s";
        $params[]    = $modified_after;
    }

    if ( $modified_before ) {
        $where_sql[] = "{$table_posts_quoted}.post_modified_gmt < %s";
        $params[]    = $modified_before;
    }

    if ( ! empty( $a['id_gte'] ) ) {
        $where_sql[] = "{$table_posts_quoted}.ID >= %d";
        $params[]    = (int) $a['id_gte'];
    }

    if ( ! empty( $a['id_lte'] ) ) {
        $where_sql[] = "{$table_posts_quoted}.ID <= %d";
        $params[]    = (int) $a['id_lte'];
    }

    $where = $where_sql ? ( 'WHERE ' . implode( ' AND ', $where_sql ) ) : '';

    $select_cols = ( $fields === 'ids' )
        ? 'ID'
        : '*';

    $sql = "
        SELECT {$select_cols}
            FROM {$table_posts_quoted}
            {$join_tax}
            {$where}
        ORDER BY {$table_posts_quoted}.{$orderby} {$order}
    ";

    if ( $limit !== null && $limit > 0 ) {
        $sql     .= " LIMIT %d";
        $params[] = $limit;

        if ( isset( $args['offset'] ) && $offset > 0 ) {
            $sql     .= " OFFSET %d";
            $params[] = $offset;
        }
    }

    $prepared = $ext->prepare( $sql, ...$params );

    if ( $fields === 'ids' ) {
        $ids = $ext->get_col( $prepared );
        return array_map( 'intval', $ids ?: [] );
    }

    $rows = $ext->get_results( $prepared, ARRAY_A );

    if ( $rows ) {
        $rows = attach_meta_to_rows( $ext, $creds, $rows, $a['blog_id'] );
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


/**
 * Obtém todas as meta keys únicas utilizadas por um post type no banco de dados remoto.
 *
 * Em caso de erro na execução da consulta SQL, o erro é registrado através da action "logger".
 *
 * @since 0.0.1
 *
 * @version 1.0.0
 *
 * @param string $post_type
 *     O post type cujas meta keys devem ser buscadas no banco remoto.
 *     Caso seja passado vazio, a função retorna um array vazio.
 *
 * @param int $blog_id
 *     ID do blog remoto em instalações multisite. Usado para resolver as tabelas
 *     corretas (`wp_X_posts` e `wp_X_postmeta`).
 *     Padrão: 1.
 *
 * @return string[]
 *     Um array contendo meta keys únicas associadas ao post_type informado.
 *     Retorna um array vazio caso não seja possível conectar, resolver tabelas
 *     ou caso nenhuma meta key seja encontrada.
 */
function get_remote_meta_keys( string $post_type, int $blog_id = 1 ): array {
    if ( $post_type === '' ) {
        return [];
    }

    $ext = get_external_wpdb();

    if ( ! $ext instanceof \wpdb ) {
        return [];
    }

    $creds = get_credentials();

    $posts_table    = resolve_remote_posts_table( $creds, $blog_id );
    $postmeta_table = resolve_remote_postmeta_table( $creds, $blog_id );

    if ( ! $posts_table || ! $postmeta_table ) {
        return [];
    }

    $sql = "
        SELECT DISTINCT pm.meta_key
        FROM {$postmeta_table} AS pm
        INNER JOIN {$posts_table} AS p
            ON p.ID = pm.post_id
        WHERE p.post_type = %s
        ORDER BY pm.meta_key ASC
    ";

    $prepared = $ext->prepare( $sql, $post_type );
    $keys = $ext->get_col( $prepared );

    if ( $ext->last_error ) {
        do_action( 'logger', [ 'context' => 'get_remote_meta_keys', 'error' => $ext->last_error ] );
    }

    if ( ! is_array( $keys ) ) {
        return [];
    }

    $keys = array_filter( array_map( 'strval', $keys ) );
    $keys = array_values( array_unique( $keys ) );

    return $keys;
}

/**
 * Obtém meta keys e um exemplo de valor para cada uma em uma única consulta.
 *
 * @param string $post_type Post type remoto.
 * @param int    $blog_id   ID do blog remoto (multisite) ou 1 para single.
 *
 * @return array<string,string> Mapa meta_key => exemplo de valor.
 */
function get_remote_meta_keys_with_example( string $post_type, int $blog_id = 1 ): array {
    $out = [];

    if ( $post_type === '' ) {
        return $out;
    }

    $cache_key = 'hacklab_migration_meta_' . $post_type . '_' . $blog_id;

    $cached = get_transient( $cache_key );
    if ( is_array( $cached ) ) {
        return $cached;
    }

    $ext = get_external_wpdb();

    if ( ! $ext instanceof \wpdb ) {
        return $out;
    }

    $creds = get_credentials();

    $posts_table    = resolve_remote_posts_table( $creds, $blog_id );
    $postmeta_table = resolve_remote_postmeta_table( $creds, $blog_id );

    if ( ! $posts_table || ! $postmeta_table ) {
        return $out;
    }

    $sql = "
        SELECT pm.meta_key, pm.meta_value
          FROM (
            SELECT
              pm.meta_key,
              COALESCE(
                MAX(CASE WHEN pm.meta_value IS NOT NULL AND pm.meta_value <> '' THEN pm.meta_id END),
                MAX(pm.meta_id)
              ) AS pick_id
              FROM {$postmeta_table} pm
              JOIN {$posts_table} p ON p.ID = pm.post_id
             WHERE p.post_type = %s
             GROUP BY pm.meta_key
          ) picked
          JOIN {$postmeta_table} pm ON pm.meta_id = picked.pick_id
    ";

    $prepared = $ext->prepare( $sql, $post_type );
    if ( $prepared === null ) {
        return $out;
    }

    $rows = $ext->get_results( $prepared, ARRAY_A ) ?: [];

    foreach ( $rows as $row ) {
        $k = isset( $row['meta_key'] ) ? (string) $row['meta_key'] : '';
        if ( $k === '' ) {
            continue;
        }
        $out[ $k ] = (string) ( $row['meta_value'] ?? '' );
    }

    set_transient( $cache_key, $out, 60 * MINUTE_IN_SECONDS );

    return $out;
}

/**
 * Obtém a lista de post types existentes no banco de dados remoto.
 *
 * @since 0.0.1
 *
 * @version 1.0.0
 *
 * @param int $blog_id
 *     ID do blog remoto em instalações multisite. Deve ser maior ou igual a 1.
 *     Em instalações single site, normalmente o valor é 1.
 *
 * @return string[]
 *     Array contendo os nomes dos post types disponíveis no WordPress externo.
 *     Retorna um array vazio caso a conexão falhe, a tabela não exista ou
 *     nenhum post type seja encontrado.
 */
function get_remote_post_types( int $blog_id ): array {
    $ext = get_external_wpdb();

    if ( ! $ext instanceof \wpdb || $blog_id < 1 ) {
        return [];
    }

    $creds = get_credentials();
    $posts_table = resolve_remote_posts_table( $creds, $blog_id );

    if ( ! $posts_table ) {
        return [];
    }

    $sql = "
        SELECT DISTINCT post_type
        FROM {$posts_table}
        WHERE post_type NOT LIKE '\\_%'
        ORDER BY post_type ASC
    ";

    $types = $ext->get_col( $sql );

    if ( ! is_array( $types ) ) {
        return [];
    }

    return array_values( array_filter( array_map( 'sanitize_key', $types ) ) );
}

/**
 * Aplica filtros de conteúdo ao texto fornecido.

 * @param string $content O conteúdo a ser filtrado.
 * @param array $row (Opcional) Dados adicionais associados ao conteúdo. Padrão é um array vazio.
 * @param array $options (Opcional) Opções adicionais para o processamento. Padrão é um array vazio.
 *
 * @return string O conteúdo filtrado.
 */
function apply_text_filters( string $content, array $row = [], array $options = [] ): string {
    $content = remove_divi_tags( $content );
    return $content;
}

/**
 * Remove tags e marcações específicas do tema Divi do conteúdo fornecido.
 *
 * Esta função processa o conteúdo (string) e remove elementos gerados pelo
 * construtor Divi que podem incluir:
 *  - shortcodes do Divi (ex.: [et_pb_section], [et_pb_row], [et_pb_column], ...),
 *  - wrappers e comentários inseridos pelo builder,
 *  - classes e atributos auxiliares (ex.: et_pb_*, et_builder_inner_content, ...).
 *
 * O objetivo é limpar o HTML para uso em contextos que não precisam ou não
 * suportam as marcações do Divi (por exemplo, migração de conteúdo, exibição
 * em temas distintos ou exportação). A função retorna o conteúdo modificado,
 * preservando o restante do HTML e do texto.
 *
 * @param string $content Conteúdo HTML/texto que será processado e limpo.
 * @return string Conteúdo processado sem as marcações/shortcodes específicos do Divi.
 * @example
 * // Exemplo:
 * // $clean = remove_divi_tags( $raw_content );
 * // echo $clean;
 *
 * @see https://www.elegantthemes.com/documentation/divi/
 */

function remove_divi_tags( $content ) {
    /*
     * 1. Extrai conteúdo interno de [et_pb_text]
     *    (mantém texto interno, remove o shortcode)
     */
    $content = preg_replace(
        '/\[et_pb_text[^\]]*\](.*?)\[\/et_pb_text\]/mis',
        '$1',
        $content
    );

    /*
     * 2. Extrai imagens de [et_pb_image ...]
     *    Mantém todos os atributos.
     *    Exemplo: [et_pb_image src="x.jpg" alt="y"]
     *    → <img src="x.jpg" alt="y">
     */
    $content = preg_replace(
        '/\[et_pb_image(.*?)\]/mis',
        '<img$1>',
        $content
    );

    /*
     * 3. Extrai iframes de [iframe ...] caso você use este shortcode
     *    Mantém todos os atributos.
     */
    $content = preg_replace(
        '/\[iframe(.*?)\]/mis',
        '<iframe$1></iframe>',
        $content
    );

    /*
     * 4. Remove qualquer outro shortcode do Divi
     *    Pega tudo que começa com [et_pb_ ...]
     *    Ex: [et_pb_row], [/et_pb_section], [et_pb_button ... /], etc
     */
    $content = preg_replace(
        '/\[\/?et_pb_[^\]]*\]/mis',
        '',
        $content
    );

    /*
     * 5. Remove sobras de múltiplas quebras de linha causadas pela remoção
     */
    $content = preg_replace( '/[\r\n]+/', "\n", $content );
    $content = trim( $content );

    return $content;
}


/**
 * Gera e retorna um ID sequencial para a execução atual de importação.
 *
 * @return int ID sequencial (>=1) ou 0 em caso de falha.
 */
function next_import_run_id(): int {
    $current = (int) get_option( 'hm_import_run_last', 0 );
    $next    = max( 1, $current + 1 );

    if ( get_option( 'hm_import_run_last', null ) === null ) {
        add_option( 'hm_import_run_last', $next, '', 'no' );
        return $next;
    }

    $ok = update_option( 'hm_import_run_last', $next, false );
    return $ok ? $next : 0;
}
