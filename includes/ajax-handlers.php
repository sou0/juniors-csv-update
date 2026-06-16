<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action('wp_ajax_ycu_upload_csv', 'ycu_upload_csv_handler');
add_action('wp_ajax_ycu_process_row', 'ycu_process_row_handler');
add_action('wp_ajax_ycu_export_csv', 'ycu_export_csv_handler');

function ycu_upload_csv_handler() {
    check_ajax_referer('ycu_nonce', 'nonce');

    if (!isset($_FILES['file'])) {
        wp_send_json_error('Nenhum arquivo enviado.');
    }

    $file = $_FILES['file']['tmp_name'];
    $data = array();
    $headers = array();
    
    if (($handle = fopen($file, 'r')) !== FALSE) {
        $headers_raw = fgetcsv($handle, 10000, ",");
        if (!$headers_raw) {
            $headers_raw = fgetcsv($handle, 10000, ";");
        }
        
        $headers = array_map('trim', $headers_raw); // Keep original casing for mapping keys
        
        while (($row = fgetcsv($handle, 10000, ",")) !== FALSE) {
            if (count($row) == 1 && count($headers) > 1) {
                $row = explode(";", $row[0]);
            }
            
            $rowData = array();
            foreach ($headers as $index => $header) {
                $rowData[$header] = isset($row[$index]) ? trim($row[$index]) : '';
            }
            $data[] = $rowData;
        }
        fclose($handle);
    }

    wp_send_json_success(array('rows' => $data, 'headers' => $headers, 'total' => count($data)));
}

