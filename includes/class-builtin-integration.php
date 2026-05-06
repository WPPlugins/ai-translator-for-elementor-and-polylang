<?php
/**
 * Built-in Language Management Integration
 * Provides native language management without requiring Polylang or WPML
 * Extends the Multilingual Interface for compatibility with the abstraction layer
 * 
 * Data storage:
 * - Languages stored in wp_options ('aitrfoel_builtin_languages', 'aitrfoel_builtin_default_language')
 * - Post-language association via custom taxonomy 'aitrfoel_language'
 * - Translation groups via custom DB table '{prefix}_aitrfoel_translation_groups'
 */

if (!defined('ABSPATH')) exit;

class AITRFOEL_Builtin_Integration extends AITRFOEL_Multilingual_Interface {
    
    private static $instance = null;
    
    /**
     * Custom taxonomy name for post-language association
     */
    const TAXONOMY = 'aitrfoel_language';
    
    /**
     * DB table name (without prefix)
     */
    const TABLE_NAME = 'aitrfoel_translation_groups';
    
    /**
     * Option keys
     */
    const OPTION_LANGUAGES = 'aitrfoel_builtin_languages';
    const OPTION_DEFAULT_LANG = 'aitrfoel_builtin_default_language';
    const OPTION_ENABLED = 'aitrfoel_builtin_enabled';
    const OPTION_DB_VERSION = 'aitrfoel_builtin_db_version';
    const OPTION_URL_STRUCTURE = 'aitrfoel_builtin_url_structure'; // 'subdirectory', 'parameter'
    
    /**
     * Current DB schema version
     */
    const DB_VERSION = '1.0';
    
    /**
     * Cached languages array
     * @var array|null
     */
    private $cached_languages = null;
    
    /**
     * Current frontend language
     * @var string|null
     */
    private $current_language = null;
    
    /**
     * Get singleton instance
     * @return AITRFOEL_Builtin_Integration
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        // Register taxonomy (after CPTs like 'product' are registered)
        add_action('init', [$this, 'register_taxonomy'], 20);
        
        // If init already fired (e.g. during AJAX), register taxonomy/rewrite rules immediately
        if (did_action('init')) {
            $this->register_taxonomy();
        }
        
        // Ensure DB table exists
        $this->maybe_create_table();
        
        // Frontend URL rewriting and language detection (after CPTs registered)
        add_action('init', [$this, 'add_rewrite_rules'], 25);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('parse_request', [$this, 'detect_language_from_request']);
        
        // Switch WordPress locale on frontend based on detected language
        if (!is_admin()) {
            add_filter('locale', [$this, 'filter_locale']);
        }
        
        // Permalink filtering for language prefixes
        add_filter('post_link', [$this, 'filter_permalink'], 10, 2);
        add_filter('page_link', [$this, 'filter_page_permalink'], 10, 2);
        add_filter('post_type_link', [$this, 'filter_permalink'], 10, 2);
        
        // Filter home_url to include language prefix (fixes logo link etc.)
        if (!is_admin()) {
            add_filter('home_url', [$this, 'filter_home_url'], 10, 4);
        }
        
        // Frontend query filtering
        if (!is_admin()) {
            add_action('pre_get_posts', [$this, 'filter_frontend_queries']);
            
            // Filter taxonomy term names to show translated versions
            add_filter('get_term', [$this, 'filter_term_name'], 10, 2);
            add_filter('get_terms', [$this, 'filter_terms_names'], 10, 4);
            
            // Filter WooCommerce and WordPress page IDs to translated versions
            add_filter('pre_option_page_on_front', [$this, 'filter_translated_page_option'], 10, 2);
            add_filter('pre_option_woocommerce_shop_page_id', [$this, 'filter_translated_page_option'], 10, 2);
            add_filter('pre_option_woocommerce_cart_page_id', [$this, 'filter_translated_page_option'], 10, 2);
            add_filter('pre_option_woocommerce_checkout_page_id', [$this, 'filter_translated_page_option'], 10, 2);
            add_filter('pre_option_woocommerce_myaccount_page_id', [$this, 'filter_translated_page_option'], 10, 2);
            add_filter('pre_option_woocommerce_terms_page_id', [$this, 'filter_translated_page_option'], 10, 2);
        }
        
        // SEO: hreflang tags
        add_action('wp_head', [$this, 'output_hreflang_tags']);
        
        // Redirect unprefixed default-language URLs to prefixed when option is enabled
        add_action('template_redirect', [$this, 'maybe_redirect_default_lang_prefix']);
        
        // Prevent WordPress canonical redirect from stripping language prefix
        add_filter('redirect_canonical', [$this, 'filter_redirect_canonical'], 10, 2);
        
        // Preload language switcher CSS in <head> for all frontend pages
        add_action('wp_head', [$this, 'maybe_preload_switcher_css'], 99);
        
        // Language switcher shortcode
        add_action('init', [$this, 'register_shortcode']);
        
        // Language switcher widget
        add_action('widgets_init', [$this, 'register_widget']);
        
        // Nav menu language switcher items
        add_filter('wp_nav_menu_items', [$this, 'add_language_switcher_to_menu'], 20, 2);
        
        // Per-language menu assignment: swap nav menu based on current language
        if (!is_admin()) {
            add_filter('wp_nav_menu_args', [$this, 'filter_nav_menu_by_language'], 10, 1);
            // Runtime: swap menu item URLs to translated posts
            add_filter('wp_nav_menu_objects', [$this, 'filter_menu_item_urls'], 10, 2);
            // Redirect untranslated pages to default language
            add_action('template_redirect', [$this, 'maybe_redirect_untranslated_page']);
        }
        
        // Per-language widget visibility
        if (!is_admin()) {
            add_filter('widget_display_callback', [$this, 'filter_widget_by_language'], 10, 3);
        }
        add_action('in_widget_form', [$this, 'widget_language_form'], 10, 3);
        add_filter('widget_update_callback', [$this, 'widget_language_save'], 10, 4);
        
        // Elementor Pro: swap header/footer/section templates to translated versions
        // Strategy (mirrors "Connect Polylang for Elementor" plugin):
        //   1. Translated templates have NO _elementor_conditions in DB.
        //   2. Elementor resolves candidates using only the original (default-lang) template's conditions.
        //   3. Once Elementor has picked the original template ID, we swap it to the translation via
        //      the elementor/theme/get_location_templates/template_id filter.
        //   4. The get_post_metadata filter ensures translated templates always return empty conditions
        //      even if conditions were accidentally written to DB (belt-and-suspenders).
        if (!is_admin()) {
            add_filter('elementor/theme/get_location_templates/template_id', [$this, 'swap_elementor_template_id_to_translation'], 10, 1);
            // Fallback: intercept Elementor data loading for any template
            add_filter('elementor/frontend/builder_content_data', [$this, 'filter_elementor_template_data'], 10, 2);
        }
        // Always (admin + frontend): make translated templates appear to have no conditions,
        // so they never compete with the original in Elementor's conditions resolver.
        add_filter('get_post_metadata', [$this, 'elementor_conditions_empty_on_translations'], 10, 3);
        
        // REST API language fields
        add_action('rest_api_init', [$this, 'register_rest_fields']);
        
        // WordPress core sitemap: filter URLs to include only default-language posts
        add_filter('wp_sitemaps_posts_query_args', [$this, 'filter_sitemap_query'], 10, 2);
        
        // Admin bar: language switcher in toolbar
        add_action('admin_bar_menu', [$this, 'add_admin_bar_language_switcher'], 100);
        add_action('admin_head', [$this, 'admin_bar_switcher_css']);
        add_action('wp_head', [$this, 'admin_bar_switcher_css']);
        
        // Admin: language column in posts list
        if (is_admin()) {
            add_filter('manage_posts_columns', [$this, 'add_language_column']);
            add_filter('manage_pages_columns', [$this, 'add_language_column']);
            add_action('manage_posts_custom_column', [$this, 'render_language_column'], 10, 2);
            add_action('manage_pages_custom_column', [$this, 'render_language_column'], 10, 2);
            add_action('admin_head', [$this, 'language_column_css']);
            // Language filter dropdown and query filtering
            add_action('restrict_manage_posts', [$this, 'admin_language_filter_dropdown']);
            add_action('pre_get_posts', [$this, 'admin_filter_posts_by_language']);
            // Adjust status counts (All/Published/Draft/Trash) when language filter is active
            add_filter('views_edit-post', [$this, 'filter_post_status_counts_by_language']);
            add_filter('views_edit-page', [$this, 'filter_post_status_counts_by_language']);
        }
    }
    
    // =========================================================================
    // DATA LAYER: DB Table & Taxonomy
    // =========================================================================
    
    /**
     * Register the language taxonomy
     */
    public function register_taxonomy() {
        // Collect all public post types (including product, etc.)
        $post_types = array_keys(get_post_types(['public' => true], 'names'));
        if (empty($post_types)) {
            $post_types = ['post', 'page'];
        }

        if (taxonomy_exists(self::TAXONOMY)) {
            // Taxonomy already registered — ensure it covers all public post types
            foreach ($post_types as $pt) {
                if (!is_object_in_taxonomy($pt, self::TAXONOMY)) {
                    register_taxonomy_for_object_type(self::TAXONOMY, $pt);
                }
            }
            return;
        }
        
        register_taxonomy(self::TAXONOMY, $post_types, [
            'label' => __('Language', 'ai-translator-for-elementor-and-polylang'),
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'hierarchical' => false,
            'rewrite' => false,
            'query_var' => false,
        ]);
    }
    
    /**
     * Create the translation groups table if it doesn't exist
     */
    private function maybe_create_table() {
        $installed_version = get_option(self::OPTION_DB_VERSION, '0');
        
        if (version_compare($installed_version, self::DB_VERSION, '>=')) {
            return;
        }
        
        $this->create_table();
        update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
    }
    
