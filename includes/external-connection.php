<?php

declare( strict_types = 1 );

namespace HacklabMigration;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * -------------------------------------------------------------------------
 * Credenciais padrão para banco de dados externo
 * -------------------------------------------------------------------------
 */
function credential_defaults(): array {
    return [
        'host'         => 'localhost',
        'dbname'       => '',
        'user'         => '',
        'pass'         => '',
        'charset'      => 'utf8mb4',
        'collate'      => 'utf8mb4_unicode_520_ci',
        'prefix'       => 'wp_',
        'is_multisite' => 0,
        'uploads_base' => ''
    ];
}

/**
 * -------------------------------------------------------------------------
 * Deriva uma chave de criptografia a partir das chaves do WP
 * -------------------------------------------------------------------------
 */
function generate_crypto_key(): string {
    $material = ( defined('AUTH_KEY') ? AUTH_KEY : '' )
            . ( defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '' )
            . ( defined('LOGGED_IN_KEY') ? LOGGED_IN_KEY : '' )
            . ( defined('NONCE_KEY') ? NONCE_KEY : '' );

    $info = 'hacklab-migration|' . wp_parse_url( home_url(), PHP_URL_HOST );

    if ( function_exists( 'hash_hkdf' ) ) {
        return hash_hkdf( 'sha256', $material, 32, $info, '' );
    }

    return substr( hash( 'sha256', $material . '|' . $info, true ), 0, 32 );
}

/**
 * -------------------------------------------------------------------------
 * Criptografa payload JSON (string) com Sodium (XSalsa20-Poly1305) ou OpenSSL (AES-256-GCM).
 * Retorna base64 com cabeçalho "v2:sodium:" ou "v2:openssl:".
 * -------------------------------------------------------------------------
 */