function ycu_find_object($identifier, $service_type = '') {
    global $wpdb;
    $identifier = trim($identifier);

    if (empty($identifier) && $identifier !== '0') return false;

    // Check if numeric (ID)
    if (is_numeric($identifier)) {
        if ($service_type === 'terms') {
            $term = get_term($identifier);
            if ($term && !is_wp_error($term)) return array('type' => 'term', 'id' => $term->term_id);
            
            $post = get_post($identifier);
            if ($post) return array('type' => 'post', 'id' => $post->ID, 'post_type' => $post->post_type);
        } else {
            $post = get_post($identifier);
            if ($post) return array('type' => 'post', 'id' => $post->ID, 'post_type' => $post->post_type);

            $term = get_term($identifier);
            if ($term && !is_wp_error($term)) return array('type' => 'term', 'id' => $term->term_id);
        }
    }

    $home_url = rtrim(home_url(), '/');
    $is_url = filter_var($identifier, FILTER_VALIDATE_URL) || strpos($identifier, 'http') === 0;
    $ident_url = rtrim($is_url ? $identifier : home_url($identifier), '/');

    // Home page
    if ($ident_url === $home_url || $identifier === '/') {
        if (get_option('show_on_front') == 'page') {
            $page_on_front = get_option('page_on_front');
            if ($page_on_front) return array('type' => 'post', 'id' => $page_on_front, 'post_type' => 'page');
        } else {
            return array('type' => 'home_posts', 'id' => 0);
        }
    }

    // Try finding by URL
    if ($is_url) {
        $post_id = url_to_postid($identifier);
        
        // If not found, try attachment_url_to_postid
        if (!$post_id && function_exists('attachment_url_to_postid')) {
            $post_id = attachment_url_to_postid($identifier);
        }

        if ($post_id) return array('type' => 'post', 'id' => $post_id, 'post_type' => get_post_type($post_id));
    }

    // Parse slug variants
    $parsed_url = parse_url((strpos($identifier, 'http') === false ? 'http://' : '') . $identifier);
    $path = isset($parsed_url['path']) ? trim($parsed_url['path'], '/') : '';
    if (empty($path)) {
        $path = trim($identifier, '/');
    }
    
    $full_filename = basename($path);
    $slug_only = pathinfo($full_filename, PATHINFO_FILENAME);
    $slug_from_path = basename($path);

    $slug_variants = array_unique(array(
        $slug_only,
        strtolower($slug_only),
        $slug_from_path,
        strtolower($slug_from_path),
        sanitize_title($slug_only),
        sanitize_title($full_filename)
    ));

    // SEARCH LOGIC START
    
    // 1. If terms service, try terms first (Slug or Name)
    if ($service_type === 'terms') {
        // Try by Slug
        $where_parts = array();
        foreach ($slug_variants as $variant) { $where_parts[] = $wpdb->prepare("slug = %s", $variant); }
        $term = $wpdb->get_row("SELECT term_id FROM $wpdb->terms WHERE " . implode(" OR ", $where_parts) . " LIMIT 1");
        if ($term) return array('type' => 'term', 'id' => $term->term_id);

        // Try by Name
        $term_by_name = $wpdb->get_row($wpdb->prepare("SELECT term_id FROM $wpdb->terms WHERE name = %s LIMIT 1", $identifier));
        if ($term_by_name) return array('type' => 'term', 'id' => $term_by_name->term_id);
    }

    // 2. Try Posts (Slug)
    $where_parts = array();
    foreach ($slug_variants as $variant) { $where_parts[] = $wpdb->prepare("post_name = %s", $variant); }
    $post = $wpdb->get_row("SELECT ID, post_type FROM $wpdb->posts WHERE (" . implode(" OR ", $where_parts) . ") AND post_status IN ('publish', 'draft', 'pending', 'private', 'inherit') AND post_type NOT IN ('revision', 'nav_menu_item') LIMIT 1");
    if ($post) return array('type' => 'post', 'id' => $post->ID, 'post_type' => $post->post_type);

    // 3. Try Post (Title)
    $post_by_title = $wpdb->get_row($wpdb->prepare("SELECT ID, post_type FROM $wpdb->posts WHERE post_title = %s AND post_status IN ('publish', 'draft', 'pending', 'private', 'inherit') AND post_type NOT IN ('revision', 'nav_menu_item') LIMIT 1", $identifier));
    if ($post_by_title) return array('type' => 'post', 'id' => $post_by_title->ID, 'post_type' => $post_by_title->post_type);

    // 4. Try Media (Filename in Meta)
    if (preg_match('/\.(jpg|jpeg|png|gif|webp|pdf|svg)$/i', $full_filename)) {
        $attachment_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s LIMIT 1", '%' . $wpdb->esc_like($full_filename)));
        if ($attachment_id) return array('type' => 'post', 'id' => $attachment_id, 'post_type' => 'attachment');
    }

    // 5. Try Terms (Slug or Name) - if not already done in step 1
    if ($service_type !== 'terms') {
        $where_parts = array();
        foreach ($slug_variants as $variant) { $where_parts[] = $wpdb->prepare("slug = %s", $variant); }
        $term = $wpdb->get_row("SELECT term_id FROM $wpdb->terms WHERE " . implode(" OR ", $where_parts) . " LIMIT 1");
        if ($term) return array('type' => 'term', 'id' => $term->term_id);

        $term_by_name = $wpdb->get_row($wpdb->prepare("SELECT term_id FROM $wpdb->terms WHERE name = %s LIMIT 1", $identifier));
        if ($term_by_name) return array('type' => 'term', 'id' => $term_by_name->term_id);
    }

    return false;
}

add_action('wp_ajax_ycu_create_batch', 'ycu_create_batch_handler');
add_action('wp_ajax_ycu_get_history', 'ycu_get_history_handler');
add_action('wp_ajax_ycu_revert_batch', 'ycu_revert_batch_handler');
add_action('wp_ajax_ycu_verify_categories', 'ycu_verify_categories_handler');

