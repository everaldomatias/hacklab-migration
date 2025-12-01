<?php
/**
 * Utility functions for terms
 *
 * @package HacklabMigration
 */

namespace HacklabMigration;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Importa termos do banco remoto para o WordPress local.
 *
 * Copia dados de termos (name, slug, description, taxonomy, parent) e seus
 * metadados (termmeta), com suporte a filtros, callbacks de pré/pós-processamento
 * e modo de simulação (dry run).
 *
 * ## Parâmetros aceitos em $args:
 *
 * - blog_id (int|null)   : ID do blog remoto (em multisite), ou null para usar as tabelas base.
 * - taxonomies (string[]): Lista de taxonomias a importar. Se vazio, importa todas.
 * - include_ids (int[])  : Lista de IDs de termos remotos a incluir. Se vazio, não filtra por ID.
 * - exclude_ids (int[])  : Lista de IDs de termos remotos a excluir.
 * - chunk (int)          : Tamanho do lote (chunk) para processar termos por vez. Default: 500.
 * - dry_run (bool)       : Se true, não grava nada no banco local.
 * - fn_pre (callable)    : Callback chamado antes de criar/atualizar o termo local.
 *                          Assinatura sugerida:
 *                          fn ( array &$payload, array $options ): void
 *                          Onde $payload contém:
 *                          [
 *                              'remote_term_id' => int,
 *                              'taxonomy'       => string,
 *                              'blog_id'        => int|null,
 *                              'term'           => [
 *                                  'name'          => string,
 *                                  'slug'          => string,
 *                                  'description'   => string,
 *                                  'parent_remote' => int,
 *                              ],
 *                              'meta'           => array<array{meta_key:string,meta_value:string}>
 *                          ]
 *
 * - fn_pos (callable)    : Callback chamado depois de criar/atualizar o termo e gravar metas.
 *                          Assinatura sugerida:
 *                          fn ( array $payload, array $options ): void
 *                          Onde $payload contém, além do acima:
 *                          [
 *                              'local_term_id' => int,
 *                              'is_new'        => bool,
 *                              'parent_local'  => int,
 *                          ]
 *
 * @param array $args Argumentos de configuração da importação.
 *
 * @return array {
 *     @type int   $found_terms Quantidade de termos remotos encontrados.
 *     @type int   $imported    Quantidade de termos criados no local.
 *     @type int   $updated     Quantidade de termos atualizados no local.
 *     @type array $errors      Erros agrupados por ID de termo remoto.
 *     @type array $map         Mapa [remote_term_id => local_term_id].
 * }
 */
