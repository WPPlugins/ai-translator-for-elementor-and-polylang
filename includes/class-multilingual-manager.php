<?php
/**
 * Multilingual Manager Class
 * Factory/Manager that detects which multilingual plugin is active
 * and provides the appropriate integration instance
 */

if (!defined('ABSPATH')) exit;

class AITRFOEL_Multilingual_Manager {
    
    private static $instance = null;
    
    /**
     * The active multilingual integration instance
     * @var AITRFOEL_Multilingual_Interface|null
     */
    private $integration = null;
    
    /**
     * Detected plugin type
     * @var string|null 'polylang', 'wpml', 'builtin', or null
     */
    private $detected_plugin = null;
    
    /**
     * Get singleton instance
     * 
     * @return AITRFOEL_Multilingual_Manager
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor - detects and initializes the appropriate integration
     */
    private function __construct() {
        $this->detect_multilingual_plugin();
        
        // AJAX handler for dismissing TranslatePress notice
        add_action('wp_ajax_aitrfoel_dismiss_translatepress_notice', [$this, 'ajax_dismiss_translatepress_notice']);

        // Dynamic sorting: sync translated posts array to match original ordering attributes
        add_filter('the_posts', [$this, 'sync_query_posts_order'], 25, 2);

        // Filter JetEngine Listing Grid loops to preserve original post order
        add_filter('jet-engine/listing/query/items', [$this, 'sync_jet_listing_items_order'], 25, 3);

        // Filter Elementor Header Footer Builder template IDs
        add_filter('get_hfe_header_id', [$this, 'swap_hfe_template_id'], 20, 1);
        add_filter('get_hfe_footer_id', [$this, 'swap_hfe_template_id'], 20, 1);
    }
    
    /**
     * AJAX handler to dismiss TranslatePress notice
     */
    public function ajax_dismiss_translatepress_notice() {
        check_ajax_referer('aitrfoel_dismiss_tp');
        if (current_user_can('manage_options')) {
            update_option('aitrfoel_translatepress_notice_dismissed', true);
        }
        wp_die();
    }
    
    /**
     * Detect which multilingual plugin is active
     * Priority: If user explicitly enabled built-in, use it regardless of other plugins.
     * Otherwise: Polylang first (backward compatibility), then WPML, then built-in.
     */
    private function detect_multilingual_plugin() {
        // Check for TranslatePress — not supported due to fundamentally different architecture
        if ($this->is_translatepress_available()) {
            AITRFOEL_Logger::log('Multilingual Manager: TranslatePress detected but not supported', 'warning');
            add_action('admin_notices', [$this, 'admin_notice_translatepress']);
        }

        // If the user explicitly enabled the built-in system, respect that choice
        // even if Polylang or WPML is also installed
        if ($this->is_builtin_available()) {
            $this->detected_plugin = 'builtin';
            $this->integration = AITRFOEL_Builtin_Integration::get_instance();
            AITRFOEL_Logger::log('Multilingual Manager: Built-in language system enabled and loaded (user choice)', 'info');
            return;
        }

        // Check for Polylang (backward compatibility)
        if ($this->is_polylang_available()) {
            $this->detected_plugin = 'polylang';
            $this->integration = AITRFOEL_Polylang_Integration::get_instance();
            AITRFOEL_Logger::log('Multilingual Manager: Polylang detected and loaded', 'info');
            return;
        }
        
        // Check for WPML
        if ($this->is_wpml_available()) {
            $this->detected_plugin = 'wpml';
            $this->integration = AITRFOEL_WPML_Integration::get_instance();
            AITRFOEL_Logger::log('Multilingual Manager: WPML detected and loaded', 'info');
            return;
        }
        
        // No multilingual plugin found
        $this->detected_plugin = null;
        $this->integration = null;
        AITRFOEL_Logger::log('Multilingual Manager: No multilingual plugin detected', 'warning');
    }
    
