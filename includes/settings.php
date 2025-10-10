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

function add_admin_menu() {
     add_menu_page(
        __( 'Migração', 'hacklabr' ),
        __( 'Migração', 'hacklabr' ),
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
