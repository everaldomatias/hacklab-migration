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

function resolve_remote_postmeta_table( \wpdb $ext, array $creds, ?int $blog_id ): string {
    $prefix = ! empty( $creds['prefix'] ) ? (string) $creds['prefix'] : 'wp_';
    $is_ms  = ! empty( $creds['is_multisite'] );

    if ( ! $is_ms || ! $blog_id || (int) $blog_id === 1 ) {
        return $prefix . 'postmeta';
    }

    return $prefix . ( (int) $blog_id ) . '_postmeta';
}

function resolve_remote_terms_tables( \wpdb $ext, array $creds, ?int $blog_id ): array {
    $prefix = ! empty( $creds['prefix'] ) ? (string) $creds['prefix'] : 'wp_';
    $is_ms  = ! empty( $creds['is_multisite'] );
    $mid = ( ! $is_ms || ! $blog_id || (int) $blog_id === 1 ) ? '' : ( (int) $blog_id . '_' );

    return [
        'terms' => $prefix . $mid . 'terms',
        'term_taxonomy' => $prefix . $mid . 'term_taxonomy',
        'term_relationships' => $prefix . $mid . 'term_relationships'
    ];
}

function fetch_remote_terms_for_posts( array $post_ids, ?int $blog_id = null, array $only_tax = [] ): array {
    $ext = get_external_wpdb();
    if ( ! $ext || ! $post_ids ) return [];

    $creds = get_credentials();
    $tables = resolve_remote_terms_tables( $ext, $creds, $blog_id );

    $post_ids = array_values( array_unique( array_map( 'intval', $post_ids ) ) );
    if ( ! $post_ids ) return [];

    $ph_ids = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
    $params = $post_ids;

    $sql = "
        SELECT tr.object_id AS post_id, tt.taxonomy, t.term_id, t.name, t.slug
          FROM {$tables['term_taxonomy']} tt
          JOIN {$tables['term_relationships']} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
          JOIN {$tables['terms']} t ON t.term_id = tt.term_id
         WHERE tr.object_id IN ({$ph_ids})
    ";

    if ( $only_tax ) {
        $only_tax = array_values( array_filter( array_map( 'strval', $only_tax ), static fn( $v ) => $v !== '' ) );
        if ( $only_tax ) {
            $ph_tx = implode( ',', array_fill( 0, count( $only_tax ), '%s' ) );
            $sql  .= " AND tt.taxonomy IN ({$ph_tx})";
            $params = array_merge( $params, $only_tax );
        }
    }

    $prepared = $ext->prepare( $sql, $params );

    $rows = $ext->get_results( $prepared, ARRAY_A ) ?: [];

    $out = [];
    foreach ( $rows as $r ) {
        $pid = (int) $r['post_id'];
        $tx  = (string) $r['taxonomy'];
        $out[$pid][$tx][] = [
            'term_id' => (int) $r['term_id'],
            'name'    => (string) $r['name'],
            'slug'    => (string) $r['slug']
        ];
    }
    return $out;
}

