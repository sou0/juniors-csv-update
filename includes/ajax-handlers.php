<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action('wp_ajax_ycu_upload_csv', 'ycu_upload_csv_handler');
add_action('wp_ajax_ycu_process_row', 'ycu_process_row_handler');
add_action('wp_ajax_ycu_export_csv', 'ycu_export_csv_handler');
add_action('wp_ajax_ycu_bulk_replace', 'ycu_bulk_replace_handler');

function ycu_build_search_regex($search, $ignore_case, $ignore_accents, $partial_match) {
    if ($ignore_accents) {
        $search = remove_accents($search);
        $search = preg_quote($search, '/');
        
        if ($ignore_case) {
            $map = array(
                'a' => '[aáàãâäAÁÀÃÂÄ]', 'A' => '[aáàãâäAÁÀÃÂÄ]',
                'e' => '[eéèêëEÉÈÊË]', 'E' => '[eéèêëEÉÈÊË]',
                'i' => '[iíìîïIÍÌÎÏ]', 'I' => '[iíìîïIÍÌÎÏ]',
                'o' => '[oóòõôöOÓÒÕÔÖ]', 'O' => '[oóòõôöOÓÒÕÔÖ]',
                'u' => '[uúùûüUÚÙÛÜ]', 'U' => '[uúùûüUÚÙÛÜ]',
                'c' => '[cçCÇ]', 'C' => '[cçCÇ]'
            );
        } else {
            $map = array(
                'a' => '[aáàãâä]', 'A' => '[AÁÀÃÂÄ]',
                'e' => '[eéèêë]', 'E' => '[EÉÈÊË]',
                'i' => '[iíìîï]', 'I' => '[IÍÌÎÏ]',
                'o' => '[oóòõôö]', 'O' => '[OÓÒÕÔÖ]',
                'u' => '[uúùûü]', 'U' => '[UÚÙÛÜ]',
                'c' => '[cç]', 'C' => '[CÇ]'
            );
        }
        $search = strtr($search, $map);
    } else {
        $search = preg_quote($search, '/');
    }

    $modifiers = 'u';
    if ($ignore_case) {
        $modifiers .= 'i';
    }

    if (!$partial_match) {
        $search = '(?<![\pL\pN_])' . $search . '(?![\pL\pN_])';
    }

    return '/' . $search . '/' . $modifiers;
}

function ycu_clear_elementor_cache($post_id) {
    if (empty($post_id)) return;
    
    // Clear post-specific CSS cache meta (Fast)
    delete_post_meta($post_id, '_elementor_css');
    
    // Attempt to use Elementor's native cache clearing
    if (class_exists('\Elementor\Plugin')) {
        // Clear post CSS (Relatively Fast)
        if (isset(\Elementor\Plugin::$instance->posts_css_manager)) {
            \Elementor\Plugin::$instance->posts_css_manager->clear_cache();
        }

        // Trigger 'Elementor saved' which usually flushes cache (Moderate)
        do_action('elementor/editor/after_save', $post_id, null);
    }

    // Flush common external cache plugins if active (Targeted)
    if (function_exists('rocket_clean_post')) rocket_clean_post($post_id); // WP Rocket
    if (function_exists('w3tc_pgcache_flush_post')) w3tc_pgcache_flush_post($post_id); // W3 Total Cache
    
    // Avoid global flushes in loops if possible, but keeping them safe
    // wp_cache_flush() and AutoptimizeCache::clearall() are very heavy, removed from loop.
}

function ycu_safe_html_replace($replacements, $subject) {
    if (!is_string($subject) || empty($subject)) return $subject;
    $parts = preg_split('/(<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>)/', $subject, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) return $subject;
    
    $result = '';
    foreach ($parts as $index => $part) {
        if ($index % 2 === 0) { 
            foreach ($replacements as $rep) {
                if (isset($rep['regex'])) {
                    $part = preg_replace($rep['regex'], $rep['new'], $part);
                } else {
                    $part = str_ireplace($rep['old'], $rep['new'], $part); // fallback
                }
            }
        }
        $result .= $part;
    }
    return $result;
}