    /**
     * Create the translation groups table
     */
    public function create_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            group_id BIGINT(20) UNSIGNED NOT NULL,
            post_id BIGINT(20) UNSIGNED NOT NULL,
            language_code VARCHAR(10) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY post_language (post_id, language_code),
            KEY group_id (group_id),
            KEY post_id (post_id)
        ) {$charset_collate};";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
    
    /**
     * Get the full table name with prefix
     * @return string
     */
    private function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }
    
    /**
     * Get the next available group ID
     * @return int
     */
    private function get_next_group_id() {
        global $wpdb;
        $table = $this->get_table_name();
        $max = $wpdb->get_var("SELECT MAX(group_id) FROM {$table}");
        return $max ? intval($max) + 1 : 1;
    }
    
    /**
     * Get the group ID for a post
     * @param int $post_id
     * @return int|null
     */
    private function get_post_group_id($post_id) {
        global $wpdb;
        $table = $this->get_table_name();
        $group_id = $wpdb->get_var($wpdb->prepare(
            "SELECT group_id FROM {$table} WHERE post_id = %d LIMIT 1",
            $post_id
        ));
        return $group_id ? intval($group_id) : null;
    }
    
    // =========================================================================
    // LANGUAGE MANAGEMENT (Admin API)
    // =========================================================================
    
    /**
     * Add a language to the built-in system
     * 
     * @param string $code Language code (e.g., 'fr')
     * @param string $name English name (e.g., 'French')
     * @param string $native_name Native name (e.g., 'Français')
     * @param bool $is_default Whether this is the default language
     * @return bool
     */
    public function add_language($code, $name, $native_name = '', $is_default = false) {
        $languages = $this->get_stored_languages();
        
        // Check if already exists
        foreach ($languages as $lang) {
            if ($lang['code'] === $code) {
                return false; // Already exists
            }
        }
        
        $languages[] = [
            'code' => sanitize_text_field($code),
            'name' => sanitize_text_field($name),
            'native_name' => sanitize_text_field($native_name ?: $name),
            'slug' => sanitize_text_field($code),
            'is_default' => $is_default,
        ];
        
        // If this is default, or if it's the first language, set as default
        if ($is_default || count($languages) === 1) {
            // Unset other defaults
            foreach ($languages as &$lang) {
                $lang['is_default'] = ($lang['code'] === $code);
            }
            update_option(self::OPTION_DEFAULT_LANG, $code);
        }
        
        update_option(self::OPTION_LANGUAGES, $languages);
        $this->cached_languages = null; // Clear cache
        
        // Ensure taxonomy is registered (may not be if called during AJAX)
        $this->register_taxonomy();
        
        // Ensure taxonomy term exists
        if (!term_exists($code, self::TAXONOMY)) {
            wp_insert_term($code, self::TAXONOMY, ['slug' => $code, 'description' => $name]);
        }
        
        // Note: caller is responsible for calling flush_rewrite_rules() after batch operations
        
        return true;
    }
    
    /**
     * Remove a language from the built-in system
     * 
     * @param string $code Language code
     * @return bool
     */
    public function remove_language($code) {
        $languages = $this->get_stored_languages();
        $default = $this->get_default_language();
        
        // Don't allow removing the default language if it's the only one
        if ($code === $default && count($languages) <= 1) {
            return false;
        }
        
        $languages = array_values(array_filter($languages, function($lang) use ($code) {
            return $lang['code'] !== $code;
        }));
        
        update_option(self::OPTION_LANGUAGES, $languages);
        $this->cached_languages = null;
        
        // If we removed the default, set a new default
        if ($code === $default && !empty($languages)) {
            $languages[0]['is_default'] = true;
            update_option(self::OPTION_LANGUAGES, $languages);
            update_option(self::OPTION_DEFAULT_LANG, $languages[0]['code']);
        }
        
        flush_rewrite_rules();
        
        return true;
    }
    
    /**
     * Set the default language
     * 
     * @param string $code Language code
     * @return bool
     */
    public function set_default_language($code) {
        $languages = $this->get_stored_languages();
        $found = false;
        
        foreach ($languages as &$lang) {
            $lang['is_default'] = ($lang['code'] === $code);
            if ($lang['code'] === $code) {
                $found = true;
            }
        }
        
        if (!$found) {
            return false;
        }
        
        update_option(self::OPTION_LANGUAGES, $languages);
        update_option(self::OPTION_DEFAULT_LANG, $code);
        $this->cached_languages = null;
        
        return true;
    }
    
    /**
     * Get languages from wp_options (raw stored format)
     * @return array
     */
    private function get_stored_languages() {
        if ($this->cached_languages !== null) {
            return $this->cached_languages;
        }
        $this->cached_languages = get_option(self::OPTION_LANGUAGES, []);
        return $this->cached_languages;
    }
    
    /**
     * Enable the built-in language system
     */
    public function enable() {
        update_option(self::OPTION_ENABLED, true);
        $this->maybe_create_table();
    }
    
    /**
     * Disable the built-in language system
     */
    public function disable() {
        update_option(self::OPTION_ENABLED, false);
    }
    
    /**
     * Check if built-in system is explicitly enabled
     * @return bool
     */
    public static function is_enabled() {
        return (bool) get_option(self::OPTION_ENABLED, false);
    }
    
    // =========================================================================
    // MULTILINGUAL INTERFACE IMPLEMENTATION
    // =========================================================================
    
    /**
     * @inheritDoc
     */
    public function get_plugin_name() {
        return 'AI Translator Built-in';
    }
    
    /**
     * @inheritDoc
     */
    public function is_active() {
        return self::is_enabled() && !empty($this->get_stored_languages());
    }
    
    /**
     * @inheritDoc
     */
    public function get_available_languages() {
        $stored = $this->get_stored_languages();
        $languages = [];
        
        foreach ($stored as $lang_data) {
            $obj = new stdClass();
            $obj->slug = $lang_data['code'];
            $obj->code = $lang_data['code'];
            $obj->name = $lang_data['name'];
            $obj->native_name = $lang_data['native_name'] ?? $lang_data['name'];
            $obj->is_default = !empty($lang_data['is_default']);
            $obj->flag = ''; // Flag URL can be added later
            $languages[$lang_data['code']] = $obj;
        }
        
        return $languages;
    }
    
    /**
     * @inheritDoc
     */
    public function get_default_language() {
        $default = get_option(self::OPTION_DEFAULT_LANG, null);
        if ($default) {
            return $default;
        }
        
        // Fallback: find from languages array
        $languages = $this->get_stored_languages();
        foreach ($languages as $lang) {
            if (!empty($lang['is_default'])) {
                return $lang['code'];
            }
        }
        
        // Fallback: first language
        if (!empty($languages)) {
            return $languages[0]['code'];
        }
        
        return null;
    }
    
    /**
     * @inheritDoc
     */
    public function get_post_language($post_id) {
        $terms = wp_get_object_terms($post_id, self::TAXONOMY, ['fields' => 'slugs']);
        
        if (!is_wp_error($terms) && !empty($terms)) {
            return $terms[0];
        }
        
        // Fallback: check translation groups DB table (handles products or
        // posts whose taxonomy term was not set due to earlier bugs)
        global $wpdb;
        $table = $this->get_table_name();
        $lang = $wpdb->get_var($wpdb->prepare(
            "SELECT language_code FROM {$table} WHERE post_id = %d LIMIT 1",
            $post_id
        ));
        
        if ($lang) {
            // Auto-heal: set taxonomy term for future lookups
            $this->set_post_language($post_id, $lang);
            return $lang;
        }
        
        // Posts without an explicit language are assumed to be in the default language
        return $this->get_default_language();
    }
    
    /**
     * @inheritDoc
     */
    public function set_post_language($post_id, $lang_code) {
        // Ensure the term exists
        if (!term_exists($lang_code, self::TAXONOMY)) {
            $lang_name = $lang_code;
            $languages = $this->get_stored_languages();
            foreach ($languages as $lang) {
                if ($lang['code'] === $lang_code) {
                    $lang_name = $lang['name'];
                    break;
                }
            }
            wp_insert_term($lang_code, self::TAXONOMY, ['slug' => $lang_code, 'description' => $lang_name]);
        }
        
        $result = wp_set_object_terms($post_id, $lang_code, self::TAXONOMY);
        return !is_wp_error($result);
    }
    
    /**
     * @inheritDoc
     */
    public function get_post_translations($post_id) {
        global $wpdb;
        $table = $this->get_table_name();
        
        // Get the group_id for this post
        $group_id = $this->get_post_group_id($post_id);
        if (!$group_id) {
            // If no group, return just this post with its language
            $lang = $this->get_post_language($post_id);
            if ($lang) {
                return [$lang => $post_id];
            }
            return [];
        }
        
        // Get all posts in this group
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, language_code FROM {$table} WHERE group_id = %d",
            $group_id
        ));
        
        $translations = [];
        foreach ($results as $row) {
            // Verify the post still exists and is not trashed/deleted
            $post_status = get_post_status($row->post_id);
            if ($post_status && $post_status !== 'trash') {
                $translations[$row->language_code] = intval($row->post_id);
            }
        }
        
        return $translations;
    }
    
    /**
     * @inheritDoc
     */
    public function setup_translation_relationship($original_post_id, $translated_post_id, $target_language) {
        global $wpdb;
        $table = $this->get_table_name();
        
        // Normalize language code
        $lang_code = $this->normalize_language_code($target_language);
        if (!$lang_code) {
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log("Cannot normalize language: {$target_language}", 'error', $original_post_id);
            }
            return new WP_Error('invalid_language', "Cannot normalize language: {$target_language}");
        }
        
        // Verify language exists in our system
        if (!$this->language_exists($lang_code)) {
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log("Language '{$lang_code}' not configured in built-in system.", 'error', $original_post_id);
            }
            return new WP_Error('language_not_found', "Language '{$lang_code}' not configured.");
        }
        
        // Get original's language, set default if missing
        $original_lang = $this->get_post_language($original_post_id);
        if (!$original_lang) {
            $default_lang = $this->get_default_language();
            if ($default_lang) {
                $this->set_post_language($original_post_id, $default_lang);
                $original_lang = $default_lang;
            } else {
                return new WP_Error('no_default_language', 'Cannot determine default language.');
            }
        }
        
        // Set language on translated post
        $this->set_post_language($translated_post_id, $lang_code);
        
        // Get or create translation group
        $group_id = $this->get_post_group_id($original_post_id);
        if (!$group_id) {
            $group_id = $this->get_next_group_id();
            
            // Add original to group
            $wpdb->replace($table, [
                'group_id' => $group_id,
                'post_id' => $original_post_id,
                'language_code' => $original_lang,
            ], ['%d', '%d', '%s']);
        }
        
        // Add/update translated post in the group
        // First remove any existing entry for this post
        $wpdb->delete($table, ['post_id' => $translated_post_id], ['%d']);
        
        // Also remove any existing entry for this language in this group (replacing previous translation)
        $wpdb->delete($table, [
            'group_id' => $group_id,
            'language_code' => $lang_code,
        ], ['%d', '%s']);
        
        // Insert the new relationship
        $inserted = $wpdb->insert($table, [
            'group_id' => $group_id,
            'post_id' => $translated_post_id,
            'language_code' => $lang_code,
        ], ['%d', '%d', '%s']);
        
        if ($inserted === false) {
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log("Failed to insert translation group entry: " . $wpdb->last_error, 'error', $original_post_id);
            }
            return new WP_Error('db_error', 'Failed to save translation relationship.');
        }
        
        if (class_exists('AITRFOEL_Logger')) {
            AITRFOEL_Logger::log(
                sprintf('✅ Built-in relationship created: post %d (%s) → post %d (%s), group %d',
                    $original_post_id, $original_lang, $translated_post_id, $lang_code, $group_id
                ),
                'success',
                $original_post_id,
                $translated_post_id
            );
        }
        
        return true;
    }
    
    /**
     * @inheritDoc
     */
    public function get_translation_status($post_id) {
        $status = [];
        $translations = $this->get_post_translations($post_id);
        $available_languages = $this->get_available_languages();
        
        foreach ($available_languages as $lang) {
            $status[$lang->slug] = [
                'language_name' => $lang->name,
                'language_code' => $lang->slug,
                'translated' => isset($translations[$lang->slug]),
                'post_id' => $translations[$lang->slug] ?? null,
                'edit_url' => isset($translations[$lang->slug]) ? get_edit_post_link($translations[$lang->slug]) : null,
                'view_url' => isset($translations[$lang->slug]) ? get_permalink($translations[$lang->slug]) : null,
                'status' => isset($translations[$lang->slug]) ? get_post_status($translations[$lang->slug]) : null,
            ];
        }
        
        return $status;
    }
    
    /**
     * @inheritDoc
     */
    public function language_exists($lang_code) {
        $languages = $this->get_stored_languages();
        foreach ($languages as $lang) {
            if ($lang['code'] === $lang_code || $lang['slug'] === $lang_code) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * @inheritDoc
     */
    public function get_language_code($our_language) {
        return $this->get_code_from_name($our_language);
    }
    
    /**
     * @inheritDoc
     */
    public function remove_translation_relationship($post_id) {
        global $wpdb;
        $table = $this->get_table_name();
        
        try {
            // Remove this post from its translation group
            $wpdb->delete($table, ['post_id' => $post_id], ['%d']);
            
            // Remove language taxonomy
            wp_set_object_terms($post_id, [], self::TAXONOMY);
            
            return true;
        } catch (Exception $e) {
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log(
                    sprintf('Failed to remove translation relationship for post %d: %s', $post_id, $e->getMessage()),
                    'error',
                    $post_id
                );
            }
            return false;
        }
    }
    
    /**
     * @inheritDoc
     */
    public function get_pages_translation_status() {
        if (!$this->is_active()) {
            return [];
        }
        
        $post_types = ['page', 'post'];
        if (class_exists('WooCommerce') && get_option('aitrfoel_woocommerce_support', false)) {
            $post_types[] = 'product';
        }
        $pages = get_posts([
            'post_type' => $post_types,
            'posts_per_page' => -1,
            'post_status' => ['publish', 'draft'],
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        
        $pages_status = [];
        
        foreach ($pages as $page) {
            $translation_status = $this->get_translation_status($page->ID);
            $elementor_data = get_post_meta($page->ID, '_elementor_data', true);
            
            $pages_status[] = [
                'id' => $page->ID,
                'title' => $page->post_title,
                'status' => $page->post_status,
                'edit_url' => get_edit_post_link($page->ID),
                'view_url' => get_permalink($page->ID),
                'translations' => $translation_status,
                'has_elementor' => !empty($elementor_data),
            ];
        }
        
        return $pages_status;
    }
    
    /**
     * @inheritDoc
     */
    public function get_language_switcher_data() {
        if (!$this->is_active()) {
            return [];
        }
        
        $languages = $this->get_available_languages();
        $default_lang = $this->get_default_language();
        $current_post_id = get_queried_object_id();
        $translations = $current_post_id ? $this->get_post_translations($current_post_id) : [];
        $hide_untranslated = get_option('aitrfoel_switcher_hide_untranslated', false);
        
        $switcher_data = [];
        
        foreach ($languages as $lang) {
            $has_translation = isset($translations[$lang->slug]);
            
            // Skip untranslated languages if setting is enabled (always show default & current)
            if ($hide_untranslated && $current_post_id && !$has_translation
                && $lang->slug !== $default_lang && $lang->slug !== $this->get_current_language()) {
                continue;
            }
            
            $url = trailingslashit(get_option('home'));
            
            // If we have a translation of the current page, link to it
            if ($has_translation) {
                $url = $this->get_post_permalink($translations[$lang->slug]);
            } elseif ($lang->slug === $default_lang && $current_post_id) {
                // Switching to default language: link to the original post
                // Find the default-language post in the translation group
                if (isset($translations[$default_lang])) {
                    $url = get_permalink($translations[$default_lang]);
                } else {
                    // Current post might be the default-language post itself
                    $post_lang = $this->get_post_language($current_post_id);
                    if ($post_lang === $default_lang) {
                        $url = get_permalink($current_post_id);
                    }
                }
            } elseif ($lang->slug !== $default_lang) {
                // Link to the language homepage
                $raw_home = trailingslashit(get_option('home'));
                $url = $raw_home . $lang->slug . '/';
            } elseif ($lang->slug === $default_lang && get_option('aitrfoel_default_lang_prefix', false)) {
                // Default language with prefix enabled
                $raw_home = trailingslashit(get_option('home'));
                $url = $raw_home . $lang->slug . '/';
            }
            
            $switcher_data[] = [
                'code' => $lang->slug,
                'name' => $lang->name,
                'native_name' => $lang->native_name ?? $lang->name,
                'flag' => $lang->flag ?? '',
                'is_default' => $lang->slug === $default_lang,
                'is_current' => $lang->slug === $this->get_current_language(),
                'url' => $url,
            ];
        }
        
        return $switcher_data;
    }
    
    /**
     * Get the correct permalink for a post with language prefix
     * Note: The filter_permalink/filter_page_permalink hooks already add language
     * prefixes when get_permalink() is called, so we just return get_permalink().
     * @inheritDoc
     */
    public function get_post_permalink($post_id) {
        return get_permalink($post_id);
    }
    
    // =========================================================================
    // URL REWRITING & LANGUAGE DETECTION
    // =========================================================================
    
    /**
     * Add rewrite rules for language prefixes
     */
    public function add_rewrite_rules() {
        if (!$this->is_active()) {
            return;
        }
        
        $languages = $this->get_stored_languages();
        $default_lang = $this->get_default_language();
        $include_default_prefix = get_option('aitrfoel_default_lang_prefix', false);
        
        $lang_codes = [];
        foreach ($languages as $lang) {
            if ($lang['code'] !== $default_lang || $include_default_prefix) {
                $lang_codes[] = preg_quote($lang['code'], '/');
            }
        }
        
        if (empty($lang_codes)) {
            return;
        }
        
        $lang_pattern = implode('|', $lang_codes);
        
        // Auto-flush rewrite rules when version changes OR when stored rules
        // are missing our language patterns (can happen after AJAX setup, reinstall, etc.)
        $rules_version = '1.5.9'; // Bump this when rewrite rules change
        $stored_version = get_option('aitrfoel_rewrite_rules_version', '');
        $needs_flush = ($stored_version !== $rules_version);
        
        if (!$needs_flush) {
            // Verify our language rules are actually present in stored rewrite rules
            $stored_rules = get_option('rewrite_rules', []);
            if (is_array($stored_rules)) {
                $test_pattern = '^(' . $lang_pattern . ')/?$';
                if (!isset($stored_rules[$test_pattern])) {
                    $needs_flush = true;
                }
            }
        }
        
        if ($needs_flush) {
            update_option('aitrfoel_rewrite_rules_version', $rules_version);
            // Schedule flush after all rules are registered
            add_action('wp_loaded', function() {
                flush_rewrite_rules();
            });
        }
        
        // === Rewrite rules use 'top' which adds to extra_rules_top array ===
        // WordPress extra_rules_top uses FIFO: FIRST added = FIRST checked = HIGHEST priority.
        // So we add from HIGHEST priority to LOWEST priority.
        
        // 1. HIGHEST PRIORITY: Custom post type rules (most specific multi-segment patterns)
        // Must be added FIRST so they're checked before the catch-all page rule.
        // Fixes 404 for WooCommerce products and other CPTs with language prefix
        // (e.g., /fr/product/my-product/ or /fr/boutique/produit/my-product/).
        $custom_post_types = get_post_types(['public' => true, '_builtin' => false], 'objects');
        foreach ($custom_post_types as $cpt) {
            $slug = isset($cpt->rewrite['slug']) ? $cpt->rewrite['slug'] : $cpt->name;
            // WooCommerce sets rewrite slug to '/product' (with leading slash) — strip it
            $slug = trim($slug, '/');
            
            if (empty($slug)) {
                continue;
            }

            // Single CPT post: /fr/product/my-product/
            add_rewrite_rule(
                '^(' . $lang_pattern . ')/' . preg_quote($slug, '/') . '/([^/]+)/?$',
                'index.php?lang=$matches[1]&' . $cpt->name . '=$matches[2]',
                'top'
            );

            // Archive: /fr/product/ (or /fr/shop/)
            if ($cpt->has_archive) {
                $archive_slug = $cpt->has_archive === true ? $slug : $cpt->has_archive;
                add_rewrite_rule(
                    '^(' . $lang_pattern . ')/' . preg_quote($archive_slug, '/') . '/?$',
                    'index.php?lang=$matches[1]&post_type=' . $cpt->name,
                    'top'
                );
                add_rewrite_rule(
                    '^(' . $lang_pattern . ')/' . preg_quote($archive_slug, '/') . '/page/([0-9]+)/?$',
                    'index.php?lang=$matches[1]&post_type=' . $cpt->name . '&paged=$matches[2]',
                    'top'
                );
            }
        }
        
        // 2. Tag: /fr/tag/slug/
        // Use get_taxonomy() to get the actual rewrite slug — this correctly handles:
        //   a) Custom tag base set in Settings > Permalinks ($wp_rewrite->tag_base)
        //   b) WordPress core translation of 'tag' base (e.g. $wp_rewrite->tag_base is
        //      empty but _x('tag','tag base') returns a localized slug). When category_base
        //      option is not set, WP uses _x() translation for the base, which $wp_rewrite
        //      does NOT expose — only the taxonomy rewrite['slug'] has the correct value.
        $tag_tax = get_taxonomy('post_tag');
        $tag_base = ($tag_tax && !empty($tag_tax->rewrite['slug']))
            ? trim($tag_tax->rewrite['slug'], '/')
            : 'tag';
        // Pagination first: /fr/tag/slug/page/2/
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/' . preg_quote($tag_base, '/') . '/(.+?)/page/([0-9]+)/?$',
            'index.php?lang=$matches[1]&tag=$matches[2]&paged=$matches[3]',
            'top'
        );
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/' . preg_quote($tag_base, '/') . '/(.+?)/?$',
            'index.php?lang=$matches[1]&tag=$matches[2]',
            'top'
        );

        // 3. Category: /fr/category/slug/ (or /fr/categorie/slug/ on French sites, etc.)
        // Same reasoning: read from get_taxonomy('category')->rewrite['slug'] not $wp_rewrite->category_base,
        // because when category_base option is empty, WP uses _x('category','category base') as the
        // slug — e.g. French core translates it to 'categorie'. $wp_rewrite->category_base stays ''
        // so reading it would fall back to the English 'category' and generate wrong rules.
        $cat_tax = get_taxonomy('category');
        $cat_base = ($cat_tax && !empty($cat_tax->rewrite['slug']))
            ? trim($cat_tax->rewrite['slug'], '/')
            : 'category';
        // Pagination first: /fr/categorie/slug/page/2/
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/' . preg_quote($cat_base, '/') . '/(.+?)/page/([0-9]+)/?$',
            'index.php?lang=$matches[1]&category_name=$matches[2]&paged=$matches[3]',
            'top'
        );
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/' . preg_quote($cat_base, '/') . '/(.+?)/?$',
            'index.php?lang=$matches[1]&category_name=$matches[2]',
            'top'
        );

        // 3b. Custom taxonomy terms — WooCommerce product categories, product tags, etc.
        // Handles: /fr/product-category/slug/ and hierarchical /fr/product-category/parent/child/
        // Must be added before the catch-all page rule but after specific CPT rules.
        $public_taxonomies = get_taxonomies(['public' => true, '_builtin' => false], 'objects');
        foreach ($public_taxonomies as $taxonomy) {
            if (empty($taxonomy->rewrite) || empty($taxonomy->rewrite['slug']) || empty($taxonomy->query_var)) {
                continue;
            }
            $tax_slug = trim($taxonomy->rewrite['slug'], '/');
            if (empty($tax_slug)) {
                continue;
            }
            // Pagination first (higher priority): /fr/product-category/slug/page/2/
            add_rewrite_rule(
                '^(' . $lang_pattern . ')/' . preg_quote($tax_slug, '/') . '/(.+?)/page/([0-9]+)/?$',
                'index.php?lang=$matches[1]&' . $taxonomy->query_var . '=$matches[2]&paged=$matches[3]',
                'top'
            );
            // Term archive: /fr/product-category/slug/ (supports parent/child hierarchy via .+?)
            add_rewrite_rule(
                '^(' . $lang_pattern . ')/' . preg_quote($tax_slug, '/') . '/(.+?)/?$',
                'index.php?lang=$matches[1]&' . $taxonomy->query_var . '=$matches[2]',
                'top'
            );
        }

        // 4. Feed: /fr/feed/
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/feed/(feed|rdf|rss|rss2|atom)/?$',
            'index.php?lang=$matches[1]&feed=$matches[2]',
            'top'
        );
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/feed/?$',
            'index.php?lang=$matches[1]&feed=feed',
            'top'
        );
        
        // 5. Pagination: /fr/page/2/
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/page/([0-9]+)/?$',
            'index.php?lang=$matches[1]&paged=$matches[2]',
            'top'
        );
        
        // Search: /fr/?s=query (handled by query var, no special rewrite needed)

        // 5b. Paginated pages with /page/ base: /fr/blog/page/2/
        // Standard WordPress pagination for the posts page and static pages.
        // e.g. /fr/blog/page/2/ → lang=fr, pagename=blog, paged=2
        // Also handles translated pagination bases (e.g. 'pagina' in some locales).
        global $wp_rewrite;
        $pagination_base = (isset($wp_rewrite) && !empty($wp_rewrite->pagination_base))
            ? trim($wp_rewrite->pagination_base, '/')
            : 'page';
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/([^/]+)/' . preg_quote($pagination_base, '/') . '/([0-9]+)/?$',
            'index.php?lang=$matches[1]&pagename=$matches[2]&paged=$matches[3]',
            'top'
        );

        // 5c. Paginated pages WITHOUT /page/ base: /fr/blog/2/
        // Some themes generate blog pagination as /{posts-page-slug}/{num}/ without
        // a 'page' segment in between. The second segment is purely numeric to avoid
        // conflicting with CPT/taxonomy slugs (which are handled earlier with higher priority).
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/([^/]+)/([0-9]+)/?$',
            'index.php?lang=$matches[1]&pagename=$matches[2]&paged=$matches[3]',
            'top'
        );

        // 5d. WooCommerce / plugin endpoint URLs: /fr/page-slug/endpoint/ (and /value/)
        // e.g. /fr/mon-espace-lavie/commandes/, /fr/mon-espace-lavie/editer-adresse/facturation/
        // WooCommerce registers endpoints via add_rewrite_endpoint() at init:20, so
        // $wp_rewrite->endpoints is fully populated by the time we reach init:25 here.
        // Adding explicit rules per endpoint is more reliable than parse_request fixups because
        // WordPress resolves pagename + endpoint query var in one step, matching how it handles
        // these URLs without the lang prefix.
        if (!empty($wp_rewrite->endpoints) && is_array($wp_rewrite->endpoints)) {
            foreach ($wp_rewrite->endpoints as $ep) {
                if (!is_array($ep) || count($ep) < 2) {
                    continue;
                }
                $ep_places = (int) $ep[0];
                $ep_slug   = (string) $ep[1];
                // $ep[2] is always a string by the time WP stores it (never boolean true)
                $ep_qvar   = (isset($ep[2]) && $ep[2] !== '') ? (string) $ep[2] : $ep_slug;

                // Only handle page-based endpoints (EP_PAGES = 4)
                if (!($ep_places & EP_PAGES) || $ep_slug === '') {
                    continue;
                }

                $ep_slug_q = preg_quote($ep_slug, '/');

                // /fr/page-slug/endpoint/value/ — endpoint with an optional value
                add_rewrite_rule(
                    '^(' . $lang_pattern . ')/([^/]+)/' . $ep_slug_q . '/([^/]*)/?$',
                    'index.php?lang=$matches[1]&pagename=$matches[2]&' . $ep_qvar . '=$matches[3]',
                    'top'
                );
                // /fr/page-slug/endpoint/ — endpoint without a value
                add_rewrite_rule(
                    '^(' . $lang_pattern . ')/([^/]+)/' . $ep_slug_q . '/?$',
                    'index.php?lang=$matches[1]&pagename=$matches[2]&' . $ep_qvar . '=',
                    'top'
                );
            }
        }

        // 6. Posts with permalink front base: /fr/blog/post-slug/
        // Mirrors common permalink structures like /blog/%postname%/ so prefixed
        // default-language post URLs resolve instead of falling through to pages.
        if (isset($wp_rewrite) && !empty($wp_rewrite->permalink_structure)) {
            $permalink_structure = trim($wp_rewrite->permalink_structure, '/');
            if (strpos($permalink_structure, '%postname%') !== false) {
                $front_base = trim(str_replace('%postname%', '', $permalink_structure), '/');
                if ($front_base !== '' && strpos($front_base, '%') === false) {
                    add_rewrite_rule(
                        '^(' . $lang_pattern . ')/' . preg_quote($front_base, '/') . '/([^/]+)/?$',
                        'index.php?lang=$matches[1]&name=$matches[2]',
                        'top'
                    );
                }
            }
        }

        // 7. Posts with language prefix: /fr/post-slug/ (single-segment slugs)
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/([^/]+)/?$',
            'index.php?lang=$matches[1]&name=$matches[2]',
            'top'
        );
        
        // 8. Language root: /fr/
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/?$',
            'index.php?lang=$matches[1]',
            'top'
        );
        
        // 9. LOWEST PRIORITY: Catch-all for pages/hierarchical content: /fr/parent/child/
        // Must be LAST added so it's checked after all specific patterns above.
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/(.+?)/?$',
            'index.php?lang=$matches[1]&pagename=$matches[2]',
            'top'
        );
    }
    
    /**
     * Register 'lang' as a query variable
     * @param array $vars
     * @return array
     */
    public function register_query_vars($vars) {
        $vars[] = 'lang';
        return $vars;
    }
    
    /**
     * Detect language from the incoming request
     * @param WP $wp
     */
    public function detect_language_from_request($wp) {
        if (!$this->is_active()) {
            return;
        }
        
        if (isset($wp->query_vars['lang'])) {
            $lang = sanitize_text_field($wp->query_vars['lang']);
            if ($this->language_exists($lang)) {
                $this->current_language = $lang;
            }
        }
        
        // If we matched a 'name' query var (single-segment slug), check if it's
        // actually a page rather than a post. WordPress rewrite matched the
        // single-segment rule first (name=), but it may be a page.
        if (isset($wp->query_vars['name']) && isset($wp->query_vars['lang'])) {
            $slug = $wp->query_vars['name'];
            // Check if a page with this slug exists
            $page = get_page_by_path($slug, OBJECT, 'page');
            if ($page) {
                // Switch from post lookup to page lookup
                $wp->query_vars['pagename'] = $slug;
                $wp->query_vars['page_id'] = $page->ID;
                unset($wp->query_vars['name']);
            }
        }
        
        // Language-root URL (e.g. /en/) with a static front page configured:
        // WordPress doesn't know to show the front page because the rewrite rule
        // only sets lang=. Manually point to the static front page (or its translation).
        // IMPORTANT: only apply when the ONLY query var set is 'lang' — if any
        // other content var is set (product_cat, post_type, name, etc.) it means
        // the URL matched a content rule, not the language-root rule, and we must
        // not override it with the front page.
        if (isset($wp->query_vars['lang'])
            && get_option('show_on_front') === 'page'
        ) {
            // Build the set of non-trivial query vars (exclude lang itself and
            // WordPress internal vars that are always present or not content).
            $ignore_vars = ['lang' => 1, 'error' => 1, 'preview' => 1, 'robots' => 1,
                            'favicon' => 1, 'sitemap' => 1, 'sitemap-stylesheet' => 1];
            $content_vars = array_filter(
                array_diff_key($wp->query_vars, $ignore_vars),
                function($v) { return $v !== '' && $v !== false && $v !== null && $v !== 0; }
            );

            if (empty($content_vars)) {
                $front_page_id = (int) get_option('page_on_front');
                if ($front_page_id) {
                    $request_lang = sanitize_text_field($wp->query_vars['lang']);
                    $default_lang = $this->get_default_language();

                    // If visiting a non-default language root, find the translated front page
                    if ($request_lang && $request_lang !== $default_lang) {
                        $translations = $this->get_post_translations($front_page_id);
                        if (isset($translations[$request_lang])) {
                            $front_page_id = $translations[$request_lang];
                        }
                    }

                    $wp->query_vars['page_id'] = $front_page_id;
                }
            }
        }

        // Handle sub-path URLs caught by the catch-all pagename rule that are actually
        // WooCommerce endpoint URLs (or other plugin endpoint URLs registered via
        // add_rewrite_endpoint). The catch-all maps /fr/mon-compte/commandes/ to
        // pagename=mon-compte/commandes. No child page exists so WP 404s.
        // We detect: base segment = real page, remaining segment = registered endpoint slug,
        // then set page_id + endpoint query var so WooCommerce (and other plugins) resolve.
        if (isset($wp->query_vars['lang']) && isset($wp->query_vars['pagename'])) {
            $pagename = $wp->query_vars['pagename'];

            if (strpos($pagename, '/') !== false) {
                // Only act if no real hierarchical page exists at the full path
                if (!get_page_by_path($pagename)) {
                    // Split into base page slug + the rest (endpoint path)
                    $slash_pos      = strpos($pagename, '/');
                    $base_slug      = substr($pagename, 0, $slash_pos);
                    $endpoint_path  = substr($pagename, $slash_pos + 1);
                    $base_page      = get_page_by_path($base_slug);

                    if ($base_page) {
                        $matched_qv    = null;
                        $matched_value = '';

                        // 1. Check WooCommerce endpoint slugs (handles translated slugs too)
                        if (function_exists('WC') && WC()->query) {
                            foreach (WC()->query->get_query_vars() as $query_var => $slug) {
                                if (!is_string($slug) || $slug === '') {
                                    continue;
                                }
                                if ($endpoint_path === $slug) {
                                    $matched_qv    = $query_var;
                                    $matched_value = '';
                                    break;
                                }
                                // Endpoint with sub-value: editer-adresse/facturation
                                if (strpos($endpoint_path, $slug . '/') === 0) {
                                    $matched_qv    = $query_var;
                                    $matched_value = substr($endpoint_path, strlen($slug) + 1);
                                    break;
                                }
                            }
                        }

                        // 2. Fallback: check any endpoint registered via add_rewrite_endpoint()
                        //    (WooCommerce, WP core, other plugins). Stored in $wp_rewrite->endpoints
                        //    as [bitmask, slug, query_var].
                        if ($matched_qv === null) {
                            global $wp_rewrite;
                            if (!empty($wp_rewrite->endpoints) && is_array($wp_rewrite->endpoints)) {
                                foreach ($wp_rewrite->endpoints as $ep) {
                                    $slug = isset($ep[1]) ? (string) $ep[1] : '';
                                    $qvar = isset($ep[2]) ? (string) $ep[2] : $slug;
                                    if ($slug === '') {
                                        continue;
                                    }
                                    if ($endpoint_path === $slug) {
                                        $matched_qv    = $qvar;
                                        $matched_value = '';
                                        break;
                                    }
                                    if (strpos($endpoint_path, $slug . '/') === 0) {
                                        $matched_qv    = $qvar;
                                        $matched_value = substr($endpoint_path, strlen($slug) + 1);
                                        break;
                                    }
                                }
                            }
                        }

                        if ($matched_qv !== null) {
                            $page_id = (int) $base_page->ID;

                            // Translate page to current language if not default
                            $request_lang = sanitize_text_field($wp->query_vars['lang']);
                            $default_lang  = $this->get_default_language();
                            if ($request_lang && $request_lang !== $default_lang) {
                                $translations = $this->get_post_translations($page_id);
                                if (isset($translations[$request_lang])) {
                                    $page_id = (int) $translations[$request_lang];
                                }
                            }

                            $wp->query_vars['page_id']   = $page_id;
                            $wp->query_vars[$matched_qv] = $matched_value;
                            unset($wp->query_vars['pagename']);
                        }
                    }
                }
            }
        }

        if (!$this->current_language) {
            $this->current_language = $this->get_default_language();
        }
    }
    
    /**
     * Get the currently active frontend language
     * @return string
     */
    public function get_current_language() {
        if ($this->current_language) {
            return $this->current_language;
        }
        return $this->get_default_language();
    }
    
    /**
     * Redirect unprefixed default-language URLs to their prefixed version
     * when the aitrfoel_default_lang_prefix option is enabled.
     * E.g., /my-page/ → /fr/my-page/ if French is the default language.
     */
    public function maybe_redirect_default_lang_prefix() {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }
        
        if (!get_option('aitrfoel_default_lang_prefix', false)) {
            return;
        }
        
        if (!$this->is_active()) {
            return;
        }
        
        $default_lang = $this->get_default_language();
        
        // If we already detected a language from URL prefix, no redirect needed
        if ($this->current_language && $this->current_language !== $default_lang) {
            return;
        }
        
        // Check if the URL already has a language prefix
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
        $home_path = wp_parse_url(home_url(), PHP_URL_PATH) ?: '';
        $relative = $request_uri;
        if ($home_path && strpos($relative, $home_path) === 0) {
            $relative = substr($relative, strlen($home_path));
        }
        $relative = ltrim($relative, '/');
        
        // If already prefixed with default lang, no redirect
        if (strpos($relative, $default_lang . '/') === 0 || $relative === $default_lang) {
            return;
        }
        
        // Check if prefixed with any other language
        $languages = $this->get_stored_languages();
        foreach ($languages as $lang) {
            if ($lang['code'] !== $default_lang && (strpos($relative, $lang['code'] . '/') === 0 || $relative === $lang['code'])) {
                return; // Already has a different language prefix
            }
        }
        
        // This is an unprefixed URL that should be the default language — redirect
        $redirect_url = home_url('/' . $default_lang . '/' . $relative);
        wp_safe_redirect($redirect_url, 301);
        exit;
    }
    
    /**
     * Prevent WordPress canonical redirect from stripping the language prefix.
     * Without this, /en/ (front page) gets redirected to / by WordPress core,
     * causing a redirect loop with maybe_redirect_default_lang_prefix().
     *
     * @param string $redirect_url  The canonical redirect URL.
     * @param string $requested_url The original requested URL.
     * @return string|false
     */
    public function filter_redirect_canonical($redirect_url, $requested_url) {
        if (!$this->is_active() || !$this->current_language) {
            return $redirect_url;
        }
        
        // If the current URL has a language prefix, don't let WP strip it
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
        $home_path = wp_parse_url(home_url(), PHP_URL_PATH) ?: '';
        $relative = $request_uri;
        if ($home_path && strpos($relative, $home_path) === 0) {
            $relative = substr($relative, strlen($home_path));
        }
        $relative = ltrim($relative, '/');
        
        $languages = $this->get_stored_languages();
        foreach ($languages as $lang) {
            if (strpos($relative, $lang['code'] . '/') === 0 || $relative === $lang['code']) {
                // URL has a valid language prefix — block canonical redirect
                return false;
            }
        }
        
        return $redirect_url;
    }
    
    /**
     * Filter WordPress locale based on the current frontend language.
     * This makes WooCommerce, other plugins, and WordPress core
     * output their UI strings in the correct language.
     *
     * @param string $locale Current WordPress locale
     * @return string Modified locale
     */
    public function filter_locale($locale) {
        if (!$this->current_language) {
            return $locale;
        }
        
        $default_lang = $this->get_default_language();
        if ($this->current_language === $default_lang) {
            return $locale;
        }
        
        $wp_locale = $this->get_wp_locale($this->current_language);
        return $wp_locale ?: $locale;
    }
    
    /**
     * Map a short language code to WordPress locale code.
     * E.g. 'cs' → 'cs_CZ', 'de' → 'de_DE', 'ru' → 'ru_RU'
     *
     * @param string $lang_code Short language code
     * @return string|null WordPress locale or null if unknown
     */
    private function get_wp_locale($lang_code) {
        $map = [
            'af' => 'af',       'ar' => 'ar',       'az' => 'az',
            'be' => 'bel',      'bg' => 'bg_BG',    'bn' => 'bn_BD',
            'bs' => 'bs_BA',    'ca' => 'ca',       'cs' => 'cs_CZ',
            'cy' => 'cy',       'da' => 'da_DK',    'de' => 'de_DE',
            'el' => 'el',       'en' => 'en_US',    'es' => 'es_ES',
            'et' => 'et',       'eu' => 'eu',       'fa' => 'fa_IR',
            'fi' => 'fi',       'fr' => 'fr_FR',    'ga' => 'ga',
            'gl' => 'gl_ES',    'he' => 'he_IL',    'hi' => 'hi_IN',
            'hr' => 'hr',       'hu' => 'hu_HU',    'hy' => 'hy',
            'id' => 'id_ID',    'is' => 'is_IS',    'it' => 'it_IT',
            'ja' => 'ja',       'ka' => 'ka_GE',    'kk' => 'kk',
            'km' => 'km',       'ko' => 'ko_KR',    'lo' => 'lo',
            'lt' => 'lt_LT',    'lv' => 'lv',       'mk' => 'mk_MK',
            'mn' => 'mn',       'ms' => 'ms_MY',    'my' => 'my_MM',
            'nb' => 'nb_NO',    'ne' => 'ne_NP',    'nl' => 'nl_NL',
            'nn' => 'nn_NO',    'pl' => 'pl_PL',    'pt' => 'pt_PT',
            'ro' => 'ro_RO',    'ru' => 'ru_RU',    'si' => 'si_LK',
            'sk' => 'sk_SK',    'sl' => 'sl_SI',    'sq' => 'sq',
            'sr' => 'sr_RS',    'sv' => 'sv_SE',    'sw' => 'sw',
            'ta' => 'ta_IN',    'te' => 'te',       'th' => 'th',
            'tl' => 'tl',       'tr' => 'tr_TR',    'uk' => 'uk',
            'ur' => 'ur',       'uz' => 'uz_UZ',    'vi' => 'vi',
            'zh' => 'zh_CN',    'an' => 'an',       'as' => 'as',
            'lb' => 'lb_LU',
        ];
        
        return $map[$lang_code] ?? null;
    }
    
    /**
     * Filter WordPress/WooCommerce page ID options to return translated page ID.
     * Ensures is_front_page(), is_shop(), is_cart(), etc. work correctly
     * when viewing a translated version of these pages.
     *
     * @param mixed  $pre_value Pre-filter value (false = not filtered yet).
     * @param string $option    Option name.
     * @return mixed Page ID of translated version, or $pre_value to use default.
     */
    public function filter_translated_page_option($pre_value, $option) {
        if (!$this->is_active()) {
            return $pre_value;
        }

        $current_lang = $this->get_current_language();
        $default_lang = $this->get_default_language();

        if (!$current_lang || $current_lang === $default_lang) {
            return $pre_value;
        }

        // Prevent infinite loop: temporarily remove this filter while reading the option
        static $filtering = [];
        if (isset($filtering[$option])) {
            return $pre_value;
        }
        $filtering[$option] = true;

        $original_page_id = (int) get_option($option);
        unset($filtering[$option]);

        if ($original_page_id <= 0) {
            return $pre_value;
        }

        $translations = $this->get_post_translations($original_page_id);
        if (isset($translations[$current_lang])) {
            $translated_id = (int) $translations[$current_lang];
            if ($translated_id > 0 && get_post_status($translated_id) === 'publish') {
                return $translated_id;
            }
        }

        return $pre_value;
    }

    /**
     * Filter home_url to include current language prefix.
     * Ensures logo links, home links etc. point to the correct language.
     *
     * @param string $url
     * @param string $path
     * @param string|null $orig_scheme
     * @param int|null $blog_id
     * @return string
     */
    public function filter_home_url($url, $path, $orig_scheme = null, $blog_id = null) {
        if (!$this->is_active()) {
            return $url;
        }
        
        // Only prefix the root home URL (empty path or just "/").
        // Do NOT prefix when WordPress is building post/page permalinks
        // (e.g. home_url('/my-page/')) — our permalink filters handle those.
        $trimmed_path = trim($path, '/');
        if ($trimmed_path !== '') {
            return $url;
        }
        
        $current_lang = $this->get_current_language();
        $default_lang = $this->get_default_language();
        
        // Don't prefix for default language
        if (!$current_lang || $current_lang === $default_lang) {
            return $url;
        }
        
        // Only for pretty permalinks
        if (empty(get_option('permalink_structure', ''))) {
            return add_query_arg('lang', $current_lang, $url);
        }
        
        // Use raw home option to avoid recursion
        $raw_home = trailingslashit(get_option('home'));
        
        return $raw_home . $current_lang . '/';
    }
    
    /**
     * Filter permalink to add language prefix (for posts)
     * @param string $permalink
     * @param WP_Post|int $post
     * @return string
     */
    public function filter_permalink($permalink, $post) {
        if (!$this->is_active()) {
            return $permalink;
        }
        
        $post_id = is_object($post) ? $post->ID : $post;
        $lang = $this->get_post_language($post_id);
        $default_lang = $this->get_default_language();
        
        if (!$lang) {
            return $permalink;
        }
        
        if ($lang === $default_lang && !get_option('aitrfoel_default_lang_prefix', false)) {
            return $permalink;
        }
        
        // Plain permalinks: use ?lang= query parameter instead of URL prefix
        if (empty(get_option('permalink_structure', ''))) {
            return add_query_arg('lang', $lang, $permalink);
        }
        
        // Use raw home URL to avoid double-prefix from filter_home_url
        $home_url = trailingslashit(get_option('home'));
        if (strpos($permalink, $home_url) === 0) {
            // Don't double-add language prefix
            $relative = substr($permalink, strlen($home_url));
            $lang_codes = array_column($this->get_stored_languages(), 'code');
            foreach ($lang_codes as $code) {
                if (strpos($relative, $code . '/') === 0) {
                    return $permalink; // Already prefixed
                }
            }
            $permalink = $home_url . $lang . '/' . ltrim($relative, '/');
        }
        
        return $permalink;
    }
    
    /**
     * Filter page permalink to add language prefix
     * @param string $permalink
     * @param int $page_id
     * @return string
     */
    public function filter_page_permalink($permalink, $page_id) {
        if (!$this->is_active()) {
            return $permalink;
        }
        
        $lang = $this->get_post_language($page_id);
        $default_lang = $this->get_default_language();
        
        if (!$lang) {
            return $permalink;
        }
        
        if ($lang === $default_lang && !get_option('aitrfoel_default_lang_prefix', false)) {
            return $permalink;
        }
        
        // Plain permalinks: use ?lang= query parameter instead of URL prefix
        if (empty(get_option('permalink_structure', ''))) {
            return add_query_arg('lang', $lang, $permalink);
        }
        
        // If this page is a translation of the static front page, use /lang/ as permalink
        $front_page_id = (int) get_option('page_on_front');
        if ($front_page_id && get_option('show_on_front') === 'page') {
            $translations = $this->get_post_translations($front_page_id);
            if ($page_id == $front_page_id || in_array($page_id, $translations, false)) {
                $raw_home = trailingslashit(get_option('home'));
                return $raw_home . $lang . '/';
            }
        }
        
        // Use raw home URL to avoid double-prefix from filter_home_url
        $home_url = trailingslashit(get_option('home'));
        if (strpos($permalink, $home_url) === 0) {
            $relative = substr($permalink, strlen($home_url));
            $lang_codes = array_column($this->get_stored_languages(), 'code');
            foreach ($lang_codes as $code) {
                if (strpos($relative, $code . '/') === 0) {
                    return $permalink;
                }
            }
            $permalink = $home_url . $lang . '/' . ltrim($relative, '/');
        }
        
        return $permalink;
    }
    
    /**
     * Filter frontend queries to show only current language posts
     * @param WP_Query $query
     */
    public function filter_frontend_queries($query) {
        if (!$this->is_active() || is_admin() || !$query->is_main_query()) {
            return;
        }
        
        $current_lang = $this->get_current_language();
        if (!$current_lang) {
            return;
        }
        
        // Filter archives, search, blog index, taxonomy archives
        if ($query->is_archive() || $query->is_home() || $query->is_search() || $query->is_tax()) {
            $default_lang = $this->get_default_language();
            $terms = [$current_lang];
            
            // WooCommerce product fallback: include default language products
            // so untranslated products still appear on shop/category pages
            $is_product_fallback = false;
            if ($current_lang !== $default_lang && class_exists('WooCommerce') && get_option('aitrfoel_woo_product_fallback', true)) {
                $queried_post_type = $query->get('post_type');
                $is_product = ($queried_post_type === 'product')
                    || (is_array($queried_post_type) && in_array('product', $queried_post_type, true));
                if ($is_product) {
                    $terms[] = $default_lang;
                    $is_product_fallback = true;
                }
            }
            
            $tax_query = $query->get('tax_query') ?: [];
            $tax_query[] = [
                'taxonomy' => self::TAXONOMY,
                'field'    => 'slug',
                'terms'    => $terms,
            ];
            $query->set('tax_query', $tax_query);
            
            // Deduplicate: remove default-lang products that have a current-lang translation
            if ($is_product_fallback) {
                add_filter('the_posts', [$this, 'deduplicate_fallback_products'], 10, 2);
            }
        }
        
        // For single posts/pages: if language prefix is in URL but post is in a different language,
        // WordPress will handle the 404 naturally. We just ensure the current language is set.
    }

    /**
     * Remove default-language products from results when a current-language translation exists.
     * Runs once as a the_posts filter after WooCommerce product fallback query.
     *
     * @param array    $posts Array of WP_Post objects.
     * @param WP_Query $query The query object.
     * @return array
     */
    public function deduplicate_fallback_products($posts, $query) {
        remove_filter('the_posts', [$this, 'deduplicate_fallback_products'], 10);

        if (empty($posts)) {
            return $posts;
        }

        $current_lang = $this->get_current_language();
        $default_lang = $this->get_default_language();

        // Collect IDs of default-lang source posts that have a translated version in this result set
        $translated_source_ids = [];
        foreach ($posts as $post) {
            $lang = $this->get_post_language($post->ID);
            if ($lang === $current_lang) {
                // This is a translated product — find its source (default lang) post
                $translations = $this->get_post_translations($post->ID);
                if (isset($translations[$default_lang])) {
                    $translated_source_ids[] = (int) $translations[$default_lang];
                }
            }
        }

        // Remove default-lang products whose translation is already in the results
        if (!empty($translated_source_ids)) {
            $posts = array_values(array_filter($posts, function($post) use ($translated_source_ids, $default_lang) {
                $lang = $this->get_post_language($post->ID);
                return !($lang === $default_lang && in_array((int) $post->ID, $translated_source_ids, true));
            }));
        }

        return $posts;
    }

    /**
     * Filter a single term to show its translated name on the frontend.
     * Reads from term meta `_aitrfoel_name_{lang}` set during translation.
     *
     * @param WP_Term $term     Term object.
     * @param string  $taxonomy Taxonomy slug.
     * @return WP_Term
     */
    public function filter_term_name($term, $taxonomy) {
        if (!is_object($term) || !isset($term->term_id)) {
            return $term;
        }
        
        // Skip our own language taxonomy
        if ($taxonomy === self::TAXONOMY) {
            return $term;
        }
        
        $current_lang = $this->get_current_language();
        $default_lang = $this->get_default_language();
        
        // No need to filter for the default language
        if (!$current_lang || $current_lang === $default_lang) {
            return $term;
        }
        
        $translated_name = get_term_meta($term->term_id, '_aitrfoel_name_' . $current_lang, true);
        if (!empty($translated_name)) {
            $term->name = $translated_name;
        }
        
        return $term;
    }
    
    /**
     * Filter an array of terms to show translated names on the frontend.
     *
     * @param array         $terms      Array of found terms.
     * @param array|null    $taxonomies Array of taxonomy slugs (may be null).
     * @param array         $query_vars Query variables.
     * @param WP_Term_Query $term_query Term query object.
     * @return array
     */
    public function filter_terms_names($terms, $taxonomies, $query_vars, $term_query = null) {
        if (empty($terms)) {
            return $terms;
        }
        
        $current_lang = $this->get_current_language();
        $default_lang = $this->get_default_language();
        
        if (!$current_lang || $current_lang === $default_lang) {
            return $terms;
        }
        
        foreach ($terms as &$term) {
            if (!is_object($term) || !isset($term->term_id)) {
                continue;
            }
            
            // Skip our language taxonomy
            if (isset($term->taxonomy) && $term->taxonomy === self::TAXONOMY) {
                continue;
            }
            
            $translated_name = get_term_meta($term->term_id, '_aitrfoel_name_' . $current_lang, true);
            if (!empty($translated_name)) {
                $term->name = $translated_name;
            }
        }
        unset($term);
        
        return $terms;
    }
    
    // =========================================================================
    // SEO: HREFLANG TAGS
    // =========================================================================
    
    /**
     * Output hreflang link tags in <head>
     */
    public function output_hreflang_tags() {
        if (!$this->is_active() || is_admin()) {
            return;
        }
        
        $post_id = get_queried_object_id();
        if (!$post_id) {
            return;
        }
        
        $translations = $this->get_post_translations($post_id);
        if (count($translations) < 2) {
            return;
        }
        
        echo "\n<!-- AI Translator: hreflang tags -->\n";
        foreach ($translations as $lang_code => $trans_post_id) {
            $url = $this->get_post_permalink($trans_post_id);
            printf(
                '<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
                esc_attr($lang_code),
                esc_url($url)
            );
        }
        
        // x-default points to default language version
        $default_lang = $this->get_default_language();
        if (isset($translations[$default_lang])) {
            printf(
                '<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
                esc_url($this->get_post_permalink($translations[$default_lang]))
            );
        }
        echo "<!-- /AI Translator: hreflang tags -->\n";
    }
    
    // =========================================================================
    // SITEMAP INTEGRATION
    // =========================================================================
    
    /**
     * Filter WordPress core sitemap queries to only include posts in the default
     * language. Translated posts are discoverable via hreflang tags on original pages.
     *
     * @param array  $args      WP_Query arguments for sitemap.
     * @param string $post_type Post type being queried.
     * @return array
     */
    public function filter_sitemap_query($args, $post_type) {
        if (!$this->is_active()) {
            return $args;
        }
        
        $default_lang = $this->get_default_language();
        if (!$default_lang) {
            return $args;
        }
        
        $tax_query = isset($args['tax_query']) ? $args['tax_query'] : [];
        $tax_query[] = [
            'taxonomy' => self::TAXONOMY,
            'field'    => 'slug',
            'terms'    => $default_lang,
        ];
        $args['tax_query'] = $tax_query;
        
        return $args;
    }
    
    // =========================================================================
    // ADMIN: POSTS LIST LANGUAGE COLUMN
    // =========================================================================
    
    /**
     * Add language column to posts/pages list
     * @param array $columns
     * @return array
     */
    public function add_language_column($columns) {
        if (!$this->is_active()) {
            return $columns;
        }
        
        // Insert after title column
        $new_columns = [];
        foreach ($columns as $key => $label) {
            $new_columns[$key] = $label;
            if ($key === 'title') {
                $new_columns['aitrfoel_language'] = '<span style="font-size:11px;">' . __('Lang', 'ai-translator-for-elementor-and-polylang') . '</span>';
            }
        }
        return $new_columns;
    }
    
    /**
     * Render language column content
     * @param string $column
     * @param int $post_id
     */
    public function render_language_column($column, $post_id) {
        if ($column !== 'aitrfoel_language') {
            return;
        }
        
        $lang = $this->get_post_language($post_id);
        if ($lang) {
            $default = $this->get_default_language();
            echo '<span style="font-size:11px;white-space:nowrap;">' . esc_html(strtoupper($lang));
            if ($lang === $default) {
                echo ' <small style="color:#888;">(' . esc_html__('def', 'ai-translator-for-elementor-and-polylang') . ')</small>';
            }
            echo '</span>';
        } else {
            echo '<span style="color: #ccc;">—</span>';
        }
    }
    
    /**
     * Output CSS to constrain the language column width
     */
    public function language_column_css() {
        echo '<style>.column-aitrfoel_language{width:50px!important;text-align:center;}</style>';
    }
    
    /**
     * Add a language filter dropdown to the admin posts/pages list.
     */
    public function admin_language_filter_dropdown() {
        if (!$this->is_active()) {
            return;
        }
        
        global $typenow;
        if (!in_array($typenow, ['post', 'page'], true)) {
            return;
        }
        
        $languages = $this->get_stored_languages();
        if (empty($languages)) {
            return;
        }
        
        $selected = isset($_GET['aitrfoel_lang']) ? sanitize_text_field($_GET['aitrfoel_lang']) : '';
        $default_lang = $this->get_default_language();
        $default_name = strtoupper($default_lang);
        foreach ($languages as $lang) {
            if ($lang['code'] === $default_lang) {
                $default_name = $lang['name'];
                break;
            }
        }
        
        // Preserve post_status when changing language filter (e.g. in Trash view)
        $post_status = isset($_GET['post_status']) ? sanitize_text_field($_GET['post_status']) : '';
        
        // In Trash with no explicit language filter, default to "All Languages"
        if ($post_status === 'trash' && $selected === '') {
            $selected = 'all';
        }
        if ($post_status) {
            echo '<input type="hidden" name="post_status" value="' . esc_attr($post_status) . '">';
        }
        
        echo '<select name="aitrfoel_lang">';
        echo '<option value="">' . esc_html($default_name) . ' (' . esc_html__('default', 'ai-translator-for-elementor-and-polylang') . ')</option>';
        $all_sel = selected($selected, 'all', false);
        echo '<option value="all"' . $all_sel . '>' . esc_html__('All Languages', 'ai-translator-for-elementor-and-polylang') . '</option>';
        foreach ($languages as $lang) {
            if ($lang['code'] === $default_lang) {
                continue; // Already shown as default option
            }
            $sel = selected($selected, $lang['code'], false);
            echo '<option value="' . esc_attr($lang['code']) . '"' . $sel . '>' . esc_html($lang['name']) . '</option>';
        }
        echo '</select>';
    }
    
    /**
     * Filter admin post queries by selected language.
     */
    public function admin_filter_posts_by_language($query) {
        if (!$this->is_active() || !is_admin() || !$query->is_main_query()) {
            return;
        }
        
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->base !== 'edit') {
            return;
        }
        
        $lang = isset($_GET['aitrfoel_lang']) ? sanitize_text_field($_GET['aitrfoel_lang']) : '';
        
        if ($lang === 'all') {
            // Show everything, no filtering
            return;
        }
        
        if (!empty($lang) && $this->language_exists($lang)) {
            // Specific language selected — filter by that taxonomy term
            $tax_query = $query->get('tax_query') ?: [];
            $tax_query[] = [
                'taxonomy' => self::TAXONOMY,
                'field'    => 'slug',
                'terms'    => $lang,
                'include_children' => false,
            ];
            $query->set('tax_query', $tax_query);
            return;
        }
        
        // In Trash view with no language filter, show all languages by default
        $post_status = isset($_GET['post_status']) ? sanitize_text_field($_GET['post_status']) : '';
        if ($post_status === 'trash') {
            return;
        }
        
        // Default view (no param): exclude non-default language posts
        // This hides translations while showing default-language posts
        // (including untagged legacy posts)
        $non_default_langs = [];
        $default_lang = $this->get_default_language();
        foreach ($this->get_stored_languages() as $l) {
            if ($l['code'] !== $default_lang) {
                $non_default_langs[] = $l['code'];
            }
        }
        if (!empty($non_default_langs)) {
            $tax_query = $query->get('tax_query') ?: [];
            $tax_query[] = [
                'taxonomy' => self::TAXONOMY,
                'field'    => 'slug',
                'terms'    => $non_default_langs,
                'operator' => 'NOT IN',
                'include_children' => false,
            ];
            $query->set('tax_query', $tax_query);
        }
    }
    
    /**
     * Adjust post status view counts when a language filter is active.
     * Without this, the All/Published/Draft/Trash counts show totals across all languages.
     *
     * @param array $views Associative array of status links (HTML).
     * @return array Modified views with corrected counts.
     */
    public function filter_post_status_counts_by_language($views) {
        if (!$this->is_active()) {
            return $views;
        }
        
        $lang = isset($_GET['aitrfoel_lang']) ? sanitize_text_field($_GET['aitrfoel_lang']) : '';
        $post_status = isset($_GET['post_status']) ? sanitize_text_field($_GET['post_status']) : '';
        
        // Only adjust counts when filtering by a specific language or default view
        if ($lang === 'all') {
            return $views;
        }
        
        global $typenow;
        $post_type = $typenow ?: 'post';
        $default_lang = $this->get_default_language();
        
        // In Trash with no lang filter, show all — no need to adjust
        if ($post_status === 'trash' && empty($lang)) {
            return $views;
        }
        
        // Build the tax_query for counting
        if (!empty($lang) && $this->language_exists($lang)) {
            // Specific language: count posts with this taxonomy term
            $tax_query = [
                [
                    'taxonomy' => self::TAXONOMY,
                    'field'    => 'slug',
                    'terms'    => $lang,
                    'include_children' => false,
                ],
            ];
        } else {
            // Default view: count posts NOT in non-default languages
            $non_default_langs = [];
            foreach ($this->get_stored_languages() as $l) {
                if ($l['code'] !== $default_lang) {
                    $non_default_langs[] = $l['code'];
                }
            }
            if (empty($non_default_langs)) {
                return $views;
            }
            $tax_query = [
                [
                    'taxonomy' => self::TAXONOMY,
                    'field'    => 'slug',
                    'terms'    => $non_default_langs,
                    'operator' => 'NOT IN',
                    'include_children' => false,
                ],
            ];
        }
        
        // Count posts per status with language filter
        $statuses = ['publish', 'draft', 'pending', 'trash', 'private'];
        $counts = [];
        $total = 0;
        
        foreach ($statuses as $status) {
            $q = new \WP_Query([
                'post_type'      => $post_type,
                'post_status'    => $status,
                'tax_query'      => $tax_query,
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => false,
            ]);
            $counts[$status] = $q->found_posts;
            if ($status !== 'trash') {
                $total += $q->found_posts;
            }
        }
        
        // Update view links with corrected counts
        foreach ($views as $key => &$html) {
            if ($key === 'all') {
                $html = preg_replace('/\(<span class="count">\(?\d+\)?\s*<\/span>\)/', '(<span class="count">(' . $total . ')</span>)', $html);
                // Also fix plain count format
                $html = preg_replace('/class="count">\(\d+\)/', 'class="count">(' . $total . ')', $html);
            } elseif (isset($counts[$key])) {
                $html = preg_replace('/class="count">\(\d+\)/', 'class="count">(' . $counts[$key] . ')', $html);
            }
            
            // Remove status links with zero count (except current)
            if ($key !== 'all' && isset($counts[$key]) && $counts[$key] === 0) {
                unset($views[$key]);
            }
        }
        unset($html);
        
        return $views;
    }
    
    // =========================================================================
    // CLEANUP
    // =========================================================================
    
    /**
     * Remove all built-in language data (for uninstall)
     */
    public static function uninstall() {
        global $wpdb;
        
        // Drop translation groups table
        $table = $wpdb->prefix . self::TABLE_NAME;
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
        
        // Remove options
        delete_option(self::OPTION_LANGUAGES);
        delete_option(self::OPTION_DEFAULT_LANG);
        delete_option(self::OPTION_ENABLED);
        delete_option(self::OPTION_DB_VERSION);
        delete_option(self::OPTION_URL_STRUCTURE);
        
        // Remove taxonomy terms and associations
        $terms = get_terms(['taxonomy' => self::TAXONOMY, 'hide_empty' => false, 'fields' => 'ids']);
        if (!is_wp_error($terms)) {
            foreach ($terms as $term_id) {
                wp_delete_term($term_id, self::TAXONOMY);
            }
        }
    }
    
    // =========================================================================
    // LANGUAGE SWITCHER WIDGET & SHORTCODE
    // =========================================================================
    
    /**
     * Register the language switcher shortcode
     */
    public function register_shortcode() {
        add_shortcode('aitrfoel_language_switcher', [$this, 'render_language_switcher_shortcode']);
    }
    
    /**
     * Register the language switcher widget
     */
    public function register_widget() {
        register_widget('AITRFOEL_Language_Switcher_Widget');
    }
    
    /**
     * Append language switcher links to a nav menu if the theme location
     * is configured via the 'aitrfoel_nav_menu_switcher' option.
     *
     * Enable with: update_option('aitrfoel_nav_menu_switcher', true);
     *
     * @param string   $items  HTML menu items.
     * @param stdClass $args   Menu display arguments.
     * @return string
     */
    public function add_language_switcher_to_menu($items, $args) {
        if (!$this->is_active()) {
            return $items;
        }
        
        if (!get_option('aitrfoel_switcher_enabled', true)) {
            return $items;
        }
        
        // Only add to menus if enabled via option
        $enabled = get_option('aitrfoel_nav_menu_switcher', false);
        if (!$enabled) {
            return $items;
        }
        
        // Optionally restrict to specific menu location
        $location = get_option('aitrfoel_nav_menu_location', '');
        if ($location && $args->theme_location !== $location) {
            return $items;
        }
        
        $switcher_data = $this->get_language_switcher_data();
        if (empty($switcher_data)) {
            return $items;
        }
        
        $show_flags = get_option('aitrfoel_switcher_show_flags', true);
        $show_names = get_option('aitrfoel_switcher_show_names', true);
        
        foreach ($switcher_data as $lang) {
            $class = 'menu-item menu-item-language';
            if ($lang['is_current']) {
                $class .= ' current-language';
            }
            $flag_html = $show_flags ? AITRFOEL_Language_Mapper::get_flag_html($lang['code'], 16, 'margin-right: 4px;') : '';
            $name_html = $show_names ? esc_html($this->get_language_display_name($lang)) : '';
            if (!$show_flags && !$show_names) {
                $name_html = esc_html(strtoupper($lang['code']));
            }
            $items .= sprintf(
                '<li class="%s"><a href="%s">%s%s</a></li>',
                esc_attr($class),
                esc_url($lang['url']),
                $flag_html,
                $name_html
            );
        }
        
        return $items;
    }
    
    /**
     * Register REST API fields for language and translations on posts/pages.
     */
    public function register_rest_fields() {
        $post_types = get_post_types(['public' => true], 'names');
        
        foreach ($post_types as $post_type) {
            // Language field
            register_rest_field($post_type, 'language', [
                'get_callback' => function ($object) {
                    return $this->get_post_language($object['id']) ?: $this->get_default_language();
                },
                'update_callback' => function ($value, $object) {
                    if ($value && $this->language_exists($value)) {
                        $this->set_post_language($object->ID, $value);
                    }
                },
                'schema' => [
                    'description' => __('Post language code', 'ai-translator-for-elementor-and-polylang'),
                    'type'        => 'string',
                    'context'     => ['view', 'edit'],
                ],
            ]);
            
            // Translations field (read-only)
            register_rest_field($post_type, 'translations', [
                'get_callback' => function ($object) {
                    $translations = $this->get_post_translations($object['id']);
                    $result = [];
                    foreach ($translations as $lang_code => $post_id) {
                        $result[$lang_code] = [
                            'post_id'   => $post_id,
                            'permalink' => $this->get_post_permalink($post_id),
                        ];
                    }
                    return $result;
                },
                'schema' => [
                    'description' => __('Translations of this post', 'ai-translator-for-elementor-and-polylang'),
                    'type'        => 'object',
                    'context'     => ['view', 'edit'],
                    'readonly'    => true,
                ],
            ]);
        }
    }
    
    /**
     * Get the display name for a language based on settings
     * 
     * @param array $lang Language data with code, name, native_name keys
     * @return string The formatted display name
     */
    private function get_language_display_name($lang) {
        return self::resolve_language_display_name($lang);
    }
    
    /**
     * Static version for use in settings preview
     * 
     * @param array $lang Language data with code, name, native_name keys
     * @return string The formatted display name
     */
    public static function resolve_language_display_name($lang) {
        // Check custom names first
        $custom_names = get_option('aitrfoel_switcher_custom_names', []);
        if (!empty($custom_names[$lang['code']])) {
            return $custom_names[$lang['code']];
        }
        
        $format = get_option('aitrfoel_switcher_name_display', 'native');
        
        switch ($format) {
            case 'english':
                return $lang['name'] ?? strtoupper($lang['code']);
            case 'code':
                return strtoupper($lang['code']);
            case 'code_lower':
                return strtolower($lang['code']);
            case 'native':
            default:
                return $lang['native_name'] ?? $lang['name'] ?? strtoupper($lang['code']);
        }
    }
    
    /**
     * Render language switcher via shortcode
     * 
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function render_language_switcher_shortcode($atts = []) {
        if (!get_option('aitrfoel_switcher_enabled', true)) {
            return '';
        }
        
        $atts = shortcode_atts([
            'style' => get_option('aitrfoel_switcher_style', 'flags-text'),
            'show_names' => get_option('aitrfoel_switcher_show_names', true) ? 'true' : 'false',
            'show_flags' => get_option('aitrfoel_switcher_show_flags', true) ? 'true' : 'false',
        ], $atts);
        
        $switcher_data = $this->get_language_switcher_data();
        if (empty($switcher_data) || count($switcher_data) < 2) {
            return '';
        }
        
        // Enqueue frontend switcher styles
        $this->enqueue_switcher_frontend_styles();
        
        ob_start();
        
        $show_flags = ($atts['show_flags'] === 'true' || $atts['show_flags'] === '1');
        $show_names = ($atts['show_names'] === 'true' || $atts['show_names'] === '1');
        
        if ($atts['style'] === 'list') {
            echo '<ul class="aitrfoel-language-switcher aitrfoel-switcher-list">';
            foreach ($switcher_data as $lang) {
                $active_class = $lang['is_current'] ? ' active' : '';
                $flag_html = $show_flags ? AITRFOEL_Language_Mapper::get_flag_html($lang['code'], 20, 'margin-right: 6px;') : '';
                $name_html = $show_names ? esc_html($this->get_language_display_name($lang)) : '';
                if (!$show_flags && !$show_names) {
                    $name_html = esc_html(strtoupper($lang['code']));
                }
                printf(
                    '<li class="aitrfoel-switcher-item%s"><a href="%s">%s%s</a></li>',
                    esc_attr($active_class),
                    esc_url($lang['url']),
                    $flag_html,
                    $name_html
                );
            }
            echo '</ul>';
        } elseif ($atts['style'] === 'flags') {
            echo '<div class="aitrfoel-language-switcher aitrfoel-switcher-flags">';
            foreach ($switcher_data as $lang) {
                $active_class = $lang['is_current'] ? ' active' : '';
                $flag_html = AITRFOEL_Language_Mapper::get_flag_html($lang['code'], 24, '');
                printf(
                    '<a href="%s" class="aitrfoel-flag-link%s" title="%s">%s</a>',
                    esc_url($lang['url']),
                    esc_attr($active_class),
                    esc_attr($lang['name']),
                    $flag_html
                );
            }
            echo '</div>';
        } elseif ($atts['style'] === 'flags-text') {
            echo '<div class="aitrfoel-language-switcher aitrfoel-switcher-flags-text">';
            foreach ($switcher_data as $lang) {
                $active_class = $lang['is_current'] ? ' active' : '';
                $flag_html = $show_flags ? AITRFOEL_Language_Mapper::get_flag_html($lang['code'], 20, 'margin-right: 4px;') : '';
                $name_html = $show_names ? esc_html($this->get_language_display_name($lang)) : '';
                if (!$show_flags && !$show_names) {
                    $name_html = esc_html(strtoupper($lang['code']));
                }
                printf(
                    '<a href="%s" class="aitrfoel-flag-text-link%s" title="%s">%s%s</a>',
                    esc_url($lang['url']),
                    esc_attr($active_class),
                    esc_attr($lang['name']),
                    $flag_html,
                    $name_html ? '<span>' . $name_html . '</span>' : ''
                );
            }
            echo '</div>';
        } else {
            // Custom dropdown with flag images (native <select> can't render HTML)
            $current_lang = null;
            foreach ($switcher_data as $lang) {
                if ($lang['is_current']) { $current_lang = $lang; break; }
            }
            if (!$current_lang) $current_lang = $switcher_data[0];
            
            $current_flag = $show_flags ? AITRFOEL_Language_Mapper::get_flag_html($current_lang['code'], 20, 'margin-right: 6px;') : '';
            $current_label = $show_names ? esc_html($this->get_language_display_name($current_lang)) : '';
            if (!$show_flags && !$show_names) {
                $current_label = esc_html(strtoupper($current_lang['code']));
            }
            
            echo '<div class="aitrfoel-language-switcher aitrfoel-switcher-dropdown-wrapper">';
            echo '<button type="button" class="aitrfoel-dropdown-trigger" aria-expanded="false" aria-haspopup="listbox">';
            echo $current_flag . '<span>' . $current_label . '</span>';
            echo '<svg class="aitrfoel-dropdown-arrow" width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M3 5l3 3 3-3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            echo '</button>';
            echo '<ul class="aitrfoel-dropdown-list" role="listbox" style="display:none;">';
            foreach ($switcher_data as $lang) {
                $active_class = $lang['is_current'] ? ' active' : '';
                $flag_html = $show_flags ? AITRFOEL_Language_Mapper::get_flag_html($lang['code'], 20, 'margin-right: 6px;') : '';
                $label = $show_names ? esc_html($this->get_language_display_name($lang)) : '';
                if (!$show_flags && !$show_names) {
                    $label = esc_html(strtoupper($lang['code']));
                }
                printf(
                    '<li role="option" class="aitrfoel-dropdown-item%s"><a href="%s">%s%s</a></li>',
                    esc_attr($active_class),
                    esc_url($lang['url']),
                    $flag_html,
                    $label
                );
            }
            echo '</ul>';
            echo '</div>';
            
            // Inline JS for dropdown toggle (no jQuery dependency)
            echo '<script>
(function(){
    var wrappers = document.querySelectorAll(".aitrfoel-switcher-dropdown-wrapper");
    wrappers.forEach(function(w){
        var btn = w.querySelector(".aitrfoel-dropdown-trigger");
        var list = w.querySelector(".aitrfoel-dropdown-list");
        if(!btn||!list) return;
        btn.addEventListener("click",function(e){
            e.stopPropagation();
            var open = list.style.display !== "none";
            list.style.display = open ? "none" : "block";
            btn.setAttribute("aria-expanded", open ? "false" : "true");
        });
        document.addEventListener("click",function(){ list.style.display="none"; btn.setAttribute("aria-expanded","false"); });
    });
})();
</script>';
        }
        
        return ob_get_clean();
    }
    
    /**
     * Output switcher CSS in <head> if nav menu switcher is enabled 
     * (since the shortcode may also appear on any page via widget/menu).
     * If the nav menu switcher is active, we always need styles loaded.
     */
    public function maybe_preload_switcher_css() {
        if (is_admin()) return;
        // If nav menu switcher is enabled, always preload CSS
        $nav_enabled = get_option('aitrfoel_nav_menu_switcher', false);
        if ($nav_enabled) {
            $this->enqueue_switcher_frontend_styles();
        }
    }
    
    /**
     * Enqueue language switcher frontend styles (inline CSS + custom CSS)
     */
    public function enqueue_switcher_frontend_styles() {
        static $enqueued = false;
        if ($enqueued) return;
        $enqueued = true;
        
        $default_css = '
/* AI Translator Language Switcher - CSS Custom Properties for theme overridability */
.aitrfoel-language-switcher {
    font-family: inherit;
    --aitrfoel-text-color: currentColor;
    --aitrfoel-text-hover-color: currentColor;
    --aitrfoel-active-bg: #0073aa;
    --aitrfoel-active-color: #fff;
    --aitrfoel-hover-bg: rgba(0,0,0,0.06);
    --aitrfoel-border-color: rgba(0,0,0,0.15);
    --aitrfoel-bg: transparent;
    --aitrfoel-dropdown-bg: #fff;
    --aitrfoel-dropdown-shadow: 0 4px 16px rgba(0,0,0,0.12);
}

/* List style */
.aitrfoel-switcher-list { list-style: none; padding: 0; margin: 0; display: flex; flex-wrap: wrap; gap: 4px; }
.aitrfoel-switcher-list .aitrfoel-switcher-item { margin: 0; }
.aitrfoel-switcher-list .aitrfoel-switcher-item a { display: flex; align-items: center; padding: 6px 12px; text-decoration: none; color: var(--aitrfoel-text-color); border-radius: 6px; transition: background-color 0.2s, color 0.2s; font-size: 14px; }
.aitrfoel-switcher-list .aitrfoel-switcher-item a:hover { background-color: var(--aitrfoel-hover-bg); color: var(--aitrfoel-text-hover-color); }
.aitrfoel-switcher-list .aitrfoel-switcher-item.active a { background-color: var(--aitrfoel-active-bg); color: var(--aitrfoel-active-color); font-weight: 600; }
.aitrfoel-switcher-list .aitrfoel-switcher-item.active a img { box-shadow: 0 0 0 2px rgba(255,255,255,0.5); border-radius: 2px; }

/* Flags only style */
.aitrfoel-switcher-flags { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.aitrfoel-flag-link { display: inline-flex; align-items: center; padding: 4px; border-radius: 4px; transition: transform 0.15s, box-shadow 0.15s; text-decoration: none; }
.aitrfoel-flag-link:hover { transform: scale(1.15); box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
.aitrfoel-flag-link.active { box-shadow: 0 0 0 2px var(--aitrfoel-active-bg); border-radius: 4px; }
.aitrfoel-flag-link img { display: block; border-radius: 2px; }

/* Flags + text style */
.aitrfoel-switcher-flags-text { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.aitrfoel-flag-text-link { display: inline-flex; align-items: center; padding: 4px 10px; text-decoration: none; color: var(--aitrfoel-text-color); border-radius: 6px; font-size: 13px; font-weight: 500; transition: background-color 0.2s; }
.aitrfoel-flag-text-link:hover { background-color: var(--aitrfoel-hover-bg); }
.aitrfoel-flag-text-link.active { background-color: var(--aitrfoel-active-bg); color: var(--aitrfoel-active-color); }
.aitrfoel-flag-text-link.active img { box-shadow: 0 0 0 1px rgba(255,255,255,0.5); }

/* Custom dropdown style */
.aitrfoel-switcher-dropdown-wrapper { display: inline-block; position: relative; }
.aitrfoel-dropdown-trigger { display: inline-flex; align-items: center; gap: 4px; padding: 6px 12px; border: 1px solid var(--aitrfoel-border-color); border-radius: 6px; font-size: 14px; font-family: inherit; background: var(--aitrfoel-bg); color: var(--aitrfoel-text-color); cursor: pointer; min-width: 120px; }
.aitrfoel-dropdown-trigger:hover { border-color: var(--aitrfoel-active-bg); }
.aitrfoel-dropdown-arrow { margin-left: auto; transition: transform 0.2s; flex-shrink: 0; }
.aitrfoel-dropdown-trigger[aria-expanded="true"] .aitrfoel-dropdown-arrow { transform: rotate(180deg); }
.aitrfoel-dropdown-list { position: absolute; top: 100%; left: 0; z-index: 9999; min-width: 100%; margin: 4px 0 0; padding: 4px 0; list-style: none; background: var(--aitrfoel-dropdown-bg); border: 1px solid var(--aitrfoel-border-color); border-radius: 6px; box-shadow: var(--aitrfoel-dropdown-shadow); }
.aitrfoel-dropdown-item a { display: flex; align-items: center; padding: 6px 12px; text-decoration: none; color: var(--aitrfoel-text-color); font-size: 14px; white-space: nowrap; transition: background-color 0.15s; }
.aitrfoel-dropdown-item a:hover { background-color: var(--aitrfoel-hover-bg); }
.aitrfoel-dropdown-item.active a { font-weight: 600; color: var(--aitrfoel-active-bg); }
';
        
        // Get custom CSS from settings
        $custom_css = get_option('aitrfoel_switcher_custom_css', '');
        
        $full_css = $default_css;
        if (!empty(trim($custom_css))) {
            $full_css .= "\n/* Custom Language Switcher CSS */\n" . $custom_css;
        }
        
        // Output in <head> via wp_head hook if possible, otherwise inline
        if (!did_action('wp_head') || doing_action('wp_head')) {
            echo '<style id="aitrfoel-language-switcher-css">' . $full_css . '</style>';
        } else {
            // Shortcode rendered after wp_head (e.g., in content) — output inline
            echo '<style id="aitrfoel-language-switcher-css">' . $full_css . '</style>';
        }
    }
    
    /**
     * Add language switcher to the WordPress admin bar / toolbar.
     * Shows the current language with a flag, and a dropdown of other languages.
     *
     * @param WP_Admin_Bar $wp_admin_bar
     */
    public function add_admin_bar_language_switcher($wp_admin_bar) {
        if (!$this->is_active()) {
            return;
        }
        
        $languages = $this->get_stored_languages();
        if (count($languages) < 2) {
            return;
        }
        
        $default_lang = $this->get_default_language();
        $current_lang = $default_lang;
        
        // Detect current language from the request
        if (is_admin()) {
            if (isset($_GET['aitrfoel_lang'])) {
                $admin_lang = sanitize_text_field($_GET['aitrfoel_lang']);
                if ($admin_lang === 'all') {
                    $current_lang = 'all';
                } elseif ($this->language_exists($admin_lang)) {
                    $current_lang = $admin_lang;
                }
            } elseif (isset($_GET['post_status']) && $_GET['post_status'] === 'trash') {
                // In Trash with no explicit language filter, show as "All Languages"
                $current_lang = 'all';
            }
        } else {
            $queried_lang = get_query_var('lang', '');
            if ($queried_lang && $this->language_exists($queried_lang)) {
                $current_lang = $queried_lang;
            }
        }
        
        // Find current language info
        if ($current_lang === 'all') {
            $current_lang_name = __('All Languages', 'ai-translator-for-elementor-and-polylang');
        } else {
            $current_lang_name = strtoupper($current_lang);
            foreach ($languages as $lang) {
                if ($lang['code'] === $current_lang) {
                    $current_lang_name = $lang['name'];
                    break;
                }
            }
        }
        
        // Get flag HTML for the current language
        $flag_html = '';
        if ($current_lang === 'all') {
            $flag_html = '🌐 ';
        } elseif (class_exists('AITRFOEL_Language_Mapper')) {
            $flag_url = AITRFOEL_Language_Mapper::get_flag_url($current_lang, 20);
            $flag_html = '<img src="' . esc_url($flag_url) . '" alt="" style="vertical-align:middle;margin-right:4px;width:16px;height:12px;border-radius:2px;">';
        }
        
        // Parent node: current language
        $wp_admin_bar->add_node([
            'id'    => 'aitrfoel-lang-switcher',
            'title' => $flag_html . '<span class="ab-label">' . esc_html($current_lang_name) . '</span>',
            'href'  => false,
            'meta'  => [
                'class' => 'aitrfoel-admin-bar-lang',
            ],
        ]);
        
        // "All Languages" option (first item)
        $all_href = home_url('/');
        if (is_admin()) {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            $post_type = ($screen && $screen->post_type) ? $screen->post_type : 'post';
            $admin_status = isset($_GET['post_status']) ? '&post_status=' . sanitize_text_field($_GET['post_status']) : '';
            $all_href = admin_url('edit.php?post_type=' . $post_type . '&aitrfoel_lang=all' . $admin_status);
        }
        $wp_admin_bar->add_node([
            'id'     => 'aitrfoel-lang-all',
            'parent' => 'aitrfoel-lang-switcher',
            'title'  => '🌐 ' . esc_html__('All Languages', 'ai-translator-for-elementor-and-polylang'),
            'href'   => $all_href,
            'meta'   => [
                'class' => 'aitrfoel-lang-all',
            ],
        ]);

        // Child nodes: individual languages
        foreach ($languages as $lang) {
            $lang_flag = '';
            if (class_exists('AITRFOEL_Language_Mapper')) {
                $lang_flag_url = AITRFOEL_Language_Mapper::get_flag_url($lang['code'], 20);
                $lang_flag = '<img src="' . esc_url($lang_flag_url) . '" alt="" style="vertical-align:middle;margin-right:4px;width:16px;height:12px;border-radius:2px;">';
            }
            
            // Build URL for this language
            if (is_admin()) {
                // In admin, filter the posts list by language
                $screen = function_exists('get_current_screen') ? get_current_screen() : null;
                $post_type = ($screen && $screen->post_type) ? $screen->post_type : 'post';
                $admin_status = isset($_GET['post_status']) ? '&post_status=' . sanitize_text_field($_GET['post_status']) : '';
                if ($lang['code'] === $default_lang) {
                    $url = admin_url('edit.php?post_type=' . $post_type . $admin_status);
                } else {
                    $url = admin_url('edit.php?post_type=' . $post_type . '&aitrfoel_lang=' . $lang['code'] . $admin_status);
                }
            } else {
                // On frontend, try to get the same page in this language
                $current_post_id = get_queried_object_id();
                if ($current_post_id) {
                    $translations = $this->get_post_translations($current_post_id);
                    if (isset($translations[$lang['code']])) {
                        $url = get_permalink($translations[$lang['code']]);
                    } else {
                        $url = ($lang['code'] === $default_lang) ? home_url('/') : home_url('/' . $lang['code'] . '/');
                    }
                } else {
                    $url = ($lang['code'] === $default_lang) ? home_url('/') : home_url('/' . $lang['code'] . '/');
                }
            }
            
            $is_current = ($lang['code'] === $current_lang);
            
            $wp_admin_bar->add_node([
                'id'     => 'aitrfoel-lang-' . $lang['code'],
                'parent' => 'aitrfoel-lang-switcher',
                'title'  => $lang_flag . esc_html($lang['name']) . ($is_current ? ' ✓' : ''),
                'href'   => esc_url($url),
                'meta'   => [
                    'class' => $is_current ? 'aitrfoel-lang-current' : '',
                ],
            ]);
        }
    }
    
    // =========================================================================
    // PER-LANGUAGE MENU ASSIGNMENT
    // =========================================================================
    
    /**
     * Output CSS for the admin bar language switcher to ensure inline layout.
     * Some themes override admin bar styles causing flag + text to stack vertically.
     */
    public function admin_bar_switcher_css() {
        if (!is_admin_bar_showing()) {
            return;
        }
        echo '<style id="aitrfoel-admin-bar-lang-css">'
            . '#wpadminbar .aitrfoel-admin-bar-lang > .ab-item { display: flex !important; align-items: center !important; }'
            . '#wpadminbar .aitrfoel-admin-bar-lang > .ab-item img { flex-shrink: 0; }'
            . '#wpadminbar .aitrfoel-admin-bar-lang .ab-submenu .ab-item { display: flex !important; align-items: center !important; }'
            . '#wpadminbar .aitrfoel-admin-bar-lang .ab-submenu .ab-item img { flex-shrink: 0; }'
            . '</style>';
    }

    /**
     * Filter wp_nav_menu_args to swap the menu based on current language.
     * Uses option 'aitrfoel_menu_assignments' which stores:
     * [ 'primary' => ['de' => 42, 'fr' => 43], 'footer' => ['de' => 44] ]
     * where keys are theme_location slugs and values map lang code → menu term_id.
     *
     * @param array $args wp_nav_menu arguments.
     * @return array
     */
    public function filter_nav_menu_by_language($args) {
        if (!$this->is_active()) {
            return $args;
        }
        
        $current_lang = $this->get_current_language();
        $default_lang = $this->get_default_language();
        
        // Only swap for non-default languages
        if ($current_lang === $default_lang) {
            return $args;
        }
        
        $assignments = get_option('aitrfoel_menu_assignments', []);
        if (!is_array($assignments)) {
            $assignments = [];
        }
        
        // Try by theme location first
        $location = isset($args['theme_location']) ? $args['theme_location'] : '';
        if (!empty($location) && !empty($assignments) && isset($assignments[$location][$current_lang])) {
            $menu_id = intval($assignments[$location][$current_lang]);
            if ($menu_id > 0) {
                $args['menu'] = $menu_id;
            }
            return $args;
        }
        
        // Fallback: match by menu ID/slug/object across all location assignments
        $nav_menu_locations = get_nav_menu_locations();
        $requested_menu = isset($args['menu']) ? $args['menu'] : '';
        if (!empty($requested_menu)) {
            $requested_menu_obj = wp_get_nav_menu_object($requested_menu);
            $requested_menu_id = $requested_menu_obj ? $requested_menu_obj->term_id : 0;
            
            if ($requested_menu_id) {
                // Check location assignments
                foreach ($nav_menu_locations as $loc => $loc_menu_id) {
                    if ((int) $loc_menu_id === $requested_menu_id && isset($assignments[$loc][$current_lang])) {
                        $translated_menu_id = intval($assignments[$loc][$current_lang]);
                        if ($translated_menu_id > 0) {
                            $args['menu'] = $translated_menu_id;
                            return $args;
                        }
                    }
                }
                
                // Check widget translations for nav_menu widgets
                $widget_translations = get_option('aitrfoel_widget_translations', []);
                foreach ($widget_translations as $wid => $lang_data) {
                    if (isset($lang_data[$current_lang]['nav_menu'])) {
                        // Verify this widget's source menu matches the requested menu
                        if (preg_match('/^nav_menu-(\d+)$/', $wid, $wm)) {
                            $widget_instances = get_option('widget_nav_menu', []);
                            $widget_num = (int) $wm[1];
                            if (isset($widget_instances[$widget_num]['nav_menu']) && (int) $widget_instances[$widget_num]['nav_menu'] === $requested_menu_id) {
                                $args['menu'] = intval($lang_data[$current_lang]['nav_menu']);
                                return $args;
                            }
                        }
                    }
                }
                
                // Final fallback: direct menu ID map (handles Elementor Nav Menu widgets
                // and other menus not assigned to any theme location)
                $menu_id_map = get_option('aitrfoel_menu_id_map', []);
                if (isset($menu_id_map[$requested_menu_id][$current_lang])) {
                    $mapped_id = intval($menu_id_map[$requested_menu_id][$current_lang]);
                    if ($mapped_id > 0) {
                        $args['menu'] = $mapped_id;
                        return $args;
                    }
                }
            }
        }
        
        return $args;
    }
    
    /**
     * Runtime filter: swap menu item URLs to point to the correct translated post
     * for the current language. This handles cases where translations were created
     * after the menu was built.
     *
     * @param array $items Menu item objects.
     * @param object $args Menu args.
     * @return array
     */
    public function filter_menu_item_urls($items, $args) {
        if (!$this->is_active()) {
            return $items;
        }
        
        $current_lang = $this->get_current_language();
        $default_lang = $this->get_default_language();
        
        if ($current_lang === $default_lang) {
            return $items;
        }
        
        foreach ($items as &$item) {
            // Only process post_type items (pages, posts, custom post types)
            if ($item->type !== 'post_type' || empty($item->object_id)) {
                continue;
            }
            
            $object_id = (int) $item->object_id;
            
            // Try to find the translation for the current language
            $translations = $this->get_post_translations($object_id);
            if (isset($translations[$current_lang]) && (int) $translations[$current_lang] !== $object_id) {
                $translated_id = (int) $translations[$current_lang];
                $item->object_id = $translated_id;
                $item->url = get_permalink($translated_id);
                // Swap label to translated post title
                $translated_title = get_the_title($translated_id);
                if ($translated_title) {
                    $item->title = $translated_title;
                }
            } elseif (!isset($translations[$current_lang])) {
                // No translation exists — still fix the URL to include language prefix
                $item->url = get_permalink($object_id);
            }
        }
        
        return $items;
    }
    
    /**
     * Redirect untranslated pages to their default language version.
     * If a user visits /de/services/ but no German translation exists,
     * redirect to /services/ (the default language page).
     */
    public function maybe_redirect_untranslated_page() {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }
        
        if (!$this->is_active() || !is_singular()) {
            return;
        }
        
        $current_lang = $this->get_current_language();
        $default_lang = $this->get_default_language();
        
        if ($current_lang === $default_lang) {
            return;
        }
        
        $post_id = get_queried_object_id();
        if (!$post_id) {
            return;
        }
        
        // Check if the current post is in the current language AND is published.
        // A draft/pending translation should also redirect to the default language,
        // otherwise anonymous visitors get a 404 or a caching redirect loop.
        $post_lang   = $this->get_post_language($post_id);
        $post_status = get_post_status($post_id);
        
        // Logged-in editors/admins must never be redirected away from their own draft translations.
        // They need to be able to preview newly created drafts via ?p=ID, ?page_id=ID, or pretty permalink.
        if (current_user_can('edit_posts')) {
            return;
        }
        
        // Only redirect if the post truly belongs to a different language or is not published.
        $needs_redirect = ( $post_lang !== $current_lang ) ||
                          ( $post_lang === $current_lang && $post_status !== 'publish' );
        
        if (!$needs_redirect) {
            return;
        }
        
        $default_lang_prefix = get_option('aitrfoel_default_lang_prefix', false);
        
        // For the front page: redirect directly to the default language home.
        // Using get_permalink() for front page returns home_url('/') which then
        // triggers another hop through maybe_redirect_default_lang_prefix — this
        // extra hop can interact with Cloudflare / host-level redirects and loop.
        if (is_front_page()) {
            $default_url = $default_lang_prefix
                ? home_url('/' . $default_lang . '/')
                : home_url('/');
        } else {
            // For regular pages/posts, redirect to the default-language version.
            // Temporarily remove our prefix filters so get_permalink returns the raw URL.
            remove_filter('page_link',      [$this, 'filter_page_permalink'], 10);
            remove_filter('post_link',      [$this, 'filter_permalink'],      10);
            remove_filter('post_type_link', [$this, 'filter_permalink'],      10);
            
            // Try to find the actual default-language post for this content.
            $translations    = $this->get_post_translations($post_id);
            $default_post_id = isset($translations[$default_lang]) ? (int) $translations[$default_lang] : $post_id;
            $default_url     = get_permalink($default_post_id) ?: home_url('/');
            
            add_filter('post_link',      [$this, 'filter_permalink'],      10, 2);
            add_filter('page_link',      [$this, 'filter_page_permalink'], 10, 2);
            add_filter('post_type_link', [$this, 'filter_permalink'],      10, 2);
        }
        
        // Anti-loop guard: don't redirect if the target path equals the current path.
        $current_path = rtrim(wp_parse_url(home_url(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'))), PHP_URL_PATH) ?? '/', '/');
        $target_path  = rtrim(wp_parse_url($default_url, PHP_URL_PATH) ?? '/', '/');
        if ($current_path === $target_path) {
            return;
        }
        
        wp_safe_redirect($default_url, 302);
        exit;
    }
    
    // =========================================================================
    // PER-LANGUAGE WIDGET VISIBILITY
    // =========================================================================
    
    /**
     * Filter widget display: hide widget if it's restricted to languages
     * that don't include the current language.
     *
     * @param array     $instance Widget instance settings.
     * @param WP_Widget $widget   Widget object.
     * @param array     $args     Widget area arguments.
     * @return array|false False to prevent display.
     */
    public function filter_widget_by_language($instance, $widget, $args) {
        if (!$this->is_active() || empty($instance)) {
            return $instance;
        }
        
        $current_lang = $this->get_current_language();
        $default_lang = $this->get_default_language();
        
        // Check settings-based widget language assignments (from plugin settings page)
        $widget_id = $widget->id; // e.g. "text-2"
        $widget_lang_settings = get_option('aitrfoel_widget_languages', []);
        if (!empty($widget_lang_settings[$widget_id])) {
            $langs = (array) $widget_lang_settings[$widget_id];
            if (!in_array($current_lang, $langs, true)) {
                return false; // Hide widget
            }
        }
        
        // Fallback: check per-widget instance setting (classic widgets form)
        if (!empty($instance['aitrfoel_languages'])) {
            $langs = (array) $instance['aitrfoel_languages'];
            if (!in_array($current_lang, $langs, true)) {
                return false; // Hide widget
            }
        }
        
        // Swap translated content if viewing a non-default language
        if ($current_lang !== $default_lang) {
            $translations = get_option('aitrfoel_widget_translations', []);
            if (!empty($translations[$widget_id][$current_lang])) {
                $tr = $translations[$widget_id][$current_lang];
                // Swap block widget content
                if (isset($tr['content']) && isset($instance['content'])) {
                    $instance['content'] = $tr['content'];
                }
                // Swap widget title
                if (isset($tr['title']) && isset($instance['title'])) {
                    $instance['title'] = $tr['title'];
                }
                // Swap nav_menu widget to translated menu
                if (isset($tr['nav_menu']) && isset($instance['nav_menu'])) {
                    $instance['nav_menu'] = $tr['nav_menu'];
                }
            }
        }
        
        return $instance;
    }
    
    /**
     * Add language visibility controls to every widget's admin form.
     *
     * @param WP_Widget $widget   Widget object.
     * @param null      $return   Unused.
     * @param array     $instance Current widget instance settings.
     */
    public function widget_language_form($widget, $return, $instance) {
        if (!$this->is_active()) {
            return;
        }
        
        $languages = $this->get_available_languages();
        if (empty($languages)) {
            return;
        }
        
        $selected = isset($instance['aitrfoel_languages']) ? (array) $instance['aitrfoel_languages'] : [];
        $field_name = $widget->get_field_name('aitrfoel_languages');
        ?>
        <div style="margin: 8px 0; padding: 8px; background: #f0f6fc; border-left: 3px solid #2271b1; border-radius: 3px;">
            <p style="margin: 0 0 6px; font-weight: 600; font-size: 12px;">
                🌍 <?php esc_html_e('Show in Languages:', 'ai-translator-for-elementor-and-polylang'); ?>
            </p>
            <label style="display: block; margin-bottom: 4px;">
                <input type="checkbox" class="aitrfoel-widget-lang-all"
                       <?php checked(empty($selected)); ?>
                       onchange="var c=this.closest('div').querySelectorAll('.aitrfoel-widget-lang-cb');c.forEach(function(x){x.checked=false;});" />
                <?php esc_html_e('All languages', 'ai-translator-for-elementor-and-polylang'); ?>
            </label>
            <?php foreach ($languages as $lang): ?>
            <label style="display: inline-block; margin-right: 10px; margin-bottom: 4px;">
                <input type="checkbox" class="aitrfoel-widget-lang-cb"
                       name="<?php echo esc_attr($field_name); ?>[]"
                       value="<?php echo esc_attr($lang->code); ?>"
                       <?php checked(in_array($lang->code, $selected, true)); ?>
                       onchange="this.closest('div').querySelector('.aitrfoel-widget-lang-all').checked=false;" />
                <?php echo esc_html(strtoupper($lang->code) . ' — ' . $lang->name); ?>
            </label>
            <?php endforeach; ?>
            <p class="description" style="margin: 4px 0 0; font-size: 11px;">
                <?php esc_html_e('Select specific languages to show this widget only for those languages, or leave "All" checked.', 'ai-translator-for-elementor-and-polylang'); ?>
            </p>
        </div>
        <?php
    }
    
    /**
     * Save the language visibility setting when widget is updated.
     *
     * @param array     $instance     New widget instance.
     * @param array     $new_instance Values from the form.
     * @param array     $old_instance Previously saved values.
     * @param WP_Widget $widget       Widget object.
     * @return array
     */
    public function widget_language_save($instance, $new_instance, $old_instance, $widget) {
        if (isset($new_instance['aitrfoel_languages']) && is_array($new_instance['aitrfoel_languages'])) {
            $valid_codes = array_keys($this->get_available_languages());
            $instance['aitrfoel_languages'] = array_values(array_intersect($new_instance['aitrfoel_languages'], $valid_codes));
        } else {
            $instance['aitrfoel_languages'] = []; // All languages
        }
        return $instance;
    }

    /**
     * Swap the Elementor template ID to its translation for the current language.
     *
     * This is the primary hook for Elementor Pro Theme Builder template swapping.
     * It mirrors the approach used by "Connect Polylang for Elementor":
     *
     *   1. Translated templates have NO _elementor_conditions (enforced by
     *      elementor_conditions_empty_on_translations() below).
     *   2. Elementor resolves its candidates using only the default-language template's
     *      conditions — translated templates are invisible to the resolver.
     *   3. Once Elementor has selected the default-language template ID, this filter
     *      swaps it to the translated version for the current language.
     *
     * Called via elementor/theme/get_location_templates/template_id filter.
     *
     * @param int $post_id The template post ID Elementor selected via conditions.
     * @return int The translated template post ID, or the original if no translation exists.
     */
    public function swap_elementor_template_id_to_translation($post_id) {
        if (!$this->is_active() || $this->should_skip_elementor_template_swap()) {
            return $post_id;
        }

        $current_lang = $this->get_current_language();
        $default_lang = $this->get_default_language();

        if ($current_lang === $default_lang) {
            return $post_id;
        }

        $translations = $this->get_post_translations((int) $post_id);
        if (!isset($translations[$current_lang])) {
            return $post_id; // No translation — fall back to original.
        }

        $translated_id = (int) $translations[$current_lang];
        if ($translated_id > 0 && get_post_status($translated_id) === 'publish') {
            return $translated_id;
        }

        return $post_id;
    }

    /**
     * Return empty conditions for translated Elementor templates.
     *
     * Hooked to get_post_metadata (always, admin + frontend).
     *
     * Elementor Pro queries _elementor_conditions when building its candidates list.
     * If a translated template has conditions it competes with the original, causing
     * the wrong-language template to appear. By returning an empty array here,
     * translated templates are invisible to the conditions resolver.
     * The actual swap happens via swap_elementor_template_id_to_translation().
     *
     * This mirrors elementor_conditions_empty_on_translations() in Connect Polylang for Elementor.
     *
     * @param mixed  $null     null (pass-through) or override value.
     * @param int    $post_id  Post being queried.
     * @param string $meta_key Meta key being read.
     * @return mixed null (pass-through) or empty array.
     */
    public function elementor_conditions_empty_on_translations($null, $post_id, $meta_key) {
        if ('_elementor_conditions' !== $meta_key) {
            return $null;
        }
        if (!$this->is_active()) {
            return $null;
        }
        // Only intercept elementor_library posts.
        if (get_post_type($post_id) !== 'elementor_library') {
            return $null;
        }
        $default_lang = $this->get_default_language();
        $post_lang = $this->get_post_language($post_id);
        // If this is a translated (non-default) template, return empty conditions.
        if ($post_lang && $post_lang !== $default_lang) {
            return [[]]; // Polylang convention: array containing one empty array.
        }
        return $null;
    }

    /**
     * Swap Elementor template data when rendering a template in a non-default language.
     * This is a fallback for the builder_content_data path (e.g. Template widget, shortcodes).
     * Called via elementor/frontend/builder_content_data filter.
     *
     * @param array $data    Elementor data array.
     * @param int   $post_id Post ID of the template being rendered.
     * @return array
     */
    public function filter_elementor_template_data($data, $post_id) {
        if (!$this->is_active() || empty($data)) {
            return $data;
        }

        if ($this->should_skip_elementor_template_swap()) {
            return $data;
        }

        $current_lang = $this->get_current_language();
        $default_lang = $this->get_default_language();

        if ($current_lang === $default_lang) {
            return $data;
        }

        // Only swap for elementor_library posts (templates)
        $post_type = get_post_type($post_id);
        if ($post_type !== 'elementor_library') {
            return $data;
        }

        // Try loading a fully translated template first
        $translations = $this->get_post_translations($post_id);
        if (isset($translations[$current_lang])) {
            $translated_id = (int) $translations[$current_lang];
            if ($translated_id > 0 && get_post_status($translated_id) === 'publish') {
                $translated_data_json = get_post_meta($translated_id, '_elementor_data', true);
                if (!empty($translated_data_json)) {
                    $translated_data = json_decode($translated_data_json, true);
                    if (is_array($translated_data) && !empty($translated_data)) {
                        // Translated template already has correct menu IDs baked in
                        return $translated_data;
                    }
                }
            }
        }

        // Fallback: no translated template available — dynamically swap menu IDs
        // in the original template data so Nav Menu widgets show the correct language menu
        $menu_id_map = get_option('aitrfoel_menu_id_map', []);
        $menu_assignments = get_option('aitrfoel_menu_assignments', []);

        // Build a combined source→translated map from both location assignments and direct map
        $combined_map = is_array($menu_id_map) ? $menu_id_map : [];
        if (!empty($menu_assignments) && is_array($menu_assignments)) {
            $nav_locations = get_nav_menu_locations();
            foreach ($nav_locations as $loc => $loc_menu_id) {
                $loc_menu_id = (int) $loc_menu_id;
                if ($loc_menu_id > 0 && isset($menu_assignments[$loc][$current_lang])) {
                    $combined_map[$loc_menu_id][$current_lang] = (int) $menu_assignments[$loc][$current_lang];
                }
            }
        }

        if (!empty($combined_map)) {
            $this->swap_menu_ids_in_elementor_data_recursive($data, $combined_map, $current_lang);
        }

        $this->swap_nested_elementor_template_refs_recursive($data, $current_lang);

        return $data;
    }

    /**
     * Recursively swap nav-menu widget menu IDs in Elementor data at runtime.
     *
     * @param array  &$data       Elementor elements array (modified in place).
     * @param array  $menu_id_map Source menu ID => [lang => translated menu ID].
     * @param string $target_lang Target language code.
     */
    private function swap_menu_ids_in_elementor_data_recursive(&$data, $menu_id_map, $target_lang) {
        foreach ($data as &$element) {
            if (!is_array($element)) continue;

            $widget_type = isset($element['widgetType']) ? $element['widgetType'] : '';
            if ($widget_type === 'nav-menu' && isset($element['settings']['menu'])) {
                $source_menu_id = (int) $element['settings']['menu'];
                if ($source_menu_id > 0 && isset($menu_id_map[$source_menu_id][$target_lang])) {
                    $element['settings']['menu'] = (string) $menu_id_map[$source_menu_id][$target_lang];
                }
            }

            if (isset($element['elements']) && is_array($element['elements'])) {
                $this->swap_menu_ids_in_elementor_data_recursive($element['elements'], $menu_id_map, $target_lang);
            }
        }
        unset($element);
    }

    /**
     * Recursively swap nested Elementor reusable template references.
     *
     * Covers common reusable-element patterns such as the Template widget
     * (`settings.template_id`) and Global Widget references (`templateID`).
     *
     * @param array  &$data       Elementor elements array (modified in place).
     * @param string $target_lang Target language code.
     */
    private function swap_nested_elementor_template_refs_recursive(&$data, $target_lang) {
        foreach ($data as &$element) {
            if (!is_array($element)) continue;

            $widget_type = isset($element['widgetType']) ? $element['widgetType'] : '';

            if ($widget_type === 'template' && isset($element['settings']['template_id'])) {
                $translated_id = $this->get_translated_runtime_elementor_template_id((int) $element['settings']['template_id'], $target_lang);
                if ($translated_id > 0) {
                    $element['settings']['template_id'] = (string) $translated_id;
                }
            }

            if (isset($element['templateID'])) {
                $translated_id = $this->get_translated_runtime_elementor_template_id((int) $element['templateID'], $target_lang);
                if ($translated_id > 0) {
                    $element['templateID'] = $translated_id;
                }
            }

            if (isset($element['elements']) && is_array($element['elements'])) {
                $this->swap_nested_elementor_template_refs_recursive($element['elements'], $target_lang);
            }
        }
        unset($element);
    }

    /**
     * Resolve a translated reusable Elementor template for runtime rendering.
     *
     * @param int    $template_id Source template ID.
     * @param string $target_lang Target language code.
     * @return int
     */
    private function get_translated_runtime_elementor_template_id($template_id, $target_lang) {
        if ($template_id <= 0 || get_post_type($template_id) !== 'elementor_library') {
            return 0;
        }

        $translations = $this->get_post_translations($template_id);
        if (!isset($translations[$target_lang])) {
            return 0;
        }

        $translated_id = (int) $translations[$target_lang];
        if ($translated_id <= 0 || get_post_status($translated_id) !== 'publish') {
            return 0;
        }

        return $translated_id;
    }

    /**
     * Do not swap template content when previewing or editing the template itself.
     *
     * @return bool
     */
    private function should_skip_elementor_template_swap() {
        if (is_singular('elementor_library')) {
            return true;
        }

        // Elementor editor loads as a non-admin frontend iframe with ?elementor in the URL.
        if (isset($_GET['elementor'])) {
            return true;
        }

        $preview_id = isset($_GET['elementor-preview']) ? (int) $_GET['elementor-preview'] : 0;
        if ($preview_id > 0 && get_post_type($preview_id) === 'elementor_library') {
            return true;
        }

        // Use Elementor's own API to detect editor / preview mode when available.
        if (class_exists('\\Elementor\\Plugin')) {
            $elementor = \Elementor\Plugin::instance();
            if (isset($elementor->editor) && method_exists($elementor->editor, 'is_edit_mode') && $elementor->editor->is_edit_mode()) {
                return true;
            }
            if (isset($elementor->preview) && method_exists($elementor->preview, 'is_preview_mode') && $elementor->preview->is_preview_mode()) {
                return true;
            }
        }

        return false;
    }
}

/**
 * Language Switcher WordPress Widget
 *
 * Allows users to add a language switcher to any widget area (sidebar, footer, etc.)
 * Only active when the built-in language system is enabled.
 */
class AITRFOEL_Language_Switcher_Widget extends WP_Widget {

    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct(
            'aitrfoel_language_switcher',
            __('AI Translator – Language Switcher', 'ai-translator-for-elementor-and-polylang'),
            [
                'description' => __('Display a language switcher for the built-in language system.', 'ai-translator-for-elementor-and-polylang'),
                'classname'   => 'aitrfoel-language-switcher-widget',
            ]
        );
    }

    /**
     * Front-end display
     *
     * @param array $args     Widget arguments.
     * @param array $instance Saved values from database.
     */
    public function widget($args, $instance) {
        if (!get_option('aitrfoel_switcher_enabled', true)) {
            return;
        }
        
        if (!class_exists('AITRFOEL_Builtin_Integration') || !AITRFOEL_Builtin_Integration::is_enabled()) {
            return;
        }

        $title = !empty($instance['title']) ? $instance['title'] : '';
        $style = !empty($instance['style']) ? $instance['style'] : 'list';

        echo $args['before_widget'];

        if ($title) {
            echo $args['before_title'] . esc_html(apply_filters('widget_title', $title)) . $args['after_title'];
        }

        $builtin = AITRFOEL_Builtin_Integration::get_instance();
        echo $builtin->render_language_switcher_shortcode([
            'style'      => $style,
            'show_names' => 'true',
            'show_flags' => 'true',
        ]);

        echo $args['after_widget'];
    }

    /**
     * Back-end widget form
     *
     * @param array $instance Previously saved values from database.
     */
    public function form($instance) {
        $title = !empty($instance['title']) ? $instance['title'] : __('Languages', 'ai-translator-for-elementor-and-polylang');
        $style = !empty($instance['style']) ? $instance['style'] : 'list';
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php esc_html_e('Title:', 'ai-translator-for-elementor-and-polylang'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($title); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('style')); ?>"><?php esc_html_e('Display Style:', 'ai-translator-for-elementor-and-polylang'); ?></label>
            <select class="widefat" id="<?php echo esc_attr($this->get_field_id('style')); ?>" name="<?php echo esc_attr($this->get_field_name('style')); ?>">
                <option value="list" <?php selected($style, 'list'); ?>><?php esc_html_e('List', 'ai-translator-for-elementor-and-polylang'); ?></option>
                <option value="dropdown" <?php selected($style, 'dropdown'); ?>><?php esc_html_e('Dropdown', 'ai-translator-for-elementor-and-polylang'); ?></option>
                <option value="flags" <?php selected($style, 'flags'); ?>><?php esc_html_e('Flags / Codes', 'ai-translator-for-elementor-and-polylang'); ?></option>
                <option value="flags-text" <?php selected($style, 'flags-text'); ?>><?php esc_html_e('Flags + Text', 'ai-translator-for-elementor-and-polylang'); ?></option>
            </select>
        </p>
        <?php
    }

    /**
     * Sanitize widget form values as they are saved
     *
     * @param array $new_instance Values just sent to be saved.
     * @param array $old_instance Previously saved values from database.
     * @return array Updated safe values to be saved.
     */
    public function update($new_instance, $old_instance) {
        $instance = [];
        $instance['title'] = sanitize_text_field($new_instance['title'] ?? '');
        $instance['style'] = in_array($new_instance['style'] ?? '', ['list', 'dropdown', 'flags', 'flags-text'], true) ? $new_instance['style'] : 'list';
        return $instance;
    }
}