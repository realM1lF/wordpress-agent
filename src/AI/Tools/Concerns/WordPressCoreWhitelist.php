<?php

namespace Levi\Agent\AI\Tools\Concerns;

trait WordPressCoreWhitelist
{
    /**
     * Returns a set of ~300 commonly used WordPress core functions.
     * Used by checkReferenceIntegrity() to avoid false-positive "undefined function" warnings.
     * @return array<string, true> Function names as keys for O(1) lookup
     */
    protected function getWordPressCoreFunctions(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $functions = [
            // Hooks
            'add_action', 'remove_action', 'do_action', 'did_action', 'has_action',
            'add_filter', 'remove_filter', 'apply_filters', 'has_filter', 'current_filter',
            'remove_all_actions', 'remove_all_filters', 'doing_action', 'doing_filter',
            // Enqueue
            'wp_enqueue_script', 'wp_enqueue_style', 'wp_register_script', 'wp_register_style',
            'wp_dequeue_script', 'wp_dequeue_style', 'wp_localize_script', 'wp_add_inline_style',
            'wp_add_inline_script', 'wp_script_is', 'wp_style_is', 'wp_enqueue_media',
            // Post
            'get_post', 'get_posts', 'wp_insert_post', 'wp_update_post', 'wp_delete_post',
            'get_post_meta', 'update_post_meta', 'add_post_meta', 'delete_post_meta',
            'get_post_type', 'get_post_types', 'get_post_status', 'get_post_statuses',
            'register_post_type', 'unregister_post_type', 'post_type_exists',
            'get_post_type_object', 'get_post_type_labels',
            'wp_get_post_terms', 'wp_set_post_terms', 'wp_remove_object_terms',
            'get_the_title', 'get_the_content', 'get_the_excerpt', 'get_the_date',
            'the_title', 'the_content', 'the_excerpt', 'the_date', 'the_permalink',
            'get_permalink', 'get_the_permalink', 'get_post_permalink',
            'wp_trash_post', 'wp_untrash_post', 'get_page_by_title', 'get_page_by_path',
            'setup_postdata', 'wp_reset_postdata', 'wp_reset_query',
            // Taxonomy
            'register_taxonomy', 'unregister_taxonomy', 'taxonomy_exists',
            'get_taxonomy', 'get_taxonomies', 'get_object_taxonomies',
            'get_terms', 'get_term', 'get_term_by', 'wp_get_object_terms',
            'wp_set_object_terms', 'wp_insert_term', 'wp_update_term', 'wp_delete_term',
            'get_term_meta', 'update_term_meta', 'add_term_meta', 'delete_term_meta',
            'get_categories', 'get_category', 'get_cat_name', 'get_tags',
            'has_term', 'is_object_in_term', 'term_exists',
            // User
            'get_current_user_id', 'wp_get_current_user', 'get_user_by', 'get_userdata',
            'current_user_can', 'user_can', 'wp_create_user', 'wp_insert_user',
            'wp_update_user', 'wp_delete_user', 'get_users', 'is_user_logged_in',
            'wp_get_current_user', 'get_user_meta', 'update_user_meta', 'add_user_meta',
            'delete_user_meta', 'wp_set_current_user', 'is_super_admin',
            'wp_logout', 'wp_signon', 'wp_authenticate',
            // Options
            'get_option', 'update_option', 'add_option', 'delete_option',
            'get_site_option', 'update_site_option', 'add_site_option', 'delete_site_option',
            'get_transient', 'set_transient', 'delete_transient',
            'get_site_transient', 'set_site_transient', 'delete_site_transient',
            // Query
            'WP_Query', 'get_queried_object', 'get_queried_object_id',
            'have_posts', 'the_post', 'rewind_posts', 'query_posts',
            'is_main_query', 'is_single', 'is_singular', 'is_page', 'is_home',
            'is_front_page', 'is_archive', 'is_category', 'is_tag', 'is_tax',
            'is_search', 'is_404', 'is_admin', 'is_network_admin',
            'is_post_type_archive', 'is_author', 'is_date', 'is_year', 'is_month', 'is_day',
            // Sanitize / Escape
            'sanitize_text_field', 'sanitize_title', 'sanitize_email', 'sanitize_file_name',
            'sanitize_html_class', 'sanitize_key', 'sanitize_meta', 'sanitize_mime_type',
            'sanitize_option', 'sanitize_sql_orderby', 'sanitize_user', 'sanitize_url',
            'esc_html', 'esc_attr', 'esc_url', 'esc_js', 'esc_textarea', 'esc_sql',
            'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e', 'esc_url_raw',
            'wp_kses', 'wp_kses_post', 'wp_kses_data', 'wp_kses_allowed_html',
            'absint', 'intval', 'floatval',
            // Nonce / Security
            'wp_nonce_field', 'wp_nonce_url', 'wp_verify_nonce', 'wp_create_nonce',
            'check_admin_referer', 'check_ajax_referer', 'wp_check_nonces',
            // Shortcodes
            'add_shortcode', 'remove_shortcode', 'do_shortcode', 'shortcode_atts',
            'has_shortcode', 'shortcode_exists',
            // Admin
            'add_menu_page', 'add_submenu_page', 'add_options_page', 'add_theme_page',
            'add_management_page', 'add_plugins_page', 'remove_menu_page', 'remove_submenu_page',
            'add_meta_box', 'remove_meta_box', 'add_settings_section', 'add_settings_field',
            'register_setting', 'unregister_setting', 'settings_fields', 'do_settings_sections',
            'submit_button', 'get_submit_button',
            'admin_url', 'site_url', 'home_url', 'content_url', 'plugins_url',
            'get_admin_url', 'get_site_url', 'get_home_url',
            'is_plugin_active', 'activate_plugin', 'deactivate_plugins',
            // Media / Uploads
            'wp_upload_dir', 'wp_get_attachment_url', 'wp_get_attachment_image',
            'wp_get_attachment_image_src', 'wp_get_attachment_metadata',
            'wp_generate_attachment_metadata', 'wp_insert_attachment',
            'wp_delete_attachment', 'get_attached_file', 'wp_handle_upload',
            'media_handle_upload', 'wp_get_image_editor',
            'get_post_thumbnail_id', 'get_the_post_thumbnail', 'get_the_post_thumbnail_url',
            'has_post_thumbnail', 'set_post_thumbnail', 'the_post_thumbnail',
            // Filesystem
            'wp_mkdir_p', 'wp_is_writable', 'WP_Filesystem', 'get_filesystem_method',
            // HTTP
            'wp_remote_get', 'wp_remote_post', 'wp_remote_request', 'wp_remote_head',
            'wp_remote_retrieve_body', 'wp_remote_retrieve_response_code',
            'wp_remote_retrieve_headers', 'wp_remote_retrieve_header',
            'wp_safe_remote_get', 'wp_safe_remote_post',
            // Cache
            'wp_cache_get', 'wp_cache_set', 'wp_cache_delete', 'wp_cache_add',
            'wp_cache_flush', 'wp_cache_replace',
            // Cron
            'wp_schedule_event', 'wp_schedule_single_event', 'wp_next_scheduled',
            'wp_unschedule_event', 'wp_clear_scheduled_hook', 'wp_cron',
            // Rewrite
            'flush_rewrite_rules', 'add_rewrite_rule', 'add_rewrite_tag',
            'add_rewrite_endpoint', 'add_permastruct',
            // i18n
            '__', '_e', '_x', '_n', '_nx', '_ex',
            'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e',
            'load_plugin_textdomain', 'load_theme_textdomain',
            // Theme
            'get_template_directory', 'get_template_directory_uri',
            'get_stylesheet_directory', 'get_stylesheet_directory_uri',
            'get_theme_mod', 'set_theme_mod', 'remove_theme_mod',
            'add_theme_support', 'remove_theme_support', 'current_theme_supports',
            'get_header', 'get_footer', 'get_sidebar', 'get_template_part',
            'locate_template', 'load_template', 'get_search_form',
            'wp_nav_menu', 'register_nav_menus', 'register_nav_menu', 'has_nav_menu',
            'wp_get_nav_menu_items', 'wp_get_nav_menu_object',
            // Widgets / Sidebars
            'register_sidebar', 'unregister_sidebar', 'register_widget', 'unregister_widget',
            'dynamic_sidebar', 'is_active_sidebar', 'the_widget',
            // REST API
            'register_rest_route', 'register_rest_field', 'rest_url',
            'rest_ensure_response', 'rest_authorization_required_code',
            // WP_Error
            'is_wp_error', 'wp_die',
            // Misc
            'wp_redirect', 'wp_safe_redirect', 'wp_get_referer',
            'wp_mail', 'wp_set_object_terms',
            'wp_parse_args', 'wp_list_pluck', 'wp_array_slice_assoc',
            'wp_json_encode', 'wp_send_json', 'wp_send_json_success', 'wp_send_json_error',
            'wp_doing_ajax', 'wp_doing_cron', 'wp_is_json_request',
            'get_bloginfo', 'wp_title', 'wp_head', 'wp_footer', 'wp_body_open',
            'is_ssl', 'is_multisite', 'get_current_blog_id',
            'wp_get_environment_type', 'wp_get_development_mode',
            'dbDelta', 'maybe_serialize', 'maybe_unserialize',
            'wp_generate_password', 'wp_hash_password', 'wp_check_password',
            'get_plugin_data', 'plugin_dir_path', 'plugin_dir_url', 'plugin_basename',
            'trailingslashit', 'untrailingslashit', 'path_join',
            'wp_normalize_path', 'wp_is_mobile',
            'wp_date', 'current_time', 'mysql2date', 'human_time_diff',
            'size_format', 'number_format_i18n', 'date_i18n',
            // WooCommerce common
            'wc_get_product', 'wc_get_order', 'wc_get_page_id', 'wc_get_cart_url',
            'wc_get_checkout_url', 'wc_price', 'wc_get_template', 'wc_get_template_part',
            'wc_add_notice', 'wc_print_notices', 'wc_get_logger', 'WC',
            'is_shop', 'is_product', 'is_product_category', 'is_cart', 'is_checkout',
            'is_account_page', 'is_woocommerce',
        ];

        $cache = array_fill_keys($functions, true);
        return $cache;
    }