function import_remote_terms( array $args ) : array {
    $defaults = [
        'blog_id'     => null,
        'taxonomies'  => [],
        'include_ids' => [],
        'exclude_ids' => [],
        'chunk'       => 500,
        'dry_run'     => false,
        'fn_pre'      => null,
        'fn_pos'      => null,
    ];

    $o = wp_parse_args( $args, $defaults );

    $result = [
        'found_terms' => 0,
        'imported'    => 0,
        'updated'     => 0,
        'errors'      => [],
        'map'         => [],
    ];

    $ext = get_external_wpdb();
    if ( ! $ext instanceof \wpdb ) {
        $result['errors'][] = 'Falha ao conectar no banco remoto.';
        return $result;
    }

    $blog_id = $o['blog_id'] ? (int) $o['blog_id'] : 1;

    $creds  = get_credentials();
    $tables = resolve_remote_terms_tables( $creds, $blog_id );

    if (
        empty( $tables['terms'] ) ||
        empty( $tables['term_taxonomy'] ) ||
        empty( $tables['term_relationships'] ) ||
        empty( $tables['termmeta'] )
    ) {
        $result['errors'][] = 'Tabelas de termos remotos não foram resolvidas corretamente.';
        return $result;
    }

    $t_terms         = $tables['terms'];
    $t_term_taxonomy = $tables['term_taxonomy'];
    $t_termmeta      = $tables['termmeta'];

    $taxonomies = array_values(
        array_filter(
            array_map( 'sanitize_key', (array) $o['taxonomies'] ),
            static fn( $v ) => $v !== ''
        )
    );

    $include_ids = array_values( array_unique( array_map( 'intval', (array) $o['include_ids'] ) ) );
    $exclude_ids = array_values( array_unique( array_map( 'intval', (array) $o['exclude_ids'] ) ) );

    $ids_sql = "
        SELECT DISTINCT t.term_id
          FROM {$t_terms} t
          JOIN {$t_term_taxonomy} tt ON tt.term_id = t.term_id
    ";

    $clauses = [];
    $params  = [];

    if ( $taxonomies ) {
        $ph = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );
        $clauses[] = "tt.taxonomy IN ({$ph})";
        $params    = array_merge( $params, $taxonomies );
    }

    if ( $include_ids ) {
        $ph = implode( ',', array_fill( 0, count( $include_ids ), '%d' ) );
        $clauses[] = "t.term_id IN ({$ph})";
        $params    = array_merge( $params, $include_ids );
    }

    if ( $exclude_ids ) {
        $ph = implode( ',', array_fill( 0, count( $exclude_ids ), '%d' ) );
        $clauses[] = "t.term_id NOT IN ({$ph})";
        $params    = array_merge( $params, $exclude_ids );
    }

    if ( $clauses ) {
        $ids_sql .= ' WHERE ' . implode( ' AND ', $clauses );
    }

    $ids_sql .= ' ORDER BY tt.parent ASC, t.term_id ASC';

    $ids_stmt   = $params ? $ext->prepare( $ids_sql, $params ) : $ids_sql;
    $remote_ids = $ext->get_col( $ids_stmt );
    $remote_ids = array_values( array_unique( array_map( 'intval', (array) $remote_ids ) ) );

    $result['found_terms'] = count( $remote_ids );

    if ( ! $remote_ids ) {
        return $result;
    }

    $chunk   = max( 1, (int) $o['chunk'] );
    $dry_run = (bool) $o['dry_run'];

    $has_fn_pre = ! empty( $o['fn_pre'] ) && is_callable( $o['fn_pre'] );
    $has_fn_pos = ! empty( $o['fn_pos'] ) && is_callable( $o['fn_pos'] );

    for ( $i = 0; $i < count( $remote_ids ); $i += $chunk ) {
        $ids = array_slice( $remote_ids, $i, $chunk );
        $ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        $terms_sql = "
            SELECT t.term_id,
                   t.name,
                   t.slug,
                   t.term_group,
                   tt.taxonomy,
                   tt.description,
                   tt.parent
              FROM {$t_terms} t
              JOIN {$t_term_taxonomy} tt ON tt.term_id = t.term_id
             WHERE t.term_id IN ({$ph})
             ORDER BY tt.parent ASC, t.term_id ASC
        ";

        $terms_rows = $ext->get_results( $ext->prepare( $terms_sql, $ids ), ARRAY_A ) ?: [];
        if ( ! $terms_rows ) {
            continue;
        }

        $by_remote = [];
        foreach ( $terms_rows as $row ) {
            $rid = (int) $row['term_id'];
            $by_remote[ $rid ][] = $row;
        }

        $meta_by_remote = [];
        $ph_meta        = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        $meta_sql = "
            SELECT term_id, meta_key, meta_value
              FROM {$t_termmeta}
             WHERE term_id IN ({$ph_meta})
             ORDER BY meta_id ASC
        ";

        $meta_rows = $ext->get_results( $ext->prepare( $meta_sql, $ids ), ARRAY_A ) ?: [];
        foreach ( $meta_rows as $meta ) {
            $rid = (int) ( $meta['term_id'] ?? 0 );
            if ( $rid <= 0 ) {
                continue;
            }

            $meta_by_remote[ $rid ][] = [
                'meta_key'   => (string) ( $meta['meta_key']   ?? '' ),
                'meta_value' => (string) ( $meta['meta_value'] ?? '' ),
            ];
        }

        foreach ( $by_remote as $rid => $rows_for_term ) {
            foreach ( $rows_for_term as $row ) {
                $taxonomy = (string) $row['taxonomy'];
                $name     = (string) $row['name'];
                $slug     = (string) $row['slug'];
                $parent_r = (int) $row['parent'];
                $desc     = (string) $row['description'];

                if ( ! taxonomy_exists( $taxonomy ) ) {
                    $result['errors'][ $rid ][] = "Taxonomia '{$taxonomy}' não existe no ambiente local.";
                    continue;
                }

                $term_meta = $meta_by_remote[ $rid ] ?? [];

                $payload = [
                    'remote_term_id' => $rid,
                    'taxonomy'       => $taxonomy,
                    'blog_id'        => $blog_id,
                    'term'           => [
                        'name'          => $name,
                        'slug'          => $slug,
                        'description'   => $desc,
                        'parent_remote' => $parent_r,
                    ],
                    'meta'           => $term_meta,
                ];

                if ( $has_fn_pre ) {
                    try {
                        ( $o['fn_pre'] )( $payload, $o );
                    } catch ( \Throwable $e ) {
                        $result['errors'][ $rid ][] = 'fn_pre (term ' . $rid . '): ' . $e->getMessage();
                    }
                }

                $term_def   = $payload['term'] ?? [];
                $name       = (string) ( $term_def['name']          ?? $name );
                $slug       = (string) ( $term_def['slug']          ?? $slug );
                $desc       = (string) ( $term_def['description']   ?? $desc );
                $parent_r   = (int)    ( $term_def['parent_remote'] ?? $parent_r );
                $term_meta  = is_array( $payload['meta'] ?? null ) ? $payload['meta'] : $term_meta;

                if ( $dry_run ) {
                    $exists        = term_exists( $slug, $taxonomy );
                    $local_term_id = 0;

                    if ( is_array( $exists ) && ! empty( $exists['term_id'] ) ) {
                        $local_term_id = (int) $exists['term_id'];
                    } elseif ( is_numeric( $exists ) ) {
                        $local_term_id = (int) $exists;
                    }

                    if ( $local_term_id > 0 ) {
                        $result['map'][ $rid ] = $local_term_id;
                    }

                    continue;
                }

                $parent_local = 0;
                if ( $parent_r > 0 ) {
                    if ( isset( $result['map'][ $parent_r ] ) ) {
                        $parent_local = (int) $result['map'][ $parent_r ];
                    } else {
                        $parent_local = find_local_term(
                            $parent_r,
                            $blog_id,
                            $taxonomy
                        );
                    }
                }

                $exists        = term_exists( $slug, $taxonomy );
                $local_term_id = 0;

                if ( is_array( $exists ) && ! empty( $exists['term_id'] ) ) {
                    $local_term_id = (int) $exists['term_id'];
                } elseif ( is_numeric( $exists ) ) {
                    $local_term_id = (int) $exists;
                }

                $is_new = false;

                if ( $local_term_id <= 0 ) {
                    $insert_args = [
                        'slug'        => $slug,
                        'description' => $desc,
                    ];

                    if ( $parent_local > 0 ) {
                        $insert_args['parent'] = $parent_local;
                    }

                    $insert = wp_insert_term( $name, $taxonomy, $insert_args );

                    if ( is_wp_error( $insert ) ) {
                        $result['errors'][ $rid ][] = 'Erro ao criar termo local: ' . $insert->get_error_message();
                        continue;
                    }

                    $local_term_id = (int) $insert['term_id'];
                    $result['imported']++;
                    $is_new = true;
                } else {
                    $update_args = [
                        'name'        => $name,
                        'slug'        => $slug,
                        'description' => $desc,
                    ];

                    if ( $parent_local > 0 ) {
                        $update_args['parent'] = $parent_local;
                    }

                    $up = wp_update_term( $local_term_id, $taxonomy, $update_args );

                    if ( is_wp_error( $up ) ) {
                        $result['errors'][ $rid ][] = 'Erro ao atualizar termo local: ' . $up->get_error_message();
                        continue;
                    } else {
                        $result['updated']++;
                    }
                }

                // Mapa remoto -> local
                $result['map'][ $rid ] = $local_term_id;

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

                        update_term_meta( $local_term_id, $k, maybe_unserialize( $v ) );
                    }
                }

                update_term_meta( $local_term_id, '_hacklab_migration_source_id', $rid );

                if ( $blog_id ) {
                    update_term_meta( $local_term_id, '_hacklab_migration_source_blog', $blog_id );
                }

                // fn_pos: pós-processamento (ex.: log, relações extras, etc.)
                if ( $has_fn_pos ) {
                    $payload_pos = [
                        'remote_term_id' => $rid,
                        'local_term_id'  => $local_term_id,
                        'taxonomy'       => $taxonomy,
                        'blog_id'        => $blog_id,
                        'term'           => [
                            'name'          => $name,
                            'slug'          => $slug,
                            'description'   => $desc,
                            'parent_remote' => $parent_r,
                            'parent_local'  => $parent_local,
                        ],
                        'meta'           => $term_meta,
                        'is_new'         => $is_new,
                    ];

                    try {
                        ( $o['fn_pos'] )( $payload_pos, $o );
                    } catch ( \Throwable $e ) {
                        $result['errors'][ $rid ][] = 'fn_pos (term ' . $rid . '): ' . $e->getMessage();
                    }
                }
            }
        }
    }

    return $result;
}