function encrypt_credentials( string $plaintext ): ?string {
    $key = generate_crypto_key();

    // Libsodium
    if ( function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'random_bytes' ) ) {
        if ( strlen( $key ) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) {
            $key = substr( hash('sha256', $key, true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
        }

        $nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
        return base64_encode( "v2:sodium:" . $nonce . $cipher );
    }

    // Fallback: OpenSSL AES-256-GCM
    if ( function_exists( 'openssl_encrypt' ) && function_exists( 'random_bytes' ) ) {
        $iv  = random_bytes( 12 );
        $tag = '';
        $cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
        if ( $cipher === false ) {
            return null;
        }
        return base64_encode( "v2:openssl:" . $iv . $tag . $cipher );
    }

    return null;
}

/**
 * -------------------------------------------------------------------------
 * Descriptografa base64 gerado por encrypt_credentials().
 * -------------------------------------------------------------------------
 */
function decrypt_credentials( string $encoded ): ?string {
    $raw = base64_decode( $encoded, true );
    if ( $raw === false ) {
        return null;
    }

    $key = generate_crypto_key();

    if ( strncmp( $raw, 'v2:sodium:', 10 ) === 0 ) {
        $payload = substr( $raw, 10 );

        if ( strlen( $payload ) < ( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) ) {
            return null;
        }

        $nonce  = substr( $payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $cipher = substr( $payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $pt = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
        return $pt === false ? null : $pt;
    }

    if ( strncmp( $raw, 'v2:openssl:', 11 ) === 0 ) {
        $payload = substr( $raw, 11 );
        if ( strlen( $payload ) < (12 + 16) ) return null;
        $iv     = substr( $payload, 0, 12 );
        $tag    = substr( $payload, 12, 16 );
        $cipher = substr( $payload, 28 );
        $pt = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '' );
        return $pt === false ? null : $pt;
    }

    return null;
}

/**
 * -------------------------------------------------------------------------
 * Obtém credenciais descriptografadas e normalizadas.
 * -------------------------------------------------------------------------
 */
function get_credentials(): array {
    $enc = get_option( HACKLAB_MIGRATION_DB_OPTIONS, '' );
    if ( ! $enc ) {
        return credential_defaults();
    }

    $pt = decrypt_credentials( $enc );
    if ( $pt === null ) {
        return credential_defaults();
    }

    $data = json_decode( $pt, true );
    if ( ! is_array( $data ) ) {
        return credential_defaults();
    };

    return array_merge(
        credential_defaults(),
        array_intersect_key( $data, credential_defaults() )
    );
}

/**
 * -------------------------------------------------------------------------
 * Testa conexão usando mysqli sem “bail”.
 * -------------------------------------------------------------------------
 */
function check_connection( array $cfg ): array {
    $status = ['ok' => false, 'message' => ''];

    $raw_host = isset( $cfg['host'] )   ? trim( (string) $cfg['host'] ) : '';
    $dbname   = isset( $cfg['dbname'] ) ? trim( (string) $cfg['dbname'] ) : '';
    $user     = isset( $cfg['user'] )   ? (string) $cfg['user'] : '';
    $pass     = isset( $cfg['pass'] )   ? (string) $cfg['pass'] : '';

    if ( $raw_host === '' || $dbname === '' || $user === '' ) {
        $status['message'] = __( 'Credenciais incompletas.', 'hacklabr' );
        return $status;
    }

    if ( function_exists( 'mysqli_report' ) ) {
        mysqli_report( MYSQLI_REPORT_OFF );
    }

    // Parse de host: suporta host:port, [ipv6]:port, socket
    $host   = $raw_host;
    $port   = 3306;
    $socket = null;

    if ( $raw_host[0] === '/' ) {
        $socket = $raw_host;
        $host   = 'localhost';
    } elseif ( $raw_host[0] === '[' ) {
        // IPv6 entre colchetes, ex.: [2001:db8::1]:3306
        $end_bracket = strpos( $raw_host, ']' );
        if ($end_bracket !== false) {
            $addr = substr( $raw_host, 1, $end_bracket - 1 );
            $rest = substr( $raw_host, $end_bracket + 1 );
            $host = $addr !== '' ? $addr : 'localhost';

            if ( $rest && $rest[0] === ':' && ctype_digit( substr( $rest, 1 ) ) ) {
                $port = (int) substr( $rest, 1 );
            }
        }
    } elseif ( strpos( $raw_host, ':' ) !== false ) {
        // host:port (IPv4/hostname)
        [$h, $p] = explode( ':', $raw_host, 2 );
        $host = $h !== '' ? $h : 'localhost';
        if ( ctype_digit( $p ) ) {
            $port = (int) $p;
        } elseif ( strpos( $p, '/' ) === 0 ) {
            $socket = $p;
        }
    }

    $mysqli = mysqli_init();
    if ( function_exists( 'mysqli_options' ) ) {
        @mysqli_options( $mysqli, MYSQLI_OPT_CONNECT_TIMEOUT, 5 );
    }

    @mysqli_real_connect( $mysqli, $host, $user, $pass, $dbname, $port, $socket );

    if ( mysqli_connect_errno() ) {
        error_log( sprintf(
            'Hacklab Migration DB connect error (%d): %s',
            mysqli_connect_errno(),
            mysqli_connect_error()
        ) );
        $status['message'] = __( 'Não foi possível estabelecer conexão.', 'hacklabr' );
        @mysqli_close( $mysqli );
        return $status;
    }

    $res = @mysqli_query( $mysqli, 'SELECT 1' );
    if ( ! $res ) {
        $status['message'] = __( 'Conexão abriu, mas a consulta de teste falhou.', 'hacklabr' );
        @mysqli_close( $mysqli );
        return $status;
    }

    @mysqli_free_result( $res );
    @mysqli_close( $mysqli );

    $status['ok']      = true;
    $status['message'] = __( 'Conexão OK.', 'hacklabr' );
    return $status;
}

/**
 * -------------------------------------------------------------------------
 * Conecta ao banco externo e retorna um \wpdb pronto para uso.
 * -------------------------------------------------------------------------
 */
function get_external_wpdb(): ?\wpdb {
    $cfg = get_credentials();

    $host   = isset( $cfg['host'] )   ? trim( (string) $cfg['host'] )   : '';
    $dbname = isset( $cfg['dbname'] ) ? trim( (string) $cfg['dbname'] ) : '';
    $user   = isset( $cfg['user'] )   ? (string) $cfg['user']           : '';

    if ( $host === '' || $dbname === '' || $user === '' ) {
        return null;
    }

    $check_connection = check_connection( $cfg );
    if ( ! $check_connection['ok'] ) {
        return null;
    }

    if ( ! class_exists('wpdb', false ) ) {
        require_once ABSPATH . WPINC . '/class-wpdb.php';
    }

    $connect_flag_supported = version_compare( $GLOBALS['wp_version'] ?? '6.1', '6.1', '>=' );

    // Observação: $cfg['host'] pode conter host:port ou [ipv6]:port ou socket.
    // O construtor de wpdb aceita uma string no host com esses formatos.
    $ext = $connect_flag_supported
        ? new \wpdb( $cfg['user'], $cfg['pass'], $cfg['dbname'], (string)$cfg['host'], false)
        : new \wpdb( $cfg['user'], $cfg['pass'], $cfg['dbname'], (string)$cfg['host'] );

    $ext->show_errors( false );
    $ext->suppress_errors( true );

    if ( ! empty( $cfg['charset'] ) ) {
        $ext->charset = (string) $cfg['charset'];
    }
    if ( ! empty( $cfg['collate'] ) ) {
        $ext->collate = (string) $cfg['collate'];
    }

    $prefix = ! empty( $cfg['prefix'] ) ? (string) $cfg['prefix'] : 'wp_';
    $ext->set_prefix( $prefix );

    if ( $connect_flag_supported ) {
        if ( ! $ext->db_connect( false ) || ! empty( $ext->error ) ) {
            return null;
        }
    } else {
        // WP < 6.1: o construtor já tenta conectar
        if ( ! $ext->dbh || ! empty( $ext->error ) ) {
            return null;
        }
    }

    return $ext;
}
