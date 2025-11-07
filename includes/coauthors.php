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
