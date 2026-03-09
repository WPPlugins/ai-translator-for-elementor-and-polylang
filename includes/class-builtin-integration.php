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
        // Register taxonomy
        add_action('init', [$this, 'register_taxonomy'], 5);
        
        // If init already fired (e.g. during AJAX), register taxonomy/rewrite rules immediately
        if (did_action('init')) {
            $this->register_taxonomy();
        }
        
        // Ensure DB table exists
        $this->maybe_create_table();
        
        // Frontend URL rewriting and language detection
        add_action('init', [$this, 'add_rewrite_rules'], 10);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('parse_request', [$this, 'detect_language_from_request']);
        
        // Permalink filtering for language prefixes
        add_filter('post_link', [$this, 'filter_permalink'], 10, 2);
        add_filter('page_link', [$this, 'filter_page_permalink'], 10, 2);
        add_filter('post_type_link', [$this, 'filter_permalink'], 10, 2);
        
        // Frontend query filtering
        if (!is_admin()) {
            add_action('pre_get_posts', [$this, 'filter_frontend_queries']);
        }
        
        // SEO: hreflang tags
        add_action('wp_head', [$this, 'output_hreflang_tags']);
        
        // Preload language switcher CSS in <head> for all frontend pages
        add_action('wp_head', [$this, 'maybe_preload_switcher_css'], 99);
        
        // Language switcher shortcode
        add_action('init', [$this, 'register_shortcode']);
        
        // Language switcher widget
        add_action('widgets_init', [$this, 'register_widget']);
        
        // Nav menu language switcher items
        add_filter('wp_nav_menu_items', [$this, 'add_language_switcher_to_menu'], 20, 2);
        
        // REST API language fields
        add_action('rest_api_init', [$this, 'register_rest_fields']);
        
        // WordPress core sitemap: filter URLs to include only default-language posts
        add_filter('wp_sitemaps_posts_query_args', [$this, 'filter_sitemap_query'], 10, 2);
        
        // Admin bar: language switcher in toolbar
        add_action('admin_bar_menu', [$this, 'add_admin_bar_language_switcher'], 100);
        
        // Admin: language column in posts list
        if (is_admin()) {
            add_filter('manage_posts_columns', [$this, 'add_language_column']);
            add_filter('manage_pages_columns', [$this, 'add_language_column']);
            add_action('manage_posts_custom_column', [$this, 'render_language_column'], 10, 2);
            add_action('manage_pages_custom_column', [$this, 'render_language_column'], 10, 2);
        }
    }
    
    // =========================================================================
    // DATA LAYER: DB Table & Taxonomy
    // =========================================================================
    
    /**
     * Register the language taxonomy
     */
    public function register_taxonomy() {
        if (taxonomy_exists(self::TAXONOMY)) {
            return;
        }
        
        register_taxonomy(self::TAXONOMY, ['post', 'page'], [
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
        
        if (is_wp_error($terms) || empty($terms)) {
            return null;
        }
        
        return $terms[0];
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
        
        $pages = get_posts([
            'post_type' => ['page', 'post'],
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
            
            $url = home_url('/');
            
            // If we have a translation of the current page, link to it
            if ($has_translation) {
                $url = $this->get_post_permalink($translations[$lang->slug]);
            } elseif ($lang->slug !== $default_lang) {
                // Link to the language homepage
                $url = home_url('/' . $lang->slug . '/');
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
        
        $lang_codes = [];
        foreach ($languages as $lang) {
            if ($lang['code'] !== $default_lang) {
                $lang_codes[] = preg_quote($lang['code'], '/');
            }
        }
        
        if (empty($lang_codes)) {
            return;
        }
        
        $lang_pattern = implode('|', $lang_codes);
        
        // Auto-flush rewrite rules when version changes OR when stored rules
        // are missing our language patterns (can happen after AJAX setup, reinstall, etc.)
        $rules_version = '1.3.2a'; // Bump this when rewrite rules change
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
        
        // Language root: /fr/
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/?$',
            'index.php?lang=$matches[1]',
            'top'
        );
        
        // Pagination: /fr/page/2/
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/page/([0-9]+)/?$',
            'index.php?lang=$matches[1]&paged=$matches[2]',
            'top'
        );
        
        // Feed: /fr/feed/
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
        
        // Category: /fr/category/slug/
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/category/(.+?)/?$',
            'index.php?lang=$matches[1]&category_name=$matches[2]',
            'top'
        );
        
        // Tag: /fr/tag/slug/
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/tag/(.+?)/?$',
            'index.php?lang=$matches[1]&tag=$matches[2]',
            'top'
        );
        
        // Search: /fr/?s=query (handled by query var, no special rewrite needed)
        
        // Custom post type archives: /fr/cpt-slug/
        $custom_post_types = get_post_types(['public' => true, '_builtin' => false, 'has_archive' => true], 'objects');
        foreach ($custom_post_types as $cpt) {
            $archive_slug = $cpt->has_archive === true ? $cpt->rewrite['slug'] : $cpt->has_archive;
            if ($archive_slug) {
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
        
        // Catch-all for pages/hierarchical content with language prefix: /fr/parent/child/
        // Added FIRST so it ends up BELOW the post rule (since 'top' prepends)
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/(.+?)/?$',
            'index.php?lang=$matches[1]&pagename=$matches[2]',
            'top'
        );
        
        // Posts with language prefix: /fr/post-slug/ (single-segment slugs)
        // Added LAST so it ends up ABOVE the page catch-all
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/([^/]+)/?$',
            'index.php?lang=$matches[1]&name=$matches[2]',
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
        
        if (!$lang || $lang === $default_lang) {
            return $permalink;
        }
        
        $home_url = home_url('/');
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
        
        if (!$lang || $lang === $default_lang) {
            return $permalink;
        }
        
        $home_url = home_url('/');
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
            $tax_query = $query->get('tax_query') ?: [];
            $tax_query[] = [
                'taxonomy' => self::TAXONOMY,
                'field'    => 'slug',
                'terms'    => $current_lang,
            ];
            $query->set('tax_query', $tax_query);
        }
        
        // For single posts/pages: if language prefix is in URL but post is in a different language,
        // WordPress will handle the 404 naturally. We just ensure the current language is set.
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
                $new_columns['aitrfoel_language'] = __('Language', 'ai-translator-for-elementor-and-polylang');
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
            echo '<span style="font-size: 12px;">' . esc_html(strtoupper($lang));
            if ($lang === $default) {
                echo ' <span style="color: #888; font-size: 10px;">(' . esc_html__('default', 'ai-translator-for-elementor-and-polylang') . ')</span>';
            }
            echo '</span>';
        } else {
            echo '<span style="color: #ccc;">—</span>';
        }
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
        
        foreach ($switcher_data as $lang) {
            $class = 'menu-item menu-item-language';
            if ($lang['is_current']) {
                $class .= ' current-language';
            }
            $flag_html = AITRFOEL_Language_Mapper::get_flag_html($lang['code'], 16, 'margin-right: 4px;');
            $items .= sprintf(
                '<li class="%s"><a href="%s">%s%s</a></li>',
                esc_attr($class),
                esc_url($lang['url']),
                $flag_html,
                esc_html(strtoupper($lang['code']))
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
     * Render language switcher via shortcode
     * 
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function render_language_switcher_shortcode($atts = []) {
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
                $name_html = $show_names ? esc_html($lang['native_name'] ?? $lang['name']) : esc_html(strtoupper($lang['code']));
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
                $flag_html = AITRFOEL_Language_Mapper::get_flag_html($lang['code'], 20, 'margin-right: 4px;');
                printf(
                    '<a href="%s" class="aitrfoel-flag-text-link%s" title="%s">%s<span>%s</span></a>',
                    esc_url($lang['url']),
                    esc_attr($active_class),
                    esc_attr($lang['name']),
                    $flag_html,
                    esc_html(strtoupper($lang['code']))
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
            $current_label = $show_names ? esc_html($current_lang['native_name'] ?? $current_lang['name']) : esc_html(strtoupper($current_lang['code']));
            
            echo '<div class="aitrfoel-language-switcher aitrfoel-switcher-dropdown-wrapper">';
            echo '<button type="button" class="aitrfoel-dropdown-trigger" aria-expanded="false" aria-haspopup="listbox">';
            echo $current_flag . '<span>' . $current_label . '</span>';
            echo '<svg class="aitrfoel-dropdown-arrow" width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M3 5l3 3 3-3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            echo '</button>';
            echo '<ul class="aitrfoel-dropdown-list" role="listbox" style="display:none;">';
            foreach ($switcher_data as $lang) {
                $active_class = $lang['is_current'] ? ' active' : '';
                $flag_html = $show_flags ? AITRFOEL_Language_Mapper::get_flag_html($lang['code'], 20, 'margin-right: 6px;') : '';
                $label = $show_names ? esc_html($lang['native_name'] ?? $lang['name']) : esc_html(strtoupper($lang['code']));
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
        if (!is_admin()) {
            $queried_lang = get_query_var('lang', '');
            if ($queried_lang && $this->language_exists($queried_lang)) {
                $current_lang = $queried_lang;
            }
        }
        
        // Find current language info
        $current_lang_name = strtoupper($current_lang);
        foreach ($languages as $lang) {
            if ($lang['code'] === $current_lang) {
                $current_lang_name = $lang['name'];
                break;
            }
        }
        
        // Get flag HTML for the current language
        $flag_html = '';
        if (class_exists('AITRFOEL_Language_Mapper')) {
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
        $wp_admin_bar->add_node([
            'id'     => 'aitrfoel-lang-all',
            'parent' => 'aitrfoel-lang-switcher',
            'title'  => '🌐 ' . esc_html__('All Languages', 'ai-translator-for-elementor-and-polylang'),
            'href'   => is_admin() ? admin_url('edit.php?page=ai-translator-for-elementor-and-polylang') : home_url('/'),
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
                // In admin, link to the frontend home in this language
                if ($lang['code'] === $default_lang) {
                    $url = home_url('/');
                } else {
                    $url = home_url('/' . $lang['code'] . '/');
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