function ycu_bulk_replace_handler() {
    global $wpdb;
    check_ajax_referer('ycu_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Sem permissão.');

    $replacements = isset($_POST['replacements']) ? $_POST['replacements'] : array();
    $targets = isset($_POST['targets']) ? $_POST['targets'] : array();
    $options = isset($_POST['options']) ? $_POST['options'] : array();
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $limit = 20; // Reduced limit for better memory handling

    if (empty($replacements)) wp_send_json_error('Nenhuma substituição definida.');

    $ignore_case = !empty($options['ignore_case']);
    $ignore_accents = !empty($options['ignore_accents']);
    $partial_match = !empty($options['partial_match']);

    // Pre-calculate regex for each replacement
    foreach ($replacements as &$rep) {
        $rep['regex'] = ycu_build_search_regex($rep['old'], $ignore_case, $ignore_accents, $partial_match);
    }
    unset($rep);

    $args = array(
        'post_type' => 'any',
        'post_status' => array('publish', 'inherit', 'private', 'draft'),
        'posts_per_page' => $limit,
        'offset' => $offset,
        'orderby' => 'ID',
        'order' => 'ASC'
    );
    
    $query = new WP_Query($args);
    $posts = $query->posts;
    $processed = count($posts);
    $updated_count = 0;
    
    $total_posts = $query->found_posts;
    
    // Total including terms
    $total_terms = intval($wpdb->get_var("SELECT COUNT(*) FROM $wpdb->term_taxonomy"));
    $grand_total = $total_posts + $total_terms;

    // Use a temporary batch_id for bulk operations
    $batch_id = '';
    if ($offset === 0) {
        $batch_id = 'bulk_' . date('Ymd_His');
        $wpdb->insert($wpdb->prefix . 'ycu_batches', array(
            'batch_id' => $batch_id,
            'file_name' => 'Bulk Replace (Global)',
            'created_at' => current_time('mysql')
        ));
    } else {
        $found_batch = $wpdb->get_var("SELECT batch_id FROM {$wpdb->prefix}ycu_batches WHERE file_name = 'Bulk Replace (Global)' ORDER BY created_at DESC LIMIT 1");
        $batch_id = $found_batch ? $found_batch : 'bulk_temp';
    }

    // Process Posts
    foreach ($posts as $post) {
        $post_id = $post->ID;
        $content_changed = false;
        $meta_changed = false;
        
        $old_content = $post->post_content;
        $new_content = $old_content;
        $old_excerpt = $post->post_excerpt;
        $new_excerpt = $old_excerpt;
        $old_title = $post->post_title;
        $new_title = $old_title;

        $old_slug = $post->post_name;
        $new_slug = $old_slug;

        for ($i = 1; $i <= 6; $i++) {
            $tag = 'h' . $i;
            if (in_array($tag, $targets)) {
                $new_content = preg_replace_callback('/(<' . $tag . '[^>]*>)(.*?)(<\/' . $tag . '>)/i', function($m) use ($replacements) {
                    return $m[1] . ycu_safe_html_replace($replacements, $m[2]) . $m[3];
                }, $new_content);
            }
        }
        if (in_array('p', $targets)) {
            $new_content = preg_replace_callback('/(<p[^>]*>)(.*?)(<\/p>)/i', function($m) use ($replacements) {
                return $m[1] . ycu_safe_html_replace($replacements, $m[2]) . $m[3];
            }, $new_content);
        }
        if (in_array('post_title', $targets)) {
            $new_title = ycu_safe_html_replace($replacements, $new_title);
        }
        if (in_array('excerpt', $targets)) {
            $new_excerpt = ycu_safe_html_replace($replacements, $new_excerpt);
        }
        if (in_array('slug', $targets)) {
            foreach ($replacements as $rep) {
                // For slugs, we usually just want simple replacement, but let's respect the regex if provided.
                if (isset($rep['regex'])) {
                    $new_slug = preg_replace($rep['regex'], $rep['new'], $new_slug);
                } else {
                    $new_slug = str_ireplace($rep['old'], $rep['new'], $new_slug);
                }
            }
        }

        if ($new_content !== $old_content || $new_excerpt !== $old_excerpt || $new_slug !== $old_slug || $new_title !== $old_title) {
            if ($new_content !== $old_content) ycu_save_log($batch_id, 'post', $post_id, 'post_content', $old_content, $new_content);
            if ($new_excerpt !== $old_excerpt) ycu_save_log($batch_id, 'post', $post_id, 'post_excerpt', $old_excerpt, $new_excerpt);
            if ($new_slug !== $old_slug) ycu_save_log($batch_id, 'post', $post_id, 'slug', $old_slug, $new_slug);
            if ($new_title !== $old_title) ycu_save_log($batch_id, 'post', $post_id, 'media_title', $old_title, $new_title); // media_title key used for titles
            
            wp_update_post(array(
                'ID' => $post_id,
                'post_content' => $new_content,
                'post_excerpt' => $new_excerpt,
                'post_name' => $new_slug,
                'post_title' => $new_title
            ));
            $updated_count++;
            $content_changed = true;
        }

        // Media Alt Text Support
        if (in_array('media_alt', $targets) && $post->post_type === 'attachment') {
            $old_alt = get_post_meta($post_id, '_wp_attachment_image_alt', true);
            $new_alt = ycu_safe_html_replace($replacements, $old_alt);
            if ($new_alt !== $old_alt) {
                ycu_save_log($batch_id, 'attachment', $post_id, '_wp_attachment_image_alt', $old_alt, $new_alt);
                update_post_meta($post_id, '_wp_attachment_image_alt', $new_alt);
                if (!$content_changed) $updated_count++;
                $meta_changed = true;
            }
        }

        // Elementor Support
        $heading_targets = array_intersect(array('h1', 'h2', 'h3', 'h4', 'h5', 'h6'), $targets);
        if (!empty($heading_targets) || in_array('p', $targets)) {
            $elementor_data = get_post_meta($post_id, '_elementor_data', true);
            if (!empty($elementor_data)) {
                $data = json_decode($elementor_data, true);
                if (is_array($data)) {
                    $el_updated = false;
                    $traverse = function(&$elements) use (&$traverse, &$el_updated, $replacements, $targets) {
                        if (!is_array($elements)) return;
                        foreach ($elements as &$el) {
                            if (isset($el['elType']) && $el['elType'] === 'widget') {
                                $is_standard = false;
                                if ($el['widgetType'] === 'heading') {
                                    $size = isset($el['settings']['header_size']) ? strtolower($el['settings']['header_size']) : 'h2';
                                    if (empty($size)) $size = 'h2';
                                    if (in_array($size, $targets)) {
                                        if (isset($el['settings']['title'])) {
                                            $old_t = $el['settings']['title'];
                                            $el['settings']['title'] = ycu_safe_html_replace($replacements, $el['settings']['title']);
                                            if ($el['settings']['title'] !== $old_t) $el_updated = true;
                                            $is_standard = true;
                                        }
                                    }
                                } elseif ($el['widgetType'] === 'text-editor' && (!empty(array_intersect(array('h1', 'h2', 'h3', 'h4', 'h5', 'h6'), $targets)) || in_array('p', $targets))) {
                                    if (isset($el['settings']['editor'])) {
                                        $old_e = $el['settings']['editor'];
                                        $el['settings']['editor'] = ycu_safe_html_replace($replacements, $el['settings']['editor']);
                                        if ($el['settings']['editor'] !== $old_e) $el_updated = true;
                                        $is_standard = true;
                                    }
                                }

                                // Fallback for Custom Widgets (like premium-dual-header)
                                if (!$is_standard && isset($el['settings']) && is_array($el['settings'])) {
                                    $replace_recursive = function(&$array) use (&$replace_recursive, &$el_updated, $replacements) {
                                        foreach ($array as $key => &$value) {
                                            if (is_string($value)) {
                                                $old_v = $value;
                                                $value = ycu_safe_html_replace($replacements, $value);
                                                if ($value !== $old_v) $el_updated = true;
                                            } elseif (is_array($value)) {
                                                $replace_recursive($value);
                                            }
                                        }
                                    };
                                    $replace_recursive($el['settings']);
                                }
                            }
                            if (isset($el['elements'])) $traverse($el['elements']);
                        }
                    };
                    $traverse($data);
                    if ($el_updated) {
                        $new_json = json_encode($data);
                        ycu_save_log($batch_id, 'post', $post_id, '_elementor_data', $elementor_data, $new_json);
                        update_post_meta($post_id, '_elementor_data', wp_slash($new_json));

                        // Clear Elementor and External Caches
                        ycu_clear_elementor_cache($post_id);

                        if (!$content_changed) $updated_count++;
                        $content_changed = true;
                    }
                }
            }
        }

        // ACF Support
        if (in_array('acf', $targets)) {
            $all_meta = get_post_meta($post_id);
            foreach ($all_meta as $key => $values) {
                if (strpos($key, '_') === 0 && $key !== '_elementor_data') {
                     if (strpos($key, '_edit_') === 0 || strpos($key, '_wp_') === 0) continue;
                }
                $old_val = $values[0]; 
                $new_val = ycu_safe_html_replace($replacements, $old_val);
                if ($new_val !== $old_val) {
                    ycu_save_log($batch_id, 'post', $post_id, $key, $old_val, $new_val);
                    update_post_meta($post_id, $key, $new_val);
                    if (!$content_changed && !$meta_changed) $updated_count++;
                    $meta_changed = true;
                }
            }
        }

        // SEO Support
        if (in_array('seo_title', $targets) || in_array('seo_desc', $targets)) {
            $seo_keys = array();
            if (in_array('seo_title', $targets)) $seo_keys = array_merge($seo_keys, array('_yoast_wpseo_title', 'rank_math_title'));
            if (in_array('seo_desc', $targets)) $seo_keys = array_merge($seo_keys, array('_yoast_wpseo_metadesc', 'rank_math_description'));

            foreach ($seo_keys as $key) {
                $old_val = get_post_meta($post_id, $key, true);
                if (!$old_val) continue;
                $new_val = ycu_safe_html_replace($replacements, $old_val);
                if ($new_val !== $old_val) {
                    ycu_save_log($batch_id, 'post', $post_id, $key, $old_val, $new_val);
                    update_post_meta($post_id, $key, $new_val);
                    if (!$content_changed && !$meta_changed) $updated_count++;
                    $meta_changed = true;
                }
            }
        }
    }

    // Process Terms
    foreach ($terms_to_process as $term) {
        $term_id = $term->term_id;
        $changed = false;
        
        // Term Name
        if (in_array('post_title', $targets)) {
            $old_name = $term->name; 
            $new_name = ycu_safe_html_replace($replacements, $old_name);
            if ($new_name !== $old_name) {
                ycu_save_log($batch_id, 'term', $term_id, 'term_name', $old_name, $new_name);
                wp_update_term($term_id, $term->taxonomy, array('name' => $new_name));
                $changed = true;
            }
        }

        // Excerpt (Description)
        if (in_array('excerpt', $targets)) {
            $old_desc = $term->description; 
            $new_desc = ycu_safe_html_replace($replacements, $old_desc);
            if ($new_desc !== $old_desc) {
                ycu_save_log($batch_id, 'term', $term_id, 'term_description', $old_desc, $new_desc);
                wp_update_term($term_id, $term->taxonomy, array('description' => $new_desc));
                $changed = true;
            }
        }
        
        // Slug
        if (in_array('slug', $targets)) {
            $old_s = $term->slug; $new_s = $old_s;
            foreach ($replacements as $rep) { 
                if (isset($rep['regex'])) {
                    $new_s = preg_replace($rep['regex'], $rep['new'], $new_s);
                } else {
                    $new_s = str_ireplace($rep['old'], $rep['new'], $new_s); 
                }
            }
            if ($new_s !== $old_s) {
                ycu_save_log($batch_id, 'term', $term_id, 'slug', $old_s, $new_s);
                wp_update_term($term_id, $term->taxonomy, array('slug' => $new_s));
                $changed = true;
            }
        }
        
        // ACF Term
        if (in_array('acf', $targets)) {
            $all_meta = get_term_meta($term_id);
            foreach ($all_meta as $key => $values) {
                if (strpos($key, '_') === 0) continue;
                $old_v = $values[0]; 
                $new_v = ycu_safe_html_replace($replacements, $old_v);
                if ($new_v !== $old_v) {
                    ycu_save_log($batch_id, 'term', $term_id, $key, $old_v, $new_v);
                    update_term_meta($term_id, $key, $new_v);
                    $changed = true;
                }
            }
        }

        // SEO Term
        if (in_array('seo_title', $targets) || in_array('seo_desc', $targets)) {
            $seo_keys = array();
            if (in_array('seo_title', $targets)) $seo_keys = array_merge($seo_keys, array('wpseo_title', 'rank_math_title'));
            if (in_array('seo_desc', $targets)) $seo_keys = array_merge($seo_keys, array('wpseo_desc', 'rank_math_description'));

            foreach ($seo_keys as $key) {
                $old_v = get_term_meta($term_id, $key, true);
                if (!$old_v) continue;
                $new_v = ycu_safe_html_replace($replacements, $old_v);
                if ($new_v !== $old_v) {
                    ycu_save_log($batch_id, 'term', $term_id, $key, $old_v, $new_v);
                    update_term_meta($term_id, $key, $new_v);
                    $changed = true;
                }
            }
        }
        
        if ($changed) $updated_count++;
    }

    $new_offset = $offset + $limit;
    wp_send_json_success(array(
        'processed' => $processed,
        'updated' => $updated_count,
        'offset' => $new_offset,
        'total' => $grand_total
    ));
}

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
        
        $headers = array_map('trim', $headers_raw);
        
        $counts = array();
        foreach ($headers as $k => $h) {
            if (empty($h)) $h = 'Coluna_' . ($k + 1);
            if (!isset($counts[$h])) {
                $counts[$h] = 1;
                $headers[$k] = $h;
            } else {
                $counts[$h]++;
                $headers[$k] = $h . '_' . $counts[$h];
            }
        }
        
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

    // 1. Identificação por ID Numérico (Respeitando estritamente o serviço)
    if (is_numeric($identifier)) {
        $id = intval($identifier);
        if ($service_type === 'terms') {
            $term = get_term($id);
            if ($term && !is_wp_error($term)) return array('type' => 'term', 'id' => $term->term_id, 'taxonomy' => $term->taxonomy);
        } else {
            $post = get_post($id);
            if ($post) return array('type' => 'post', 'id' => $post->ID, 'post_type' => $post->post_type);
        }
        return false; // Se for número e não encontrou no serviço solicitado, para aqui.
    }

    // 2. Preparação de URL e Caminho
    $home_url = rtrim(home_url(), '/');
    $is_url = filter_var($identifier, FILTER_VALIDATE_URL) || strpos($identifier, 'http') === 0;
    
    // Check Home Page
    if ($identifier === '/' || ($is_url && rtrim($identifier, '/') === $home_url)) {
        if (get_option('show_on_front') == 'page') {
            $page_on_front = get_option('page_on_front');
            if ($page_on_front) return array('type' => 'post', 'id' => $page_on_front, 'post_type' => 'page');
        } else {
            return array('type' => 'home_posts', 'id' => 0);
        }
    }

    // 3. Busca por URL (Apenas para Posts/Mídias)
    if ($is_url && $service_type !== 'terms') {
        $post_id = url_to_postid($identifier);
        if (!$post_id && function_exists('attachment_url_to_postid')) {
            $post_id = attachment_url_to_postid($identifier);
        }
        if ($post_id) return array('type' => 'post', 'id' => $post_id, 'post_type' => get_post_type($post_id));
    }

    // 4. Variantes de Busca (Slug, Nome, etc)
    $path = $identifier;
    if ($is_url) {
        $parsed_url = parse_url($identifier);
        $path = isset($parsed_url['path']) ? trim($parsed_url['path'], '/') : '';
    }
    $path = trim($path, '/');
    $full_filename = basename($path);
    $slug_only = pathinfo($full_filename, PATHINFO_FILENAME);

    $search_variants = array_unique(array(
        $identifier, 
        $path, 
        $slug_only, 
        $full_filename,
        sanitize_title($identifier), 
        sanitize_title($slug_only)
    ));

    // 5. SERVIÇO DE CATEGORIAS/TERMOS - Busca Robusta
    if ($service_type === 'terms') {
        foreach ($search_variants as $variant) {
            if (empty($variant)) continue;
            
            // Busca Direta no Banco (Mais confiável para multi-taxonomia)
            $term_id = $wpdb->get_var($wpdb->prepare(
                "SELECT t.term_id FROM $wpdb->terms t 
                 INNER JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id 
                 WHERE t.slug = %s OR t.name = %s LIMIT 1", 
                $variant, $variant
            ));

            if ($term_id) {
                $term = get_term($term_id);
                if ($term && !is_wp_error($term)) {
                    return array('type' => 'term', 'id' => $term->term_id, 'taxonomy' => $term->taxonomy);
                }
            }
        }
        return false; // Se o serviço é termos e não achou nada, falha.
    }

    // 6. SERVIÇOS DE POSTS/CONTEÚDO/MÍDIA
    foreach ($search_variants as $variant) {
        if (empty($variant)) continue;
        
        // Busca por post_name (slug)
        $post = $wpdb->get_row($wpdb->prepare(
            "SELECT ID, post_type FROM $wpdb->posts WHERE post_name = %s AND post_status IN ('publish', 'draft', 'pending', 'private', 'inherit') LIMIT 1", 
            $variant
        ));
        if ($post) return array('type' => 'post', 'id' => $post->ID, 'post_type' => $post->post_type);
        
        // Busca por post_title
        $post = $wpdb->get_row($wpdb->prepare(
            "SELECT ID, post_type FROM $wpdb->posts WHERE post_title = %s AND post_status IN ('publish', 'draft', 'pending', 'private', 'inherit') LIMIT 1", 
            $variant
        ));
        if ($post) return array('type' => 'post', 'id' => $post->ID, 'post_type' => $post->post_type);
    }

    // 7. Busca por Arquivo de Mídia (meta _wp_attached_file)
    if (preg_match('/\.(jpg|jpeg|png|gif|webp|pdf|svg)$/i', $full_filename)) {
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s LIMIT 1", 
            '%' . $wpdb->esc_like($full_filename)
        ));
        if ($attachment_id) return array('type' => 'post', 'id' => $attachment_id, 'post_type' => 'attachment');
    }

    return false;
}