    /**
     * Check if Polylang is available
     * 
     * @return bool
     */
    private function is_polylang_available() {
        return function_exists('pll_get_post_language') && 
               function_exists('pll_set_post_language') &&
               function_exists('pll_get_post_translations') &&
               function_exists('pll_save_post_translations');
    }
    
    /**
     * Check if WPML is available
     * 
     * @return bool
     */
    private function is_wpml_available() {
        // WPML main plugin check
        return defined('ICL_SITEPRESS_VERSION') || 
               class_exists('SitePress') ||
               function_exists('icl_object_id');
    }
    
    /**
     * Check if the built-in language system is available and enabled
     * 
     * @return bool
     */
    private function is_builtin_available() {
        return class_exists('AITRFOEL_Builtin_Integration') && 
               AITRFOEL_Builtin_Integration::is_enabled();
    }
    
    /**
     * Check if TranslatePress is installed/active
     */
    private function is_translatepress_available() {
        return class_exists('TRP_Translate_Press') || 
               defined('TRP_PLUGIN_VERSION') ||
               function_exists('trp_translate');
    }
    
    /**
     * Admin notice for TranslatePress detection
     */
    public function admin_notice_translatepress() {
        // Only show on our plugin pages and dashboard
        $screen = get_current_screen();
        if (!$screen) return;
        
        $our_pages = ['toplevel_page_ai-translator', 'dashboard'];
        $is_our_page = in_array($screen->id, $our_pages) || strpos($screen->id, 'ai-translator') !== false;
        if (!$is_our_page) return;
        
        // Allow dismissal
        $dismissed = get_option('aitrfoel_translatepress_notice_dismissed', false);
        if ($dismissed) return;
        
        ?>
        <div class="notice notice-warning is-dismissible" id="aitrfoel-translatepress-notice">
            <p><strong><?php esc_html_e('Meep AI Translator — TranslatePress Detected', 'ai-translator-for-elementor-and-polylang'); ?></strong></p>
            <p><?php esc_html_e('TranslatePress uses a fundamentally different translation approach (single-post with inline replacements) that is not compatible with this plugin. Meep AI Translator works with duplicate-post translation systems.', 'ai-translator-for-elementor-and-polylang'); ?></p>
            <p><?php echo wp_kses_post(__('<strong>Supported systems:</strong> Polylang, WPML, or our Built-in Language System (recommended — includes menu and widget translation).', 'ai-translator-for-elementor-and-polylang')); ?></p>
            <p><?php
                echo wp_kses_post(sprintf(
                    /* translators: %s: URL to Language Management settings tab */
                    __('You can enable the <strong>Built-in Language System</strong> in <a href="%s">Settings &rarr; Languages &amp; Switcher</a> to get full translation support including menus and widgets.', 'ai-translator-for-elementor-and-polylang'),
                    esc_url(admin_url('admin.php?page=ai-translator-for-elementor-and-polylang-settings#tab-languages'))
                ));
            ?></p>
        </div>
        <script>
        jQuery(document).on('click', '#aitrfoel-translatepress-notice .notice-dismiss', function() {
            jQuery.post(ajaxurl, { action: 'aitrfoel_dismiss_translatepress_notice', _wpnonce: '<?php echo esc_js(wp_create_nonce('aitrfoel_dismiss_tp')); ?>' });
        });
        </script>
        <?php
    }
    
    /**
     * Get the active multilingual integration
     * 
     * @return AITRFOEL_Multilingual_Interface|null
     */
    public function get_integration() {
        return $this->integration;
    }
    
    /**
     * Check if any multilingual plugin is active
     * 
     * @return bool
     */
    public function has_multilingual_plugin() {
        return $this->integration !== null && $this->integration->is_active();
    }
    
    /**
     * Get the name of the detected multilingual plugin
     * 
     * @return string|null 'polylang', 'wpml', or null
     */
    public function get_detected_plugin() {
        return $this->detected_plugin;
    }
    
    /**
     * Get the display name of the detected plugin
     * 
     * @return string
     */
    public function get_plugin_display_name() {
        if ($this->integration) {
            return $this->integration->get_plugin_name();
        }
        return __('None', 'ai-translator-for-elementor-and-polylang');
    }
    
