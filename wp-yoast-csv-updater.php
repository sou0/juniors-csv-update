<?php
/**
 * Plugin Name: SEO & Meta CSV Updater
 * Description: Plugin para atualizar em massa Titles, Descriptions (Yoast/Rank Math), Campos ACF, Alt de Imagens, Categorias e Slugs via upload de arquivo CSV com mapeamento de colunas. Conta com interface de terminal para logs e exportação.
 * Version: 2.0.0
 * Author: junior
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define('YCU_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('YCU_PLUGIN_URL', plugin_dir_url(__FILE__));

// Hooks do Menu
add_action('admin_menu', 'ycu_admin_menu');
add_action('admin_enqueue_scripts', 'ycu_admin_scripts');

function ycu_admin_menu() {
    add_menu_page(
        'SEO & Meta CSV',
        'SEO & Meta CSV',
        'manage_options',
        'yoast-csv-updater',
        'ycu_admin_page',
        'dashicons-media-spreadsheet',
        80
    );
}

function ycu_admin_scripts($hook) {
    if ($hook != 'toplevel_page_yoast-csv-updater') return;
    $ver = time();
    wp_enqueue_style('ycu-style', YCU_PLUGIN_URL . 'assets/style.css', array(), $ver);
    wp_enqueue_script('ycu-script', YCU_PLUGIN_URL . 'assets/script.js', array('jquery'), $ver, true);
    wp_localize_script('ycu-script', 'ycu_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'), 
        'nonce'    => wp_create_nonce('ycu_nonce')
    ));
}

function ycu_admin_page() {
    include YCU_PLUGIN_DIR . 'includes/admin-page.php';
}

function ycu_setup_database() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $table_batches = $wpdb->prefix . 'ycu_batches';
    $table_logs = $wpdb->prefix . 'ycu_logs';

    // Cria as tabelas apenas se não existirem
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_batches'") != $table_batches) {
        $sql1 = "CREATE TABLE $table_batches (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            batch_id varchar(50) NOT NULL,
            file_name varchar(255) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY batch_id (batch_id)
        ) $charset_collate;";

        $sql2 = "CREATE TABLE $table_logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            batch_id varchar(50) NOT NULL,
            obj_type varchar(20) NOT NULL,
            obj_id bigint(20) NOT NULL,
            field_name varchar(100) NOT NULL,
            old_val longtext,
            new_val longtext,
            PRIMARY KEY  (id),
            KEY batch_id (batch_id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql1 );
        dbDelta( $sql2 );
    }
}
add_action('admin_init', 'ycu_setup_database');

require_once YCU_PLUGIN_DIR . 'includes/ajax-handlers.php';
