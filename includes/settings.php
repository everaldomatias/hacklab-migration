<?php

namespace HacklabMigration;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'HACKLAB_MIGRATION_DB_OPTIONS',       'hm_external_db_options' );
define( 'HACKLAB_MIGRATION_DB_OPTIONS_FLAGS', 'hm_external_db_meta' );
define( 'HACKLAB_MIGRATION_MENU_SLUG',        'hacklab-migration-db' );
define( 'HACKLAB_MIGRATION_NONCE_ACTION',     'hm_db_save' );

add_action( 'admin_menu', __NAMESPACE__ . '\\add_admin_menu', 20 );
add_action( 'admin_post_hm_export_meta', __NAMESPACE__ . '\\export_remote_meta_csv' );

function add_admin_menu() {
     add_menu_page(
        __( 'Migração', 'hacklabr' ),
        __( 'h/ Migração', 'hacklabr' ),
        HACKLAB_MIGRATION_CAP,
        'hacklab-migration',
        function () {
            echo '<div class="wrap"><h1>Migração</h1><p>Selecione uma seção no menu.</p></div>';
        },
        'dashicons-migrate',
        80
    );

    add_submenu_page(
        'hacklab-migration',
        __( 'Banco de dados externo', 'hacklabr' ),
        __( 'Banco de dados externo', 'hacklabr' ),
        HACKLAB_MIGRATION_CAP,
        HACKLAB_MIGRATION_MENU_SLUG,
        __NAMESPACE__ . '\\render_settings_page'
    );

    add_submenu_page(
        'hacklab-migration',
        __( 'Metadados', 'hacklabr' ),
        __( 'Metadados', 'hacklabr' ),
        HACKLAB_MIGRATION_CAP,
        'hacklab-migration-remote-meta',
        __NAMESPACE__ . '\\render_remote_meta_page'
    );
}

/**
 * -------------------------------------------------------------------------
 *  Renderiza a página se configurações
 * -------------------------------------------------------------------------
 */