    /**
     * Check if the detected plugin is Polylang
     * 
     * @return bool
     */
    public function is_polylang() {
        return $this->detected_plugin === 'polylang';
    }
    
    /**
     * Check if the detected plugin is WPML
     * 
     * @return bool
     */
    public function is_wpml() {
        return $this->detected_plugin === 'wpml';
    }
    
    /**
     * Check if the detected system is the built-in language manager
     * 
     * @return bool
     */
    public function is_builtin() {
        return $this->detected_plugin === 'builtin';
    }
    
    /**
     * Check if TranslatePress is detected (unsupported)
     */
    public function is_translatepress_detected() {
        return $this->is_translatepress_available();
    }
    
    /**
     * Force re-detection of multilingual plugin
     * Useful after plugin activation/deactivation
     */
    public function refresh() {
        $this->integration = null;
        $this->detected_plugin = null;
        $this->detect_multilingual_plugin();
    }
    
    // =========================================================================
    // CONVENIENCE PROXY METHODS
    // These methods proxy to the active integration for easier access
    // =========================================================================
    
    /**
     * Check if multilingual plugin is active
     * 
     * @return bool
     */
    public function is_active() {
        return $this->integration !== null && $this->integration->is_active();
    }
    
    /**
     * Get available languages
     * 
     * @return array
     */
    public function get_available_languages() {
        if (!$this->integration) {
            return [];
        }
        return $this->integration->get_available_languages();
    }
    
    /**
     * Check if languages are configured (at least 2 languages for translation)
     * 
     * @return bool
     */
    public function has_languages_configured() {
        if (!$this->integration) {
            return false;
        }
        $languages = $this->integration->get_available_languages();
        return count($languages) >= 2;
    }
    
    /**
     * Get the number of configured languages
     * 
     * @return int
     */
    public function get_language_count() {
        if (!$this->integration) {
            return 0;
        }
        return count($this->integration->get_available_languages());
    }
    
    /**
     * Get default language
     * 
     * @return string|null
     */
    public function get_default_language() {
        if (!$this->integration) {
            return null;
        }
        return $this->integration->get_default_language();
    }
    
    /**
     * Get post language
     * 
     * @param int $post_id
     * @return string|null
     */
    public function get_post_language($post_id) {
        if (!$this->integration) {
            return null;
        }
        return $this->integration->get_post_language($post_id);
    }
    
    /**
     * Set post language
     * 
     * @param int $post_id
     * @param string $lang_code
     * @return bool
     */
    public function set_post_language($post_id, $lang_code) {
        if (!$this->integration) {
            return false;
        }
        return $this->integration->set_post_language($post_id, $lang_code);
    }
    
    /**
     * Get post translations
     * 
     * @param int $post_id
     * @return array
     */
    public function get_post_translations($post_id) {
        if (!$this->integration) {
            return [];
        }
        return $this->integration->get_post_translations($post_id);
    }
    
    /**
     * Setup translation relationship
     * 
     * @param int $original_post_id
     * @param int $translated_post_id
     * @param string $target_language
     * @return bool|WP_Error
     */
    public function setup_translation_relationship($original_post_id, $translated_post_id, $target_language) {
        if (!$this->integration) {
            return new WP_Error('no_multilingual_plugin', __('No multilingual plugin is active.', 'ai-translator-for-elementor-and-polylang'));
        }
        return $this->integration->setup_translation_relationship($original_post_id, $translated_post_id, $target_language);
    }
    
    /**
     * Get translation status
     * 
     * @param int $post_id
     * @return array
     */
    public function get_translation_status($post_id) {
        if (!$this->integration) {
            return [];
        }
        return $this->integration->get_translation_status($post_id);
    }
    
    /**
     * Check if language exists
     * 
     * @param string $lang_code
     * @return bool
     */
    public function language_exists($lang_code) {
        if (!$this->integration) {
            return false;
        }
        return $this->integration->language_exists($lang_code);
    }
    