add_action('wp_ajax_ycu_create_batch', 'ycu_create_batch_handler');
add_action('wp_ajax_ycu_get_history', 'ycu_get_history_handler');
add_action('wp_ajax_ycu_revert_batch', 'ycu_revert_batch_handler');
add_action('wp_ajax_ycu_verify_categories', 'ycu_verify_categories_handler');
add_action('wp_ajax_ycu_validate_authors', 'ycu_validate_authors_handler');
add_action('wp_ajax_ycu_finish_batch', 'ycu_finish_batch_handler');

function ycu_finish_batch_handler() {
    check_ajax_referer('ycu_nonce', 'nonce');
    // Clear global Elementor cache (CSS files)
    if (class_exists('\Elementor\Plugin')) {
        if (class_exists('\Elementor\Core\Files\Manager')) {
            $manager = \Elementor\Plugin::$instance->files_manager;
            if ($manager) $manager->clear_cache();
        }
    }
    // Flush common external cache plugins
    if (function_exists('wp_cache_flush')) wp_cache_flush();
    if (class_exists('AutoptimizeCache')) AutoptimizeCache::clearall();
    wp_send_json_success('Cache global limpo.');
}

function ycu_validate_authors_handler() {
    check_ajax_referer('ycu_nonce', 'nonce');
    $csv_authors = isset($_POST['authors']) ? (array) $_POST['authors'] : array();
    
    // Get all users who can author posts (or simply all users for flexibility)
    $users = get_users();
    $all_authors = array();
    foreach ($users as $u) {
        $all_authors[] = array(
            'id' => $u->ID,
            'name' => $u->display_name,
            'email' => $u->user_email
        );
    }
    
    $matches = array();
    foreach ($csv_authors as $auth_str) {
        $auth_str = trim($auth_str);
        if (empty($auth_str)) continue;
        
        $matched_id = '';
        foreach ($users as $u) {
            if (strcasecmp($u->display_name, $auth_str) === 0 || strcasecmp($u->user_login, $auth_str) === 0 || strcasecmp($u->user_email, $auth_str) === 0) {
                $matched_id = $u->ID;
                break;
            }
        }
        if ($matched_id !== '') {
            $matches[$auth_str] = $matched_id;
        }
    }
    
    wp_send_json_success(array(
        'csv_authors' => $csv_authors,
        'all_authors' => $all_authors,
        'matches' => $matches
    ));
}