function render_settings_page() {
    if ( ! current_user_can( HACKLAB_MIGRATION_CAP ) ) {
        wp_die( esc_html__( 'Sem permissão.', 'hacklabr' ) );
    }

    $notice_key = 'hm_settings';

    $current = get_credentials();

    if ( isset( $_POST['hm_submit'] ) ) {
        check_admin_referer( HACKLAB_MIGRATION_NONCE_ACTION );

        $in = isset( $_POST['hm'] ) && is_array( $_POST['hm'] ) ? wp_unslash( $_POST['hm'] ) : [];

        $host = sanitize_text_field( $in['host'] ?? '' );
        $host = preg_replace( '/\s+/', '', $host );

        // Configuração do campo Prefixo
        $prefix = isset( $in['prefix'] ) ? sanitize_text_field( $in['prefix'] ) : '';
        $prefix = preg_replace( '/[^A-Za-z0-9_]/', '', $prefix );

        // Garante o underline no final do prefixo
        if ( $prefix && substr( $prefix, -1 ) !== '_' ) {
            $prefix .= '_';
        }

        // Configuração do campo É multisite?
        $is_multisite = isset( $in['is_multisite'] ) && (int) $in['is_multisite'] === 1 ? 1 : 0;

        $new = [
            'host'         => $host ? $host : $current['host'],
            'dbname'       => isset( $in['dbname'] ) ? sanitize_text_field( $in['dbname'] ) : $current['dbname'],
            'user'         => isset( $in['user'] ) ? sanitize_text_field( $in['user'] ) : $current['user'],
            'pass'         => ( isset( $in['pass'] ) && $in['pass'] !== '' ) ? (string) $in['pass'] : $current['pass'],
            'charset'      => isset( $in['charset'] ) ? sanitize_text_field( $in['charset'] ) : $current['charset'],
            'collate'      => isset( $in['collate'] ) ? sanitize_text_field( $in['collate'] ) : $current['collate'],
            'prefix'       => $prefix ?: ( $current['prefix'] ?? '' ),
            'is_multisite' => $is_multisite
        ];

        if ( empty( $new['host'] ) || empty( $new['dbname'] ) || empty( $new['user'] ) ) {
            add_settings_error( $notice_key, 'required', __( 'Host, Banco e Usuário são obrigatórios.', 'hacklabr' ), 'error' );
        } else {
            if ( save_settings( $new ) ) {
                $current = $new;
                add_settings_error( $notice_key, 'saved', __( 'Credenciais salvas com sucesso.', 'hacklabr' ), 'updated' );
            } else {
                add_settings_error( $notice_key, 'encrypt_fail', __( 'Falha ao criptografar/salvar as credenciais.', 'hacklabr' ), 'error' );
            }
        }
    }

    // Teste de conexão no carregamento (com os valores atuais)
    $check_connection = check_connection( $current );

    ?>
    <div class="wrap">
        <h1><?php echo esc_html__( 'Migração — Banco Externo', 'hacklabr' ); ?></h1>

        <?php settings_errors( $notice_key ); ?>

        <div style="margin:1em 0;padding:10px;border-left:4px solid <?php echo $check_connection['ok'] ? '#46b450' : '#dc3232'; ?>;background:#fff;">
            <strong><?php echo esc_html__( 'Status da conexão:', 'hacklabr' ); ?></strong>
            <span style="margin-left:.5em;"><?php echo esc_html( $check_connection['message'] ); ?></span>
        </div>

        <form method="post" action="">
            <?php wp_nonce_field( HACKLAB_MIGRATION_NONCE_ACTION ); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="hm_host"><?php esc_html_e( 'Host', 'hacklabr' ); ?></label></th>
                    <td><input name="hm[host]" id="hm_host" type="text" class="regular-text" value="<?php echo esc_attr( $current['host'] ); ?>" autocomplete="off"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hm_dbname"><?php esc_html_e( 'Nome do Banco', 'hacklabr' ); ?></label></th>
                    <td><input name="hm[dbname]" id="hm_dbname" type="text" class="regular-text" value="<?php echo esc_attr( $current['dbname'] ); ?>" autocomplete="off"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hm_user"><?php esc_html_e( 'Usuário', 'hacklabr' ); ?></label></th>
                    <td><input name="hm[user]" id="hm_user" type="text" class="regular-text" value="<?php echo esc_attr( $current['user'] ); ?>" autocomplete="off"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hm_pass"><?php esc_html_e( 'Senha', 'hacklabr' ); ?></label></th>
                    <td>
                        <input name="hm[pass]" id="hm_pass" type="password" class="regular-text" value="" placeholder="<?php echo $current['pass'] ? esc_attr__( '••••••••', 'hacklabr' ) : ''; ?>" autocomplete="new-password">
                        <p class="description"><?php esc_html_e( 'Deixe em branco para manter a senha atual.', 'hacklabr' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="hm_charset"><?php esc_html_e( 'Charset (ex: utf8mb4)', 'hacklabr' ); ?></label></th>
                    <td><input name="hm[charset]" id="hm_charset" type="text" class="regular-text" value="<?php echo esc_attr( $current['charset'] ); ?>" autocomplete="off"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hm_collate"><?php esc_html_e( 'Collate (ex: utf8mb4_unicode_ci)', 'hacklabr' ); ?></label></th>
                    <td><input name="hm[collate]" id="hm_collate" type="text" class="regular-text" value="<?php echo esc_attr( $current['collate'] ); ?>" autocomplete="off"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hm_prefix"><?php esc_html_e( 'Prefixo (ex: wp_)', 'hacklabr' ); ?></label></th>
                    <td><input name="hm[prefix]" id="hm_prefix" type="text" class="regular-text" value="<?php echo esc_attr( $current['prefix'] ); ?>" autocomplete="off"></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'É multisite?', 'hacklabr' ); ?></th>
                    <td>
                        <select name="hm[is_multisite]" id="hm_is_multisite">
                            <option value="0" <?php selected( (int) ( $current['is_multisite'] ?? 0 ), 0 ); ?>>
                                <?php esc_html_e( 'Não', 'hacklabr' ); ?>
                            </option>
                            <option value="1" <?php selected( (int) ( $current['is_multisite'] ?? 0 ), 1 ); ?>>
                                <?php esc_html_e( 'Sim', 'hacklabr' ); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Informe se o WordPress externo é multisite (subsites usam prefixos como wp_2_, wp_3_...).', 'hacklabr'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button( __( 'Salvar', 'hacklabr' ), 'primary', 'hm_submit' ); ?>
        </form>

        <hr>
        <h2><?php esc_html_e( 'Boas práticas', 'hacklabr' ); ?></h2>
        <ul>
            <li><?php esc_html_e( 'Use um usuário do banco com privilégios mínimos necessários.', 'hacklabr' ); ?></li>
            <li><?php esc_html_e( 'Prefira conexões TLS (MariaDB/MySQL com SSL) quando disponível.', 'hacklabr' ); ?></li>
            <li><?php esc_html_e( 'Defina charset utf8mb4 e um collate consistente.', 'hacklabr' ); ?></li>
        </ul>
    </div>
    <?php
}

