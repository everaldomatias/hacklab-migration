<?php

/**
 * Define o post type `importado` em todos os posts do lote
 *
 * @version 1.0.0
 */
function set_post_type_importado( &$postarr, $options ) {
    $postarr['post_type'] = 'importado';
}


/**
 * Adiciona o termo `csbh-csbh` em todos os posts do lote
 *
 * @version 1.0.0
 */
function set_category_term_csbh_csbh( $local_id, $row, $is_update, $dry_run ) {
    if ( $dry_run ) return;

    $term_name = 'CSBH - CSBH';
    $taxonomy = 'category';

    $term = term_exists( $term_name, $taxonomy );

    if ( ! $term ) {
        $term = wp_insert_term( $term_name, $taxonomy );
    }

    if ( is_wp_error( $term ) ) return false;

    $term_id = is_array( $term ) ? $term['term_id'] : $term;

    wp_set_object_terms( (int) $local_id, (int) $term_id, $taxonomy, true );

    return true;
}