function ycu_verify_categories_handler() {
    check_ajax_referer('ycu_nonce', 'nonce');
    $categories = isset($_POST['categories']) ? (array) $_POST['categories'] : array();
    $taxonomy = isset($_POST['taxonomy']) ? sanitize_text_field($_POST['taxonomy']) : 'category';
    if (empty($taxonomy)) $taxonomy = 'category';
    $existing_terms = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false));
    $all_terms = array();
    if (!is_wp_error($existing_terms)) { foreach ($existing_terms as $term) { $all_terms[] = array('id' => $term->term_id, 'name' => $term->name); } }
    $matches = array(); $csv_categories = array();
    foreach ($categories as $cat) {
        $cat = trim($cat); if (empty($cat)) continue;
        $csv_categories[] = $cat;
        $term = term_exists($cat, $taxonomy);
        $matches[$cat] = ($term !== 0 && $term !== null) ? (is_array($term) ? $term['term_id'] : $term) : null;
    }
    wp_send_json_success(array('taxonomy' => $taxonomy, 'all_terms' => $all_terms, 'matches' => $matches, 'csv_categories' => $csv_categories));
}

function ycu_create_batch_handler() {
    check_ajax_referer('ycu_nonce', 'nonce');
    global $wpdb;
    $file_name = isset($_POST['file_name']) ? sanitize_text_field($_POST['file_name']) : 'unknown.csv';
    $batch_id = uniqid('batch_');
    $wpdb->insert($wpdb->prefix . 'ycu_batches', array('batch_id' => $batch_id, 'file_name' => $file_name, 'created_at' => current_time('mysql')));
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

    $limit = 100; // Processa 100 itens por vez
    $logs = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ycu_logs WHERE batch_id = %s LIMIT %d", $batch_id, $limit));
    
    if (empty($logs)) {
        $wpdb->delete($wpdb->prefix . 'ycu_batches', array('batch_id' => $batch_id));
        wp_send_json_success(array('done' => true));
    }

    $processed_ids = array();
    foreach ($logs as $log) {
        $obj_type = $log->obj_type; $obj_id = $log->obj_id; $field = $log->field_name; $old_val = $log->old_val;
        if ($field === 'slug') {
            if ($obj_type === 'post') wp_update_post(array('ID' => $obj_id, 'post_name' => $old_val));
            elseif ($obj_type === 'term') wp_update_term($obj_id, get_term($obj_id)->taxonomy, array('slug' => $old_val));
        } elseif ($field === 'media_title' && $obj_type === 'post') wp_update_post(array('ID' => $obj_id, 'post_title' => $old_val));
        elseif ($field === 'post_content' && $obj_type === 'post') wp_update_post(array('ID' => $obj_id, 'post_content' => $old_val));
        elseif ($field === 'post_excerpt' && $obj_type === 'post') wp_update_post(array('ID' => $obj_id, 'post_excerpt' => $old_val));
        elseif ($field === 'media_caption' && $obj_type === 'post') wp_update_post(array('ID' => $obj_id, 'post_excerpt' => $old_val));
        elseif ($field === 'media_description' && $obj_type === 'post') wp_update_post(array('ID' => $obj_id, 'post_content' => $old_val));
        elseif ($field === 'term_name' && $obj_type === 'term') wp_update_term($obj_id, get_term($obj_id)->taxonomy, array('name' => $old_val));
        elseif ($field === 'term_description' && $obj_type === 'term') wp_update_term($obj_id, get_term($obj_id)->taxonomy, array('description' => $old_val));
        elseif (strpos($field, 'category_') === 0) {
            $tax = str_replace('category_', '', $field);
            $term_ids = empty($old_val) ? array() : explode(',', $old_val);
            wp_set_object_terms($obj_id, array_map('intval', $term_ids), $tax, false);
        } else {
            if ($obj_type === 'post' || $obj_type === 'attachment') update_post_meta($obj_id, $field, wp_slash($old_val));
            elseif ($obj_type === 'term') update_term_meta($obj_id, $field, wp_slash($old_val));
            elseif ($obj_type === 'home_posts') update_option($field, maybe_unserialize($old_val));
        }

        // Clear Elementor and External Caches if the field was elementor data or post content
        if ($obj_type === 'post' && ($field === '_elementor_data' || $field === 'post_content')) {
            ycu_clear_elementor_cache($obj_id);
        }

        $processed_ids[] = $log->id;
    }

    if (!empty($processed_ids)) {
        $wpdb->query("DELETE FROM {$wpdb->prefix}ycu_logs WHERE id IN (" . implode(',', $processed_ids) . ")");
    }

    $remaining = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}ycu_logs WHERE batch_id = %s", $batch_id));

    // Global flushes and delete batch only when completely finished
    if ($remaining == 0) {
        $wpdb->delete($wpdb->prefix . 'ycu_batches', array('batch_id' => $batch_id));
        if (function_exists('wp_cache_flush')) wp_cache_flush();
        if (class_exists('AutoptimizeCache')) AutoptimizeCache::clearall();
    }

    wp_send_json_success(array(
        'done' => $remaining == 0 ? true : false,
        'remaining' => (int)$remaining
    ));
}