    /**
     * Get language code from name
     * 
     * @param string $our_language
     * @return string|null
     */
    public function get_language_code($our_language) {
        if (!$this->integration) {
            return null;
        }
        return $this->integration->get_language_code($our_language);
    }
    
    /**
     * Get pages translation status
     * 
     * @return array
     */
    public function get_pages_translation_status() {
        if (!$this->integration) {
            return [];
        }
        return $this->integration->get_pages_translation_status();
    }
    
    /**
     * Get language switcher data
     * 
     * @return array
     */
    public function get_language_switcher_data() {
        if (!$this->integration) {
            return [];
        }
        return $this->integration->get_language_switcher_data();
    }
    
    /**
     * Get the correct permalink for a post in its assigned language
     * This ensures the multilingual plugin returns the URL with the correct language
     * 
     * @param int $post_id Post ID
     * @return string The permalink with correct language
     */
    public function get_post_permalink($post_id) {
        if (!$this->integration) {
            return get_permalink($post_id);
        }
        return $this->integration->get_post_permalink($post_id);
    }
    
    /**
     * Get the original post ID for a translation
     * Returns the post ID of the source/original post in the default language
     * 
     * @param int $post_id Post ID to check
     * @return int|null Original post ID, or null if not a translation (or is the original)
     */
    public function get_original_post_id($post_id) {
        if (!$this->integration) {
            return null;
        }
        
        // Get all translations for this post
        $translations = $this->integration->get_post_translations($post_id);
        if (empty($translations)) {
            return null;
        }
        
        // Get the default language
        $default_lang = $this->get_default_language();
        
        // If the post's language is the default language, it's the original
        $post_lang = $this->get_post_language($post_id);
        if ($post_lang === $default_lang) {
            return $post_id; // This IS the original
        }
        
        // Return the original (default language) post ID
        if (isset($translations[$default_lang])) {
            return intval($translations[$default_lang]);
        }
        
        // Fallback: find any post in the group that isn't this one
        foreach ($translations as $lang => $tid) {
            if (intval($tid) !== intval($post_id)) {
                return intval($tid);
            }
        }
        
        return null;
    }

