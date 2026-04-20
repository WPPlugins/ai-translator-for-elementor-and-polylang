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
    }
    
    /**
     * Enqueue settings page scripts
     */
    public static function enqueue_settings_scripts($hook) {
        // Only load on settings page
        if (strpos($hook, 'ai-translator-for-elementor-and-polylang') === false) {
            return;
        }
        
        wp_enqueue_script(
            'aitrfoel-settings-page',
            AITRFOEL_PLUGIN_URL . 'assets/js/settings-page.js',
            [],
            AITRFOEL_VERSION,
            true
        );
        
        // Localize script with AJAX data
        wp_localize_script('aitrfoel-settings-page', 'aitrfoel_settings_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'test_nonce' => wp_create_nonce('aitrfoel_test_api'),
            'clear_cache_nonce' => wp_create_nonce('aitrfoel_clear_cache'),
            'clear_cache_confirm' => __('Are you sure you want to clear all cached translations? This cannot be undone.', 'ai-translator-for-elementor-and-polylang'),
            'clearing_text' => __('Clearing...', 'ai-translator-for-elementor-and-polylang'),
            'clear_cache_text' => __('Clear Translation Cache', 'ai-translator-for-elementor-and-polylang'),
        ]);
    }
    
    /**
     * Get word limit for plan
     * Based on Freemius plan IDs and token limits:
     * - Free: 10K tokens ≈ 1,800 words
     * - Starter (45513): 100K tokens ≈ 18,000 words
     * - Agency (45514): 500K tokens ≈ 89,000 words
     * - Enterprise (45515): 2M tokens ≈ 355,000 words
     */
    public static function get_word_limit_for_plan($plan_name) {
        $limits = [
            'Free' => 1800,        // 10,000 tokens
            'free' => 1800,        // lowercase variant
            'Starter' => 18000,    // 100,000 tokens (Plan ID: 45513)
            'starter' => 18000,    // lowercase variant
            'Agency' => 89000,     // 500,000 tokens (Plan ID: 45514)
            'agency' => 89000,     // lowercase variant
            'Enterprise' => 355000, // 2,000,000 tokens (Plan ID: 45515)
            'enterprise' => 355000  // lowercase variant
        ];
        
        return isset($limits[$plan_name]) ? $limits[$plan_name] : $limits['Free'];
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
        

        
        // Page Creation Settings
        register_setting('aitrfoel_settings_group', 'aitrfoel_translated_page_status', [
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'draft'
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
    
    public static function render_page() {
        $license_key = get_option('aitrfoel_license_key', '');
        $license_status = 'Free';
        if (function_exists('aitrfoel_fs') && aitrfoel_fs()->is_registered()) {
            $plan = aitrfoel_fs()->get_plan();
            $license_status = $plan ? $plan->name : 'Free';
        }
        // Get usage from server (not local cache)
        $words_used = 0;
        $word_limit = 1800; // Default free limit
        if (class_exists('AITRFOEL_License_Manager_Server')) {
            $license_manager = AITRFOEL_License_Manager_Server::get_instance();
            $license_info = $license_manager->get_license_status();
            $words_used = $license_info['words_used'];
            $word_limit = $license_info['word_limit'];
        }
        $cache_enabled = get_option('aitrfoel_cache_enabled', true);
        $cache_duration = get_option('aitrfoel_cache_duration', 30);
        $translated_page_status = get_option('aitrfoel_translated_page_status', 'draft');
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
                
                <!-- Settings Section Navigation -->
                <nav class="aitrfoel-settings-nav" id="aitrfoel-settings-nav">
                    <a href="#section-license">🔑 <?php esc_html_e('License', 'ai-translator-for-elementor-and-polylang'); ?></a>
                    <a href="#section-multilingual">🌍 <?php esc_html_e('Multilingual', 'ai-translator-for-elementor-and-polylang'); ?></a>
                    <a href="#section-switcher">🔄 <?php esc_html_e('Switcher', 'ai-translator-for-elementor-and-polylang'); ?></a>
                    <a href="#section-menus-widgets">📋 <?php esc_html_e('Menus & Widgets', 'ai-translator-for-elementor-and-polylang'); ?></a>
                    <?php if (post_type_exists('elementor_library')): ?>
                    <a href="#section-elementor-templates">🧩 <?php esc_html_e('Templates', 'ai-translator-for-elementor-and-polylang'); ?></a>
                    <?php endif; ?>
                    <a href="#section-cache">💾 <?php esc_html_e('Cache', 'ai-translator-for-elementor-and-polylang'); ?></a>
                    <a href="#section-page-creation">📄 <?php esc_html_e('Pages', 'ai-translator-for-elementor-and-polylang'); ?></a>
                    <a href="#section-seo">🔍 <?php esc_html_e('SEO', 'ai-translator-for-elementor-and-polylang'); ?></a>
                    <a href="#section-woocommerce">🛒 <?php esc_html_e('WooCommerce', 'ai-translator-for-elementor-and-polylang'); ?></a>
                    <a href="#section-exclude-words">🚫 <?php esc_html_e('Exclude Words', 'ai-translator-for-elementor-and-polylang'); ?></a>
                    <a href="#section-glossary">📖 <?php esc_html_e('Glossary', 'ai-translator-for-elementor-and-polylang'); ?></a>
                    <a href="#section-theme">🎨 <?php esc_html_e('Theme', 'ai-translator-for-elementor-and-polylang'); ?></a>
                    <a href="#section-advanced">🔧 <?php esc_html_e('Advanced', 'ai-translator-for-elementor-and-polylang'); ?></a>
                    <a href="#section-privacy">🔒 <?php esc_html_e('Privacy', 'ai-translator-for-elementor-and-polylang'); ?></a>
                </nav>
                
                <script>
                (function() {
                    document.addEventListener('DOMContentLoaded', function() {
                        var nav = document.getElementById('aitrfoel-settings-nav');
                        if (!nav) return;
                        var links = nav.querySelectorAll('a[href^="#"]');
                        
                        // Smooth scroll
                        links.forEach(function(link) {
                            link.addEventListener('click', function(e) {
                                var target = document.querySelector(this.getAttribute('href'));
                                if (target) {
                                    e.preventDefault();
                                    var offset = nav.offsetHeight + 48;
                                    var top = target.getBoundingClientRect().top + window.pageYOffset - offset;
                                    window.scrollTo({ top: top, behavior: 'smooth' });
                                }
                            });
                        });
                        
                        // Active state on scroll
                        var sections = [];
                        links.forEach(function(link) {
                            var id = link.getAttribute('href').substring(1);
                            var el = document.getElementById(id);
                            if (el) sections.push({ id: id, el: el, link: link });
                        });
                        
                        function updateActive() {
                            var scrollPos = window.pageYOffset + nav.offsetHeight + 80;
                            var current = sections[0];
                            sections.forEach(function(s) {
                                if (s.el.offsetTop <= scrollPos) current = s;
                            });
                            links.forEach(function(l) { l.classList.remove('active'); });
                            if (current) current.link.classList.add('active');
                        }
                        
                        window.addEventListener('scroll', updateActive);
                        updateActive();
                    });
                })();
                </script>
                
                <!-- License Configuration -->
                <div class="aitrfoel-settings-section" id="section-license">
                    <h3><?php esc_html_e('🔑 License Configuration', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label><?php esc_html_e('License', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <div class="aitrfoel-license-info">
                                    <div class="aitrfoel-license-status">
                                        <strong><?php esc_html_e('Current Plan:', 'ai-translator-for-elementor-and-polylang'); ?></strong> 
                                        <span class="eat-plan-name"><?php echo esc_html($license_status); ?></span>
                                    </div>
                                    <div class="eat-word-usage">
                                        <strong><?php esc_html_e('Words Translated:', 'ai-translator-for-elementor-and-polylang'); ?></strong> 
                                        <?php echo number_format($words_used); ?> / <?php echo $word_limit === -1 ? esc_html__('Unlimited', 'ai-translator-for-elementor-and-polylang') : number_format($word_limit); ?>
                                    </div>
                                    <?php if ($word_limit !== -1): ?>
                                        <div class="eat-usage-bar">
                                            <div class="eat-usage-progress" style="width: <?php echo absint(min(100, ($words_used / $word_limit) * 100)); ?>%;"></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <p class="description">
                                    <?php esc_html_e('Get your License from:', 'ai-translator-for-elementor-and-polylang'); ?> 
                                    <a href="<?php echo esc_url('https://meeptech.com/products/ai-translator-for-elementor-polylang/#pricing'); ?>"><?php esc_html_e('Upgrade page', 'ai-translator-for-elementor-and-polylang'); ?></a>
                                </p>
                                <?php if (function_exists('eatp_fs') && (!eatp_fs()->is_registered() || eatp_fs()->is_free_plan())): ?>
                                    <a href="<?php echo esc_url(eatp_fs()->get_upgrade_url()); ?>" class="button button-primary"><?php esc_html_e('Upgrade License', 'ai-translator-for-elementor-and-polylang'); ?></a>
                                <?php elseif (!function_exists('eatp_fs')): ?>
                                    <a href="https://meeptech.com/products/ai-translator-for-elementor-polylang/#pricing" target="_blank" class="button button-primary"><?php esc_html_e('Get License', 'ai-translator-for-elementor-and-polylang'); ?></a>
                                <?php endif; ?>
                                
                                <?php if (function_exists('eatp_fs') && eatp_fs()->is_registered() && !eatp_fs()->is_free_plan()): ?>
                                    <button type="button" id="aitrfoel-sync-plan" class="button button-secondary">
                                        <?php esc_html_e('Sync Plan', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </button>
                                    <span id="aitrfoel-sync-plan-status" style="margin-left: 10px; display: none;"></span>
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
                                                    nonce: '<?php echo wp_create_nonce('aitrfoel_nonce'); ?>'
                                                },
                                                success: function(response) {
                                                    $btn.prop('disabled', false).text(originalText);
                                                    if (response.success) {
                                                        $status.css('color', 'green').text('✓ ' + response.data.message).show();
                                                        // Reload page after 1.5 seconds to show updated plan
                                                        setTimeout(function() { location.reload(); }, 1500);
                                                    } else {
                                                        $status.css('color', 'red').text('✗ ' + (response.data.message || 'Sync failed')).show();
                                                    }
                                                },
                                                error: function() {
                                                    $btn.prop('disabled', false).text(originalText);
                                                    $status.css('color', 'red').text('✗ <?php echo esc_js(__('Connection error', 'ai-translator-for-elementor-and-polylang')); ?>').show();
                                                }
                                            });
                                        });
                                    });
                                    </script>
                                <?php endif; ?>
                            </td>
                        </tr>
                        



                    </table>
                </div>
                
                <!-- Multilingual Plugin Info -->
                <div class="aitrfoel-settings-section" id="section-multilingual">
                    <h3><?php esc_html_e('🌐 Multilingual Plugin', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    
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
                                        $plugin_name = 'AI Translator Built-in';
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
                                        if ($is_builtin) {
                                            $icon = '⚡';
                                        } elseif ($is_polylang) {
                                            $icon = '🦜';
                                        } else {
                                            $icon = '🌍';
                                        }
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
                                                <?php echo $icon; ?> <?php echo esc_html($plugin_name); ?>
                                            </span>
                                            <p class="description" style="margin-top: 8px;">
                                                <strong><?php esc_html_e('Default Language:', 'ai-translator-for-elementor-and-polylang'); ?></strong> 
                                                <?php echo AITRFOEL_Language_Mapper::get_flag_html($default_lang, 16, 'margin-right: 4px;'); ?>
                                                <?php echo esc_html(strtoupper($default_lang)); ?>
                                                <br>
                                                <strong><?php esc_html_e('Available Languages:', 'ai-translator-for-elementor-and-polylang'); ?></strong> 
                                                <?php echo esc_html($lang_count); ?> 
                                                (<?php 
                                                $lang_labels = [];
                                                foreach ($lang_codes as $lc) {
                                                    $lang_labels[] = AITRFOEL_Language_Mapper::get_flag_html($lc, 16, 'margin-right: 2px;') . ' ' . esc_html(strtoupper($lc));
                                                }
                                                echo implode(', ', $lang_labels);
                                                ?>)
                                            </p>
                                            <?php if ($is_builtin): ?>
                                                <!-- Built-in language management controls -->
                                                <?php self::render_builtin_language_management($builtin); ?>
                                            <?php else: ?>
                                                <p style="margin-top: 10px;">
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
                                                ⚠️ <?php esc_html_e('No multilingual plugin detected', 'ai-translator-for-elementor-and-polylang'); ?>
                                            </span>
                                            <p class="description" style="margin-top: 8px;">
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
                                            ⚠️ <?php esc_html_e('Error detecting multilingual plugin', 'ai-translator-for-elementor-and-polylang'); ?>
                                        </span>
                                    </div>
                                    <?php
                                }
                                ?>
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
                    </table>
                    
                    <!-- Inline Save (after Multilingual Plugin) -->
                    <div class="aitrfoel-inline-save">
                        <?php submit_button(__('Save Settings', 'ai-translator-for-elementor-and-polylang'), 'primary', 'submit', false); ?>
                    </div>
                </div>
                
                <!-- Language Switcher Settings -->
                <div class="aitrfoel-settings-section" id="section-switcher">
                    <h3><?php esc_html_e('🌍 Language Switcher', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    
                    <p class="description" style="margin-bottom: 15px; font-size: 13px;">
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
                                
                                <div id="aitrfoel-name-display-options" style="margin-top: 10px; padding: 10px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 4px; <?php echo !$show_names ? 'display:none;' : ''; ?>">
                                    <label style="display: block; margin-bottom: 4px; font-weight: 500; font-size: 12px; color: #374151;"><?php esc_html_e('Name format:', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                    <select name="aitrfoel_switcher_name_display" style="width: max-content; min-width: 200px;">
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
                                    <div style="margin-top: 10px;">
                                        <label style="display: block; margin-bottom: 4px; font-weight: 500; font-size: 12px; color: #374151;"><?php esc_html_e('Custom names (optional — overrides the format above):', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                        <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                            <?php foreach ($switcher_languages as $sl): ?>
                                            <div style="display: flex; align-items: center; gap: 4px;">
                                                <?php echo AITRFOEL_Language_Mapper::get_flag_html($sl->code, 16); ?>
                                                <input type="text" name="aitrfoel_switcher_custom_names[<?php echo esc_attr($sl->code); ?>]" value="<?php echo esc_attr($custom_names[$sl->code] ?? ''); ?>" placeholder="<?php echo esc_attr($sl->native_name ?? $sl->name); ?>" style="width: 100px; font-size: 12px; padding: 2px 6px;">
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <p class="description" style="margin-top: 4px; font-size: 11px;"><?php esc_html_e('Leave empty to use the selected format above.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php esc_html_e('Shortcode', 'ai-translator-for-elementor-and-polylang'); ?></th>
                            <td>
                                <div style="background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; padding: 12px; margin-bottom: 10px;">
                                    <code id="aitrfoel-switcher-shortcode" style="font-size: 13px; word-break: break-all; user-select: all;">[aitrfoel_language_switcher style="<?php echo esc_attr($switcher_style); ?>" show_flags="<?php echo $show_flags ? 'true' : 'false'; ?>" show_names="<?php echo $show_names ? 'true' : 'false'; ?>"]</code>
                                    <button type="button" class="button button-small" onclick="navigator.clipboard.writeText(document.getElementById('aitrfoel-switcher-shortcode').textContent).then(function(){var b=event.target;b.textContent='<?php echo esc_js(__('Copied!', 'ai-translator-for-elementor-and-polylang')); ?>';setTimeout(function(){b.textContent='<?php echo esc_js(__('Copy', 'ai-translator-for-elementor-and-polylang')); ?>';},1500)})" style="margin-left: 8px; vertical-align: middle;">
                                        <?php esc_html_e('Copy', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </button>
                                </div>
                                <p class="description">
                                    <?php esc_html_e('Copy this shortcode and paste it into any page, post, or text widget where you want the language switcher to appear.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                                
                                <div style="margin-top: 12px; padding: 12px; background: #f0f6fc; border-left: 3px solid #2271b1; border-radius: 3px;">
                                    <strong>💡 <?php esc_html_e('Alternative Ways to Add:', 'ai-translator-for-elementor-and-polylang'); ?></strong>
                                    <ul style="margin: 8px 0 0 16px; list-style: disc;">
                                        <li>
                                            <strong><?php esc_html_e('WordPress Widget:', 'ai-translator-for-elementor-and-polylang'); ?></strong>
                                            <?php printf(
                                                /* translators: %s: link to WordPress widgets page */
                                                esc_html__('Go to %s and add the "AI Translator – Language Switcher" widget to any sidebar or widget area.', 'ai-translator-for-elementor-and-polylang'),
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
                                            <code style="font-size: 12px;">&lt;?php echo do_shortcode('[aitrfoel_language_switcher]'); ?&gt;</code>
                                        </li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php esc_html_e('Preview', 'ai-translator-for-elementor-and-polylang'); ?></th>
                            <td>
                                <div style="background: #fff; border: 1px solid #dcdcde; border-radius: 4px; padding: 16px;">
                                    <?php
                                    // Render actual shortcode output so preview matches frontend exactly
                                    if (class_exists('AITRFOEL_Builtin_Integration') && AITRFOEL_Builtin_Integration::is_enabled()) {
                                        $builtin = AITRFOEL_Builtin_Integration::get_instance();
                                        $preview_html = $builtin->render_language_switcher_shortcode();
                                        if (!empty($preview_html)) {
                                            echo $preview_html;
                                        } else {
                                            echo '<p style="color: #666; margin: 0;">' . esc_html__('No languages configured yet. Add languages in the Multilingual Plugin section above.', 'ai-translator-for-elementor-and-polylang') . '</p>';
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
                                                echo '<span style="display: inline-flex; align-items: center; padding: 4px 8px; margin-right: 6px; border: 1px solid #ddd; border-radius: 4px;">';
                                                echo $flag . ($pll_name ? ' <span style="font-size: 13px;">' . $pll_name . '</span>' : '');
                                                echo '</span>';
                                            }
                                            echo '</div>';
                                        } else {
                                            echo '<p style="color: #666; margin: 0;">' . esc_html__('No languages configured yet.', 'ai-translator-for-elementor-and-polylang') . '</p>';
                                        }
                                    } else {
                                        echo '<p style="color: #666; margin: 0;">' . esc_html__('No multilingual plugin active. Configure one first.', 'ai-translator-for-elementor-and-polylang') . '</p>';
                                    }
                                    ?>
                                </div>
                                <p class="description" style="margin-top: 6px;">
                                    <?php esc_html_e('This preview shows the exact output that will appear on the frontend. Save settings to update.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_switcher_custom_css"><?php esc_html_e('Custom CSS', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
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
                                <label style="display: block; margin-bottom: 10px;">
                                    <input type="checkbox" name="aitrfoel_switcher_hide_untranslated" value="1" <?php checked($hide_untranslated); ?> />
                                    <?php esc_html_e('Hide languages that have no translation for the current page', 'ai-translator-for-elementor-and-polylang'); ?>
                                </label>
                                <p class="description" style="margin-bottom: 16px;">
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
                                <div style="margin-top: 10px;">
                                    <label for="aitrfoel_nav_menu_location">
                                        <strong><?php esc_html_e('Menu Location:', 'ai-translator-for-elementor-and-polylang'); ?></strong>
                                    </label>
                                    <select id="aitrfoel_nav_menu_location" name="aitrfoel_nav_menu_location" style="margin-left: 6px;">
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
                                </div>
                                <?php else: ?>
                                <p class="description" style="margin-top: 6px;">
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

                <!-- Menus & Widgets Settings -->
                <div class="aitrfoel-settings-section" id="section-menus-widgets">
                    <h3><?php esc_html_e('📋 Menus & Widgets', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    
                    <p class="description" style="margin-bottom: 15px; font-size: 13px;">
                        <?php esc_html_e('Assign different navigation menus and control widget visibility per language. Only available when the built-in language system is active.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                    
                    <?php
                    $mw_builtin_active = class_exists('AITRFOEL_Builtin_Integration') && AITRFOEL_Builtin_Integration::is_enabled();
                    if (!$mw_builtin_active): ?>
                        <div style="padding: 20px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; text-align: center; color: #666;">
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
                        <div style="padding: 20px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; text-align: center; color: #666;">
                            <?php esc_html_e('Add at least one additional language in the Multilingual section to configure per-language menus and widgets.', 'ai-translator-for-elementor-and-polylang'); ?>
                        </div>
                    <?php else: ?>
                    
                    <!-- Auto-Translate & Assign -->
                    <div style="margin-bottom: 20px; padding: 16px; background: linear-gradient(135deg, #f0f6fc 0%, #e8f0fe 100%); border: 1px solid #c3d9f0; border-radius: 6px;">
                        <h4 style="margin: 0 0 8px; font-size: 14px;">🚀 <?php esc_html_e('Auto-Translate & Assign', 'ai-translator-for-elementor-and-polylang'); ?></h4>
                        <p class="description" style="margin: 0 0 12px; font-size: 12px;">
                            <?php esc_html_e('Automatically clone your menus, translate menu item labels and widget content, and assign everything per language. Uses your translation credits.', 'ai-translator-for-elementor-and-polylang'); ?>
                        </p>
                        <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
                            <?php foreach ($mw_secondary as $lang): ?>
                            <button type="button" class="button aitrfoel-auto-translate-lang" data-lang="<?php echo esc_attr($lang->code); ?>" data-name="<?php echo esc_attr($lang->name); ?>">
                                <?php echo AITRFOEL_Language_Mapper::get_flag_html($lang->code, 16, 'vertical-align: middle; margin-right: 4px;'); ?>
                                <?php echo esc_html(sprintf(
                                    __('Translate → %s', 'ai-translator-for-elementor-and-polylang'),
                                    strtoupper($lang->code)
                                )); ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <div style="margin-top: 12px;">
                            <button type="button" class="button button-primary aitrfoel-auto-translate-all">
                                🌍 <?php esc_html_e('Translate All Languages', 'ai-translator-for-elementor-and-polylang'); ?>
                            </button>
                        </div>
                        <div class="aitrfoel-auto-translate-status" style="margin-top: 10px; display: none;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span class="spinner" style="float: none; visibility: visible; margin: 0;"></span>
                                <span class="aitrfoel-auto-translate-msg" style="font-size: 13px; color: #1d2327;"></span>
                            </div>
                            <div class="aitrfoel-auto-translate-log" style="margin-top: 8px; max-height: 200px; overflow-y: auto; font-size: 12px; color: #555; font-family: monospace; background: #fff; padding: 8px; border-radius: 3px; border: 1px solid #dcdcde;"></div>
                        </div>
                    </div>
                    
                    <!-- Advanced: Per-Language Menu & Widget Assignment (collapsible) -->
                    <div style="margin-top: 20px; margin-bottom: 20px;">
                        <a href="#" id="aitrfoel-toggle-advanced-mw" style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: #646970; text-decoration: none; padding: 6px 0;">
                            <span class="dashicons dashicons-admin-generic" style="font-size: 16px; width: 16px; height: 16px;"></span>
                            <?php esc_html_e('Advanced: Manual Menu & Widget Assignment', 'ai-translator-for-elementor-and-polylang'); ?>
                            <span class="dashicons dashicons-arrow-down-alt2" id="aitrfoel-advanced-mw-arrow" style="font-size: 14px; width: 14px; height: 14px; transition: transform 0.2s;"></span>
                        </a>
                    </div>
                    <div id="aitrfoel-advanced-mw" style="display: none; margin-bottom: 24px; padding: 16px; background: #fafafa; border: 1px solid #e2e4e7; border-radius: 4px;">
                    
                    <!-- Per-Language Menu Assignment -->
                    <table class="form-table" style="margin-top: 0;">
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
                                            esc_html__('No menus found. %sCreate menus%s in Appearance → Menus first, then assign them per language here.', 'ai-translator-for-elementor-and-polylang'),
                                            '<a href="' . esc_url(admin_url('nav-menus.php')) . '">',
                                            '</a>'
                                        ); ?>
                                    </p>
                                <?php else: ?>
                                    <p class="description" style="margin-bottom: 12px;">
                                        <?php esc_html_e('The default language uses the menu assigned in Appearance → Menus. Select a different menu for each additional language below.', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </p>
                                    <?php foreach ($mw_menu_locations as $loc_slug => $loc_name): ?>
                                    <div style="margin-bottom: 14px; padding: 10px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px;">
                                        <strong><?php echo esc_html($loc_name); ?></strong>
                                        <span style="color: #666; font-size: 12px;">(<?php echo esc_html($loc_slug); ?>)</span>
                                        <div style="margin-top: 8px; display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 6px;">
                                        <?php foreach ($mw_secondary as $lang): ?>
                                            <label style="display: flex; align-items: center; gap: 6px; font-size: 13px;">
                                                <?php echo AITRFOEL_Language_Mapper::get_flag_html($lang->code, 16); ?>
                                                <span><?php echo esc_html(strtoupper($lang->code)); ?>:</span>
                                                <select name="aitrfoel_menu_assignments[<?php echo esc_attr($loc_slug); ?>][<?php echo esc_attr($lang->code); ?>]" style="flex: 1; min-width: 0;">
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
                    
                    <hr style="margin: 20px 0; border: 0; border-top: 1px solid #dcdcde;">
                    
                    <!-- Per-Language Widget Visibility -->
                    <h4 style="margin-top: 0;"><?php esc_html_e('Widget Language Visibility', 'ai-translator-for-elementor-and-polylang'); ?></h4>
                    <p class="description" style="margin-bottom: 12px;">
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
                    <div style="margin-bottom: 16px; padding: 12px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px;">
                        <strong style="font-size: 13px;"><?php echo esc_html($sidebar_name); ?></strong>
                        <table class="widefat" style="margin-top: 8px; border: none;">
                            <thead>
                                <tr>
                                    <th style="padding: 6px 8px; font-size: 12px; width: 40%;"><?php esc_html_e('Widget', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                    <th style="padding: 6px 8px; font-size: 12px;"><?php esc_html_e('Show in Languages', 'ai-translator-for-elementor-and-polylang'); ?></th>
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
                                    <td style="padding: 6px 8px; font-size: 13px; vertical-align: middle;">
                                        <?php echo esc_html($widget_title); ?>
                                        <span style="color: #999; font-size: 11px;">(<?php echo esc_html($widget_id); ?>)</span>
                                    </td>
                                    <td style="padding: 6px 8px;">
                                        <label style="margin-right: 10px; font-size: 12px;">
                                            <input type="radio" name="aitrfoel_widget_languages[<?php echo esc_attr($widget_id); ?>][_mode]" value="all"
                                                <?php checked(empty($selected_langs)); ?> />
                                            <?php esc_html_e('All', 'ai-translator-for-elementor-and-polylang'); ?>
                                        </label>
                                        <?php foreach ($mw_all_languages as $lang): ?>
                                        <label style="margin-right: 8px; font-size: 12px; white-space: nowrap;">
                                            <input type="checkbox" name="aitrfoel_widget_languages[<?php echo esc_attr($widget_id); ?>][<?php echo esc_attr($lang->code); ?>]" value="1"
                                                <?php checked(in_array($lang->code, $selected_langs, true)); ?>
                                                onchange="this.closest('td').querySelector('input[type=radio]').checked=false;" />
                                            <?php echo AITRFOEL_Language_Mapper::get_flag_html($lang->code, 14, 'vertical-align: middle; margin-right: 2px;'); ?>
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
                        <div style="padding: 16px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; text-align: center; color: #666;">
                            <?php printf(
                                esc_html__('No active widgets found. %sAdd widgets%s to your sidebars first, then configure their language visibility here.', 'ai-translator-for-elementor-and-polylang'),
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

                <!-- Elementor Templates -->
                <?php if (post_type_exists('elementor_library')): ?>
                <div class="aitrfoel-settings-section" id="section-elementor-templates">
                    <h3><?php esc_html_e('🧩 Elementor Templates', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    
                    <?php
                    $et_builtin_active = class_exists('AITRFOEL_Builtin_Integration') && AITRFOEL_Builtin_Integration::is_enabled();
                    if (!$et_builtin_active): ?>
                        <div style="padding: 20px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; text-align: center; color: #666;">
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
                        
                        // Split into active (has display conditions = used by theme) and other
                        $et_active = [];
                        $et_other = [];
                        $active_types = ['header', 'footer', 'single', 'single-post', 'single-page', 'archive', 'product', 'product-archive', 'error-404', 'search-results'];
                        foreach ($et_templates as $tpl) {
                            $conditions = get_post_meta($tpl->ID, '_elementor_conditions', true);
                            $tpl_type = get_post_meta($tpl->ID, '_elementor_template_type', true);
                            // Active = has display conditions OR is a header/footer type
                            if (!empty($conditions) || in_array($tpl_type, ['header', 'footer'], true)) {
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
                        <div style="padding: 20px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; text-align: center; color: #666;">
                            <?php esc_html_e('Add at least one additional language in the Multilingual section first.', 'ai-translator-for-elementor-and-polylang'); ?>
                        </div>
                    <?php elseif (empty($et_templates)): ?>
                        <div style="padding: 20px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; text-align: center; color: #666;">
                            <?php esc_html_e('No Elementor templates found.', 'ai-translator-for-elementor-and-polylang'); ?>
                        </div>
                    <?php else: ?>
                    
                    <!-- Active templates (displayed on the site) -->
                    <?php if (!empty($et_active)): ?>
                    <p class="description" style="margin-bottom: 12px; font-size: 13px;">
                        <?php esc_html_e('These templates are actively displayed on your site (headers, footers, and page templates with display conditions). Translate them to show the correct content for each language.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                    
                    <table class="widefat" style="margin-bottom: 16px;">
                        <thead>
                            <tr>
                                <th style="padding: 8px; width: 40%;"><?php esc_html_e('Template', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                <th style="padding: 8px; width: 12%;"><?php esc_html_e('Type', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                <th style="padding: 8px;"><?php esc_html_e('Translations', 'ai-translator-for-elementor-and-polylang'); ?></th>
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
                                <td style="padding: 8px;">
                                    <strong><?php echo esc_html($tpl->post_title ?: __('(no title)', 'ai-translator-for-elementor-and-polylang')); ?></strong>
                                </td>
                                <td style="padding: 8px;">
                                    <span style="background: <?php echo $is_hf ? '#dcfce7' : '#e8f0fe'; ?>; color: <?php echo $is_hf ? '#166534' : '#1a56db'; ?>; padding: 2px 8px; border-radius: 3px; font-size: 12px;"><?php echo esc_html($type_label); ?></span>
                                </td>
                                <td style="padding: 8px;">
                                    <?php foreach ($et_secondary as $lang):
                                        $has = isset($tpl_translations[$lang->code]) && $tpl_translations[$lang->code] != $tpl->ID;
                                    ?>
                                        <span style="display: inline-flex; align-items: center; gap: 3px; margin-right: 8px; font-size: 12px;">
                                            <?php echo AITRFOEL_Language_Mapper::get_flag_html($lang->code, 14); ?>
                                            <?php echo esc_html(strtoupper($lang->code)); ?>
                                            <?php if ($has): ?>
                                                <span style="color: #16a34a;">✓</span>
                                            <?php else: ?>
                                                <span style="color: #dc2626;">✗</span>
                                            <?php endif; ?>
                                        </span>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                        <div style="padding: 16px; background: #fefce8; border: 1px solid #fef08a; border-radius: 4px; margin-bottom: 16px; color: #854d0e;">
                            <?php esc_html_e('No active templates detected. Your Elementor templates may not have display conditions set. Check the Advanced section below for all templates.', 'ai-translator-for-elementor-and-polylang'); ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Translate buttons -->
                    <div style="padding: 16px; background: linear-gradient(135deg, #f0f6fc 0%, #e8f0fe 100%); border: 1px solid #c3d9f0; border-radius: 6px; margin-bottom: 20px;">
                        <h4 style="margin: 0 0 8px; font-size: 14px;">🚀 <?php esc_html_e('Translate Active Templates', 'ai-translator-for-elementor-and-polylang'); ?></h4>
                        <p class="description" style="margin: 0 0 12px; font-size: 12px;">
                            <?php esc_html_e('Translates your headers, footers, and other active page templates. Already translated templates will be skipped. Uses your translation credits.', 'ai-translator-for-elementor-and-polylang'); ?>
                        </p>
                        <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
                            <?php foreach ($et_secondary as $lang): ?>
                            <button type="button" class="button aitrfoel-translate-tpl-lang" data-lang="<?php echo esc_attr($lang->code); ?>" data-name="<?php echo esc_attr($lang->name); ?>" data-mode="active">
                                <?php echo AITRFOEL_Language_Mapper::get_flag_html($lang->code, 16, 'vertical-align: middle; margin-right: 4px;'); ?>
                                <?php echo esc_html(sprintf(
                                    __('Translate → %s', 'ai-translator-for-elementor-and-polylang'),
                                    strtoupper($lang->code)
                                )); ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <div style="margin-top: 12px; display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
                            <button type="button" class="button button-primary aitrfoel-translate-tpl-all" data-mode="active">
                                🌍 <?php esc_html_e('Translate All Languages', 'ai-translator-for-elementor-and-polylang'); ?>
                            </button>
                            <label style="font-size: 12px; color: #646970; display: inline-flex; align-items: center; gap: 4px; cursor: pointer;">
                                <input type="checkbox" id="aitrfoel-tpl-force" value="1" style="margin: 0;">
                                <?php esc_html_e('Force re-translate (replace existing translations)', 'ai-translator-for-elementor-and-polylang'); ?>
                            </label>
                        </div>
                        <div class="aitrfoel-tpl-translate-status" style="margin-top: 10px; display: none;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span class="spinner" style="float: none; visibility: visible; margin: 0;"></span>
                                <span class="aitrfoel-tpl-translate-msg" style="font-size: 13px; color: #1d2327;"></span>
                            </div>
                            <div class="aitrfoel-tpl-translate-log" style="margin-top: 8px; max-height: 200px; overflow-y: auto; font-size: 12px; color: #555; font-family: monospace; background: #fff; padding: 8px; border-radius: 3px; border: 1px solid #dcdcde;"></div>
                        </div>
                    </div>
                    
                    <!-- Advanced: Other templates (collapsible) -->
                    <?php if (!empty($et_other)): ?>
                    <div style="margin-top: 10px;">
                        <a href="#" id="aitrfoel-toggle-other-tpl" style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: #646970; text-decoration: none; padding: 6px 0;">
                            <span class="dashicons dashicons-admin-generic" style="font-size: 16px; width: 16px; height: 16px;"></span>
                            <?php echo esc_html(sprintf(
                                __('Advanced: %d other templates (sections, popups, loops...)', 'ai-translator-for-elementor-and-polylang'),
                                count($et_other)
                            )); ?>
                            <span class="dashicons dashicons-arrow-down-alt2" id="aitrfoel-other-tpl-arrow" style="font-size: 14px; width: 14px; height: 14px; transition: transform 0.2s;"></span>
                        </a>
                    </div>
                    <div id="aitrfoel-other-tpl" style="display: none; margin-top: 10px;">
                        <p class="description" style="margin-bottom: 10px; font-size: 12px;">
                            <?php esc_html_e('These are reusable sections, containers, popups, and loop templates. They are usually embedded inside pages that are already translated, so translating them separately is optional.', 'ai-translator-for-elementor-and-polylang'); ?>
                        </p>
                        <table class="widefat" style="margin-bottom: 16px;">
                            <thead>
                                <tr>
                                    <th style="padding: 8px; width: 40%;"><?php esc_html_e('Template', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                    <th style="padding: 8px; width: 12%;"><?php esc_html_e('Type', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                    <th style="padding: 8px;"><?php esc_html_e('Translations', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($et_other as $tpl):
                                $tpl_type = get_post_meta($tpl->ID, '_elementor_template_type', true);
                                $tpl_translations = $et_builtin->get_post_translations($tpl->ID);
                                $type_label = isset($type_labels[$tpl_type]) ? $type_labels[$tpl_type] : ucfirst($tpl_type ?: 'Template');
                            ?>
                                <tr>
                                    <td style="padding: 8px;">
                                        <?php echo esc_html($tpl->post_title ?: __('(no title)', 'ai-translator-for-elementor-and-polylang')); ?>
                                        <span style="color: #999; font-size: 11px;">#<?php echo esc_html($tpl->ID); ?></span>
                                    </td>
                                    <td style="padding: 8px;">
                                        <span style="background: #f3f4f6; color: #6b7280; padding: 2px 8px; border-radius: 3px; font-size: 12px;"><?php echo esc_html($type_label); ?></span>
                                    </td>
                                    <td style="padding: 8px;">
                                        <?php foreach ($et_secondary as $lang):
                                            $has = isset($tpl_translations[$lang->code]) && $tpl_translations[$lang->code] != $tpl->ID;
                                        ?>
                                            <span style="display: inline-flex; align-items: center; gap: 3px; margin-right: 8px; font-size: 12px;">
                                                <?php echo AITRFOEL_Language_Mapper::get_flag_html($lang->code, 14); ?>
                                                <?php echo esc_html(strtoupper($lang->code)); ?>
                                                <?php if ($has): ?>
                                                    <span style="color: #16a34a;">✓</span>
                                                <?php else: ?>
                                                    <span style="color: #dc2626;">✗</span>
                                                <?php endif; ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div style="padding: 12px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px;">
                            <button type="button" class="button aitrfoel-translate-tpl-all" data-mode="all">
                                <?php esc_html_e('Translate All Templates (including advanced)', 'ai-translator-for-elementor-and-polylang'); ?>
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php endif; // end templates check ?>
                    <?php endif; // end builtin check ?>
                </div>
                <?php endif; // end elementor_library check ?>

                <!-- Cache Settings -->
                <div class="aitrfoel-settings-section" id="section-cache">
                    <h3><?php esc_html_e('💾 Cache Settings', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    
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
                                    🗑️ <?php esc_html_e('Clear Translation Cache', 'ai-translator-for-elementor-and-polylang'); ?>
                                </button>
                                <span id="aitrfoel-clear-cache-status" style="margin-left: 10px;"></span>
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
                

                <!-- Page Creation Settings -->
                <div class="aitrfoel-settings-section" id="section-page-creation">
                    <h3><?php esc_html_e('📄 Page Creation Settings', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_translated_page_status"><?php esc_html_e('Translated Page Status', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <select id="aitrfoel_translated_page_status" name="aitrfoel_translated_page_status">
                                    <option value="draft" <?php selected($translated_page_status, 'draft'); ?>>
                                        <?php esc_html_e('Draft (Recommended)', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </option>
                                    <option value="publish" <?php selected($translated_page_status, 'publish'); ?>>
                                        <?php esc_html_e('Published (Auto-publish)', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </option>
                                    <option value="inherit" <?php selected($translated_page_status, 'inherit'); ?>>
                                        <?php esc_html_e('Inherit from Original', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </option>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Status for newly created translated pages. Draft is recommended for review before publishing.', 'ai-translator-for-elementor-and-polylang'); ?>
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
                
                <!-- SEO Translation Settings -->
                <div class="aitrfoel-settings-section" id="section-seo">
                    <h3><?php esc_html_e('🔍 SEO Translation', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    
                    <?php
                    $seo_enabled = get_option('aitrfoel_seo_translation_enabled', true);
                    $seo_og = get_option('aitrfoel_seo_translate_og', true);
                    $seo_twitter = get_option('aitrfoel_seo_translate_twitter', true);
                    $has_yoast = defined('WPSEO_VERSION') || class_exists('WPSEO_Meta');
                    $has_rankmath = class_exists('RankMath') || defined('RANK_MATH_VERSION');
                    $seo_plugin = $has_yoast ? 'Yoast SEO' : ($has_rankmath ? 'Rank Math' : '');
                    ?>
                    
                    <?php if ($seo_plugin) : ?>
                        <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px;">
                            <span style="color: #166534;">✅ <strong><?php echo esc_html($seo_plugin); ?></strong> <?php esc_html_e('detected — SEO meta fields will be translated automatically.', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </div>
                    <?php else : ?>
                        <div style="background: #fefce8; border: 1px solid #fef08a; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px;">
                            <span style="color: #854d0e;">⚠️ <?php esc_html_e('No supported SEO plugin detected. Install Yoast SEO or Rank Math to enable SEO meta translation.', 'ai-translator-for-elementor-and-polylang'); ?></span>
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
                    
                    <p class="description" style="margin-top: 8px; margin-bottom: 0;">
                        💡 <?php esc_html_e('Supported plugins: Yoast SEO, Rank Math. Both are auto-detected at runtime.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                    <!-- Inline Save (after SEO / before WooCommerce) -->
                    <div class="aitrfoel-inline-save">
                        <?php submit_button(__('Save Settings', 'ai-translator-for-elementor-and-polylang'), 'primary', 'submit', false); ?>
                        <button type="button" class="button aitrfoel-reset-defaults" data-sections="seo" title="<?php esc_attr_e('Reset SEO settings to defaults', 'ai-translator-for-elementor-and-polylang'); ?>"><?php esc_html_e('Reset SEO Defaults', 'ai-translator-for-elementor-and-polylang'); ?></button>
                    </div>
                </div>

                <!-- WooCommerce Settings -->
                <div class="aitrfoel-settings-section" id="section-woocommerce">
                    <h3><?php esc_html_e('🛒 WooCommerce', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    
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
                        <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px;">
                            <span style="color: #166534;">✅ <strong>WooCommerce</strong> <?php esc_html_e('detected — product translation support is available.', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </div>
                    <?php else : ?>
                        <div style="background: #fefce8; border: 1px solid #fef08a; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px;">
                            <span style="color: #854d0e;">⚠️ <?php esc_html_e('WooCommerce is not active. These settings will apply when WooCommerce is installed and activated.', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </div>
                    <?php endif; ?>
                    
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
                    <!-- Inline Save (after WooCommerce) -->
                    <div class="aitrfoel-inline-save">
                        <?php submit_button(__('Save Settings', 'ai-translator-for-elementor-and-polylang'), 'primary', 'submit', false); ?>
                        <button type="button" class="button aitrfoel-reset-defaults" data-sections="woocommerce" title="<?php esc_attr_e('Reset WooCommerce settings to defaults', 'ai-translator-for-elementor-and-polylang'); ?>"><?php esc_html_e('Reset WooCommerce Defaults', 'ai-translator-for-elementor-and-polylang'); ?></button>
                    </div>
                </div>

                <!-- Exclude Words -->
                <div class="aitrfoel-settings-section" id="section-exclude-words">
                    <h3><?php esc_html_e('🚫 Exclude Words', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    
                    <p class="description" style="margin-bottom: 12px;">
                        <?php esc_html_e('Words or terms listed here will never be translated — they will be kept exactly as-is in every language. Use this for brand names, product names, technical terms, or any text that should remain untranslated.', 'ai-translator-for-elementor-and-polylang'); ?>
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
                                    <?php esc_html_e('Tip: You can also quickly add/remove words from the ⚙️ Settings button on the main plugin page.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Translation Glossary -->
                <div class="aitrfoel-settings-section" id="section-glossary">
                    <h3><?php esc_html_e('📖 Translation Glossary', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    
                    <p class="description" style="margin-bottom: 12px;">
                        <?php esc_html_e('Define terms that should always be translated a specific way. The AI will follow these rules for every translation to ensure consistency across your site.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel_glossary_terms"><?php esc_html_e('Glossary Terms', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <textarea name="aitrfoel_glossary_terms"
                                          id="aitrfoel_glossary_terms"
                                          class="large-text code"
                                          rows="10"
                                          placeholder="Dashboard = Tableau de bord (fr), Panel de control (es), Dashboard (de)
Website = Site web (fr), Sitio web (es), Webseite (de)
Contact Us = Contactez-nous (fr), Contáctenos (es)"><?php echo esc_textarea(get_option('aitrfoel_glossary_terms', '')); ?></textarea>
                                <p class="description">
                                    <?php esc_html_e('One term per line. Format:', 'ai-translator-for-elementor-and-polylang'); ?>
                                    <code>English Term = Translation (lang_code), Translation (lang_code)</code>
                                    <br>
                                    <?php esc_html_e('Example:', 'ai-translator-for-elementor-and-polylang'); ?>
                                    <code>Dashboard = Tableau de bord (fr), Panel de control (es)</code>
                                    <br>
                                    <?php esc_html_e('You can also use a simple format without language codes — the AI will apply the term to the closest matching language:', 'ai-translator-for-elementor-and-polylang'); ?>
                                    <code>Get Started = Commencer</code>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Theme Compatibility Settings -->
                <div class="aitrfoel-settings-section" id="section-theme">
                    <h3><?php esc_html_e('🎨 Theme Compatibility', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    
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
                                <button type="button" class="button" id="eat-diagnose-theme-btn">
                                    &#128269; <?php esc_html_e('Re-check Compatibility', 'ai-translator-for-elementor-and-polylang'); ?>
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
                    <h3><?php esc_html_e('🔧 Advanced Settings', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    
                    <table class="form-table">
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
                    </table>
                </div>

                <!-- Privacy & Data Collection Notice -->
                <div class="aitrfoel-settings-section" id="section-privacy" style="background: #f0f8ff; border-left: 4px solid #1447E6; padding: 20px; margin-top: 30px;">
                    <h3 style="margin-top: 0;">🔒 <?php esc_html_e('Privacy & Data Collection', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    
                    <p><?php esc_html_e('This plugin connects to our translation API at', 'ai-translator-for-elementor-and-polylang'); ?> <code>meeptech.com</code> <?php esc_html_e('to:', 'ai-translator-for-elementor-and-polylang'); ?></p>
                    
                    <ul style="list-style: disc; margin-left: 20px;">
                        <li>✅ <?php esc_html_e('Process AI-powered translations', 'ai-translator-for-elementor-and-polylang'); ?></li>
                        <li>✅ <?php esc_html_e('Track word usage for plan limits and licensing', 'ai-translator-for-elementor-and-polylang'); ?></li>
                        <li>✅ <?php esc_html_e('Enforce monthly word limits based on your plan', 'ai-translator-for-elementor-and-polylang'); ?></li>
                    </ul>
                    
                    <div style="background: white; padding: 15px; border-radius: 4px; margin: 15px 0;">
                        <p style="margin: 0 0 10px 0;"><strong><?php esc_html_e('Data sent to our server:', 'ai-translator-for-elementor-and-polylang'); ?></strong></p>
                        <ul style="list-style: disc; margin-left: 20px; margin-bottom: 10px;">
                            <li><?php esc_html_e('Your website URL (for service authentication)', 'ai-translator-for-elementor-and-polylang'); ?></li>
                            <li><?php esc_html_e('Word counts and language pairs (for usage tracking)', 'ai-translator-for-elementor-and-polylang'); ?></li>
                            <li><?php esc_html_e('License key (if applicable)', 'ai-translator-for-elementor-and-polylang'); ?></li>
                        </ul>
                        
                        <p style="margin: 10px 0 0 0;"><strong><?php esc_html_e('We DO NOT collect:', 'ai-translator-for-elementor-and-polylang'); ?></strong></p>
                        <ul style="list-style: disc; margin-left: 20px; margin-top: 5px;">
                            <li>❌ <?php esc_html_e('Your actual translated content', 'ai-translator-for-elementor-and-polylang'); ?></li>
                            <li>❌ <?php esc_html_e('Personal information or email addresses', 'ai-translator-for-elementor-and-polylang'); ?></li>
                            <li>❌ <?php esc_html_e('User or visitor data', 'ai-translator-for-elementor-and-polylang'); ?></li>
                            <li>❌ <?php esc_html_e('IP addresses (beyond standard server logs)', 'ai-translator-for-elementor-and-polylang'); ?></li>
                        </ul>
                    </div>
                    
                    <p style="margin-bottom: 0;">
                        <a href="https://meeptech.com/privacy-policy" target="_blank" rel="noopener noreferrer" class="button button-secondary">
                            📄 <?php esc_html_e('Privacy Policy', 'ai-translator-for-elementor-and-polylang'); ?>
                        </a>
                        <a href="https://meeptech.com/terms" target="_blank" rel="noopener noreferrer" class="button button-secondary">
                            📋 <?php esc_html_e('Terms of Service', 'ai-translator-for-elementor-and-polylang'); ?>
                        </a>
                    </p>
                    
                    <p class="description" style="margin-top: 15px;">
                        <strong><?php esc_html_e('Third-party services:', 'ai-translator-for-elementor-and-polylang'); ?></strong><br>
                        <?php esc_html_e('Content you translate is sent to OpenAI for translation processing. Review their', 'ai-translator-for-elementor-and-polylang'); ?> 
                        <a href="https://openai.com/policies/privacy-policy" target="_blank" rel="noopener noreferrer"><?php esc_html_e('privacy policy', 'ai-translator-for-elementor-and-polylang'); ?></a>.
                    </p>
                </div>

                <?php submit_button(__('Save Settings', 'ai-translator-for-elementor-and-polylang')); ?>
            </form>
            
            <!-- Reset Plugin Data (outside the form) -->
            <div class="aitrfoel-settings-section" style="background: #fff5f5; border: 1px solid #e2401c; border-radius: 6px; padding: 20px; margin-top: 30px;">
                <h3 style="margin-top: 0; color: #d63638;">⚠️ <?php esc_html_e('Reset Plugin Data', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                <p class="description" style="margin-bottom: 15px;">
                    <?php esc_html_e('This will reset all built-in language settings, disable the built-in system, and clear the onboarding state. Useful for re-testing the welcome screen and language setup. Your API key and other settings will be preserved. Translated pages will NOT be deleted.', 'ai-translator-for-elementor-and-polylang'); ?>
                </p>
                <button type="button" class="button button-link-delete" id="aitrfoel-reset-plugin-data" style="font-size: 13px; padding: 6px 16px;">
                    🗑️ <?php esc_html_e('Reset Plugin Data', 'ai-translator-for-elementor-and-polylang'); ?>
                </button>
                <span id="aitrfoel-reset-status" style="margin-left: 12px; display: none;"></span>
            </div>
            
            <script>
            jQuery(document).ready(function($) {
                $('#aitrfoel-reset-plugin-data').on('click', function() {
                    if (!confirm('<?php echo esc_js(__('Are you sure you want to reset all plugin data? This will:\n\n• Disable the built-in language system\n• Remove all configured languages\n• Reset the onboarding/welcome screen\n• Clear dependency status cache\n\nYour API key, translated pages, and other settings will be preserved.\n\nContinue?', 'ai-translator-for-elementor-and-polylang')); ?>')) {
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
                            nonce: '<?php echo wp_create_nonce('aitrfoel_reset_plugin_data'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $status.css('color', 'green').text('✓ ' + response.data.message).show();
                                setTimeout(function() { 
                                    window.location.href = '<?php echo esc_url(admin_url('admin.php?page=ai-translator-for-elementor-and-polylang')); ?>';
                                }, 1000);
                            } else {
                                $btn.prop('disabled', false).text('🗑️ <?php echo esc_js(__('Reset Plugin Data', 'ai-translator-for-elementor-and-polylang')); ?>');
                                $status.css('color', 'red').text('✗ ' + (response.data || 'Error')).show();
                            }
                        },
                        error: function() {
                            $btn.prop('disabled', false).text('🗑️ <?php echo esc_js(__('Reset Plugin Data', 'ai-translator-for-elementor-and-polylang')); ?>');
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
                            nonce: '<?php echo wp_create_nonce('aitrfoel_ajax_nonce'); ?>',
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
                                nonce: '<?php echo wp_create_nonce('aitrfoel_ajax_nonce'); ?>',
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
                    $arrow.toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
                });

                function tplLog(msg) {
                    var time = new Date().toLocaleTimeString();
                    $tplLog.append('<div>[' + time + '] ' + msg + '</div>');
                    $tplLog.scrollTop($tplLog[0].scrollHeight);
                }

                function translateTemplatesLang(lang, name, mode, force) {
                    return new Promise(function(resolve) {
                        $tplMsg.text('<?php echo esc_js(__('Translating templates for', 'ai-translator-for-elementor-and-polylang')); ?> ' + name + '...');
                        tplLog('→ <?php echo esc_js(__('Translating templates →', 'ai-translator-for-elementor-and-polylang')); ?> ' + name + (force ? ' (<?php echo esc_js(__('force re-translate', 'ai-translator-for-elementor-and-polylang')); ?>)' : ''));

                        var postData = {
                            action: 'aitrfoel_auto_translate_templates',
                            nonce: '<?php echo wp_create_nonce('aitrfoel_ajax_nonce'); ?>',
                            target_lang: lang,
                            mode: mode || 'active'
                        };
                        if (force) postData.force = 1;

                        $.post(ajaxurl, postData).done(function(r) {
                            if (r.success) {
                                tplLog('  ' + r.data.message);
                                if (r.data.details && r.data.details.length) {
                                    r.data.details.forEach(function(d) {
                                        tplLog('    ' + d);
                                    });
                                }
                            } else {
                                tplLog('  ⚠ ' + (r.data || '<?php echo esc_js(__('Error', 'ai-translator-for-elementor-and-polylang')); ?>'));
                            }
                            resolve();
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
                    var label = mode === 'all' ? '<?php echo esc_js(__('all', 'ai-translator-for-elementor-and-polylang')); ?>' : '<?php echo esc_js(__('active', 'ai-translator-for-elementor-and-polylang')); ?>';
                    if (!confirm('<?php echo esc_js(__('Translate', 'ai-translator-for-elementor-and-polylang')); ?> ' + label + ' <?php echo esc_js(__('Elementor templates for', 'ai-translator-for-elementor-and-polylang')); ?> ' + name + '? <?php echo esc_js(__('Uses translation credits.', 'ai-translator-for-elementor-and-polylang')); ?>')) return;
                    startTemplateTranslate([{code: lang, name: name}], mode);
                });

                $(document).on('click', '.aitrfoel-translate-tpl-all', function() {
                    var mode = $(this).data('mode') || 'active';
                    var langs = [];
                    // Gather language buttons from the same mode context
                    $('.aitrfoel-translate-tpl-lang[data-mode="' + (mode === 'all' ? 'active' : mode) + '"]').each(function() {
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
                    var label = mode === 'all' ? '<?php echo esc_js(__('ALL templates (including advanced)', 'ai-translator-for-elementor-and-polylang')); ?>' : '<?php echo esc_js(__('active templates', 'ai-translator-for-elementor-and-polylang')); ?>';
                    if (!confirm('<?php echo esc_js(__('Translate', 'ai-translator-for-elementor-and-polylang')); ?> ' + label + ' <?php echo esc_js(__('for ALL languages? Uses translation credits.', 'ai-translator-for-elementor-and-polylang')); ?>')) return;
                    startTemplateTranslate(langs, mode);
                });
            });
            </script>
            
            <!-- MeepTech Footer Branding -->
            <div class="aitrfoel-footer-branding">
                <a href="https://meeptech.com" target="_blank" rel="noopener noreferrer">
                    <img src="<?php echo esc_url(AITRFOEL_PLUGIN_URL . 'assets/img/meeptech.svg'); ?>" alt="Meep Tech" class="aitrfoel-footer-logo">
                </a>
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
            $table_name = $wpdb->prefix . 'aitrfoel_translation_cache';
            
            // Delete all cached translations
            $deleted = $wpdb->query("TRUNCATE TABLE {$table_name}");
            
            if ($deleted !== false) {
                // Also clear any transients related to translations
                delete_transient('aitrfoel_theme_status_report');
                
                wp_send_json_success([
                    'message' => __('Translation cache cleared successfully!', 'ai-translator-for-elementor-and-polylang')
                ]);
            } else {
                wp_send_json_error(__('Failed to clear cache. Please try again.', 'ai-translator-for-elementor-and-polylang'));
            }
            
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
        ?>
        <div id="aitrfoel-builtin-lang-management" style="margin-top: 15px; padding: 15px; background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 6px;">
            <h4 style="margin-top: 0;"><?php esc_html_e('Manage Languages', 'ai-translator-for-elementor-and-polylang'); ?></h4>

            <!-- Current Languages Table -->
            <style>
                #aitrfoel-builtin-languages-table th,
                #aitrfoel-builtin-languages-table td {
                    padding: 10px 12px;
                }
                #aitrfoel-builtin-languages-table .dashicons {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    width: 20px;
                    height: 20px;
                    font-size: 20px;
                    vertical-align: middle;
                }
                #aitrfoel-builtin-languages-table .button.button-small {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    min-height: 28px;
                    padding: 0 8px;
                    line-height: 1;
                }
                #aitrfoel-builtin-languages-table .button.button-small .dashicons {
                    margin: 0;
                    line-height: 1;
                }
            </style>
            <table class="widefat striped" id="aitrfoel-builtin-languages-table" style="margin-bottom: 15px;">
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
                            <td><code><?php echo esc_html(strtoupper($code)); ?></code></td>
                            <td>
                                <?php echo AITRFOEL_Language_Mapper::get_flag_html($code, 20, 'margin-right: 6px;'); ?>
                                <?php echo esc_html($lang_name); ?>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($code === $default_lang): ?>
                                    <span class="dashicons dashicons-star-filled" style="color: #f0b849; vertical-align: middle;"></span>
                                <?php else: ?>
                                    <button type="button" class="button button-small aitrfoel-set-default-lang" data-code="<?php echo esc_attr($code); ?>" title="<?php esc_attr_e('Set as default', 'ai-translator-for-elementor-and-polylang'); ?>">
                                        <span class="dashicons dashicons-star-empty" style="vertical-align: middle;"></span>
                                    </button>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($code !== $default_lang): ?>
                                    <button type="button" class="button button-small button-link-delete aitrfoel-remove-lang" data-code="<?php echo esc_attr($code); ?>">
                                        <?php esc_html_e('Remove', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </button>
                                <?php else: ?>
                                    <em style="color: #999;"><?php esc_html_e('Default', 'ai-translator-for-elementor-and-polylang'); ?></em>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Add Language -->
            <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 15px;">
                <select id="aitrfoel-add-lang-select" style="min-width: 220px;">
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

            <!-- Disable Built-in Option -->
            <div style="border-top: 1px solid #e0e0e0; padding-top: 12px; margin-top: 12px;">
                <button type="button" class="button button-link-delete" id="aitrfoel-disable-builtin">
                    <?php esc_html_e('Disable Built-in Language System', 'ai-translator-for-elementor-and-polylang'); ?>
                </button>
                <span style="color: #999; margin-left: 8px; font-size: 12px; vertical-align: middle;">
                    <?php esc_html_e('Switch to Polylang or WPML instead.', 'ai-translator-for-elementor-and-polylang'); ?>
                </span>
            </div>

            <!-- Status message -->
            <div id="aitrfoel-builtin-lang-status" style="margin-top: 10px; display: none;"></div>
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
                            $status.css('color', 'green').text('✓ ' + (resp.data.message || 'Done')).show();
                            if (callback) callback(resp);
                            setTimeout(function() { location.reload(); }, 800);
                        } else {
                            $status.css('color', 'red').text('✗ ' + (resp.data || 'Error')).show();
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

            // Set default
            $(document).on('click', '.aitrfoel-set-default-lang', function() {
                var code = $(this).data('code');
                $(this).prop('disabled', true);
                builtinLangAjax('set_default', code, '');
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

}