function ycu_save_log($batch_id, $obj_type, $obj_id, $field_name, $old_val, $new_val) {
    global $wpdb; if (empty($batch_id)) return;
    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}ycu_logs WHERE batch_id = %s AND obj_id = %d AND field_name = %s LIMIT 1", $batch_id, $obj_id, $field_name));
    if ($exists) return;
    if (is_array($old_val) || is_object($old_val)) $old_val = maybe_serialize($old_val);
    $wpdb->insert($wpdb->prefix . 'ycu_logs', array('batch_id' => $batch_id, 'obj_type' => $obj_type, 'obj_id' => $obj_id, 'field_name' => $field_name, 'old_val' => (string)$old_val, 'new_val' => (string)$new_val));
}

function ycu_process_row_handler() {
    check_ajax_referer('ycu_nonce', 'nonce');
    $row = isset($_POST['row']) ? $_POST['row'] : array();
    $mapping = isset($_POST['mapping']) ? $_POST['mapping'] : array();
    $seo_plugin = isset($_POST['seo_plugin']) ? $_POST['seo_plugin'] : 'both';
    $service_type = isset($_POST['service_type']) ? sanitize_text_field($_POST['service_type']) : '';
    $batch_id = isset($_POST['batch_id']) ? sanitize_text_field($_POST['batch_id']) : '';
    $term_mapping = isset($_POST['term_mapping']) ? (array) $_POST['term_mapping'] : array();
    $identifier_val = '';
    foreach ($mapping as $col => $config) { if ($config['type'] === 'identifier') { $identifier_val = isset($row[$col]) ? $row[$col] : ''; break; } }
    if ($identifier_val === '') wp_send_json_error('Identificador vazio na linha.');
    $obj = ycu_find_object($identifier_val, $service_type);
    if (!$obj) wp_send_json_error('Item não encontrado para o identificador: ' . $identifier_val);
    $updated = array(); $errors = array(); $content_ops = array();
    foreach ($mapping as $col => $config) {
        $type = $config['type']; if ($type === 'ignore' || $type === 'identifier') continue;
        $val = isset($row[$col]) ? $row[$col] : '';
        if ($type === 'post_content_original') {
            if (!isset($content_ops['current_content_old'])) $content_ops['current_content_old'] = array();
            $content_ops['current_content_old'][] = $val;
            continue;
        }
        elseif ($type === 'post_content_update') {
            if (isset($content_ops['current_content_old']) && count($content_ops['current_content_old']) > 0) {
                $old_val = array_shift($content_ops['current_content_old']);
                if ($old_val !== '' && $val !== '') {
                    if (!isset($content_ops['content_replaces'])) $content_ops['content_replaces'] = array();
                    $content_ops['content_replaces'][] = array('old' => $old_val, 'new' => $val);
                }
            }
            continue;
        }
        elseif ($type === 'allheading_original') {
            if (!isset($content_ops['current_allheading_old'])) $content_ops['current_allheading_old'] = array();
            $content_ops['current_allheading_old'][] = $val;
            continue;
        }
        elseif ($type === 'allheading_update') {
            if (isset($content_ops['current_allheading_old']) && count($content_ops['current_allheading_old']) > 0) {
                $old_val = array_shift($content_ops['current_allheading_old']);
                if ($old_val !== '' && $val !== '') {
                    if (!isset($content_ops['allheading_replaces'])) $content_ops['allheading_replaces'] = array();
                    $content_ops['allheading_replaces'][] = array('old' => $old_val, 'new' => $val);
                }
            }
            continue;
        }
        elseif (preg_match('/^h([1-6])_(original|update|order)$/', $type, $matches)) {
            $h_num = $matches[1];
            $h_type = $matches[2];
            $key_old = 'current_h' . $h_num . '_old';
            $key_replaces = 'h' . $h_num . '_replaces';
            $key_order = 'h' . $h_num . '_order';
            $key_order_idx = 'h' . $h_num . '_order_auto_index';
            
            if ($h_type === 'original') {
                if (!isset($content_ops[$key_old])) $content_ops[$key_old] = array();
                $content_ops[$key_old][] = $val;
            } elseif ($h_type === 'update') {
                if (isset($content_ops[$key_old]) && count($content_ops[$key_old]) > 0) {
                    $old_val = array_shift($content_ops[$key_old]);
                    if ($old_val !== '' && $val !== '') {
                        if (!isset($content_ops[$key_replaces])) $content_ops[$key_replaces] = array();
                        $content_ops[$key_replaces][] = array('old' => $old_val, 'new' => $val);
                    }
                } else {
                    if ($h_num == 1 && $val !== '') {
                        $content_ops['h1_force_replace'] = $val;
                    }
                }
            } elseif ($h_type === 'order') {
                if (!isset($content_ops[$key_order_idx])) $content_ops[$key_order_idx] = 1;
                $order = $content_ops[$key_order_idx]++;
                if ($val !== '') {
                    if (!isset($content_ops[$key_order])) $content_ops[$key_order] = array();
                    $content_ops[$key_order][$order] = $val;
                }
            }
            continue;
        }
        if ($val === '') continue;
        if ($type === 'seo_title' || $type === 'seo_desc') {
            $is_title = ($type === 'seo_title'); $val = $is_title ? sanitize_text_field($val) : sanitize_textarea_field($val);
            if ($obj['type'] === 'post') {
                if ($seo_plugin === 'both' || $seo_plugin === 'yoast') { $meta_key = $is_title ? '_yoast_wpseo_title' : '_yoast_wpseo_metadesc'; $old = get_post_meta($obj['id'], $meta_key, true); ycu_save_log($batch_id, 'post', $obj['id'], $meta_key, $old, $val); update_post_meta($obj['id'], $meta_key, $val); }
                if ($seo_plugin === 'both' || $seo_plugin === 'rankmath') { $meta_key = $is_title ? 'rank_math_title' : 'rank_math_description'; $old = get_post_meta($obj['id'], $meta_key, true); ycu_save_log($batch_id, 'post', $obj['id'], $meta_key, $old, $val); update_post_meta($obj['id'], $meta_key, $val); }
                $updated[] = $is_title ? 'SEO Title' : 'SEO Desc';
            } elseif ($obj['type'] === 'term') {
                if ($seo_plugin === 'both' || $seo_plugin === 'yoast') { $meta_key = $is_title ? 'wpseo_title' : 'wpseo_desc'; $old = get_term_meta($obj['id'], $meta_key, true); ycu_save_log($batch_id, 'term', $obj['id'], $meta_key, $old, $val); update_term_meta($obj['id'], $meta_key, $val); }
                if ($seo_plugin === 'both' || $seo_plugin === 'rankmath') { $meta_key = $is_title ? 'rank_math_title' : 'rank_math_description'; $old = get_term_meta($obj['id'], $meta_key, true); ycu_save_log($batch_id, 'term', $obj['id'], $meta_key, $old, $val); update_term_meta($obj['id'], $meta_key, $val); }
                $updated[] = $is_title ? 'SEO Title (Term)' : 'SEO Desc (Term)';
            } elseif ($obj['type'] === 'home_posts') {
                if ($seo_plugin === 'both' || $seo_plugin === 'yoast') { $yoast_titles = get_option('wpseo_titles', array()); $old = $yoast_titles; $yoast_key = $is_title ? 'title-home-wpseo' : 'metadesc-home-wpseo'; $yoast_titles[$yoast_key] = $val; ycu_save_log($batch_id, 'home_posts', 0, 'wpseo_titles', $old, $yoast_titles); update_option('wpseo_titles', $yoast_titles); }
                if ($seo_plugin === 'both' || $seo_plugin === 'rankmath') { $rm_titles = get_option('rank-math-options-titles', array()); $old = $rm_titles; $rm_key = $is_title ? 'homepage_title' : 'homepage_description'; $rm_titles[$rm_key] = $val; ycu_save_log($batch_id, 'home_posts', 0, 'rank-math-options-titles', $old, $rm_titles); update_option('rank-math-options-titles', $rm_titles); }
                $updated[] = $is_title ? 'Home SEO Title' : 'Home SEO Desc';
            }
        } elseif ($type === 'slug_update') {
            $new_slug = sanitize_title($val);
            if ($obj['type'] === 'post') { $old = get_post_field('post_name', $obj['id']); ycu_save_log($batch_id, 'post', $obj['id'], 'slug', $old, $new_slug); wp_update_post(array('ID' => $obj['id'], 'post_name' => $new_slug)); $updated[] = 'Slug'; }
            elseif ($obj['type'] === 'term') { $old = get_term($obj['id'])->slug; ycu_save_log($batch_id, 'term', $obj['id'], 'slug', $old, $new_slug); wp_update_term($obj['id'], get_term($obj['id'])->taxonomy, array('slug' => $new_slug)); $updated[] = 'Slug'; }
        } elseif ($type === 'media_title_update') { if ($obj['type'] === 'post') { $old = get_post_field('post_title', $obj['id']); ycu_save_log($batch_id, 'post', $obj['id'], 'media_title', $old, $val); wp_update_post(array('ID' => $obj['id'], 'post_title' => sanitize_text_field($val))); $updated[] = 'Media/Post Title'; } }
        elseif ($type === 'media_caption') { if ($obj['type'] === 'post' && $obj['post_type'] === 'attachment') { $old = get_post_field('post_excerpt', $obj['id']); ycu_save_log($batch_id, 'post', $obj['id'], 'media_caption', $old, $val); wp_update_post(array('ID' => $obj['id'], 'post_excerpt' => wp_kses_post($val))); $updated[] = 'Legenda (Caption)'; } }
        elseif ($type === 'media_description') { if ($obj['type'] === 'post' && $obj['post_type'] === 'attachment') { $old = get_post_field('post_content', $obj['id']); ycu_save_log($batch_id, 'post', $obj['id'], 'media_description', $old, $val); wp_update_post(array('ID' => $obj['id'], 'post_content' => wp_kses_post($val))); $updated[] = 'Descrição (Media)'; } }
        elseif ($type === 'post_excerpt') { if ($obj['type'] === 'post') { $old = get_post_field('post_excerpt', $obj['id']); ycu_save_log($batch_id, 'post', $obj['id'], 'post_excerpt', $old, $val); wp_update_post(array('ID' => $obj['id'], 'post_excerpt' => wp_kses_post($val))); $updated[] = 'Post Excerpt'; } }
        elseif ($type === 'term_name_update') { if ($obj['type'] === 'term') { $old = get_term($obj['id'])->name; ycu_save_log($batch_id, 'term', $obj['id'], 'term_name', $old, $val); wp_update_term($obj['id'], get_term($obj['id'])->taxonomy, array('name' => sanitize_text_field($val))); $updated[] = 'Nome do Termo'; } }
        elseif ($type === 'term_description') { if ($obj['type'] === 'term') { $old = get_term($obj['id'])->description; ycu_save_log($batch_id, 'term', $obj['id'], 'term_description', $old, $val); wp_update_term($obj['id'], get_term($obj['id'])->taxonomy, array('description' => wp_kses_post($val))); $updated[] = 'Descrição do Termo'; } }
        elseif ($type === 'alt_text') { if ($obj['type'] === 'post' && $obj['post_type'] === 'attachment') { $old = get_post_meta($obj['id'], '_wp_attachment_image_alt', true); ycu_save_log($batch_id, 'attachment', $obj['id'], '_wp_attachment_image_alt', $old, $val); update_post_meta($obj['id'], '_wp_attachment_image_alt', sanitize_text_field($val)); $updated[] = 'Alt Text'; } else { $errors[] = "Aviso: Não é anexo."; } }
        elseif ($type === 'category') {
            if ($obj['type'] === 'post') {
                $taxonomy = !empty($config['extra_key']) ? sanitize_text_field($config['extra_key']) : 'category'; $cats = array_map('trim', explode(',', $val)); $cat_ids = array();
                foreach ($cats as $cat_name) {
                    if (empty($cat_name)) continue; $mapped_val = isset($term_mapping[$cat_name]) ? $term_mapping[$cat_name] : 'CREATE:' . $cat_name;
                    if (strpos($mapped_val, 'ID:') === 0) { $cat_ids[] = intval(substr($mapped_val, 3)); }
                    else {
                        $new_name = strpos($mapped_val, 'CREATE:') === 0 ? substr($mapped_val, 7) : $mapped_val; $term = term_exists($new_name, $taxonomy);
                        if ($term !== 0 && $term !== null) { $cat_ids[] = intval(is_array($term) ? $term['term_id'] : $term); }
                        else { $new_term = wp_insert_term($new_name, $taxonomy); if (!is_wp_error($new_term)) { $cat_ids[] = intval($new_term['term_id']); } else { $errors[] = "Erro ao criar termo '$new_name'"; } }
                    }
                }
                if (!empty($cat_ids)) { $old_terms = wp_get_object_terms($obj['id'], $taxonomy, array('fields' => 'ids')); $old_val = is_wp_error($old_terms) ? '' : implode(',', $old_terms); ycu_save_log($batch_id, 'post', $obj['id'], 'category_' . $taxonomy, $old_val, implode(',', $cat_ids)); wp_set_object_terms($obj['id'], $cat_ids, $taxonomy, false); $updated[] = 'Taxonomia (' . $taxonomy . ')'; }
            } else { $errors[] = "Aviso: Taxonomias só Posts."; }
        } elseif ($type === 'author') {
            if ($obj['type'] === 'post') {
                $author_mapping = isset($_POST['author_mapping']) ? $_POST['author_mapping'] : array();
                $author_id = isset($author_mapping[$val]) ? intval($author_mapping[$val]) : 0;
                if ($author_id > 0) {
                    $old_author = get_post_field('post_author', $obj['id']);
                    if (intval($old_author) !== $author_id) {
                        ycu_save_log($batch_id, 'post', $obj['id'], 'post_author', $old_author, $author_id);
                        wp_update_post(array('ID' => $obj['id'], 'post_author' => $author_id));
                        $updated[] = 'Autor do Post';
                    }
                }
            } else { $errors[] = "Aviso: Autor só pode ser alterado em Posts/Páginas/CPTs."; }
        } elseif ($type === 'acf') {
            $acf_key = isset($config['extra_key']) ? $config['extra_key'] : '';
            if ($acf_key) {
                $post_id_to_update = $obj['id']; if ($obj['type'] === 'term') { $term_obj = get_term($obj['id']); $post_id_to_update = $term_obj->taxonomy . '_' . $obj['id']; }
                if (function_exists('update_field')) { $old = get_field($acf_key, $post_id_to_update); ycu_save_log($batch_id, $obj['type'], $obj['id'], $acf_key, $old, $val); update_field($acf_key, $val, $post_id_to_update); $updated[] = 'ACF (' . $acf_key . ')'; }
                else {
                    if ($obj['type'] === 'post') { $old = get_post_meta($obj['id'], $acf_key, true); ycu_save_log($batch_id, 'post', $obj['id'], $acf_key, $old, $val); update_post_meta($obj['id'], $acf_key, $val); }
                    elseif ($obj['type'] === 'term') { $old = get_term_meta($obj['id'], $acf_key, true); ycu_save_log($batch_id, 'term', $obj['id'], $acf_key, $old, $val); update_term_meta($obj['id'], $acf_key, $val); }
                    $updated[] = 'Meta (' . $acf_key . ')';
                }
            }
        }
    }
    if (!empty($content_ops) && in_array($obj['type'], array('post', 'term', 'attachment'))) {
        $is_term = ($obj['type'] === 'term');
        $obj_id = $obj['id']; 
        $old_content = $is_term ? get_term($obj_id)->description : get_post_field('post_content', $obj_id); 
        $new_content = $old_content;
        if (isset($content_ops['content_replaces'])) { 
            foreach ($content_ops['content_replaces'] as $rep) {
                $new_content = str_replace($rep['old'], $rep['new'], $new_content); 
            }
        }
        
        if (isset($content_ops['allheading_replaces'])) { 
            $new_content = preg_replace_callback('/(<h[2-6][^>]*>)(.*?)(<\/h[2-6]>)/is', function($matches) use ($content_ops) {
                $inner = $matches[2];
                foreach ($content_ops['allheading_replaces'] as $rep) {
                    $inner = str_replace($rep['old'], $rep['new'], $inner);
                }
                return $matches[1] . $inner . $matches[3];
            }, $new_content);
        }
        
        if (isset($content_ops['h1_force_replace'])) {
            $new_content = preg_replace_callback('/(<h1[^>]*>)(.*?)(<\/h1>)/is', function($matches) use ($content_ops) {
                return $matches[1] . $content_ops['h1_force_replace'] . $matches[3];
            }, $new_content);
        }
        
        $counters_post = array(1=>0, 2=>0, 3=>0, 4=>0, 5=>0, 6=>0);
        for ($i = 1; $i <= 6; $i++) {
            $key_replaces = 'h' . $i . '_replaces';
            if (isset($content_ops[$key_replaces])) {
                $new_content = preg_replace_callback('/(<h' . $i . '[^>]*>)(.*?)(<\/h' . $i . '>)/is', function($matches) use ($content_ops, $key_replaces) {
                    $inner = $matches[2];
                    foreach ($content_ops[$key_replaces] as $rep) {
                        $inner = str_replace($rep['old'], $rep['new'], $inner);
                    }
                    return $matches[1] . $inner . $matches[3];
                }, $new_content);
            }
            
            $key_order = 'h' . $i . '_order';
            if (isset($content_ops[$key_order])) {
                $new_content = preg_replace_callback('/(<h' . $i . '[^>]*>)(.*?)(<\/h' . $i . '>)/is', function($matches) use (&$counters_post, $i, $content_ops, $key_order) {
                    $counters_post[$i]++;
                    if (isset($content_ops[$key_order][$counters_post[$i]]) && !empty($content_ops[$key_order][$counters_post[$i]])) { 
                        return $matches[1] . wp_kses_post($content_ops[$key_order][$counters_post[$i]]) . $matches[3]; 
                    } 
                    return $matches[0]; 
                }, $new_content);
            }
        }
        if ($new_content !== $old_content) { 
            if ($is_term) {
                ycu_save_log($batch_id, 'term', $obj_id, 'term_description', $old_content, $new_content); 
                wp_update_term($obj_id, get_term($obj_id)->taxonomy, array('description' => $new_content)); 
            } else {
                ycu_save_log($batch_id, 'post', $obj_id, 'post_content', $old_content, $new_content); 
                wp_update_post(array('ID' => $obj_id, 'post_content' => $new_content)); 
            }
            $updated[] = 'Conteúdo HTML'; 
        }
        
        if (!$is_term) {
            $post_id = $obj_id;
            
            // Sincroniza post_title com alterações de H1
            $old_post_title = get_post_field('post_title', $post_id);
            $new_post_title = $old_post_title;
            
            if (isset($content_ops['h1_force_replace'])) {
                $new_post_title = $content_ops['h1_force_replace'];
            } else {
                if (isset($content_ops['h1_replaces'])) {
                    foreach ($content_ops['h1_replaces'] as $rep) {
                        $new_post_title = str_replace($rep['old'], $rep['new'], $new_post_title);
                    }
                }
                if (isset($content_ops['h1_order']) && isset($content_ops['h1_order'][1])) {
                    $new_post_title = wp_kses_post($content_ops['h1_order'][1]);
                }
            }
            
            if ($new_post_title !== $old_post_title) {
                ycu_save_log($batch_id, 'post', $post_id, 'post_title', $old_post_title, $new_post_title);
                wp_update_post(array('ID' => $post_id, 'post_title' => sanitize_text_field($new_post_title)));
                $updated[] = 'Título do Post (Sincronizado com H1)';
            }

            $elementor_data = get_post_meta($post_id, '_elementor_data', true);
            if (!empty($elementor_data)) {
                $data = json_decode($elementor_data, true);
                if (is_array($data)) {
                    $all_exact_replaces = array();
                    if (isset($content_ops['content_replaces'])) $all_exact_replaces = array_merge($all_exact_replaces, $content_ops['content_replaces']);
                    if (isset($content_ops['allheading_replaces'])) $all_exact_replaces = array_merge($all_exact_replaces, $content_ops['allheading_replaces']);
                    for ($i = 1; $i <= 6; $i++) {
                        $kr = 'h' . $i . '_replaces';
                        if (isset($content_ops[$kr])) $all_exact_replaces = array_merge($all_exact_replaces, $content_ops[$kr]);
                    }
                    
                    $counters_el = array(1=>0, 2=>0, 3=>0, 4=>0, 5=>0, 6=>0);
                    $traverse = function(&$elements) use (&$traverse, &$counters_el, $content_ops, $all_exact_replaces) {
                        if (!is_array($elements)) return;
                        foreach ($elements as &$el) {
                            if (isset($el['elType']) && $el['elType'] === 'widget') {
                                if ($el['widgetType'] === 'heading' || $el['widgetType'] === 'icon-box' || $el['widgetType'] === 'image-box') {
                                    $size_key = ($el['widgetType'] === 'heading') ? 'header_size' : 'title_size';
                                    $title_key = ($el['widgetType'] === 'heading') ? 'title' : 'title_text';
                                    
                                    $size = isset($el['settings'][$size_key]) ? strtolower($el['settings'][$size_key]) : (($el['widgetType'] === 'heading') ? 'h2' : 'h3');
                                    if (empty($size)) $size = ($el['widgetType'] === 'heading') ? 'h2' : 'h3';
                                    
                                    $h_num = 0;
                                    if (preg_match('/^h([1-6])$/', $size, $m)) $h_num = intval($m[1]);
                                    
                                    if (isset($el['settings'][$title_key])) {
                                        $old_t = $el['settings'][$title_key];
                                        $new_t = $old_t;
                                        
                                        // Allheading for H2-H6
                                        if (isset($content_ops['allheading_replaces']) && $h_num >= 2 && $h_num <= 6) {
                                            foreach ($content_ops['allheading_replaces'] as $rep) {
                                                $new_t = str_replace($rep['old'], $rep['new'], $new_t);
                                            }
                                        }
                                        
                                        // Specific Hx
                                        if ($h_num >= 1 && $h_num <= 6) {
                                            if ($h_num === 1 && isset($content_ops['h1_force_replace'])) {
                                                $new_t = $content_ops['h1_force_replace'];
                                            }
                                            
                                            $key_replaces = 'h' . $h_num . '_replaces';
                                            if (isset($content_ops[$key_replaces])) {
                                                foreach ($content_ops[$key_replaces] as $rep) {
                                                    $new_t = str_replace($rep['old'], $rep['new'], $new_t);
                                                }
                                            }
                                            
                                            $key_order = 'h' . $h_num . '_order';
                                            if (isset($content_ops[$key_order])) {
                                                $counters_el[$h_num]++;
                                                if (isset($content_ops[$key_order][$counters_el[$h_num]]) && !empty($content_ops[$key_order][$counters_el[$h_num]])) {
                                                    $new_t = wp_kses_post($content_ops[$key_order][$counters_el[$h_num]]);
                                                }
                                            }
                                        }
                                        
                                        if ($new_t !== $old_t) {
                                            $el['settings'][$title_key] = $new_t;
                                        }
                                    }
                                } elseif ($el['widgetType'] === 'text-editor') {
                                    if (isset($el['settings']['editor'])) {
                                        $html = $el['settings']['editor'];
                                        
                                        if (isset($content_ops['allheading_replaces'])) { 
                                            $html = preg_replace_callback('/(<h[2-6][^>]*>)(.*?)(<\/h[2-6]>)/is', function($matches) use ($content_ops) {
                                                $inner = $matches[2];
                                                foreach ($content_ops['allheading_replaces'] as $rep) {
                                                    $inner = str_replace($rep['old'], $rep['new'], $inner);
                                                }
                                                return $matches[1] . $inner . $matches[3];
                                            }, $html);
                                        }
                                        
                                        if (isset($content_ops['h1_force_replace'])) {
                                            $html = preg_replace_callback('/(<h1[^>]*>)(.*?)(<\/h1>)/is', function($matches) use ($content_ops) {
                                                return $matches[1] . $content_ops['h1_force_replace'] . $matches[3];
                                            }, $html);
                                        }
                                        
                                        for ($i = 1; $i <= 6; $i++) {
                                            $key_replaces = 'h' . $i . '_replaces';
                                            if (isset($content_ops[$key_replaces])) {
                                                $html = preg_replace_callback('/(<h' . $i . '[^>]*>)(.*?)(<\/h' . $i . '>)/is', function($matches) use ($content_ops, $key_replaces) {
                                                    $inner = $matches[2];
                                                    foreach ($content_ops[$key_replaces] as $rep) {
                                                        $inner = str_replace($rep['old'], $rep['new'], $inner);
                                                    }
                                                    return $matches[1] . $inner . $matches[3];
                                                }, $html);
                                            }
                                            
                                            $key_order = 'h' . $i . '_order';
                                            if (isset($content_ops[$key_order])) {
                                                $html = preg_replace_callback('/(<h' . $i . '[^>]*>)(.*?)(<\/h' . $i . '>)/is', function($matches) use (&$counters_el, $i, $content_ops, $key_order) {
                                                    $counters_el[$i]++;
                                                    if (isset($content_ops[$key_order][$counters_el[$i]]) && !empty($content_ops[$key_order][$counters_el[$i]])) { 
                                                        return $matches[1] . wp_kses_post($content_ops[$key_order][$counters_el[$i]]) . $matches[3]; 
                                                    } 
                                                    return $matches[0]; 
                                                }, $html);
                                            }
                                        }
                                        $el['settings']['editor'] = $html;
                                    }
                                }
                                
                                // Global exact replaces for ANY widget setting!
                                if (!empty($all_exact_replaces) && isset($el['settings']) && is_array($el['settings'])) {
                                    $replace_recursive = function(&$array) use (&$replace_recursive, $all_exact_replaces) {
                                        foreach ($array as $key => &$value) {
                                            if (is_string($value)) {
                                                foreach ($all_exact_replaces as $rep) {
                                                    $value = str_replace($rep['old'], $rep['new'], $value);
                                                    
                                                    // Fallback for non-breaking spaces
                                                    $fallback_old = str_replace(' ', '&nbsp;', $rep['old']);
                                                    if ($fallback_old !== $rep['old']) {
                                                        $value = str_replace($fallback_old, $rep['new'], $value);
                                                    }
                                                }
                                            } elseif (is_array($value)) {
                                                $replace_recursive($value);
                                            }
                                        }
                                    };
                                    $replace_recursive($el['settings']);
                                }
                            }
                            if (isset($el['elements'])) { $traverse($el['elements']); }
                        }
                    };
                    $traverse($data); $new_json = json_encode($data);
                    if ($new_json !== $elementor_data) { 
                        ycu_save_log($batch_id, 'post', $post_id, '_elementor_data', $elementor_data, $new_json); 
                        update_post_meta($post_id, '_elementor_data', wp_slash($new_json)); 
                        
                        // Clear Elementor and External Caches
                        ycu_clear_elementor_cache($post_id);
    
                        if (!in_array('Conteúdo Elementor', $updated)) $updated[] = 'Conteúdo Elementor'; 
                    }
                }
            }
        }
    }
    $info = '';
    if ($obj['type'] === 'post') $info = '[ID: ' . $obj['id'] . ', Tipo: ' . $obj['post_type'] . ']';
    elseif ($obj['type'] === 'term') $info = '[ID: ' . $obj['id'] . ', Tax: ' . $obj['taxonomy'] . ']';
    elseif ($obj['type'] === 'home_posts') $info = '[Home Page]';

    wp_send_json_success('Sucesso ' . $info . '. Atualizado: ' . (empty($updated) ? 'Nenhum' : implode(', ', $updated)));
}

