<?php
/**
 * Utility functions for users
 *
 * @package HacklabMigration
 */

namespace HacklabMigration;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function import_remote_users( array $args ) : array {
    global $wpdb;

    $defaults = [
        'blog_id'     => 1,
        'include_ids' => [],
        'exclude_ids' => [],
        'chunk'       => 500,
        'dry_run'     => false
    ];

    $o = wp_parse_args( $args, $defaults );

    $result = [
        'found_users'  => 0,
        'imported'     => 0,
        'updated'      => 0,
        'remote_users' => [],
        'errors'       => [],
        'map'          => []
    ];

    $ext = get_external_wpdb();

    if ( ! $ext instanceof \wpdb ) {
        $result['errors'][] = "Falha ao conectar no banco remoto.";
        return $result;
    }

    $creds = get_credentials();
    $remote_prefix  = ! empty( $creds['prefix'] ) ? (string) $creds['prefix'] : 'wp_';

    $t_users    = resolve_remote_users_table( $creds );
    $t_usermeta = resolve_remote_usermeta_table( $creds );

    $include = array_values( array_unique( array_map( 'intval', (array) $o['include_ids'] ) ) );
    $exclude = array_values( array_unique( array_map( 'intval', (array) $o['exclude_ids'] ) ) );

    $off_email_filters = static function () {
        add_filter( 'send_password_change_email', '__return_false', 999 );
        add_filter( 'send_email_change_email', '__return_false', 999 );
        add_filter( 'wp_send_new_user_notification_to_admin', '__return_false', 999 );
        add_filter( 'wp_send_new_user_notification_to_user', '__return_false', 999 );
    };

    $restore_email_filters = static function () {
        remove_filter( 'send_password_change_email', '__return_false', 999 );
        remove_filter( 'send_email_change_email', '__return_false', 999 );
        remove_filter( 'wp_send_new_user_notification_to_admin', '__return_false', 999 );
        remove_filter( 'wp_send_new_user_notification_to_user', '__return_false', 999 );
    };

    $ids_sql = "SELECT DISTINCT u.ID
                FROM {$t_users} u";
    $clauses = [];
    $params  = [];

    if ( $o['blog_id'] && (int) $o['blog_id'] > 1 ) {
        $rbid = (int) $o['blog_id'];

        $base_prefix = ! empty( $creds['prefix'] ) ? (string) $creds['prefix'] : 'wp_';
        $cap_meta_key = sprintf( '%s%d_capabilities', $base_prefix, $rbid );

        $ids_sql .= " INNER JOIN {$t_usermeta} um
                        ON um.user_id = u.ID
                    AND um.meta_key = %s";
        $params[] = $cap_meta_key;
    }

    if ( $include ) {
        $place = implode( ',', array_fill( 0, count( $include ), '%d' ) );
        $clauses[] = "u.ID IN ($place)";
        $params = array_merge( $params, $include );
    }

    if ( $exclude ) {
        $place = implode( ',', array_fill( 0, count( $exclude ), '%d' ) );
        $clauses[] = "u.ID NOT IN ($place)";
        $params = array_merge( $params, $exclude );
    }

    if ( $clauses ) {
        $ids_sql .= " WHERE " . implode( ' AND ', $clauses );
    }

    $ids_sql .= " ORDER BY u.ID ASC";

    $ids_stmt   = $params ? $ext->prepare( $ids_sql, $params ) : $ids_sql;
    if ( $params && $ids_stmt === null ) {
        $result['errors'][] = 'Falha ao preparar consulta de usuários remotos.';
        return $result;
    }

    $remote_ids = $ext->get_col( $ids_stmt );
    $remote_ids = array_values( array_map( 'intval', (array) $remote_ids ) );

    $result['found_users'] = count( $remote_ids );

    if ( ! $remote_ids ) {
        return $result;
    }

    $chunk        = max( 1, (int) $o['chunk'] );
    $local_prefix = $wpdb->get_blog_prefix( 0 );
    $blog_id      = max( 1, (int) $o['blog_id'] );