    /**
     * Sort the queried posts array on-the-fly to match the exact sorting sequence
     * (menu_order or post_date) of their default-language original source posts.
     * This bypasses limitations of third-party drag-and-drop post ordering plugins
     * that update the database using custom SQL queries instead of wp_update_post/save_post.
     *
     * @param array    $posts Array of WP_Post objects.
     * @param WP_Query $query The query object.
     * @return array
     */
    public function sync_query_posts_order($posts, $query) {

        if (!get_option('aitrfoel_preserve_post_order', true)) {
            return $posts;
        }

        if (empty($posts) || !$this->integration || !$this->integration->is_active()) {
            return $posts;
        }

        // Bypass backend admin post list tables, but run on frontend AJAX and REST queries
        if (is_admin() && !wp_doing_ajax() && !defined('REST_REQUEST')) {
            return $posts;
        }

        $default = $this->get_default_language();
        $lang = $this->get_post_language($posts[0]->ID);
        if (!$lang || $lang === $default) {
            return $posts;
        }

        // Only sort for post types that use the language taxonomy or are translatable
        $post_type = $query->get('post_type');
        if (empty($post_type)) {
            $post_type = $posts[0]->post_type;
        }
        $post_types = (array) $post_type;
        $applies = false;
        foreach ($post_types as $pt) {
            if ($pt && (post_type_exists($pt) || $pt === 'post' || $pt === 'page')) {
                $applies = true;
                break;
            }
        }
        if (!$applies) {
            return $posts;
        }

        // Determine if the query is sorting by menu_order or date (safely parsing strings and arrays)
        $orderby = $query->get('orderby');
        $order = strtoupper((string) $query->get('order')) === 'ASC' ? 'ASC' : 'DESC';

        $sort_by_menu = false;
        $sort_by_date = false;

        if (is_array($orderby)) {
            foreach ($orderby as $key => $val) {
                if (is_string($key)) {
                    if (strpos($key, 'menu_order') !== false) {
                        $sort_by_menu = true;
                    }
                    if ($key === 'date' || $key === 'post_date' || $key === 'ID') {
                        $sort_by_date = true;
                    }
                }
            }
        } elseif (is_string($orderby)) {
            $sort_by_menu = (strpos($orderby, 'menu_order') !== false);
            $sort_by_date = ($orderby === '' || $orderby === 'date' || $orderby === 'post_date' || $orderby === 'ID');
        } else {
            $sort_by_date = true;
        }

        if (!$sort_by_menu && !$sort_by_date) {
            return $posts;
        }

        // Collect original posts to sort by their attributes
        $original_ids = [];
        foreach ($posts as $post) {
            $src_id = (int) get_post_meta($post->ID, '_aitrfoel_source_page', true);
            if (!$src_id) {
                $translations = $this->integration->get_post_translations($post->ID);
                $src_id = isset($translations[$default]) ? (int) $translations[$default] : $post->ID;
            }
            $original_ids[$post->ID] = $src_id;
        }

        if (empty($original_ids)) {
            return $posts;
        }

        // Fetch original post attributes in a single database query for performance
        global $wpdb;
        $ids_placeholder = implode(',', array_map('intval', array_unique(array_values($original_ids))));
        $rows = $wpdb->get_results("SELECT ID, menu_order, post_date FROM {$wpdb->posts} WHERE ID IN ($ids_placeholder)", OBJECT_K);


        // Sort the posts array
        usort($posts, function($a, $b) use ($original_ids, $rows, $sort_by_menu, $order) {
            $src_a_id = $original_ids[$a->ID] ?? $a->ID;
            $src_b_id = $original_ids[$b->ID] ?? $b->ID;

            $attr_a = isset($rows[$src_a_id]) ? $rows[$src_a_id] : null;
            $attr_b = isset($rows[$src_b_id]) ? $rows[$src_b_id] : null;

            if ($sort_by_menu) {
                $val_a = $attr_a ? (int) $attr_a->menu_order : $a->menu_order;
                $val_b = $attr_b ? (int) $attr_b->menu_order : $b->menu_order;
                if ($val_a === $val_b) {
                    $date_a = $attr_a ? strtotime($attr_a->post_date) : strtotime($a->post_date);
                    $date_b = $attr_b ? strtotime($attr_b->post_date) : strtotime($b->post_date);
                    if ($date_a === $date_b) {
                        return 0;
                    }
                    return ($date_a > $date_b) ? -1 : 1; // Default to DESC (newest first)
                }
            } else {
                $val_a = $attr_a ? strtotime($attr_a->post_date) : strtotime($a->post_date);
                $val_b = $attr_b ? strtotime($attr_b->post_date) : strtotime($b->post_date);
            }

            if ($val_a === $val_b) {
                return 0;
            }

            if ($order === 'ASC') {
                return ($val_a < $val_b) ? -1 : 1;
            } else {
                return ($val_a > $val_b) ? -1 : 1;
            }
        });

        return $posts;
    }

