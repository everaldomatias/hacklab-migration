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

    if ( ! $mes && ! $ano ) {
        return false;
    }

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

/**
 * Função para agrupar múltiplos callbacks ao importar posts
 */
function run_callbacks( \WP_Post $post ) {
    set_image_meta_field( $post );
    set_mes_ano_field( $post );
    set_link_info_url_field( $post );
    set_pdf_field( $post );
}

/**
 * Reprocessa o post inteiro em uma única esteira (Divi -> URLs -> Thumbnails -> Limpeza).
 * Feita especificamente para ser o callback do comando `wp modify-posts`.
 *
 * COMANDO CLI:
 * wp modify-posts q:post_type=post q:include=72457 fn:"hacklabr\Utils\reprocess_post_content"
 *
 * @param \WP_Post $post O objeto do post atual no loop do CLI.
 */
function reprocess_post_content( \WP_Post $post ): object {
    $post_id = $post->ID;

    // Recupera dados de origem
    $post_blog_id = (int) get_post_meta( $post_id, '_hacklab_migration_source_blog', true );
    $post_blog_id = $post_blog_id > 0 ? $post_blog_id : 1;

    $raw_content = get_post_meta( $post_id, '_hacklab_migration_source_content', true );
    $content     = ! empty( $raw_content ) ? $raw_content : $post->post_content;

    // Remove tags DIVI -> GUTENBERG
    if ( function_exists( '\HacklabMigration\remove_divi_tags' ) ) {
        $content = \HacklabMigration\remove_divi_tags( $content );
    }

    // Tratamento da imagem destacada
    $remote_thumb_id = 0;
    $source_meta = get_post_meta( $post_id, '_hacklab_migration_source_meta', true );

    if ( is_array( $source_meta ) && ! empty( $source_meta['_thumbnail_id'] ) ) {
        $candidate = $source_meta['_thumbnail_id'];
        $remote_thumb_id = (int) ( is_array( $candidate ) ? reset( $candidate ) : $candidate );
    }

    $final_thumb_id = (int) get_post_thumbnail_id( $post_id );

    if ( $remote_thumb_id > 0 ) {
        $attachment_id = \HacklabMigration\find_local_post( $remote_thumb_id, $post_blog_id );

        if ( $attachment_id > 0 ) {
            if ( $final_thumb_id !== $attachment_id ) {
                set_post_thumbnail( $post_id, $attachment_id );
                $final_thumb_id = $attachment_id;
            }
        } else {
            $info = \HacklabMigration\fetch_remote_attachments_by_ids( [ $remote_thumb_id ], $post_blog_id, true );
            if ( ! empty( $info[ $remote_thumb_id ] ) ) {
                $data = $info[ $remote_thumb_id ];
                $data['meta'] = \HacklabMigration\apply_rsync_prefix_to_rmeta( $data['meta'], $post_blog_id );
                $run_id = get_post_meta( $post_id, '_hacklab_migration_import_run_id', true );

                $att_id = \HacklabMigration\register_local_attachments(
                    $data['post'] ?? [],
                    $data['meta'] ?? [],
                    $post_blog_id,
                    $run_id,
                    $post_blog_id,
                    true
                );

                if ( $att_id > 0 ) {
                    set_post_thumbnail( $post_id, $att_id );
                    $final_thumb_id = $att_id;
                }
            }
        }
    }

    // Reescreve as URLs de mídia no content
    $uploads_base = (string) ( get_post_meta( $post_id, '_hacklab_migration_uploads_base', true ) ?? '' );

    if ( $uploads_base !== '' && function_exists( '\HacklabMigration\replace_content_urls' ) ) {
        $new_base = HACKLAB_MIGRATION_UPLOADS_BASEURL;
        $content  = \HacklabMigration\replace_content_urls( $content, $uploads_base, $new_base, $post_blog_id, true );
    }

    // Remove a imagem destacada do content
    if ( $final_thumb_id > 0 && ! empty( $content ) ) {
        $featured_url = wp_get_attachment_url( $final_thumb_id );

        if ( $featured_url ) {
            $featured_basename = pathinfo( $featured_url, PATHINFO_FILENAME );
            $featured_basename_clean = preg_replace( '/(-scaled?|-\d+x\d+)+$/i', '', $featured_basename );

            if ( $post_blog_id > 1 ) {
                $prefix = $post_blog_id . '-';
                if ( strpos( $featured_basename_clean, $prefix ) === 0 ) {
                    $featured_basename_clean = substr( $featured_basename_clean, strlen( $prefix ) );
                }
            }

            $pattern = '/(?:\s*)?(?:\s*<figure[^>]*>)?\s*<img[^>]+src=["\']([^"\']+)["\'][^>]*>\s*(?:<\/figure>\s*)?(?:\s*)?/is';

            if ( preg_match( $pattern, $content, $matches ) ) {
                $first_img_url = $matches[1];
                $first_img_basename = pathinfo( $first_img_url, PATHINFO_FILENAME );
                $first_img_basename_clean = preg_replace( '/(-scaled?|-\d+x\d+)+$/i', '', $first_img_basename );

                if ( $post_blog_id > 1 ) {
                    if ( strpos( $first_img_basename_clean, $prefix ) === 0 ) {
                        $first_img_basename_clean = substr( $first_img_basename_clean, strlen( $prefix ) );
                    }
                }

                if ( $featured_basename_clean === $first_img_basename_clean ) {
                    $cleaned_content = preg_replace( $pattern, '', $content, 1 );

                    if ( $cleaned_content !== null ) {
                        $cleaned_content = preg_replace( '/<p>\s*(?:<br\s*\/?>|&nbsp;)?\s*<\/p>/i', '', $cleaned_content );
                        $content = trim( $cleaned_content );
                    }
                }
            }
        }
    }

    // Salva o content com as alterações
    $post->post_content = $content;

    // Verifica metadados (ACF, Galerias, etc)e anexos
    if ( $uploads_base !== '' && function_exists( '\HacklabMigration\rewrite_post_media_urls' ) ) {
        \HacklabMigration\rewrite_post_media_urls( $post_id, $uploads_base, $post_blog_id, true, false );
    }

    return $post;
}