    if ( ! $o['dry_run'] ) {
        $off_email_filters();
    }

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

    try {
        for ( $i = 0; $i < count( $remote_ids ); $i += $chunk ) {
            $ids = array_slice( $remote_ids, $i, $chunk );
            $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

            $users_sql = "
                SELECT u.ID, u.user_login, u.user_pass, u.user_nicename, u.user_email, u.user_url,
                       u.user_registered, u.user_activation_key, u.user_status, u.display_name
                FROM {$t_users} u
                WHERE u.ID IN ($ph)
                ORDER BY u.ID ASC
            ";

            $users_stmt = $ext->prepare( $users_sql, $ids );
            if ( $users_stmt === null ) {
                $result['errors'][] = 'Falha ao preparar consulta de usuários remotos.';
                continue;
            }

            $rows = $ext->get_results( $users_stmt, ARRAY_A ) ?: [];

            if ( ! $rows ) {
                continue;
            }

            $meta_sql = "
                SELECT um.user_id, um.meta_key, um.meta_value
                FROM {$t_usermeta} um
                WHERE um.user_id IN ($ph)
                ORDER BY um.umeta_id ASC
            ";

            $meta_stmt = $ext->prepare( $meta_sql, $ids );
            if ( $meta_stmt === null ) {
                $result['errors'][] = 'Falha ao preparar consulta de metadados de usuários remotos.';
                continue;
            }

            $meta_rows = $ext->get_results( $meta_stmt, ARRAY_A ) ?: [];
            $meta_by_user = [];

            foreach( $meta_rows as $mr ) {
                $uid = (int) $mr['user_id'];
                $meta_by_user[$uid][] = [
                    'meta_key'   => (string) $mr['meta_key'],
                    'meta_value' => $safe_unserialize( $mr['meta_value'] )
                ];
            }

            foreach( $rows as $u ) {
                $rid = (int) $u['ID'];
                $login = (string) $u['user_login'];
                $email = (string) $u['user_email'];
                $exists_by_login = get_user_by( 'login', $login );

                if ( $login === 'hacklab' ) {
                    continue;
                }

                $target_user_id = 0;
                $is_new = false;

                if ( $exists_by_login instanceof \WP_User ) {
                    $target_user_id = (int) $exists_by_login->ID;
                }

                $result['remote_users'][] = [
                    'ID'    => $rid,
                    'login' => $login,
                    'email' => $email
                ];

                if ( ! $o['dry_run'] ) {
                    // Usuário não existe no WP local, cria
                    if ( $target_user_id <= 0 ) {
                        $exists_by_email = $email !== '' ? get_user_by( 'email', $email ) : false;
                        if ( $exists_by_email instanceof \WP_User ) {
                            $target_user_id = (int) $exists_by_email->ID;
                        }

                        $candidate_login = $login;
                        $suffix = 1;
                        while ( username_exists( $candidate_login ) ) {
                            $candidate_login = $login . '_' . $suffix;
                            $suffix++;
                        }

                        $userdata = [
                            'user_login'    => $candidate_login,
                            'user_email'    => $email,
                            'user_nicename' => (string) $u['user_nicename'],
                            'user_url'      => (string) $u['user_url'],
                            'display_name'  => (string) $u['display_name'],
                            'user_pass'     => wp_generate_password( 20, true, true ),
                            'role'          => ''
                        ];

                        if ( ! empty( $u['user_registered'] ) ) {
                            $userdata['user_registered'] = $u['user_registered'];
                        }

                        $new_id = wp_insert_user( $userdata );

                        if ( is_wp_error( $new_id ) ) {
                            $result['errors'][$rid] = 'Erro ao criar usuário local: ' . $new_id->get_error_message();
                            continue;
                        }

                        $target_user_id = (int) $new_id;
                        $is_new = true;

                        $wpdb->update(
                            $wpdb->users,
                            [
                                'user_pass'           => (string) $u['user_pass'],
                                'user_activation_key' => (string) $u['user_activation_key'],
                                'user_status'         => (int) $u['user_status']
                            ],
                            ['ID' => $target_user_id],
                            ['%s', '%s', '%d'],
                            ['%d']
                        );

                        clean_user_cache( $target_user_id );
                    } else { // Atualiza usuário encontrado no local
                        $update_data = [
                            'ID'            => $target_user_id,
                            'user_nicename' => (string) $u['user_nicename'],
                            'user_url'      => (string) $u['user_url'],
                            'display_name'  => (string) $u['display_name']
                        ];

                        $up = wp_update_user( $update_data );

                        if ( is_wp_error( $up ) ) {
                            $result['errors'][$rid] = 'Erro ao atualizar usuário local: ' . $up->get_error_message();
                        } else {
                            $result['updated']++;
                        }
                    }

                    $user_metas = $meta_by_user[$rid] ?? [];
                    $source_meta = [];

                    if ( $user_metas ) {
                        foreach ( $user_metas as $raw_meta ) {
                            $mk = (string) $raw_meta['meta_key'];
                            $mv = $safe_unserialize( $raw_meta['meta_value'] );

                            $source_meta[ $mk ][] = $mv;
                        }

                        foreach ( $source_meta as $mk => $values ) {
                            $source_meta[ $mk ] = count( $values ) === 1 ? $values[0] : $values;
                        }
                    }

                    $normalized_user_metas = $user_metas
                        ? normalize_remote_usermetas_for_target( $user_metas, $local_prefix, $blog_id, $remote_prefix )
                        : [];

                    if ( $normalized_user_metas ) {
                        foreach( $normalized_user_metas as $m ) {
                            $k = (string) $m['meta_key'];
                            $v = $m['meta_value'];

                            if ( $k === '_hacklab_migration_source_id' || $k === '_hacklab_migration_source_blog' ) {
                                continue;
                            }

                            update_user_meta( $target_user_id, $k, $safe_unserialize( $v ) );
                        }
                    }

                    update_user_meta( $target_user_id, '_hacklab_migration_source_id', $rid );

                    if ( $blog_id ) {
                        update_user_meta( $target_user_id, '_hacklab_migration_source_blog', (int) $blog_id );
                    }

                    update_user_meta( $target_user_id, '_hacklab_migration_source_meta', $source_meta );

                    $result['map'][$rid] = (int) $target_user_id;

                    if ( $is_new ) {
                        $result['imported']++;
                    }
                }
            }
        }

    } finally {
        if ( ! $o['dry_run'] ) {
            $restore_email_filters();
        }
    }