function ycu_verify_categories_handler() {
    check_ajax_referer('ycu_nonce', 'nonce');
    
    $categories = isset($_POST['categories']) ? (array) $_POST['categories'] : array();
    $taxonomy = isset($_POST['taxonomy']) ? sanitize_text_field($_POST['taxonomy']) : 'category';
    
    if (empty($taxonomy)) {
        $taxonomy = 'category';
    }

    $existing_terms = get_terms(array(
        'taxonomy' => $taxonomy,
        'hide_empty' => false,
    ));

    $all_terms = array();
    if (!is_wp_error($existing_terms)) {
        foreach ($existing_terms as $term) {
            $all_terms[] = array('id' => $term->term_id, 'name' => $term->name);
        }
    }

    $matches = array();
    $csv_categories = array();

    foreach ($categories as $cat) {
        $cat = trim($cat);
        if (empty($cat)) continue;
        $csv_categories[] = $cat;

        $term = term_exists($cat, $taxonomy);
        if ($term !== 0 && $term !== null) {
            $matches[$cat] = is_array($term) ? $term['term_id'] : $term;
        } else {
            $matches[$cat] = null;
        }
    }

    wp_send_json_success(array(
        'taxonomy' => $taxonomy,
        'all_terms' => $all_terms,
        'matches' => $matches,
        'csv_categories' => $csv_categories
    ));
}

function ycu_create_batch_handler() {
    check_ajax_referer('ycu_nonce', 'nonce');
    global $wpdb;
    
    $file_name = isset($_POST['file_name']) ? sanitize_text_field($_POST['file_name']) : 'unknown.csv';
    $batch_id = uniqid('batch_');

    $wpdb->insert($wpdb->prefix . 'ycu_batches', array(
        'batch_id' => $batch_id,
        'file_name' => $file_name,
        'created_at' => current_time('mysql')
    ));

    wp_send_json_success(array('batch_id' => $batch_id));
}

function ycu_get_history_handler() {
    check_ajax_referer('ycu_nonce', 'nonce');
    global $wpdb;

    $batches = $wpdb->get_results("SELECT b.*, COUNT(l.id) as actions_count FROM {$wpdb->prefix}ycu_batches b LEFT JOIN {$wpdb->prefix}ycu_logs l ON b.batch_id = l.batch_id GROUP BY b.batch_id ORDER BY b.created_at DESC LIMIT 50");

    wp_send_json_success($batches);
}

function ycu_revert_batch_handler() {
    check_ajax_referer('ycu_nonce', 'nonce');
    global $wpdb;

    $batch_id = isset($_POST['batch_id']) ? sanitize_text_field($_POST['batch_id']) : '';
    if (empty($batch_id)) wp_send_json_error('Lote inválido.');

    $logs = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ycu_logs WHERE batch_id = %s", $batch_id));

    foreach ($logs as $log) {
        $obj_type = $log->obj_type;
        $obj_id = $log->obj_id;
        $field = $log->field_name;
        $old_val = $log->old_val;

        if ($field === 'slug') {
            if ($obj_type === 'post') {
                wp_update_post(array('ID' => $obj_id, 'post_name' => $old_val));
            } elseif ($obj_type === 'term') {
                wp_update_term($obj_id, get_term($obj_id)->taxonomy, array('slug' => $old_val));
            }
        } elseif ($field === 'media_title' && $obj_type === 'post') {
            wp_update_post(array('ID' => $obj_id, 'post_title' => $old_val));
        } elseif ($field === 'post_content' && $obj_type === 'post') {
            wp_update_post(array('ID' => $obj_id, 'post_content' => $old_val));
        } elseif ($field === 'post_excerpt' && $obj_type === 'post') {
            wp_update_post(array('ID' => $obj_id, 'post_excerpt' => $old_val));
        } elseif ($field === 'term_name' && $obj_type === 'term') {
            wp_update_term($obj_id, get_term($obj_id)->taxonomy, array('name' => $old_val));
        } elseif ($field === 'term_description' && $obj_type === 'term') {
            wp_update_term($obj_id, get_term($obj_id)->taxonomy, array('description' => $old_val));
        } elseif (strpos($field, 'category_') === 0) {
            $tax = str_replace('category_', '', $field);
            $term_ids = empty($old_val) ? array() : explode(',', $old_val);
            wp_set_object_terms($obj_id, array_map('intval', $term_ids), $tax, false);
        } else {
            // General meta update
            if ($obj_type === 'post' || $obj_type === 'attachment') {
                update_post_meta($obj_id, $field, $old_val);
            } elseif ($obj_type === 'term') {
                update_term_meta($obj_id, $field, $old_val);
            } elseif ($obj_type === 'home_posts') {
                update_option($field, maybe_unserialize($old_val));
            }
        }
    }

    // Apaga os logs e o batch
    $wpdb->delete($wpdb->prefix . 'ycu_logs', array('batch_id' => $batch_id));
    $wpdb->delete($wpdb->prefix . 'ycu_batches', array('batch_id' => $batch_id));

    wp_send_json_success('Lote revertido e removido do histórico com sucesso.');
}