    /**
     * Filter JetEngine Listing Grid items to ensure translated post order matches default-language order.
     *
     * @param array  $items Array of objects/posts.
     * @param array  $settings Rendering settings.
     * @param object $render_instance The rendering instance.
     * @return array
     */
    public function sync_jet_listing_items_order($items, $settings, $render_instance) {

        if (!get_option('aitrfoel_preserve_post_order', true)) {
            return $items;
        }

        if (empty($items) || !is_array($items) || !$this->integration || !$this->integration->is_active()) {
            return $items;
        }

        // Only sort WP_Post objects
        if (!isset($items[0]) || !($items[0] instanceof WP_Post)) {
            return $items;
        }

        $default = $this->get_default_language();
        $lang = $this->get_post_language($items[0]->ID);
        if (!$lang || $lang === $default) {
            return $items;
        }

        // Collect original posts to sort by their attributes
        $original_ids = [];
        foreach ($items as $post) {
            $src_id = (int) get_post_meta($post->ID, '_aitrfoel_source_page', true);
            if (!$src_id) {
                $translations = $this->get_post_translations($post->ID);
                $src_id = isset($translations[$default]) ? (int) $translations[$default] : $post->ID;
            }
            $original_ids[$post->ID] = $src_id;
        }

        if (empty($original_ids)) {
            return $items;
        }

        // Fetch original post attributes in a single database query for performance
        global $wpdb;
        $ids_placeholder = implode(',', array_map('intval', array_unique(array_values($original_ids))));
        $rows = $wpdb->get_results("SELECT ID, menu_order, post_date FROM {$wpdb->posts} WHERE ID IN ($ids_placeholder)", OBJECT_K);

        // JetEngine listing settings for sorting
        $orderby = $settings['orderby'] ?? '';
        $order = strtoupper((string) ($settings['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $sort_by_menu = (strpos($orderby, 'menu_order') !== false);
        $sort_by_date = ($orderby === '' || $orderby === 'date' || $orderby === 'post_date' || $orderby === 'ID');

        if (!$sort_by_menu && !$sort_by_date) {
            $sort_by_date = true;
        }

        // Sort the items array
        usort($items, function($a, $b) use ($original_ids, $rows, $sort_by_menu, $order) {
            $src_a_id = $original_ids[$a->ID] ?? $a->ID;
            $src_b_id = $original_ids[$b->ID] ?? $b->ID;

            $attr_a = isset($rows[$src_a_id]) ? $rows[$src_a_id] : null;
            $attr_b = isset($rows[$src_b_id]) ? $rows[$src_b_id] : null;

            if ($sort_by_menu) {
                $val_a = $attr_a ? (int) $attr_a->menu_order : $a->menu_order;
                $val_b = $attr_b ? (int) $attr_b->menu_order : $b->menu_order;
                if ($val_a === $val_b) {
                    $date_a = $attr_a ? strtotime($attr_a->post_date) : strtotime($a->post_date);
                    $date_b = $attr_b ? strtotime($attr_b->post_date) : strtotime($b->post_date);
                    if ($date_a === $date_b) {
                        return 0;
                    }
                    return ($date_a > $date_b) ? -1 : 1; // Default to DESC (newest first)
                }
            } else {
                $val_a = $attr_a ? strtotime($attr_a->post_date) : strtotime($a->post_date);
                $val_b = $attr_b ? strtotime($attr_b->post_date) : strtotime($b->post_date);
            }

            if ($val_a === $val_b) {
                return 0;
            }

            if ($order === 'ASC') {
                return ($val_a < $val_b) ? -1 : 1;
            } else {
                return ($val_a > $val_b) ? -1 : 1;
            }
        });

        return $items;
    }

    /**
     * Swap Elementor Header Footer Builder template ID to current language translation.
     *
     * @param int $template_id
     * @return int
     */
    public function swap_hfe_template_id($template_id) {
        if (!$template_id) {
            return $template_id;
        }
        return $this->get_translated_post_id($template_id);
    }

    /**
     * Get translated post ID for the current language.
     *
     * @param int $post_id
     * @return int
     */
    public function get_translated_post_id($post_id) {
        if (!$post_id || !$this->integration || !$this->integration->is_active()) {
            return $post_id;
        }

        $current_lang = $this->integration->get_current_language();
        if (!$current_lang) {
            return $post_id;
        }

        // Check if the post itself is already in the current language
        $post_lang = $this->get_post_language($post_id);
        if ($post_lang === $current_lang) {
            return $post_id;
        }

        $translations = $this->get_post_translations($post_id);
        if (!empty($translations) && isset($translations[$current_lang])) {
            return (int) $translations[$current_lang];
        }

        return $post_id;
    }
}

/**
 * Helper function to get the multilingual manager instance
 * 
 * @return AITRFOEL_Multilingual_Manager
 */
function aitrfoel_multilingual() {
    return AITRFOEL_Multilingual_Manager::get_instance();
}
