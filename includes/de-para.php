<?php
/**
 * Helpers for DE/PARA rules (CSV driven).
 *
 * @package HacklabMigration
 */

namespace HacklabMigration;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Lê e junta regras DE/PARA a partir de dois CSVs (de.csv + para.csv) via Control.
 *
 * Os arquivos esperados seguem o formato:
 * - de.csv:   Control, CPTs, Taxonomy, Termo (term_name)
 * - para.csv: Control, CPTs, Taxonomy, Termo (term_name), Taxonomy, Termo (term_name), ...
 *
 * @param string $de_csv_path   Caminho do arquivo de.csv.
 * @param string $para_csv_path Caminho do arquivo para.csv.
 *
 * @return array<int,array{
 *   control:int,
 *   de:array{cpt:string,taxonomy:string,term:string},
 *   para:array{cpt:string,assignments:array<int,array{taxonomy:string,term:string}>}
 * }>
 */
function load_de_para_rules_from_csv( string $de_csv_path, string $para_csv_path ): array {
    static $cache = [];

    $key = $de_csv_path . '|' . $para_csv_path;
    $de_mtime = @filemtime( $de_csv_path ) ?: 0;
    $pa_mtime = @filemtime( $para_csv_path ) ?: 0;
    $cache_key = $key . '|' . $de_mtime . '|' . $pa_mtime;

    if ( isset( $cache[ $cache_key ] ) ) {
        return $cache[ $cache_key ];
    }

    $de = read_csv_rows( $de_csv_path );
    $pa = read_csv_rows( $para_csv_path );

    if ( ! $de || ! $pa ) {
        $cache[ $cache_key ] = [];
        return [];
    }

    $de_header = array_shift( $de );
    $pa_header = array_shift( $pa );

    if ( ! is_array( $de_header ) || ! is_array( $pa_header ) ) {
        $cache[ $cache_key ] = [];
        return [];
    }

    $de_map = [];
    foreach ( $de as $row ) {
        $row = normalize_row( $row, count( $de_header ) );
        $control = (int) ( $row[0] ?? 0 );
        if ( $control <= 0 ) {
            continue;
        }
        $de_map[ $control ] = [
            'control' => $control,
            'de'      => [
                'cpt'      => sanitize_key( (string) ( $row[1] ?? '' ) ),
                'taxonomy' => sanitize_key( (string) ( $row[2] ?? '' ) ),
                'term'     => sanitize_title( (string) ( $row[3] ?? '' ) ),
            ],
        ];
    }

    $rules = [];

    foreach ( $pa as $row ) {
        $row = normalize_row( $row, count( $pa_header ) );
        $control = (int) ( $row[0] ?? 0 );
        if ( $control <= 0 ) {
            continue;
        }

        if ( empty( $de_map[ $control ] ) ) {
            continue;
        }

        $assignments = [];
        // pares Taxonomy/Termo começam na coluna 2
        for ( $i = 2; $i < count( $row ); $i += 2 ) {
            $tax = sanitize_key( (string) ( $row[ $i ] ?? '' ) );
            $term = trim( (string) ( $row[ $i + 1 ] ?? '' ) );

            // Regra: termo vazio => não atribui nada, mas segue as outras orientações
            if ( $tax === '' || $term === '' ) {
                continue;
            }

            $assignments[] = [
                'taxonomy' => $tax,
                'term'     => $term,
            ];
        }

        $rules[] = [
            'control' => $control,
            'de'      => $de_map[ $control ]['de'],
            'para'    => [
                'cpt'         => sanitize_key( (string) ( $row[1] ?? '' ) ),
                'assignments' => $assignments,
            ],
        ];
    }

    usort( $rules, static fn( $a, $b ) => (int) $a['control'] <=> (int) $b['control'] );

    $cache[ $cache_key ] = $rules;
    return $rules;
}