    return $result;
}


/**
 * Normaliza metadados dependentes de blog (capabilities/user_level) quando importando de MU para single.
 * - Preserva TODAS as chaves originais.
 * - Adiciona também a variante local (ex.: 'wp_capabilities' e 'wp_user_level') baseada nas chaves do blog remoto.
 *
 * @param array<int,array{meta_key:string,meta_value:mixed}> $metas
 * @param string $local_prefix Ex.: 'wp_'
 * @param int|null $blog_id
 * @return array<int,array{meta_key:string,meta_value:mixed}>
 */
function normalize_remote_usermetas_for_target( array $metas, string $local_prefix, ?int $blog_id, string $remote_prefix = 'wp_' ) : array {
    if ( ! $blog_id || $blog_id <= 1 ) {
        return $metas;
    }

    $remote_blog_prefix = sprintf( '%s%d_', $remote_prefix, $blog_id );
    $out = [];

    foreach ( $metas as $m ) {
        $key = (string) $m['meta_key'];

        $out[] = [
            'meta_key'   => $key,
            'meta_value' => $m['meta_value'],
        ];

        if ( strpos( $key, $remote_blog_prefix ) === 0 ) {
            $local_key = $local_prefix . substr( $key, strlen( $remote_blog_prefix ) );
            $out[] = [
                'meta_key'   => $local_key,
                'meta_value' => $m['meta_value'],
            ];
        }
    }

    return $out;
}