function ycu_export_csv_handler() {
    check_ajax_referer('ycu_nonce', 'nonce');
    $upload_dir = wp_upload_dir(); $file_name = 'seo_meta_export_' . time() . '.csv'; $file_path = $upload_dir['path'] . '/' . $file_name; $file_url = $upload_dir['url'] . '/' . $file_name;
    $fp = fopen($file_path, 'w'); fputcsv($fp, array('ID', 'Type', 'Slug', 'Title/Name', 'Description/Content', 'Excerpt', 'Yoast SEO Title', 'Yoast SEO Desc', 'Rank Math Title', 'Rank Math Desc', 'Alt Text'));
    $args = array('post_type' => 'any', 'post_status' => 'publish,inherit', 'posts_per_page' => -1, 'fields' => 'ids'); $posts = get_posts($args);
    foreach ($posts as $post_id) {
        $type = get_post_type($post_id); $slug = get_post_field('post_name', $post_id); $title = get_post_field('post_title', $post_id); $content = get_post_field('post_content', $post_id); $excerpt = get_post_field('post_excerpt', $post_id);
        $y_title = get_post_meta($post_id, '_yoast_wpseo_title', true); $y_desc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true); $rm_title = get_post_meta($post_id, 'rank_math_title', true); $rm_desc = get_post_meta($post_id, 'rank_math_description', true);
        $alt = ($type === 'attachment') ? get_post_meta($post_id, '_wp_attachment_image_alt', true) : '';
        fputcsv($fp, array($post_id, 'post/' . $type, $slug, $title, $content, $excerpt, $y_title, $y_desc, $rm_title, $rm_desc, $alt));
    }
    $terms = get_terms(array('hide_empty' => false));
    if (!is_wp_error($terms)) { foreach ($terms as $term) { $y_t = get_term_meta($term->term_id, 'wpseo_title', true); $y_d = get_term_meta($term->term_id, 'wpseo_desc', true); $rm_t = get_term_meta($term->term_id, 'rank_math_title', true); $rm_d = get_term_meta($term->term_id, 'rank_math_description', true); fputcsv($fp, array($term->term_id, 'term/' . $term->taxonomy, $term->slug, $term->name, $term->description, '', $y_t, $y_d, $rm_t, $rm_d, '')); } }
    fclose($fp); wp_send_json_success(array('url' => $file_url));
}