/**
 * Aplica regras DE/PARA (CSV) a um post.
 *
 * Use com hacklab-dev-utils:
 *   wp modify-posts q:post_type=migration fn:\\HacklabMigration\\apply_de_para_from_csv
 *
 * Os caminhos dos CSV podem ser definidos por constantes em wp-config.php:
 * - HACKLAB_MIGRATION_DE_CSV_PATH
 * - HACKLAB_MIGRATION_PARA_CSV_PATH
 *
 * @param \WP_Post $post
 */
function apply_de_para_from_csv( \WP_Post $post ): void {
    $de_path = defined( 'HACKLAB_MIGRATION_DE_CSV_PATH' ) ? (string) HACKLAB_MIGRATION_DE_CSV_PATH : '/files/wp-content/uploads/de.csv';
    $pa_path = defined( 'HACKLAB_MIGRATION_PARA_CSV_PATH' ) ? (string) HACKLAB_MIGRATION_PARA_CSV_PATH : '/files/wp-content/uploads/para.csv';

    if ( $de_path === '' || $pa_path === '' ) {
        return;
    }

    $rules = load_de_para_rules_from_csv( $de_path, $pa_path );
    if ( ! $rules ) {
        return;
    }

    $source_meta = get_post_meta( $post->ID, '_hacklab_migration_source_meta', true );
    $remote_cpt  = '';
    if ( is_array( $source_meta ) ) {
        $remote_cpt = sanitize_key( (string) ( $source_meta['post_type'] ?? '' ) );
    }
    if ( $remote_cpt === '' ) {
        $remote_cpt = sanitize_key( (string) get_post_meta( $post->ID, '_hacklab_migration_source_post_type', true ) );
    }
    if ( $remote_cpt === '' ) {
        $remote_cpt = sanitize_key( (string) $post->post_type );
    }

    $remote_terms = get_post_meta( $post->ID, '_hacklab_migration_remote_terms', true );
    $remote_terms = is_array( $remote_terms ) ? $remote_terms : [];
    $has_remote_terms = ! empty( $remote_terms );

    foreach ( $rules as $rule ) {
        $de = $rule['de'] ?? [];
        $pa = $rule['para'] ?? [];

        $de_cpt = sanitize_key( (string) ( $de['cpt'] ?? '' ) );
        if ( $de_cpt !== '' && $remote_cpt !== $de_cpt ) {
            continue;
        }

        $de_tax  = sanitize_key( (string) ( $de['taxonomy'] ?? '' ) );
        $de_term = sanitize_title( (string) ( $de['term'] ?? '' ) );

        // Se DE não define tax/termo, aplica em todos do CPT.
        if ( $de_tax !== '' && $de_term !== '' ) {
            $has_term = false;

            if ( $has_remote_terms ) {
                $terms_in_tax = is_array( $remote_terms[ $de_tax ] ?? null ) ? $remote_terms[ $de_tax ] : [];
                foreach ( $terms_in_tax as $t ) {
                    if ( sanitize_title( (string) ( $t['slug'] ?? '' ) ) === $de_term ) {
                        $has_term = true;
                        break;
                    }
                }
            } else {
                // Fallback: se não há snapshot de termos remotos, testa a atribuição local.
                if ( taxonomy_exists( $de_tax ) && has_term( $de_term, $de_tax, $post ) ) {
                    $has_term = true;
                }
            }

            if ( ! $has_term ) {
                continue;
            }
        }

        $target_cpt = sanitize_key( (string) ( $pa['cpt'] ?? '' ) );
        if ( $target_cpt !== '' ) {
            $post->post_type = $target_cpt;
        }

        $assignments = is_array( $pa['assignments'] ?? null ) ? $pa['assignments'] : [];
        foreach ( $assignments as $a ) {
            $tax  = sanitize_key( (string) ( $a['taxonomy'] ?? '' ) );
            $term = trim( (string) ( $a['term'] ?? '' ) );

            if ( $tax === '' || $term === '' ) {
                continue;
            }

            add_term_to_post( $post->ID, $tax, $term );
        }

        // Se o destino for guest-author (Co-Authors Plus), garante metadados mínimos.
        if ( $target_cpt === 'guest-author' ) {
            ensure_guest_author_post( $post, $source_meta );
        }
    }
}

