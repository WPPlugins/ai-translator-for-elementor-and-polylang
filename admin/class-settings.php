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
    }
    
    public static function render_page() {
        $license_key = get_option('aitrfoel_license_key', '');
        $license_status = 'Free';
        if (function_exists('aitrfoel_fs') && aitrfoel_fs()->is_registered()) {
            $plan = aitrfoel_fs()->get_plan();
            $license_status = $plan ? $plan->name : 'Free';
        }
        // Get usage from server (not local cache)
        $license_manager = AITRFOEL_License_Manager_Server::get_instance();
        $license_info = $license_manager->get_license_status();
        $words_used = $license_info['words_used'];
        $word_limit = $license_info['word_limit'];
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
                                    
                                    // Check for Polylang directly
                                    if (function_exists('pll_languages_list') && function_exists('pll_default_language')) {
                                        $has_plugin = true;
                                        $plugin_name = 'Polylang';
                                        $is_polylang = true;
                                        $default_lang = pll_default_language('slug');
                                        $lang_codes = pll_languages_list(['fields' => 'slug']);
                                    }
                                    // Check for WPML directly
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
                                        $icon = $is_polylang ? '🦜' : '🌍';
                                        $lang_count = is_array($lang_codes) ? count($lang_codes) : 0;
                                        
                                        // Get the language settings URL for the active plugin
                                        $manage_languages_url = '';
                                        if ($is_polylang) {
                                            $manage_languages_url = admin_url('admin.php?page=mlang');
                                        } else {
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
                                                <?php echo esc_html(strtoupper($default_lang)); ?>
                                                <br>
                                                <strong><?php esc_html_e('Available Languages:', 'ai-translator-for-elementor-and-polylang'); ?></strong> 
                                                <?php echo esc_html($lang_count); ?> 
                                                (<?php echo esc_html(implode(', ', array_map('strtoupper', $lang_codes))); ?>)
                                            </p>
                                            <p style="margin-top: 10px;">
                                                <a href="<?php echo esc_url($manage_languages_url); ?>" class="button button-secondary">
                                                    <?php esc_html_e('Manage Languages', 'ai-translator-for-elementor-and-polylang'); ?>
                                                </a>
                                            </p>
                                        </div>
                                        <?php
                                    } else {
                                        ?>
                                        <div class="aitrfoel-multilingual-info">
                                            <span class="aitrfoel-plugin-badge aitrfoel-plugin-missing">
                                                ⚠️ <?php esc_html_e('No multilingual plugin detected', 'ai-translator-for-elementor-and-polylang'); ?>
                                            </span>
                                            <p class="description" style="margin-top: 8px;">
                                                <?php esc_html_e('Please install and activate either Polylang or WPML to use translation features.', 'ai-translator-for-elementor-and-polylang'); ?>
                                                <br>
                                                <a href="https://wordpress.org/plugins/polylang/" target="_blank">Polylang</a> | 
                                                <a href="https://wpml.org/" target="_blank">WPML</a>
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
        </div>
        
        <?php
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

}

