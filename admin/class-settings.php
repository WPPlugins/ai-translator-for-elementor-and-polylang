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

        // Glossary settings
        register_setting('aitrfoel_settings_group', 'aitrfoel_glossary_terms', [
            'sanitize_callback' => [self::class, 'sanitize_glossary_terms'],
            'default' => ''
        ]);
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
                
                
                <!-- License Configuration -->
                <div class="aitrfoel-settings-section" id="license-settings">
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
                                    <button type="button" id="aitrfoel-sync-plan" class="button button-secondary" style="margin-left: 10px;">
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
                <div class="aitrfoel-settings-section">
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
                    </table>
                </div>
                
                <!-- Cache Settings -->
                <div class="aitrfoel-settings-section">
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
                </div>
                

                <!-- Page Creation Settings -->
                <div class="aitrfoel-settings-section">
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
                </div>
                
                <!-- Translation Glossary -->
                <div class="aitrfoel-settings-section">
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
                <div class="aitrfoel-settings-section">
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

                <!-- Language Switcher Settings -->
                <div class="aitrfoel-settings-section">
                    <h3><?php esc_html_e('🌍 Language Switcher', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    
                    <p class="description" style="margin-bottom: 15px; font-size: 13px;">
                        <?php esc_html_e('Configure the language switcher that visitors use to change the language on your site. You can add it using a shortcode, a WordPress widget, or a nav menu integration.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                    
                    <table class="form-table">
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
                                <label>
                                    <input type="checkbox" name="aitrfoel_switcher_show_flags" value="1" <?php checked($show_flags); ?> />
                                    <?php esc_html_e('Show country flags', 'ai-translator-for-elementor-and-polylang'); ?>
                                </label>
                                <br>
                                <label>
                                    <input type="checkbox" name="aitrfoel_switcher_show_names" value="1" <?php checked($show_names); ?> />
                                    <?php esc_html_e('Show language names', 'ai-translator-for-elementor-and-polylang'); ?>
                                </label>
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
                                    // Show a live preview of the language switcher
                                    if (class_exists('AITRFOEL_Builtin_Integration') && AITRFOEL_Builtin_Integration::is_enabled()) {
                                        $builtin = AITRFOEL_Builtin_Integration::get_instance();
                                        $switcher_data = $builtin->get_language_switcher_data();
                                        if (!empty($switcher_data)) {
                                            // Render inline preview
                                            echo '<div class="aitrfoel-switcher-preview">';
                                            foreach ($switcher_data as $lang) {
                                                $flag = AITRFOEL_Language_Mapper::get_flag_html($lang['code'], 20, 'margin-right: 4px;');
                                                echo '<span style="display: inline-flex; align-items: center; padding: 4px 8px; margin-right: 6px; border: 1px solid #ddd; border-radius: 4px;">';
                                                echo $flag . ' <span style="font-size: 13px;">' . esc_html(strtoupper($lang['code'])) . '</span>';
                                                echo '</span>';
                                            }
                                            echo '</div>';
                                        } else {
                                            echo '<p style="color: #666; margin: 0;">' . esc_html__('No languages configured yet. Add languages in the Multilingual Plugin section above.', 'ai-translator-for-elementor-and-polylang') . '</p>';
                                        }
                                    } elseif (function_exists('pll_languages_list')) {
                                        $lang_codes = pll_languages_list(['fields' => 'slug']);
                                        echo '<div class="aitrfoel-switcher-preview">';
                                        foreach ($lang_codes as $lc) {
                                            $flag = AITRFOEL_Language_Mapper::get_flag_html($lc, 20, 'margin-right: 4px;');
                                            echo '<span style="display: inline-flex; align-items: center; padding: 4px 8px; margin-right: 6px; border: 1px solid #ddd; border-radius: 4px;">';
                                            echo $flag . ' <span style="font-size: 13px;">' . esc_html(strtoupper($lc)) . '</span>';
                                            echo '</span>';
                                        }
                                        echo '</div>';
                                    } else {
                                        echo '<p style="color: #666; margin: 0;">' . esc_html__('No multilingual plugin active. Configure one first.', 'ai-translator-for-elementor-and-polylang') . '</p>';
                                    }
                                    ?>
                                </div>
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
                </div>

                <!-- Advanced / Debug Settings -->
                <div class="aitrfoel-settings-section">
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
                <div class="aitrfoel-settings-section" style="background: #f0f8ff; border-left: 4px solid #2271b1; padding: 20px; margin-top: 30px;">
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
            });
            </script>
        </div>
        
        <?php
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

            function builtinLangAjax(operation, code, callback) {
                var $status = $('#aitrfoel-builtin-lang-status');
                $status.hide();
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aitrfoel_manage_builtin_language',
                        nonce: nonce,
                        operation: operation,
                        language_code: code || ''
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
                var code = $('#aitrfoel-add-lang-select').val();
                if (!code) return;
                $(this).prop('disabled', true);
                builtinLangAjax('add', code);
            });

            // Remove language
            $(document).on('click', '.aitrfoel-remove-lang', function() {
                var code = $(this).data('code');
                if (!confirm('<?php echo esc_js(__('Remove this language? Existing translations in this language will remain but will no longer be managed.', 'ai-translator-for-elementor-and-polylang')); ?>')) return;
                $(this).prop('disabled', true);
                builtinLangAjax('remove', code);
            });

            // Set default
            $(document).on('click', '.aitrfoel-set-default-lang', function() {
                var code = $(this).data('code');
                $(this).prop('disabled', true);
                builtinLangAjax('set_default', code);
            });

            // Disable built-in
            $('#aitrfoel-disable-builtin').on('click', function() {
                if (!confirm('<?php echo esc_js(__('Are you sure you want to disable the built-in language system? You will need Polylang or WPML to manage translations.', 'ai-translator-for-elementor-and-polylang')); ?>')) return;
                $(this).prop('disabled', true);
                builtinLangAjax('disable', '');
            });
        });
        </script>
        <?php
    }

}

