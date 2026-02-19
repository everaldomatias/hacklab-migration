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

/**
 * Sincroniza o metadado 'link_info_image' para o campo 'imagem'.
 * * @param WP_Post $post O objeto do post.
 * @return bool Retorna true se o metadado foi atualizado, false caso contrário.
 */
function set_image_meta_field( WP_Post $post ): bool {
    $image_data = get_post_meta( $post->ID, 'link_info_image', true );

    if ( empty( $image_data ) ) {
        return false;
    }

    $current_imagem = get_post_meta( $post->ID, 'imagem', true );
    if ( $current_imagem === $image_data ) {
        return false;
    }

    return (bool) update_post_meta( $post->ID, 'imagem', $image_data );
}


/**
 * Concatena 'edicao_mes' e 'edicao_ano' no metadado 'mes_e_ano'.
 * * @param WP_Post $post Objeto do post fornecido pelo loop ou WP-CLI.
 */
function set_mes_ano_field( $post ) {
    $mes = get_post_meta( $post->ID, 'edicao_mes', true );
    $ano = get_post_meta( $post->ID, 'edicao_ano', true );

    $partes = array_filter( [ $mes, $ano ], function( $valor ) {
        return ! empty( trim( (string) $valor ) );
    } );

    $valor_final = implode( ' ', $partes );

    if ( ! empty( $valor_final ) ) {
        update_post_meta( $post->ID, 'mes_e_ano', $valor_final );
    }
}

/**
 * Normaliza e sincroniza a URL do YouTube entre campos de metadados.
 *
 * @param \WP_Post $post Objeto do post a ser processado.
 * * @return bool Retorna true se o metadado 'url' foi atualizado com sucesso,
 * false se o campo de origem estava vazio ou se o valor já era idêntico.
 */
function set_link_info_url_field( \WP_Post $post ): bool {
    $link_info_url = get_post_meta( $post->ID, 'link_info_url', true );

    if ( empty( $link_info_url ) ) {
        return false;
    }
    if ( strpos( $link_info_url, 'http' ) === false && strpos( $link_info_url, 'youtube.com' ) === false ) {
        $link_info_url = "https://www.youtube.com/watch?v={$link_info_url}";
    }

    $current_link_info_url = get_post_meta( $post->ID, 'url', true );

    if ( $link_info_url === $current_link_info_url ) {
        return false;
    }

    return (bool) update_post_meta( $post->ID, 'url', $link_info_url );
}

/**
 * Mapeia IDs de anexos remotos salvos em campos Pods para os novos IDs locais.
 * Utiliza a API nativa do Pods para garantir a integridade dos dados e do cache.
 *
 * @param \WP_Post $post      O objeto do post local.
 * @param string   $pod_field O nome do campo no Pods (ex: 'pdf').
 *
 * @return bool True se atualizou, False se não precisou ou falhou.
 */
function set_pdf_field( \WP_Post $post ): bool {
    global $wpdb;

    $source_meta = get_post_meta( $post->ID, '_hacklab_migration_source_meta', false );

    if ( ! is_array( $source_meta ) || empty( $source_meta[0] ) ) {
        return false;
    }

    $meta_data = $source_meta[0];
    $pods_hidden_field = '_pods_pdf';
    $remote_attachment_id = 0;

    if ( isset( $meta_data[ $pods_hidden_field ] ) && is_array( $meta_data[ $pods_hidden_field ] ) ) {
        $remote_attachment_id = (int) ( $meta_data[ $pods_hidden_field ][0] ?? 0 );
    }

    if ( $remote_attachment_id <= 0 ) {
        do_action( 'logger', [
            'context'              => 'Metadado do arquivo não encontrado | set_pdf_field()',
            'post_id'              => $post->ID
        ] );
        return false;
    }

    $source_blog_id = (int) get_post_meta( $post->ID, '_hacklab_migration_source_blog', true );

    if ( $source_blog_id <= 0 ) {
        $source_blog_id = 1;
    }

    $sql = $wpdb->prepare( "
        SELECT pm1.post_id
        FROM {$wpdb->postmeta} pm1
        INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
        INNER JOIN {$wpdb->posts} p ON pm1.post_id = p.ID
        WHERE pm1.meta_key = '_hacklab_migration_source_id' AND pm1.meta_value = %d
        AND pm2.meta_key = '_hacklab_migration_source_blog' AND pm2.meta_value = %d
        AND p.post_type = 'attachment'
        LIMIT 1
    ", $remote_attachment_id, $source_blog_id );

    $local_attachment_id = (int) $wpdb->get_var( $sql );

    if ( $local_attachment_id <= 0 ) {
        do_action( 'logger', [
            'context'              => 'Arquivo não encontrado | set_pdf_field()',
            'post_id'              => $post->ID,
            'remote_attachment_id' => $remote_attachment_id
        ] );
        return false;
    }

    if ( function_exists( 'pods' ) ) {
        $pod = pods( $post->post_type, $post->ID );

        if ( $pod ) {
            $pod->save( 'pdf', [ $local_attachment_id ] );
            return true;
        }
    }

    $updated_visible = update_post_meta( $post->ID, 'pdf', (string) $local_attachment_id );
    $updated_hidden  = update_post_meta( $post->ID, $pods_hidden_field, [ $local_attachment_id ] );

    return ( $updated_visible !== false || $updated_hidden !== false );
}

/**
 * Corrige metadados serializados que deveriam ser inteiros únicos.
 */
function fix_serialized_thumbnail_id( $post ) {
    $thumb_id = get_post_meta( $post->ID, '_thumbnail_id', true );

    // Verifica se o dado veio como array (o WP já deserializa automaticamente no get_post_meta)
    if ( is_array( $thumb_id ) ) {
        // Pega o primeiro valor do array (no seu caso, 89256)
        $correct_id = reset( $thumb_id );

        if ( is_numeric( $correct_id ) ) {
            update_post_meta( $post->ID, '_thumbnail_id', (int) $correct_id );
            echo "Post {$post->ID}: Corrigido de array para ID " . (int) $correct_id . "\n";
        }
    }
}