function get_user_by_meta_data( string $meta_key, $meta_value ): int {
    static $cache = [];

    $meta_value = trim( (string) $meta_value );

    if ( $meta_value === '' ) {
        return 0;
    }

    $cache_key = $meta_key . '|' . $meta_value;

    if ( array_key_exists( $cache_key, $cache ) ) {
        return (int) $cache[ $cache_key ];
    }

    $user_query = new \WP_User_Query(
        [
            'meta_key'   => $meta_key,
            'meta_value' => $meta_value,
            'number'     => 1,
            'fields'     => 'ID'
        ]
    );

    $users = $user_query->get_results();

    if ( empty( $users ) ) {
        $cache[ $cache_key ] = 0;
        return 0;
    }

    $user_id = (int) $users[0];
    $cache[ $cache_key ] = $user_id;

    return (int) $user_id;
}

function import_remote_user( int $remote_user_id, int $blog_id = 1, bool $dry_run = false ): int {
    global $wpdb;

    $ext = get_external_wpdb();
    if ( ! $ext instanceof \wpdb ) return 0;

    $creds = get_credentials();
    $remote_prefix = ! empty( $creds['prefix'] ) ? (string) $creds['prefix'] : 'wp_';

    $t_users    = resolve_remote_users_table( $creds );
    $t_usermeta = resolve_remote_usermeta_table( $creds );

    $user_sql = "
        SELECT * FROM {$t_users} u
        WHERE u.ID = %d
        LIMIT 1
    ";

    $u = $ext->get_row( $ext->prepare( $user_sql, $remote_user_id ), ARRAY_A );

    if ( ! $u ) return 0;

    $rid = (int) $u['ID'];
    $login = (string) $u['user_login'];
    $email = (string) $u['user_email'];

    $meta_sql = "
        SELECT um.user_id, um.meta_key, um.meta_value
        FROM {$t_usermeta} um
        WHERE um.user_id = %d
        ORDER BY um.umeta_id ASC
    ";

    $meta_rows = $ext->get_results( $ext->prepare( $meta_sql, $rid ), ARRAY_A ) ?: [];
    $user_metas = [];

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

    foreach ( $meta_rows as $meta ) {
        $user_metas[] = [
            'meta_key'   => (string) $meta['meta_key'],
            'meta_value' => $safe_unserialize( $meta['meta_value'] )
        ];
    }

    $user_metas[] = [
        'meta_key' => '_hacklab_migration_last_updated',
        'meta_value' => time()
    ];

    $local_prefix = $wpdb->get_blog_prefix( 0 );
    $target_blog_id = max( 1, (int) $blog_id );

    $source_meta = [];

    if ( $user_metas ) {
        foreach ( $user_metas as $m ) {
            $mk = (string) $m['meta_key'];
            $mv = $safe_unserialize( $m['meta_value'] );
            $source_meta[ $mk ][] = $mv;
        }

        foreach ( $source_meta as $mk => $values ) {
            $source_meta[ $mk ] = count( $values ) === 1 ? $values[0] : $values;
        }

        $user_metas = normalize_remote_usermetas_for_target( $user_metas, $local_prefix, $target_blog_id, $remote_prefix );
    }

    $target_user_id = 0;
    $exists_by_login = get_user_by( 'login', $login );

    if ( $exists_by_login instanceof \WP_User ) {
        $target_user_id = (int) $exists_by_login->ID;
    }

    if ( $dry_run ) {
        return $target_user_id;
    }

    add_filter( 'send_password_change_email', '__return_false', 999 );
    add_filter( 'send_email_change_email', '__return_false', 999 );
    add_filter( 'wp_send_new_user_notification_to_admin', '__return_false', 999 );
    add_filter( 'wp_send_new_user_notification_to_user', '__return_false', 999 );

    try {
        // Usuário não existe, cria
        if ( $target_user_id <= 0 ) {
            $userdata = [
                'user_login'    => $login,
                'user_email'    => $email,
                'user_nicename' => (string) $u['user_nicename'],
                'user_url'      => (string) $u['user_url'],
                'display_name'  => (string) $u['display_name'],
                'user_pass'     => wp_generate_password( 20, true, true ),
                'role'          => ''
            ];

            if ( ! empty( $u['user_registered'] ) ) {
                $userdata['user_registered'] = $u['user_registered'];
            }

            $new_id = wp_insert_user( $userdata );

            if ( is_wp_error( $new_id ) ) {
                return 0;
            }

            $target_user_id = (int) $new_id;

            $wpdb->update(
                $wpdb->users,
                [
                    'user_pass'           => (string) $u['user_pass'],
                    'user_activation_key' => (string) $u['user_activation_key'],
                    'user_status'         => (int) $u['user_status']
                ],
                [ 'ID' => $target_user_id ],
                [ '%s', '%s', '%d' ],
                [ '%d' ]
            );

            clean_user_cache( $target_user_id );

        } else {
            $update_data = [
                'ID'            => $target_user_id,
                'user_nicename' => (string) $u['user_nicename'],
                'user_url'      => (string) $u['user_url'],
                'display_name'  => (string) $u['display_name'],
            ];

            if ( ! empty( $u['user_registered'] ) ) {
                $update_data['user_registered'] = $u['user_registered'];
            }

            $up = wp_update_user( $update_data );

            if ( is_wp_error( $up ) ) {
                return 0;
            }
        }

        if ( $user_metas ) {
            foreach ( $user_metas as $m ) {
                $k = $m['meta_key'];
                $v = $m['meta_value'];

                if ( $k === '_hacklab_migration_source_id' || $k === '_hacklab_migration_source_blog' ) {
                    continue;
                }

                update_user_meta( $target_user_id, $k, $safe_unserialize( $v ) );
            }
        }

        update_user_meta( $target_user_id, '_hacklab_migration_source_id', $rid );

        if ( $target_blog_id ) {
            update_user_meta( $target_user_id, '_hacklab_migration_source_blog', $target_blog_id );
        }

        update_user_meta( $target_user_id, '_hacklab_migration_source_meta', $source_meta );
    } finally {
        remove_filter( 'send_password_change_email', '__return_false', 999 );
        remove_filter( 'send_email_change_email', '__return_false', 999 );
        remove_filter( 'wp_send_new_user_notification_to_admin', '__return_false', 999 );
        remove_filter( 'wp_send_new_user_notification_to_user', '__return_false', 999 );
    }

    return (int) $target_user_id;
}