function ycu_save_log($batch_id, $obj_type, $obj_id, $field_name, $old_val, $new_val) {
    global $wpdb;
    if (empty($batch_id)) return;
    
    // Evita log duplicado para a mesma coluna e lote (pode acontecer em retentativas)
    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}ycu_logs WHERE batch_id = %s AND obj_id = %d AND field_name = %s LIMIT 1", $batch_id, $obj_id, $field_name));
    if ($exists) return;

    if (is_array($old_val) || is_object($old_val)) $old_val = maybe_serialize($old_val);
    
    $wpdb->insert($wpdb->prefix . 'ycu_logs', array(
        'batch_id' => $batch_id,
        'obj_type' => $obj_type,
        'obj_id' => $obj_id,
        'field_name' => $field_name,
        'old_val' => (string)$old_val,
        'new_val' => (string)$new_val
    ));
}

function ycu_process_row_handler() {
    check_ajax_referer('ycu_nonce', 'nonce');

    $row = isset($_POST['row']) ? $_POST['row'] : array();
    $mapping = isset($_POST['mapping']) ? $_POST['mapping'] : array();
    $seo_plugin = isset($_POST['seo_plugin']) ? $_POST['seo_plugin'] : 'both';
    $service_type = isset($_POST['service_type']) ? sanitize_text_field($_POST['service_type']) : '';
    $batch_id = isset($_POST['batch_id']) ? sanitize_text_field($_POST['batch_id']) : '';

    // Find Identifier
    $identifier_val = '';
    foreach ($mapping as $col => $config) {
        if ($config['type'] === 'identifier') {
            $identifier_val = isset($row[$col]) ? $row[$col] : '';
            break;
        }
    }

    if ($identifier_val === '') {
        wp_send_json_error('Identificador vazio na linha.');
    }

    $obj = ycu_find_object($identifier_val, $service_type);

    if (!$obj) {
        wp_send_json_error('Item não encontrado para o identificador: ' . $identifier_val);
    }

    $updated = array();
    $errors = array();

    foreach ($mapping as $col => $config) {
        $type = $config['type'];
        if ($type === 'ignore' || $type === 'identifier') continue;

        $val = isset($row[$col]) ? $row[$col] : '';
        if ($val === '') continue; // Skip empty fields

        if ($type === 'seo_title' || $type === 'seo_desc') {
            $is_title = ($type === 'seo_title');
            $val = $is_title ? sanitize_text_field($val) : sanitize_textarea_field($val);
            
            if ($obj['type'] === 'post') {
                if ($seo_plugin === 'both' || $seo_plugin === 'yoast') {
                    $meta_key = $is_title ? '_yoast_wpseo_title' : '_yoast_wpseo_metadesc';
                    $old = get_post_meta($obj['id'], $meta_key, true);
                    ycu_save_log($batch_id, 'post', $obj['id'], $meta_key, $old, $val);
                    update_post_meta($obj['id'], $meta_key, $val);
                }
                if ($seo_plugin === 'both' || $seo_plugin === 'rankmath') {
                    $meta_key = $is_title ? 'rank_math_title' : 'rank_math_description';
                    $old = get_post_meta($obj['id'], $meta_key, true);
                    ycu_save_log($batch_id, 'post', $obj['id'], $meta_key, $old, $val);
                    update_post_meta($obj['id'], $meta_key, $val);
                }
                $updated[] = $is_title ? 'SEO Title' : 'SEO Desc';
            } elseif ($obj['type'] === 'term') {
                if ($seo_plugin === 'both' || $seo_plugin === 'yoast') {
                    $meta_key = $is_title ? 'wpseo_title' : 'wpseo_desc';
                    $old = get_term_meta($obj['id'], $meta_key, true);
                    ycu_save_log($batch_id, 'term', $obj['id'], $meta_key, $old, $val);
                    update_term_meta($obj['id'], $meta_key, $val);
                }
                if ($seo_plugin === 'both' || $seo_plugin === 'rankmath') {
                    $meta_key = $is_title ? 'rank_math_title' : 'rank_math_description';
                    $old = get_term_meta($obj['id'], $meta_key, true);
                    ycu_save_log($batch_id, 'term', $obj['id'], $meta_key, $old, $val);
                    update_term_meta($obj['id'], $meta_key, $val);
                }
                $updated[] = $is_title ? 'SEO Title (Term)' : 'SEO Desc (Term)';
            } elseif ($obj['type'] === 'home_posts') {
                if ($seo_plugin === 'both' || $seo_plugin === 'yoast') {
                    $yoast_titles = get_option('wpseo_titles', array());
                    $old = $yoast_titles;
                    $yoast_key = $is_title ? 'title-home-wpseo' : 'metadesc-home-wpseo';
                    $yoast_titles[$yoast_key] = $val;
                    ycu_save_log($batch_id, 'home_posts', 0, 'wpseo_titles', $old, $yoast_titles);
                    update_option('wpseo_titles', $yoast_titles);
                }
                if ($seo_plugin === 'both' || $seo_plugin === 'rankmath') {
                    $rm_titles = get_option('rank-math-options-titles', array());
                    $old = $rm_titles;
                    $rm_key = $is_title ? 'homepage_title' : 'homepage_description';
                    $rm_titles[$rm_key] = $val;
                    ycu_save_log($batch_id, 'home_posts', 0, 'rank-math-options-titles', $old, $rm_titles);
                    update_option('rank-math-options-titles', $rm_titles);
                }
                $updated[] = $is_title ? 'Home SEO Title' : 'Home SEO Desc';
            }
        }
        
        elseif ($type === 'slug') {
            $new_slug = sanitize_title($val);
            if ($obj['type'] === 'post') {
                $old = get_post_field('post_name', $obj['id']);
                ycu_save_log($batch_id, 'post', $obj['id'], 'slug', $old, $new_slug);
                wp_update_post(array('ID' => $obj['id'], 'post_name' => $new_slug));
                $updated[] = 'Slug';
            } elseif ($obj['type'] === 'term') {
                $old = get_term($obj['id'])->slug;
                ycu_save_log($batch_id, 'term', $obj['id'], 'slug', $old, $new_slug);
                wp_update_term($obj['id'], get_term($obj['id'])->taxonomy, array('slug' => $new_slug));
                $updated[] = 'Slug';
            }
        }
        
        elseif ($type === 'media_title') {
            if ($obj['type'] === 'post') {
                $old = get_post_field('post_title', $obj['id']);
                ycu_save_log($batch_id, 'post', $obj['id'], 'media_title', $old, $val);
                wp_update_post(array('ID' => $obj['id'], 'post_title' => sanitize_text_field($val)));
                $updated[] = 'Media/Post Title';
            }
        }
        
        elseif ($type === 'post_content') {
            if ($obj['type'] === 'post') {
                $old = get_post_field('post_content', $obj['id']);
                ycu_save_log($batch_id, 'post', $obj['id'], 'post_content', $old, $val);
                wp_update_post(array('ID' => $obj['id'], 'post_content' => wp_kses_post($val)));
                $updated[] = 'Post Content';
            }
        }
        
        elseif ($type === 'post_excerpt') {
            if ($obj['type'] === 'post') {
                $old = get_post_field('post_excerpt', $obj['id']);
                ycu_save_log($batch_id, 'post', $obj['id'], 'post_excerpt', $old, $val);
                wp_update_post(array('ID' => $obj['id'], 'post_excerpt' => wp_kses_post($val)));
                $updated[] = 'Post Excerpt';
            }
        }
        
        elseif ($type === 'term_name') {
            if ($obj['type'] === 'term') {
                $old = get_term($obj['id'])->name;
                ycu_save_log($batch_id, 'term', $obj['id'], 'term_name', $old, $val);
                wp_update_term($obj['id'], get_term($obj['id'])->taxonomy, array('name' => sanitize_text_field($val)));
                $updated[] = 'Nome do Termo';
            }
        }
        
        elseif ($type === 'term_description') {
            if ($obj['type'] === 'term') {
                $old = get_term($obj['id'])->description;
                ycu_save_log($batch_id, 'term', $obj['id'], 'term_description', $old, $val);
                wp_update_term($obj['id'], get_term($obj['id'])->taxonomy, array('description' => wp_kses_post($val)));
                $updated[] = 'Descrição do Termo';
            }
        }
        
        elseif ($type === 'alt_text') {
            if ($obj['type'] === 'post' && $obj['post_type'] === 'attachment') {
                $old = get_post_meta($obj['id'], '_wp_attachment_image_alt', true);
                ycu_save_log($batch_id, 'attachment', $obj['id'], '_wp_attachment_image_alt', $old, $val);
                update_post_meta($obj['id'], '_wp_attachment_image_alt', sanitize_text_field($val));
                $updated[] = 'Alt Text';
            } else {
                $errors[] = "Aviso: Tentou atualizar Alt Text, mas não é um anexo/imagem.";
            }
        }
        
        elseif ($type === 'category') {
            if ($obj['type'] === 'post') {
                $taxonomy = !empty($config['extra_key']) ? sanitize_text_field($config['extra_key']) : 'category';
                $cats = array_map('trim', explode(',', $val));
                $cat_ids = array();
                foreach ($cats as $cat_name) {
                    if (empty($cat_name)) continue;
                    $mapped_val = isset($term_mapping[$cat_name]) ? $term_mapping[$cat_name] : 'CREATE:' . $cat_name;
                    
                    if (strpos($mapped_val, 'ID:') === 0) {
                        $cat_ids[] = intval(substr($mapped_val, 3));
                    } else {
                        $new_name = strpos($mapped_val, 'CREATE:') === 0 ? substr($mapped_val, 7) : $mapped_val;
                        $term = term_exists($new_name, $taxonomy);
                        if ($term !== 0 && $term !== null) {
                            $cat_ids[] = intval(is_array($term) ? $term['term_id'] : $term);
                        } else {
                            // Create it if it doesn't exist
                            $new_term = wp_insert_term($new_name, $taxonomy);
                            if (!is_wp_error($new_term)) {
                                $cat_ids[] = intval($new_term['term_id']);
                            } else {
                                $errors[] = "Erro ao criar termo '$new_name' na taxonomia '$taxonomy'";
                            }
                        }
                    }
                }
                if (!empty($cat_ids)) {
                    $old_terms = wp_get_object_terms($obj['id'], $taxonomy, array('fields' => 'ids'));
                    $old_val = is_wp_error($old_terms) ? '' : implode(',', $old_terms);
                    ycu_save_log($batch_id, 'post', $obj['id'], 'category_' . $taxonomy, $old_val, implode(',', $cat_ids));
                    
                    wp_set_object_terms($obj['id'], $cat_ids, $taxonomy, false); // false = overwrite
                    $updated[] = 'Taxonomia (' . $taxonomy . ')';
                }
            } else {
                $errors[] = "Aviso: Taxonomias só podem ser atribuídas a Posts/CPTs.";
            }
        }

        elseif ($type === 'acf') {
            $acf_key = isset($config['extra_key']) ? $config['extra_key'] : '';
            if ($acf_key) {
                $post_id_to_update = $obj['id'];
                if ($obj['type'] === 'term') {
                    $term_obj = get_term($obj['id']);
                    $post_id_to_update = $term_obj->taxonomy . '_' . $obj['id'];
                }
                
                if (function_exists('update_field')) {
                    $old = get_field($acf_key, $post_id_to_update);
                    ycu_save_log($batch_id, $obj['type'], $obj['id'], $acf_key, $old, $val);
                    update_field($acf_key, $val, $post_id_to_update);
                    $updated[] = 'ACF (' . $acf_key . ')';
                } else {
                    if ($obj['type'] === 'post') {
                        $old = get_post_meta($obj['id'], $acf_key, true);
                        ycu_save_log($batch_id, 'post', $obj['id'], $acf_key, $old, $val);
                        update_post_meta($obj['id'], $acf_key, $val);
                    } elseif ($obj['type'] === 'term') {
                        $old = get_term_meta($obj['id'], $acf_key, true);
                        ycu_save_log($batch_id, 'term', $obj['id'], $acf_key, $old, $val);
                        update_term_meta($obj['id'], $acf_key, $val);
                    }
                    $updated[] = 'Meta (' . $acf_key . ')';
                }
            }
        }
    }

    $msg = 'Sucesso [' . ucfirst($obj['type']) . ' ID: ' . $obj['id'] . ']. Atualizado: ' . (empty($updated) ? 'Nenhum' : implode(', ', $updated));
    if (!empty($errors)) {
        $msg .= ' | ' . implode(' | ', $errors);
    }

    wp_send_json_success($msg);
}