    /**
     * Returns a set of common PHP built-in functions to exclude from reference checks.
     * @return array<string, true>
     */
    protected function getPhpBuiltinFunctions(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $builtins = [
            // Array
            'array_key_exists', 'array_keys', 'array_values', 'array_merge', 'array_push',
            'array_pop', 'array_shift', 'array_unshift', 'array_slice', 'array_splice',
            'array_search', 'array_unique', 'array_reverse', 'array_flip', 'array_map',
            'array_filter', 'array_reduce', 'array_walk', 'array_combine', 'array_chunk',
            'array_column', 'array_diff', 'array_diff_key', 'array_intersect', 'array_pad',
            'array_sum', 'array_count_values', 'array_fill', 'array_fill_keys',
            'array_multisort', 'array_rand', 'in_array', 'count', 'sizeof', 'sort', 'rsort',
            'asort', 'arsort', 'ksort', 'krsort', 'usort', 'uasort', 'uksort',
            'compact', 'extract', 'list', 'range', 'shuffle',
            // String
            'strlen', 'strpos', 'strrpos', 'str_contains', 'str_starts_with', 'str_ends_with',
            'substr', 'str_replace', 'str_ireplace', 'strtolower', 'strtoupper',
            'ucfirst', 'lcfirst', 'ucwords', 'trim', 'ltrim', 'rtrim',
            'explode', 'implode', 'join', 'sprintf', 'printf', 'fprintf', 'sscanf',
            'number_format', 'money_format', 'nl2br', 'wordwrap', 'str_pad',
            'str_repeat', 'str_word_count', 'substr_count', 'substr_replace',
            'str_split', 'chunk_split', 'similar_text', 'soundex', 'metaphone',
            'quoted_printable_encode', 'quoted_printable_decode',
            'htmlspecialchars', 'htmlspecialchars_decode', 'htmlentities', 'html_entity_decode',
            'strip_tags', 'addslashes', 'stripslashes', 'addcslashes', 'stripcslashes',
            'md5', 'sha1', 'crc32', 'hash', 'hash_hmac', 'base64_encode', 'base64_decode',
            'urlencode', 'urldecode', 'rawurlencode', 'rawurldecode', 'http_build_query',
            'parse_url', 'parse_str',
            'mb_strlen', 'mb_strpos', 'mb_substr', 'mb_strtolower', 'mb_strtoupper',
            'mb_detect_encoding', 'mb_convert_encoding', 'mb_internal_encoding',
            // Regex
            'preg_match', 'preg_match_all', 'preg_replace', 'preg_replace_callback',
            'preg_split', 'preg_quote', 'preg_last_error',
            // File
            'file_get_contents', 'file_put_contents', 'file_exists', 'is_file', 'is_dir',
            'is_readable', 'is_writable', 'is_writeable', 'mkdir', 'rmdir', 'unlink',
            'rename', 'copy', 'fopen', 'fclose', 'fread', 'fwrite', 'fgets', 'feof',
            'flock', 'fseek', 'ftell', 'rewind', 'ftruncate', 'fputcsv', 'fgetcsv',
            'glob', 'scandir', 'opendir', 'readdir', 'closedir',
            'pathinfo', 'dirname', 'basename', 'realpath', 'tempnam', 'sys_get_temp_dir',
            'filesize', 'filemtime', 'fileatime', 'filectime', 'chmod', 'chown',
            // JSON
            'json_encode', 'json_decode', 'json_last_error', 'json_last_error_msg',
            // Type
            'gettype', 'settype', 'is_string', 'is_int', 'is_integer', 'is_long',
            'is_float', 'is_double', 'is_bool', 'is_array', 'is_object', 'is_null',
            'is_numeric', 'is_callable', 'is_resource', 'isset', 'unset', 'empty',
            'intval', 'floatval', 'strval', 'boolval',
            // Math
            'abs', 'ceil', 'floor', 'round', 'max', 'min', 'pow', 'sqrt', 'log',
            'rand', 'mt_rand', 'random_int', 'random_bytes',
            // Output
            'echo', 'print', 'print_r', 'var_dump', 'var_export',
            // Date/Time
            'time', 'date', 'mktime', 'strtotime', 'gmdate', 'microtime',
            'date_create', 'date_format', 'date_diff', 'date_modify',
            // Class/Object
            'class_exists', 'interface_exists', 'trait_exists', 'method_exists',
            'property_exists', 'get_class', 'get_parent_class', 'is_a', 'instanceof',
            'get_object_vars', 'get_class_methods',
            // Function
            'function_exists', 'call_user_func', 'call_user_func_array',
            // Error
            'trigger_error', 'set_error_handler', 'restore_error_handler',
            'error_reporting', 'error_log',
            // Misc
            'defined', 'define', 'constant', 'die', 'exit', 'sleep', 'usleep',
            'header', 'headers_sent', 'http_response_code', 'setcookie',
            'ob_start', 'ob_end_clean', 'ob_get_clean', 'ob_flush', 'ob_get_contents',
            'phpversion', 'phpinfo', 'php_uname', 'getenv', 'putenv',
            'array_key_first', 'array_key_last',
        ];

        $cache = array_fill_keys($builtins, true);
        return $cache;
    }
}
