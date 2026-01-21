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

function cap_instance() {
    global $coauthors_plus;
    return ( is_object( $coauthors_plus ) && method_exists( $coauthors_plus, 'add_coauthors' ) ) ? $coauthors_plus : null;
}

function cap_create_guest_author( string $slug, string $display_name, ?string $email = null ): string {
    $slug = sanitize_title( $slug );
    if ( $slug === '' ) return '';

    $user_login = sanitize_user( $slug, true ) ?: sanitize_key( $slug );

    $ga_id = wp_insert_post( [
        'post_type'   => 'guest-author',
        'post_status' => 'publish',
        'post_title'  => ( $display_name !== '' ? $display_name : $slug ),
        'post_name'   => $slug,
    ], true);

    if ( is_wp_error($ga_id) || $ga_id <= 0 ) {
        return '';
    }

    update_post_meta( $ga_id, 'cap-display_name', ( $display_name !== '' ? $display_name : $slug ) );
    update_post_meta( $ga_id, 'cap-user_login',   $user_login );

    if ( $email ) {
        update_post_meta( $ga_id, 'cap-user_email', sanitize_email( $email ) );
    }

    return $user_login;
}

function cap_assign_coauthors_to_post( int $post_id, array $row_terms ): void {
    $cap = cap_instance();
    if ( ! $cap || empty( $row_terms['author'] ) ) {
        return;
    }

    $ga_enabled = method_exists( $cap, 'is_guest_authors_enabled' )
        ? (bool) $cap->is_guest_authors_enabled()
        : true;

    $idents = [];

    foreach ( (array) $row_terms['author'] as $a ) {
        $slug  = (string) ( $a['slug']  ?? '' );
        $login = (string) ( $a['login'] ?? '' );
        $email = (string) ( $a['email'] ?? '' );
        $name  = (string) ( $a['name']  ?? $slug );

        if ( $slug === '' ) continue;

        $co = $cap->get_coauthor_by( 'user_nicename', $slug );

        if ( ! $co && $login !== '' ) {
            $co = $cap->get_coauthor_by( 'login', $login );
        }

        if ( ! $co && $email !== '' ) {
            $co = $cap->get_coauthor_by( 'user_email', $email );
        }

        if ( ! $co && $ga_enabled ) {
            $created_login = cap_create_guest_author( $slug, $name, ( $email !== '' ? $email : null ) );
            if ( $created_login !== '' ) {
                $co = $cap->get_coauthor_by( 'user_nicename', $slug );
                if ( ! $co ) {
                    $idents[] = $created_login;
                    continue;
                }
            }
        }

        if ( $co && ! empty( $co->user_login ) ) {
            $idents[] = $co->user_login;
        }
    }

    $idents = array_values( array_unique( array_filter( $idents ) ) );
    if ( ! $idents ) return;

    $cap->add_coauthors( $post_id, $idents, false );
}

/**
 * Callback para run-import: converte um post importado para guest-author do CoAuthors Plus.
 *
 * Uso sugerido:
 *   wp run-import --post_type=guest-author --fn_pos=\\HacklabMigration\\cap_convert_post_to_guest_author ...
 */
function cap_convert_post_to_guest_author( int $local_id, array $row, bool $is_update, bool $dry_run ): void {
    if ( $dry_run ) {
        return;
    }

    $post = get_post( $local_id );
    if ( ! $post instanceof \WP_Post ) {
        return;
    }

    $source_meta = get_post_meta( $local_id, '_hacklab_migration_source_meta', true );
    if ( ! is_array( $source_meta ) ) {
        $source_meta = is_array( $row['post_meta'] ?? null ) ? $row['post_meta'] : [];
    }

    $source_id = (int) ( $row['ID'] ?? 0 );
    $source_blog = (int) ( $row['blog_id'] ?? 1 );

    if ( $source_id ) {
        update_post_meta( $local_id, '_hacklab_migration_source_id', $source_id );
    }

    update_post_meta( $local_id, '_hacklab_migration_source_blog', $source_blog );

    // Preenche metas esperadas pelo CoAuthors Plus para guest-author.
    if ( function_exists( __NAMESPACE__ . '\\ensure_guest_author_post' ) ) {
        ensure_guest_author_post( $post, $source_meta );
    }

    $slug = sanitize_title( $post->post_name !== '' ? $post->post_name : ( $post->post_title !== '' ? $post->post_title : $post->ID ) );

    wp_update_post( [
        'ID'          => $local_id,
        'post_type'   => 'guest-author',
        'post_status' => 'publish',
        'post_name'   => $slug,
    ] );
}

/**
 * Importa coautores (guest-authors do CoAuthors Plus) do banco remoto.
 *
 * @param array $args {
 *     @type int      $blog_id           ID do blog remoto. Default: 1.
 *     @type int[]    $include_ids       IDs remotos a incluir.
 *     @type int[]    $exclude_ids       IDs remotos a excluir.
 *     @type int|null $limit             Limite de registros a buscar (null/0 para todos).
 *     @type int      $offset            Offset usado junto com limit.
 *     @type bool     $dry_run           Simula sem gravar.
 *     @type bool     $force_base_prefix Usa as tabelas base em instalações single.
 *     @type int      $run_id            ID do run de importação (gerado automaticamente se omitido).
 * }
 *
 * @return array Resumo retornado por import_remote_posts().
 */
function import_remote_coauthors( array $args = [] ): array {
    $defaults = [
        'blog_id'           => 1,
        'include_ids'       => [],
        'exclude_ids'       => [],
        'limit'             => null,
        'offset'            => 0,
        'dry_run'           => false,
        'force_base_prefix' => false,
        'run_id'            => 0,
    ];

    $o = wp_parse_args( $args, $defaults );

    $blog_id = max( 1, (int) $o['blog_id'] );
    $limit   = isset( $o['limit'] ) ? max( 0, (int) $o['limit'] ) : null;
    $offset  = max( 0, (int) $o['offset'] );

    $include_ids = array_values( array_filter( array_map( 'intval', (array) $o['include_ids'] ), static fn( $v ) => $v > 0 ) );
    $exclude_ids = array_values( array_filter( array_map( 'intval', (array) $o['exclude_ids'] ), static fn( $v ) => $v > 0 ) );

    $run_id = (int) $o['run_id'];
    $dry_run = (bool) $o['dry_run'];

    if ( $run_id <= 0 && ! $dry_run ) {
        $run_id = next_import_run_id();
    }

    $fetch = [
        'blog_id'           => $blog_id,
        'post_type'         => 'guest-author',
        'post_status'       => ['publish', 'pending', 'draft', 'private'],
        'orderby'           => 'ID',
        'order'             => 'ASC',
        'include'           => $include_ids,
        'exclude'           => $exclude_ids,
        'limit'             => $limit,
        'numberposts'       => $limit ?? 0,
        'offset'            => $offset,
        'force_base_prefix' => (bool) $o['force_base_prefix'],
    ];

    $summary = import_remote_posts( [
        'fetch'            => $fetch,
        'media'            => false,
        'assign_terms'     => false,
        'map_users'        => false,
        'dry_run'          => $dry_run,
        'target_post_type' => 'guest-author',
        'write_mode'       => 'upsert',
        'fn_pos'           => __NAMESPACE__ . '\\cap_convert_post_to_guest_author',
        'force_base_prefix'=> (bool) $o['force_base_prefix'],
        'run_id'           => $run_id,
    ] );

    $summary['run_id'] = $run_id;

    return $summary;
}
