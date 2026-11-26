<?php
/**
 * Admin Settings Page
 */

if (!defined('ABSPATH')) {
    exit;
}

class AITRFOEL_Settings {
    
    public static function init() {
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_settings_scripts']);
        add_action('wp_ajax_aitrfoel_test_api', [self::class, 'test_api_connection']);
        add_action('wp_ajax_aitrfoel_validate_word_count', [self::class, 'validate_word_count']);
        add_action('wp_ajax_aitrfoel_save_cooldown', [self::class, 'save_cooldown']);
        add_action('wp_ajax_aitrfoel_clear_cache', [self::class, 'clear_cache']);
        add_action('wp_ajax_aitrfoel_diagnose_theme', [self::class, 'diagnose_theme_ajax_handler']);
        add_action('wp_ajax_aitrfoel_reset_plugin_data', [self::class, 'reset_plugin_data']);
        add_action('wp_ajax_aitrfoel_add_glossary_term', [self::class, 'add_glossary_term_ajax']);
        add_action('wp_ajax_aitrfoel_delete_glossary_term', [self::class, 'delete_glossary_term_ajax']);
        add_action('wp_ajax_aitrfoel_import_glossary_csv', [self::class, 'import_glossary_csv_ajax']);
        add_action('wp_ajax_aitrfoel_export_glossary_csv', [self::class, 'export_glossary_csv_ajax']);
        add_action('wp_ajax_aitrfoel_refresh_inventory_stats', [self::class, 'refresh_inventory_stats_ajax']);
        add_action('wp_ajax_aitrfoel_clear_translation_memory', [self::class, 'clear_translation_memory_ajax']);
    }
    