/**
 * Procura um número no título do post e o atribui como termo na taxonomia 'edicao'.
 * * Exemplo: "Revista Edição 123" -> Termo "123" na taxonomia "edicao".
 *
 * COMANDO CLI:
 * wp modify-posts q:post_type=post fn="HacklabMigration\map_title_number_to_edition_tax"
 *
 * @param \WP_Post $post Objeto do post fornecido pelo WordPress ou pelo modify-posts.
 */
function map_title_number_to_edition_tax( \WP_Post $post ): void {
    if ( ! $post instanceof \WP_Post ) {
        return;
    }

    if ( preg_match( '/\d+/', $post->post_title, $matches ) ) {
        $edition_number = $matches[0];
        $result = wp_set_object_terms( $post->ID, $edition_number, 'edicao', false );

        if ( is_wp_error( $result ) ) {
            if ( defined( 'WP_CLI' ) && WP_CLI ) {
                do_action( 'logger', "Post {$post->ID}: Erro ao atribuir edição {$edition_number}." );
            }
        }
    } else {
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            do_action( 'logger', "Post {$post->ID}: Nenhum número encontrado no título." );
        }
    }
}

/**
 * Converte um post em um termo da taxonomia "edicao", copia seus metadados
 * e reescreve a URL da imagem de capa (wpcf-edicao_lista_capa).
 * * USO VIA CLI:
 * wp modify-posts --q:post_type=edicao --fn="HacklabMigration\fpa_convert_post_to_edition_term"
 *
 * @param \WP_Post $post Objeto do post atual no loop do CLI.
 */
function fpa_convert_post_to_edition_term( \WP_Post $post ): void {
    $taxonomy  = 'edicao';
    $term_name = trim( $post->post_title );
    $term_name = $term_name . ' – Teoria e Debate';

    $source_id   = (int) get_post_meta( $post->ID, '_hacklab_migration_source_id', true );
    $source_blog = (int) get_post_meta( $post->ID, '_hacklab_migration_source_blog', true );

    if ( $source_blog <= 0 ) {
        $source_blog = 1;
    }

    $existing_terms = get_terms( [
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
        'fields'     => 'ids',
        'meta_query' => [
            'relation' => 'AND',
            [
                'key'     => '_hacklab_migration_source_id',
                'value'   => $source_id,
                'compare' => '='
            ],
            [
                'key'     => '_hacklab_migration_source_blog',
                'value'   => $source_blog,
                'compare' => '='
            ]
        ]
    ] );

    $term_id = 0;

    if ( ! empty( $existing_terms ) && ! is_wp_error( $existing_terms ) ) {
        // Termo já existe
        $term_id = (int) $existing_terms[0];
    } else {
        // Termo não existe, vamos criar
        $inserted_term = wp_insert_term( $term_name, $taxonomy );

        if ( is_wp_error( $inserted_term ) ) {
            // Se falhou porque já existe um termo com este nome (slug collision), tentamos pegar o ID dele
            if ( isset( $inserted_term->error_data['term_exists'] ) ) {
                $term_id = (int) $inserted_term->error_data['term_exists'];
            } else {
                do_action( 'logger', "Erro ao criar termo {$term_name}: " . $inserted_term->get_error_message() );
                return;
            }
        } else {
            $term_id = (int) $inserted_term['term_id'];
        }
    }

    if ( $term_id <= 0 ) {
        return;
    }

    $all_post_meta = get_post_custom( $post->ID );

    foreach ( $all_post_meta as $meta_key => $meta_values ) {
        foreach ( $meta_values as $meta_value ) {
            $unserialized_value = maybe_unserialize( $meta_value );
            update_term_meta( $term_id, $meta_key, $unserialized_value );
        }
    }
}
