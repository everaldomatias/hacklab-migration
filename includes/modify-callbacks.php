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

/**
 * Atribui coautor local (WP user ou guest-author) com base no autor remoto salvo nas metas.
 *
 * Regras:
 * - Tenta resolver o autor remoto para usuário local (find_local_user ou import_remote_user).
 * - Se não existir usuário e CoAuthors Plus estiver ativo, procura guest-author com metas de origem;
 *   se não achar, cria guest-author e atribui.
 *
 * Pode ser usado com hacklab-dev-utils:
 *   wp modify-posts --fn:\\HacklabMigration\\map_remote_author_to_coauthor ...
 *
 * @param \WP_Post $post
 */
function map_remote_author_to_coauthor( \WP_Post $post ): void {
    // Se CoAuthors não está ativo, nada a fazer.
    if ( ! cap_instance() ) {
        return;
    }

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

    $authors = [];

    // 1) Tenta usuário local.
    $local_user = find_local_user( $remote_author, $blog_id );

    if ( $local_user <= 0 ) {
        $local_user = import_remote_user( $remote_author, $blog_id, false );
    }

    if ( $local_user > 0 ) {
        $user = get_user_by( 'id', $local_user );
        if ( $user instanceof \WP_User ) {
            $authors[] = [
                'slug'  => (string) $user->user_nicename,
                'login' => (string) $user->user_login,
                'email' => (string) $user->user_email,
                'name'  => (string) $user->display_name,
            ];
        }
    } else {
        // 2) Busca guest-author existente pelas metas de origem.
        $existing_ga = get_posts( [
            'post_type'      => 'guest-author',
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'meta_query'     => [
                [ 'key' => '_hacklab_migration_source_id',   'value' => $remote_author, 'compare' => '=' ],
                [ 'key' => '_hacklab_migration_source_blog', 'value' => $blog_id,       'compare' => '=' ],
            ],
            'fields'                => 'ids',
            'no_found_rows'         => true,
            'update_post_meta_cache'=> false,
            'update_post_term_cache'=> false,
        ] );

        $ga_id = $existing_ga ? (int) $existing_ga[0] : 0;

        if ( $ga_id <= 0 ) {
            // Cria guest-author mínimo.
            $slug    = 'remote-author-' . $blog_id . '-' . $remote_author;
            $display = 'Autor ' . $remote_author;

            $login = cap_create_guest_author( $slug, $display );
            if ( $login !== '' ) {
                $ga_post = get_page_by_path( $slug, OBJECT, 'guest-author' );
                $ga_id   = $ga_post instanceof \WP_Post ? (int) $ga_post->ID : 0;
                if ( $ga_id > 0 ) {
                    update_post_meta( $ga_id, '_hacklab_migration_source_id', $remote_author );
                    update_post_meta( $ga_id, '_hacklab_migration_source_blog', $blog_id );
                }
            }
        }

        if ( $ga_id > 0 ) {
            $ga_post = get_post( $ga_id );
            $authors[] = [
                'slug'  => $ga_post instanceof \WP_Post ? (string) $ga_post->post_name : '',
                'login' => $ga_post instanceof \WP_Post ? (string) $ga_post->post_name : '',
                'email' => '',
                'name'  => $ga_post instanceof \WP_Post ? (string) $ga_post->post_title : '',
            ];
        }
    }

    if ( ! $authors ) {
        return;
    }

    cap_assign_coauthors_to_post( $post->ID, [ 'author' => $authors ] );
}

/**
 * Sincroniza coautores usando dados do meta de origem e CoAuthors Plus.
 *
 * Uso (com hacklab-dev-utils):
 *   wp modify-posts --q:post_type=migration --fn:\\HacklabMigration\\sync_coauthors_plus
 *
 * @param \WP_Post $post
 */
function sync_coauthors_plus( \WP_Post $post ): void {
    if ( ! cap_instance() ) {
        return;
    }

    $source_meta = get_post_meta( $post->ID, '_hacklab_migration_source_meta', true );
    $authors_raw = is_array( $source_meta ) ? ( $source_meta['authors'] ?? [] ) : [];

    if ( ! $authors_raw ) {
        return;
    }

    $authors_raw = is_array( $authors_raw ) ? $authors_raw : [ $authors_raw ];
    $authors = [];

    foreach ( $authors_raw as $a ) {
        if ( is_string( $a ) ) {
            // Permite lista simples (ex.: nomes ou slugs separados por vírgula)
            $parts = array_filter( array_map( 'trim', explode( ',', $a ) ) );
            foreach ( $parts as $part ) {
                $slug = sanitize_title( $part );
                if ( $slug === '' ) {
                    continue;
                }
                $authors[] = [
                    'slug' => $slug,
                    'name' => $part,
                ];
            }
            continue;
        }

        if ( is_array( $a ) ) {
            $slug  = sanitize_title( (string) ( $a['slug'] ?? $a['user_nicename'] ?? $a['login'] ?? '' ) );
            if ( $slug === '' && ! empty( $a['name'] ) ) {
                $slug = sanitize_title( (string) $a['name'] );
            }

            if ( $slug === '' ) {
                continue;
            }

            $authors[] = [
                'slug'  => $slug,
                'login' => (string) ( $a['login'] ?? $a['user_login'] ?? '' ),
                'email' => sanitize_email( (string) ( $a['email'] ?? $a['user_email'] ?? '' ) ),
                'name'  => (string) ( $a['name'] ?? $a['display_name'] ?? $slug ),
            ];
        }
    }

    if ( ! $authors ) {
        return;
    }

    cap_assign_coauthors_to_post( $post->ID, [ 'author' => $authors ] );
}