function ensure_terms_and_assign( int $post_id, string $post_type, array $terms_by_tax, array $tax_map = [] ) : void {
    if ( ! $terms_by_tax ) return;

    $allowed_tax = array_fill_keys( get_object_taxonomies( $post_type, 'names' ), true );

    foreach ( $terms_by_tax as $remote_tax => $term_list ) {
        $local_tax = $tax_map[$remote_tax] ?? $remote_tax;

        if ( ! taxonomy_exists( $local_tax ) || empty( $allowed_tax[$local_tax] ) ) {
            continue;
        }

        $to_set = [];
        foreach ( $term_list as $t ) {
            $slug = sanitize_title( $t['slug'] ?: $t['name'] );
            $name = $t['name'];

            $exists = term_exists( $slug, $local_tax );
            if ( ! $exists ) {
                $ins = wp_insert_term( $name, $local_tax, ['slug' => $slug] );
                if ( ! is_wp_error( $ins ) && ! empty( $ins['term_id'] ) ) {
                    $to_set[] = (int) $ins['term_id'];
                }
            } else {
                $term_id = is_array( $exists ) ? (int) $exists['term_id'] : (int) $exists;
                $to_set[] = $term_id;
            }
        }

        if ( $to_set ) {
            wp_set_object_terms( $post_id, array_values( array_unique( $to_set ) ), $local_tax, false );
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

/**
 * Importa posts do WP remoto para o site atual (single).
 *
 * Args aceitos:
 * - rows           : array de linhas em formato do remote_get_posts() (opcional)
 * - fetch          : array de args para chamar remote_get_posts() se 'rows' não for passado (opcional)
 * - replacements   : array de search-replace. Pode ser:
 *                    - ['old' => 'new', ...] (ordem preservada)
 *                    - [['from'=>'old','to'=>'new','regex'=>true], ...]
 * - dry_run        : bool (default false) — não cria nada, só simula
 *
 * Retorno:
 * - ['imported' => int,'updated' =>int, 'skipped' => int, 'attachments' => int, 'map' => [remote-id => local->id], 'errors'=>[...], 'args' => [...]]
 */
function import_remote_posts( array $args = [] ): array {
    $defaults = [
        'rows'         => null,
        'fetch'        => ['numberposts' => 10],
        'replacements' => [],
        'media'        => [
            'enabled' => true
        ],
        'dry_run'      => false,
        'changes'      => [
            // post_type destino e mapeamento de taxonomias (opcional)
            // 'post_type' => 'post',
            // 'tax_map'   => ['tag_remota' => 'post_tag', 'categoria_remota' => 'category'],
        ]
    ];

    $opt     = wp_parse_args( $args, $defaults );
    $media   = wp_parse_args( $opt['media'], $defaults['media'] );
    $changes = wp_parse_args( $opt['changes'], $defaults['changes'] );

    $summary = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'attachments' => 0, 'map' => [], 'errors' => [], 'args' => $opt['fetch']];

    $rows = is_array( $opt['rows'] ) ? $opt['rows'] : remote_get_posts( (array) $opt['fetch'] );

    if ( is_wp_error( $rows ) ) {
        return [
            'imported'    => 0,
            'updated'     => 0,
            'skipped'     => 0,
            'attachments' => 0,
            'map'         => [],
            'errors'      => [
                $rows->get_error_message()
            ],
            'args' => $opt['fetch']
        ];
    }

    if ( ! $rows ) {
        return $summary;
    }

    $blog_id = (int) ( $opt['fetch']['blog_id'] ?? $args['blog_id'] ?? 1 );
    $replacements_rules = normalize_replacements( $opt['replacements'] );

    $remote_ids = array_map( static fn( $r )=> (int) $r['ID'], $rows );
    $terms_map  = fetch_remote_terms_for_posts( $remote_ids, $blog_id );

    echo '<pre>';
        var_dump ( $terms_map );
    echo '</pre>';

    foreach ( $rows as $row ) {
        $remote_id   = (int) $row['ID'];
        $remote_type = (string) $row['post_type'];
        $remote_st   = (string) $row['post_status'];

        // Verifica se já foi importado (evita duplicidade)
        $existing = find_local_post( $remote_id, $blog_id );
        $is_update = $existing > 0;

        // Monta postarr
        $title   = apply_replacements( (string) $row['post_title'], $replacements_rules );
        $content = apply_replacements( (string) $row['post_content'], $replacements_rules );
        $excerpt = apply_replacements( (string) ( $row['post_excerpt'] ?? '' ), $replacements_rules );

        $post_type = isset( $changes['post_type'] ) && post_type_exists( $changes['post_type'] ) ? $changes['post_type'] : ( post_type_exists( $remote_type ) ? $remote_type : 'post' );

        $postarr = [
            'post_title'    => $title,
            'post_content'  => $content,
            'post_excerpt'  => $excerpt,
            'post_status'   => in_array( $remote_st, ['publish','draft','pending','private'], true ) ? $remote_st : 'draft',
            'post_type'     => $post_type,
            'post_date'     => (string) $row['post_date'],
            'post_date_gmt' => (string) ( $row['post_date_gmt'] ?? '' ),
            'post_author'   => (string) ( $row['post_author'] ?? '' ),
            'post_name'     => (string) ( $row['post_name'] ?? sanitize_title( $title ) )
        ];

        // Dry-run
        if ( $opt['dry_run'] ) {
            $summary['skipped']++;
            $summary['map'][$remote_id] = 0;
            continue;
        }

        // Metadados - @todo: adicionar replacements nos metadados?
        $post_meta = isset( $row['post_meta'] ) && is_array( $row['post_meta'] ) ? $row['post_meta'] : [];

        $postarr['meta_input'] = $post_meta;
        $postarr['meta_input']['_hacklab_migration_source_meta'] = $post_meta;
        $postarr['meta_input']['_hacklab_migration_source_meta']['post_type'] = $remote_type;
        $postarr['meta_input']['_hacklab_migration_last_update'] = time();

        // Cria ou atualiza o post
        $local_id = 0;

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

        // Taxonomias/termos
        $row_terms = $terms_map[$remote_id] ?? [];
        $tax_map   = isset( $changes['tax_map'] ) && is_array( $changes['tax_map'] ) ? $changes['tax_map'] : [];
        ensure_terms_and_assign( $local_id, $post_type, $row_terms, $tax_map );

        // Mídia (imagens no conteúdo) — opcional
        if ( ! empty( $media['enabled'] ) ) {
            [$new_content, $att_count] = import_images_in_content(
                (string) get_post_field( 'post_content', $local_id ),
                $local_id
            );
            if ( $att_count > 0 ) {
                $summary['attachments'] += $att_count;
                wp_update_post( [
                    'ID'           => $local_id,
                    'post_content' => $new_content,
                ] );
            }
        }

        $summary['map'][$remote_id] = $local_id;
    }

    return $summary;
}

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

function apply_replacements( string $text, array $rules ): string {
    foreach ( $rules as $r ) {
        if ( ! empty($r['regex'] ) ) {
            $text = preg_replace( $r['from'], $r['to'], $text );
        } else {
            // múltiplos 'from' plain em bloco
            if ( is_array( $r['from'] ) ) {
                $text = str_replace( $r['from'], $r['to'], $text );
            } else {
                $text = str_replace( (string) $r['from'], (string) $r['to'], $text );
            }
        }
    }
    return $text;
}

/**
 * Normaliza um array de substituições em um formato padronizado.
 *
 * Esta função aceita diferentes formatos de entrada para substituições e os converte
 * em um array de regras padronizado. Os formatos aceitos são:
 * - Lista indexada de arrays associativos no formato [['from' => ..., 'to' => ..., 'regex' => ...], ...].
 * - Array associativo simples no formato ['old' => 'new', ...].
 *
 * @since 1.0.0
 *
 * @param array $replacements Array de substituições a ser normalizado. Pode ser vazio,
 *                            uma lista indexada de arrays associativos ou um array associativo simples.
 *
 * @return array Retorna um array de regras normalizadas no formato:
 *               [['from' => ..., 'to' => ..., 'regex' => ...], ...].
 */
function normalize_replacements( $replacements ): array {
    $rules = [];
    if ( ! $replacements ) return $rules;

    // formato ['from'=>'to', ...]
    if ( \is_array( $replacements ) && array_values( $replacements ) === $replacements ) {
        // lista indexada? assume já no formato [['from'=>..., 'to'=>...], ...]
        foreach ( $replacements as $r ) {
            if ( isset( $r['from'], $r['to'] ) ) {
                $rules[] = ['from' => $r['from'], 'to' => $r['to'], 'regex' => !empty( $r['regex'] )];
            }
        }
        return $rules;
    }

    // formato associativo simples: ['old'=>'new', ...]
    if ( \is_array( $replacements ) ) {
        foreach ( $replacements as $from => $to ) {
            $rules[] = ['from' => $from, 'to' => $to, 'regex' => false];
        }
    }
    return $rules;
}

function import_images_in_content( string $html, int $post_id ): array {
    if ( $html === '' ) return [$html, 0];

    $urls = extract_image_urls( $html );
    if ( ! $urls ) return [$html, 0];

    $count = 0;
    $map   = [];

    foreach ( $urls as $u ) {
        $existing = find_attachment_by_source_url( $u );

        if ( $existing ) {
            $new = wp_get_attachment_url( $existing );
            if ( $new ) {
                $map[$u] = $new;
                $count++;
                continue;
            }
        }

        $att_id = sideload_attachment( $u, $post_id );
        if ( $att_id && ! is_wp_error( $att_id ) ) {
            add_post_meta( $att_id, '_hacklab_migration_source_url', esc_url_raw( $u ), true );
            $new = wp_get_attachment_url( (int) $att_id );
            if ( $new ) {
                $map[$u] = $new;
                $count++;
            }
        }
    }

    if ( $map ) {
        $html = replace_urls_in_content( $html, $map );
    }

    return [$html, $count];
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

function find_attachment_by_source_url( string $url ): int {
    $q = get_posts( [
        'post_type'             => 'attachment',
        'posts_per_page'        => 1,
        'meta_key'              => '_hacklab_migration_source_url',
        'meta_value'            => esc_url_raw( $url ),
        'fields'                => 'ids',
        'no_found_rows'         => true,
        'update_post_term_cache'=> false,
        'update_meta_cache'     => false
    ] );

    return $q ? (int)$q[0] : 0;
}

function sideload_attachment( string $url, int $post_id ) {
    if ( ! function_exists( 'download_url' ) )  require_once ABSPATH . 'wp-admin/includes/file.php';
    if ( ! function_exists( 'wp_handle_sideload' ) ) require_once ABSPATH . 'wp-admin/includes/file.php';
    if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = download_url( $url, 15 );
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

/** Substitui URLs no conteúdo (inclusive dentro de srcset). */
function replace_urls_in_content( string $html, array $map ): string {
    if ( ! $map ) return $html;
    return strtr( $html, $map );
}