/**
 * Get a term ID by meta data value.
 *
 * @param string      $meta_key   Meta key to search for.
 * @param string|int  $meta_value Meta value to match.
 * @param string|null $taxonomy   Optional taxonomy. If null/empty, search in all taxonomies.
 *
 * @return int Term ID or 0 if not found.
 */
function get_term_by_meta_data( string $meta_key, $meta_value, ?string $taxonomy = null ): int {
    static $cache = [];

    $meta_value = trim( (string) $meta_value );

    if ( $meta_value === '' ) {
        return 0;
    }

    $taxonomy   = $taxonomy ? (string) $taxonomy : '';
    $cache_key  = $meta_key . '|' . $meta_value . '|' . $taxonomy;

    if ( array_key_exists( $cache_key, $cache ) ) {
        return (int) $cache[ $cache_key ];
    }

    $args = [
        'meta_key'   => $meta_key,
        'meta_value' => $meta_value,
        'number'     => 1,
        'fields'     => 'ids',
        'hide_empty' => false,
    ];

    if ( $taxonomy !== '' ) {
        $args['taxonomy'] = $taxonomy;
    }

    $term_query = new \WP_Term_Query( $args );
    $terms      = $term_query->get_terms();

    if ( empty( $terms ) || is_wp_error( $terms ) ) {
        $cache[ $cache_key ] = 0;
        return 0;
    }

    $term_id = (int) $terms[0];

    $cache[ $cache_key ] = $term_id;

    return $term_id;
}