/**
 * -------------------------------------------------------------------------
 *  Renderiza página de listagem de meta_keys remotas
 * -------------------------------------------------------------------------
 */
function render_remote_meta_page() {
    if ( ! current_user_can( HACKLAB_MIGRATION_CAP ) ) {
        wp_die( esc_html__( 'Sem permissão.', 'hacklabr' ) );
    }

    $notice_key = 'hm_remote_meta';

    $post_type    = '';
    $blog_id      = 1;
    $meta_keys    = [];
    $meta_samples = [];

    if ( isset( $_POST['hm_meta_submit'] ) ) {
        check_admin_referer( HACKLAB_MIGRATION_NONCE_ACTION );

        $post_type = isset( $_POST['hm_meta_post_type'] )
            ? sanitize_key( wp_unslash( $_POST['hm_meta_post_type'] ) )
            : '';

        $blog_id = isset( $_POST['hm_meta_blog_id'] )
            ? (int) $_POST['hm_meta_blog_id']
            : 1;

        if ( $post_type === '' ) {
            add_settings_error(
                $notice_key,
                'missing_post_type',
                __( 'Informe um post type válido.', 'hacklabr' ),
                'error'
            );
        } else {
            $meta_samples = get_remote_meta_keys_with_example( $post_type, $blog_id );
            $meta_keys    = array_keys( $meta_samples );

            if ( empty( $meta_keys ) ) {
                add_settings_error(
                    $notice_key,
                    'empty_meta_keys',
                    __( 'Nenhuma meta key encontrada para os parâmetros informados.', 'hacklabr' ),
                    'info'
                );
            } else {
                add_settings_error(
                    $notice_key,
                    'meta_keys_loaded',
                    sprintf(
                        __( 'Consulta executada com sucesso. %d meta keys encontradas.', 'hacklabr' ),
                        count( $meta_keys )
                    ),
                    'updated'
                );
            }
        }
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__( 'Migração — Metadados remotos', 'hacklabr' ); ?></h1>

        <?php settings_errors( $notice_key ); ?>

        <p class="description">
            <?php esc_html_e( 'Use esta ferramenta para inspecionar as meta keys usadas em um post type no WordPress externo configurado na aba "Banco de dados externo".', 'hacklabr' ); ?>
        </p>

        <form method="post" action="">
            <?php wp_nonce_field( HACKLAB_MIGRATION_NONCE_ACTION ); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="hm_meta_post_type"><?php esc_html_e( 'Post type remoto', 'hacklabr' ); ?></label>
                    </th>
                    <td>
                        <input
                            name="hm_meta_post_type"
                            id="hm_meta_post_type"
                            type="text"
                            class="regular-text"
                            value="<?php echo esc_attr( $post_type ); ?>"
                            placeholder="post, page, product..."
                        >
                        <p class="description">
                            <?php esc_html_e( 'Informe o post type existente no site remoto (ex: post, page, product).', 'hacklabr' ); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="hm_meta_blog_id"><?php esc_html_e( 'Blog ID remoto', 'hacklabr' ); ?></label>
                    </th>
                    <td>
                        <input
                            name="hm_meta_blog_id"
                            id="hm_meta_blog_id"
                            type="number"
                            class="small-text"
                            value="<?php echo (int) $blog_id; ?>"
                            min="1"
                        >
                        <p class="description">
                            <?php esc_html_e( 'Em instalações multisite, corresponde ao ID do site (wp_2_, wp_3_...). Em single site, use 1.', 'hacklabr' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button( __( 'Consultar meta keys remotas', 'hacklabr' ), 'secondary', 'hm_meta_submit' ); ?>
        </form>

        <?php if ( ! empty( $meta_keys ) ) : ?>
            <hr>
            <h2>
                <?php
                printf(
                    esc_html__( 'Meta keys encontradas (%d)', 'hacklabr' ),
                    count( $meta_keys )
                );
                ?>
            </h2>

            <p class="description">
                <?php esc_html_e( 'A lista abaixo é apenas para referência visual. Você pode copiá-la e usar em mapeamentos de migração.', 'hacklabr' ); ?>
            </p>

            <textarea
                readonly
                style="width:100%;max-width:900px;min-height:220px;font-family:monospace;"
            ><?php echo esc_textarea( implode( "\n", $meta_keys ) ); ?></textarea>

            <?php if ( ! empty( $meta_samples ) ) : ?>
                <h3><?php esc_html_e( 'Exemplos de valores', 'hacklabr' ); ?></h3>
                <p class="description">
                    <?php esc_html_e( 'Coleta um exemplo por meta key para facilitar mapeamentos.', 'hacklabr' ); ?>
                </p>
                <table class="widefat striped" style="max-width:960px;">
                    <thead>
                        <tr>
                            <th style="width:220px;"><?php esc_html_e( 'Meta key', 'hacklabr' ); ?></th>
                            <th><?php esc_html_e( 'Exemplo de valor', 'hacklabr' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $meta_keys as $mk ) : ?>
                            <tr>
                                <td><code><?php echo esc_html( $mk ); ?></code></td>
                                <td>
                                    <?php
                                    $raw = $meta_samples[ $mk ] ?? '';
                                    if ( $raw === '' ) {
                                        echo '<span style="color:#666;">&mdash;</span>';
                                    } else {
                                        $maybe = maybe_unserialize( $raw );
                                        if ( is_array( $maybe ) || is_object( $maybe ) ) {
                                            $render = wp_json_encode( $maybe, JSON_UNESCAPED_UNICODE );
                                        } else {
                                            $render = (string) $maybe;
                                        }
                                        $render = mb_substr( $render, 0, 400 );
                                        echo '<code style="white-space:pre-wrap;">' . esc_html( $render ) . '</code>';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
                    <?php wp_nonce_field( HACKLAB_MIGRATION_NONCE_ACTION ); ?>
                    <input type="hidden" name="hm_meta_post_type" value="<?php echo esc_attr( $post_type ); ?>">
                    <input type="hidden" name="hm_meta_blog_id" value="<?php echo (int) $blog_id; ?>">
                    <input type="hidden" name="action" value="hm_export_meta">
                    <input type="hidden" name="hm_meta_export" value="1">
                    <?php submit_button( __( 'Baixar CSV (meta_key, valor)', 'hacklabr' ), 'secondary', 'hm_meta_export_btn', false ); ?>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}


/**
 * -------------------------------------------------------------------------
 *  Salva as configurações
 * -------------------------------------------------------------------------
 */
function save_settings( array $values ) : bool {
    $payload = wp_json_encode( $values, JSON_UNESCAPED_UNICODE );
    $encrypt = encrypt_credentials( $payload );
    if ( $encrypt === null ) return false;

    // Garante autoload = no
    if ( get_option( HACKLAB_MIGRATION_DB_OPTIONS, null ) === null ) {
        add_option( HACKLAB_MIGRATION_DB_OPTIONS, $encrypt, '', 'no' );
    } else {
        update_option( HACKLAB_MIGRATION_DB_OPTIONS, $encrypt );
    }

    if ( get_option( HACKLAB_MIGRATION_DB_OPTIONS_FLAGS, null ) === null ) {
        add_option( HACKLAB_MIGRATION_DB_OPTIONS_FLAGS, [ 'format' => 1, 'updated' => time() ], '', 'no' );
    } else {
        update_option( HACKLAB_MIGRATION_DB_OPTIONS_FLAGS, [ 'format' => 1, 'updated' => time() ] );
    }

    return true;
}

/**
 * Exporta meta keys e exemplos em CSV (endpoint admin-post.php?action=hm_export_meta).
 */
function export_remote_meta_csv() {
    if ( ! current_user_can( HACKLAB_MIGRATION_CAP ) ) {
        wp_die( esc_html__( 'Sem permissão.', 'hacklabr' ) );
    }

    check_admin_referer( HACKLAB_MIGRATION_NONCE_ACTION );

    $post_type = isset( $_POST['hm_meta_post_type'] )
        ? sanitize_key( wp_unslash( $_POST['hm_meta_post_type'] ) )
        : '';

    $blog_id = isset( $_POST['hm_meta_blog_id'] )
        ? (int) $_POST['hm_meta_blog_id']
        : 1;

    if ( $post_type === '' ) {
        wp_die( esc_html__( 'Post type inválido para exportação.', 'hacklabr' ) );
    }

    $meta_samples = get_remote_meta_keys_with_example( $post_type, $blog_id );

    $filename = sprintf( 'remote-meta-%s-blog-%d.csv', $post_type, (int) $blog_id );

    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

    $out = fopen( 'php://output', 'w' );
    if ( $out ) {
        fputcsv( $out, [ 'meta_key', 'example_value' ] );
        foreach ( $meta_samples as $mk => $raw ) {
            $maybe = maybe_unserialize( $raw );
            if ( is_array( $maybe ) || is_object( $maybe ) ) {
                $val = wp_json_encode( $maybe, JSON_UNESCAPED_UNICODE );
            } else {
                $val = (string) $maybe;
            }
            fputcsv( $out, [ $mk, $val ] );
        }
        fclose( $out );
    }
    exit;
}