function ycu_export_csv_handler() {
    check_ajax_referer('ycu_nonce', 'nonce');
    
    $upload_dir = wp_upload_dir();
    $file_name = 'seo_meta_export_' . time() . '.csv';
    $file_path = $upload_dir['path'] . '/' . $file_name;
    $file_url = $upload_dir['url'] . '/' . $file_name;

    $fp = fopen($file_path, 'w');
    fputcsv($fp, array('ID', 'Type', 'Slug', 'Title/Name', 'Description/Content', 'Excerpt', 'Yoast SEO Title', 'Yoast SEO Desc', 'Rank Math Title', 'Rank Math Desc', 'Alt Text'));

    // Export Posts/Pages/Attachments
    $args = array(
        'post_type'      => 'any',
        'post_status'    => 'publish,inherit',
        'posts_per_page' => -1,
        'fields'         => 'ids'
    );
    
    $posts = get_posts($args);
    foreach ($posts as $post_id) {
        $type = get_post_type($post_id);
        $slug = get_post_field('post_name', $post_id);
        $title = get_post_field('post_title', $post_id);
        $content = get_post_field('post_content', $post_id);
        $excerpt = get_post_field('post_excerpt', $post_id);
        
        $y_title = get_post_meta($post_id, '_yoast_wpseo_title', true);
        $y_desc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
        $rm_title = get_post_meta($post_id, 'rank_math_title', true);
        $rm_desc = get_post_meta($post_id, 'rank_math_description', true);
        
        $alt = ($type === 'attachment') ? get_post_meta($post_id, '_wp_attachment_image_alt', true) : '';

        fputcsv($fp, array($post_id, 'post/' . $type, $slug, $title, $content, $excerpt, $y_title, $y_desc, $rm_title, $rm_desc, $alt));
    }

    // Export Terms
    $terms = get_terms(array('hide_empty' => false));
    if (!is_wp_error($terms)) {
        foreach ($terms as $term) {
            $y_title = get_term_meta($term->term_id, 'wpseo_title', true);
            $y_desc = get_term_meta($term->term_id, 'wpseo_desc', true);
            $rm_title = get_term_meta($term->term_id, 'rank_math_title', true);
            $rm_desc = get_term_meta($term->term_id, 'rank_math_description', true);
            
            fputcsv($fp, array($term->term_id, 'term/' . $term->taxonomy, $term->slug, $term->name, $term->description, '', $y_title, $y_desc, $rm_title, $rm_desc, ''));
        }
    }
    
    fclose($fp);
    
    wp_send_json_success(array('url' => $file_url));
}