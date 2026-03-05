<?php

namespace HacklabMigration;

if ( ! class_exists( '\WP_CLI' ) ) {
    return;
}

class Helpers {
    public static function csv_or_scalar( $value ) {
        if ( is_array( $value ) ) return $value;
        if ( is_string( $value ) && strpos( $value, ',' ) !== false ) {
            return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), static fn( $v ) => $v !== '' ) );
        }
        return $value;
    }

    public static function csv_ints( $value ): array {
        if ( is_array( $value ) ) {
            $vals = $value;
        } else {
            $vals = explode( ',', (string) $value );
        }
        return array_values(
            array_filter(
                array_map( 'intval', array_map( 'trim', $vals ) ),
                static fn( $v ) => $v > 0
            )
        );
    }

    public static function to_bool( $value, bool $default = false ): bool {
        if ( $value === null ) return $default;
        if ( is_bool( $value ) ) return $value;
        $v = strtolower( (string) $value );
        if ( $v === '' ) return $default;
        return in_array( $v, [ '1', 'true', 'yes', 'y', 'on' ], true );
    }
}