    /**
     * Enqueue settings page scripts
     */
    public static function enqueue_settings_scripts($hook) {
        $hook = (string) $hook;

        // Only load on settings page
        if (strpos($hook, 'ai-translator-for-elementor-and-polylang') === false) {
            return;
        }
        
        wp_enqueue_script(
            'aitrfoel-settings-page',
            AITRFOEL_PLUGIN_URL . 'assets/js/settings-page.js',
            ['jquery'],
            AITRFOEL_VERSION,
            true
        );
        
        // Localize script with AJAX data
        wp_localize_script('aitrfoel-settings-page', 'aitrfoel_settings_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'test_nonce' => wp_create_nonce('aitrfoel_test_api'),
            'clear_cache_nonce' => wp_create_nonce('aitrfoel_clear_cache'),
            'glossary_nonce' => wp_create_nonce('aitrfoel_glossary_nonce'),
            'inventory_nonce' => wp_create_nonce('aitrfoel_inventory_nonce'),
            'tm_nonce' => wp_create_nonce('aitrfoel_tm_nonce'),
            'clear_cache_confirm' => __('Are you sure you want to clear all cached translations? This cannot be undone.', 'ai-translator-for-elementor-and-polylang'),
            'clear_tm_confirm' => __('Are you sure you want to clear all Translation Memory? Cached phrases will need to be re-translated on future runs.', 'ai-translator-for-elementor-and-polylang'),
            'clearing_text' => __('Clearing...', 'ai-translator-for-elementor-and-polylang'),
            'clear_cache_text' => __('Clear Translation Cache', 'ai-translator-for-elementor-and-polylang'),
            'recalculating_text' => __('Recalculating...', 'ai-translator-for-elementor-and-polylang'),
            'recalculate_stats_text' => __('Recalculate Stats', 'ai-translator-for-elementor-and-polylang'),
        ]);
    }
    
    /**
     * Get word limit for plan
     * Based on Freemius plan IDs and token limits:
     * - Free: 40K tokens ≈ 7,200 words
     * - Starter (45513): 100K tokens ≈ 18,000 words
     * - Agency (45514): 500K tokens ≈ 89,000 words
     * - Enterprise (45515): 2M tokens ≈ 355,000 words
     */
    public static function get_word_limit_for_plan($plan_name) {
        $limits = [
            'Free' => 7200,        // 40,000 tokens
            'free' => 7200,        // lowercase variant
            'Starter' => 18000,    // 100,000 tokens (Plan ID: 45513)
            'starter' => 18000,    // lowercase variant
            'Agency' => 89000,     // 500,000 tokens (Plan ID: 45514)
            'agency' => 89000,     // lowercase variant
            'Enterprise' => 355000, // 2,000,000 tokens (Plan ID: 45515)
            'enterprise' => 355000  // lowercase variant
        ];
        
        return isset($limits[$plan_name]) ? $limits[$plan_name] : $limits['Free'];
    }
    
    /**
     * Sanitize the custom string-translations setting.
     * Form submits parallel rows under ['rows'][i]['source'] and ['rows'][i]['trans'][lang].
     * Stored as: [ ['source'=>..., 'translations'=>['en'=>...,'de'=>...]], ... ].
     *
     * @param mixed $input
     * @return array
     */
    public static function sanitize_string_translations($input) {
        $out = [];
        if (!is_array($input)) {
            return $out;
        }

        // Normalize to a flat list of rows. This runs both on the settings-form
        // submission (shape: ['rows'][i]['source'], ['rows'][i]['trans'][lang])
        // AND on direct update_option() calls from the auto-translate tool
        // (shape: [ ['source'=>..., 'translations'=>[lang=>txt]], ... ]), because
        // register_setting's sanitize filter fires on both.
        $rows = [];
        if (isset($input['rows']) && is_array($input['rows'])) {
            foreach ($input['rows'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rows[] = [
                    'source'       => isset($row['source']) ? $row['source'] : '',
                    'translations' => (!empty($row['trans']) && is_array($row['trans'])) ? $row['trans'] : [],
                ];
            }
        } else {
            foreach ($input as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rows[] = [
                    'source'       => isset($row['source']) ? $row['source'] : '',
                    'translations' => (!empty($row['translations']) && is_array($row['translations'])) ? $row['translations'] : [],
                ];
            }
        }

        foreach ($rows as $row) {
            $source = trim(wp_kses_post((string) $row['source']));
            if ($source === '') {
                continue;
            }
            $translations = [];
            foreach ((array) $row['translations'] as $lang => $txt) {
                $txt = trim(wp_kses_post((string) $txt));
                if ($txt !== '') {
                    $translations[sanitize_key($lang)] = $txt;
                }
            }
            if (!empty($translations)) {
                $out[] = ['source' => $source, 'translations' => $translations];
            }
        }
        return $out;
    }

    public static function register_settings() {
        // License Settings (hosted OpenAI service)
        register_setting('aitrfoel_settings_group', 'aitrfoel_license_key', [
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        


        // Cache Settings
        register_setting('aitrfoel_settings_group', 'aitrfoel_cache_enabled', [
            'sanitize_callback' => 'boolval',
            'default' => true
        ]);
        
        register_setting('aitrfoel_settings_group', 'aitrfoel_cache_duration', [
            'sanitize_callback' => 'intval',
            'default' => 30
        ]);

        // Elementor async (submit→poll) translation. Opt-in / experimental: when enabled,
        // Elementor pages translate via the proxy async job + browser polling instead of
        // the synchronous path. Default OFF so existing behavior is unchanged.
        register_setting('aitrfoel_settings_group', 'aitrfoel_elementor_async_enabled', [
            'sanitize_callback' => 'boolval',
            'default' => false
        ]);
        

        // Floating translation toolbar on WP List Screens (edit.php)
        register_setting('aitrfoel_settings_group', 'aitrfoel_list_toolbar_enabled', [
            'sanitize_callback' => 'boolval',
            'default' => true
        ]);

        // Page Creation Settings
        register_setting('aitrfoel_settings_group', 'aitrfoel_translated_page_status', [
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'publish'
        ]);

        // SEO Translation Settings
        register_setting('aitrfoel_settings_group', 'aitrfoel_seo_translation_enabled', [
            'sanitize_callback' => 'boolval',
            'default' => true
        ]);

        register_setting('aitrfoel_settings_group', 'aitrfoel_seo_translate_og', [
            'sanitize_callback' => 'boolval',
            'default' => true
        ]);

        register_setting('aitrfoel_settings_group', 'aitrfoel_seo_translate_twitter', [
            'sanitize_callback' => 'boolval',
            'default' => true
        ]);

        // WooCommerce Settings
        register_setting('aitrfoel_settings_group', 'aitrfoel_woocommerce_support', [
            'sanitize_callback' => 'boolval',
            'default' => true
        ]);

        // SureCart product translation toggle
        register_setting('aitrfoel_settings_group', 'aitrfoel_surecart_support', [
            'sanitize_callback' => 'boolval',
            'default' => true
        ]);

        register_setting('aitrfoel_settings_group', 'aitrfoel_woo_translate_categories', [
            'sanitize_callback' => 'boolval',
            'default' => true
        ]);

        register_setting('aitrfoel_settings_group', 'aitrfoel_woo_translate_tags', [
            'sanitize_callback' => 'boolval',
            'default' => true
        ]);

        register_setting('aitrfoel_settings_group', 'aitrfoel_woo_translate_attributes', [
            'sanitize_callback' => 'boolval',
            'default' => true
        ]);

        register_setting('aitrfoel_settings_group', 'aitrfoel_woo_translate_variations', [
            'sanitize_callback' => 'boolval',
            'default' => true
        ]);

        register_setting('aitrfoel_settings_group', 'aitrfoel_woo_translate_gallery_alt', [
            'sanitize_callback' => 'boolval',
            'default' => true
        ]);

        register_setting('aitrfoel_settings_group', 'aitrfoel_woo_product_fallback', [
            'sanitize_callback' => 'boolval',
            'default' => true
        ]);

        // Taxonomy name translation (built-in language system)
        register_setting('aitrfoel_settings_group', 'aitrfoel_translate_taxonomy_names', [
            'sanitize_callback' => 'boolval',
            'default' => true
        ]);

        // URL/slug translation for translated posts
        register_setting('aitrfoel_settings_group', 'aitrfoel_translate_url_slugs', [
            'sanitize_callback' => 'boolval',
            'default' => true
        ]);

        // Preserve existing slug on retranslation
        register_setting('aitrfoel_settings_group', 'aitrfoel_preserve_slug_on_retranslate', [
            'sanitize_callback' => 'boolval',
            'default' => true
        ]);

        // Localize absolute internal links (/fr/...) in content/widgets to the current language
        register_setting('aitrfoel_settings_group', 'aitrfoel_localize_internal_links', [
            'sanitize_callback' => 'boolval',
            'default' => true
        ]);

        // Paths excluded from language routing (no prefix, no redirect)
        register_setting('aitrfoel_settings_group', 'aitrfoel_routing_excluded_paths', [
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => ''
        ]);

        // Custom string translations (admin overrides for UI strings not in .mo files)
        register_setting('aitrfoel_settings_group', 'aitrfoel_string_translations', [
            'sanitize_callback' => [self::class, 'sanitize_string_translations'],
            'default' => []
        ]);

        // Default language URL prefix
        register_setting('aitrfoel_settings_group', 'aitrfoel_default_lang_prefix', [
            'sanitize_callback' => 'boolval',
            'default' => false
        ]);

        // Theme Compatibility Settings
        register_setting('aitrfoel_settings_group', 'aitrfoel_custom_meta_fields', [
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => ''
        ]);
        
        // Preserve Post Sorting Order
        register_setting('aitrfoel_settings_group', 'aitrfoel_preserve_post_order', [
            'sanitize_callback' => 'boolval',
            'default' => true
        ]);

        // Synchronize Media, Galleries and Custom Fields
        register_setting('aitrfoel_settings_group', 'aitrfoel_copy_custom_media_fields', [
            'sanitize_callback' => 'boolval',
            'default' => true
        ]);

        // Media Library Duplication (Optional attachment post creation per language)
        register_setting('aitrfoel_settings_group', 'aitrfoel_duplicate_media_attachments', [
            'sanitize_callback' => 'boolval',
            'default' => false
        ]);

        // Brand Voice & Localization Tone (PRO Language Enhancer)
        register_setting('aitrfoel_settings_group', 'aitrfoel_brand_tone_preset', [
            'sanitize_callback' => function($value) {
                $allowed = ['hospitality', 'corporate', 'sales', 'casual', 'custom'];
                return in_array($value, $allowed, true) ? $value : 'corporate';
            },
            'default' => 'corporate'
        ]);

        register_setting('aitrfoel_settings_group', 'aitrfoel_brand_tone_custom', [
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => ''
        ]);

        register_setting('aitrfoel_settings_group', 'aitrfoel_formality_mode', [
            'sanitize_callback' => function($value) {
                $allowed = ['auto', 'formal', 'informal'];
                return in_array($value, $allowed, true) ? $value : 'auto';
            },
            'default' => 'auto'
        ]);
        
        // Debug Settings
        register_setting('aitrfoel_settings_group', 'aitrfoel_debug_mode', [
            'sanitize_callback' => 'boolval',
            'default' => false
        ]);
        
        // Language Switcher Settings
        register_setting('aitrfoel_settings_group', 'aitrfoel_switcher_enabled', [
            'sanitize_callback' => 'boolval',
            'default' => true
        ]);
        
        register_setting('aitrfoel_settings_group', 'aitrfoel_switcher_style', [
            'sanitize_callback' => function($value) {
                $allowed = ['dropdown', 'list', 'flags', 'flags-text'];
                return in_array($value, $allowed, true) ? $value : 'flags-text';
            },
            'default' => 'flags-text'
        ]);
        
        register_setting('aitrfoel_settings_group', 'aitrfoel_switcher_show_flags', [
            'sanitize_callback' => 'boolval',
            'default' => true
        ]);

        register_setting('aitrfoel_settings_group', 'aitrfoel_switcher_theme', [
            'sanitize_callback' => function($value) {
                $allowed = ['default', 'minimal', 'light', 'pill'];
                return in_array($value, $allowed, true) ? $value : 'default';
            },
            'default' => 'default'
        ]);

        register_setting('aitrfoel_settings_group', 'aitrfoel_switcher_dropdown_names', [
            'sanitize_callback' => 'boolval',
            'default' => true
        ]);

        register_setting('aitrfoel_settings_group', 'aitrfoel_switcher_mobile_names', [
            'sanitize_callback' => 'boolval',
            'default' => false
        ]);

        register_setting('aitrfoel_settings_group', 'aitrfoel_auto_translate_templates', [
            'sanitize_callback' => 'boolval',
            'default' => false
        ]);

        register_setting('aitrfoel_settings_group', 'aitrfoel_extra_template_post_types', [
            'sanitize_callback' => function($value) {
                $slugs = preg_split('/[\s,]+/', (string) $value);
                $slugs = array_filter(array_map('sanitize_key', array_map('trim', $slugs)));
                return implode(', ', array_values(array_unique($slugs)));
            },
            'default' => ''
        ]);

        register_setting('aitrfoel_settings_group', 'aitrfoel_switcher_border_color', [
            'sanitize_callback' => 'sanitize_hex_color',
            'default' => ''
        ]);

        register_setting('aitrfoel_settings_group', 'aitrfoel_switcher_flag_size', [
            'sanitize_callback' => function($value) {
                $v = absint($value);
                if ($v === 0) { return 0; }
                return max(8, min(128, $v));
            },
            'default' => 0
        ]);

        register_setting('aitrfoel_settings_group', 'aitrfoel_switcher_show_names', [
            'sanitize_callback' => 'boolval',
            'default' => true
        ]);
        
        register_setting('aitrfoel_settings_group', 'aitrfoel_switcher_name_display', [
            'sanitize_callback' => function($value) {
                $allowed = ['native', 'english', 'code', 'code_lower'];
                return in_array($value, $allowed, true) ? $value : 'native';
            },
            'default' => 'native'
        ]);
        
        register_setting('aitrfoel_settings_group', 'aitrfoel_switcher_custom_names', [
            'sanitize_callback' => function($value) {
                if (!is_array($value)) return [];
                return array_map('sanitize_text_field', $value);
            },
            'default' => []
        ]);
        
        register_setting('aitrfoel_settings_group', 'aitrfoel_switcher_custom_css', [
            'sanitize_callback' => 'wp_strip_all_tags',
            'default' => ''
        ]);
        
        // Nav menu switcher settings
        register_setting('aitrfoel_settings_group', 'aitrfoel_switcher_hide_untranslated', [
            'sanitize_callback' => 'boolval',
            'default' => false
        ]);
        
        register_setting('aitrfoel_settings_group', 'aitrfoel_nav_menu_switcher', [
            'sanitize_callback' => 'boolval',
            'default' => false
        ]);
        
        register_setting('aitrfoel_settings_group', 'aitrfoel_nav_menu_location', [
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ]);

        register_setting('aitrfoel_settings_group', 'aitrfoel_delete_data_on_uninstall', [
            'sanitize_callback' => function ($v) { return $v ? '1' : ''; },
            'default' => ''
        ]);

        register_setting('aitrfoel_settings_group', 'aitrfoel_nav_menu_style', [
            'sanitize_callback' => function ($v) { return $v === 'dropdown' ? 'dropdown' : 'list'; },
            'default' => 'list'
        ]);

        // Per-language menu assignments
        register_setting('aitrfoel_settings_group', 'aitrfoel_menu_assignments', [
            'sanitize_callback' => [self::class, 'sanitize_menu_assignments'],
            'default' => []
        ]);

        // Per-language widget visibility
        register_setting('aitrfoel_settings_group', 'aitrfoel_widget_languages', [
            'sanitize_callback' => [self::class, 'sanitize_widget_languages'],
            'default' => []
        ]);

        // Glossary settings
        register_setting('aitrfoel_settings_group', 'aitrfoel_glossary_terms', [
            'sanitize_callback' => [self::class, 'sanitize_glossary_terms'],
            'default' => ''
        ]);

        // Exclude words settings
        register_setting('aitrfoel_settings_group', 'aitrfoel_exclude_words', [
            'sanitize_callback' => [self::class, 'sanitize_exclude_words'],
            'default' => ''
        ]);

        // Translation Memory settings
        register_setting('aitrfoel_settings_group', 'aitrfoel_enable_translation_memory', [
            'sanitize_callback' => 'boolval',
            'default' => true
        ]);

        // Background Auto-Sync settings
        register_setting('aitrfoel_settings_group', 'aitrfoel_enable_background_sync', [
            'sanitize_callback' => 'boolval',
            'default' => true
        ]);

        // AI Translation Engine selection
        register_setting('aitrfoel_settings_group', 'aitrfoel_ai_engine', [
            'sanitize_callback' => function($val) {
                $allowed = ['auto', 'claude-3-5-sonnet', 'deepl', 'gpt-4o', 'gpt-4o-mini'];
                return in_array($val, $allowed, true) ? $val : 'auto';
            },
            'default' => 'auto'
        ]);
    }

    /**
     * Get exclude words as array (synced from both Settings page and modal)
     */
    public static function get_exclude_words() {
        // Check the modal-based setting first (stored by AJAX handler)
        $modal_settings = get_option('aitrfoel_translation_settings', []);
        $modal_words = isset($modal_settings['exclude_words']) ? $modal_settings['exclude_words'] : [];

        // Check the settings-page-based textarea
        $textarea_value = get_option('aitrfoel_exclude_words', '');
        $textarea_words = array_filter(array_map('trim', explode("\n", $textarea_value)));

        // Merge both sources, remove duplicates
        return array_values(array_unique(array_merge($modal_words, $textarea_words)));
    }

    /**
     * Sanitize exclude words textarea and sync to modal option
     */
    public static function sanitize_exclude_words($input) {
        $words = array_filter(array_map('sanitize_text_field', explode("\n", $input)));

        // Sync to the AJAX-based modal option so both stay in sync
        $settings = get_option('aitrfoel_translation_settings', []);
        $settings['exclude_words'] = array_values($words);
        $settings['updated_at'] = time();
        update_option('aitrfoel_translation_settings', $settings);

        return implode("\n", $words);
    }

    /**
     * Get site-wide translatable content inventory
     *
     * @param bool $force_recount
     * @return array
     */
    public static function get_site_content_inventory($force_recount = false) {
        $transient_key = 'aitrfoel_site_inventory_stats_v2';
        if (!$force_recount) {
            $cached = get_transient($transient_key);
            if (is_array($cached) && !empty($cached)) {
                return $cached;
            }
        }

        $detector = class_exists('AITRFOEL_Content_Detector') ? AITRFOEL_Content_Detector::get_instance() : null;
        $manager = class_exists('AITRFOEL_Multilingual_Manager') ? AITRFOEL_Multilingual_Manager::get_instance() : null;
        $default_lang = ($manager && $manager->is_active()) ? $manager->get_default_language() : '';
        $target_languages = ($manager && $manager->is_active()) ? $manager->get_available_languages() : [];
        $target_lang_count = max(1, count($target_languages) - ($default_lang ? 1 : 0));

        // Content group definitions with crisp vector SVG icons
        $post_type_groups = [
            'pages' => [
                'label'      => __('Pages', 'ai-translator-for-elementor-and-polylang'),
                'icon'       => '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
                'post_types' => ['page'],
            ],
            'posts' => [
                'label'      => __('Posts', 'ai-translator-for-elementor-and-polylang'),
                'icon'       => '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>',
                'post_types' => ['post'],
            ],
            'templates' => [
                'label'      => __('Templates & Headers/Footers', 'ai-translator-for-elementor-and-polylang'),
                'icon'       => '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>',
                'post_types' => ['elementor_library', 'elementor-hf', 'jet-theme-core'],
            ],
            'products' => [
                'label'      => __('Products', 'ai-translator-for-elementor-and-polylang'),
                'icon'       => '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>',
                'post_types' => ['product', 'sc_product'],
            ],
        ];

        $breakdown = [];
        $total_items = 0;
        $total_source_words = 0;

        foreach ($post_type_groups as $group_key => $group) {
            $valid_types = array_filter($group['post_types'], 'post_type_exists');
            if (empty($valid_types)) {
                continue;
            }

            $posts = get_posts([
                'post_type'      => $valid_types,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ]);

            // Filter to only source-language posts if multilingual is active
            if ($manager && $manager->is_active() && $default_lang) {
                $posts = array_filter($posts, function($pid) use ($manager, $default_lang) {
                    $lang = $manager->get_post_language($pid);
                    if (!empty($lang)) {
                        return $lang === $default_lang;
                    }
                    $translations = $manager->get_post_translations($pid);
                    if (!empty($translations)) {
                        foreach ($translations as $l => $id) {
                            if ((int)$id === $pid && $l !== $default_lang) {
                                return false;
                            }
                        }
                    }
                    return true;
                });
                $posts = array_values($posts);
            }

            $group_words = 0;
            foreach ($posts as $pid) {
                if ($detector) {
                    $group_words += $detector->get_cached_word_count($pid, $force_recount);
                }
            }

            $item_count = count($posts);
            if ($item_count > 0 || in_array($group_key, ['pages', 'posts'])) {
                $breakdown[$group_key] = [
                    'label' => $group['label'],
                    'icon'  => $group['icon'],
                    'count' => $item_count,
                    'words' => $group_words,
                ];
                $total_items += $item_count;
                $total_source_words += $group_words;
            }
        }

        $estimated_total_demand = $total_source_words * $target_lang_count;
        $recommended_plan = self::get_recommended_plan_for_words($estimated_total_demand);

        $result = [
            'total_items'            => $total_items,
            'total_source_words'     => $total_source_words,
            'target_lang_count'      => $target_lang_count,
            'estimated_total_demand' => $estimated_total_demand,
            'breakdown'              => $breakdown,
            'recommended_plan'       => $recommended_plan,
        ];

        set_transient($transient_key, $result, HOUR_IN_SECONDS);

        return $result;
    }

    /**
     * Get recommended plan based on word demand
     *
     * @param int $demand_words
     * @return array
     */
    public static function get_recommended_plan_for_words($demand_words) {
        if ($demand_words <= 18000) {
            $plan_name = 'Starter';
            $plan_limit = 18000;
            $plan_id = 45513;
            $plan_slug = 'starter';
        } elseif ($demand_words <= 89000) {
            $plan_name = 'Agency';
            $plan_limit = 89000;
            $plan_id = 45514;
            $plan_slug = 'agency';
        } else {
            $plan_name = 'Enterprise';
            $plan_limit = 355000;
            $plan_id = 45515;
            $plan_slug = 'enterprise';
        }

        $upgrade_url = aitrfoel_free_upgrade_url('plugin-settings', 'pro-upgrade', $plan_slug);
        try {
            $is_pro = (defined('AITRFOEL_VERSION_TYPE') && AITRFOEL_VERSION_TYPE === 'pro');
            if ($is_pro && function_exists('aitrfoel_fs') && is_object(aitrfoel_fs()) && method_exists(aitrfoel_fs(), 'is_registered') && aitrfoel_fs()->is_registered()) {
                $upgrade_url = aitrfoel_fs()->get_upgrade_url();
            } elseif (function_exists('eatp_fs') && is_object(eatp_fs()) && method_exists(eatp_fs(), 'is_registered') && eatp_fs()->is_registered()) {
                $upgrade_url = eatp_fs()->get_upgrade_url();
            }
        } catch (\Throwable $e) {
            // Keep default meeptech pricing URL fallback
        }

        return [
            'name'        => $plan_name,
            'limit'       => $plan_limit,
            'limit_k'     => round($plan_limit / 1000),
            'slug'        => $plan_slug,
            'upgrade_url' => $upgrade_url,
        ];
    }

    /**
     * AJAX endpoint to recalculate inventory stats on demand
     */
    public static function refresh_inventory_stats_ajax() {
        try {
            check_ajax_referer('aitrfoel_inventory_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('Insufficient permissions.', 'ai-translator-for-elementor-and-polylang')]);
            }

            $inventory = self::get_site_content_inventory(true);
            wp_send_json_success($inventory);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX handler to clear Translation Memory
     */
    public static function clear_translation_memory_ajax() {
        try {
            check_ajax_referer('aitrfoel_tm_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('Insufficient permissions.', 'ai-translator-for-elementor-and-polylang')]);
            }

            if (class_exists('AITRFOEL_Translation_Memory')) {
                AITRFOEL_Translation_Memory::get_instance()->clear_memory();
            }

            wp_send_json_success([
                'message' => __('Translation Memory cleared successfully.', 'ai-translator-for-elementor-and-polylang'),
                'stats'   => [
                    'total_entries'     => 0,
                    'total_words_saved' => 0,
                    'total_hits'        => 0,
                ],
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    public static function render_page() {
        $license_key = get_option('aitrfoel_license_key', '');
        $license_status = 'Free';
        if (function_exists('aitrfoel_fs') && aitrfoel_fs()->is_registered()) {
            $plan = aitrfoel_fs()->get_plan();
            $license_status = $plan ? $plan->name : 'Free';
        }
        // Get usage from server (not local cache)
        $words_used = 0;
        $word_limit = 7200; // Default free limit
        if (class_exists('AITRFOEL_License_Manager_Server')) {
            $license_manager = AITRFOEL_License_Manager_Server::get_instance();
            $license_info = $license_manager->get_license_status();
            $words_used = $license_info['words_used'];
            $word_limit = $license_info['word_limit'];
        }
        // Claude and DeepL are paid-plan engines (the server enforces this too).
        $engines_plan     = isset($license_info['plan']) ? (string) $license_info['plan'] : $license_status;
        $premium_engines  = $engines_plan !== '' && strtolower($engines_plan) !== 'free';
        $engines_upgrade  = aitrfoel_free_upgrade_url('plugin-settings', 'engine-upgrade');
        $usage_percent = null;
        if ($word_limit !== -1 && $word_limit > 0) {
            $usage_percent = (int) min(100, round(($words_used / $word_limit) * 100));
        }
        $cache_enabled = get_option('aitrfoel_cache_enabled', true);
        $cache_duration = get_option('aitrfoel_cache_duration', 30);
        $translated_page_status = get_option('aitrfoel_translated_page_status', 'publish');
        ?>
        
        <div class="wrap aitrfoel-admin-wrapper">
            <?php 
            // Render plugin header
            $admin_menu = AITRFOEL_Admin_Menu::get_instance();
            $admin_menu->render_plugin_header(__('Settings', 'ai-translator-for-elementor-and-polylang'));
            ?>
            
            <?php settings_errors(); ?>
            
            <form method="post" action="options.php">
                <?php settings_fields('aitrfoel_settings_group'); ?>

                <!-- Settings 6-Tab Navigation Carousel -->
                <div class="aitrfoel-tabs-carousel-container">
                    <button type="button" class="aitrfoel-carousel-arrow aitrfoel-carousel-prev" id="aitrfoel-tabs-prev" aria-label="<?php esc_attr_e('Previous tabs', 'ai-translator-for-elementor-and-polylang'); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
                    </button>
                    <div class="aitrfoel-settings-tabs-wrapper" id="aitrfoel-settings-tabs-wrapper">
                        <button type="button" class="aitrfoel-settings-tab-btn active" data-tab="tab-general">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="aitrfoel-tab-icon"><circle cx="8" cy="15" r="4"/><path d="M10.85 12.15L21 2M15 5l3 3M11 8l3 3"/></svg>
                            <span><?php esc_html_e('General & Performance', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </button>
                        <button type="button" class="aitrfoel-settings-tab-btn" data-tab="tab-languages">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="aitrfoel-tab-icon"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 010 20M12 2a15 15 0 000 20"/></svg>
                            <span><?php esc_html_e('Languages & Switcher', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </button>
                        <button type="button" class="aitrfoel-settings-tab-btn" data-tab="tab-components">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="aitrfoel-tab-icon"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                            <span><?php esc_html_e('Site Elements & Auto-Translate', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </button>
                        <button type="button" class="aitrfoel-settings-tab-btn" data-tab="tab-integrations">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="aitrfoel-tab-icon"><path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a6 6 0 01-12 0V8z"/></svg>
                            <span><?php esc_html_e('Plugin Integrations', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </button>
                        <button type="button" class="aitrfoel-settings-tab-btn" data-tab="tab-glossary">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="aitrfoel-tab-icon"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>
                            <span><?php esc_html_e('Brand & Glossary', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </button>
                        <button type="button" class="aitrfoel-settings-tab-btn" data-tab="tab-advanced">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="aitrfoel-tab-icon"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 11-4 0v-.09a1.65 1.65 0 00-1-1.51 1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 110-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 114 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 110 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                            <span><?php esc_html_e('Tools & Advanced', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </button>
                    </div>
                    <button type="button" class="aitrfoel-carousel-arrow aitrfoel-carousel-next" id="aitrfoel-tabs-next" aria-label="<?php esc_attr_e('Next tabs', 'ai-translator-for-elementor-and-polylang'); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                    </button>
                </div>

                <!-- ========================================================= -->
                <!-- TAB 1: General & Performance                              -->
                <!-- ========================================================= -->
                <div class="aitrfoel-tab-pane active" id="tab-general">
                
                <!-- License Configuration -->
                <div class="aitrfoel-settings-section" id="section-license">
                    <h3><?php esc_html_e('License Configuration', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label><?php esc_html_e('License', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <div class="aitrfoel-license-info">
                                    <div class="aitrfoel-license-status">
                                        <strong><?php esc_html_e('Current Plan:', 'ai-translator-for-elementor-and-polylang'); ?></strong> 
                                        <span class="aitrfoel-plan-name"><?php echo esc_html($license_status); ?></span>
                                    </div>
                                    <div class="aitrfoel-word-usage">
                                        <strong><?php esc_html_e('Words Translated:', 'ai-translator-for-elementor-and-polylang'); ?></strong> 
                                        <?php echo number_format($words_used); ?> / <?php echo $word_limit === -1 ? esc_html__('Unlimited', 'ai-translator-for-elementor-and-polylang') : number_format($word_limit); ?>
                                        <?php if ($usage_percent !== null): ?>
                                            <span class="aitrfoel-usage-percent">
                                                <?php printf(/* translators: %d: usage percent */ esc_html__('%d%% used', 'ai-translator-for-elementor-and-polylang'), (int) $usage_percent); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($word_limit !== -1): ?>
                                        <div class="aitrfoel-usage-bar">
                                            <div class="aitrfoel-usage-progress" style="width: <?php echo absint((int) $usage_percent); ?>%;"></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <p class="description">
                                    <?php esc_html_e('Get your License from:', 'ai-translator-for-elementor-and-polylang'); ?> 
                                    <a href="<?php echo esc_url('https://meeptech.com/upgrade?utm_source=wp-plugin&utm_medium=free-plugin&utm_campaign=pro-upgrade'); ?>"><?php esc_html_e('Upgrade page', 'ai-translator-for-elementor-and-polylang'); ?></a>
                                </p>
                                 <?php if (function_exists('eatp_fs') && is_object(eatp_fs()) && method_exists(eatp_fs(), 'is_registered') && (!eatp_fs()->is_registered() || (method_exists(eatp_fs(), 'is_free_plan') && eatp_fs()->is_free_plan()))): ?>
                                     <a href="<?php echo esc_url(method_exists(eatp_fs(), 'get_upgrade_url') ? eatp_fs()->get_upgrade_url() : 'https://meeptech.com/upgrade?utm_source=wp-plugin&utm_medium=free-plugin&utm_campaign=pro-upgrade'); ?>" class="button button-primary"><?php esc_html_e('Upgrade License', 'ai-translator-for-elementor-and-polylang'); ?></a>
                                 <?php elseif (!function_exists('eatp_fs') || !is_object(eatp_fs()) || !method_exists(eatp_fs(), 'is_registered') || !eatp_fs()->is_registered()): ?>
                                     <a href="https://meeptech.com/products/ai-translator-for-elementor-polylang/#pricing" target="_blank" class="button button-primary"><?php esc_html_e('Get License', 'ai-translator-for-elementor-and-polylang'); ?></a>
                                 <?php endif; ?>
                                 
                                 <?php if (function_exists('eatp_fs') && is_object(eatp_fs()) && method_exists(eatp_fs(), 'is_registered') && eatp_fs()->is_registered() && method_exists(eatp_fs(), 'is_free_plan') && !eatp_fs()->is_free_plan()): ?>
                                    <button type="button" id="aitrfoel-sync-plan" class="button button-secondary">
                                        <?php esc_html_e('Sync Plan', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </button>
                                    <span id="aitrfoel-sync-plan-status" class="aitrfoel-sync-plan-status"></span>
                                    <script>
                                    jQuery(document).ready(function($) {
                                        $('#aitrfoel-sync-plan').on('click', function() {
                                            var $btn = $(this);
                                            var $status = $('#aitrfoel-sync-plan-status');
                                            var originalText = $btn.text();
                                            
                                            $btn.prop('disabled', true).text('<?php echo esc_js(__('Syncing...', 'ai-translator-for-elementor-and-polylang')); ?>');
                                            $status.hide();
                                            
                                            $.ajax({
                                                url: ajaxurl,
                                                type: 'POST',
                                                data: {
                                                    action: 'aitrfoel_sync_plan',
                                                    nonce: '<?php echo esc_js(wp_create_nonce('aitrfoel_nonce')); ?>'
                                                },
                                                success: function(response) {
                                                    $btn.prop('disabled', false).text(originalText);
                                                    if (response.success) {
                                                        $status.removeClass('is-error').addClass('is-success').text('✓ ' + response.data.message).show();
                                                        // Reload page after 1.5 seconds to show updated plan
                                                        setTimeout(function() { location.reload(); }, 1500);
                                                    } else {
                                                        $status.removeClass('is-success').addClass('is-error').text('✗ ' + (response.data.message || 'Sync failed')).show();
                                                    }
                                                },
                                                error: function() {
                                                    $btn.prop('disabled', false).text(originalText);
                                                    $status.removeClass('is-success').addClass('is-error').text('✗ <?php echo esc_js(__('Connection error', 'ai-translator-for-elementor-and-polylang')); ?>').show();
                                                }
                                            });
                                        });
                                    });
                                    </script>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                       <!-- Inline Save (General & License) -->
                    <div class="aitrfoel-inline-save">
                        <?php submit_button(__('Save Settings', 'ai-translator-for-elementor-and-polylang'), 'primary', 'submit', false); ?>
                    </div>
                </div>

                <!-- AI Translation Engine Selection -->
                <div class="aitrfoel-settings-section" id="section-ai-engine">
                    <h3><?php esc_html_e('AI Translation Engine', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    <p class="description aitrfoel-section-intro">
                        <?php esc_html_e('Choose which specialized AI model or translation engine processes your website content.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                    
                    <?php
                    $selected_engine = get_option('aitrfoel_ai_engine', 'auto');
                    // On a free plan a saved Claude/DeepL choice runs on ChatGPT; show that honestly.
                    $engine_shown = (!$premium_engines && in_array($selected_engine, ['claude-3-5-sonnet', 'deepl'], true)) ? 'auto' : $selected_engine;
                    $locked_attr  = $premium_engines ? '' : ' aria-disabled="true" data-locked="1"';
                    $locked_badge = $premium_engines ? '' : '<span class="aitrfoel-engine-paid-badge">' . esc_html__('Paid plans', 'ai-translator-for-elementor-and-polylang') . '</span>';
                    ?>

                    <input type="hidden" id="aitrfoel_ai_engine" name="aitrfoel_ai_engine" value="<?php echo esc_attr($engine_shown); ?>">
                    <div class="aitrfoel-engine-cards-grid">
                        <!-- Auto / Smart Routing -->
                        <div class="aitrfoel-engine-card <?php echo $engine_shown === 'auto' ? 'active' : ''; ?>" data-engine="auto">
                            <div class="aitrfoel-engine-card-header">
                                <div class="aitrfoel-engine-icon-wrap" style="background:#eff6ff;color:#2563eb;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                                </div>
                                <span class="aitrfoel-engine-badge"><?php esc_html_e('Recommended', 'ai-translator-for-elementor-and-polylang'); ?></span>
                            </div>
                            <div class="aitrfoel-engine-title"><?php esc_html_e('Auto', 'ai-translator-for-elementor-and-polylang'); ?></div>
                            <div class="aitrfoel-engine-desc"><?php esc_html_e('Our fast default (ChatGPT), tuned for website content. Works on every plan.', 'ai-translator-for-elementor-and-polylang'); ?></div>
                        </div>

                        <!-- Anthropic Claude -->
                        <div class="aitrfoel-engine-card <?php echo $engine_shown === 'claude-3-5-sonnet' ? 'active' : ''; ?><?php echo $premium_engines ? '' : ' is-locked'; ?>" data-engine="claude-3-5-sonnet"<?php echo $locked_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string built in this file, no user input ?>>
                            <div class="aitrfoel-engine-card-header">
                                <div class="aitrfoel-engine-icon-wrap" style="background:#fdf2f8;color:#db2777;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a7 7 0 0 0-7 7c0 2.38 1.19 4.47 3 5.74V17a2 2 0 0 0 2 2h4a2 2 0 0 0 2-2v-2.26c1.81-1.27 3-3.36 3-5.74a7 7 0 0 0-7-7z"></path><line x1="9" y1="21" x2="15" y2="21"></line></svg>
                                </div>
                                <span class="aitrfoel-engine-tag"><?php esc_html_e('Brand Nuance', 'ai-translator-for-elementor-and-polylang'); ?></span><?php echo $locked_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html__ above ?>
                            </div>
                            <div class="aitrfoel-engine-title"><?php esc_html_e('Anthropic Claude', 'ai-translator-for-elementor-and-polylang'); ?></div>
                            <div class="aitrfoel-engine-desc"><?php esc_html_e('Industry gold standard for marketing copy, brand voice, and natural idiomatic flow.', 'ai-translator-for-elementor-and-polylang'); ?></div>
                        </div>

                        <!-- DeepL Pro -->
                        <div class="aitrfoel-engine-card <?php echo $engine_shown === 'deepl' ? 'active' : ''; ?><?php echo $premium_engines ? '' : ' is-locked'; ?>" data-engine="deepl"<?php echo $locked_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string built in this file, no user input ?>>
                            <div class="aitrfoel-engine-card-header">
                                <div class="aitrfoel-engine-icon-wrap" style="background:#f0fdf4;color:#16a34a;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                                </div>
                                <span class="aitrfoel-engine-tag"><?php esc_html_e('European Precision', 'ai-translator-for-elementor-and-polylang'); ?></span><?php echo $locked_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html__ above ?>
                            </div>
                            <div class="aitrfoel-engine-title"><?php esc_html_e('DeepL Pro', 'ai-translator-for-elementor-and-polylang'); ?></div>
                            <div class="aitrfoel-engine-desc"><?php esc_html_e('Unmatched accuracy and grammatical precision for European business languages.', 'ai-translator-for-elementor-and-polylang'); ?></div>
                        </div>

                        <!-- OpenAI GPT -->
                        <div class="aitrfoel-engine-card <?php echo $engine_shown === 'gpt-4o' ? 'active' : ''; ?>" data-engine="gpt-4o">
                            <div class="aitrfoel-engine-card-header">
                                <div class="aitrfoel-engine-icon-wrap" style="background:#faf5ff;color:#9333ea;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                                </div>
                                <span class="aitrfoel-engine-tag"><?php esc_html_e('High Speed', 'ai-translator-for-elementor-and-polylang'); ?></span>
                            </div>
                            <div class="aitrfoel-engine-title"><?php esc_html_e('OpenAI ChatGPT', 'ai-translator-for-elementor-and-polylang'); ?></div>
                            <div class="aitrfoel-engine-desc"><?php esc_html_e('Ultra-fast generation with broad global language vocabulary and context parsing.', 'ai-translator-for-elementor-and-polylang'); ?></div>
                        </div>
                    </div>
                    <?php if (!$premium_engines) : ?>
                        <p class="description aitrfoel-engine-upgrade-note">
                            <?php esc_html_e('Claude and DeepL are included in every paid plan. On the free plan, translations use ChatGPT.', 'ai-translator-for-elementor-and-polylang'); ?>
                            <a href="<?php echo esc_url($engines_upgrade); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('See plans', 'ai-translator-for-elementor-and-polylang'); ?> &rarr;</a>
                        </p>
                    <?php endif; ?>

                    <div class="aitrfoel-inline-save">
                        <?php submit_button(__('Save Settings', 'ai-translator-for-elementor-and-polylang'), 'primary', 'submit', false); ?>
                    </div>
                </div>

                <!-- Autonomous Background Auto-Sync -->
                <div class="aitrfoel-settings-section" id="section-auto-sync">
                    <h3><?php esc_html_e('Autonomous Background Auto-Sync', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    <p class="description aitrfoel-section-intro">
                        <?php esc_html_e('True "Set & Forget" automation. When content is updated in your default language, all linked translations automatically sync in the background using Translation Memory.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_enable_background_sync"><?php esc_html_e('Auto-Sync on Update', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                                    <label class="aitrfoel-switch" style="margin:0;flex-shrink:0;">
                                        <input type="checkbox" id="aitrfoel_enable_background_sync" name="aitrfoel_enable_background_sync" value="1" <?php checked(get_option('aitrfoel_enable_background_sync', 1), 1); ?>>
                                        <span class="aitrfoel-slider"></span>
                                    </label>
                                    <span class="description" style="margin:0;line-height:1.4;">
                                        <?php esc_html_e('Automatically detect edits on pages, posts, and WooCommerce products and update all languages in the background.', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </span>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <div class="aitrfoel-inline-save">
                        <?php submit_button(__('Save Settings', 'ai-translator-for-elementor-and-polylang'), 'primary', 'submit', false); ?>
                    </div>
                </div>

                <!-- Site Content & Word Inventory -->
                <?php 
                $inventory = self::get_site_content_inventory(); 
                $recommended_plan = $inventory['recommended_plan'] ?? self::get_recommended_plan_for_words($inventory['estimated_total_demand'] ?? 0);
                ?>
                <div class="aitrfoel-settings-section aitrfoel-inventory-section" id="section-content-inventory">
                    <div class="aitrfoel-inventory-header" style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;flex-wrap:wrap;gap:10px;">
                        <div class="aitrfoel-inventory-title-col">
                            <h3 style="margin:0;"><?php esc_html_e('Site Content & Word Inventory', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                        </div>
                        <div class="aitrfoel-inventory-actions" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <a href="<?php echo esc_url($recommended_plan['upgrade_url']); ?>" target="_blank" rel="noopener" class="button button-primary button-small aitrfoel-plan-recommend-btn" id="inv-recommended-plan-btn" style="display:inline-flex;align-items:center;gap:6px;background:#1447e6;border-color:#1447e6;color:#ffffff;font-weight:600;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg>
                                <span id="inv-recommended-plan-label"><?php printf(/* translators: 1: recommended plan name, 2: plan word limit in thousands */ esc_html__('Get %s Plan (~%sk words)', 'ai-translator-for-elementor-and-polylang'), esc_html($recommended_plan['name']), esc_html($recommended_plan['limit_k'])); ?></span>
                            </a>
                            <button type="button" id="aitrfoel-refresh-inventory-btn" class="button button-secondary button-small" style="display:inline-flex;align-items:center;gap:5px;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" class="aitrfoel-refresh-svg" style="flex-shrink:0;"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>
                                <span id="aitrfoel-refresh-inv-label"><?php esc_html_e('Recalculate Stats', 'ai-translator-for-elementor-and-polylang'); ?></span>
                            </button>
                        </div>
                    </div>
                    <p class="description" style="margin-bottom:16px;">
                        <?php esc_html_e('Real-time overview of all translatable content, source words, and estimated full site translation demand across your active target languages.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>

                    <!-- 4 Metric Cards -->
                    <div class="aitrfoel-inventory-grid">
                        <div class="aitrfoel-inv-stat-card">
                            <div class="aitrfoel-inv-stat-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                            </div>
                            <div class="aitrfoel-inv-stat-info">
                                <div class="aitrfoel-inv-stat-num" id="inv-stat-items"><?php echo esc_html(number_format_i18n($inventory['total_items'])); ?></div>
                                <div class="aitrfoel-inv-stat-label"><?php esc_html_e('Total Content Items', 'ai-translator-for-elementor-and-polylang'); ?></div>
                            </div>
                        </div>
                        <div class="aitrfoel-inv-stat-card">
                            <div class="aitrfoel-inv-stat-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                            </div>
                            <div class="aitrfoel-inv-stat-info">
                                <div class="aitrfoel-inv-stat-num" id="inv-stat-words">~<?php echo esc_html(number_format_i18n($inventory['total_source_words'])); ?></div>
                                <div class="aitrfoel-inv-stat-label"><?php esc_html_e('Total Source Words', 'ai-translator-for-elementor-and-polylang'); ?></div>
                            </div>
                        </div>
                        <div class="aitrfoel-inv-stat-card">
                            <div class="aitrfoel-inv-stat-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                            </div>
                            <div class="aitrfoel-inv-stat-info">
                                <div class="aitrfoel-inv-stat-num" id="inv-stat-langs"><?php echo (int) $inventory['target_lang_count']; ?></div>
                                <div class="aitrfoel-inv-stat-label"><?php esc_html_e('Target Languages', 'ai-translator-for-elementor-and-polylang'); ?></div>
                            </div>
                        </div>
                        <div class="aitrfoel-inv-stat-card highlight">
                            <div class="aitrfoel-inv-stat-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                            </div>
                            <div class="aitrfoel-inv-stat-info">
                                <div class="aitrfoel-inv-stat-num" id="inv-stat-demand">~<?php echo esc_html(number_format_i18n($inventory['estimated_total_demand'])); ?></div>
                                <div class="aitrfoel-inv-stat-label"><?php esc_html_e('Full Translation Volume', 'ai-translator-for-elementor-and-polylang'); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Content Type Breakdown -->
                    <div class="aitrfoel-inv-breakdown-box" style="margin-top:18px;">
                        <h4 style="margin:0 0 10px 0;font-size:13px;font-weight:600;color:#334155;"><?php esc_html_e('Content Breakdown by Type', 'ai-translator-for-elementor-and-polylang'); ?></h4>
                        <div class="aitrfoel-inv-breakdown-list" id="inv-breakdown-list">
                            <?php 
                            $breakdown_icon_map = [
                                'pages'     => '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
                                'posts'     => '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>',
                                'templates' => '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>',
                                'products'  => '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>',
                            ];
                            foreach ($inventory['breakdown'] as $group_key => $group): 
                                $row_icon = (isset($group['icon']) && strpos($group['icon'], '<svg') !== false) 
                                    ? $group['icon'] 
                                    : ($breakdown_icon_map[$group_key] ?? $breakdown_icon_map['pages']);
                            ?>
                                <div class="aitrfoel-inv-breakdown-row" data-group="<?php echo esc_attr($group_key); ?>">
                                    <div class="aitrfoel-inv-type-name">
                                        <span class="aitrfoel-inv-icon"><?php echo $row_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                        <strong><?php echo esc_html($group['label']); ?></strong>
                                    </div>
                                    <div class="aitrfoel-inv-type-stats">
                                        <span class="aitrfoel-inv-count"><?php printf(/* translators: %d: number of items in this content group */ esc_html__('%d items', 'ai-translator-for-elementor-and-polylang'), (int) $group['count']); ?></span>
                                        <span class="aitrfoel-inv-words">~<?php echo esc_html(number_format_i18n($group['words'])); ?> <?php esc_html_e('words', 'ai-translator-for-elementor-and-polylang'); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Page Creation Settings -->
                <div class="aitrfoel-settings-section" id="section-page-creation">
                    <h3><?php esc_html_e('Page Creation Settings', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_translated_page_status"><?php esc_html_e('Translated Page Status', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <select id="aitrfoel_translated_page_status" name="aitrfoel_translated_page_status" class="aitrfoel-settings-select">
                                    <option value="publish" <?php selected($translated_page_status, 'publish'); ?>>
                                        <?php esc_html_e('Published (Recommended)', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </option>
                                    <option value="draft" <?php selected($translated_page_status, 'draft'); ?>>
                                        <?php esc_html_e('Draft (Review before publishing)', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </option>
                                    <option value="inherit" <?php selected($translated_page_status, 'inherit'); ?>>
                                        <?php esc_html_e('Inherit from Original', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </option>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Status for newly created translated pages. Published makes translations immediately accessible.', 'ai-translator-for-elementor-and-polylang'); ?>
                                    <br/>
                                    <strong><?php esc_html_e('Note:', 'ai-translator-for-elementor-and-polylang'); ?></strong> 
                                    <?php esc_html_e('LiteSpeed Cache optimizations only apply to published pages viewed by visitors.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <!-- Inline Save (after Pages) -->
                    <div class="aitrfoel-inline-save">
                        <?php submit_button(__('Save Settings', 'ai-translator-for-elementor-and-polylang'), 'primary', 'submit', false); ?>
                        <button type="button" class="button aitrfoel-reset-defaults" data-sections="page-creation" title="<?php esc_attr_e('Reset Page Creation settings to defaults', 'ai-translator-for-elementor-and-polylang'); ?>"><?php esc_html_e('Reset Pages Defaults', 'ai-translator-for-elementor-and-polylang'); ?></button>
                    </div>
                </div>

                <!-- List Screens Translation Toolbar -->
                <div class="aitrfoel-settings-section" id="section-list-toolbar">
                    <h3><?php esc_html_e('List Screens Translation Toolbar', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    <p class="description aitrfoel-section-intro">
                        <?php esc_html_e('Configure the quick-action translation toolbar displayed on WordPress admin Posts and Pages list screens.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_list_toolbar_enabled"><?php esc_html_e('Floating Toolbar', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <input type="checkbox" 
                                       id="aitrfoel_list_toolbar_enabled" 
                                       name="aitrfoel_list_toolbar_enabled" 
                                       value="1" 
                                       <?php checked(get_option('aitrfoel_list_toolbar_enabled', true)); ?> />
                                <label for="aitrfoel_list_toolbar_enabled"><?php esc_html_e('Show floating translation toolbar when selecting items on Posts, Pages, Products, and CPT list screens', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                <p class="description">
                                    <?php esc_html_e('Displays real-time required word calculation, plan quota status, and 1-click batch translation for selected items.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <div class="aitrfoel-inline-save">
                        <?php submit_button(__('Save Settings', 'ai-translator-for-elementor-and-polylang'), 'primary', 'submit', false); ?>
                    </div>
                </div>

                <!-- Cache Settings -->
                <div class="aitrfoel-settings-section" id="section-cache">
                    <h3><?php esc_html_e('Cache Settings', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_cache_enabled"><?php esc_html_e('Enable Cache', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <input type="checkbox" 
                                       id="aitrfoel_cache_enabled" 
                                       name="aitrfoel_cache_enabled" 
                                       value="1" 
                                       <?php checked($cache_enabled); ?> />
                                <label for="aitrfoel_cache_enabled"><?php esc_html_e('Cache translations to avoid duplicate API calls', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_cache_duration"><?php esc_html_e('Cache Duration (days)', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <input type="number" 
                                       id="aitrfoel_cache_duration" 
                                       name="aitrfoel_cache_duration" 
                                       value="<?php echo esc_attr($cache_duration); ?>" 
                                       min="1" 
                                       max="365" 
                                       class="small-text" />
                                <p class="description"><?php esc_html_e('How long to keep translations in cache before refreshing.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <?php esc_html_e('Clear Cache', 'ai-translator-for-elementor-and-polylang'); ?>
                            </th>
                            <td>
                                <button type="button" class="button" id="aitrfoel-clear-cache-btn">
                                    <?php esc_html_e('Clear Translation Cache', 'ai-translator-for-elementor-and-polylang'); ?>
                                </button>
                                <span id="aitrfoel-clear-cache-status" class="aitrfoel-action-status"></span>
                                <p class="description">
                                    <?php esc_html_e('Remove all cached translations. Useful when you want to regenerate translations with updated content or settings.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <!-- Inline Save (after Cache) -->
                    <div class="aitrfoel-inline-save">
                        <?php submit_button(__('Save Settings', 'ai-translator-for-elementor-and-polylang'), 'primary', 'submit', false); ?>
                        <button type="button" class="button aitrfoel-reset-defaults" data-sections="cache" title="<?php esc_attr_e('Reset Cache settings to defaults', 'ai-translator-for-elementor-and-polylang'); ?>"><?php esc_html_e('Reset Cache Defaults', 'ai-translator-for-elementor-and-polylang'); ?></button>
                    </div>
                </div>
                
                </div><!-- /#tab-general -->

                <!-- ========================================================= -->
                <!-- TAB 2: Languages & Switcher                               -->
                <!-- ========================================================= -->
                <div class="aitrfoel-tab-pane" id="tab-languages">
                
                <!-- Multilingual Plugin Info -->
                <div class="aitrfoel-settings-section" id="section-multilingual">
                    <h3><?php esc_html_e('Multilingual Plugin', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label><?php esc_html_e('Active Plugin', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <?php
                                try {
                                    $has_plugin = false;
                                    $plugin_name = '';
                                    $default_lang = '';
                                    $lang_codes = [];
                                    $is_polylang = false;
                                    $is_builtin = false;
                                    
                                    // Check for built-in language system FIRST (user's explicit choice)
                                    if (class_exists('AITRFOEL_Builtin_Integration') && AITRFOEL_Builtin_Integration::is_enabled()) {
                                        $has_plugin = true;
                                        $is_builtin = true;
                                        $plugin_name = 'Meep Translator Built-in';
                                        $builtin = AITRFOEL_Builtin_Integration::get_instance();
                                        $default_lang = $builtin->get_default_language() ?: '';
                                        $languages = $builtin->get_available_languages();
                                        $lang_codes = array_keys($languages);
                                    }
                                    // Check for Polylang
                                    elseif (function_exists('pll_languages_list') && function_exists('pll_default_language')) {
                                        $has_plugin = true;
                                        $plugin_name = 'Polylang';
                                        $is_polylang = true;
                                        $default_lang = pll_default_language('slug');
                                        $lang_codes = pll_languages_list(['fields' => 'slug']);
                                    }
                                    // Check for WPML
                                    elseif (defined('ICL_SITEPRESS_VERSION')) {
                                        global $sitepress;
                                        if (isset($sitepress) && is_object($sitepress) && method_exists($sitepress, 'get_default_language')) {
                                            $has_plugin = true;
                                            $plugin_name = 'WPML';
                                            $default_lang = $sitepress->get_default_language();
                                            $active_langs = $sitepress->get_active_languages();
                                            $lang_codes = is_array($active_langs) ? array_keys($active_langs) : [];
                                        }
                                    }
                                    
                                    if ($has_plugin && !empty($plugin_name)) {
                                        $lang_count = is_array($lang_codes) ? count($lang_codes) : 0;
                                        
                                        // Get the language settings URL for the active plugin
                                        $manage_languages_url = '';
                                        if ($is_polylang) {
                                            $manage_languages_url = admin_url('admin.php?page=mlang');
                                        } elseif (!$is_builtin) {
                                            // WPML languages page
                                            $manage_languages_url = admin_url('admin.php?page=sitepress-multilingual-cms/menu/languages.php');
                                        }
                                        ?>
                                        <div class="aitrfoel-multilingual-info">
                                            <span class="aitrfoel-plugin-badge aitrfoel-plugin-active">
                                                <?php echo esc_html($plugin_name); ?>
                                            </span>
                                            <p class="description aitrfoel-settings-description-top-sm">
                                                <strong><?php esc_html_e('Default Language:', 'ai-translator-for-elementor-and-polylang'); ?></strong> 
                                                <?php echo AITRFOEL_Language_Mapper::get_flag_html($default_lang, 16, 'margin-right: 4px;'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag <img> from AITRFOEL_Language_Mapper::get_flag_html(), which escapes its URL, alt and style ?>
                                                <?php echo esc_html(strtoupper($default_lang)); ?>
                                                <br>
                                                <strong><?php esc_html_e('Available Languages:', 'ai-translator-for-elementor-and-polylang'); ?></strong> 
                                                <?php echo esc_html($lang_count); ?> 
                                                (<?php 
                                                $lang_labels = [];
                                                foreach ($lang_codes as $lc) {
                                                    $lang_labels[] = AITRFOEL_Language_Mapper::get_flag_html($lc, 16, 'margin-right: 2px;') . ' ' . esc_html(strtoupper($lc));
                                                }
                                                echo implode(', ', $lang_labels); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped with esc_html()/esc_attr() where the value is built
                                                ?>)
                                            </p>
                                            <?php if ($is_builtin): ?>
                                                <!-- Built-in language management controls -->
                                                <?php self::render_builtin_language_management($builtin); ?>
                                            <?php else: ?>
                                                <p class="aitrfoel-settings-actions-row">
                                                    <a href="<?php echo esc_url($manage_languages_url); ?>" class="button button-secondary">
                                                        <?php esc_html_e('Manage Languages', 'ai-translator-for-elementor-and-polylang'); ?>
                                                    </a>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        <?php
                                    } else {
                                        ?>
                                        <div class="aitrfoel-multilingual-info">
                                            <span class="aitrfoel-plugin-badge aitrfoel-plugin-missing">
                                                <?php esc_html_e('No multilingual plugin detected', 'ai-translator-for-elementor-and-polylang'); ?>
                                            </span>
                                            <p class="description aitrfoel-settings-description-top-sm">
                                                <?php esc_html_e('Please install and activate either Polylang or WPML, or use the built-in language system.', 'ai-translator-for-elementor-and-polylang'); ?>
                                                <br>
                                                <a href="https://wordpress.org/plugins/polylang/" target="_blank">Polylang</a> | 
                                                <a href="https://wpml.org/" target="_blank">WPML</a> |
                                                <a href="<?php echo esc_url(admin_url('admin.php?page=ai-translator-for-elementor-and-polylang')); ?>"><?php esc_html_e('Set Up Built-in', 'ai-translator-for-elementor-and-polylang'); ?></a>
                                            </p>
                                        </div>
                                        <?php
                                    }
                                } catch (Exception $e) {
                                    ?>
                                    <div class="aitrfoel-multilingual-info">
                                        <span class="aitrfoel-plugin-badge aitrfoel-plugin-missing">
                                            <?php esc_html_e('Error detecting multilingual plugin', 'ai-translator-for-elementor-and-polylang'); ?>
                                        </span>
                                    </div>
                                    <?php
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Translate URL Slugs', 'ai-translator-for-elementor-and-polylang'); ?></th>
                            <td>
                                <?php $translate_url_slugs = get_option('aitrfoel_translate_url_slugs', true); ?>
                                <label>
                                    <input type="checkbox" name="aitrfoel_translate_url_slugs" value="1" <?php checked($translate_url_slugs); ?> />
                                    <?php esc_html_e('Generate translated URL slugs from translated titles', 'ai-translator-for-elementor-and-polylang'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('When enabled, translated posts/pages/products get localized slugs (post_name). Disable to keep source-style slugs instead of translating them.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Preserve Slugs on Retranslation', 'ai-translator-for-elementor-and-polylang'); ?></th>
                            <td>
                                <?php $preserve_slug_on_retranslate = get_option('aitrfoel_preserve_slug_on_retranslate', true); ?>
                                <label>
                                    <input type="checkbox" name="aitrfoel_preserve_slug_on_retranslate" value="1" <?php checked($preserve_slug_on_retranslate); ?> />
                                    <?php esc_html_e('Preserve existing URL slugs when re-translating already published pages', 'ai-translator-for-elementor-and-polylang'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('Recommended. Protects your live SEO rankings, bookmarks, and external backlinks by keeping the existing slug unchanged when content is re-translated. Only newly created translations will generate new slugs.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Localize Internal Links', 'ai-translator-for-elementor-and-polylang'); ?></th>
                            <td>
                                <?php $localize_internal_links = get_option('aitrfoel_localize_internal_links', true); ?>
                                <label>
                                    <input type="checkbox" name="aitrfoel_localize_internal_links" value="1" <?php checked($localize_internal_links); ?> />
                                    <?php esc_html_e('Rewrite absolute internal links (e.g. /fr/...) in content and widgets to the current language', 'ai-translator-for-elementor-and-polylang'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('When enabled, hard-coded internal links inside content/Elementor widgets are pointed to the translated target on translated pages (when a translation exists). Links without a translation are left unchanged.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Default Language URL Prefix', 'ai-translator-for-elementor-and-polylang'); ?></th>
                            <td>
                                <?php $default_lang_prefix = get_option('aitrfoel_default_lang_prefix', false); ?>
                                <label>
                                    <input type="checkbox" name="aitrfoel_default_lang_prefix" value="1" <?php checked($default_lang_prefix); ?> />
                                    <?php esc_html_e('Add language prefix to the default language URLs (e.g., /fr/page/ instead of /page/)', 'ai-translator-for-elementor-and-polylang'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('When enabled, the default language will also get a URL prefix like other languages. Visitors accessing pages without a prefix will be redirected to the prefixed version. Only works with the built-in language system.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Exclude Paths from Language Routing', 'ai-translator-for-elementor-and-polylang'); ?></th>
                            <td>
                                <?php $routing_excluded_paths = get_option('aitrfoel_routing_excluded_paths', ''); ?>
                                <textarea name="aitrfoel_routing_excluded_paths" rows="4" cols="50" class="large-text code" placeholder="wcsag-api&#10;my-plugin-endpoint"><?php echo esc_textarea($routing_excluded_paths); ?></textarea>
                                <p class="description">
                                    <?php esc_html_e('One path or token per line. Matching URLs are never given a language prefix and never redirected — useful for plugin API/rewrite endpoints, webhooks, and callbacks. Example: enter "wcsag-api" to exclude /wcsag-api/... requests.', 'ai-translator-for-elementor-and-polylang'); ?>
                                    <br>
                                    <?php esc_html_e('REST API (/wp-json/), admin-ajax, WooCommerce API (/wc-api/), feeds, login and XML-RPC are always excluded automatically.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <!-- Inline Save (after Multilingual Plugin) -->
                    <div class="aitrfoel-inline-save">
                        <?php submit_button(__('Save Settings', 'ai-translator-for-elementor-and-polylang'), 'primary', 'submit', false); ?>
                    </div>
                </div>
                
                <!-- Language Switcher Settings -->
                <div class="aitrfoel-settings-section" id="section-switcher">
                    <h3><?php esc_html_e('Language Switcher', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    
                    <p class="description aitrfoel-switcher-intro">
                        <?php esc_html_e('Configure the language switcher that visitors use to change the language on your site. You can add it using a shortcode, a WordPress widget, or a nav menu integration.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_switcher_enabled"><?php esc_html_e('Enable Switcher', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <?php $switcher_enabled = get_option('aitrfoel_switcher_enabled', true); ?>
                                <label>
                                    <input type="checkbox" id="aitrfoel_switcher_enabled" name="aitrfoel_switcher_enabled" value="1" <?php checked($switcher_enabled); ?> />
                                    <?php esc_html_e('Output the language switcher on the frontend (shortcode, widget, and nav menu)', 'ai-translator-for-elementor-and-polylang'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('Uncheck to completely disable all language switcher output on your site.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_switcher_style"><?php esc_html_e('Switcher Style', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <?php $switcher_style = get_option('aitrfoel_switcher_style', 'flags-text'); ?>
                                <select id="aitrfoel_switcher_style" name="aitrfoel_switcher_style">
                                    <option value="dropdown" <?php selected($switcher_style, 'dropdown'); ?>>
                                        <?php esc_html_e('Dropdown', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </option>
                                    <option value="list" <?php selected($switcher_style, 'list'); ?>>
                                        <?php esc_html_e('Horizontal List', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </option>
                                    <option value="flags" <?php selected($switcher_style, 'flags'); ?>>
                                        <?php esc_html_e('Flags Only', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </option>
                                    <option value="flags-text" <?php selected($switcher_style, 'flags-text'); ?>>
                                        <?php esc_html_e('Flags + Language Code', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_switcher_theme"><?php esc_html_e('Switcher Theme', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <?php $switcher_theme = get_option('aitrfoel_switcher_theme', 'default'); ?>
                                <select id="aitrfoel_switcher_theme" name="aitrfoel_switcher_theme">
                                    <option value="default" <?php selected($switcher_theme, 'default'); ?>>
                                        <?php esc_html_e('Default (bordered)', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </option>
                                    <option value="light" <?php selected($switcher_theme, 'light'); ?>>
                                        <?php esc_html_e('Light border', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </option>
                                    <option value="minimal" <?php selected($switcher_theme, 'minimal'); ?>>
                                        <?php esc_html_e('Minimal (no border / transparent)', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </option>
                                    <option value="pill" <?php selected($switcher_theme, 'pill'); ?>>
                                        <?php esc_html_e('Pill (rounded)', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </option>
                                </select>
                                <p class="description"><?php esc_html_e('Visual style for the Dropdown switcher. Tip: for a compact flag-only switcher, choose the Dropdown style and turn off "Show language names" — the languages still appear (with names) inside the dropdown.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_switcher_border_color"><?php esc_html_e('Border Color', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <?php $switcher_border_color = get_option('aitrfoel_switcher_border_color', ''); ?>
                                <input type="color" id="aitrfoel_switcher_border_color_picker" value="<?php echo esc_attr($switcher_border_color ?: '#cccccc'); ?>"
                                       oninput="document.getElementById('aitrfoel_switcher_border_color').value=this.value;" style="vertical-align:middle;width:48px;height:32px;padding:0;" />
                                <input type="text" id="aitrfoel_switcher_border_color" name="aitrfoel_switcher_border_color" value="<?php echo esc_attr($switcher_border_color); ?>" placeholder="#cccccc" class="regular-text" style="width:120px;vertical-align:middle;" />
                                <p class="description"><?php esc_html_e('Border colour for the switcher (and dropdown). Leave empty to use the theme default.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_switcher_flag_size"><?php esc_html_e('Flag Size (px)', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <?php $switcher_flag_size = (int) get_option('aitrfoel_switcher_flag_size', 0); ?>
                                <input type="number" id="aitrfoel_switcher_flag_size" name="aitrfoel_switcher_flag_size" value="<?php echo $switcher_flag_size ? esc_attr($switcher_flag_size) : ''; ?>" min="8" max="128" step="1" placeholder="20" style="width:90px;" />
                                <p class="description"><?php esc_html_e('Flag width in pixels (8–128). Leave empty for the default size.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><?php esc_html_e('Display Options', 'ai-translator-for-elementor-and-polylang'); ?></th>
                            <td>
                                <?php $show_flags = get_option('aitrfoel_switcher_show_flags', true); ?>
                                <?php $show_names = get_option('aitrfoel_switcher_show_names', true); ?>
                                <?php $name_display = get_option('aitrfoel_switcher_name_display', 'native'); ?>
                                <label>
                                    <input type="checkbox" name="aitrfoel_switcher_show_flags" value="1" <?php checked($show_flags); ?> />
                                    <?php esc_html_e('Show country flags', 'ai-translator-for-elementor-and-polylang'); ?>
                                </label>
                                <br>
                                <label>
                                    <input type="checkbox" name="aitrfoel_switcher_show_names" value="1" <?php checked($show_names); ?> />
                                    <?php esc_html_e('Show language names', 'ai-translator-for-elementor-and-polylang'); ?>
                                </label>
                                <br>
                                <?php $dropdown_names = get_option('aitrfoel_switcher_dropdown_names', true); ?>
                                <label>
                                    <input type="checkbox" name="aitrfoel_switcher_dropdown_names" value="1" <?php checked($dropdown_names); ?> />
                                    <?php esc_html_e('Show language names inside the dropdown list', 'ai-translator-for-elementor-and-polylang'); ?>
                                </label>
                                <p class="description" style="margin-top:4px;"><?php esc_html_e('Applies to the Dropdown style only. Turn the trigger to flag-only above and leave this on to get a compact flag with named entries in the dropdown; turn it off for flags-only entries.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                                <br>
                                <?php $mobile_names = get_option('aitrfoel_switcher_mobile_names', false); ?>
                                <label>
                                    <input type="checkbox" name="aitrfoel_switcher_mobile_names" value="1" <?php checked($mobile_names); ?> />
                                    <?php esc_html_e('Show language names on mobile (flag-only on desktop)', 'ai-translator-for-elementor-and-polylang'); ?>
                                </label>
                                <p class="description" style="margin-top:4px;"><?php esc_html_e('Dropdown style only, when language names are off. Keeps the desktop header compact (flag only) but shows the language name in the mobile menu, where there is more room.', 'ai-translator-for-elementor-and-polylang'); ?></p>

                                <div id="aitrfoel-name-display-options" class="aitrfoel-name-display-options<?php echo !$show_names ? ' is-hidden' : ''; ?>">
                                    <label class="aitrfoel-name-display-label"><?php esc_html_e('Name format:', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                    <select name="aitrfoel_switcher_name_display" class="aitrfoel-name-display-select">
                                        <option value="native" <?php selected($name_display, 'native'); ?>><?php esc_html_e('Native (Français, Deutsch)', 'ai-translator-for-elementor-and-polylang'); ?></option>
                                        <option value="english" <?php selected($name_display, 'english'); ?>><?php esc_html_e('English (French, German)', 'ai-translator-for-elementor-and-polylang'); ?></option>
                                        <option value="code" <?php selected($name_display, 'code'); ?>><?php esc_html_e('Code uppercase (FR, DE)', 'ai-translator-for-elementor-and-polylang'); ?></option>
                                        <option value="code_lower" <?php selected($name_display, 'code_lower'); ?>><?php esc_html_e('Code lowercase (fr, de)', 'ai-translator-for-elementor-and-polylang'); ?></option>
                                    </select>
                                    
                                    <?php
                                    $custom_names = get_option('aitrfoel_switcher_custom_names', []);
                                    $switcher_languages = [];
                                    if (class_exists('AITRFOEL_Builtin_Integration') && AITRFOEL_Builtin_Integration::is_enabled()) {
                                        $bi = AITRFOEL_Builtin_Integration::get_instance();
                                        $switcher_languages = $bi->get_available_languages();
                                    }
                                    if (!empty($switcher_languages)): ?>
                                    <div class="aitrfoel-custom-names-wrap">
                                        <label class="aitrfoel-name-display-label"><?php esc_html_e('Custom names (optional — overrides the format above):', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                        <div class="aitrfoel-custom-names-grid">
                                            <?php foreach ($switcher_languages as $sl): ?>
                                            <div class="aitrfoel-custom-name-item">
                                                <?php echo AITRFOEL_Language_Mapper::get_flag_html($sl->code, 16); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag <img> from AITRFOEL_Language_Mapper::get_flag_html(), which escapes its URL, alt and style ?>
                                                <input type="text" name="aitrfoel_switcher_custom_names[<?php echo esc_attr($sl->code); ?>]" value="<?php echo esc_attr($custom_names[$sl->code] ?? ''); ?>" placeholder="<?php echo esc_attr($sl->native_name ?? $sl->name); ?>" class="aitrfoel-custom-name-input">
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <p class="description aitrfoel-custom-names-note"><?php esc_html_e('Leave empty to use the selected format above.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php esc_html_e('Shortcode', 'ai-translator-for-elementor-and-polylang'); ?></th>
                            <td>
                                <div class="aitrfoel-switcher-shortcode-box">
                                    <code id="aitrfoel-switcher-shortcode" class="aitrfoel-switcher-shortcode">[aitrfoel_language_switcher style="<?php echo esc_attr($switcher_style); ?>" show_flags="<?php echo $show_flags ? 'true' : 'false'; ?>" show_names="<?php echo $show_names ? 'true' : 'false'; ?>"]</code>
                                    <button type="button" class="button button-small aitrfoel-switcher-copy-btn" onclick="navigator.clipboard.writeText(document.getElementById('aitrfoel-switcher-shortcode').textContent).then(function(){var b=event.target;b.textContent='<?php echo esc_js(__('Copied!', 'ai-translator-for-elementor-and-polylang')); ?>';setTimeout(function(){b.textContent='<?php echo esc_js(__('Copy', 'ai-translator-for-elementor-and-polylang')); ?>';},1500)})">
                                        <?php esc_html_e('Copy', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </button>
                                </div>
                                <p class="description">
                                    <?php esc_html_e('Copy this shortcode and paste it into any page, post, or text widget where you want the language switcher to appear.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                                
                                <div class="aitrfoel-switcher-help-box">
                                    <strong><?php esc_html_e('Alternative Ways to Add:', 'ai-translator-for-elementor-and-polylang'); ?></strong>
                                    <ul class="aitrfoel-switcher-help-list">
                                        <li>
                                            <strong><?php esc_html_e('WordPress Widget:', 'ai-translator-for-elementor-and-polylang'); ?></strong>
                                            <?php printf(
                                                /* translators: %s: link to WordPress widgets page */
                                                esc_html__('Go to %s and add the "Meep Translator – Language Switcher" widget to any sidebar or widget area.', 'ai-translator-for-elementor-and-polylang'),
                                                '<a href="' . esc_url(admin_url('widgets.php')) . '">' . esc_html__('Appearance → Widgets', 'ai-translator-for-elementor-and-polylang') . '</a>'
                                            ); ?>
                                        </li>
                                        <li>
                                            <strong><?php esc_html_e('Elementor:', 'ai-translator-for-elementor-and-polylang'); ?></strong>
                                            <?php esc_html_e('Use the "Shortcode" widget in Elementor and paste the shortcode above.', 'ai-translator-for-elementor-and-polylang'); ?>
                                        </li>
                                        <li>
                                            <strong><?php esc_html_e('Theme Header/Footer:', 'ai-translator-for-elementor-and-polylang'); ?></strong>
                                            <?php esc_html_e('Use do_shortcode() in your theme template:', 'ai-translator-for-elementor-and-polylang'); ?>
                                            <code class="aitrfoel-switcher-inline-code">&lt;?php echo do_shortcode('[aitrfoel_language_switcher]'); ?&gt;</code>
                                        </li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php esc_html_e('Preview', 'ai-translator-for-elementor-and-polylang'); ?></th>
                            <td>
                                <div class="aitrfoel-switcher-preview-box">
                                    <?php
                                    // Render actual shortcode output so preview matches frontend exactly
                                    if (class_exists('AITRFOEL_Builtin_Integration') && AITRFOEL_Builtin_Integration::is_enabled()) {
                                        $builtin = AITRFOEL_Builtin_Integration::get_instance();
                                        $preview_html = $builtin->render_language_switcher_shortcode();
                                        if (!empty($preview_html)) {
                                            echo $preview_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- language switcher HTML built from escaped parts (esc_url/esc_attr/esc_html)
                                        } else {
                                            echo '<p class="aitrfoel-switcher-preview-empty">' . esc_html__('No languages configured yet. Add languages in the Multilingual Plugin section above.', 'ai-translator-for-elementor-and-polylang') . '</p>';
                                        }
                                    } elseif (function_exists('pll_languages_list')) {
                                        $lang_codes = pll_languages_list(['fields' => 'slug']);
                                        if (!empty($lang_codes)) {
                                            echo '<div class="aitrfoel-switcher-preview">';
                                            foreach ($lang_codes as $lc) {
                                                $flag = $show_flags ? AITRFOEL_Language_Mapper::get_flag_html($lc, 20, 'margin-right: 4px;') : '';
                                                $pll_name = $show_names ? esc_html(strtoupper($lc)) : '';
                                                if (!$show_flags && !$show_names) {
                                                    $pll_name = esc_html(strtoupper($lc));
                                                }
                                                echo '<span class="aitrfoel-switcher-preview-chip">';
                                                echo $flag . ($pll_name ? ' <span class="aitrfoel-switcher-preview-chip-text">' . $pll_name . '</span>' : ''); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag <img> from AITRFOEL_Language_Mapper::get_flag_html(), which escapes its URL, alt and style; already escaped with esc_html()/esc_attr() where the value is built
                                                echo '</span>';
                                            }
                                            echo '</div>';
                                        } else {
                                            echo '<p class="aitrfoel-switcher-preview-empty">' . esc_html__('No languages configured yet.', 'ai-translator-for-elementor-and-polylang') . '</p>';
                                        }
                                    } else {
                                        echo '<p class="aitrfoel-switcher-preview-empty">' . esc_html__('No multilingual plugin active. Configure one first.', 'ai-translator-for-elementor-and-polylang') . '</p>';
                                    }
                                    ?>
                                </div>
                                <p class="description aitrfoel-switcher-preview-note">
                                    <?php esc_html_e('This preview updates live as you change the options above. Save settings to apply them on the frontend.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                                <script>
                                (function($){
                                    var nonce = '<?php echo esc_js(wp_create_nonce('aitrfoel_ajax_nonce')); ?>';
                                    var $box  = $('.aitrfoel-switcher-preview-box');
                                    var timer = null;
                                    function gatherCustomNames(){
                                        var names = {};
                                        $('input[name^="aitrfoel_switcher_custom_names["]').each(function(){
                                            var m = this.name.match(/\[([^\]]+)\]/);
                                            if (m) { names[m[1]] = this.value; }
                                        });
                                        return names;
                                    }
                                    function refreshPreview(){
                                        if (!$box.length) return;
                                        $box.css('opacity', '0.5');
                                        $.post(ajaxurl, {
                                            action: 'aitrfoel_switcher_preview',
                                            nonce: nonce,
                                            style: $('#aitrfoel_switcher_style').val(),
                                            show_flags: $('input[name="aitrfoel_switcher_show_flags"]').is(':checked') ? 1 : 0,
                                            show_names: $('input[name="aitrfoel_switcher_show_names"]').is(':checked') ? 1 : 0,
                                            name_display: $('select[name="aitrfoel_switcher_name_display"]').val(),
                                            hide_untranslated: $('input[name="aitrfoel_switcher_hide_untranslated"]').is(':checked') ? 1 : 0,
                                            custom_names: gatherCustomNames()
                                        }).done(function(r){
                                            if (r && r.success && typeof r.data.html === 'string' && r.data.html.trim() !== '') {
                                                $box.html(r.data.html);
                                            }
                                        }).always(function(){ $box.css('opacity', '1'); });
                                    }
                                    function debounced(){ clearTimeout(timer); timer = setTimeout(refreshPreview, 250); }
                                    $(document).on('change',
                                        '#aitrfoel_switcher_style, input[name="aitrfoel_switcher_show_flags"], input[name="aitrfoel_switcher_show_names"], select[name="aitrfoel_switcher_name_display"], input[name="aitrfoel_switcher_hide_untranslated"]',
                                        refreshPreview);
                                    $(document).on('input', 'input[name^="aitrfoel_switcher_custom_names["]', debounced);
                                }(jQuery));
                                </script>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_switcher_custom_css"><?php esc_html_e('Custom CSS', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <div class="aitrfoel-css-editor-header">
                                    <button type="button" id="aitrfoel-generate-sample-css" class="button button-secondary">
                                        <?php esc_html_e('Generate Sample CSS', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </button>
                                    <span class="description"><?php esc_html_e('Generates a starting template based on your current switcher style.', 'ai-translator-for-elementor-and-polylang'); ?></span>
                                </div>
                                <textarea name="aitrfoel_switcher_custom_css"
                                          id="aitrfoel_switcher_custom_css"
                                          class="large-text code"
                                          rows="8"
                                          placeholder="/* Example: change flag size */
.aitrfoel-language-switcher img {
    width: 24px !important;
    height: auto !important;
}

/* Example: change active language background */
.aitrfoel-switcher-list .aitrfoel-switcher-item.active a {
    background-color: #333;
}"><?php echo esc_textarea(get_option('aitrfoel_switcher_custom_css', '')); ?></textarea>
                                <p class="description">
                                    <?php esc_html_e('Add custom CSS to override the default language switcher styles. This CSS is loaded on the frontend wherever the shortcode or widget is used.', 'ai-translator-for-elementor-and-polylang'); ?>
                                    <br>
                                    <strong><?php esc_html_e('Available CSS classes:', 'ai-translator-for-elementor-and-polylang'); ?></strong>
                                    <code>.aitrfoel-language-switcher</code>,
                                    <code>.aitrfoel-switcher-list</code>,
                                    <code>.aitrfoel-switcher-flags</code>,
                                    <code>.aitrfoel-switcher-flags-text</code>,
                                    <code>.aitrfoel-switcher-dropdown</code>,
                                    <code>.aitrfoel-flag-link</code>,
                                    <code>.aitrfoel-flag-text-link</code>,
                                    <code>.aitrfoel-switcher-item</code>,
                                    <code>.active</code>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php esc_html_e('Nav Menu Integration', 'ai-translator-for-elementor-and-polylang'); ?></th>
                            <td>
                                <?php $hide_untranslated = get_option('aitrfoel_switcher_hide_untranslated', false); ?>
                                <label class="aitrfoel-nav-option-label">
                                    <input type="checkbox" name="aitrfoel_switcher_hide_untranslated" value="1" <?php checked($hide_untranslated); ?> />
                                    <?php esc_html_e('Hide languages that have no translation for the current page', 'ai-translator-for-elementor-and-polylang'); ?>
                                </label>
                                <p class="description aitrfoel-nav-option-desc">
                                    <?php esc_html_e('When enabled, only languages with an existing translation of the current page will appear in the language switcher. The default language is always shown.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>

                                <?php $nav_menu_enabled = get_option('aitrfoel_nav_menu_switcher', false); ?>
                                <?php $nav_menu_location = get_option('aitrfoel_nav_menu_location', ''); ?>
                                <label>
                                    <input type="checkbox" name="aitrfoel_nav_menu_switcher" value="1" <?php checked($nav_menu_enabled); ?> />
                                    <?php esc_html_e('Automatically add language switcher to a navigation menu', 'ai-translator-for-elementor-and-polylang'); ?>
                                </label>
                                
                                <?php
                                $menu_locations = get_registered_nav_menus();
                                if (!empty($menu_locations)): ?>
                                <div class="aitrfoel-nav-menu-location-wrap">
                                    <label for="aitrfoel_nav_menu_location">
                                        <strong><?php esc_html_e('Menu Location:', 'ai-translator-for-elementor-and-polylang'); ?></strong>
                                    </label>
                                    <select id="aitrfoel_nav_menu_location" name="aitrfoel_nav_menu_location" class="aitrfoel-nav-menu-location-select">
                                        <option value="" <?php selected($nav_menu_location, ''); ?>>
                                            <?php esc_html_e('All menu locations', 'ai-translator-for-elementor-and-polylang'); ?>
                                        </option>
                                        <?php foreach ($menu_locations as $location_slug => $location_name): ?>
                                            <option value="<?php echo esc_attr($location_slug); ?>" <?php selected($nav_menu_location, $location_slug); ?>>
                                                <?php echo esc_html($location_name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        <?php esc_html_e('Choose which menu location gets the language switcher appended, or select "All" to add to every menu.', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </p>
                                    <?php $nav_menu_style = get_option('aitrfoel_nav_menu_style', 'list'); ?>
                                    <label for="aitrfoel_nav_menu_style" style="display:block;margin-top:12px;">
                                        <strong><?php esc_html_e('Menu style:', 'ai-translator-for-elementor-and-polylang'); ?></strong>
                                    </label>
                                    <select id="aitrfoel_nav_menu_style" name="aitrfoel_nav_menu_style">
                                        <option value="dropdown" <?php selected($nav_menu_style, 'dropdown'); ?>><?php esc_html_e('Dropdown (current language, others on hover)', 'ai-translator-for-elementor-and-polylang'); ?></option>
                                        <option value="list" <?php selected($nav_menu_style, 'list'); ?>><?php esc_html_e('All languages side by side', 'ai-translator-for-elementor-and-polylang'); ?></option>
                                    </select>
                                </div>
                                <?php else: ?>
                                <p class="description aitrfoel-settings-description-top-xs">
                                    <?php esc_html_e('Your current theme has no registered navigation menu locations. The switcher will be appended to all menus when enabled.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        
                    </table>

                    <!-- Inline Save (after Switcher) -->
                    <div class="aitrfoel-inline-save">
                        <?php submit_button(__('Save Settings', 'ai-translator-for-elementor-and-polylang'), 'primary', 'submit', false); ?>
                        <button type="button" class="button aitrfoel-reset-defaults" data-sections="switcher" title="<?php esc_attr_e('Reset Language Switcher settings to defaults', 'ai-translator-for-elementor-and-polylang'); ?>"><?php esc_html_e('Reset Switcher Defaults', 'ai-translator-for-elementor-and-polylang'); ?></button>
                    </div>
                </div>
                
                </div><!-- /#tab-languages -->

                <!-- ========================================================= -->
                <!-- TAB 3: Site Elements & Auto-Translate                     -->
                <!-- ========================================================= -->
                <div class="aitrfoel-tab-pane" id="tab-components">
                
                <!-- Elementor Templates -->
                <div class="aitrfoel-settings-section" id="section-elementor-templates">
                    <h3><?php esc_html_e('Elementor Templates', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    <?php $aitrfoel_has_elementor = post_type_exists('elementor_library'); ?>
                    <?php if ($aitrfoel_has_elementor) : ?>
                        <div class="aitrfoel-plugin-detected-notice is-success" style="display:inline-flex;align-items:center;gap:8px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                            <span><strong>Elementor</strong> <?php esc_html_e('detected — template translation is available.', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </div>
                    <?php else : ?>
                        <div class="aitrfoel-plugin-detected-notice is-warning" style="display:inline-flex;align-items:center;gap:8px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            <span><?php esc_html_e('Elementor is not active. These settings will apply when Elementor is installed and activated.', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($aitrfoel_has_elementor) : // Hide the template settings until Elementor is active. ?>

                    <?php $auto_tpl = get_option('aitrfoel_auto_translate_templates', false); ?>
                    <p style="margin:0 0 14px;">
                        <label>
                            <input type="checkbox" name="aitrfoel_auto_translate_templates" value="1" <?php checked($auto_tpl); ?> />
                            <strong><?php esc_html_e('Auto-translate templates when translating a page', 'ai-translator-for-elementor-and-polylang'); ?></strong>
                        </label>
                        <span class="description" style="display:block;margin-top:4px;">
                            <?php esc_html_e('When a page is translated, also translate any Elementor templates that are not yet translated into that language — including embedded templates and theme-builder single/header/footer templates (e.g. a custom-post-type single). This means non-technical users don\'t have to know a page pulls in an extra template. Note: translating templates uses additional words.', 'ai-translator-for-elementor-and-polylang'); ?>
                        </span>
                    </p>

                    <?php $extra_tpl_types = get_option('aitrfoel_extra_template_post_types', ''); ?>
                    <p style="margin:0 0 14px;">
                        <label for="aitrfoel_extra_template_post_types" style="display:block;font-weight:600;margin-bottom:4px;">
                            <?php esc_html_e('Additional template post types', 'ai-translator-for-elementor-and-polylang'); ?>
                        </label>
                        <input type="text" id="aitrfoel_extra_template_post_types" name="aitrfoel_extra_template_post_types"
                               value="<?php echo esc_attr($extra_tpl_types); ?>" class="regular-text" style="width:100%;max-width:480px;"
                               placeholder="jet-theme-core, my-template-cpt" />
                        <span class="description" style="display:block;margin-top:4px;">
                            <?php esc_html_e('Comma-separated post-type slugs to treat as translatable Elementor templates (translated + swapped at render). Elementor Library and JetEngine listings (jet-engine) are already included. Add others here — e.g. JetThemeCore parts — without editing code.', 'ai-translator-for-elementor-and-polylang'); ?>
                        </span>
                    </p>

                    <?php
                    // Live readout of what is actually recognised as a translatable
                    // template post type (after the saved value is merged in). Slugs that
                    // don't match a registered post type are dropped here — so if a slug
                    // doesn't appear after saving, it was mistyped.
                    $aitrfoel_active_tpl_types = apply_filters('aitrfoel_template_post_types', ['elementor_library', 'jet-engine']);
                    $aitrfoel_active_tpl_types = array_values(array_unique(array_filter((array) $aitrfoel_active_tpl_types, 'post_type_exists')));
                    ?>
                    <p class="description" style="margin:0 0 14px;">
                        <?php esc_html_e('Currently recognised as translatable templates:', 'ai-translator-for-elementor-and-polylang'); ?>
                        <code><?php echo esc_html($aitrfoel_active_tpl_types ? implode(', ', $aitrfoel_active_tpl_types) : '—'); ?></code>
                    </p>

                    <div class="aitrfoel-inline-save" style="margin:0 0 20px;">
                        <?php submit_button(__('Save', 'ai-translator-for-elementor-and-polylang'), 'primary', 'submit', false); ?>
                    </div>

                    <?php
                    $et_builtin_active = class_exists('AITRFOEL_Builtin_Integration') && AITRFOEL_Builtin_Integration::is_enabled();
                    if (!$et_builtin_active): ?>
                        <div class="aitrfoel-inactive-notice">
                            <?php esc_html_e('Enable the built-in language system in the Multilingual section to translate Elementor templates.', 'ai-translator-for-elementor-and-polylang'); ?>
                        </div>
                    <?php else:
                        $et_builtin = AITRFOEL_Builtin_Integration::get_instance();
                        $et_all_languages = $et_builtin->get_available_languages();
                        $et_default_lang = $et_builtin->get_default_language();
                        $et_secondary = array_filter($et_all_languages, function($l) use ($et_default_lang) {
                            return $l->code !== $et_default_lang;
                        });
                        
                        // Get all default-language templates
                        $et_templates = get_posts([
                            'post_type'      => 'elementor_library',
                            'post_status'    => 'publish',
                            'posts_per_page' => -1,
                            'meta_query'     => [
                                ['key' => '_elementor_data', 'compare' => 'EXISTS'],
                            ],
                            'tax_query'      => !empty($et_secondary) ? [
                                [
                                    'taxonomy' => 'aitrfoel_language',
                                    'field'    => 'slug',
                                    'terms'    => array_map(function($l) { return $l->code; }, $et_secondary),
                                    'operator' => 'NOT IN',
                                ],
                            ] : [],
                        ]);
                        
                        // Split into active (used on the site) and other. "Used" now also
                        // includes templates that are embedded elsewhere (via the Template/
                        // Loop widgets or an [elementor-template] shortcode), so reusable
                        // sections without their own display conditions aren't mis-filed as
                        // optional.
                        $et_active = [];
                        $et_other = [];
                        $active_types = ['header', 'footer', 'single', 'single-post', 'single-page', 'archive', 'product', 'product-archive', 'error-404', 'search-results'];
                        $referenced_ids = [];
                        if (class_exists('AITRFOEL_Translation_Engine')) {
                            $et_engine = AITRFOEL_Translation_Engine::get_instance();
                            if (method_exists($et_engine, 'get_referenced_template_ids')) {
                                $referenced_ids = array_flip($et_engine->get_referenced_template_ids());
                            }
                        }
                        foreach ($et_templates as $tpl) {
                            $conditions = get_post_meta($tpl->ID, '_elementor_conditions', true);
                            $tpl_type = get_post_meta($tpl->ID, '_elementor_template_type', true);
                            // Active = has display conditions, is a header/footer/theme-builder
                            // type, OR is embedded somewhere on the site.
                            if (!empty($conditions)
                                || in_array($tpl_type, ['header', 'footer', 'single', 'single-post', 'single-page', 'archive', 'product', 'product-archive', 'error-404', 'search-results'], true)
                                || isset($referenced_ids[(int) $tpl->ID])) {
                                $et_active[] = $tpl;
                            } else {
                                $et_other[] = $tpl;
                            }
                        }
                        
                        $type_labels = [
                            'header'         => __('Header', 'ai-translator-for-elementor-and-polylang'),
                            'footer'         => __('Footer', 'ai-translator-for-elementor-and-polylang'),
                            'single'         => __('Single Page', 'ai-translator-for-elementor-and-polylang'),
                            'single-post'    => __('Blog Post', 'ai-translator-for-elementor-and-polylang'),
                            'single-page'    => __('Page', 'ai-translator-for-elementor-and-polylang'),
                            'archive'        => __('Archive', 'ai-translator-for-elementor-and-polylang'),
                            'product'        => __('Product', 'ai-translator-for-elementor-and-polylang'),
                            'product-archive' => __('Shop Page', 'ai-translator-for-elementor-and-polylang'),
                            'error-404'      => __('404 Page', 'ai-translator-for-elementor-and-polylang'),
                            'search-results' => __('Search Results', 'ai-translator-for-elementor-and-polylang'),
                            'section'        => __('Section', 'ai-translator-for-elementor-and-polylang'),
                            'page'           => __('Page', 'ai-translator-for-elementor-and-polylang'),
                            'popup'          => __('Popup', 'ai-translator-for-elementor-and-polylang'),
                            'loop-item'      => __('Loop Item', 'ai-translator-for-elementor-and-polylang'),
                            'kit'            => __('Kit', 'ai-translator-for-elementor-and-polylang'),
                            'container'      => __('Section', 'ai-translator-for-elementor-and-polylang'),
                        ];
                    ?>
                    
                    <?php if (empty($et_secondary)): ?>
                        <div class="aitrfoel-inactive-notice">
                            <?php esc_html_e('Add at least one additional language in the Multilingual section first.', 'ai-translator-for-elementor-and-polylang'); ?>
                        </div>
                    <?php elseif (empty($et_templates)): ?>
                        <div class="aitrfoel-inactive-notice">
                            <?php esc_html_e('No Elementor templates found.', 'ai-translator-for-elementor-and-polylang'); ?>
                        </div>
                    <?php else: ?>
                    
                    <!-- Active templates (displayed on the site) -->
                    <?php if (!empty($et_active)): ?>
                    <p class="description aitrfoel-section-intro">
                        <?php esc_html_e('These templates are actively displayed on your site (headers, footers, and page templates with display conditions). Translate them to show the correct content for each language.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                    
                    <table class="widefat aitrfoel-settings-table">
                        <thead>
                            <tr>
                                <th class="aitrfoel-th-template"><?php esc_html_e('Template', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                <th class="aitrfoel-th-type"><?php esc_html_e('Type', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                <th><?php esc_html_e('Translations', 'ai-translator-for-elementor-and-polylang'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($et_active as $tpl):
                            $tpl_type = get_post_meta($tpl->ID, '_elementor_template_type', true);
                            $tpl_translations = $et_builtin->get_post_translations($tpl->ID);
                            $type_label = isset($type_labels[$tpl_type]) ? $type_labels[$tpl_type] : ucfirst($tpl_type ?: 'Template');
                            $is_hf = in_array($tpl_type, ['header', 'footer'], true);
                        ?>
                            <tr>
                                <td class="aitrfoel-td">
                                    <strong><?php echo esc_html($tpl->post_title ?: __('(no title)', 'ai-translator-for-elementor-and-polylang')); ?></strong>
                                    <span class="aitrfoel-template-id">#<?php echo absint($tpl->ID); ?></span>
                                </td>
                                <td class="aitrfoel-td">
                                    <span class="aitrfoel-type-badge <?php echo $is_hf ? 'aitrfoel-type-hf' : 'aitrfoel-type-other'; ?>"><?php echo esc_html($type_label); ?></span>
                                </td>
                                <td class="aitrfoel-td">
                                    <?php foreach ($et_secondary as $lang):
                                        $has = isset($tpl_translations[$lang->code]) && $tpl_translations[$lang->code] != $tpl->ID;
                                        $tr_id = $has ? (int) $tpl_translations[$lang->code] : 0;
                                        $tpl_label = esc_attr($tpl->post_title ?: 'Template #' . $tpl->ID);
                                    ?>
                                        <span class="aitrfoel-template-lang-pill">
                                            <?php echo AITRFOEL_Language_Mapper::get_flag_html($lang->code, 14); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag <img> from AITRFOEL_Language_Mapper::get_flag_html(), which escapes its URL, alt and style ?>
                                            <span class="aitrfoel-lang-code"><?php echo esc_html(strtoupper($lang->code)); ?></span>
                                            <?php if ($has): ?>
                                                <span class="aitrfoel-exists-check">✓</span>
                                                <span class="aitrfoel-template-id aitrfoel-translated-id" title="<?php esc_attr_e('Translated template ID', 'ai-translator-for-elementor-and-polylang'); ?>">#<?php echo absint($tr_id); ?></span>
                                                <button type="button"
                                                    class="button button-small aitrfoel-copy-tpl-shortcode"
                                                    data-shortcode='[elementor-template id="<?php echo absint($tr_id); ?>"]'
                                                    title="<?php echo esc_attr(sprintf(/* translators: %d: translated template ID */ __('Copy [elementor-template id="%d"] to clipboard', 'ai-translator-for-elementor-and-polylang'), $tr_id)); ?>">⧉</button>
                                                <button type="button"
                                                    class="button button-small aitrfoel-tpl-translate-single aitrfoel-retranslate-btn"
                                                    data-tpl-id="<?php echo esc_attr($tpl->ID); ?>"
                                                    data-tpl-label="<?php echo $tpl_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped with esc_html()/esc_attr() where the value is built ?>"
                                                    data-lang="<?php echo esc_attr($lang->code); ?>"
                                                    data-lang-name="<?php echo esc_attr($lang->name); ?>"
                                                    data-force="1"
                                                    title="<?php esc_attr_e('Force re-translate this template', 'ai-translator-for-elementor-and-polylang'); ?>">↺</button>
                                            <?php else: ?>
                                                <span class="aitrfoel-missing-check">✗</span>
                                                <button type="button"
                                                    class="button button-small aitrfoel-tpl-translate-single aitrfoel-translate-btn-sm"
                                                    data-tpl-id="<?php echo esc_attr($tpl->ID); ?>"
                                                    data-tpl-label="<?php echo $tpl_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped with esc_html()/esc_attr() where the value is built ?>"
                                                    data-lang="<?php echo esc_attr($lang->code); ?>"
                                                    data-lang-name="<?php echo esc_attr($lang->name); ?>"
                                                    data-force="0"
                                                    title="<?php esc_attr_e('Translate this template', 'ai-translator-for-elementor-and-polylang'); ?>">+</button>
                                            <?php endif; ?>
                                        </span>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                        <div class="aitrfoel-warning-notice">
                            <?php esc_html_e('No active templates detected. Your Elementor templates may not have display conditions set. Check the Advanced section below for all templates.', 'ai-translator-for-elementor-and-polylang'); ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Translate buttons -->
                    <div class="aitrfoel-auto-translate-box">
                        <h4 class="aitrfoel-action-box-heading"><?php esc_html_e('Translate Active Templates', 'ai-translator-for-elementor-and-polylang'); ?></h4>
                        <p class="description aitrfoel-action-box-desc">
                            <?php esc_html_e('Translates your headers, footers, and other active page templates. Already translated templates will be skipped. Uses your translation credits.', 'ai-translator-for-elementor-and-polylang'); ?>
                        </p>
                        <div class="aitrfoel-action-button-row">
                            <?php foreach ($et_secondary as $lang): ?>
                            <button type="button" class="button aitrfoel-translate-tpl-lang" data-lang="<?php echo esc_attr($lang->code); ?>" data-name="<?php echo esc_attr($lang->name); ?>" data-mode="active">
                                <?php echo AITRFOEL_Language_Mapper::get_flag_html($lang->code, 16, 'vertical-align: middle; margin-right: 4px;'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag <img> from AITRFOEL_Language_Mapper::get_flag_html(), which escapes its URL, alt and style ?>
                                <?php echo esc_html(sprintf(
                                    /* translators: %s: language code */ __('Translate → %s', 'ai-translator-for-elementor-and-polylang'),
                                    strtoupper($lang->code)
                                )); ?>
                            </button>
                            <?php endforeach; ?>
                            <button type="button" class="button button-primary aitrfoel-translate-tpl-all" data-mode="active">
                                <?php esc_html_e('Translate All Languages', 'ai-translator-for-elementor-and-polylang'); ?>
                            </button>
                        </div>
                        <div class="aitrfoel-action-translate-all-row">
                            <label class="aitrfoel-force-label">
                                <input type="checkbox" id="aitrfoel-tpl-force" value="1" class="aitrfoel-force-checkbox">
                                <?php esc_html_e('Force re-translate (replace existing translations)', 'ai-translator-for-elementor-and-polylang'); ?>
                            </label>
                        </div>
                        <div class="aitrfoel-tpl-translate-status">
                            <div class="aitrfoel-spinner-row">
                                <span class="spinner aitrfoel-inline-spinner"></span>
                                <span class="aitrfoel-tpl-translate-msg aitrfoel-action-msg"></span>
                            </div>
                            <div class="aitrfoel-tpl-translate-log aitrfoel-action-log"></div>
                        </div>
                    </div>
                    
                    <!-- Advanced: Other templates (collapsible) -->
                    <?php if (!empty($et_other)): ?>
                    <div class="aitrfoel-collapsible-header">
                        <a href="#" id="aitrfoel-toggle-other-tpl" class="aitrfoel-collapsible-toggle">
                            <span aria-hidden="true" class="aitrfoel-icon-sm"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:16px;height:16px;display:block;"><path d="M12 8.5L9 11.5L12 14.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M15 8.5L12 11.5L15 14.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 6V18C4 19.1 4.9 20 6 20H18C19.1 20 20 19.1 20 18V6C20 4.9 19.1 4 18 4H6C4.9 4 4 4.9 4 6Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                            <?php echo esc_html(sprintf(
                                /* translators: %d: number of other Elementor templates */ __('Advanced: %d other templates (sections, popups, loops...)', 'ai-translator-for-elementor-and-polylang'),
                                count($et_other)
                            )); ?>
                            <span id="aitrfoel-other-tpl-arrow" aria-hidden="true" class="aitrfoel-collapse-arrow"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:14px;height:14px;display:block;"><path d="M7 10L12 15L17 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                        </a>
                    </div>
                    <div id="aitrfoel-other-tpl" class="aitrfoel-collapsible-panel">
                        <p class="description aitrfoel-section-intro">
                            <?php esc_html_e('These are reusable sections, containers, popups, and loop templates. They are usually embedded inside pages that are already translated, so translating them separately is optional.', 'ai-translator-for-elementor-and-polylang'); ?>
                        </p>
                        <table class="widefat aitrfoel-settings-table">
                            <thead>
                                <tr>
                                    <th class="aitrfoel-th-template"><?php esc_html_e('Template', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                    <th class="aitrfoel-th-type"><?php esc_html_e('Type', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                    <th><?php esc_html_e('Translations', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($et_other as $tpl):
                                $tpl_type = get_post_meta($tpl->ID, '_elementor_template_type', true);
                                $tpl_translations = $et_builtin->get_post_translations($tpl->ID);
                                $type_label = isset($type_labels[$tpl_type]) ? $type_labels[$tpl_type] : ucfirst($tpl_type ?: 'Template');
                            ?>
                                <tr>
                                    <td class="aitrfoel-td">
                                        <?php echo esc_html($tpl->post_title ?: __('(no title)', 'ai-translator-for-elementor-and-polylang')); ?>
                                        <span class="aitrfoel-template-id">#<?php echo esc_html($tpl->ID); ?></span>
                                    </td>
                                    <td class="aitrfoel-td">
                                        <span class="aitrfoel-type-badge aitrfoel-type-generic"><?php echo esc_html($type_label); ?></span>
                                    </td>
                                    <td class="aitrfoel-td">
                                        <?php foreach ($et_secondary as $lang):
                                            $has = isset($tpl_translations[$lang->code]) && $tpl_translations[$lang->code] != $tpl->ID;
                                            $tr_id = $has ? (int) $tpl_translations[$lang->code] : 0;
                                            $tpl_label = esc_attr($tpl->post_title ?: 'Template #' . $tpl->ID);
                                        ?>
                                            <span class="aitrfoel-template-lang-pill">
                                                <?php echo AITRFOEL_Language_Mapper::get_flag_html($lang->code, 14); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag <img> from AITRFOEL_Language_Mapper::get_flag_html(), which escapes its URL, alt and style ?>
                                                <span class="aitrfoel-lang-code"><?php echo esc_html(strtoupper($lang->code)); ?></span>
                                                <?php if ($has): ?>
                                                    <span class="aitrfoel-exists-check">✓</span>
                                                    <span class="aitrfoel-template-id aitrfoel-translated-id" title="<?php esc_attr_e('Translated template ID', 'ai-translator-for-elementor-and-polylang'); ?>">#<?php echo absint($tr_id); ?></span>
                                                    <button type="button"
                                                        class="button button-small aitrfoel-copy-tpl-shortcode"
                                                        data-shortcode='[elementor-template id="<?php echo absint($tr_id); ?>"]'
                                                        title="<?php echo esc_attr(sprintf(/* translators: %d: translated template ID */ __('Copy [elementor-template id="%d"] to clipboard', 'ai-translator-for-elementor-and-polylang'), $tr_id)); ?>">⧉</button>
                                                    <button type="button"
                                                        class="button button-small aitrfoel-tpl-translate-single aitrfoel-retranslate-btn"
                                                        data-tpl-id="<?php echo esc_attr($tpl->ID); ?>"
                                                        data-tpl-label="<?php echo $tpl_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped with esc_html()/esc_attr() where the value is built ?>"
                                                        data-lang="<?php echo esc_attr($lang->code); ?>"
                                                        data-lang-name="<?php echo esc_attr($lang->name); ?>"
                                                        data-force="1"
                                                        title="<?php esc_attr_e('Force re-translate this template', 'ai-translator-for-elementor-and-polylang'); ?>">↺</button>
                                                <?php else: ?>
                                                    <span class="aitrfoel-missing-check">✗</span>
                                                    <button type="button"
                                                        class="button button-small aitrfoel-tpl-translate-single aitrfoel-translate-btn-sm"
                                                        data-tpl-id="<?php echo esc_attr($tpl->ID); ?>"
                                                        data-tpl-label="<?php echo $tpl_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped with esc_html()/esc_attr() where the value is built ?>"
                                                        data-lang="<?php echo esc_attr($lang->code); ?>"
                                                        data-lang-name="<?php echo esc_attr($lang->name); ?>"
                                                        data-force="0"
                                                        title="<?php esc_attr_e('Translate this template', 'ai-translator-for-elementor-and-polylang'); ?>">+</button>
                                                <?php endif; ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="aitrfoel-collapsible-footer">
                            <button type="button" class="button aitrfoel-translate-tpl-all aitrfoel-translate-tpl-mr" data-mode="advanced">
                                <?php esc_html_e('Translate Advanced Templates Only', 'ai-translator-for-elementor-and-polylang'); ?>
                            </button>
                            <button type="button" class="button aitrfoel-translate-tpl-all" data-mode="all">
                                <?php esc_html_e('Translate All Templates (including advanced)', 'ai-translator-for-elementor-and-polylang'); ?>
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php endif; // end templates check ?>
                    <?php endif; // end builtin check ?>
                    <?php endif; // end Elementor detection — contents hidden when inactive ?>
                </div>
                
                <!-- Menus & Widgets Settings -->
                <div class="aitrfoel-settings-section" id="section-menus-widgets">
                    <h3><?php esc_html_e('Menus & Widgets', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    
                    <p class="description aitrfoel-section-intro">
                        <?php esc_html_e('Assign different navigation menus and control widget visibility per language. Only available when the built-in language system is active.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                    
                    <?php
                    $mw_builtin_active = class_exists('AITRFOEL_Builtin_Integration') && AITRFOEL_Builtin_Integration::is_enabled();
                    if (!$mw_builtin_active): ?>
                        <div class="aitrfoel-inactive-notice">
                            <?php esc_html_e('Enable the built-in language system in the Multilingual section to use per-language menus and widget visibility.', 'ai-translator-for-elementor-and-polylang'); ?>
                        </div>
                    <?php else:
                        $mw_builtin = AITRFOEL_Builtin_Integration::get_instance();
                        $mw_all_languages = $mw_builtin->get_available_languages();
                        $mw_default_lang = $mw_builtin->get_default_language();
                        $mw_secondary = array_filter($mw_all_languages, function($l) use ($mw_default_lang) {
                            return $l->code !== $mw_default_lang;
                        });
                    ?>
                    
                    <?php if (empty($mw_secondary)): ?>
                        <div class="aitrfoel-inactive-notice">
                            <?php esc_html_e('Add at least one additional language in the Multilingual section to configure per-language menus and widgets.', 'ai-translator-for-elementor-and-polylang'); ?>
                        </div>
                    <?php else: ?>
                    
                    <!-- Auto-Translate & Assign -->
                    <div class="aitrfoel-auto-translate-box">
                        <h4 class="aitrfoel-action-box-heading"><?php esc_html_e('Auto-Translate & Assign', 'ai-translator-for-elementor-and-polylang'); ?></h4>
                        <p class="description aitrfoel-action-box-desc">
                            <?php esc_html_e('Automatically clone your menus, translate menu item labels and widget content, and assign everything per language. Uses your translation credits.', 'ai-translator-for-elementor-and-polylang'); ?>
                        </p>
                        <div class="aitrfoel-action-button-row">
                            <?php foreach ($mw_secondary as $lang): ?>
                            <button type="button" class="button aitrfoel-auto-translate-lang" data-lang="<?php echo esc_attr($lang->code); ?>" data-name="<?php echo esc_attr($lang->name); ?>">
                                <?php echo AITRFOEL_Language_Mapper::get_flag_html($lang->code, 16, 'vertical-align: middle; margin-right: 4px;'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag <img> from AITRFOEL_Language_Mapper::get_flag_html(), which escapes its URL, alt and style ?>
                                <?php echo esc_html(sprintf(
                                    /* translators: %s: language code */ __('Translate → %s', 'ai-translator-for-elementor-and-polylang'),
                                    strtoupper($lang->code)
                                )); ?>
                            </button>
                            <?php endforeach; ?>
                            <button type="button" class="button button-primary aitrfoel-auto-translate-all">
                                <?php esc_html_e('Translate All Languages', 'ai-translator-for-elementor-and-polylang'); ?>
                            </button>
                        </div>
                        <div class="aitrfoel-auto-translate-status">
                            <div class="aitrfoel-spinner-row">
                                <span class="spinner aitrfoel-inline-spinner"></span>
                                <span class="aitrfoel-auto-translate-msg aitrfoel-action-msg"></span>
                            </div>
                            <div class="aitrfoel-auto-translate-log"></div>
                        </div>
                    </div>
                    
                    <!-- Advanced: Per-Language Menu & Widget Assignment (collapsible) -->
                    <div class="aitrfoel-collapsible-header">
                        <a href="#" id="aitrfoel-toggle-advanced-mw" class="aitrfoel-collapsible-toggle">
                            <span aria-hidden="true" class="aitrfoel-icon-sm"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:16px;height:16px;display:block;"><path d="M12 8.5L9 11.5L12 14.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M15 8.5L12 11.5L15 14.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 6V18C4 19.1 4.9 20 6 20H18C19.1 20 20 19.1 20 18V6C20 4.9 19.1 4 18 4H6C4.9 4 4 4.9 4 6Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                            <?php esc_html_e('Advanced: Manual Menu & Widget Assignment', 'ai-translator-for-elementor-and-polylang'); ?>
                            <span id="aitrfoel-advanced-mw-arrow" aria-hidden="true" class="aitrfoel-collapse-arrow"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:14px;height:14px;display:block;"><path d="M7 10L12 15L17 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                        </a>
                    </div>
                    <div id="aitrfoel-advanced-mw" class="aitrfoel-collapsible-panel">
                    
                    <!-- Per-Language Menu Assignment -->
                    <table class="form-table aitrfoel-form-table-notop">
                        <tr>
                            <th scope="row"><?php esc_html_e('Per-Language Menus', 'ai-translator-for-elementor-and-polylang'); ?></th>
                            <td>
                                <?php
                                $mw_menu_locations = get_registered_nav_menus();
                                $mw_all_menus = wp_get_nav_menus();
                                $mw_menu_assignments = get_option('aitrfoel_menu_assignments', []);
                                
                                if (empty($mw_menu_locations)): ?>
                                    <p class="description">
                                        <?php esc_html_e('Your current theme has no registered navigation menu locations. Menu assignment per language is not available.', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </p>
                                <?php elseif (empty($mw_all_menus)): ?>
                                    <p class="description">
                                        <?php printf(
                                            /* translators: 1: opening link tag, 2: closing link tag */ esc_html__('No menus found. %sCreate menus%s in Appearance → Menus first, then assign them per language here.', 'ai-translator-for-elementor-and-polylang'),
                                            '<a href="' . esc_url(admin_url('nav-menus.php')) . '">',
                                            '</a>'
                                        ); ?>
                                    </p>
                                <?php else: ?>
                                    <p class="description aitrfoel-settings-description-top-sm">
                                        <?php esc_html_e('The default language uses the menu assigned in Appearance → Menus. Select a different menu for each additional language below.', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </p>
                                    <?php foreach ($mw_menu_locations as $loc_slug => $loc_name): ?>
                                    <div class="aitrfoel-location-card">
                                        <strong><?php echo esc_html($loc_name); ?></strong>
                                        <span class="aitrfoel-location-slug">(<?php echo esc_html($loc_slug); ?>)</span>
                                        <div class="aitrfoel-location-grid">
                                        <?php foreach ($mw_secondary as $lang): ?>
                                            <label class="aitrfoel-location-label">
                                                <?php echo AITRFOEL_Language_Mapper::get_flag_html($lang->code, 16); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag <img> from AITRFOEL_Language_Mapper::get_flag_html(), which escapes its URL, alt and style ?>
                                                <span><?php echo esc_html(strtoupper($lang->code)); ?>:</span>
                                                <select name="aitrfoel_menu_assignments[<?php echo esc_attr($loc_slug); ?>][<?php echo esc_attr($lang->code); ?>]" class="aitrfoel-location-select">
                                                    <option value="0"><?php esc_html_e('— Same as default —', 'ai-translator-for-elementor-and-polylang'); ?></option>
                                                    <?php foreach ($mw_all_menus as $menu_obj): ?>
                                                    <option value="<?php echo esc_attr($menu_obj->term_id); ?>"
                                                        <?php selected(
                                                            isset($mw_menu_assignments[$loc_slug][$lang->code]) ? $mw_menu_assignments[$loc_slug][$lang->code] : 0,
                                                            $menu_obj->term_id
                                                        ); ?>>
                                                        <?php echo esc_html($menu_obj->name); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                        <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                    
                    <hr class="aitrfoel-section-divider">
                    
                    <!-- Per-Language Widget Visibility -->
                    <h4 class="aitrfoel-sub-heading"><?php esc_html_e('Widget Language Visibility', 'ai-translator-for-elementor-and-polylang'); ?></h4>
                    <p class="description aitrfoel-settings-description-top-sm">
                        <?php esc_html_e('Control which language(s) each widget is displayed for. Widgets with no language selected will show for all languages. Duplicate your footer/sidebar widgets and assign each copy to a specific language.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                    
                    <?php
                    $mw_sidebars = wp_get_sidebars_widgets();
                    $mw_widget_assignments = get_option('aitrfoel_widget_languages', []);
                    $registered_sidebars = $GLOBALS['wp_registered_sidebars'] ?? [];
                    $has_widgets = false;
                    
                    foreach ($mw_sidebars as $sidebar_id => $widgets) {
                        if ($sidebar_id === 'wp_inactive_widgets' || empty($widgets)) {
                            continue;
                        }
                        $sidebar_name = isset($registered_sidebars[$sidebar_id]['name']) ? $registered_sidebars[$sidebar_id]['name'] : $sidebar_id;
                        $has_widgets = true;
                    ?>
                    <div class="aitrfoel-widget-sidebar-card">
                        <strong class="aitrfoel-sidebar-name"><?php echo esc_html($sidebar_name); ?></strong>
                        <table class="widefat aitrfoel-widget-table">
                            <thead>
                                <tr>
                                    <th class="aitrfoel-widget-th-name"><?php esc_html_e('Widget', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                    <th class="aitrfoel-widget-th-langs"><?php esc_html_e('Show in Languages', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($widgets as $widget_id):
                                // Parse widget ID to get type and number (e.g., "text-2" → type="text", number=2)
                                $widget_title = $widget_id;
                                if (preg_match('/^(.+)-(\d+)$/', $widget_id, $m)) {
                                    $widget_type = $m[1];
                                    $widget_number = $m[2];
                                    // Try to get widget display name
                                    global $wp_widget_factory;
                                    if (isset($wp_widget_factory->widgets)) {
                                        foreach ($wp_widget_factory->widgets as $widget_obj) {
                                            if ($widget_obj->id_base === $widget_type) {
                                                $widget_title = $widget_obj->name;
                                                // Try to get instance title
                                                $all_instances = get_option('widget_' . $widget_type, []);
                                                if (isset($all_instances[$widget_number]['title']) && $all_instances[$widget_number]['title'] !== '') {
                                                    $widget_title .= ': ' . $all_instances[$widget_number]['title'];
                                                }
                                                break;
                                            }
                                        }
                                    }
                                }
                                $selected_langs = isset($mw_widget_assignments[$widget_id]) ? (array) $mw_widget_assignments[$widget_id] : [];
                            ?>
                                <tr>
                                    <td class="aitrfoel-widget-name-cell">
                                        <?php echo esc_html($widget_title); ?>
                                        <span class="aitrfoel-widget-id">(<?php echo esc_html($widget_id); ?>)</span>
                                    </td>
                                    <td class="aitrfoel-widget-langs-cell">
                                        <label class="aitrfoel-widget-all-label">
                                            <input type="radio" name="aitrfoel_widget_languages[<?php echo esc_attr($widget_id); ?>][_mode]" value="all"
                                                <?php checked(empty($selected_langs)); ?> />
                                            <?php esc_html_e('All', 'ai-translator-for-elementor-and-polylang'); ?>
                                        </label>
                                        <?php foreach ($mw_all_languages as $lang): ?>
                                        <label class="aitrfoel-widget-lang-label">
                                            <input type="checkbox" name="aitrfoel_widget_languages[<?php echo esc_attr($widget_id); ?>][<?php echo esc_attr($lang->code); ?>]" value="1"
                                                <?php checked(in_array($lang->code, $selected_langs, true)); ?>
                                                onchange="this.closest('td').querySelector('input[type=radio]').checked=false;" />
                                            <?php echo AITRFOEL_Language_Mapper::get_flag_html($lang->code, 14, 'vertical-align: middle; margin-right: 2px;'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag <img> from AITRFOEL_Language_Mapper::get_flag_html(), which escapes its URL, alt and style ?>
                                            <?php echo esc_html(strtoupper($lang->code)); ?>
                                        </label>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php } // end sidebar loop ?>
                    
                    <?php if (!$has_widgets): ?>
                        <div class="aitrfoel-inactive-notice">
                            <?php printf(
                                /* translators: 1: opening link tag, 2: closing link tag */ esc_html__('No active widgets found. %sAdd widgets%s to your sidebars first, then configure their language visibility here.', 'ai-translator-for-elementor-and-polylang'),
                                '<a href="' . esc_url(admin_url('widgets.php')) . '">',
                                '</a>'
                            ); ?>
                        </div>
                    <?php endif; ?>
                    
                    </div><!-- /#aitrfoel-advanced-mw -->
                    
                    <?php endif; // end secondary languages check ?>
                    <?php endif; // end builtin check ?>
                    
                    <!-- Inline Save (after Menus & Widgets) -->
                    <div class="aitrfoel-inline-save">
                        <?php submit_button(__('Save Settings', 'ai-translator-for-elementor-and-polylang'), 'primary', 'submit', false); ?>
                        <button type="button" class="button aitrfoel-reset-defaults" data-sections="menus-widgets" title="<?php esc_attr_e('Reset Menus & Widgets settings to defaults', 'ai-translator-for-elementor-and-polylang'); ?>"><?php esc_html_e('Reset Menus & Widgets Defaults', 'ai-translator-for-elementor-and-polylang'); ?></button>
                    </div>
                </div>
                
                <!-- Contact Forms -->
                <div class="aitrfoel-settings-section" id="section-forms">
                    <h3><?php esc_html_e('Contact Forms', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    <p class="description" style="margin-bottom:12px;">
                        <?php esc_html_e('Translate the labels, placeholders, options, buttons, and messages of your contact forms — Contact Form 7, WPForms, Gravity Forms, and JetFormBuilder. Scan to see what was detected, then translate. Detected strings also appear in String Translations below, where you can fine-tune any wording. (JetSmartFilters and other filter labels live in String Translations.)', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                    <p style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <button type="button" class="button" id="aitrfoel-forms-scan"><?php esc_html_e('Scan forms', 'ai-translator-for-elementor-and-polylang'); ?></button>
                        <button type="button" class="button button-primary" id="aitrfoel-forms-autotranslate"><?php esc_html_e('Translate form strings', 'ai-translator-for-elementor-and-polylang'); ?></button>
                        <label style="font-size:13px;"><input type="checkbox" id="aitrfoel-forms-force" value="1" /> <?php esc_html_e('Overwrite existing', 'ai-translator-for-elementor-and-polylang'); ?></label>
                        <span id="aitrfoel-forms-msg" style="font-size:13px;color:#555;"></span>
                    </p>
                    <script>
                    (function($){
                        var nonce = '<?php echo esc_js(wp_create_nonce('aitrfoel_ajax_nonce')); ?>';
                        $('#aitrfoel-forms-scan').on('click', function(){
                            var $b = $(this).prop('disabled', true);
                            var $m = $('#aitrfoel-forms-msg').text('<?php echo esc_js(__('Scanning…', 'ai-translator-for-elementor-and-polylang')); ?>');
                            $.post(ajaxurl, { action: 'aitrfoel_scan_forms', nonce: nonce, scope: 'forms' }).done(function(r){
                                $m.text((r.data && r.data.message) ? r.data.message : (r.data || '?'));
                            }).fail(function(){ $m.text('<?php echo esc_js(__('Request failed — try again.', 'ai-translator-for-elementor-and-polylang')); ?>'); })
                            .always(function(){ $b.prop('disabled', false); });
                        });
                        $('#aitrfoel-forms-autotranslate').on('click', function(){
                            var $b = $(this).prop('disabled', true);
                            var $m = $('#aitrfoel-forms-msg').text('<?php echo esc_js(__('Translating…', 'ai-translator-for-elementor-and-polylang')); ?>');
                            $.post(ajaxurl, { action: 'aitrfoel_autotranslate_strings', nonce: nonce, force: $('#aitrfoel-forms-force').is(':checked') ? 1 : 0 })
                            .done(function(r){
                                if (r.success) {
                                    $m.text(((r.data && r.data.message) ? r.data.message : '') + ' <?php echo esc_js(__('Reloading…', 'ai-translator-for-elementor-and-polylang')); ?>');
                                    setTimeout(function(){ location.reload(); }, 1200);
                                } else {
                                    $m.text('<?php echo esc_js(__('Error:', 'ai-translator-for-elementor-and-polylang')); ?> ' + (r.data || '?'));
                                    $b.prop('disabled', false);
                                }
                            }).fail(function(){ $m.text('<?php echo esc_js(__('Request failed — try again.', 'ai-translator-for-elementor-and-polylang')); ?>'); $b.prop('disabled', false); });
                        });
                    }(jQuery));
                    </script>
                </div>
                
                <!-- String Translations -->
                <div class="aitrfoel-settings-section" id="section-strings">
                    <h3><?php esc_html_e('String Translations', 'ai-translator-for-elementor-and-polylang'); ?></h3>

                    <?php
                    $st_mm = class_exists('AITRFOEL_Multilingual_Manager') ? AITRFOEL_Multilingual_Manager::get_instance() : null;
                    if ($st_mm && $st_mm->is_builtin() && class_exists('AITRFOEL_Builtin_Integration')) :
                        $st_builtin   = AITRFOEL_Builtin_Integration::get_instance();
                        $st_default   = $st_builtin->get_default_language();
                        $st_all_langs = $st_builtin->get_available_languages();
                        $st_secondary = array_values(array_filter($st_all_langs, function ($l) use ($st_default) { return $l->code !== $st_default; }));
                        $st_entries   = get_option('aitrfoel_string_translations', []);
                        if (!is_array($st_entries)) { $st_entries = []; }

                        // Map known source strings to a human "Used in" label so the admin
                        // can see where each detected string comes from.
                        $st_sources_map = [];
                        $st_priv = get_option('woocommerce_checkout_privacy_policy_text', '');
                        if (is_string($st_priv) && trim($st_priv) !== '') {
                            $st_sources_map[trim($st_priv)] = __('WooCommerce · Checkout privacy', 'ai-translator-for-elementor-and-polylang');
                        }
                        if (function_exists('WC') && WC()->payment_gateways) {
                            foreach (WC()->payment_gateways->payment_gateways() as $st_gw) {
                                if (!empty($st_gw->title)) {
                                    $st_sources_map[trim((string) $st_gw->title)] = sprintf(/* translators: %s: payment gateway ID */ __('WooCommerce · Payment title (%s)', 'ai-translator-for-elementor-and-polylang'), $st_gw->id);
                                }
                                if (!empty($st_gw->description)) {
                                    $st_sources_map[trim((string) $st_gw->description)] = __('WooCommerce · Payment description', 'ai-translator-for-elementor-and-polylang');
                                }
                            }
                        }
                        // Label JetSmartFilters strings so detected filter labels are easy
                        // to spot in the table after Auto-translate.
                        if (post_type_exists('jet-smart-filters')) {
                            $st_jsf_ids = get_posts([
                                'post_type'        => 'jet-smart-filters',
                                'post_status'      => ['publish', 'draft', 'pending', 'private'],
                                'numberposts'      => -1,
                                'fields'           => 'ids',
                                'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- intentional: Polylang/WPML query filters would hide posts in other languages, which the translator must see
                            ]);
                            foreach ($st_jsf_ids as $st_fid) {
                                $st_all = get_post_meta($st_fid, '_all_option_label', true);
                                if (is_string($st_all) && trim($st_all) !== '') {
                                    $st_sources_map[trim($st_all)] = __('JetSmartFilters · "Show all"', 'ai-translator-for-elementor-and-polylang');
                                }
                                $st_flabel = get_post_meta($st_fid, '_filter_label', true);
                                if (is_string($st_flabel) && trim($st_flabel) !== '') {
                                    $st_sources_map[trim($st_flabel)] = __('JetSmartFilters · Filter label', 'ai-translator-for-elementor-and-polylang');
                                }
                                if (get_post_meta($st_fid, '_data_source', true) === 'taxonomies') {
                                    $st_tax = get_post_meta($st_fid, '_source_taxonomy', true);
                                    if (is_string($st_tax) && $st_tax !== '' && taxonomy_exists($st_tax)) {
                                        $st_terms = get_terms(['taxonomy' => $st_tax, 'hide_empty' => false]);
                                        if (is_array($st_terms)) {
                                            foreach ($st_terms as $st_term) {
                                                if (is_object($st_term) && !empty($st_term->name)) {
                                                    $st_sources_map[trim($st_term->name)] = __('JetSmartFilters · Filter option', 'ai-translator-for-elementor-and-polylang');
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    ?>
                    <p class="description" style="margin-bottom:12px;">
                        <?php esc_html_e('Translate interface strings that aren\'t covered by automatic translation — payment method titles/descriptions, the checkout privacy notice, JetSmartFilters labels (the "Show all" option and filter terms), and text from other plugins. Use Scan strings to detect filter labels, then Auto-translate strings to fill them in — they appear as rows below. You can also enter the exact source text on the left and its translation for each language; leave a language blank to keep the original.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>

                    <!-- Search / Filter Toolbar for Strings -->
                    <div class="aitrfoel-st-toolbar" style="margin-bottom:12px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                        <div style="position:relative; max-width:340px; width:100%;">
                            <input type="search" id="aitrfoel-st-search" class="regular-text" style="width:100%; padding-left:30px; border-radius:6px;" placeholder="<?php esc_attr_e('Search strings, filters, or translations…', 'ai-translator-for-elementor-and-polylang'); ?>" />
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position:absolute; left:9px; top:50%; transform:translateY(-50%); opacity:0.5; pointer-events:none;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        </div>
                        <div id="aitrfoel-st-match-count" style="font-size:12.5px; color:#64748b; font-weight:500;"></div>
                    </div>

                    <table class="widefat aitrfoel-settings-table" id="aitrfoel-st-table">
                        <thead>
                            <tr>
                                <th style="width:30%;"><?php esc_html_e('Source text', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                <th style="width:140px;"><?php esc_html_e('Used in', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                <?php foreach ($st_secondary as $sl) : ?>
                                    <th><?php echo esc_html(strtoupper($sl->code)); ?></th>
                                <?php endforeach; ?>
                                <th style="width:32px;"></th>
                            </tr>
                        </thead>
                        <tbody id="aitrfoel-st-rows">
                            <?php
                            $st_render_row = function ($index, $source, $translations) use ($st_secondary, $st_sources_map) {
                                $used_in = ($source !== '' && isset($st_sources_map[trim($source)]))
                                    ? $st_sources_map[trim($source)]
                                    : ($source !== '' ? __('Custom', 'ai-translator-for-elementor-and-polylang') : '');
                                ?>
                                <tr class="aitrfoel-st-row">
                                    <td><textarea name="aitrfoel_string_translations[rows][<?php echo (int) $index; ?>][source]" rows="2" class="large-text code" placeholder="<?php esc_attr_e('Original text…', 'ai-translator-for-elementor-and-polylang'); ?>"><?php echo esc_textarea($source); ?></textarea></td>
                                    <td style="font-size:12px;color:#555;vertical-align:middle;"><?php echo esc_html($used_in); ?></td>
                                    <?php foreach ($st_secondary as $sl) :
                                        $val = isset($translations[$sl->code]) ? $translations[$sl->code] : '';
                                    ?>
                                        <td><textarea name="aitrfoel_string_translations[rows][<?php echo (int) $index; ?>][trans][<?php echo esc_attr($sl->code); ?>]" rows="2" class="large-text code" placeholder="<?php echo esc_attr(strtoupper($sl->code)); ?>"><?php echo esc_textarea($val); ?></textarea></td>
                                    <?php endforeach; ?>
                                    <td><button type="button" class="button button-small aitrfoel-st-remove" title="<?php esc_attr_e('Remove', 'ai-translator-for-elementor-and-polylang'); ?>">✕</button></td>
                                </tr>
                                <?php
                            };
                            $st_i = 0;
                            foreach ($st_entries as $entry) {
                                if (empty($entry['source'])) { continue; }
                                $st_render_row($st_i, $entry['source'], isset($entry['translations']) && is_array($entry['translations']) ? $entry['translations'] : []);
                                $st_i++;
                            }

                            // Pre-seed with the site's actual WooCommerce strings (gateway
                            // titles/descriptions + checkout privacy text) when nothing is saved
                            // yet, so the admin only fills the translation columns. Unsaved
                            // rows with no translations are dropped on save.
                            if (empty($st_entries)) {
                                $st_seed = [];
                                $priv = get_option('woocommerce_checkout_privacy_policy_text', '');
                                if (is_string($priv) && trim($priv) !== '') { $st_seed[] = trim($priv); }
                                if (function_exists('WC') && WC()->payment_gateways) {
                                    foreach (WC()->payment_gateways->payment_gateways() as $gw) {
                                        if (!empty($gw->title))       { $st_seed[] = trim((string) $gw->title); }
                                        if (!empty($gw->description)) { $st_seed[] = trim((string) $gw->description); }
                                    }
                                }
                                $st_seed = array_values(array_unique(array_filter($st_seed)));
                                foreach ($st_seed as $seed_source) {
                                    $st_render_row($st_i, $seed_source, []);
                                    $st_i++;
                                }
                            }

                            // Always render one empty row to start.
                            $st_render_row($st_i, '', []);
                            $st_next_index = $st_i + 1;
                            ?>
                        </tbody>
                    </table>
                    <p style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <button type="button" class="button" id="aitrfoel-st-add"><?php esc_html_e('+ Add string', 'ai-translator-for-elementor-and-polylang'); ?></button>
                        <button type="button" class="button" id="aitrfoel-st-scan"><?php esc_html_e('Scan strings', 'ai-translator-for-elementor-and-polylang'); ?></button>
                        <button type="button" class="button button-primary" id="aitrfoel-st-autotranslate"><?php esc_html_e('Auto-translate strings', 'ai-translator-for-elementor-and-polylang'); ?></button>
                        <label style="font-size:13px;"><input type="checkbox" id="aitrfoel-st-force" value="1" /> <?php esc_html_e('Overwrite existing', 'ai-translator-for-elementor-and-polylang'); ?></label>
                        <span id="aitrfoel-st-autotranslate-msg" style="font-size:13px;color:#555;"></span>
                    </p>
                    <p class="description" style="font-size:12px;">
                        <?php esc_html_e('Scan forms reports which forms, filters, and SureCart products were detected. Auto-translate then fills every language for all detected strings (your saved entries, WooCommerce payment/checkout text, and the labels of JetFormBuilder, WPForms, Contact Form 7, Gravity Forms, and JetSmartFilters) using the translation engine. SureCart products are translated separately, on the Products tab. Review the results — save afterwards is automatic.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                    <script>
                    (function($){
                        // Live search for strings
                        $('#aitrfoel-st-search').on('input', function(){
                            var q = $(this).val().toLowerCase().trim();
                            var matchCount = 0;
                            var totalCount = 0;
                            $('#aitrfoel-st-rows tr.aitrfoel-st-row').each(function(){
                                totalCount++;
                                if (!q) {
                                    $(this).show();
                                    matchCount++;
                                    return;
                                }
                                var rowText = $(this).find('textarea, td').map(function(){ return $(this).val() || $(this).text(); }).get().join(' ').toLowerCase();
                                if (rowText.indexOf(q) !== -1) {
                                    $(this).show();
                                    matchCount++;
                                } else {
                                    $(this).hide();
                                }
                            });
                            if (q) {
                                $('#aitrfoel-st-match-count').text(matchCount + ' of ' + totalCount + ' <?php echo esc_js(__('strings found', 'ai-translator-for-elementor-and-polylang')); ?>');
                            } else {
                                $('#aitrfoel-st-match-count').text('');
                            }
                        });

                        $('#aitrfoel-st-scan').on('click', function(){
                            var $btn = $(this).prop('disabled', true);
                            var $msg = $('#aitrfoel-st-autotranslate-msg').text('<?php echo esc_js(__('Scanning…', 'ai-translator-for-elementor-and-polylang')); ?>');
                            $.post(ajaxurl, {
                                action: 'aitrfoel_scan_forms',
                                scope: 'filters',
                                nonce: '<?php echo esc_js(wp_create_nonce('aitrfoel_ajax_nonce')); ?>'
                            }).done(function(r){
                                if (r.success) {
                                    $msg.text(r.data && r.data.message ? r.data.message : '');
                                } else {
                                    $msg.text('<?php echo esc_js(__('Error:', 'ai-translator-for-elementor-and-polylang')); ?> ' + (r.data || '?'));
                                }
                                $btn.prop('disabled', false);
                            }).fail(function(){
                                $msg.text('<?php echo esc_js(__('Request failed — try again.', 'ai-translator-for-elementor-and-polylang')); ?>');
                                $btn.prop('disabled', false);
                            });
                        });
                        $('#aitrfoel-st-autotranslate').on('click', function(){
                            var $btn = $(this).prop('disabled', true);
                            var $msg = $('#aitrfoel-st-autotranslate-msg').text('<?php echo esc_js(__('Translating…', 'ai-translator-for-elementor-and-polylang')); ?>');
                            $.post(ajaxurl, {
                                action: 'aitrfoel_autotranslate_strings',
                                nonce: '<?php echo esc_js(wp_create_nonce('aitrfoel_ajax_nonce')); ?>',
                                force: $('#aitrfoel-st-force').is(':checked') ? 1 : 0
                            }).done(function(r){
                                if (r.success) {
                                    $msg.text('✅ ' + (r.data && r.data.message ? r.data.message : '') + ' <?php echo esc_js(__('Reloading…', 'ai-translator-for-elementor-and-polylang')); ?>');
                                    setTimeout(function(){ location.reload(); }, 1200);
                                } else {
                                    $msg.text('<?php echo esc_js(__('Error:', 'ai-translator-for-elementor-and-polylang')); ?> ' + (r.data || '?'));
                                    $btn.prop('disabled', false);
                                }
                            }).fail(function(){
                                $msg.text('<?php echo esc_js(__('Request failed — try again.', 'ai-translator-for-elementor-and-polylang')); ?>');
                                $btn.prop('disabled', false);
                            });
                        });
                    }(jQuery));
                    </script>
                    <script>
                    (function($){
                        var nextIndex = <?php echo (int) $st_next_index; ?>;
                        var langs = <?php echo wp_json_encode(array_map(function ($l) { return $l->code; }, $st_secondary)); ?>;
                        $('#aitrfoel-st-add').on('click', function(){
                            var cells = '<td><textarea name="aitrfoel_string_translations[rows]['+nextIndex+'][source]" rows="2" class="large-text code"></textarea></td>';
                            cells += '<td style="font-size:12px;color:#555;vertical-align:middle;"><?php echo esc_js(__('Custom', 'ai-translator-for-elementor-and-polylang')); ?></td>';
                            langs.forEach(function(code){
                                cells += '<td><textarea name="aitrfoel_string_translations[rows]['+nextIndex+'][trans]['+code+']" rows="2" class="large-text code" placeholder="'+code.toUpperCase()+'"></textarea></td>';
                            });
                            cells += '<td><button type="button" class="button button-small aitrfoel-st-remove" title="✕">✕</button></td>';
                            $('#aitrfoel-st-rows').append('<tr class="aitrfoel-st-row">'+cells+'</tr>');
                            nextIndex++;
                        });
                        $('#aitrfoel-st-table').on('click', '.aitrfoel-st-remove', function(){
                            var $rows = $('#aitrfoel-st-rows .aitrfoel-st-row');
                            if ($rows.length > 1) { $(this).closest('tr').remove(); }
                            else { $(this).closest('tr').find('textarea').val(''); }
                        });
                    }(jQuery));
                    </script>
                    <?php else :
                        $has_polylang = defined('POLYLANG_VERSION') || function_exists('pll_register_string');
                    ?>
                        <div class="aitrfoel-inactive-notice" style="display: flex; gap: 16px; align-items: flex-start;">
                            <div style="flex-shrink: 0; color: #1447e6; margin-top: 2px;">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                            </div>
                            <div>
                                <strong style="font-size: 14px; color: #0e1116; display: block; margin-bottom: 4px;">
                                    <?php echo $has_polylang ? esc_html__('String Translations Managed by Polylang', 'ai-translator-for-elementor-and-polylang') : esc_html__('Built-in Multilingual System Required', 'ai-translator-for-elementor-and-polylang'); ?>
                                </strong>
                                <p style="margin: 0 0 12px 0; color: #475569; font-size: 13.5px; line-height: 1.5;">
                                    <?php if ($has_polylang) : ?>
                                        <?php esc_html_e('Your website is actively configured with Polylang. All widget titles, contact form labels, payment gateway names, and plugin strings are automatically translated and managed through Polylang\'s Strings table.', 'ai-translator-for-elementor-and-polylang'); ?>
                                    <?php else : ?>
                                        <?php esc_html_e('To manage and auto-translate custom interface strings (contact form labels, filter terms, payment titles, etc.) directly in this table, enable the Built-in language system with at least one target language in the Multilingual tab.', 'ai-translator-for-elementor-and-polylang'); ?>
                                    <?php endif; ?>
                                </p>
                                <?php if ($has_polylang && function_exists('admin_url')) : ?>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=mlang_strings')); ?>" class="button button-secondary" style="display: inline-flex; align-items: center; gap: 6px;">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                                        <span><?php esc_html_e('Open Polylang String Translations', 'ai-translator-for-elementor-and-polylang'); ?></span>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Inline Save (String Translations) -->
                    <div class="aitrfoel-inline-save">
                        <?php submit_button(__('Save Settings', 'ai-translator-for-elementor-and-polylang'), 'primary', 'submit', false); ?>
                    </div>
                </div>
                
                </div><!-- /#tab-components -->

                <!-- ========================================================= -->
                <!-- TAB 4: Plugin Integrations                                -->
                <!-- ========================================================= -->
                <div class="aitrfoel-tab-pane" id="tab-integrations">
                
                <!-- WooCommerce Settings -->
                <div class="aitrfoel-settings-section" id="section-woocommerce">
                    <h3><?php esc_html_e('WooCommerce', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    
                    <?php
                    $woo_enabled = get_option('aitrfoel_woocommerce_support', true);
                    $woo_categories = get_option('aitrfoel_woo_translate_categories', true);
                    $woo_tags = get_option('aitrfoel_woo_translate_tags', true);
                    $woo_attributes = get_option('aitrfoel_woo_translate_attributes', true);
                    $woo_variations = get_option('aitrfoel_woo_translate_variations', true);
                    $woo_gallery_alt = get_option('aitrfoel_woo_translate_gallery_alt', true);
                    $woo_fallback = get_option('aitrfoel_woo_product_fallback', true);
                    $has_woo = class_exists('WooCommerce');
                    ?>
                    
                    <?php if ($has_woo) : ?>
                        <div class="aitrfoel-plugin-detected-notice is-success" style="display:inline-flex;align-items:center;gap:8px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                            <span><strong>WooCommerce</strong> <?php esc_html_e('detected — product translation support is available.', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </div>
                    <?php else : ?>
                        <div class="aitrfoel-plugin-detected-notice is-warning" style="display:inline-flex;align-items:center;gap:8px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            <span><?php esc_html_e('WooCommerce is not active. These settings will apply when WooCommerce is installed and activated.', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($has_woo) : // Hide the settings themselves until WooCommerce is active. ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_woocommerce_support"><?php esc_html_e('Product Support', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <input type="checkbox" 
                                       id="aitrfoel_woocommerce_support" 
                                       name="aitrfoel_woocommerce_support" 
                                       value="1" 
                                       <?php checked($woo_enabled); ?> />
                                <label for="aitrfoel_woocommerce_support"><?php esc_html_e('Enable WooCommerce product translation', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                <p class="description">
                                    <?php esc_html_e('Translates product descriptions, short descriptions, and copies product data (price, SKU, stock, etc.) to translated products.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_woo_translate_categories"><?php esc_html_e('Product Categories', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <input type="checkbox" 
                                       id="aitrfoel_woo_translate_categories" 
                                       name="aitrfoel_woo_translate_categories" 
                                       value="1" 
                                       <?php checked($woo_categories); ?> />
                                <label for="aitrfoel_woo_translate_categories"><?php esc_html_e('Assign translated product categories', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                <p class="description">
                                    <?php esc_html_e('When translating products, automatically assigns the translated category equivalents (if they exist in the target language via Polylang/WPML).', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_woo_translate_tags"><?php esc_html_e('Product Tags', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <input type="checkbox" 
                                       id="aitrfoel_woo_translate_tags" 
                                       name="aitrfoel_woo_translate_tags" 
                                       value="1" 
                                       <?php checked($woo_tags); ?> />
                                <label for="aitrfoel_woo_translate_tags"><?php esc_html_e('Assign translated product tags', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                <p class="description">
                                    <?php esc_html_e('Same as categories — assigns translated product tag equivalents to the translated product.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_woo_translate_attributes"><?php esc_html_e('Product Attributes', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <input type="checkbox" 
                                       id="aitrfoel_woo_translate_attributes" 
                                       name="aitrfoel_woo_translate_attributes" 
                                       value="1" 
                                       <?php checked($woo_attributes); ?> />
                                <label for="aitrfoel_woo_translate_attributes"><?php esc_html_e('Translate product attributes', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                <p class="description">
                                    <?php esc_html_e('Translates custom product attribute labels and values (e.g., Color, Size). For global taxonomy attributes (pa_*), assigns translated terms if available.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_woo_translate_variations"><?php esc_html_e('Variable Products', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <input type="checkbox" 
                                       id="aitrfoel_woo_translate_variations" 
                                       name="aitrfoel_woo_translate_variations" 
                                       value="1" 
                                       <?php checked($woo_variations); ?> />
                                <label for="aitrfoel_woo_translate_variations"><?php esc_html_e('Translate product variations', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                <p class="description">
                                    <?php esc_html_e('Creates translated variations for variable products, preserving parent-child relationships, pricing, and per-variation data (SKU, stock, dimensions).', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_woo_translate_gallery_alt"><?php esc_html_e('Gallery & Image ALT', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <input type="checkbox" 
                                       id="aitrfoel_woo_translate_gallery_alt" 
                                       name="aitrfoel_woo_translate_gallery_alt" 
                                       value="1" 
                                       <?php checked($woo_gallery_alt); ?> />
                                <label for="aitrfoel_woo_translate_gallery_alt"><?php esc_html_e('Translate product image alt text', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                <p class="description">
                                    <?php esc_html_e('Translates alt text, captions, and descriptions of product images and gallery images for multilingual SEO.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_woo_product_fallback"><?php esc_html_e('Product Fallback', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <input type="checkbox" 
                                       id="aitrfoel_woo_product_fallback" 
                                       name="aitrfoel_woo_product_fallback" 
                                       value="1" 
                                       <?php checked($woo_fallback); ?> />
                                <label for="aitrfoel_woo_product_fallback"><?php esc_html_e('Show default language products when no translation exists', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                <p class="description">
                                    <?php esc_html_e('When browsing the shop in a non-default language, untranslated products will still appear (in the default language) instead of being hidden. Translated products always take priority.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_translate_taxonomy_names"><?php esc_html_e('Translate Category & Tag Names', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <?php $translate_tax_names = get_option('aitrfoel_translate_taxonomy_names', true); ?>
                                <input type="checkbox" 
                                       id="aitrfoel_translate_taxonomy_names" 
                                       name="aitrfoel_translate_taxonomy_names" 
                                       value="1" 
                                       <?php checked($translate_tax_names); ?> />
                                <label for="aitrfoel_translate_taxonomy_names"><?php esc_html_e('Translate taxonomy term names into the target language', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                <p class="description">
                                    <?php esc_html_e('When translating posts or products, category and tag names are translated via AI and displayed in the correct language on the frontend. Works with WooCommerce product categories/tags and regular post categories/tags. Requires the built-in language system.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <?php
                    $mm_woo = class_exists('AITRFOEL_Multilingual_Manager') ? AITRFOEL_Multilingual_Manager::get_instance() : null;
                    if ($mm_woo && $mm_woo->is_builtin() && class_exists('WooCommerce')) :
                    ?>
                    <hr style="margin:24px 0 16px;">
                    <h4 style="margin:0 0 8px;"><?php esc_html_e('Retranslate Product Short Descriptions', 'ai-translator-for-elementor-and-polylang'); ?></h4>
                    <p class="description" style="margin-bottom:12px;">
                        <?php esc_html_e('Re-translates the short description (excerpt) for all translated products. Use this if product short descriptions still appear in the source language. Safe to re-run: already-translated products are skipped unless their source text changed, or you tick "Force re-translate".', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                    <div id="aitrfoel-excerpt-patch-wrap" style="margin-bottom:16px;">
                        <button type="button" id="aitrfoel-excerpt-patch-start" class="button button-secondary">
                            <?php esc_html_e('Retranslate Short Descriptions', 'ai-translator-for-elementor-and-polylang'); ?>
                        </button>
                        <label style="margin-left:10px;font-size:13px;display:inline-flex;align-items:center;gap:5px;">
                            <input type="checkbox" id="aitrfoel-excerpt-patch-force" value="1" style="margin:0;" />
                            <?php esc_html_e('Force re-translate (ignore already-translated)', 'ai-translator-for-elementor-and-polylang'); ?>
                        </label>
                        <div id="aitrfoel-excerpt-patch-status" style="display:none;margin-top:10px;">
                            <div style="background:#f0f0f0;border-radius:3px;height:16px;overflow:hidden;max-width:400px;">
                                <div id="aitrfoel-excerpt-patch-bar" style="background:#0073aa;height:100%;width:0;transition:width .3s;"></div>
                            </div>
                            <p id="aitrfoel-excerpt-patch-msg" style="margin:6px 0 0;color:#555;font-size:13px;"></p>
                        </div>
                    </div>
                    <script>
                    (function($){
                        var excNonce = '<?php echo esc_js(wp_create_nonce('aitrfoel_ajax_nonce')); ?>';
                        var excRunning = false;

                        $('#aitrfoel-excerpt-patch-start').on('click', function(){
                            if (excRunning) return;
                            excRunning = true;
                            var $btn = $(this).prop('disabled', true);
                            var $status = $('#aitrfoel-excerpt-patch-status').show();
                            var $bar = $('#aitrfoel-excerpt-patch-bar');
                            var $msg = $('#aitrfoel-excerpt-patch-msg');
                            $msg.text('<?php echo esc_js(__('Counting products…', 'ai-translator-for-elementor-and-polylang')); ?>');

                            $.post(ajaxurl, { action: 'aitrfoel_excerpt_patch_count', nonce: excNonce })
                            .done(function(r){
                                if (!r.success) {
                                    $msg.text('<?php echo esc_js(__('Error:', 'ai-translator-for-elementor-and-polylang')); ?> ' + (r.data || '?'));
                                    $btn.prop('disabled', false); excRunning = false; return;
                                }
                                var total = r.data.total;
                                if (total === 0) {
                                    $msg.text('<?php echo esc_js(__('No translated products found.', 'ai-translator-for-elementor-and-polylang')); ?>');
                                    $btn.prop('disabled', false); excRunning = false; return;
                                }
                                $msg.text('<?php echo esc_js(__('Retranslating 0 of', 'ai-translator-for-elementor-and-polylang')); ?> ' + total + '…');
                                var offset = 0, perPage = 10;

                                var forceVal = $('#aitrfoel-excerpt-patch-force').is(':checked') ? 1 : 0;
                                function nextBatch() {
                                    $.post(ajaxurl, { action: 'aitrfoel_excerpt_patch_batch', nonce: excNonce, offset: offset, per_page: perPage, force: forceVal })
                                    .done(function(br){
                                        if (!br.success) {
                                            $msg.text('<?php echo esc_js(__('Error during retranslating.', 'ai-translator-for-elementor-and-polylang')); ?>');
                                            $btn.prop('disabled', false); excRunning = false; return;
                                        }
                                        offset = br.data.next_offset;
                                        var pct = Math.min(100, Math.round(offset / total * 100));
                                        $bar.css('width', pct + '%');
                                        $msg.text('<?php echo esc_js(__('Retranslated', 'ai-translator-for-elementor-and-polylang')); ?> ' + offset + ' <?php echo esc_js(__('of', 'ai-translator-for-elementor-and-polylang')); ?> ' + total + '…');
                                        if (br.data.done) {
                                            $bar.css('width', '100%');
                                            $msg.text('✅ <?php echo esc_js(__('Done! Retranslated', 'ai-translator-for-elementor-and-polylang')); ?> ' + offset + ' <?php echo esc_js(__('short descriptions.', 'ai-translator-for-elementor-and-polylang')); ?>');
                                            $btn.prop('disabled', false); excRunning = false;
                                        } else {
                                            nextBatch();
                                        }
                                    })
                                    .fail(function(){
                                        $msg.text('<?php echo esc_js(__('Request failed — try again.', 'ai-translator-for-elementor-and-polylang')); ?>');
                                        $btn.prop('disabled', false); excRunning = false;
                                    });
                                }
                                nextBatch();
                            })
                            .fail(function(){
                                $msg.text('<?php echo esc_js(__('Could not connect to server.', 'ai-translator-for-elementor-and-polylang')); ?>');
                                $btn.prop('disabled', false); excRunning = false;
                            });
                        });
                    }(jQuery));
                    </script>
                    <?php endif; ?>

                    <!-- Inline Save (after WooCommerce) -->
                    <div class="aitrfoel-inline-save">
                        <?php submit_button(__('Save Settings', 'ai-translator-for-elementor-and-polylang'), 'primary', 'submit', false); ?>
                        <button type="button" class="button aitrfoel-reset-defaults" data-sections="woocommerce" title="<?php esc_attr_e('Reset WooCommerce settings to defaults', 'ai-translator-for-elementor-and-polylang'); ?>"><?php esc_html_e('Reset WooCommerce Defaults', 'ai-translator-for-elementor-and-polylang'); ?></button>
                    </div>
                    <?php endif; // $has_woo ?>
                </div>
                
                <?php if (defined('SURECART_PLUGIN_FILE')) : // SureCart section only appears when SureCart is installed. ?>
                <!-- SureCart -->
                <div class="aitrfoel-settings-section" id="section-surecart">
                    <h3><?php esc_html_e('SureCart', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    <div class="aitrfoel-plugin-detected-notice is-success" style="display:inline-flex;align-items:center;gap:8px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                        <span><strong>SureCart</strong> <?php esc_html_e('detected — product translation is available.', 'ai-translator-for-elementor-and-polylang'); ?></span>
                    </div>
                    <?php $surecart_enabled = get_option('aitrfoel_surecart_support', true); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_surecart_support"><?php esc_html_e('Product Support', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <input type="checkbox" id="aitrfoel_surecart_support" name="aitrfoel_surecart_support" value="1" <?php checked($surecart_enabled); ?> />
                                <label for="aitrfoel_surecart_support"><?php esc_html_e('Enable SureCart product translation', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                <p class="description">
                                    <?php esc_html_e('Translates SureCart product names and descriptions and renders the translated version in the visitor\'s language across product pages, collection grids, and checkout. Products are translated and reviewed on the dashboard\'s Products tab, just like WooCommerce products — there is nothing else to configure here.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <div class="aitrfoel-inline-save">
                        <?php submit_button(__('Save Settings', 'ai-translator-for-elementor-and-polylang'), 'primary', 'submit', false); ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- SEO Translation Settings -->
                <div class="aitrfoel-settings-section" id="section-seo">
                    <h3><?php esc_html_e('SEO Translation', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    
                    <?php
                    $seo_enabled = get_option('aitrfoel_seo_translation_enabled', true);
                    $seo_og = get_option('aitrfoel_seo_translate_og', true);
                    $seo_twitter = get_option('aitrfoel_seo_translate_twitter', true);
                    $seo_plugin = AITRFOEL_Translation_Engine::detected_seo_plugin();
                    ?>
                    
                    <?php if ($seo_plugin) : ?>
                        <div class="aitrfoel-plugin-detected-notice is-success" style="display:inline-flex;align-items:center;gap:8px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                            <span><strong><?php echo esc_html($seo_plugin); ?></strong> <?php esc_html_e('detected — SEO meta fields will be translated automatically.', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </div>
                    <?php else : ?>
                        <div class="aitrfoel-plugin-detected-notice is-warning" style="display:inline-flex;align-items:center;gap:8px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            <span><?php esc_html_e('No supported SEO plugin detected. Install Yoast SEO, Rank Math, SEOPress or SureRank to enable SEO meta translation.', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_seo_translation_enabled"><?php esc_html_e('SEO Meta Translation', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <input type="checkbox" 
                                       id="aitrfoel_seo_translation_enabled" 
                                       name="aitrfoel_seo_translation_enabled" 
                                       value="1" 
                                       <?php checked($seo_enabled); ?> />
                                <label for="aitrfoel_seo_translation_enabled"><?php esc_html_e('Translate SEO title & meta description', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                <p class="description">
                                    <?php esc_html_e('Automatically translates SEO meta title, description, and focus keywords when translating pages.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_seo_translate_og"><?php esc_html_e('Open Graph Tags', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <input type="checkbox" 
                                       id="aitrfoel_seo_translate_og" 
                                       name="aitrfoel_seo_translate_og" 
                                       value="1" 
                                       <?php checked($seo_og); ?> />
                                <label for="aitrfoel_seo_translate_og"><?php esc_html_e('Translate Open Graph title & description', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                <p class="description">
                                    <?php esc_html_e('Translates og:title and og:description for Facebook/social sharing previews.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_seo_translate_twitter"><?php esc_html_e('Twitter Card Tags', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <input type="checkbox" 
                                       id="aitrfoel_seo_translate_twitter" 
                                       name="aitrfoel_seo_translate_twitter" 
                                       value="1" 
                                       <?php checked($seo_twitter); ?> />
                                <label for="aitrfoel_seo_translate_twitter"><?php esc_html_e('Translate Twitter card title & description', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                <p class="description">
                                    <?php esc_html_e('Translates twitter:title and twitter:description for Twitter/X sharing.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="description aitrfoel-seo-supported-note" style="display:flex;align-items:center;gap:6px;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18h6"/><path d="M10 22h4"/><path d="M15.09 14c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0 0 18 8 6 6 0 0 0 6 8c0 1 .23 2.23 1.5 3.5A4.61 4.61 0 0 1 8.91 14"/></svg>
                        <span><?php esc_html_e('Supported plugins: Yoast SEO, Rank Math, SEOPress and SureRank. All are auto-detected at runtime.', 'ai-translator-for-elementor-and-polylang'); ?></span>
                    </p>

                    <?php
                    $mm = class_exists('AITRFOEL_Multilingual_Manager') ? AITRFOEL_Multilingual_Manager::get_instance() : null;
                    if ($mm && $mm->is_builtin()) :
                    ?>
                    <hr style="margin:24px 0 16px;">
                    <h4 style="margin:0 0 8px;"><?php esc_html_e('Retranslate Existing SEO Titles', 'ai-translator-for-elementor-and-polylang'); ?></h4>
                    <p class="description" style="margin-bottom:12px;">
                        <?php esc_html_e('Re-translates SEO title, meta description, OG, and Twitter fields for all already-translated pages. Use this if translated pages still show the source-language SEO title. (To fix product short descriptions, use the "Retranslate Short Descriptions" tool below.)', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                    <div id="aitrfoel-seo-patch-wrap" style="margin-bottom:16px;">
                        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px;">
                            <button type="button" id="aitrfoel-seo-patch-start" class="button button-secondary">
                                <?php esc_html_e('Retranslate SEO Titles', 'ai-translator-for-elementor-and-polylang'); ?>
                            </button>
                            <button type="button" id="aitrfoel-seo-check-start" class="button button-secondary">
                                <?php esc_html_e('Check Translation Status', 'ai-translator-for-elementor-and-polylang'); ?>
                            </button>
                        </div>
                        <div id="aitrfoel-seo-check-result" style="display:none;margin-bottom:10px;padding:10px 12px;background:#f6f7f7;border-left:4px solid #72aee6;font-size:13px;"></div>
                        <div id="aitrfoel-seo-patch-status" style="display:none;margin-top:4px;">
                            <div style="background:#f0f0f0;border-radius:3px;height:16px;overflow:hidden;max-width:400px;">
                                <div id="aitrfoel-seo-patch-bar" style="background:#0073aa;height:100%;width:0;transition:width .3s;"></div>
                            </div>
                            <p id="aitrfoel-seo-patch-msg" style="margin:6px 0 0;color:#555;font-size:13px;"></p>
                        </div>
                    </div>
                    <script>
                    (function($){
                        var patchNonce = '<?php echo esc_js(wp_create_nonce('aitrfoel_ajax_nonce')); ?>';
                        var running = false;

                        // ---- Retranslate ----
                        $('#aitrfoel-seo-patch-start').on('click', function(){
                            if (running) return;
                            running = true;
                            var $btn  = $(this).prop('disabled', true);
                            $('#aitrfoel-seo-check-start').prop('disabled', true);
                            $('#aitrfoel-seo-patch-status').show();
                            var $bar  = $('#aitrfoel-seo-patch-bar');
                            var $msg  = $('#aitrfoel-seo-patch-msg');
                            $msg.text('<?php echo esc_js(__('Counting pages…', 'ai-translator-for-elementor-and-polylang')); ?>');

                            $.post(ajaxurl, { action: 'aitrfoel_seo_patch_count', nonce: patchNonce })
                            .done(function(r){
                                if (!r.success) {
                                    $msg.text('<?php echo esc_js(__('Error:', 'ai-translator-for-elementor-and-polylang')); ?> ' + (r.data || '?'));
                                    $btn.prop('disabled', false);
                                    $('#aitrfoel-seo-check-start').prop('disabled', false);
                                    running = false;
                                    return;
                                }
                                var total = r.data.total;
                                if (total === 0) {
                                    $msg.text('<?php echo esc_js(__('No translated pages found.', 'ai-translator-for-elementor-and-polylang')); ?>');
                                    $btn.prop('disabled', false);
                                    $('#aitrfoel-seo-check-start').prop('disabled', false);
                                    running = false;
                                    return;
                                }
                                $msg.text('<?php echo esc_js(__('Retranslating 0 of', 'ai-translator-for-elementor-and-polylang')); ?> ' + total + '…');
                                var offset = 0, perPage = 20;

                                function nextBatch() {
                                    $.post(ajaxurl, { action: 'aitrfoel_seo_patch_batch', nonce: patchNonce, offset: offset, per_page: perPage })
                                    .done(function(br){
                                        if (!br.success) {
                                            $msg.text('<?php echo esc_js(__('Error during retranslating.', 'ai-translator-for-elementor-and-polylang')); ?>');
                                            $btn.prop('disabled', false);
                                            $('#aitrfoel-seo-check-start').prop('disabled', false);
                                            running = false;
                                            return;
                                        }
                                        offset = br.data.next_offset;
                                        var pct = Math.min(100, Math.round(offset / total * 100));
                                        $bar.css('width', pct + '%');
                                        $msg.text('<?php echo esc_js(__('Retranslated', 'ai-translator-for-elementor-and-polylang')); ?> ' + offset + ' <?php echo esc_js(__('of', 'ai-translator-for-elementor-and-polylang')); ?> ' + total + '…');
                                        if (br.data.done) {
                                            $bar.css('width', '100%');
                                            $msg.text('✅ <?php echo esc_js(__('Done! Retranslated', 'ai-translator-for-elementor-and-polylang')); ?> ' + offset + ' <?php echo esc_js(__('translations.', 'ai-translator-for-elementor-and-polylang')); ?>');
                                            $btn.prop('disabled', false);
                                            $('#aitrfoel-seo-check-start').prop('disabled', false);
                                            running = false;
                                        } else {
                                            nextBatch();
                                        }
                                    })
                                    .fail(function(){
                                        $msg.text('<?php echo esc_js(__('Request failed — try again.', 'ai-translator-for-elementor-and-polylang')); ?>');
                                        $btn.prop('disabled', false);
                                        $('#aitrfoel-seo-check-start').prop('disabled', false);
                                        running = false;
                                    });
                                }
                                nextBatch();
                            })
                            .fail(function(){
                                $msg.text('<?php echo esc_js(__('Could not connect to server.', 'ai-translator-for-elementor-and-polylang')); ?>');
                                $btn.prop('disabled', false);
                                $('#aitrfoel-seo-check-start').prop('disabled', false);
                                running = false;
                            });
                        });

                        // ---- Check Status ----
                        $('#aitrfoel-seo-check-start').on('click', function(){
                            if (running) return;
                            running = true;
                            var $btn = $(this).prop('disabled', true);
                            $('#aitrfoel-seo-patch-start').prop('disabled', true);
                            var $result = $('#aitrfoel-seo-check-result').show().text('<?php echo esc_js(__('Checking…', 'ai-translator-for-elementor-and-polylang')); ?>');

                            $.post(ajaxurl, { action: 'aitrfoel_seo_check', nonce: patchNonce })
                            .done(function(r){
                                if (!r.success) {
                                    $result.text('<?php echo esc_js(__('Error:', 'ai-translator-for-elementor-and-polylang')); ?> ' + (r.data || '?'));
                                } else {
                                    var d = r.data;
                                    if (d.total === 0) {
                                        $result.html('<?php echo esc_js(__('No translated pages found.', 'ai-translator-for-elementor-and-polylang')); ?>');
                                    } else if (d.needs_retranslation === 0) {
                                        $result.css('border-left-color', '#00a32a').html('✅ <?php echo esc_js(__('All', 'ai-translator-for-elementor-and-polylang')); ?> ' + d.total + ' <?php echo esc_js(__('translations have correct SEO titles.', 'ai-translator-for-elementor-and-polylang')); ?>');
                                    } else {
                                        $result.css('border-left-color', '#dba617').html(
                                            '⚠️ <strong>' + d.needs_retranslation + ' / ' + d.total + '</strong> <?php echo esc_js(__('translations need retranslation', 'ai-translator-for-elementor-and-polylang')); ?>'
                                            + ' &nbsp;·&nbsp; '
                                            + d.ok + ' <?php echo esc_js(__('appear correctly translated.', 'ai-translator-for-elementor-and-polylang')); ?>'
                                        );
                                    }
                                }
                                $btn.prop('disabled', false);
                                $('#aitrfoel-seo-patch-start').prop('disabled', false);
                                running = false;
                            })
                            .fail(function(){
                                $result.text('<?php echo esc_js(__('Could not connect to server.', 'ai-translator-for-elementor-and-polylang')); ?>');
                                $btn.prop('disabled', false);
                                $('#aitrfoel-seo-patch-start').prop('disabled', false);
                                running = false;
                            });
                        });
                    }(jQuery));
                    </script>
                    <?php endif; ?>


                    <!-- Inline Save (after SEO / before WooCommerce) -->
                    <div class="aitrfoel-inline-save">
                        <?php submit_button(__('Save Settings', 'ai-translator-for-elementor-and-polylang'), 'primary', 'submit', false); ?>
                        <button type="button" class="button aitrfoel-reset-defaults" data-sections="seo" title="<?php esc_attr_e('Reset SEO settings to defaults', 'ai-translator-for-elementor-and-polylang'); ?>"><?php esc_html_e('Reset SEO Defaults', 'ai-translator-for-elementor-and-polylang'); ?></button>
                    </div>
                </div>
                
                </div><!-- /#tab-integrations -->

                <!-- ========================================================= -->
                <!-- TAB 5: Brand & Glossary                                   -->
                <!-- ========================================================= -->
                <div class="aitrfoel-tab-pane" id="tab-glossary">
                
                <!-- Smart Translation Memory (TM) Card -->
                <div class="aitrfoel-settings-section" id="section-translation-memory">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:12px;">
                        <div class="aitrfoel-tm-title-col">
                            <h3 style="margin:0;"><?php esc_html_e('Smart Translation Memory', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                            <p class="description" style="margin:6px 0 0 0;">
                                <?php esc_html_e('Automatically stores translated phrases and Elementor widgets. Re-uses identical content across your site at 0 extra word cost and maximum speed.', 'ai-translator-for-elementor-and-polylang'); ?>
                            </p>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <button type="button" class="button button-secondary button-small" id="aitrfoel-clear-tm-btn" style="display:inline-flex;align-items:center;gap:5px;color:#dc2626;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                <span id="aitrfoel-clear-tm-label"><?php esc_html_e('Clear Memory', 'ai-translator-for-elementor-and-polylang'); ?></span>
                            </button>
                        </div>
                    </div>

                    <?php
                    $tm_stats = class_exists('AITRFOEL_Translation_Memory') ? AITRFOEL_Translation_Memory::get_instance()->get_stats() : ['total_entries' => 0, 'total_words_saved' => 0, 'total_hits' => 0];
                    ?>
                    <div class="aitrfoel-inventory-grid" style="margin-top:14px;margin-bottom:16px;">
                        <div class="aitrfoel-inv-stat-card">
                            <div class="aitrfoel-inv-stat-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                            </div>
                            <div class="aitrfoel-inv-stat-info">
                                <div class="aitrfoel-inv-stat-num" id="tm-stat-entries"><?php echo esc_html(number_format_i18n($tm_stats['total_entries'])); ?></div>
                                <div class="aitrfoel-inv-stat-label"><?php esc_html_e('Phrases in Memory', 'ai-translator-for-elementor-and-polylang'); ?></div>
                            </div>
                        </div>
                        <div class="aitrfoel-inv-stat-card highlight">
                            <div class="aitrfoel-inv-stat-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                            </div>
                            <div class="aitrfoel-inv-stat-info">
                                <div class="aitrfoel-inv-stat-num" id="tm-stat-words-saved">~<?php echo esc_html(number_format_i18n($tm_stats['total_words_saved'])); ?></div>
                                <div class="aitrfoel-inv-stat-label"><?php esc_html_e('Words Saved for Your Plan', 'ai-translator-for-elementor-and-polylang'); ?></div>
                            </div>
                        </div>
                        <div class="aitrfoel-inv-stat-card">
                            <div class="aitrfoel-inv-stat-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                            </div>
                            <div class="aitrfoel-inv-stat-info">
                                <div class="aitrfoel-inv-stat-num" id="tm-stat-hits"><?php echo esc_html(number_format_i18n($tm_stats['total_hits'])); ?></div>
                                <div class="aitrfoel-inv-stat-label"><?php esc_html_e('Instant Memory Reuses', 'ai-translator-for-elementor-and-polylang'); ?></div>
                            </div>
                        </div>
                    </div>

                    <table class="form-table" style="margin-top:0;">
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_enable_translation_memory"><?php esc_html_e('Translation Memory', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                                    <label class="aitrfoel-switch" style="margin:0;flex-shrink:0;">
                                        <input type="checkbox" id="aitrfoel_enable_translation_memory" name="aitrfoel_enable_translation_memory" value="1" <?php checked(get_option('aitrfoel_enable_translation_memory', 1), 1); ?>>
                                        <span class="aitrfoel-slider"></span>
                                    </label>
                                    <span class="description" style="margin:0;line-height:1.4;">
                                        <?php esc_html_e('When enabled, identical headings, buttons, and paragraphs across your site are translated once and reused at zero word cost.', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </span>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ========================================================= -->
                <!-- PRO Brand Voice & Localization Tone Card                 -->
                <!-- ========================================================= -->
                <?php
                $is_pro_plan = (defined('AITRFOEL_VERSION_TYPE') && AITRFOEL_VERSION_TYPE === 'pro');
                $upgrade_pricing_url = aitrfoel_free_upgrade_url('plugin-settings');
                ?>
                <div class="aitrfoel-settings-section <?php echo !$is_pro_plan ? 'aitrfoel-pro-locked-section' : ''; ?>" id="section-brand-voice">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:14px;">
                        <div class="aitrfoel-brand-voice-head">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                                <h3 style="margin:0;"><?php esc_html_e('Brand Voice & Localization Tone', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                                <span class="aitrfoel-pro-badge" style="background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;font-size:11px;font-weight:700;padding:2px 8px;border-radius:12px;letter-spacing:0.04em;">PRO</span>
                            </div>
                            <p class="description" style="margin:4px 0 0 0;">
                                <?php esc_html_e('Adapt AI translation personality, copy tone, and formality to match your brand identity across all target languages on the first pass.', 'ai-translator-for-elementor-and-polylang'); ?>
                            </p>
                        </div>
                        <div>
                            <button type="button" class="button button-secondary" id="aitrfoel-detect-site-tone-btn" style="display:inline-flex;align-items:center;gap:6px;" <?php echo !$is_pro_plan ? 'disabled' : ''; ?>>
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
                                <span id="aitrfoel-detect-tone-label"><?php esc_html_e('Detect Site Context & Suggest Tone', 'ai-translator-for-elementor-and-polylang'); ?></span>
                            </button>
                        </div>
                    </div>

                    <?php if (!$is_pro_plan): ?>
                    <!-- PRO Feature Upgrade Banner for Free Users -->
                    <div class="aitrfoel-pro-upgrade-banner" style="background:linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);border:1px solid #bae6fd;border-radius:8px;padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;">
                        <div style="display:flex;align-items:center;gap:12px;">
                            <span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;background:#0284c7;color:#fff;border-radius:8px;font-weight:700;font-size:14px;">PRO</span>
                            <div>
                                <strong style="color:#0369a1;font-size:13.5px;display:block;"><?php esc_html_e('Unlock Brand Voice & Formality Adaptation in PRO', 'ai-translator-for-elementor-and-polylang'); ?></strong>
                                <span style="color:#0284c7;font-size:12px;"><?php esc_html_e('Enforce custom brand persona rules, hospitality/sales tone presets, and strict grammatical address across German, French, Spanish & Italian.', 'ai-translator-for-elementor-and-polylang'); ?></span>
                            </div>
                        </div>
                        <a href="<?php echo esc_url($upgrade_pricing_url); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary" style="background:#0284c7;border-color:#0284c7;color:#ffffff;font-weight:600;white-space:nowrap;padding:4px 16px;">
                            <?php esc_html_e('Upgrade to PRO', 'ai-translator-for-elementor-and-polylang'); ?> &rarr;
                        </a>
                    </div>
                    <?php endif; ?>

                    <!-- AI Context Detection Result Box (Dynamic) -->
                    <div id="aitrfoel-tone-scan-result" style="display:none;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px 16px;margin-bottom:16px;align-items:center;justify-content:space-between;gap:12px;">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/></svg>
                            <div>
                                <strong id="aitrfoel-tone-scan-title" style="color:#166534;font-size:13px;display:block;"></strong>
                                <span id="aitrfoel-tone-scan-desc" style="color:#15803d;font-size:12px;"></span>
                            </div>
                        </div>
                        <button type="button" class="button button-small" id="aitrfoel-apply-suggested-tone" style="white-space:nowrap;background:#16a34a;color:#fff;border-color:#16a34a;">
                            <?php esc_html_e('Apply Suggestion', 'ai-translator-for-elementor-and-polylang'); ?>
                        </button>
                    </div>

                    <?php
                    $current_tone = get_option('aitrfoel_brand_tone_preset', 'corporate');
                    $custom_tone_rules = get_option('aitrfoel_brand_tone_custom', '');
                    $current_formality = get_option('aitrfoel_formality_mode', 'auto');
                    ?>

                    <div class="<?php echo !$is_pro_plan ? 'aitrfoel-pro-preview-locked' : ''; ?>">
                        <!-- Visual Tone Presets Grid -->
                        <input type="hidden" name="aitrfoel_brand_tone_preset" id="aitrfoel_brand_tone_preset_input" value="<?php echo esc_attr($current_tone); ?>">

                        <div class="aitrfoel-tone-presets-grid">
                            <!-- Preset: Hospitality -->
                            <div class="aitrfoel-tone-card <?php echo ($current_tone === 'hospitality') ? 'selected' : ''; ?>" data-tone="hospitality">
                                <div class="aitrfoel-tone-card-header">
                                    <span class="aitrfoel-tone-icon">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16M9 7h1M9 11h1M9 15h1M14 7h1M14 11h1M14 15h1"/></svg>
                                    </span>
                                    <span class="aitrfoel-tone-tag"><?php esc_html_e('Hospitality & Travel', 'ai-translator-for-elementor-and-polylang'); ?></span>
                                </div>
                                <div class="aitrfoel-tone-title"><?php esc_html_e('Warm & Welcoming', 'ai-translator-for-elementor-and-polylang'); ?></div>
                                <div class="aitrfoel-tone-desc"><?php esc_html_e('Friendly, inviting, and emotionally resonant. Ideal for hotels, travel, dining, and wellness.', 'ai-translator-for-elementor-and-polylang'); ?></div>
                            </div>

                            <!-- Preset: Corporate -->
                            <div class="aitrfoel-tone-card <?php echo ($current_tone === 'corporate') ? 'selected' : ''; ?>" data-tone="corporate">
                                <div class="aitrfoel-tone-card-header">
                                    <span class="aitrfoel-tone-icon">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                                    </span>
                                    <span class="aitrfoel-tone-tag"><?php esc_html_e('B2B & Tech', 'ai-translator-for-elementor-and-polylang'); ?></span>
                                </div>
                                <div class="aitrfoel-tone-title"><?php esc_html_e('Professional & Corporate', 'ai-translator-for-elementor-and-polylang'); ?></div>
                                <div class="aitrfoel-tone-desc"><?php esc_html_e('Polished, clear, and authoritative. Ideal for SaaS, consulting, financial, and legal services.', 'ai-translator-for-elementor-and-polylang'); ?></div>
                            </div>

                            <!-- Preset: Sales -->
                            <div class="aitrfoel-tone-card <?php echo ($current_tone === 'sales') ? 'selected' : ''; ?>" data-tone="sales">
                                <div class="aitrfoel-tone-card-header">
                                    <span class="aitrfoel-tone-icon">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4zM3 6h18M16 10a4 4 0 0 1-8 0"/></svg>
                                    </span>
                                    <span class="aitrfoel-tone-tag"><?php esc_html_e('eCommerce & DTC', 'ai-translator-for-elementor-and-polylang'); ?></span>
                                </div>
                                <div class="aitrfoel-tone-title"><?php esc_html_e('Persuasive & Sales-Driven', 'ai-translator-for-elementor-and-polylang'); ?></div>
                                <div class="aitrfoel-tone-desc"><?php esc_html_e('Compelling, benefits-driven, and high energy. Ideal for stores, offers, and product launches.', 'ai-translator-for-elementor-and-polylang'); ?></div>
                            </div>

                            <!-- Preset: Casual -->
                            <div class="aitrfoel-tone-card <?php echo ($current_tone === 'casual') ? 'selected' : ''; ?>" data-tone="casual">
                                <div class="aitrfoel-tone-card-header">
                                    <span class="aitrfoel-tone-icon">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>
                                    </span>
                                    <span class="aitrfoel-tone-tag"><?php esc_html_e('Blogs & Creators', 'ai-translator-for-elementor-and-polylang'); ?></span>
                                </div>
                                <div class="aitrfoel-tone-title"><?php esc_html_e('Casual & Modern', 'ai-translator-for-elementor-and-polylang'); ?></div>
                                <div class="aitrfoel-tone-desc"><?php esc_html_e('Approachable, conversational, and direct. Ideal for modern digital brands, creators, and blogs.', 'ai-translator-for-elementor-and-polylang'); ?></div>
                            </div>

                            <!-- Preset: Custom -->
                            <div class="aitrfoel-tone-card <?php echo ($current_tone === 'custom') ? 'selected' : ''; ?>" data-tone="custom">
                                <div class="aitrfoel-tone-card-header">
                                    <span class="aitrfoel-tone-icon">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                                    </span>
                                    <span class="aitrfoel-tone-tag"><?php esc_html_e('Custom Persona', 'ai-translator-for-elementor-and-polylang'); ?></span>
                                </div>
                                <div class="aitrfoel-tone-title"><?php esc_html_e('Custom Brand Rules', 'ai-translator-for-elementor-and-polylang'); ?></div>
                                <div class="aitrfoel-tone-desc"><?php esc_html_e('Define your exact persona, rules, and vocabulary constraints in the box below.', 'ai-translator-for-elementor-and-polylang'); ?></div>
                            </div>
                        </div>

                        <!-- Tone Configuration Fields -->
                        <table class="form-table" style="margin-top:16px;">
                            <tr>
                                <th scope="row">
                                    <label for="aitrfoel_formality_mode"><?php esc_html_e('Grammatical Formality', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                </th>
                                <td>
                                    <select name="aitrfoel_formality_mode" id="aitrfoel_formality_mode" style="min-width:280px;" <?php echo !$is_pro_plan ? 'disabled' : ''; ?>>
                                        <option value="auto" <?php selected($current_formality, 'auto'); ?>><?php esc_html_e('Auto (Follow Selected Tone Preset)', 'ai-translator-for-elementor-and-polylang'); ?></option>
                                        <option value="formal" <?php selected($current_formality, 'formal'); ?>><?php esc_html_e('Strict Formal Address (Sie / Vous / Usted / Lei / Вы)', 'ai-translator-for-elementor-and-polylang'); ?></option>
                                        <option value="informal" <?php selected($current_formality, 'informal'); ?>><?php esc_html_e('Friendly / Informal Address (Du / Tu / Tú / ты)', 'ai-translator-for-elementor-and-polylang'); ?></option>
                                    </select>
                                    <p class="description" style="margin-top:6px;">
                                        <?php esc_html_e('Controls formal vs informal pronoun addressing in languages with grammatical T-V distinction (German, French, Spanish, Italian, Russian, etc.).', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="aitrfoel_brand_tone_custom"><?php esc_html_e('Custom Brand Rules & Guidelines', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                </th>
                                <td>
                                    <textarea name="aitrfoel_brand_tone_custom" id="aitrfoel_brand_tone_custom" rows="3" class="large-text code" style="max-width:680px;" placeholder="<?php esc_attr_e('e.g., Speak as an eco-conscious outdoor brand. Keep all action verbs punchy and direct. Never use corporate buzzwords.', 'ai-translator-for-elementor-and-polylang'); ?>" <?php echo !$is_pro_plan ? 'disabled' : ''; ?>><?php echo esc_textarea($custom_tone_rules); ?></textarea>
                                    <p class="description" style="margin-top:6px;">
                                        <?php esc_html_e('Optional free-form guidelines injected directly into Claude and ChatGPT translation prompts alongside your chosen preset.', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Translation Glossary -->
                <div class="aitrfoel-settings-section" id="section-glossary">
                    <h3><?php esc_html_e('Brand Intelligence & Glossary', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    
                    <p class="description aitrfoel-section-intro">
                        <?php esc_html_e('Define brand names, slogans, and company terminology that must NEVER be translated, or specify exact custom translations for specific terms across languages.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>

                    <div class="aitrfoel-glossary-manager">
                        <div class="aitrfoel-glossary-toolbar">
                            <div style="display:flex;gap:10px;align-items:center;">
                                <button type="button" class="button button-primary" id="aitrfoel-open-add-term-modal">
                                    + <?php esc_html_e('Add Glossary Term', 'ai-translator-for-elementor-and-polylang'); ?>
                                </button>
                            </div>
                            <div style="display:flex;gap:10px;align-items:center;">
                                <button type="button" class="button" id="aitrfoel-export-glossary-csv">
                                    <?php esc_html_e('Export CSV', 'ai-translator-for-elementor-and-polylang'); ?>
                                </button>
                                <label class="button" style="cursor: pointer;margin:0;">
                                    <?php esc_html_e('Import CSV', 'ai-translator-for-elementor-and-polylang'); ?>
                                    <input type="file" id="aitrfoel-import-glossary-file" accept=".csv" style="display: none;">
                                </label>
                            </div>
                        </div>

                        <table class="wp-list-table widefat fixed striped aitrfoel-glossary-table">
                            <thead>
                                <tr>
                                    <th style="width: 30%;"><?php esc_html_e('Term / Phrase', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                    <th style="width: 20%;"><?php esc_html_e('Target Language', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                    <th style="width: 35%;"><?php esc_html_e('Rule / Translation', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                    <th style="width: 15%; text-align: right;"><?php esc_html_e('Action', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="aitrfoel-glossary-tbody">
                                <?php
                                global $wpdb;
                                $g_table = $wpdb->prefix . 'aitrfoel_glossary';
                                $terms = [];
                                if ($wpdb->get_var("SHOW TABLES LIKE '$g_table'") === $g_table) {
                                    $terms = $wpdb->get_results("SELECT * FROM $g_table ORDER BY id DESC", ARRAY_A);
                                }
                                if (empty($terms)):
                                ?>
                                <tr id="aitrfoel-glossary-empty-row">
                                    <td colspan="4" style="text-align: center; color: #666; padding: 24px;">
                                        <?php esc_html_e('No glossary terms added yet. Click "Add Glossary Term" above to create your first brand protection rule.', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($terms as $t): ?>
                                    <tr data-term-id="<?php echo esc_attr($t['id']); ?>">
                                        <td><strong><?php echo esc_html($t['source_text']); ?></strong></td>
                                        <td>
                                            <?php 
                                            if ($t['lang_code'] === 'all' || empty($t['lang_code'])) {
                                                echo '<span class="badge" style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">' . esc_html__('All Languages', 'ai-translator-for-elementor-and-polylang') . '</span>';
                                            } else {
                                                echo '<span class="badge" style="background:#f1f5f9;color:#334155;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">' . esc_html(strtoupper($t['lang_code'])) . '</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php if ($t['action'] === 'do_not_translate'): ?>
                                                <span style="color:#b91c1c;font-weight:500;"><?php esc_html_e('Do Not Translate (Keep exact source)', 'ai-translator-for-elementor-and-polylang'); ?></span>
                                            <?php else: ?>
                                                <span style="color:#15803d;font-weight:500;">→ <?php echo esc_html($t['target_text']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right;">
                                            <button type="button" class="button button-small button-link-delete aitrfoel-delete-glossary-term" data-id="<?php echo esc_attr($t['id']); ?>">
                                                <?php esc_html_e('Delete', 'ai-translator-for-elementor-and-polylang'); ?>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Modal for Adding Glossary Term -->
                    <div id="aitrfoel-glossary-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:100002;align-items:center;justify-content:center;padding:20px;">
                        <div style="background:#fff;border-radius:12px;width:100%;max-width:480px;padding:24px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1),0 10px 10px -5px rgba(0,0,0,0.04);position:relative;">
                            <h3 style="margin-top:0;font-size:18px;color:#0f172a;display:flex;align-items:center;gap:8px;">
                                <?php esc_html_e('Add Glossary Rule', 'ai-translator-for-elementor-and-polylang'); ?>
                            </h3>
                            
                            <div style="margin-bottom:16px;">
                                <label style="display:block;font-weight:600;margin-bottom:6px;font-size:13px;"><?php esc_html_e('Term / Phrase / Brand Name', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                <input type="text" id="aitrfoel-term-source" class="widefat" placeholder="e.g. Meep AI, Pro Plan, Checkout" style="padding:8px 12px;border-radius:6px;">
                            </div>

                            <div style="margin-bottom:16px;">
                                <label style="display:block;font-weight:600;margin-bottom:6px;font-size:13px;"><?php esc_html_e('Rule Type', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                <select id="aitrfoel-term-action" class="widefat" style="padding:8px 12px;border-radius:6px;">
                                    <option value="do_not_translate"><?php esc_html_e('Do Not Translate (Keep exact source text)', 'ai-translator-for-elementor-and-polylang'); ?></option>
                                    <option value="replace"><?php esc_html_e('Custom Translation (Replace with specific term)', 'ai-translator-for-elementor-and-polylang'); ?></option>
                                </select>
                            </div>

                            <div id="aitrfoel-term-target-group" style="display:none;margin-bottom:16px;">
                                <label style="display:block;font-weight:600;margin-bottom:6px;font-size:13px;"><?php esc_html_e('Target Translation Replacement', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                <input type="text" id="aitrfoel-term-target" class="widefat" placeholder="e.g. Afrekenen" style="padding:8px 12px;border-radius:6px;">
                            </div>

                            <div style="margin-bottom:20px;">
                                <label style="display:block;font-weight:600;margin-bottom:6px;font-size:13px;"><?php esc_html_e('Applies To Language', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                <select id="aitrfoel-term-lang" class="widefat" style="padding:8px 12px;border-radius:6px;">
                                    <option value="all"><?php esc_html_e('All Languages (Universal Rule)', 'ai-translator-for-elementor-and-polylang'); ?></option>
                                    <?php 
                                    if (class_exists('AITRFOEL_Multilingual_Manager')) {
                                        $avail_langs = AITRFOEL_Multilingual_Manager::get_instance()->get_available_languages();
                                        foreach ($avail_langs as $code => $lobj) {
                                            $lname = is_object($lobj) ? $lobj->name : (class_exists('AITRFOEL_Language_Mapper') ? AITRFOEL_Language_Mapper::get_language_name($code) : strtoupper($code));
                                            echo '<option value="' . esc_attr($code) . '">' . esc_html($lname) . ' (' . esc_html(strtoupper($code)) . ')</option>';
                                        }
                                    }
                                    ?>
                                </select>
                            </div>

                            <div id="aitrfoel-modal-status" style="font-size:12px;margin-bottom:12px;"></div>

                            <div style="display:flex;justify-content:flex-end;gap:10px;">
                                <button type="button" class="button" id="aitrfoel-modal-cancel-btn">
                                    <?php esc_html_e('Cancel', 'ai-translator-for-elementor-and-polylang'); ?>
                                </button>
                                <button type="button" class="button button-primary" id="aitrfoel-save-term-btn">
                                    <?php esc_html_e('Save Term', 'ai-translator-for-elementor-and-polylang'); ?>
                                </button>
                            </div>
                        </div>
                    </div>

                    <h4 style="margin-top:25px;"><?php esc_html_e('Raw Text Format (Alternative)', 'ai-translator-for-elementor-and-polylang'); ?></h4>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_glossary_terms"><?php esc_html_e('Glossary Textarea', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <textarea name="aitrfoel_glossary_terms"
                                          id="aitrfoel_glossary_terms"
                                          class="large-text code"
                                          rows="6"
                                          placeholder="Dashboard = Tableau de bord (fr), Panel de control (es), Dashboard (de)
Website = Site web (fr), Sitio web (es), Webseite (de)
Contact Us = Contactez-nous (fr), Contáctenos (es)"><?php echo esc_textarea(get_option('aitrfoel_glossary_terms', '')); ?></textarea>
                                <p class="description">
                                    <?php esc_html_e('Optional quick multiline input: Term = Translation (lang_code)', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <div class="aitrfoel-inline-save">
                        <?php submit_button(__('Save Settings', 'ai-translator-for-elementor-and-polylang'), 'primary', 'submit', false); ?>
                    </div>
                </div>
                
                <!-- Exclude Words -->
                <div class="aitrfoel-settings-section" id="section-exclude-words">
                    <h3><?php esc_html_e('Excluded Terms (Quick List)', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    
                    <p class="description aitrfoel-section-intro">
                        <?php esc_html_e('Words or terms listed here will never be translated — they are automatically preserved as-is across all languages alongside your Glossary rules. Use this for brand names, company names, or technical terms.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_exclude_words"><?php esc_html_e('Excluded Terms', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <textarea name="aitrfoel_exclude_words"
                                          id="aitrfoel_exclude_words"
                                          class="large-text code"
                                          rows="8"
                                          placeholder="WordPress
WooCommerce
Elementor
Meep Tech"><?php echo esc_textarea(implode("\n", self::get_exclude_words())); ?></textarea>
                                <p class="description">
                                    <?php esc_html_e('One word or phrase per line. These terms will be preserved as-is during translation.', 'ai-translator-for-elementor-and-polylang'); ?>
                                    <br>
                                    <?php esc_html_e('Tip: You can also quickly add/remove words from the Settings button on the main plugin page.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <div class="aitrfoel-inline-save">
                        <?php submit_button(__('Save Settings', 'ai-translator-for-elementor-and-polylang'), 'primary', 'submit', false); ?>
                    </div>
                </div>
                
                </div><!-- /#tab-glossary -->

                <!-- ========================================================= -->
                <!-- TAB 6: Tools & Advanced                                   -->
                <!-- ========================================================= -->
                <div class="aitrfoel-tab-pane" id="tab-advanced">
                
                <!-- Tools (hub) -->
                <div class="aitrfoel-settings-section" id="section-tools">
                    <h3><?php esc_html_e('Tools & Maintenance', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    <p class="description aitrfoel-section-intro">
                        <?php esc_html_e('Maintenance and one-off actions, gathered in one place. Each card opens its tool below.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                    <div class="aitrfoel-tools-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;margin-top:8px;">
                        <?php
                        $aitrfoel_tools = [
                            [
                                'title' => __('Repair Translation Links', 'ai-translator-for-elementor-and-polylang'),
                                'desc'  => __('Reconcile inconsistent translation links (dashboard list, switcher, cart/checkout swap).', 'ai-translator-for-elementor-and-polylang'),
                                'href'  => '#aitrfoel-repair-links-wrap',
                            ],
                            [
                                'title' => __('Repair Page Content Links', 'ai-translator-for-elementor-and-polylang'),
                                'desc'  => __('Re-localize internal links inside translated page content and Elementor data.', 'ai-translator-for-elementor-and-polylang'),
                                'href'  => '#aitrfoel-content-links-wrap',
                            ],
                            [
                                'title' => __('Fix Elementor Template Shortcodes', 'ai-translator-for-elementor-and-polylang'),
                                'desc'  => __('Rewrite [elementor-template id="X"] shortcodes in translated posts to use the per-language template.', 'ai-translator-for-elementor-and-polylang'),
                                'href'  => '#aitrfoel-fix-shortcodes-wrap',
                            ],
                            [
                                'title' => __('Retranslate SEO Titles', 'ai-translator-for-elementor-and-polylang'),
                                'desc'  => __('Re-translate SEO title, description, OG/Twitter for existing translations.', 'ai-translator-for-elementor-and-polylang'),
                                'href'  => '#aitrfoel-seo-patch-wrap',
                            ],
                            [
                                'title' => __('Retranslate Short Descriptions', 'ai-translator-for-elementor-and-polylang'),
                                'desc'  => __('Re-translate WooCommerce product short descriptions (post excerpt).', 'ai-translator-for-elementor-and-polylang'),
                                'href'  => '#aitrfoel-excerpt-patch-wrap',
                            ],
                            [
                                'title' => __('Switch Default Language', 'ai-translator-for-elementor-and-polylang'),
                                'desc'  => __('Move root URLs and migrate translation groups when changing default language.', 'ai-translator-for-elementor-and-polylang'),
                                'href'  => '#aitrfoel-sdl-wrap',
                            ],
                            [
                                'title' => __('String Translations', 'ai-translator-for-elementor-and-polylang'),
                                'desc'  => __('Override UI strings not covered automatically (gateway titles, privacy text, plugin strings).', 'ai-translator-for-elementor-and-polylang'),
                                'href'  => '#section-strings',
                            ],
                            [
                                'title' => __('Localize Existing Media Library', 'ai-translator-for-elementor-and-polylang'),
                                'desc'  => __('Generate localized Media Library attachment records for all active target languages.', 'ai-translator-for-elementor-and-polylang'),
                                'href'  => '#aitrfoel-media-localizer-wrap',
                            ],
                            [
                                'title' => __('Exclude Paths from Language Routing', 'ai-translator-for-elementor-and-polylang'),
                                'desc'  => __('Stop the router from prefixing custom plugin/API endpoints.', 'ai-translator-for-elementor-and-polylang'),
                                'href'  => '#section-multilingual',
                            ],
                        ];
                        foreach ($aitrfoel_tools as $tool) :
                        ?>
                            <a class="aitrfoel-tool-card" href="<?php echo esc_attr($tool['href']); ?>" style="display:block;padding:14px 16px;border:1px solid #dcdcde;border-radius:8px;background:#fff;text-decoration:none;color:inherit;">
                                <strong style="display:block;margin-bottom:4px;color:#1d2327;"><?php echo esc_html($tool['title']); ?> <span aria-hidden="true">→</span></strong>
                                <span class="description" style="font-size:12px;"><?php echo esc_html($tool['desc']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <hr style="margin:24px 0 16px;">

                    <!-- Tool: Repair Translation Links -->
                    <h4 style="margin:0 0 8px;"><?php esc_html_e('Repair Translation Links', 'ai-translator-for-elementor-and-polylang'); ?></h4>
                    <p class="description" style="margin-bottom:12px;">
                        <?php esc_html_e('Reconciles translation links so the dashboard page list, language switcher, and the cart/checkout page swap all agree. Fixes translated pages that wrongly appear as their own source, links left inconsistent by older versions, and re-attaches translated child pages to their translated parent (so a translated sub-page nests under the translated parent instead of the original). Run "Scan" first to preview, then "Repair Links".', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                    <div id="aitrfoel-repair-links-wrap" style="margin-bottom:24px;">
                        <button type="button" id="aitrfoel-repair-scan" class="button button-secondary">
                            <?php esc_html_e('Scan (preview)', 'ai-translator-for-elementor-and-polylang'); ?>
                        </button>
                        <button type="button" id="aitrfoel-repair-run" class="button button-primary" style="margin-left:6px;">
                            <?php esc_html_e('Repair Links', 'ai-translator-for-elementor-and-polylang'); ?>
                        </button>
                        <div id="aitrfoel-repair-result" style="display:none;margin-top:10px;padding:10px 12px;background:#f6f7f7;border-left:4px solid #72aee6;font-size:13px;"></div>
                    </div>
                    <!-- Tool: Repair Page Content Links -->
                    <h4 style="margin:0 0 8px;"><?php esc_html_e('Repair Page Content Links', 'ai-translator-for-elementor-and-polylang'); ?></h4>
                    <p class="description" style="margin-bottom:12px;">
                        <?php esc_html_e('Loops through all translated posts/pages and re-localizes internal links inside their Elementor data based on the latest translation mappings and slug resolution fallbacks. Use this after shifting domains or nesting paths to fix outdated or broken links in translations without re-translating.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                    <div id="aitrfoel-content-links-wrap" style="margin-bottom:24px;">
                        <button type="button" id="aitrfoel-content-links-scan" class="button button-secondary">
                            <?php esc_html_e('Scan (preview)', 'ai-translator-for-elementor-and-polylang'); ?>
                        </button>
                        <button type="button" id="aitrfoel-content-links-run" class="button button-primary" style="margin-left:6px;">
                            <?php esc_html_e('Repair Page Links', 'ai-translator-for-elementor-and-polylang'); ?>
                        </button>
                        <div id="aitrfoel-content-links-result" style="display:none;margin-top:10px;padding:10px 12px;background:#f6f7f7;border-left:4px solid #72aee6;font-size:13px;"></div>
                    </div>

                    <!-- Tool: Fix Elementor Template Shortcodes -->
                    <h4 style="margin:0 0 8px;"><?php esc_html_e('Fix Elementor Template Shortcodes', 'ai-translator-for-elementor-and-polylang'); ?></h4>
                    <p class="description" style="margin-bottom:12px;">
                        <?php esc_html_e('Rewrites [elementor-template id="X"] shortcodes embedded in translated posts so each id points at the per-language translated template instead of the source-language one. Run "Scan" to preview which posts would change, then "Fix Shortcodes".', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                    <div id="aitrfoel-fix-shortcodes-wrap" style="margin-bottom:16px;">
                        <button type="button" id="aitrfoel-shortcodes-scan" class="button button-secondary">
                            <?php esc_html_e('Scan (preview)', 'ai-translator-for-elementor-and-polylang'); ?>
                        </button>
                        <button type="button" id="aitrfoel-shortcodes-run" class="button button-primary" style="margin-left:6px;">
                            <?php esc_html_e('Fix Shortcodes', 'ai-translator-for-elementor-and-polylang'); ?>
                        </button>
                        <div id="aitrfoel-shortcodes-result" style="display:none;margin-top:10px;padding:10px 12px;background:#f6f7f7;border-left:4px solid #72aee6;font-size:13px;"></div>
                    </div>

                    <!-- Tool: Localize Existing Media Library -->
                    <h4 style="margin:0 0 8px;"><?php esc_html_e('Localize Existing Media Library', 'ai-translator-for-elementor-and-polylang'); ?></h4>
                    <p class="description" style="margin-bottom:12px;">
                        <?php esc_html_e('Scans existing media in your WordPress Media Library and generates language-specific attachment database records for all active target languages (with translated Titles, Captions, and Alt text). Useful when enabling Media Library Duplication for an existing site.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                    <div id="aitrfoel-media-localizer-wrap" style="margin-bottom:16px;">
                        <button type="button" id="aitrfoel-media-scan" class="button button-secondary">
                            <?php esc_html_e('Scan (preview)', 'ai-translator-for-elementor-and-polylang'); ?>
                        </button>
                        <button type="button" id="aitrfoel-media-run" class="button button-primary" style="margin-left:6px;">
                            <?php esc_html_e('Localize Media Library', 'ai-translator-for-elementor-and-polylang'); ?>
                        </button>
                        <div id="aitrfoel-media-result" style="display:none;margin-top:10px;padding:10px 12px;background:#f6f7f7;border-left:4px solid #72aee6;font-size:13px;"></div>
                    </div>

                    <script>
                    (function($){
                        var rNonce = '<?php echo esc_js(wp_create_nonce('aitrfoel_ajax_nonce')); ?>';

                        /* ---- Repair Translation Links ---- */
                        var rRunning = false;
                        function runRepair(scan){
                            if (rRunning) return;
                            rRunning = true;
                            var $scan = $('#aitrfoel-repair-scan'), $run = $('#aitrfoel-repair-run');
                            $scan.prop('disabled', true); $run.prop('disabled', true);
                            var $res = $('#aitrfoel-repair-result').show()
                                .text('<?php echo esc_js(__('Working…', 'ai-translator-for-elementor-and-polylang')); ?>');
                            $.post(ajaxurl, { action: 'aitrfoel_repair_links', nonce: rNonce, scan: scan ? 1 : 0 })
                            .done(function(r){
                                if (!r.success) { $res.text('<?php echo esc_js(__('Error:', 'ai-translator-for-elementor-and-polylang')); ?> ' + (r.data || '?')); }
                                else {
                                    var d = r.data;
                                    var verb = d.applied ? '<?php echo esc_js(__('Repaired', 'ai-translator-for-elementor-and-polylang')); ?>' : '<?php echo esc_js(__('Would repair', 'ai-translator-for-elementor-and-polylang')); ?>';
                                    if (d.components_repaired === 0 && !d.parents_remapped && !d.group_langs_fixed && (!d.orphans || !d.orphans.length) && (!d.conflicts || !d.conflicts.length)) {
                                        $res.css('border-left-color', '#00a32a').html('✅ <?php echo esc_js(__('All translation links are already consistent.', 'ai-translator-for-elementor-and-polylang')); ?>');
                                    } else {
                                        var html = '<strong>' + verb + ':</strong> '
                                            + d.components_repaired + ' <?php echo esc_js(__('groups', 'ai-translator-for-elementor-and-polylang')); ?>, '
                                            + d.group_rows_written + ' <?php echo esc_js(__('missing links', 'ai-translator-for-elementor-and-polylang')); ?>, '
                                            + d.metas_written + ' <?php echo esc_js(__('source refs', 'ai-translator-for-elementor-and-polylang')); ?>, '
                                            + d.language_terms_set + ' <?php echo esc_js(__('language tags', 'ai-translator-for-elementor-and-polylang')); ?>, '
                                            + (d.parents_remapped || 0) + ' <?php echo esc_js(__('child pages re-parented', 'ai-translator-for-elementor-and-polylang')); ?>, '
                                            + (d.group_langs_fixed || 0) + ' <?php echo esc_js(__('language records reconciled', 'ai-translator-for-elementor-and-polylang')); ?>.';
                                        if (d.orphans && d.orphans.length) {
                                            html += '<br><strong><?php echo esc_js(__('Need manual review (no source link found):', 'ai-translator-for-elementor-and-polylang')); ?></strong><br>';
                                            html += d.orphans.map(function(o){ return '#' + o.post_id + ' ' + o.title + ' (' + o.lang + ')'; }).join('<br>');
                                        }
                                        if (d.conflicts && d.conflicts.length) {
                                            html += '<br><strong><?php echo esc_js(__('Conflicts:', 'ai-translator-for-elementor-and-polylang')); ?></strong> ' + d.conflicts.length;
                                        }
                                        if (d.applied) { html += '<br><?php echo esc_js(__('Reload the page list to see the result.', 'ai-translator-for-elementor-and-polylang')); ?>'; }
                                        $res.css('border-left-color', '#72aee6').html(html);
                                    }
                                    if (d.wc_pages && d.wc_pages.length) {
                                        var wc = '<br><strong><?php echo esc_js(__('WooCommerce cart/checkout link status:', 'ai-translator-for-elementor-and-polylang')); ?></strong><br>';
                                        wc += d.wc_pages.map(function(p){
                                            var t = (p.translations && p.translations.length) ? p.translations.join(', ') : '<?php echo esc_js(__('NONE — not linked', 'ai-translator-for-elementor-and-polylang')); ?>';
                                            return p.label + ': #' + p.page_id + ' "' + p.title + '" (' + p.lang + ') → ' + t;
                                        }).join('<br>');
                                        $res.append(wc);
                                    }
                                }
                                $scan.prop('disabled', false); $run.prop('disabled', false); rRunning = false;
                            })
                            .fail(function(){
                                $res.text('<?php echo esc_js(__('Request failed — try again.', 'ai-translator-for-elementor-and-polylang')); ?>');
                                $scan.prop('disabled', false); $run.prop('disabled', false); rRunning = false;
                            });
                        }
                        $('#aitrfoel-repair-scan').on('click', function(){ runRepair(true); });
                        $('#aitrfoel-repair-run').on('click', function(){
                            if (confirm('<?php echo esc_js(__('Repair translation links now? This updates link data and is safe to run multiple times.', 'ai-translator-for-elementor-and-polylang')); ?>')) runRepair(false);
                        });

                        /* ---- Repair Page Content Links ---- */
                        var clRunning = false;
                        function runContentLinks(scan){
                            if (clRunning) return;
                            clRunning = true;
                            var $scan = $('#aitrfoel-content-links-scan'), $run = $('#aitrfoel-content-links-run');
                            $scan.prop('disabled', true); $run.prop('disabled', true);
                            var $res = $('#aitrfoel-content-links-result').show()
                                .text('<?php echo esc_js(__('Working…', 'ai-translator-for-elementor-and-polylang')); ?>');
                            $.post(ajaxurl, { action: 'aitrfoel_repair_content_links', nonce: rNonce, scan: scan ? 1 : 0 })
                            .done(function(r){
                                if (!r.success) { $res.text('<?php echo esc_js(__('Error:', 'ai-translator-for-elementor-and-polylang')); ?> ' + (r.data || '?')); }
                                else {
                                    var d = r.data;
                                    var verb = d.applied ? '<?php echo esc_js(__('Repaired', 'ai-translator-for-elementor-and-polylang')); ?>' : '<?php echo esc_js(__('Would repair', 'ai-translator-for-elementor-and-polylang')); ?>';
                                    if (d.pages_repaired === 0) {
                                        $res.css('border-left-color', '#00a32a').html('✅ <?php echo esc_js(__('All page links are already localized and up-to-date.', 'ai-translator-for-elementor-and-polylang')); ?>');
                                    } else {
                                        var html = '<strong>' + verb + ':</strong> ' + d.pages_repaired + ' <?php echo esc_js(__('translated page(s) out of', 'ai-translator-for-elementor-and-polylang')); ?> ' + d.pages_scanned + ' <?php echo esc_js(__('scanned.', 'ai-translator-for-elementor-and-polylang')); ?>';
                                        if (d.repaired_pages_info && d.repaired_pages_info.length) {
                                            html += '<br>' + d.repaired_pages_info.map(function(o){ return '#' + o.post_id + ' ' + o.title + ' (' + o.lang + ')'; }).join('<br>');
                                        }
                                        if (d.applied) { html += '<br><?php echo esc_js(__('Affected page content and Elementor CSS caches have been updated.', 'ai-translator-for-elementor-and-polylang')); ?>'; }
                                        $res.css('border-left-color', '#72aee6').html(html);
                                    }
                                }
                                $scan.prop('disabled', false); $run.prop('disabled', false); clRunning = false;
                            })
                            .fail(function(){
                                $res.text('<?php echo esc_js(__('Request failed — try again.', 'ai-translator-for-elementor-and-polylang')); ?>');
                                $scan.prop('disabled', false); $run.prop('disabled', false); clRunning = false;
                            });
                        }
                        $('#aitrfoel-content-links-scan').on('click', function(){ runContentLinks(true); });
                        $('#aitrfoel-content-links-run').on('click', function(){
                            if (confirm('<?php echo esc_js(__('Repair links inside translated page content now? This will inspect all translated pages and re-localize their internal links. This is safe to run multiple times.', 'ai-translator-for-elementor-and-polylang')); ?>')) runContentLinks(false);
                        });

                        /* ---- Fix Elementor Template Shortcodes ---- */
                        var sRunning = false;
                        function runShortcodes(scan){
                            if (sRunning) return;
                            sRunning = true;
                            var $scan = $('#aitrfoel-shortcodes-scan'), $run = $('#aitrfoel-shortcodes-run');
                            $scan.prop('disabled', true); $run.prop('disabled', true);
                            var $res = $('#aitrfoel-shortcodes-result').show()
                                .text('<?php echo esc_js(__('Working…', 'ai-translator-for-elementor-and-polylang')); ?>');
                            $.post(ajaxurl, { action: 'aitrfoel_repair_template_shortcodes', nonce: rNonce, scan: scan ? 1 : 0 })
                            .done(function(r){
                                if (!r.success) { $res.text('<?php echo esc_js(__('Error:', 'ai-translator-for-elementor-and-polylang')); ?> ' + (r.data || '?')); }
                                else {
                                    var d = r.data;
                                    var verb = d.applied ? '<?php echo esc_js(__('Fixed', 'ai-translator-for-elementor-and-polylang')); ?>' : '<?php echo esc_js(__('Would fix', 'ai-translator-for-elementor-and-polylang')); ?>';
                                    if (!d.shortcode_template_ids_fixed) {
                                        $res.css('border-left-color', '#00a32a').html('✅ <?php echo esc_js(__('No translated posts have source-language template shortcodes.', 'ai-translator-for-elementor-and-polylang')); ?>');
                                    } else {
                                        var html = '<strong>' + verb + ':</strong> ' + d.shortcode_template_ids_fixed + ' <?php echo esc_js(__('translated post(s) with [elementor-template id="X"] shortcodes updated to the per-language template.', 'ai-translator-for-elementor-and-polylang')); ?>';
                                        if (d.shortcode_template_post_ids && d.shortcode_template_post_ids.length) {
                                            html += '<br>' + d.shortcode_template_post_ids.map(function(o){ return '#' + o.post_id + ' ' + o.title + ' (' + o.lang + ')'; }).join('<br>');
                                        }
                                        if (d.applied) { html += '<br><?php echo esc_js(__('Reload the affected pages to see the result.', 'ai-translator-for-elementor-and-polylang')); ?>'; }
                                        $res.css('border-left-color', '#72aee6').html(html);
                                    }
                                }
                                $scan.prop('disabled', false); $run.prop('disabled', false); sRunning = false;
                            })
                            .fail(function(){
                                $res.text('<?php echo esc_js(__('Request failed — try again.', 'ai-translator-for-elementor-and-polylang')); ?>');
                                $scan.prop('disabled', false); $run.prop('disabled', false); sRunning = false;
                            });
                        }
                        $('#aitrfoel-shortcodes-scan').on('click', function(){ runShortcodes(true); });
                        $('#aitrfoel-shortcodes-run').on('click', function(){
                            if (confirm('<?php echo esc_js(__('Rewrite Elementor template shortcodes now? This updates post content and is safe to run multiple times.', 'ai-translator-for-elementor-and-polylang')); ?>')) runShortcodes(false);
                        });

                        /* ---- Localize Existing Media Library ---- */
                        var mRunning = false;
                        function runMediaLocalizer(scan){
                            if (mRunning) return;
                            mRunning = true;
                            var $scan = $('#aitrfoel-media-scan'), $run = $('#aitrfoel-media-run');
                            $scan.prop('disabled', true); $run.prop('disabled', true);
                            var $res = $('#aitrfoel-media-result').show()
                                .text('<?php echo esc_js(__('Working…', 'ai-translator-for-elementor-and-polylang')); ?>');
                            $.post(ajaxurl, { action: 'aitrfoel_localize_media_library', nonce: rNonce, scan: scan ? 1 : 0 })
                            .done(function(r){
                                if (!r.success) { $res.text('<?php echo esc_js(__('Error:', 'ai-translator-for-elementor-and-polylang')); ?> ' + (r.data || '?')); }
                                else {
                                    var d = r.data;
                                    if (d.scan) {
                                        if (d.total_to_create === 0) {
                                            $res.css('border-left-color', '#00a32a').html('✅ <?php echo esc_js(__('All media attachments are already localized for all active languages.', 'ai-translator-for-elementor-and-polylang')); ?>');
                                        } else {
                                            $res.css('border-left-color', '#72aee6').html('<strong><?php echo esc_js(__('Found:', 'ai-translator-for-elementor-and-polylang')); ?></strong> ' + d.total_to_create + ' <?php echo esc_js(__('localized attachment record(s) to create across', 'ai-translator-for-elementor-and-polylang')); ?> ' + d.total_sources + ' <?php echo esc_js(__('source media files. Click "Localize Media Library" to proceed.', 'ai-translator-for-elementor-and-polylang')); ?>');
                                        }
                                    } else {
                                        $res.css('border-left-color', '#00a32a').html('✅ <strong><?php echo esc_js(__('Complete:', 'ai-translator-for-elementor-and-polylang')); ?></strong> ' + d.created + ' <?php echo esc_js(__('localized attachment records created in Media Library.', 'ai-translator-for-elementor-and-polylang')); ?>');
                                    }
                                }
                                $scan.prop('disabled', false); $run.prop('disabled', false); mRunning = false;
                            })
                            .fail(function(){
                                $res.text('<?php echo esc_js(__('Request failed — try again.', 'ai-translator-for-elementor-and-polylang')); ?>');
                                $scan.prop('disabled', false); $run.prop('disabled', false); mRunning = false;
                            });
                        }
                        $('#aitrfoel-media-scan').on('click', function(){ runMediaLocalizer(true); });
                        $('#aitrfoel-media-run').on('click', function(){
                            if (confirm('<?php echo esc_js(__('Generate localized attachment records in Media Library now? Uses existing upload files without duplicating disk space.', 'ai-translator-for-elementor-and-polylang')); ?>')) runMediaLocalizer(false);
                        });
                    }(jQuery));
                    </script>
                </div>
                
                <!-- Theme Compatibility Settings -->
                <div class="aitrfoel-settings-section" id="section-theme">
                    <h3><?php esc_html_e('Theme Compatibility (optional)', 'ai-translator-for-elementor-and-polylang'); ?></h3>

                    <p class="description aitrfoel-section-intro">
                        <?php esc_html_e('Most sites need nothing here — page content, Elementor/Gutenberg data, and WooCommerce fields are translated automatically. This section is only for themes that store translatable text in custom meta fields. If the status below says "Standard", you can ignore it.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <?php esc_html_e('Compatibility Status', 'ai-translator-for-elementor-and-polylang'); ?>
                            </th>
                            <td>
                                <div id="theme-compatibility-status-wrapper">
                                    <!-- Status will be loaded here by JavaScript -->
                                    <p><i><?php esc_html_e('Loading status...', 'ai-translator-for-elementor-and-polylang'); ?></i></p>
                                </div>
                                <button type="button" class="button" id="aitrfoel-diagnose-theme-btn">
                                    <?php esc_html_e('Re-check Compatibility', 'ai-translator-for-elementor-and-polylang'); ?>
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_custom_meta_fields"><?php esc_html_e('Additional Meta Fields', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <textarea name="aitrfoel_custom_meta_fields"
                                          id="aitrfoel_custom_meta_fields"
                                          class="large-text"
                                          rows="5"
                                          placeholder="theme_field_1, theme_field_2, theme_field_3"><?php 
                                    echo esc_textarea(get_option('aitrfoel_custom_meta_fields', '')); 
                                ?></textarea>
                                <p class="description">
                                    <?php esc_html_e('Advanced setting. Add comma-separated meta field keys here if the auto-detection misses something for your theme.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Advanced / Debug Settings -->
                <div class="aitrfoel-settings-section" id="section-advanced">
                    <h3><?php esc_html_e('Advanced Settings', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_preserve_post_order"><?php esc_html_e('Preserve Post Order', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <input type="checkbox" 
                                       id="aitrfoel_preserve_post_order" 
                                       name="aitrfoel_preserve_post_order" 
                                       value="1" 
                                       <?php checked(get_option('aitrfoel_preserve_post_order', true)); ?> />
                                <label for="aitrfoel_preserve_post_order"><?php esc_html_e('Mirror default-language sorting order on translation pages', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                <p class="description">
                                    <?php esc_html_e('When enabled, translated posts are automatically sorted to match the order of their original post counterparts. This prevents page builder listings (such as JetEngine grids) from displaying items in a scrambled order due to different translation creation dates.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_copy_custom_media_fields"><?php esc_html_e('Synchronize Media & Galleries', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <input type="checkbox" 
                                       id="aitrfoel_copy_custom_media_fields" 
                                       name="aitrfoel_copy_custom_media_fields" 
                                       value="1" 
                                       <?php checked(get_option('aitrfoel_copy_custom_media_fields', true)); ?> />
                                <label for="aitrfoel_copy_custom_media_fields"><?php esc_html_e('Automatically copy gallery images, media attachments, and custom case fields', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                <p class="description">
                                    <?php esc_html_e('When enabled, custom gallery fields, ACF media lists, JetEngine galleries, and theme attachment fields are automatically copied from the source post to translated posts so Elementor image galleries and dynamic sections work out of the box.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_duplicate_media_attachments"><?php esc_html_e('Media Library Duplication', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <input type="checkbox" 
                                       id="aitrfoel_duplicate_media_attachments" 
                                       name="aitrfoel_duplicate_media_attachments" 
                                       value="1" 
                                       <?php checked(get_option('aitrfoel_duplicate_media_attachments', false)); ?> />
                                <label for="aitrfoel_duplicate_media_attachments"><?php esc_html_e('Create translated media attachments in WordPress Media Library per language', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                <p class="description">
                                    <?php esc_html_e('Optional. When enabled, translating posts creates language-specific attachment records in the Media Library with translated titles, captions, and alt texts, allowing independent image management per language. Uses the same physical upload files on disk.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_debug_mode"><?php esc_html_e('Debug Mode', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <input type="checkbox" 
                                       id="aitrfoel_debug_mode" 
                                       name="aitrfoel_debug_mode" 
                                       value="1" 
                                       <?php checked(get_option('aitrfoel_debug_mode', false)); ?> />
                                <label for="aitrfoel_debug_mode"><?php esc_html_e('Enable debug logging', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                <p class="description">
                                    <?php esc_html_e('When enabled, detailed debug information will be written to the WordPress debug.log file.', 'ai-translator-for-elementor-and-polylang'); ?>
                                    <br>
                                    <strong><?php esc_html_e('Note:', 'ai-translator-for-elementor-and-polylang'); ?></strong> 
                                    <?php esc_html_e('WP_DEBUG and WP_DEBUG_LOG must be enabled in wp-config.php for logging to work.', 'ai-translator-for-elementor-and-polylang'); ?>
                                    <br>
                                    <code>define('WP_DEBUG', true);<br>define('WP_DEBUG_LOG', true);</code>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Inspect Elementor Data', 'ai-translator-for-elementor-and-polylang'); ?></th>
                            <td>
                                <div style="display:inline-flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                    <input type="number" id="aitrfoel-inspect-id" placeholder="<?php esc_attr_e('Post/Template ID', 'ai-translator-for-elementor-and-polylang'); ?>" style="width:240px; height:34px; box-sizing:border-box; margin:0;" />
                                    <button type="button" class="button" id="aitrfoel-inspect-btn" style="height:34px; display:inline-flex; align-items:center; justify-content:center; padding:0 16px; margin:0; line-height:1;"><?php esc_html_e('Inspect', 'ai-translator-for-elementor-and-polylang'); ?></button>
                                </div>
                                <p class="description" style="margin-top:8px;"><?php esc_html_e('Read-only diagnostic: prints the Elementor structure (widget types + setting shapes) for a page/template. Useful for support — paste the result back to us.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                                <textarea id="aitrfoel-inspect-out" class="large-text code" rows="10" readonly style="display:none;margin-top:8px;"></textarea>
                                <script>
                                (function($){
                                    $('#aitrfoel-inspect-btn').on('click', function(){
                                        var id = parseInt($('#aitrfoel-inspect-id').val(), 10);
                                        if (!id) { return; }
                                        var $out = $('#aitrfoel-inspect-out').show().val('<?php echo esc_js(__('Loading…', 'ai-translator-for-elementor-and-polylang')); ?>');
                                        $.post(ajaxurl, { action: 'aitrfoel_inspect_elementor', nonce: '<?php echo esc_js(wp_create_nonce('aitrfoel_ajax_nonce')); ?>', post_id: id })
                                        .done(function(r){
                                            if (r && r.success) {
                                                $out.val('post #' + r.data.post_id + '  (elementor v' + (r.data.elementor_version || '?') + ')\n\n' + r.data.report);
                                            } else {
                                                $out.val('<?php echo esc_js(__('Error:', 'ai-translator-for-elementor-and-polylang')); ?> ' + (r.data || '?'));
                                            }
                                        }).fail(function(){ $out.val('<?php echo esc_js(__('Request failed.', 'ai-translator-for-elementor-and-polylang')); ?>'); });
                                    });
                                }(jQuery));
                                </script>
                            </td>
                        </tr>
                    </table>
                    <div class="aitrfoel-inline-save">
                        <?php submit_button(__('Save Settings', 'ai-translator-for-elementor-and-polylang'), 'primary', 'submit', false); ?>
                    </div>
                </div>
                
                <!-- Privacy & Data Collection Notice -->
                <div class="aitrfoel-settings-section aitrfoel-privacy-section" id="section-privacy">
                    <h3 class="aitrfoel-sub-heading"><?php esc_html_e('Privacy & Data Collection', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    
                    <p><?php esc_html_e('This plugin connects to our translation API at', 'ai-translator-for-elementor-and-polylang'); ?> <code>meeptech.com</code> <?php esc_html_e('to:', 'ai-translator-for-elementor-and-polylang'); ?></p>
                    
                    <ul class="aitrfoel-check-list" style="list-style:none;padding-left:0;margin:12px 0;">
                        <li style="display:flex;align-items:center;gap:8px;margin-bottom:6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg><span><?php esc_html_e('Process AI-powered translations', 'ai-translator-for-elementor-and-polylang'); ?></span></li>
                        <li style="display:flex;align-items:center;gap:8px;margin-bottom:6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg><span><?php esc_html_e('Track word usage for plan limits and licensing', 'ai-translator-for-elementor-and-polylang'); ?></span></li>
                        <li style="display:flex;align-items:center;gap:8px;margin-bottom:6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg><span><?php esc_html_e('Enforce monthly word limits based on your plan', 'ai-translator-for-elementor-and-polylang'); ?></span></li>
                    </ul>
                    
                    <div class="aitrfoel-privacy-data-box">
                        <p class="aitrfoel-privacy-data-heading"><strong><?php esc_html_e('Data sent to our server:', 'ai-translator-for-elementor-and-polylang'); ?></strong></p>
                        <ul class="aitrfoel-disc-list aitrfoel-disc-list-mb">
                            <li><?php esc_html_e('Your website URL (for service authentication)', 'ai-translator-for-elementor-and-polylang'); ?></li>
                            <li><?php esc_html_e('Word counts and language pairs (for usage tracking)', 'ai-translator-for-elementor-and-polylang'); ?></li>
                            <li><?php esc_html_e('License key (if applicable)', 'ai-translator-for-elementor-and-polylang'); ?></li>
                        </ul>
                        
                        <p class="aitrfoel-privacy-data-heading aitrfoel-privacy-data-heading-mt"><strong><?php esc_html_e('We DO NOT collect:', 'ai-translator-for-elementor-and-polylang'); ?></strong></p>
                        <ul class="aitrfoel-cross-list" style="list-style:none;padding-left:0;margin:8px 0 0;">
                            <li style="display:flex;align-items:center;gap:8px;margin-bottom:6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg><span><?php esc_html_e('Your actual translated content', 'ai-translator-for-elementor-and-polylang'); ?></span></li>
                            <li style="display:flex;align-items:center;gap:8px;margin-bottom:6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg><span><?php esc_html_e('Personal information or email addresses', 'ai-translator-for-elementor-and-polylang'); ?></span></li>
                            <li style="display:flex;align-items:center;gap:8px;margin-bottom:6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg><span><?php esc_html_e('User or visitor data', 'ai-translator-for-elementor-and-polylang'); ?></span></li>
                            <li style="display:flex;align-items:center;gap:8px;margin-bottom:6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg><span><?php esc_html_e('IP addresses (beyond standard server logs)', 'ai-translator-for-elementor-and-polylang'); ?></span></li>
                        </ul>
                    </div>
                    
                    <?php $aitrfoel_analytics_on = (bool) get_option('aitrfoel_analytics_enabled', false); ?>
                    <div class="aitrfoel-privacy-analytics" style="margin:16px 0;padding:14px 16px;border:1px solid #e6e8ee;border-radius:10px;background:#fff;">
                        <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer;">
                            <input type="checkbox" id="aitrfoel-analytics-toggle" <?php checked($aitrfoel_analytics_on); ?> style="margin-top:3px;">
                            <span>
                                <strong><?php esc_html_e('Share anonymous usage data', 'ai-translator-for-elementor-and-polylang'); ?></strong><br>
                                <span class="description"><?php esc_html_e('Optional. Sends anonymous events (plugin, WordPress and PHP versions, translation counts and errors) to PostHog (EU) to help us improve the plugin. No content, emails or visitor data. You can turn this off at any time.', 'ai-translator-for-elementor-and-polylang'); ?></span>
                            </span>
                        </label>
                        <span id="aitrfoel-analytics-toggle-status" class="description" style="display:block;margin-top:6px;"></span>
                    </div>
                    <script>
                    (function () {
                        var cb = document.getElementById('aitrfoel-analytics-toggle');
                        if (!cb || typeof ajaxurl === 'undefined') { return; }
                        var status = document.getElementById('aitrfoel-analytics-toggle-status');
                        cb.addEventListener('change', function (e) {
                            e.stopPropagation(); // saved instantly; don't mark the settings form as unsaved
                            var fd = new FormData();
                            fd.append('action', 'aitrfoel_save_analytics_optin');
                            fd.append('nonce', <?php echo wp_json_encode(wp_create_nonce('aitrfoel_builtin_setup')); ?>);
                            fd.append('optin', cb.checked ? '1' : '0');
                            fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                                .then(function (r) { return r.json(); })
                                .then(function (res) { status.textContent = res && res.success ? <?php echo wp_json_encode(__('Saved.', 'ai-translator-for-elementor-and-polylang')); ?> : <?php echo wp_json_encode(__('Could not save. Please try again.', 'ai-translator-for-elementor-and-polylang')); ?>; })
                                .catch(function () { status.textContent = <?php echo wp_json_encode(__('Could not save. Please try again.', 'ai-translator-for-elementor-and-polylang')); ?>; });
                        });
                    })();
                    </script>

                    <p class="aitrfoel-privacy-links">
                        <a href="https://meeptech.com/privacy-policy" target="_blank" rel="noopener noreferrer" class="button button-secondary">
                            <?php esc_html_e('Privacy Policy', 'ai-translator-for-elementor-and-polylang'); ?>
                        </a>
                        <a href="https://meeptech.com/terms" target="_blank" rel="noopener noreferrer" class="button button-secondary">
                            <?php esc_html_e('Terms of Service', 'ai-translator-for-elementor-and-polylang'); ?>
                        </a>
                    </p>
                    
                    <p class="description aitrfoel-settings-description-top-lg">
                        <strong><?php esc_html_e('Third-party services:', 'ai-translator-for-elementor-and-polylang'); ?></strong><br>
                        <?php esc_html_e('Content you translate is sent to OpenAI for translation processing. Review their', 'ai-translator-for-elementor-and-polylang'); ?> 
                        <a href="https://openai.com/policies/privacy-policy" target="_blank" rel="noopener noreferrer"><?php esc_html_e('privacy policy', 'ai-translator-for-elementor-and-polylang'); ?></a>.
                    </p>
                </div>
                
                <!-- Reset Plugin Data -->
                <div class="aitrfoel-settings-section aitrfoel-reset-section">
                    <h3 class="aitrfoel-reset-heading"><?php esc_html_e('Reset Plugin Data', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    <p class="description aitrfoel-section-intro">
                        <?php esc_html_e('This will reset all built-in language settings, disable the built-in system, and reset plugin options to defaults. Your API key and license will be preserved. Translated pages will NOT be deleted.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                    <button type="button" class="button button-link-delete aitrfoel-reset-data-btn" id="aitrfoel-reset-plugin-data">
                        <?php esc_html_e('Reset Plugin Data', 'ai-translator-for-elementor-and-polylang'); ?>
                    </button>
                    <span id="aitrfoel-reset-status" class="aitrfoel-action-status"></span>

                    <p style="margin-top:16px;">
                        <input type="hidden" name="aitrfoel_delete_data_on_uninstall" value="">
                        <label>
                            <input type="checkbox" name="aitrfoel_delete_data_on_uninstall" value="1" <?php checked((bool) get_option('aitrfoel_delete_data_on_uninstall')); ?>>
                            <?php esc_html_e('Delete all plugin data when the plugin is deleted', 'ai-translator-for-elementor-and-polylang'); ?>
                        </label>
                        <br><span class="description"><?php esc_html_e('Removes settings, translation memory, cache, history and usage tables. Translated pages and posts are always kept.', 'ai-translator-for-elementor-and-polylang'); ?></span>
                    </p>
                </div>
                
                </div><!-- /#tab-advanced -->

                <div class="aitrfoel-sticky-save-bar" id="aitrfoel-sticky-save-bar">
                    <div class="aitrfoel-sticky-save-info">
                        <span class="aitrfoel-sticky-save-dot" aria-hidden="true"></span>
                        <span class="aitrfoel-sticky-save-text" data-default-text="<?php esc_attr_e('All settings across tabs save together.', 'ai-translator-for-elementor-and-polylang'); ?>" data-dirty-text="<?php esc_attr_e('You have unsaved changes.', 'ai-translator-for-elementor-and-polylang'); ?>">
                            <?php esc_html_e('All settings across tabs save together.', 'ai-translator-for-elementor-and-polylang'); ?>
                        </span>
                    </div>
                    <div class="aitrfoel-sticky-save-actions">
                        <?php submit_button(__('Save Settings', 'ai-translator-for-elementor-and-polylang'), 'primary aitrfoel-sticky-save-btn', 'submit', false); ?>
                    </div>
                </div>
            </form>
            
            <script>
            jQuery(document).ready(function($) {
                $('#aitrfoel-reset-plugin-data').on('click', function() {
                    if (!confirm('<?php echo esc_js(__('Are you sure you want to reset all plugin data? This will:\n\n• Disable the built-in language system\n• Remove configured languages\n• Reset plugin options to defaults\n• Clear cache and temporary state\n\nYour API key, translated pages, and license will be preserved.\n\nContinue?', 'ai-translator-for-elementor-and-polylang')); ?>')) {
                        return;
                    }
                    
                    var $btn = $(this);
                    var $status = $('#aitrfoel-reset-status');
                    $btn.prop('disabled', true).text('<?php echo esc_js(__('Resetting...', 'ai-translator-for-elementor-and-polylang')); ?>');
                    $status.hide();
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'aitrfoel_reset_plugin_data',
                            nonce: '<?php echo esc_js(wp_create_nonce('aitrfoel_reset_plugin_data')); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $status.css('color', 'green').text('✓ ' + response.data.message).show();
                                setTimeout(function() { 
                                    window.location.href = '<?php echo esc_url(admin_url('admin.php?page=ai-translator-for-elementor-and-polylang')); ?>';
                                }, 1000);
                            } else {
                                $btn.prop('disabled', false).text('<?php echo esc_js(__('Reset Plugin Data', 'ai-translator-for-elementor-and-polylang')); ?>');
                                $status.css('color', 'red').text('✗ ' + (response.data || 'Error')).show();
                            }
                        },
                        error: function() {
                            $btn.prop('disabled', false).text('<?php echo esc_js(__('Reset Plugin Data', 'ai-translator-for-elementor-and-polylang')); ?>');
                            $status.css('color', 'red').text('✗ <?php echo esc_js(__('Connection error', 'ai-translator-for-elementor-and-polylang')); ?>').show();
                        }
                    });
                });
            });
            </script>
            
            <script>
            jQuery(document).ready(function($) {
                // Reset to defaults handler
                var sectionDefaults = {
                    switcher: {
                        'aitrfoel_switcher_enabled': {type: 'checkbox', val: true},
                        'aitrfoel_switcher_style': {type: 'select', val: 'flags-text'},
                        'aitrfoel_switcher_show_flags': {type: 'checkbox', val: true},
                        'aitrfoel_switcher_show_names': {type: 'checkbox', val: true},
                        'aitrfoel_switcher_name_display': {type: 'select', val: 'native'},
                        'aitrfoel_switcher_custom_css': {type: 'textarea', val: ''},
                        'aitrfoel_switcher_hide_untranslated': {type: 'checkbox', val: false},
                        'aitrfoel_nav_menu_switcher': {type: 'checkbox', val: false},
                        'aitrfoel_nav_menu_location': {type: 'select', val: ''}
                    },
                    multilingual: {
                        'aitrfoel_translate_url_slugs': {type: 'checkbox', val: true},
                        'aitrfoel_preserve_slug_on_retranslate': {type: 'checkbox', val: true},
                        'aitrfoel_localize_internal_links': {type: 'checkbox', val: true},
                        'aitrfoel_default_lang_prefix': {type: 'checkbox', val: false}
                    },
                    cache: {
                        'aitrfoel_cache_enabled': {type: 'checkbox', val: true},
                        'aitrfoel_cache_duration': {type: 'number', val: 30}
                    },
                    'page-creation': {
                        'aitrfoel_translated_page_status': {type: 'select', val: 'draft'}
                    },
                    seo: {
                        'aitrfoel_seo_translate_meta': {type: 'checkbox', val: true},
                        'aitrfoel_seo_translate_og': {type: 'checkbox', val: true},
                        'aitrfoel_seo_translate_twitter': {type: 'checkbox', val: true}
                    },
                    woocommerce: {
                        'aitrfoel_woocommerce_support': {type: 'checkbox', val: true},
                        'aitrfoel_woo_translate_categories': {type: 'checkbox', val: true},
                        'aitrfoel_woo_translate_tags': {type: 'checkbox', val: true},
                        'aitrfoel_woo_translate_attributes': {type: 'checkbox', val: true},
                        'aitrfoel_woo_translate_variations': {type: 'checkbox', val: true},
                        'aitrfoel_woo_translate_gallery_alt': {type: 'checkbox', val: true},
                        'aitrfoel_translate_taxonomy_names': {type: 'checkbox', val: true}
                    },
                    'menus-widgets': {}
                };

                $('.aitrfoel-reset-defaults').on('click', function() {
                    var sections = $(this).data('sections').split(',');
                    var label = $(this).text().trim();
                    if (!confirm('<?php echo esc_js(__('Reset these settings to their defaults?', 'ai-translator-for-elementor-and-polylang')); ?>\n\n' + label)) return;

                    sections.forEach(function(section) {
                        var fields = sectionDefaults[section.trim()];
                        if (!fields) return;
                        $.each(fields, function(name, cfg) {
                            var $el = $('[name="' + name + '"]');
                            if (!$el.length) return;
                            if (cfg.type === 'checkbox') {
                                $el.prop('checked', cfg.val);
                            } else if (cfg.type === 'select') {
                                $el.val(cfg.val);
                            } else if (cfg.type === 'textarea') {
                                $el.val(cfg.val);
                            } else {
                                $el.val(cfg.val);
                            }
                        });
                        // Reset per-language menu assignments when resetting menus-widgets section
                        if (section.trim() === 'menus-widgets') {
                            $('select[name^="aitrfoel_menu_assignments"]').val('0');
                            $('#section-menus-widgets input[type=radio][value=all]').prop('checked', true);
                            $('#section-menus-widgets input[type=checkbox][name^="aitrfoel_widget_languages"]').prop('checked', false);
                        }
                    });

                    // Visual flash feedback
                    var $btn = $(this);
                    var orig = $btn.text();
                    $btn.text('<?php echo esc_js(__('✓ Reset — click Save to apply', 'ai-translator-for-elementor-and-polylang')); ?>');
                    setTimeout(function() { $btn.text(orig); }, 3000);
                });

                // Dynamically update shortcode display when settings change
                function updateShortcodePreview() {
                    var style = $('#aitrfoel_switcher_style').val() || 'flags-text';
                    var showFlags = $('input[name="aitrfoel_switcher_show_flags"]').is(':checked') ? 'true' : 'false';
                    var showNames = $('input[name="aitrfoel_switcher_show_names"]').is(':checked') ? 'true' : 'false';
                    var shortcode = '[aitrfoel_language_switcher style="' + style + '" show_flags="' + showFlags + '" show_names="' + showNames + '"]';
                    $('#aitrfoel-switcher-shortcode').text(shortcode);
                }
                
                $('#aitrfoel_switcher_style').on('change', updateShortcodePreview);
                $('input[name="aitrfoel_switcher_show_flags"], input[name="aitrfoel_switcher_show_names"]').on('change', updateShortcodePreview);

                // Generate Sample CSS button
                $('#aitrfoel-generate-sample-css').on('click', function() {
                    var style = $('#aitrfoel_switcher_style').val() || 'flags-text';
                    var $ta = $('#aitrfoel_switcher_custom_css');
                    var existing = $ta.val().trim();
                    var css = '';

                    var commonCss = [
                        '/* ---- Language Switcher: Base ---- */',
                        '.aitrfoel-language-switcher {',
                        '    display: inline-flex;',
                        '    align-items: center;',
                        '    gap: 4px;',
                        '}',
                        ''
                    ].join('\n');

                    var flagsCss = [
                        '/* Flag size */',
                        '.aitrfoel-language-switcher img,',
                        '.aitrfoel-flag-link img,',
                        '.aitrfoel-flag-text-link img {',
                        '    width: 20px;',
                        '    height: auto;',
                        '    border-radius: 2px;',
                        '}',
                        ''
                    ].join('\n');

                    var listItemCss = [
                        '/* List items */',
                        '.aitrfoel-switcher-list .aitrfoel-switcher-item a,',
                        '.aitrfoel-switcher-flags .aitrfoel-flag-link,',
                        '.aitrfoel-switcher-flags-text .aitrfoel-flag-text-link {',
                        '    display: inline-flex;',
                        '    align-items: center;',
                        '    gap: 6px;',
                        '    padding: 4px 8px;',
                        '    border-radius: 4px;',
                        '    text-decoration: none;',
                        '    color: inherit;',
                        '    transition: background 0.15s;',
                        '}',
                        '.aitrfoel-switcher-list .aitrfoel-switcher-item a:hover,',
                        '.aitrfoel-switcher-flags .aitrfoel-flag-link:hover,',
                        '.aitrfoel-switcher-flags-text .aitrfoel-flag-text-link:hover {',
                        '    background: rgba(0,0,0,0.06);',
                        '}',
                        '',
                        '/* Active language */',
                        '.aitrfoel-switcher-item.active a {',
                        '    font-weight: 600;',
                        '    background: rgba(0,0,0,0.08);',
                        '}',
                        ''
                    ].join('\n');

                    var dropdownCss = [
                        '/* Dropdown toggle button */',
                        '.aitrfoel-switcher-dropdown .aitrfoel-dropdown-toggle {',
                        '    display: inline-flex;',
                        '    align-items: center;',
                        '    gap: 6px;',
                        '    padding: 6px 12px;',
                        '    border: 1px solid #ddd;',
                        '    border-radius: 4px;',
                        '    background: #fff;',
                        '    cursor: pointer;',
                        '    font-size: 14px;',
                        '}',
                        '/* Dropdown menu panel */',
                        '.aitrfoel-switcher-dropdown .aitrfoel-dropdown-menu {',
                        '    border: 1px solid #ddd;',
                        '    border-radius: 4px;',
                        '    background: #fff;',
                        '    box-shadow: 0 4px 12px rgba(0,0,0,0.1);',
                        '    min-width: 140px;',
                        '}',
                        '.aitrfoel-switcher-dropdown .aitrfoel-dropdown-menu a {',
                        '    display: flex;',
                        '    align-items: center;',
                        '    gap: 8px;',
                        '    padding: 8px 12px;',
                        '    text-decoration: none;',
                        '    color: inherit;',
                        '}',
                        '.aitrfoel-switcher-dropdown .aitrfoel-dropdown-menu a:hover {',
                        '    background: #f5f5f5;',
                        '}',
                        ''
                    ].join('\n');

                    if (style === 'dropdown') {
                        css = commonCss + flagsCss + dropdownCss;
                    } else if (style === 'flags') {
                        css = commonCss + flagsCss + listItemCss;
                    } else {
                        css = commonCss + flagsCss + listItemCss;
                    }

                    if (existing) {
                        if (!confirm('<?php echo esc_js(__('Replace current CSS with the generated sample? Click Cancel to append instead.', 'ai-translator-for-elementor-and-polylang')); ?>')) {
                            $ta.val(existing + '\n\n' + css.trim());
                        } else {
                            $ta.val(css.trim());
                        }
                    } else {
                        $ta.val(css.trim());
                    }

                    var $btn = $(this);
                    var orig = $btn.text();
                    $btn.text('<?php echo esc_js(__('Generated!', 'ai-translator-for-elementor-and-polylang')); ?>');
                    setTimeout(function() { $btn.text(orig); }, 1800);
                });
                
                // Toggle name display options visibility
                $('input[name="aitrfoel_switcher_show_names"]').on('change', function() {
                    $('#aitrfoel-name-display-options').toggle($(this).is(':checked'));
                });

                // --- Auto-Translate Menus & Widgets ---
                var autoTranslateRunning = false;
                var $atStatus = $('.aitrfoel-auto-translate-status');
                var $atMsg = $('.aitrfoel-auto-translate-msg');
                var $atLog = $('.aitrfoel-auto-translate-log');

                function atLog(msg) {
                    var time = new Date().toLocaleTimeString();
                    $atLog.append('[' + time + '] ' + msg + '\n');
                    $atLog.scrollTop($atLog[0].scrollHeight);
                }

                function autoTranslateLang(lang, name) {
                    return new Promise(function(resolve, reject) {
                        $atMsg.text('<?php echo esc_js(__('Translating menus for', 'ai-translator-for-elementor-and-polylang')); ?> ' + name + '...');
                        atLog('→ <?php echo esc_js(__('Translating menus →', 'ai-translator-for-elementor-and-polylang')); ?> ' + name);

                        $.post(ajaxurl, {
                            action: 'aitrfoel_auto_translate_menus',
                            nonce: '<?php echo esc_js(wp_create_nonce('aitrfoel_ajax_nonce')); ?>',
                            target_lang: lang
                        }).done(function(r) {
                            if (r.success) {
                                var menus = r.data.menus || [];
                                menus.forEach(function(m) {
                                    atLog('  ✓ ' + m.location + ': ' + m.menu_name + (m.skipped ? ' (<?php echo esc_js(__('already assigned', 'ai-translator-for-elementor-and-polylang')); ?>)' : ' (' + (m.items || 0) + ' <?php echo esc_js(__('items', 'ai-translator-for-elementor-and-polylang')); ?>)'));
                                });
                            } else {
                                atLog('  ⚠ <?php echo esc_js(__('Menus', 'ai-translator-for-elementor-and-polylang')); ?>: ' + (r.data || '<?php echo esc_js(__('Error', 'ai-translator-for-elementor-and-polylang')); ?>'));
                            }
                        }).fail(function() {
                            atLog('  ✗ <?php echo esc_js(__('Menu translation request failed', 'ai-translator-for-elementor-and-polylang')); ?>');
                        }).then(function() {
                            $atMsg.text('<?php echo esc_js(__('Translating widgets for', 'ai-translator-for-elementor-and-polylang')); ?> ' + name + '...');
                            atLog('→ <?php echo esc_js(__('Translating widgets →', 'ai-translator-for-elementor-and-polylang')); ?> ' + name);

                            return $.post(ajaxurl, {
                                action: 'aitrfoel_auto_translate_widgets',
                                nonce: '<?php echo esc_js(wp_create_nonce('aitrfoel_ajax_nonce')); ?>',
                                target_lang: lang
                            });
                        }).done(function(r) {
                            if (r.success) {
                                atLog('  ✓ ' + r.data.message);
                            } else {
                                atLog('  ⚠ <?php echo esc_js(__('Widgets', 'ai-translator-for-elementor-and-polylang')); ?>: ' + (r.data || '<?php echo esc_js(__('Error', 'ai-translator-for-elementor-and-polylang')); ?>'));
                            }
                            resolve();
                        }).fail(function() {
                            atLog('  ✗ <?php echo esc_js(__('Widget translation request failed', 'ai-translator-for-elementor-and-polylang')); ?>');
                            resolve();
                        });
                    });
                }

                function startAutoTranslate(langs) {
                    if (autoTranslateRunning) return;
                    autoTranslateRunning = true;
                    $atStatus.show();
                    $atLog.empty();
                    $('.aitrfoel-auto-translate-lang, .aitrfoel-auto-translate-all').prop('disabled', true);

                    var chain = Promise.resolve();
                    langs.forEach(function(l) {
                        chain = chain.then(function() {
                            return autoTranslateLang(l.code, l.name);
                        });
                    });

                    chain.then(function() {
                        $atMsg.text('<?php echo esc_js(__('Done! Reload the page to see updated assignments.', 'ai-translator-for-elementor-and-polylang')); ?>');
                        $atStatus.find('.spinner').css('visibility', 'hidden');
                        atLog('✓ <?php echo esc_js(__('All translations complete.', 'ai-translator-for-elementor-and-polylang')); ?>');
                        autoTranslateRunning = false;
                        $('.aitrfoel-auto-translate-lang, .aitrfoel-auto-translate-all').prop('disabled', false);
                        // Auto-reload after 2s so the assignment dropdowns refresh
                        setTimeout(function() { location.reload(); }, 2000);
                    });
                }

                $(document).on('click', '.aitrfoel-auto-translate-lang', function() {
                    var lang = $(this).data('lang');
                    var name = $(this).data('name');
                    if (!confirm('<?php echo esc_js(__('This will translate and assign menus + widget content for', 'ai-translator-for-elementor-and-polylang')); ?> ' + name + '. <?php echo esc_js(__('Uses translation credits. Continue?', 'ai-translator-for-elementor-and-polylang')); ?>')) return;
                    startAutoTranslate([{code: lang, name: name}]);
                });

                $(document).on('click', '.aitrfoel-auto-translate-all', function() {
                    var langs = [];
                    $('.aitrfoel-auto-translate-lang').each(function() {
                        langs.push({code: $(this).data('lang'), name: $(this).data('name')});
                    });
                    if (!langs.length) return;
                    if (!confirm('<?php echo esc_js(__('This will translate and assign menus + widget content for ALL languages. Uses translation credits. Continue?', 'ai-translator-for-elementor-and-polylang')); ?>')) return;
                    startAutoTranslate(langs);
                });

                // Toggle advanced menu/widget assignment section
                $('#aitrfoel-toggle-advanced-mw').on('click', function(e) {
                    e.preventDefault();
                    var $panel = $('#aitrfoel-advanced-mw');
                    var $arrow = $('#aitrfoel-advanced-mw-arrow');
                    $panel.slideToggle(200);
                    if ($panel.is(':visible')) {
                        $arrow.css('transform', 'rotate(180deg)');
                    } else {
                        $arrow.css('transform', 'rotate(0deg)');
                    }
                });

                // --- Translate Elementor Templates ---
                var tplRunning = false;
                var tplMode = 'active';
                var $tplStatus = $('.aitrfoel-tpl-translate-status');
                var $tplMsg = $('.aitrfoel-tpl-translate-msg');
                var $tplLog = $('.aitrfoel-tpl-translate-log');

                // Toggle advanced templates section
                $('#aitrfoel-toggle-other-tpl').on('click', function(e) {
                    e.preventDefault();
                    var $panel = $('#aitrfoel-other-tpl');
                    var $arrow = $('#aitrfoel-other-tpl-arrow');
                    $panel.slideToggle(200);
                    if ($panel.is(':visible')) {
                        $arrow.css('transform', 'rotate(180deg)');
                    } else {
                        $arrow.css('transform', 'rotate(0deg)');
                    }
                });

                function tplLog(msg) {
                    var time = new Date().toLocaleTimeString();
                    $tplLog.append('<div>[' + time + '] ' + msg + '</div>');
                    $tplLog.scrollTop($tplLog[0].scrollHeight);
                }

                // Pull a meaningful error out of a failed AJAX response (our shutdown
                // handler writes the fatal into the body; raw PHP fatals appear there too).
                function aitrfoelExtractError(xhr) {
                    if (!xhr || !xhr.responseText) {
                        return xhr && xhr.status ? ('HTTP ' + xhr.status) : '';
                    }
                    try {
                        var j = JSON.parse(xhr.responseText);
                        if (j && j.data) { return (typeof j.data === 'string') ? j.data : JSON.stringify(j.data); }
                    } catch (e) {}
                    var m = xhr.responseText.match(/(Fatal error|Allowed memory size|Maximum execution time|Uncaught[^<\n]*)[^<\n]*/i);
                    if (m) { return m[0].trim(); }
                    var txt = xhr.responseText.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
                    return txt ? txt.slice(0, 300) : (xhr.status ? 'HTTP ' + xhr.status : '');
                }

                function translateTemplatesLang(lang, name, mode, force) {
                    return new Promise(function(resolve) {
                        $tplMsg.text('<?php echo esc_js(__('Translating templates for', 'ai-translator-for-elementor-and-polylang')); ?> ' + name + '...');
                        tplLog('→ <?php echo esc_js(__('Translating templates →', 'ai-translator-for-elementor-and-polylang')); ?> ' + name + (force ? ' (<?php echo esc_js(__('force re-translate', 'ai-translator-for-elementor-and-polylang')); ?>)' : ''));

                        var baseData = {
                            action: 'aitrfoel_auto_translate_templates',
                            nonce: '<?php echo esc_js(wp_create_nonce('aitrfoel_ajax_nonce')); ?>',
                            target_lang: lang,
                            mode: mode || 'active'
                        };
                        if (force) baseData.force = 1;

                        // Step 1: fetch the list of templates to translate (no actual translation yet)
                        $.post(ajaxurl, $.extend({}, baseData, { list_only: 1 })).done(function(r) {
                            if (!r.success) {
                                tplLog('  ⚠ ' + (r.data || '<?php echo esc_js(__('Error', 'ai-translator-for-elementor-and-polylang')); ?>'));
                                return resolve();
                            }

                            var queue = r.data.queue || [];
                            if (!queue.length) {
                                tplLog('  ' + r.data.message);
                                return resolve();
                            }

                            tplLog('  ' + r.data.message);

                            // Step 2: translate one template per request to avoid PHP timeout
                            var i = 0;
                            function translateNext() {
                                if (i >= queue.length) {
                                    return resolve();
                                }
                                var tpl = queue[i++];
                                $tplMsg.text('<?php echo esc_js(__('Translating', 'ai-translator-for-elementor-and-polylang')); ?> ' + name + ': ' + tpl.label + '...');
                                $.post(ajaxurl, $.extend({}, baseData, { template_id: tpl.id })).done(function(tr) {
                                    if (tr.success) {
                                        tplLog('    ' + tr.data.message);
                                    } else {
                                        tplLog('    ⚠ ' + (tr.data || '<?php echo esc_js(__('Error', 'ai-translator-for-elementor-and-polylang')); ?>'));
                                    }
                                    translateNext();
                                }).fail(function(xhr) {
                                    tplLog('    ✗ ' + tpl.label + ' — ' + (aitrfoelExtractError(xhr) || '<?php echo esc_js(__('Request failed', 'ai-translator-for-elementor-and-polylang')); ?>'));
                                    translateNext();
                                });
                            }
                            translateNext();

                        }).fail(function() {
                            tplLog('  ✗ <?php echo esc_js(__('Request failed', 'ai-translator-for-elementor-and-polylang')); ?>');
                            resolve();
                        });
                    });
                }

                function startTemplateTranslate(langs, mode) {
                    if (tplRunning) return;
                    tplRunning = true;
                    tplMode = mode || 'active';
                    var force = $('#aitrfoel-tpl-force').is(':checked');
                    $tplStatus.show();
                    $tplLog.empty();
                    $('.aitrfoel-translate-tpl-lang, .aitrfoel-translate-tpl-all').prop('disabled', true);

                    var chain = Promise.resolve();
                    langs.forEach(function(l) {
                        chain = chain.then(function() {
                            return translateTemplatesLang(l.code, l.name, tplMode, force);
                        });
                    });

                    chain.then(function() {
                        $tplMsg.text('<?php echo esc_js(__('Done! Reload to see updated status.', 'ai-translator-for-elementor-and-polylang')); ?>');
                        $tplStatus.find('.spinner').css('visibility', 'hidden');
                        tplLog('✓ <?php echo esc_js(__('All template translations complete.', 'ai-translator-for-elementor-and-polylang')); ?>');
                        tplRunning = false;
                        $('.aitrfoel-translate-tpl-lang, .aitrfoel-translate-tpl-all').prop('disabled', false);
                        setTimeout(function() { location.reload(); }, 3000);
                    });
                }

                $(document).on('click', '.aitrfoel-translate-tpl-lang', function() {
                    var lang = $(this).data('lang');
                    var name = $(this).data('name');
                    var mode = $(this).data('mode') || 'active';
                    var label = mode === 'advanced'
                        ? '<?php echo esc_js(__('advanced', 'ai-translator-for-elementor-and-polylang')); ?>'
                        : (mode === 'all'
                            ? '<?php echo esc_js(__('all', 'ai-translator-for-elementor-and-polylang')); ?>'
                            : '<?php echo esc_js(__('active', 'ai-translator-for-elementor-and-polylang')); ?>');
                    if (!confirm('<?php echo esc_js(__('Translate', 'ai-translator-for-elementor-and-polylang')); ?> ' + label + ' <?php echo esc_js(__('Elementor templates for', 'ai-translator-for-elementor-and-polylang')); ?> ' + name + '? <?php echo esc_js(__('Uses translation credits.', 'ai-translator-for-elementor-and-polylang')); ?>')) return;
                    startTemplateTranslate([{code: lang, name: name}], mode);
                });

                // Copy [elementor-template id="X"] shortcode for the translated template
                $(document).on('click', '.aitrfoel-copy-tpl-shortcode', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var $btn = $(this);
                    var sc   = $btn.attr('data-shortcode') || '';
                    if (!sc) return;
                    var done = function(ok) {
                        var orig = $btn.text();
                        $btn.text(ok ? '✓' : '!').prop('disabled', true);
                        setTimeout(function() { $btn.text(orig).prop('disabled', false); }, 1200);
                    };
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(sc).then(function() { done(true); }, function() { done(false); });
                    } else {
                        var ta = document.createElement('textarea');
                        ta.value = sc;
                        ta.style.position = 'fixed';
                        ta.style.opacity = '0';
                        document.body.appendChild(ta);
                        ta.focus(); ta.select();
                        var ok = false;
                        try { ok = document.execCommand('copy'); } catch (_) { ok = false; }
                        document.body.removeChild(ta);
                        done(ok);
                    }
                });

                // Single-template translate (per-row + / ↺ buttons)
                $(document).on('click', '.aitrfoel-tpl-translate-single', function(e) {
                    e.stopPropagation();
                    var $btn     = $(this);
                    var tplId    = $btn.data('tpl-id');
                    var tplLabel = $btn.data('tpl-label');
                    var lang     = $btn.data('lang');
                    var langName = $btn.data('lang-name');
                    var force    = parseInt($btn.data('force')) === 1;
                    var msg      = force
                        ? '<?php echo esc_js(__('Re-translate', 'ai-translator-for-elementor-and-polylang')); ?> "' + tplLabel + '" \u2192 ' + langName + '?\n<?php echo esc_js(__('The existing translation will be replaced. Uses translation credits.', 'ai-translator-for-elementor-and-polylang')); ?>'
                        : '<?php echo esc_js(__('Translate', 'ai-translator-for-elementor-and-polylang')); ?> "' + tplLabel + '" \u2192 ' + langName + '?\n<?php echo esc_js(__('Uses translation credits.', 'ai-translator-for-elementor-and-polylang')); ?>';
                    if (!confirm(msg)) return;
                    if (tplRunning) {
                        alert('<?php echo esc_js(__('A translation is already in progress. Please wait.', 'ai-translator-for-elementor-and-polylang')); ?>');
                        return;
                    }
                    tplRunning = true;
                    $tplStatus.show();
                    $tplLog.empty();
                    $tplStatus.find('.spinner').css('visibility', 'visible');
                    $tplMsg.text('<?php echo esc_js(__('Translating', 'ai-translator-for-elementor-and-polylang')); ?> ' + tplLabel + ' \u2192 ' + langName + '...');
                    tplLog('\u2192 ' + tplLabel + ' \u2192 ' + langName + (force ? ' (<?php echo esc_js(__('force re-translate', 'ai-translator-for-elementor-and-polylang')); ?>)' : ''));
                    $btn.prop('disabled', true);
                    $.post(ajaxurl, {
                        action:      'aitrfoel_auto_translate_templates',
                        nonce:       '<?php echo esc_js(wp_create_nonce('aitrfoel_ajax_nonce')); ?>',
                        target_lang: lang,
                        template_id: tplId,
                        force:       force ? 1 : 0
                    }).done(function(r) {
                        if (r.success) {
                            tplLog('  ' + r.data.message);
                        } else {
                            tplLog('  \u26a0 ' + (r.data || '<?php echo esc_js(__('Error', 'ai-translator-for-elementor-and-polylang')); ?>'));
                        }
                        $tplMsg.text('<?php echo esc_js(__('Done! Reload to see updated status.', 'ai-translator-for-elementor-and-polylang')); ?>');
                        $tplStatus.find('.spinner').css('visibility', 'hidden');
                        tplRunning = false;
                        setTimeout(function() { location.reload(); }, 2000);
                    }).fail(function(xhr) {
                        var detail = aitrfoelExtractError(xhr);
                        tplLog('  \u2717 ' + tplLabel + ' \u2014 ' + (detail || '<?php echo esc_js(__('Request failed', 'ai-translator-for-elementor-and-polylang')); ?>'));
                        $tplMsg.text(detail || '<?php echo esc_js(__('Request failed.', 'ai-translator-for-elementor-and-polylang')); ?>');
                        $tplStatus.find('.spinner').css('visibility', 'hidden');
                        tplRunning = false;
                        $btn.prop('disabled', false);
                    });
                });

                $(document).on('click', '.aitrfoel-translate-tpl-all', function() {
                    var mode = $(this).data('mode') || 'active';
                    var langs = [];
                    // Gather language buttons from the current mode context.
                    // Advanced/all modes reuse the global language set.
                    var langSelector = (mode === 'active')
                        ? '.aitrfoel-translate-tpl-lang[data-mode="active"]'
                        : '.aitrfoel-translate-tpl-lang';

                    $(langSelector).each(function() {
                        langs.push({code: $(this).data('lang'), name: $(this).data('name')});
                    });
                    if (!langs.length) {
                        // Fallback: gather all language buttons
                        $('.aitrfoel-translate-tpl-lang').each(function() {
                            var c = $(this).data('lang');
                            if (!langs.some(function(l) { return l.code === c; })) {
                                langs.push({code: c, name: $(this).data('name')});
                            }
                        });
                    }
                    if (!langs.length) return;
                    var label = mode === 'advanced'
                        ? '<?php echo esc_js(__('advanced templates only', 'ai-translator-for-elementor-and-polylang')); ?>'
                        : (mode === 'all'
                            ? '<?php echo esc_js(__('ALL templates (including advanced)', 'ai-translator-for-elementor-and-polylang')); ?>'
                            : '<?php echo esc_js(__('active templates', 'ai-translator-for-elementor-and-polylang')); ?>');
                    if (!confirm('<?php echo esc_js(__('Translate', 'ai-translator-for-elementor-and-polylang')); ?> ' + label + ' <?php echo esc_js(__('for ALL languages? Uses translation credits.', 'ai-translator-for-elementor-and-polylang')); ?>')) return;
                    startTemplateTranslate(langs, mode);
                });
            });
            </script>
            
            <!-- MeepTech Footer Branding -->
            <div class="aitrfoel-footer-branding">
                <span class="aitrfoel-footer-text"><?php esc_html_e('Powered by', 'ai-translator-for-elementor-and-polylang'); ?> <a href="https://meeptech.com" target="_blank" rel="noopener noreferrer">Meep Tech</a> &middot; v<?php echo esc_html(AITRFOEL_VERSION); ?></span>
            </div>
        </div>
        
        <?php
    }
    
    /**
     * Sanitize per-language menu assignments
     */
    public static function sanitize_menu_assignments($input) {
        if (empty($input) || !is_array($input)) {
            return [];
        }
        $clean = [];
        foreach ($input as $location => $langs) {
            $location = sanitize_key($location);
            if (empty($location) || !is_array($langs)) {
                continue;
            }
            foreach ($langs as $lang_code => $menu_id) {
                $lang_code = sanitize_key($lang_code);
                $menu_id = intval($menu_id);
                if ($menu_id > 0) {
                    $clean[$location][$lang_code] = $menu_id;
                }
            }
        }
        return $clean;
    }

    /**
     * Sanitize per-language widget visibility assignments
     */
    public static function sanitize_widget_languages($input) {
        if (empty($input) || !is_array($input)) {
            return [];
        }
        $clean = [];
        foreach ($input as $widget_id => $data) {
            $widget_id = sanitize_text_field($widget_id);
            if (empty($widget_id) || !is_array($data)) {
                continue;
            }
            // If "all" mode is selected, store empty array (show for all)
            if (isset($data['_mode']) && $data['_mode'] === 'all') {
                continue;
            }
            // Collect checked language codes
            $langs = [];
            foreach ($data as $key => $val) {
                if ($key === '_mode') continue;
                $key = sanitize_key($key);
                if ($val && !empty($key)) {
                    $langs[] = $key;
                }
            }
            if (!empty($langs)) {
                $clean[$widget_id] = $langs;
            }
        }
        return $clean;
    }

    /**
     * Sanitize glossary terms textarea
     */
    public static function sanitize_glossary_terms($input) {
        if (empty($input)) {
            return '';
        }
        // Keep line structure, sanitize each line
        $lines = explode("\n", $input);
        $clean = [];
        foreach ($lines as $line) {
            $line = sanitize_text_field(trim($line));
            if (!empty($line)) {
                $clean[] = $line;
            }
        }
        return implode("\n", $clean);
    }

    /**
     * Test API connection AJAX handler
     */
    public static function test_api_connection() {
        check_ajax_referer('aitrfoel_test_api', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $provider = sanitize_text_field($_POST['provider'] ?? '');
        $api_key = sanitize_text_field($_POST['api_key'] ?? '');
        
        if (empty($api_key)) {
            wp_send_json_error('No API key provided');
        }
        
        if ($provider !== 'openai') {
            wp_send_json_error('Only OpenAI provider is supported');
        }
        
        try {
            $api = AITRFOEL_OpenAI_API::get_instance();
            
            // Temporarily set the API key for testing
            $original_key = get_option('aitrfoel_openai_api_key', '');
            
            $api->set_api_key($api_key);
            
            // Test connection with a simple translation
            $start_time = microtime(true);
            $result = $api->test_connection();
            $end_time = microtime(true);
            $response_time = round(($end_time - $start_time) * 1000, 2);
            
            // Restore original key
            $api->set_api_key($original_key);
            
            if ($result) {
                wp_send_json_success("OpenAI API connection successful! Response time: {$response_time}ms");
            } else {
                wp_send_json_error("OpenAI API key is invalid or connection failed");
            }
            
        } catch (Exception $e) {
            wp_send_json_error('Error testing API: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX handler for theme compatibility diagnosis.
     */
    public static function diagnose_theme_ajax_handler() {
        check_ajax_referer('aitrfoel_diagnose_theme_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        if (!class_exists('AITRFOEL_Theme_Compatibility')) {
            wp_send_json_error('Compatibility class not found.');
            return;
        }

        $theme_compat = new AITRFOEL_Theme_Compatibility();
        $report = $theme_compat->diagnose_theme_compatibility();

        // Invalidate cache so next page load gets fresh data
        delete_transient('aitrfoel_theme_status_report');

        wp_send_json_success($report);
    }
    

    
    /**
     * Validate word count AJAX handler
     */
    public static function validate_word_count() {
        check_ajax_referer('aitrfoel_validate_word_count', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $word_count = isset($_POST['word_count']) ? intval($_POST['word_count']) : 0;
        $domain = isset($_POST['domain']) ? sanitize_text_field($_POST['domain']) : '';
        
        try {
            $license_manager = AITRFOEL_License_Manager_Server::get_instance();
            
            // Check if translation is allowed
            if (!$license_manager->can_translate($word_count)) {
                $license_status = $license_manager->get_license_status();
                
                wp_send_json_error([
                    'message' => __('Word limit exceeded', 'ai-translator-for-elementor-and-polylang'),
                    'words_used' => $license_status['words_used'],
                    'word_limit' => $license_status['word_limit'],
                    'plan' => $license_status['plan']
                ]);
            }
            
            wp_send_json_success([
                'message' => __('Word count validated', 'ai-translator-for-elementor-and-polylang'),
                'word_count' => $word_count,
                'domain' => $domain
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Save cooldown expiry AJAX handler
     */
    public static function save_cooldown() {
        check_ajax_referer('aitrfoel_save_cooldown', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $expiry_time = isset($_POST['expiry_time']) ? intval($_POST['expiry_time']) : 0;
        
        if ($expiry_time > 0) {
            update_option('aitrfoel_cooldown_expiry', $expiry_time);
            wp_send_json_success(['message' => __('Cooldown saved', 'ai-translator-for-elementor-and-polylang')]);
        } else {
            wp_send_json_error('Invalid expiry time');
        }
    }
    
    /**
     * Clear translation cache AJAX handler
     */
    public static function clear_cache() {
        check_ajax_referer('aitrfoel_clear_cache', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        try {
            global $wpdb;
            $table_cache = $wpdb->prefix . 'aitrfoel_translation_cache';
            $table_tm = $wpdb->prefix . 'aitrfoel_translation_memory';
            
            // Delete all cached translations from both cache and TM
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_cache'") === $table_cache) {
                $wpdb->query("TRUNCATE TABLE {$table_cache}");
            }
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_tm'") === $table_tm) {
                $wpdb->query("TRUNCATE TABLE {$table_tm}");
            }
            
            // Also clear any transients related to translations
            delete_transient('aitrfoel_theme_status_report');
            
            wp_send_json_success([
                'message' => __('Translation cache and Translation Memory cleared successfully!', 'ai-translator-for-elementor-and-polylang')
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error clearing cache: ', 'ai-translator-for-elementor-and-polylang') . $e->getMessage());
        }
    }

    /**
     * Reset plugin data AJAX handler
     * Resets built-in language system, onboarding state, and dependency cache.
     * Preserves: API key, translated pages, cache settings, debug mode.
     */
    public static function reset_plugin_data() {
        check_ajax_referer('aitrfoel_reset_plugin_data', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        try {
            // 1. Disable and clear built-in language system
            delete_option('aitrfoel_builtin_enabled');
            delete_option('aitrfoel_builtin_languages');
            delete_option('aitrfoel_builtin_default_language');
            delete_option('aitrfoel_builtin_url_structure');
            delete_option('aitrfoel_builtin_db_version');
            
            // 2. Reset onboarding / dependency status (triggers welcome screen)
            delete_option('aitrfoel_dependencies_status');
            
            // 3. Clear analytics opt-in pref
            delete_option('aitrfoel_analytics_optin');
            delete_option('aitrfoel_analytics_enabled');
            
            // 4. Clear any transients
            delete_transient('aitrfoel_theme_status_report');
            
            // 5. Clean up built-in taxonomy terms
            $terms = get_terms([
                'taxonomy' => 'aitrfoel_language',
                'hide_empty' => false,
                'fields' => 'ids',
            ]);
            if (!is_wp_error($terms)) {
                foreach ($terms as $term_id) {
                    wp_delete_term($term_id, 'aitrfoel_language');
                }
            }
            
            // 6. Flush rewrite rules
            flush_rewrite_rules();
            
            // 7. Clear the singleton instance so it re-detects on next load
            if (class_exists('AITRFOEL_Multilingual_Manager')) {
                $manager = AITRFOEL_Multilingual_Manager::get_instance();
                if (method_exists($manager, 'refresh')) {
                    $manager->refresh();
                }
            }
            
            wp_send_json_success([
                'message' => __('Plugin data reset successfully! Redirecting to welcome screen...', 'ai-translator-for-elementor-and-polylang')
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error resetting plugin data: ', 'ai-translator-for-elementor-and-polylang') . $e->getMessage());
        }
    }

    /**
     * Render built-in language management controls on the settings page.
     *
     * @param AITRFOEL_Builtin_Integration $builtin Instance of the built-in integration.
     */
    private static function render_builtin_language_management($builtin) {
        $languages = $builtin->get_available_languages();
        $default_lang = $builtin->get_default_language();
        $all_mappings = AITRFOEL_Language_Mapper::get_all_mappings();
        $nonce = wp_create_nonce('aitrfoel_ajax_nonce');
        
        // Get raw stored data for debugging language code corruption
        $raw_stored = get_option('aitrfoel_builtin_languages', []);
        $stored_codes = array_column($raw_stored, 'code');
        $lang_keys = array_keys($languages);
        
        // Check for corruption: codes not matching
        $code_mismatch = array_diff($stored_codes, $lang_keys);
        $show_debug = !empty($code_mismatch) || (count($stored_codes) > 0 && count($lang_keys) > 0 && array_values($stored_codes) !== array_values($lang_keys));
        $debug_mode_enabled = (bool) get_option('aitrfoel_debug_mode', false);
        
        ?>
        <div id="aitrfoel-builtin-lang-management" class="aitrfoel-builtin-lang-management">
            <h4 class="aitrfoel-sub-heading"><?php esc_html_e('Manage Languages', 'ai-translator-for-elementor-and-polylang'); ?></h4>

            <!-- Debug Panel: only show when plugin Debug Mode is enabled -->
            <?php if ($debug_mode_enabled && current_user_can('manage_options')): ?>
            <div class="aitrfoel-debug-panel">
                <strong class="aitrfoel-debug-heading">Debug: Stored Language Codes</strong>
                <div class="aitrfoel-debug-body">
                    <div><strong>Stored in Database:</strong> <?php echo count($raw_stored) > 0 ? esc_html(json_encode($stored_codes)) : '(empty)'; ?></div>
                    <div><strong>Loaded into UI:</strong> <?php echo count($lang_keys) > 0 ? esc_html(json_encode($lang_keys)) : '(empty)'; ?></div>
                    <?php if (!empty($code_mismatch)): ?>
                        <div class="aitrfoel-debug-mismatch"><strong>MISMATCH DETECTED:</strong> <?php echo esc_html(json_encode($code_mismatch)); ?> - These codes in database don't match UI!</div>
                    <?php endif; ?>
                    <?php if (!empty($raw_stored)): ?>
                        <div class="aitrfoel-debug-raw">
                            <strong>Raw Data:</strong><br>
                            <?php foreach ($raw_stored as $idx => $lang): ?>
                                <div>[$<?php echo esc_html($idx); ?>] code=<code><?php echo esc_html($lang['code'] ?? 'N/A'); ?></code>, name=<code><?php echo esc_html($lang['name'] ?? 'N/A'); ?></code>, is_default=<?php echo isset($lang['is_default']) ? ($lang['is_default'] ? 'true' : 'false') : 'N/A'; ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Current Languages Table -->
            <table class="widefat striped" id="aitrfoel-builtin-languages-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Code', 'ai-translator-for-elementor-and-polylang'); ?></th>
                        <th><?php esc_html_e('Language', 'ai-translator-for-elementor-and-polylang'); ?></th>
                        <th><?php esc_html_e('Default', 'ai-translator-for-elementor-and-polylang'); ?></th>
                        <th><?php esc_html_e('Actions', 'ai-translator-for-elementor-and-polylang'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($languages as $code => $lang_obj):
                        $lang_name = is_object($lang_obj) ? $lang_obj->name : $lang_obj;
                    ?>
                        <tr data-lang="<?php echo esc_attr($code); ?>">
                            <td><code class="aitrfoel-builtin-lang-code"><?php echo esc_html(strtoupper($code)); ?></code></td>
                            <td class="aitrfoel-builtin-lang-name-cell">
                                <?php echo AITRFOEL_Language_Mapper::get_flag_html($code, 20, 'margin-right: 6px;'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag <img> from AITRFOEL_Language_Mapper::get_flag_html(), which escapes its URL, alt and style ?>
                                <?php echo esc_html($lang_name); ?>
                            </td>
                            <td class="aitrfoel-builtin-lang-default-cell">
                                <?php if ($code === $default_lang): ?>
                                    <span class="aitrfoel-default-lang-icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M12 2.75L14.9106 8.64656L21.4199 9.5922L16.7099 14.1834L17.8212 20.6653L12 17.604L6.17878 20.6653L7.29007 14.1834L2.58006 9.5922L9.08939 8.64656L12 2.75Z"/>
                                        </svg>
                                    </span>
                                <?php else: ?>
                                    <button type="button" class="button button-small aitrfoel-set-default-lang aitrfoel-builtin-icon-button" data-code="<?php echo esc_attr($code); ?>" title="<?php esc_attr_e('Set as default', 'ai-translator-for-elementor-and-polylang'); ?>">
                                        <span class="aitrfoel-default-lang-icon-empty" aria-hidden="true">
                                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                <path d="M12 2.75L14.9106 8.64656L21.4199 9.5922L16.7099 14.1834L17.8212 20.6653L12 17.604L6.17878 20.6653L7.29007 14.1834L2.58006 9.5922L9.08939 8.64656L12 2.75Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                                            </svg>
                                        </span>
                                    </button>
                                <?php endif; ?>
                            </td>
                            <td class="aitrfoel-builtin-lang-actions-cell">
                                <?php if ($code !== $default_lang): ?>
                                    <button type="button" class="button button-small button-link-delete aitrfoel-remove-lang" data-code="<?php echo esc_attr($code); ?>">
                                        <?php esc_html_e('Remove', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </button>
                                <?php else: ?>
                                    <em class="aitrfoel-builtin-default-label"><?php esc_html_e('Default', 'ai-translator-for-elementor-and-polylang'); ?></em>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Add Language -->
            <div class="aitrfoel-builtin-add-language-row">
                <select id="aitrfoel-add-lang-select" class="aitrfoel-settings-select aitrfoel-builtin-add-language-select">
                    <option value=""><?php esc_html_e('— Select a language to add —', 'ai-translator-for-elementor-and-polylang'); ?></option>
                    <?php
                    foreach ($all_mappings as $code => $name) {
                        if (!isset($languages[$code])) {
                            echo '<option value="' . esc_attr($code) . '">' . esc_html($name) . ' (' . esc_html(strtoupper($code)) . ')</option>';
                        }
                    }
                    ?>
                </select>
                <button type="button" class="button button-primary" id="aitrfoel-add-lang-btn">
                    <?php esc_html_e('Add Language', 'ai-translator-for-elementor-and-polylang'); ?>
                </button>
            </div>

            <?php
            // Switch Default Language Migration Tool — only meaningful with 2+ languages
            if (is_array($languages) && count($languages) >= 2) :
            ?>
            <div id="aitrfoel-sdl-wrap" class="aitrfoel-sdl-card" style="margin-top:20px;padding:16px 18px;background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;">
                <h4 style="margin:0 0 8px;font-size:14px;color:#1e293b;"><?php esc_html_e('Switch Default Language', 'ai-translator-for-elementor-and-polylang'); ?></h4>
                <p class="description" style="margin-bottom:12px;font-size:12px;color:#475569;line-height:1.5;">
                    <?php esc_html_e('Make a different language the one served at the root of your site (no /xx/ prefix). This re-homes slug ownership per translation group — the chosen language takes the clean URLs, the current default moves under its prefix — and adds 301 redirects for the old URLs. Translate everything first (pages, posts, products, and your menus & widgets); the migration only re-homes content that already has a version in the new default language. Always run "Preview" first, back up your database, and test on staging before production.', 'ai-translator-for-elementor-and-polylang'); ?>
                </p>
                <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;">
                    <label for="aitrfoel-sdl-lang" style="font-weight:600;font-size:13px;"><?php esc_html_e('New default language:', 'ai-translator-for-elementor-and-polylang'); ?></label>
                    <select id="aitrfoel-sdl-lang" class="aitrfoel-settings-select">
                        <?php foreach ($languages as $sdl_code => $sdl_l) :
                            $sdl_code = is_object($sdl_l) ? $sdl_l->code : $sdl_code;
                            $sdl_name = is_object($sdl_l) ? $sdl_l->name : (class_exists('AITRFOEL_Language_Mapper') ? AITRFOEL_Language_Mapper::get_language_name($sdl_code) : strtoupper($sdl_code));
                            $sdl_is_def = ($sdl_code === $default_lang);
                        ?>
                        <option value="<?php echo esc_attr($sdl_code); ?>" <?php disabled($sdl_is_def); ?>>
                            <?php echo esc_html($sdl_name . ' (' . strtoupper($sdl_code) . ')' . ($sdl_is_def ? ' — ' . __('current default', 'ai-translator-for-elementor-and-polylang') : '')); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" id="aitrfoel-sdl-preview" class="button button-secondary">
                        <?php esc_html_e('Preview', 'ai-translator-for-elementor-and-polylang'); ?>
                    </button>
                    <button type="button" id="aitrfoel-sdl-run" class="button button-primary">
                        <?php esc_html_e('Switch Default Language', 'ai-translator-for-elementor-and-polylang'); ?>
                    </button>
                </div>
                <div id="aitrfoel-sdl-result" style="display:none;margin-top:12px;padding:10px 14px;background:#ffffff;border:1px solid #e2e8f0;border-left:4px solid #dba617;border-radius:4px;font-size:13px;"></div>
            </div>
            <?php endif; ?>

            <!-- Disable Built-in Option -->
            <div class="aitrfoel-builtin-disable-row" style="margin-top:18px;">
                <button type="button" class="button button-link-delete" id="aitrfoel-disable-builtin">
                    <?php esc_html_e('Disable Built-in Language System', 'ai-translator-for-elementor-and-polylang'); ?>
                </button>
                <span class="aitrfoel-builtin-disable-note">
                    <?php esc_html_e('Switch to Polylang or WPML instead.', 'ai-translator-for-elementor-and-polylang'); ?>
                </span>
            </div>

            <!-- Status message -->
            <div id="aitrfoel-builtin-lang-status" class="aitrfoel-builtin-lang-status"></div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var nonce = '<?php echo esc_js($nonce); ?>';

            function builtinLangAjax(operation, code, langName, callback) {
                var $status = $('#aitrfoel-builtin-lang-status');
                $status.hide();
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aitrfoel_manage_builtin_language',
                        nonce: nonce,
                        operation: operation,
                        language_code: code || '',
                        language_name: langName || ''
                    },
                    success: function(resp) {
                        if (resp.success) {
                            $status.removeClass('is-error').addClass('is-success').text('✓ ' + (resp.data.message || 'Done')).show();
                            if (callback) callback(resp);
                            setTimeout(function() { location.reload(); }, 800);
                        } else {
                            $status.removeClass('is-success').addClass('is-error').text('✗ ' + (resp.data || 'Error')).show();
                        }
                    },
                    error: function() {
                        $status.css('color', 'red').text('✗ <?php echo esc_js(__('Connection error', 'ai-translator-for-elementor-and-polylang')); ?>').show();
                    }
                });
            }

            // Add language
            $('#aitrfoel-add-lang-btn').on('click', function() {
                var $select = $('#aitrfoel-add-lang-select');
                var code = $select.val();
                if (!code) return;
                var langName = $select.find('option:selected').text().replace(/\s*\([A-Z]{2,}\)\s*$/, '').trim();
                $(this).prop('disabled', true);
                builtinLangAjax('add', code, langName);
            });

            // Remove language
            $(document).on('click', '.aitrfoel-remove-lang', function() {
                var code = $(this).data('code');
                if (!confirm('<?php echo esc_js(__('Remove this language? Existing translations in this language will remain but will no longer be managed.', 'ai-translator-for-elementor-and-polylang')); ?>')) return;
                $(this).prop('disabled', true);
                builtinLangAjax('remove', code, '');
            });

            // Set default — route through Switch Default Language migration
            $(document).on('click', '.aitrfoel-set-default-lang', function() {
                var code = $(this).data('code');
                var $tool = $('#aitrfoel-sdl-wrap');
                if ($tool.length) {
                    if (!confirm('<?php echo esc_js(__('Changing the default language has to move your content, not just flip a flag — otherwise the site keeps serving the original language at the root. Use the Switch Default Language migration (with Preview + redirects) below. Jump to it now?', 'ai-translator-for-elementor-and-polylang')); ?>')) {
                        return;
                    }
                    $('#aitrfoel-sdl-lang').val(code);
                    if (typeof window.jumpToSetting === 'function') {
                        window.jumpToSetting('aitrfoel-sdl-wrap');
                    } else {
                        $('html, body').animate({ scrollTop: Math.max(0, $tool.offset().top - 80) }, 400);
                        $tool.css('outline', '2px solid #dba617').delay(1500).queue(function(n){ $(this).css('outline', ''); n(); });
                    }
                    return;
                }
                // Fallback:
                $(this).prop('disabled', true);
                builtinLangAjax('set_default', code, '');
            });

            // Switch Default Language tool actions
            var $sdlRes = $('#aitrfoel-sdl-result');
            function escSdl(s){ return $('<div>').text(String(s == null ? '' : s)).html(); }
            function postSdl(execute){
                var lang = $('#aitrfoel-sdl-lang').val();
                if (!lang) { return; }
                $('#aitrfoel-sdl-preview, #aitrfoel-sdl-run').prop('disabled', true);
                $sdlRes.show().html('<?php echo esc_js(__('Working…', 'ai-translator-for-elementor-and-polylang')); ?>');
                var data = { action: 'aitrfoel_switch_default_language', nonce: nonce, new_default: lang };
                if (execute) { data.execute = 1; }
                $.post(ajaxurl, data).done(function(r){
                    if (!r.success) { $sdlRes.html('❌ ' + escSdl(r.data || '?')); return; }
                    var d = r.data || {};
                    if (d.dry_run) {
                        var html = '<strong><?php echo esc_js(__('Preview', 'ai-translator-for-elementor-and-polylang')); ?>:</strong> ' +
                            escSdl(d.old_default) + ' → ' + escSdl(d.new_default) + ' — ' +
                            escSdl(d.group_count) + ' <?php echo esc_js(__('translation group(s) will change.', 'ai-translator-for-elementor-and-polylang')); ?>';
                        if (d.sample && d.sample.length) {
                            html += '<ul style="margin:8px 0 0 18px;list-style:disc;">';
                            d.sample.forEach(function(s){ html += '<li>' + escSdl(s.promote) + (s.demote && s.demote !== '—' ? '  |  ' + escSdl(s.demote) : '') + '</li>'; });
                            html += '</ul>';
                        }
                        html += '<p style="margin:8px 0 0;color:#996800;"><?php echo esc_js(__('Click "Switch Default Language" to apply. Back up first.', 'ai-translator-for-elementor-and-polylang')); ?></p>';
                        $sdlRes.html(html);
                    } else {
                        $sdlRes.html('✅ <strong><?php echo esc_js(__('Done.', 'ai-translator-for-elementor-and-polylang')); ?></strong> ' +
                            escSdl(d.new_default) + ' <?php echo esc_js(__('is now the default.', 'ai-translator-for-elementor-and-polylang')); ?> ' +
                            escSdl(d.group_count) + ' <?php echo esc_js(__('groups updated,', 'ai-translator-for-elementor-and-polylang')); ?> ' +
                            escSdl(d.redirects) + ' <?php echo esc_js(__('redirects added. Reloading…', 'ai-translator-for-elementor-and-polylang')); ?>');
                        setTimeout(function(){ location.reload(); }, 1800);
                    }
                }).fail(function(){ $sdlRes.html('<?php echo esc_js(__('Request failed — try again.', 'ai-translator-for-elementor-and-polylang')); ?>'); })
                .always(function(){ $('#aitrfoel-sdl-preview, #aitrfoel-sdl-run').prop('disabled', false); });
            }
            $('#aitrfoel-sdl-preview').on('click', function(){ postSdl(false); });
            $('#aitrfoel-sdl-run').on('click', function(){
                var lang = $('#aitrfoel-sdl-lang option:selected').text();
                if (!confirm('<?php echo esc_js(__('Switch the default language to', 'ai-translator-for-elementor-and-polylang')); ?> ' + lang + '?\n\n<?php echo esc_js(__('This rewrites URLs across the site and cannot be auto-undone. Make sure you have a backup and have tested on staging.', 'ai-translator-for-elementor-and-polylang')); ?>')) { return; }
                postSdl(true);
            });

            // Disable built-in
            $('#aitrfoel-disable-builtin').on('click', function() {
                if (!confirm('<?php echo esc_js(__('Are you sure you want to disable the built-in language system? You will need Polylang or WPML to manage translations.', 'ai-translator-for-elementor-and-polylang')); ?>')) return;
                $(this).prop('disabled', true);
                builtinLangAjax('disable', '', '');
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX handler to add a glossary term
     */
    public static function add_glossary_term_ajax() {
        check_ajax_referer('aitrfoel_glossary_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'ai-translator-for-elementor-and-polylang'), 403);
        }

        $source_text = isset($_POST['source_text']) ? sanitize_text_field(wp_unslash($_POST['source_text'])) : '';
        $target_text = isset($_POST['target_text']) ? sanitize_text_field(wp_unslash($_POST['target_text'])) : '';
        $lang_code = isset($_POST['lang_code']) ? sanitize_text_field(wp_unslash($_POST['lang_code'])) : 'all';
        $action = isset($_POST['term_action']) ? sanitize_text_field(wp_unslash($_POST['term_action'])) : 'do_not_translate';

        if (empty($source_text)) {
            wp_send_json_error(__('Source term cannot be empty', 'ai-translator-for-elementor-and-polylang'));
        }

        if ($action === 'replace' && empty($target_text)) {
            wp_send_json_error(__('Target translation cannot be empty for replacement rules', 'ai-translator-for-elementor-and-polylang'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'aitrfoel_glossary';

        $inserted = $wpdb->insert(
            $table_name,
            [
                'source_text' => $source_text,
                'target_text' => $target_text,
                'lang_code'   => $lang_code,
                'action'      => $action,
                'created_at'  => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            wp_send_json_error(__('Failed to save term in database', 'ai-translator-for-elementor-and-polylang'));
        }

        $new_id = $wpdb->insert_id;
        wp_send_json_success([
            'id'          => $new_id,
            'source_text' => esc_html($source_text),
            'target_text' => esc_html($target_text),
            'lang_code'   => esc_html($lang_code),
            'action'      => esc_html($action),
            'message'     => __('Term added successfully', 'ai-translator-for-elementor-and-polylang'),
        ]);
    }

    /**
     * AJAX handler to delete a glossary term
     */
    public static function delete_glossary_term_ajax() {
        check_ajax_referer('aitrfoel_glossary_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'ai-translator-for-elementor-and-polylang'), 403);
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if ($id <= 0) {
            wp_send_json_error(__('Invalid term ID', 'ai-translator-for-elementor-and-polylang'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'aitrfoel_glossary';
        $deleted = $wpdb->delete($table_name, ['id' => $id], ['%d']);

        if ($deleted === false) {
            wp_send_json_error(__('Failed to delete term', 'ai-translator-for-elementor-and-polylang'));
        }

        wp_send_json_success(['message' => __('Term deleted', 'ai-translator-for-elementor-and-polylang')]);
    }

    /**
     * AJAX handler to export glossary as CSV
     */
    public static function export_glossary_csv_ajax() {
        check_ajax_referer('aitrfoel_glossary_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'ai-translator-for-elementor-and-polylang'), 403);
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'aitrfoel_glossary';
        $terms = $wpdb->get_results("SELECT source_text, target_text, lang_code, action FROM $table_name ORDER BY id ASC", ARRAY_A);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=meep-glossary-export-' . gmdate('Y-m-d') . '.csv');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Source Text', 'Target Text', 'Language Code', 'Action']);

        if (!empty($terms)) {
            foreach ($terms as $row) {
                fputcsv($output, [
                    $row['source_text'],
                    $row['target_text'],
                    $row['lang_code'],
                    $row['action']
                ]);
            }
        }
        fclose($output); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streams the CSV download (php://output) / reads the uploaded temp file; WP_Filesystem cannot stream these
        exit;
    }

    /**
     * AJAX handler to import glossary from CSV
     */
    public static function import_glossary_csv_ajax() {
        check_ajax_referer('aitrfoel_glossary_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'ai-translator-for-elementor-and-polylang'), 403);
        }

        if (empty($_FILES['file']['tmp_name'])) {
            wp_send_json_error(__('No CSV file uploaded', 'ai-translator-for-elementor-and-polylang'));
        }

        $file = $_FILES['file']['tmp_name'];
        $handle = fopen($file, 'r'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streams the CSV download (php://output) / reads the uploaded temp file; WP_Filesystem cannot stream these
        if (!$handle) {
            wp_send_json_error(__('Could not read uploaded file', 'ai-translator-for-elementor-and-polylang'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'aitrfoel_glossary';

        $imported = 0;
        $header = fgetcsv($handle); // skip header row

        while (($data = fgetcsv($handle, 1000, ',')) !== false) {
            if (count($data) < 1 || empty(trim($data[0]))) {
                continue;
            }

            $source_text = sanitize_text_field($data[0]);
            $target_text = isset($data[1]) ? sanitize_text_field($data[1]) : '';
            $lang_code = isset($data[2]) && !empty(trim($data[2])) ? sanitize_text_field(trim($data[2])) : 'all';
            $action = isset($data[3]) && in_array(trim($data[3]), ['replace', 'do_not_translate']) ? trim($data[3]) : (!empty($target_text) ? 'replace' : 'do_not_translate');

            $wpdb->insert(
                $table_name,
                [
                    'source_text' => $source_text,
                    'target_text' => $target_text,
                    'lang_code'   => $lang_code,
                    'action'      => $action,
                    'created_at'  => current_time('mysql'),
                ],
                ['%s', '%s', '%s', '%s', '%s']
            );
            $imported++;
        }

        fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streams the CSV download (php://output) / reads the uploaded temp file; WP_Filesystem cannot stream these
        wp_send_json_success([
            'imported' => $imported,
            'message'  => sprintf(/* translators: %d: number of glossary terms imported */ __('%d terms imported successfully', 'ai-translator-for-elementor-and-polylang'), $imported)
        ]);
    }

}

