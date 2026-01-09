<?php

/**
 * Resolve o nome da tabela `posts` remota, considerando instalação multisite.
 *
 * - Para single-site ou blog ID 1, retorna: `wp_posts` (ou outro prefixo customizado).
 * - Para multisite com blog ID > 1, retorna: `wp_<blog_id>_posts`.
 *
 * @since 1.0.0
 *
 * @param array     $creds    Credenciais ou configurações da instalação remota.
 *                            Espera-se que contenha:
 *                            - 'prefix' (string): prefixo das tabelas (padrão: 'wp_').
 *                            - 'is_multisite' (bool): se a instalação é multisite.
 * @param int|null  $blog_id  ID do site no multisite. Se null ou 1, assume single-site.
 *
 * @return string             Nome da tabela `posts` remota apropriada.
 *
 */
function resolve_remote_posts_table( array $creds, ?int $blog_id, bool $force_base_prefix = false ): string {
    $prefix = ! empty( $creds['prefix'] ) ? (string) $creds['prefix'] : 'wp_';
    $is_ms  = ! empty( $creds['is_multisite'] );

    if ( $force_base_prefix || ! $is_ms || ! $blog_id || (int) $blog_id === 1 ) {
        return $prefix . 'posts';
    }

    return $prefix . ( (int) $blog_id ) . '_posts';
}


/**
 * Resolve o nome da tabela `postmeta` remota, levando em conta se o site é multisite ou não.
 *
 * - Em instalações single-site ou no blog ID 1, a tabela será `<prefix>postmeta`.
 * - Em multisite com blog ID > 1, a tabela será `<prefix><blog_id>_postmeta`.
 *
 * @since 1.0.0
 *
 * @param array     $creds    Credenciais e configurações do banco de dados remoto.
 *                            Espera-se que contenha:
 *                            - 'prefix' (string): prefixo das tabelas (padrão: 'wp_').
 *                            - 'is_multisite' (bool): se a instalação remota é multisite.
 * @param int|null  $blog_id  ID do site no caso de multisite. Se nulo ou 1, assume single-site.
 *
 * @return string             Nome da tabela `postmeta` remota apropriada.
 *
 */
function resolve_remote_postmeta_table( array $creds, ?int $blog_id, bool $force_base_prefix = false ): string {
    $prefix = ! empty( $creds['prefix'] ) ? (string) $creds['prefix'] : 'wp_';
    $is_ms  = ! empty( $creds['is_multisite'] );

    if ( $force_base_prefix || ! $is_ms || ! $blog_id || (int) $blog_id === 1 ) {
        return $prefix . 'postmeta';
    }

    return $prefix . ( (int) $blog_id ) . '_postmeta';
}


/**
 * Resolve os nomes das tabelas relacionadas a termos (taxonomias) em um banco remoto.
 *
 * Esta função retorna os nomes completos das tabelas `terms`, `term_taxonomy` e
 * `term_relationships`, levando em consideração se o banco remoto utiliza multisite
 * e o ID do blog (site) atual.
 *
 * - Em instalações single-site ou no blog ID 1, as tabelas terão o prefixo padrão.
 * - Em multisite com blog ID > 1, as tabelas incluirão o ID do blog no nome.
 *
 * @since 1.0.0
 *
 * @param array     $creds    Array com as credenciais e configurações de conexão.
 *                            Espera-se que contenha:
 *                            - 'prefix' (string): prefixo das tabelas (padrão: 'wp_').
 *                            - 'is_multisite' (bool): se a instalação é multisite.
 * @param int|null  $blog_id  ID do blog (site), usado em multisite. Se nulo ou 1, assume single-site.
 *
 * @return array              Array associativo com os nomes completos das tabelas:
 *                            - 'terms'
 *                            - 'term_taxonomy'
 *                            - 'term_relationships'
 */
function resolve_remote_terms_tables( array $creds, ?int $blog_id, bool $force_base_prefix = false ): array {
    $prefix = ! empty( $creds['prefix'] ) ? (string) $creds['prefix'] : 'wp_';
    $is_ms  = ! empty( $creds['is_multisite'] );
    $mid    = ( $force_base_prefix || ! $is_ms || ! $blog_id || (int) $blog_id === 1 ) ? '' : ( (int) $blog_id . '_' );

    return [
        'terms'              => $prefix . $mid . 'terms',
        'term_taxonomy'      => $prefix . $mid . 'term_taxonomy',
        'term_relationships' => $prefix . $mid . 'term_relationships',
        'termmeta'           => $prefix . $mid . 'termmeta'
    ];
}

/**
 * Resolve o nome da tabela `users` remota, considerando instalação multisite.
 *
 * - Para single-site ou blog ID 1, retorna: `wp_users` (ou outro prefixo customizado).
 * - Para multisite, a tabela `users` é compartilhada e não inclui o ID do blog.
 *
 * @since 1.0.0
 *
 * @param array $creds Credenciais ou configurações da instalação remota.
 *                     Espera-se que contenha:
 *                     - 'prefix' (string): prefixo das tabelas (padrão: 'wp_').
 *
 * @return string       Nome da tabela `users` remota apropriada.
 */
function resolve_remote_users_table( array $creds ): string {
    $prefix = ! empty( $creds['prefix'] ) ? (string) $creds['prefix'] : 'wp_';
    return $prefix . 'users';
}

function resolve_remote_usermeta_table( array $creds ): string {
    $prefix = ! empty( $creds['prefix'] ) ? (string) $creds['prefix'] : 'wp_';
    return $prefix . 'usermeta';
}

/**
 * Resolve o nome da tabela `options` remota, considerando multisite e blog_id.
 */
function resolve_remote_options_table( array $creds, ?int $blog_id, bool $force_base_prefix = false ): string {
    $prefix = ! empty( $creds['prefix'] ) ? (string) $creds['prefix'] : 'wp_';
    $is_ms  = ! empty( $creds['is_multisite'] );

    if ( $force_base_prefix || ! $is_ms || ! $blog_id || (int) $blog_id === 1 ) {
        return $prefix . 'options';
    }

    return $prefix . ( (int) $blog_id ) . '_options';
}