/**
 * Leitor de CSV simples.
 *
 * @param string $path
 * @return array<int,array<int,string>>
 */
function read_csv_rows( string $path ): array {
    if ( $path === '' || ! is_readable( $path ) ) {
        return [];
    }

    $rows = [];
    $fh = fopen( $path, 'r' );
    if ( ! $fh ) {
        return [];
    }

    while ( ( $row = fgetcsv( $fh ) ) !== false ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        $rows[] = array_map( static fn( $v ) => trim( (string) $v ), $row );
    }

    fclose( $fh );
    return $rows;
}

/**
 * Normaliza um row do CSV para o tamanho esperado.
 *
 * @param array<int,mixed> $row
 * @param int $len
 * @return array<int,string>
 */
function normalize_row( array $row, int $len ): array {
    $row = array_map( static fn( $v ) => trim( (string) $v ), $row );
    if ( count( $row ) < $len ) {
        $row = array_merge( $row, array_fill( 0, $len - count( $row ), '' ) );
    }
    return $row;
}

/**
 * Adiciona termo ao post mantendo existentes (append).
 *
 * @param int $post_id
 * @param string $taxonomy
 * @param string $term_value Termo como slug ou nome (ex.: "comunicacao" ou "Comunicação").
 */
function add_term_to_post( int $post_id, string $taxonomy, string $term_value ): void {
    $term_value = trim( $term_value );

    if ( $taxonomy === '' || $term_value === '' ) {
        return;
    }

    if ( ! taxonomy_exists( $taxonomy ) ) {
        return;
    }

    $slug = sanitize_title( $term_value );

    $term = $slug !== '' ? get_term_by( 'slug', $slug, $taxonomy ) : false;
    if ( ! $term ) {
        $term = get_term_by( 'name', $term_value, $taxonomy );
    }

    if ( ! $term ) {
        $ins = wp_insert_term(
            $term_value,
            $taxonomy,
            $slug !== '' ? [ 'slug' => $slug ] : []
        );
        if ( is_wp_error( $ins ) || empty( $ins['term_id'] ) ) {
            return;
        }
        $term_id = (int) $ins['term_id'];
    } else {
        $term_id = (int) ( $term->term_id ?? 0 );
    }

    if ( $term_id <= 0 ) {
        return;
    }

    wp_set_object_terms( $post_id, [ $term_id ], $taxonomy, true );
}

/**
 * Prepara um post para ser usado como guest-author (Co-Authors Plus).
 *
 * @param \WP_Post $post
 * @param array    $source_meta Metadados de origem (para tentar extrair e-mail).
 */
function ensure_guest_author_post( \WP_Post $post, array $source_meta = [] ): void {
    $title = (string) $post->post_title;
    $slug  = (string) $post->post_name;
    if ( $slug === '' ) {
        $slug = sanitize_title( $title !== '' ? $title : $post->ID );
    }

    $user_login = sanitize_user( $slug, true ) ?: sanitize_key( $slug );
    $display    = $title !== '' ? $title : $slug;

    $email_keys = ['user_email', 'email'];
    $email = '';
    foreach ( $email_keys as $k ) {
        if ( ! empty( $source_meta[ $k ] ) ) {
            $candidate = is_array( $source_meta[ $k ] ) ? reset( $source_meta[ $k ] ) : $source_meta[ $k ];
            $candidate = sanitize_email( (string) $candidate );
            if ( is_email( $candidate ) ) {
                $email = $candidate;
                break;
            }
        }
    }

    update_post_meta( $post->ID, 'cap-display_name', $display );
    update_post_meta( $post->ID, 'cap-user_login', $user_login );
    if ( $email !== '' ) {
        update_post_meta( $post->ID, 'cap-user_email', $email );
    }

    if ( $post->post_status !== 'publish' ) {
        $post->post_status = 'publish';
    }
}