/**
 * Localiza um usuário previamente importado a partir do ID remoto e blog remoto.
 *
 * Esta função busca no ambiente local um usuário que tenha sido criado pela migração,
 * utilizando os metadados internos `_hacklab_migration_source_id` e
 * `_hacklab_migration_source_blog`. Esses metadados garantem a unicidade do usuário
 * mesmo em cenários multisite, onde diferentes blogs remotos podem ter IDs iguais.
 *
 * Retorna o ID do usuário local correspondente ao usuário remoto informado.
 * Caso nenhum usuário seja encontrado, retorna 0.
 *
 * @param int $remote_user_id  ID do usuário no ambiente remoto.
 * @param int $blog_id         ID do blog remoto de onde o usuário foi importado. Default 1.
 *
 * @return int ID do usuário local encontrado ou 0 se não houver correspondência.
 *
 * @since 0.0.1
 * @version 1.0.0
 */
function find_local_user( int $remote_user_id, int $blog_id = 1 ): int {
    if ( $remote_user_id <= 0 ) {
        return 0;
    }

    $query_user = new \WP_User_Query( [
        'number'     => 1,
        'fields'     => 'ID',
        'meta_query' => [
            [
                'key'     => '_hacklab_migration_source_id',
                'value'   => $remote_user_id,
                'compare' => '='
            ],
            [
                'key'     => '_hacklab_migration_source_blog',
                'value'   => $blog_id,
                'compare' => '='
            ]
        ]
    ] );

    $ids = $query_user->get_results();

    return $ids ? (int) $ids[0] : 0;
}