function find_local_term( int $remote_term_id, int $blog_id = 1, ?string $taxonomy = null ): int {
    static $cache = [];

    if ( $remote_term_id <= 0 ) {
        return 0;
    }

    $taxonomy = $taxonomy ? (string) $taxonomy : '';
    $cache_key = $remote_term_id . '|' . $blog_id . '|' . $taxonomy;

    if ( array_key_exists( $cache_key, $cache ) ) {
        return (int) $cache[ $cache_key ];
    }

    $args = [
        'number'     => 1,
        'fields'     => 'ids',
        'hide_empty' => false,
        'meta_query' => [
            [
                'key'     => '_hacklab_migration_source_id',
                'value'   => $remote_term_id,
                'compare' => '='
            ],
            [
                'key'     => '_hacklab_migration_source_blog',
                'value'   => $blog_id,
                'compare' => '='
            ]
        ]
    ];

    if ( $taxonomy !== '' ) {
        $args['taxonomy'] = $taxonomy;
    }

    $query_term = new \WP_Term_Query( $args );
    $terms = $query_term->get_terms();

    if ( empty( $terms ) || is_wp_error( $terms ) ) {
        $cache[ $cache_key ] = 0;
        return 0;
    }

    $term_id = (int) $terms[0];
    $cache[ $cache_key ] = $term_id;

    return $term_id;
}
