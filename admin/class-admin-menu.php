<?php
/**
 * Admin Menu Class
 * Handles admin interface and menu pages
 */

if (!defined('ABSPATH')) exit;
 
class AITRFOEL_Admin_Menu {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Use priority 5 to run before Freemius (default is 10)
        // This allows Freemius to inject its Account/Pricing pages after our menu
        add_action('admin_menu', [$this, 'add_admin_menu'], 5);
        
        // Register setup checklist as a WordPress dashboard widget
        add_action('wp_dashboard_setup', [$this, 'register_dashboard_widget']);
        
        // Hide Elementor admin notices on our plugin pages
        add_action('admin_head', [$this, 'hide_elementor_notices_on_our_pages']);
        
        // AJAX handler for built-in language setup (must work even without deps loaded)
        // Delegated to AITRFOEL_Wizard_Controller — see admin/class-wizard-controller.php
        add_action('wp_ajax_aitrfoel_manage_builtin_language', [$this, 'handle_manage_builtin_language']);
        add_action('wp_ajax_aitrfoel_save_analytics_optin', [$this, 'handle_save_analytics_optin']);
        add_action('wp_ajax_aitrfoel_dismiss_setup_checklist', [$this, 'handle_dismiss_setup_checklist']);
        add_action('wp_ajax_aitrfoel_skip_setup_step', [$this, 'handle_skip_setup_step']);
    }

    /**
     * Hide Elementor admin notices (e.g. Data Updater) on our plugin pages.
     * Elementor uses .e-notice which is not caught by standard WP notice hiding.
     */
    public function hide_elementor_notices_on_our_pages() {
        $screen = get_current_screen();
        if (!$screen) return;
        
        // Check if we're on one of our plugin's admin pages
        if (strpos($screen->id, 'ai-translator-for-elementor') === false) return;
        
        echo '<style>.e-notice, .e-notice--warning, .e-notice--dismissible, .elementor-message { display: none !important; }</style>';
    }

    public function add_admin_menu() {
        // Create main menu page
        add_menu_page(
            __('AI Translator', 'ai-translator-for-elementor-and-polylang'),
            __('AI Translator', 'ai-translator-for-elementor-and-polylang'),
            'manage_options',
            'ai-translator-for-elementor-and-polylang',
            [$this, 'render_dashboard_page'],
            'dashicons-translation',
            30
        );

        // Add "Translate All" submenu (2nd item)
        add_submenu_page(
            'ai-translator-for-elementor-and-polylang',
            __('Translate All', 'ai-translator-for-elementor-and-polylang'),
            __('Translate All', 'ai-translator-for-elementor-and-polylang'),
            'manage_options',
            'ai-translator-for-elementor-and-polylang-translate-all',
            [$this, 'render_translate_all_page']
        );

        // Add Settings submenu (3rd item)
        add_submenu_page(
            'ai-translator-for-elementor-and-polylang',
            __('Settings', 'ai-translator-for-elementor-and-polylang'),
            __('Settings', 'ai-translator-for-elementor-and-polylang'),
            'manage_options',
            'ai-translator-for-elementor-and-polylang-settings',
            ['AITRFOEL_Settings', 'render_page']
        );

        // Add History submenu
        add_submenu_page(
            'ai-translator-for-elementor-and-polylang',
            __('History', 'ai-translator-for-elementor-and-polylang'),
            __('History', 'ai-translator-for-elementor-and-polylang'),
            'manage_options',
            'ai-translator-for-elementor-and-polylang-history',
            [$this, 'render_history_page']
        );

        // Add "Activate License" submenu for PRO version (always visible, even before opt-in)
        if (defined('AITRFOEL_VERSION_TYPE') && AITRFOEL_VERSION_TYPE === 'pro') {
            add_submenu_page(
                'ai-translator-for-elementor-and-polylang',
                __('Activate License', 'ai-translator-for-elementor-and-polylang'),
                __('Activate License', 'ai-translator-for-elementor-and-polylang'),
                'manage_options',
                'ai-translator-for-elementor-and-polylang-activate-license',
                [$this, 'render_activate_license_page']
            );
        }
        
        // Freemius will automatically add its Account, Pricing, etc. submenus after this
    }

    /**
     * Render History page (delegates to dedicated class)
     */
    public function render_history_page() {
        if (class_exists('AITRFOEL_Translation_History')) {
            AITRFOEL_Translation_History::get_instance()->render_page();
        }
    }

    /**
     * Render Translate All page (delegates to dedicated class)
     */
    public function render_translate_all_page() {
        if (class_exists('AITRFOEL_Translate_All')) {
            AITRFOEL_Translate_All::get_instance()->render_page();
        }
    }
    
    /**
     * Render license activation page
     * This provides a way to enter license key without reactivating the plugin
     */
    public function render_activate_license_page() {
        // Handle license activation form submission
        if (isset($_POST['aitrfoel_activate_license']) && isset($_POST['license_key'])) {
            check_admin_referer('aitrfoel_activate_license_nonce');
            $license_key = sanitize_text_field($_POST['license_key']);
            
            if (!empty($license_key) && function_exists('eatp_fs')) {
                // Try to activate the license using Freemius
                try {
                    $result = eatp_fs()->activate_migrated_license($license_key);
                    
                    // activate_migrated_license returns an array, not an object
                    if (is_array($result) && isset($result['error'])) {
                        $error_message = is_string($result['error']) ? $result['error'] : __('License activation failed.', 'ai-translator-for-elementor-and-polylang');
                    } elseif (is_object($result) && isset($result->error)) {
                        $error_message = isset($result->error->message) ? $result->error->message : __('License activation failed.', 'ai-translator-for-elementor-and-polylang');
                    } else {
                        // Check if pending email confirmation
                        if (function_exists('eatp_fs') && method_exists(eatp_fs(), 'is_pending_activation') && eatp_fs()->is_pending_activation()) {
                            // Redirect to main page with pending notice
                            wp_redirect(admin_url('admin.php?page=ai-translator-for-elementor-and-polylang&license_pending=true'));
                            exit;
                        }
                        
                        // Success - use Freemius-provided next_page URL if available, otherwise redirect to main plugin page
                        $redirect_url = admin_url('admin.php?page=ai-translator-for-elementor-and-polylang&activated=true');
                        if (is_array($result) && !empty($result['next_page'])) {
                            $redirect_url = $result['next_page'];
                        }
                        wp_redirect($redirect_url);
                        exit;
                    }
                } catch (Exception $e) {
                    $error_message = $e->getMessage();
                }
            } else {
                $error_message = __('Please enter a valid license key.', 'ai-translator-for-elementor-and-polylang');
            }
        }
        
        // Check current license status
        $current_license = null;
        $is_paying = false;
        if (function_exists('eatp_fs')) {
            $current_license = eatp_fs()->_get_license();
            $is_paying = eatp_fs()->is_paying();
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Activate License', 'ai-translator-for-elementor-and-polylang'); ?></h1>
            
            <?php if (isset($error_message)): ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html($error_message); ?></p>
                </div>
            <?php endif; ?>
            
            <?php if (is_object($current_license) && !$current_license->is_expired()): ?>
                <div class="notice notice-success">
                    <p><strong><?php esc_html_e('License Status: Active', 'ai-translator-for-elementor-and-polylang'); ?></strong></p>
                    <?php if (!empty($current_license->expiration)): ?>
                    <p><?php esc_html_e('Expires:', 'ai-translator-for-elementor-and-polylang'); ?> 
                    <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($current_license->expiration))); ?></p>
                    <?php endif; ?>
                </div>
            <?php elseif (is_object($current_license) && $current_license->is_expired()): ?>
                <div class="notice notice-warning">
                    <p><strong><?php esc_html_e('License Status: Expired', 'ai-translator-for-elementor-and-polylang'); ?></strong></p>
                    <p><?php esc_html_e('Please renew your license or enter a new license key below.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                </div>
            <?php endif; ?>
            
            <div class="card" style="max-width: 600px; padding: 20px;">
                <h2><?php esc_html_e('Enter Your License Key', 'ai-translator-for-elementor-and-polylang'); ?></h2>
                <p><?php esc_html_e('Enter the license key you received after purchasing the PRO version:', 'ai-translator-for-elementor-and-polylang'); ?></p>
                
                <form method="post" action="">
                    <?php wp_nonce_field('aitrfoel_activate_license_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="license_key"><?php esc_html_e('License Key', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <input type="text" name="license_key" id="license_key" class="regular-text" 
                                       placeholder="sk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" 
                                       style="width: 100%; max-width: 400px; font-family: monospace;">
                                <p class="description">
                                    <?php esc_html_e('Your license key was sent to your email after purchase.', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" name="aitrfoel_activate_license" class="button button-primary" 
                               value="<?php esc_attr_e('Activate License', 'ai-translator-for-elementor-and-polylang'); ?>">
                        
                        <?php if (function_exists('eatp_fs') && eatp_fs()->is_registered()): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=ai-translator-for-elementor-and-polylang-account')); ?>" class="button">
                            <?php esc_html_e('Go to Account', 'ai-translator-for-elementor-and-polylang'); ?>
                        </a>
                        <?php endif; ?>
                    </p>
                </form>
            </div>
            
            <div class="card" style="max-width: 600px; padding: 20px; margin-top: 20px;">
                <h3><?php esc_html_e('Need Help?', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><?php esc_html_e('Check your email for the license key (it was sent after purchase)', 'ai-translator-for-elementor-and-polylang'); ?></li>
                    <li><?php esc_html_e('Make sure you\'re using the PRO version of the plugin', 'ai-translator-for-elementor-and-polylang'); ?></li>
                    <li><?php esc_html_e('The license key starts with "sk_" followed by 32 characters', 'ai-translator-for-elementor-and-polylang'); ?></li>
                    <li><a href="https://meeptech.com/support/" target="_blank"><?php esc_html_e('Contact support if you need assistance', 'ai-translator-for-elementor-and-polylang'); ?></a></li>
                </ul>
            </div>
            
            <?php if (!function_exists('eatp_fs') || !eatp_fs()->is_registered()): ?>
            <div class="card" style="max-width: 600px; padding: 20px; margin-top: 20px; background: #fff3cd;">
                <h3><?php esc_html_e('Alternative Method', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                <p><?php esc_html_e('If direct activation doesn\'t work, you can also:', 'ai-translator-for-elementor-and-polylang'); ?></p>
                <ol>
                    <li><?php esc_html_e('Go to Plugins → Installed Plugins', 'ai-translator-for-elementor-and-polylang'); ?></li>
                    <li><?php esc_html_e('Deactivate "Meep AI Translator"', 'ai-translator-for-elementor-and-polylang'); ?></li>
                    <li><?php esc_html_e('Activate it again - the license activation dialog will appear', 'ai-translator-for-elementor-and-polylang'); ?></li>
                </ol>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_main_page() {
        // Check if OpenAI API key is set
        $api_key = get_option('aitrfoel_openai_api_key');
        
        if (empty($api_key)) {
            $this->render_setup_notice();
            return;
        }
        
        // Get pages with Elementor data
        $pages = $this->get_elementor_pages();
        $languages = $this->get_languages();
        
        // Get stats from OpenAI API
        $api = AITRFOEL_OpenAI_API::get_instance();
        $stats = $api->get_usage_stats();
        ?>
        
        <div class="wrap aitrfoel-admin-wrapper">
            <h1><?php esc_html_e('Meep AI Translator', 'ai-translator-for-elementor-and-polylang'); ?></h1>
            
            <!-- Stats Dashboard -->
            <div class="aitrfoel-stats-grid">
                <div class="aitrfoel-stat-card aitrfoel-stat-flex">
                    <div class="aitrfoel-stat-label"><?php esc_html_e('Cached Translations', 'ai-translator-for-elementor-and-polylang'); ?></div>
                    <div class="aitrfoel-stat-number"><?php echo number_format($stats['cached_translations']); ?></div>
                </div>
                <div class="aitrfoel-stat-card aitrfoel-stat-flex">
                    <div class="aitrfoel-stat-label"><?php esc_html_e('Recent API Calls', 'ai-translator-for-elementor-and-polylang'); ?></div>
                    <div class="aitrfoel-stat-number"><?php echo number_format($stats['recent_api_calls']); ?></div>
                </div>
                <div class="aitrfoel-stat-card aitrfoel-stat-flex">
                    <div class="aitrfoel-stat-label"><?php esc_html_e('Active Model', 'ai-translator-for-elementor-and-polylang'); ?></div>
                    <div class="aitrfoel-stat-number" style="font-size: 1.2em;"><?php echo esc_html($stats['model']); ?></div>
                </div>
                <div class="aitrfoel-stat-card aitrfoel-stat-flex">
                    <div class="aitrfoel-stat-label"><?php esc_html_e('Pages Available', 'ai-translator-for-elementor-and-polylang'); ?></div>
                    <div class="aitrfoel-stat-number"><?php echo count($pages); ?></div>
                </div>
            </div>
            
            <!-- Translation Interface -->
            <div class="aitrfoel-card" id="aitrfoel-translator-app">
                <h2><?php esc_html_e('Translate Content', 'ai-translator-for-elementor-and-polylang'); ?></h2>
                
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel-page-select"><?php esc_html_e('Select Page', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <select id="aitrfoel-page-select" style="width: 100%; max-width: 500px;">
                                    <option value=""><?php esc_html_e('-- Select a Page --', 'ai-translator-for-elementor-and-polylang'); ?></option>
                                    <?php foreach ($pages as $page) : ?>
                                        <option value="<?php echo absint($page->ID); ?>">
                                            <?php echo esc_html($page->post_title); ?>
                                            (ID: <?php echo absint($page->ID); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Only pages with Elementor content are shown', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel-source-lang"><?php esc_html_e('Source Language', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <select id="aitrfoel-source-lang" style="width: 100%; max-width: 500px;">
                                    <option value="auto"><?php esc_html_e('Auto-detect', 'ai-translator-for-elementor-and-polylang'); ?></option>
                                    <?php foreach ($languages as $code => $name) : ?>
                                        <option value="<?php echo esc_attr($code); ?>" <?php selected($code, 'English'); ?>>
                                            <?php echo esc_html($name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Leave as auto-detect for automatic language detection', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="aitrfoel-target-lang"><?php esc_html_e('Target Language', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <select id="aitrfoel-target-lang" style="width: 100%; max-width: 500px;">
                                    <?php foreach ($languages as $code => $name) : ?>
                                        <option value="<?php echo esc_attr($code); ?>">
                                            <?php echo esc_html($name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <!-- String Selection Area -->
                <div id="aitrfoel-string-selection-area" style="display: none;">
                    <h3><?php esc_html_e('Select Content to Translate', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    <p>
                        <label>
                            <input type="checkbox" id="aitrfoel-select-all" />
                            <strong><?php esc_html_e('Select All', 'ai-translator-for-elementor-and-polylang'); ?></strong>
                        </label>
                        <span style="margin-left: 20px; color: #666;">
                            <?php esc_html_e('Total strings:', 'ai-translator-for-elementor-and-polylang'); ?>
                            <span id="aitrfoel-string-count">0</span>
                        </span>
                    </p>
                    <div id="aitrfoel-string-list"></div>
                </div>
                
                <!-- Action Buttons -->
                <div class="aitrfoel-button-group">
                    <button id="aitrfoel-fetch-strings-button" class="button button-primary button-large">
                        <?php esc_html_e('Fetch Content', 'ai-translator-for-elementor-and-polylang'); ?>
                    </button>
                    <button id="aitrfoel-estimate-cost-btn" class="button button-secondary button-large">
                        <?php esc_html_e('💰 Estimate Cost', 'ai-translator-for-elementor-and-polylang'); ?>
                    </button>
                    <button id="aitrfoel-translate-button" class="button button-primary button-large" style="display: none;">
                        <?php esc_html_e('Translate Selected', 'ai-translator-for-elementor-and-polylang'); ?>
                    </button>
                    <button id="aitrfoel-clear-cache-button" class="button button-secondary">
                        <?php esc_html_e('Clear Translation Cache', 'ai-translator-for-elementor-and-polylang'); ?>
                    </button>
                    <button id="aitrfoel-reset-button" class="button button-secondary" style="display: none;">
                        <?php esc_html_e('Reset', 'ai-translator-for-elementor-and-polylang'); ?>
                    </button>
                    <span class="spinner"></span>
                </div>
                
                <!-- Cost Estimation Area -->
                <div id="aitrfoel-cost-estimation-area"></div>
                
                <!-- Usage Statistics -->
                <div id="aitrfoel-usage-stats-container">
                    <div class="aitrfoel-usage-stats-header">
                        <h3><?php esc_html_e('Monthly Usage Statistics', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                        <button id="aitrfoel-refresh-stats-btn" class="button button-small">
                            <?php esc_html_e('🔄 Refresh Stats', 'ai-translator-for-elementor-and-polylang'); ?>
                        </button>
                    </div>
                    <div id="aitrfoel-usage-stats-display"></div>
                </div>
                
                <!-- Status Messages -->
                <div id="aitrfoel-status"></div>
            </div>
            
            <!-- Information & Review Section -->
            <div class="aitrfoel-card aitrfoel-info-review-card">
                <div class="aitrfoel-info-review-grid">
                    <!-- Left Column: Information -->
                    <div class="aitrfoel-info-column">
                        <h3><?php esc_html_e('Information', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                        <div class="aitrfoel-info-content">
                            <p><?php esc_html_e('Translation engine is not ideal - errors can be spotted in translated pages. We are constantly updating algorithm to provide you with the best experience. If you spot a bug in translation contact us via feedback form.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                            <div class="aitrfoel-info-buttons">
                                <a href="https://meeptech.com/contact" target="_blank" class="button button-secondary">
                                    <?php esc_html_e('Feedback Form', 'ai-translator-for-elementor-and-polylang'); ?>
                                </a>
                                <a href="<?php echo (function_exists('eatp_fs') && eatp_fs()->is_registered()) ? esc_url(eatp_fs()->get_upgrade_url()) : esc_url('https://meeptech.com/products/ai-translator-for-elementor-polylang/#pricing'); ?>" class="button button-primary">
                                    <?php esc_html_e('Upgrade Plan', 'ai-translator-for-elementor-and-polylang'); ?>
                                </a>
                                <a href="https://meeptech.com/products/ai-translator-for-elementor-polylang/documentation" target="_blank" class="button button-secondary">
                                    <?php esc_html_e('Documentation', 'ai-translator-for-elementor-and-polylang'); ?>
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right Column: Review Request -->
                    <div class="aitrfoel-review-column">
                        <h3><?php esc_html_e('Rate Our Plugin', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                        <div class="aitrfoel-review-content">
                            <p><?php esc_html_e('If you enjoy using Meep AI Translator, please take a moment to rate us on WordPress.org! Your feedback helps us improve the plugin and reach more users who could benefit from it.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                            <p style="margin: 0 0 10px 0; color: #f5b301; font-size: 18px; letter-spacing: 2px;" aria-hidden="true">★★★★★</p>
                            <span class="screen-reader-text"><?php esc_html_e('Rated 5 out of 5 on WordPress.org', 'ai-translator-for-elementor-and-polylang'); ?></span>
                            <a href="https://wordpress.org/support/plugin/ai-translator-for-elementor-and-polylang/reviews/#new-post" target="_blank" class="button button-primary">
                                <?php esc_html_e('Leave a Review', 'ai-translator-for-elementor-and-polylang'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render setup notice for missing API key
     */
    private function render_setup_notice() {
        ?>
        <div class="wrap aitrfoel-admin-wrapper">
            <?php $this->render_plugin_header(__('Setup', 'ai-translator-for-elementor-and-polylang')); ?>
            
            <div class="aitrfoel-card" style="border-left: 4px solid #ff9800;">
                <h2><?php esc_html_e('⚠️ API Key Required', 'ai-translator-for-elementor-and-polylang'); ?></h2>
                <p><?php esc_html_e('To start translating your Elementor content, you need to configure your OpenAI API key.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                <p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ai-translator-for-elementor-and-polylang-settings')); ?>" class="button button-primary button-large">
                        <?php esc_html_e('Configure API Key', 'ai-translator-for-elementor-and-polylang'); ?>
                    </a>
                </p>
                
                <h3><?php esc_html_e('How to get your API key:', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                <ol>
                    <li><?php esc_html_e('Visit', 'ai-translator-for-elementor-and-polylang'); ?> <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI API Keys</a></li>
                    <li><?php esc_html_e('Sign in with your OpenAI account', 'ai-translator-for-elementor-and-polylang'); ?></li>
                    <li><?php esc_html_e('Click "Create new secret key"', 'ai-translator-for-elementor-and-polylang'); ?></li>
                    <li><?php esc_html_e('Copy the key and paste it in the settings page', 'ai-translator-for-elementor-and-polylang'); ?></li>
                </ol>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get pages with Elementor data
     */
    private function get_elementor_pages() {
        $args = [
            'post_type' => ['page', 'post'],
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [
                [
                    'key' => '_elementor_data',
                    'compare' => 'EXISTS'
                ]
            ],
            'orderby' => 'title',
            'order' => 'ASC'
        ];
        
        return get_posts($args);
    }
    
    /**
     * Get available languages
     */
    private function get_languages() {
        return [
            'English' => __('English', 'ai-translator-for-elementor-and-polylang'),
            'Spanish' => __('Spanish', 'ai-translator-for-elementor-and-polylang'),
            'French' => __('French', 'ai-translator-for-elementor-and-polylang'),
            'German' => __('German', 'ai-translator-for-elementor-and-polylang'),
            'Italian' => __('Italian', 'ai-translator-for-elementor-and-polylang'),
            'Portuguese' => __('Portuguese', 'ai-translator-for-elementor-and-polylang'),
            'Russian' => __('Russian', 'ai-translator-for-elementor-and-polylang'),
            'Ukrainian' => __('Ukrainian', 'ai-translator-for-elementor-and-polylang'),
            'Belarusian' => __('Belarusian', 'ai-translator-for-elementor-and-polylang'),
            'Czech' => __('Czech', 'ai-translator-for-elementor-and-polylang'),
            'Polish' => __('Polish', 'ai-translator-for-elementor-and-polylang'),
            'Vietnamese' => __('Vietnamese', 'ai-translator-for-elementor-and-polylang'),
            'Chinese' => __('Chinese (Simplified)', 'ai-translator-for-elementor-and-polylang'),
            'Japanese' => __('Japanese', 'ai-translator-for-elementor-and-polylang'),
            'Korean' => __('Korean', 'ai-translator-for-elementor-and-polylang'),
            'Arabic' => __('Arabic', 'ai-translator-for-elementor-and-polylang'),
            'Hebrew' => __('Hebrew', 'ai-translator-for-elementor-and-polylang'),
            'Hindi' => __('Hindi', 'ai-translator-for-elementor-and-polylang'),
            'Turkish' => __('Turkish', 'ai-translator-for-elementor-and-polylang'),
            'Dutch' => __('Dutch', 'ai-translator-for-elementor-and-polylang'),
            'Swedish' => __('Swedish', 'ai-translator-for-elementor-and-polylang'),
            'Norwegian' => __('Norwegian', 'ai-translator-for-elementor-and-polylang'),
            'Danish' => __('Danish', 'ai-translator-for-elementor-and-polylang'),
            'Finnish' => __('Finnish', 'ai-translator-for-elementor-and-polylang'),
        ];
    }
    
    /**
     * Render modern multi-step onboarding wizard
     * Steps: Welcome → Installing → Add Languages → Finish
     */
    private function render_multilingual_onboarding_screen() {
        // Enqueue wizard CSS (meep-admin is already loaded globally)
        wp_enqueue_style(
            'aitrfoel-onboarding-wizard',
            AITRFOEL_PLUGIN_URL . 'assets/css/onboarding-wizard.css',
            ['aitrfoel-meep-admin'],
            defined('AITRFOEL_VERSION') ? AITRFOEL_VERSION : '1.3.2'
        );
        
        // Get available languages from language mapper
        $available_languages = [];
        if (class_exists('AITRFOEL_Language_Mapper')) {
            $mappings = AITRFOEL_Language_Mapper::get_all_mappings();
            foreach ($mappings as $code => $name) {
                $available_languages[] = ['code' => $code, 'name' => $name];
            }
        } else {
            $available_languages = [
                ['code' => 'en', 'name' => 'English'],
                ['code' => 'es', 'name' => 'Spanish'],
                ['code' => 'fr', 'name' => 'French'],
                ['code' => 'de', 'name' => 'German'],
                ['code' => 'it', 'name' => 'Italian'],
                ['code' => 'pt', 'name' => 'Portuguese'],
                ['code' => 'nl', 'name' => 'Dutch'],
                ['code' => 'pl', 'name' => 'Polish'],
                ['code' => 'ru', 'name' => 'Russian'],
                ['code' => 'ja', 'name' => 'Japanese'],
                ['code' => 'zh', 'name' => 'Chinese'],
                ['code' => 'ko', 'name' => 'Korean'],
                ['code' => 'ar', 'name' => 'Arabic'],
                ['code' => 'tr', 'name' => 'Turkish'],
                ['code' => 'hi', 'name' => 'Hindi'],
            ];
        }
        usort($available_languages, function($a, $b) { return strcmp($a['name'], $b['name']); });
        
        // Popular languages with SVG flag data (ISO 3166-1 alpha-2 country codes for flags)
        $popular_languages = [
            ['code' => 'es', 'name' => 'Spanish',    'flag' => 'es'],
            ['code' => 'fr', 'name' => 'French',     'flag' => 'fr'],
            ['code' => 'de', 'name' => 'German',     'flag' => 'de'],
            ['code' => 'it', 'name' => 'Italian',    'flag' => 'it'],
            ['code' => 'pt', 'name' => 'Portuguese', 'flag' => 'pt'],
            ['code' => 'nl', 'name' => 'Dutch',      'flag' => 'nl'],
            ['code' => 'pl', 'name' => 'Polish',     'flag' => 'pl'],
            ['code' => 'ru', 'name' => 'Russian',    'flag' => 'ru'],
            ['code' => 'ja', 'name' => 'Japanese',   'flag' => 'jp'],
            ['code' => 'zh', 'name' => 'Chinese',    'flag' => 'cn'],
            ['code' => 'ko', 'name' => 'Korean',     'flag' => 'kr'],
            ['code' => 'ar', 'name' => 'Arabic',     'flag' => 'sa'],
            ['code' => 'tr', 'name' => 'Turkish',    'flag' => 'tr'],
            ['code' => 'hi', 'name' => 'Hindi',      'flag' => 'in'],
            ['code' => 'sv', 'name' => 'Swedish',    'flag' => 'se'],
            ['code' => 'uk', 'name' => 'Ukrainian',  'flag' => 'ua'],
        ];
        
        // Check if Polylang or WPML was previously installed (offer import notice)
        $polylang_was_active = get_option('polylang', false) || get_option('polylang_wpml_strings', false);
        $wpml_was_active = get_option('icl_sitepress_settings', false);
        $had_previous_plugin = $polylang_was_active || $wpml_was_active;
        $previous_plugin_name = $polylang_was_active ? 'Polylang' : ($wpml_was_active ? 'WPML' : '');
        
        // Detect languages from Polylang/WPML for auto-import
        $detected_languages = [];
        $detected_default_lang = '';
        
        // Try Polylang (active — functions available)
        if (function_exists('pll_languages_list') && function_exists('pll_default_language')) {
            $pll_codes = pll_languages_list(['fields' => 'slug']);
            $detected_default_lang = pll_default_language('slug') ?: '';
            if (is_array($pll_codes)) {
                foreach ($pll_codes as $code) {
                    $name = '';
                    if (class_exists('AITRFOEL_Language_Mapper')) {
                        $name = AITRFOEL_Language_Mapper::get_language_name($code, false);
                    }
                    $detected_languages[] = [
                        'code' => $code,
                        'name' => $name ?: strtoupper($code),
                    ];
                }
            }
            if (!$had_previous_plugin) {
                $had_previous_plugin = !empty($detected_languages);
                $previous_plugin_name = 'Polylang';
            }
        }
        // Try WPML (active — globals available)
        elseif (defined('ICL_SITEPRESS_VERSION')) {
            global $sitepress;
            if (isset($sitepress) && is_object($sitepress) && method_exists($sitepress, 'get_active_languages')) {
                $active_langs = $sitepress->get_active_languages();
                $detected_default_lang = method_exists($sitepress, 'get_default_language') ? $sitepress->get_default_language() : '';
                if (is_array($active_langs)) {
                    foreach ($active_langs as $code => $lang_info) {
                        $name = '';
                        if (class_exists('AITRFOEL_Language_Mapper')) {
                            $name = AITRFOEL_Language_Mapper::get_language_name($code, false);
                        }
                        if (!$name && isset($lang_info['english_name'])) {
                            $name = $lang_info['english_name'];
                        }
                        $detected_languages[] = [
                            'code' => $code,
                            'name' => $name ?: strtoupper($code),
                        ];
                    }
                }
                if (!$had_previous_plugin) {
                    $had_previous_plugin = !empty($detected_languages);
                    $previous_plugin_name = 'WPML';
                }
            }
        }
        // Try Polylang DB options (inactive but data remains)
        elseif ($polylang_was_active) {
            $pll_option = get_option('polylang', []);
            if (is_array($pll_option) && !empty($pll_option['default_lang'])) {
                $detected_default_lang = $pll_option['default_lang'];
            }
            // Polylang stores language data in wp_terms / taxonomy 'language'
            // When Polylang is deactivated, the taxonomy is not registered,
            // so get_terms() returns WP_Error. Use direct DB query instead.
            if (taxonomy_exists('language')) {
                $pll_terms = get_terms([
                    'taxonomy' => 'language',
                    'hide_empty' => false,
                ]);
                if (!is_wp_error($pll_terms) && is_array($pll_terms)) {
                    foreach ($pll_terms as $term) {
                        $code = $term->slug;
                        $name = '';
                        if (class_exists('AITRFOEL_Language_Mapper')) {
                            $name = AITRFOEL_Language_Mapper::get_language_name($code, false);
                        }
                        $detected_languages[] = [
                            'code' => $code,
                            'name' => $name ?: $term->name,
                        ];
                    }
                }
            } else {
                // Taxonomy not registered (Polylang deactivated) — query DB directly
                global $wpdb;
                $db_terms = $wpdb->get_results(
                    "SELECT t.slug, t.name FROM {$wpdb->terms} t "
                    . "INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id "
                    . "WHERE tt.taxonomy = 'language'"
                );
                if (is_array($db_terms)) {
                    foreach ($db_terms as $term) {
                        $code = $term->slug;
                        $name = '';
                        if (class_exists('AITRFOEL_Language_Mapper')) {
                            $name = AITRFOEL_Language_Mapper::get_language_name($code, false);
                        }
                        $detected_languages[] = [
                            'code' => $code,
                            'name' => $name ?: $term->name,
                        ];
                    }
                }
            }
        }
        
        // Detect plugin install/active states for system choice screen
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $polylang_active = (function_exists('is_plugin_active') && is_plugin_active('polylang/polylang.php')) || function_exists('pll_languages_list');
        $wpml_active = (function_exists('is_plugin_active') && is_plugin_active('sitepress-multilingual-cms/sitepress.php')) || (defined('ICL_SITEPRESS_VERSION') && class_exists('SitePress'));
        $polylang_installed = $polylang_active || file_exists(WP_PLUGIN_DIR . '/polylang/polylang.php');
        $wpml_installed = $wpml_active || file_exists(WP_PLUGIN_DIR . '/sitepress-multilingual-cms/sitepress.php');
        ?>
        <!-- Hide default WP admin notices and Elementor updater -->
        <style>
            .wrap > .notice, .wrap > .error, .wrap > .updated,
            #wpbody-content > .notice, #wpbody-content > .error, #wpbody-content > .updated,
            #wpbody-content > div.notice, .notice:not(.wizard-stage .notice),
            div.error, div.updated,
            .e-notice, .e-notice--warning, .e-notice--dismissible,
            .wizard-stage .e-notice,
            .wizard-stage .notice,
            .wiz-panel .e-notice,
            .wiz-panel .notice { display: none !important; }
        </style>

        <div class="wrap">
        <div class="wizard-stage">
        
        <div class="wizard-card">

            <!-- Progress Bar -->
            <div class="step-indicator">
                <div class="step active" data-num="1">
                    <div class="step-dot active" data-step="1">01</div>
                    <div class="step-label"><?php esc_html_e('Languages', 'ai-translator-for-elementor-and-polylang'); ?></div>
                </div>
                <div class="step-line" data-after="1"></div>
                <div class="step" data-num="2">
                    <div class="step-dot" data-step="2">02</div>
                    <div class="step-label"><?php esc_html_e('Setup', 'ai-translator-for-elementor-and-polylang'); ?></div>
                </div>
                <div class="step-line" data-after="2"></div>
                <div class="step" data-num="3">
                    <div class="step-dot" data-step="3">03</div>
                    <div class="step-label"><?php esc_html_e('Done', 'ai-translator-for-elementor-and-polylang'); ?></div>
                </div>
            </div>

            <!-- STEP 1: Languages -->
            <div class="wiz-panel active" data-panel="1">

                <div class="brand-row">
                    <div class="aitrfoel-wiz-logo">
                        <img src="<?php echo esc_url(AITRFOEL_PLUGIN_URL . 'assets/img/meep-ai-translator.png'); ?>" alt="Meep AI Translator" style="height: 32px; width: auto;">
                    </div>
                    <span class="brand-pill"><?php esc_html_e('AI Translator', 'ai-translator-for-elementor-and-polylang'); ?></span>
                </div>

                <div class="eyebrow"><?php esc_html_e('step.01 - languages', 'ai-translator-for-elementor-and-polylang'); ?></div>
                <h1 class="wiz-title"><?php esc_html_e('Pick the languages your site', 'ai-translator-for-elementor-and-polylang'); ?> <span class="accent"><?php esc_html_e('speaks', 'ai-translator-for-elementor-and-polylang'); ?></span>.</h1>
                <p class="wiz-sub"><?php esc_html_e('Add 100+ languages in minutes - no copy-paste, no manual work. Choose your default and targets, and AI handles the rest.', 'ai-translator-for-elementor-and-polylang'); ?></p>

                <?php if ($polylang_active || $wpml_active): ?>

                <!-- Detected banner (Polylang/WPML already active) -->
                <div class="detected-banner">
                    <svg width="28" height="28" viewBox="0 0 28 28" fill="none" style="flex-shrink:0"><circle cx="14" cy="14" r="14" fill="#16a34a"/><path d="M8 14l4 4 8-8" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <div>
                        <strong><?php echo esc_html(($polylang_active ? 'Polylang' : 'WPML') . ' ' . __('detected', 'ai-translator-for-elementor-and-polylang')); ?></strong>
                        <p><?php esc_html_e('Your language setup is already configured. Meep will connect to it automatically.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                    </div>
                </div>

                <?php if (!empty($detected_languages)): ?>
                <div class="selected-chips">
                    <?php foreach ($detected_languages as $dlang): ?>
                        <?php $dflag = class_exists('AITRFOEL_Language_Mapper') ? AITRFOEL_Language_Mapper::get_country_code($dlang['code']) : substr($dlang['code'], 0, 2); ?>
                        <span class="chip">
                            <img class="chip-flag" src="https://flagcdn.com/w40/<?php echo esc_attr($dflag); ?>.png" alt="" width="18" height="13">
                            <?php echo esc_html($dlang['name']); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php else: ?>

                <!-- Default Language -->
                <div class="field-label"><?php esc_html_e('Default language', 'ai-translator-for-elementor-and-polylang'); ?><span class="field-mono"><?php esc_html_e('site.locale', 'ai-translator-for-elementor-and-polylang'); ?></span></div>
                <p class="field-help"><?php esc_html_e('The original content language. Everything else translates from this.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                <select id="aitrfoel-wiz-default-lang" class="default-lang-select">
                        <?php foreach ($available_languages as $lang): ?>
                            <option value="<?php echo esc_attr($lang['code']); ?>" <?php echo $lang['code'] === 'en' ? 'selected' : ''; ?>>
                                <?php echo esc_html($lang['name']); ?> (<?php echo esc_html(strtoupper($lang['code'])); ?>)
                            </option>
                        <?php endforeach; ?>
                </select>

                <!-- Selected Language Chips -->
                <div class="field-label"><?php esc_html_e('Target languages', 'ai-translator-for-elementor-and-polylang'); ?><span class="field-mono" id="aitrfoel-selected-count">// 0 selected</span></div>
                <div class="selected-chips" id="aitrfoel-wiz-chips"></div>

                <!-- Popular Languages Grid -->
                <div class="field-label"><?php esc_html_e('Popular languages', 'ai-translator-for-elementor-and-polylang'); ?><span class="field-mono">// click to add</span></div>
                <div class="lang-grid" id="aitrfoel-wiz-lang-grid">
                    <?php foreach ($popular_languages as $lang): ?>
                    <button type="button" class="lang-btn" data-code="<?php echo esc_attr($lang['code']); ?>" data-name="<?php echo esc_attr($lang['name']); ?>">
                        <img class="flag" src="https://flagcdn.com/w40/<?php echo esc_attr($lang['flag']); ?>.png"
                             srcset="https://flagcdn.com/w80/<?php echo esc_attr($lang['flag']); ?>.png 2x"
                             alt="<?php echo esc_attr($lang['name']); ?>" width="22" height="16" loading="lazy">
                        <span class="name"><?php echo esc_html($lang['name']); ?></span>
                        <span class="code"><?php echo esc_html(strtoupper($lang['code'])); ?></span>
                        <span class="check"><svg width="10" height="10" viewBox="0 0 10 10" fill="none"><path d="M1.5 5l2.5 2.5 4.5-5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span>
                    </button>
                    <?php endforeach; ?>
                </div>

                <!-- Search Other Languages -->
                <div class="field-label"><?php esc_html_e('Add other languages', 'ai-translator-for-elementor-and-polylang'); ?><span class="field-mono">// search</span></div>
                <div class="search-wrap">
                    <span class="search-icon"><svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="7" cy="7" r="5" stroke="currentColor" stroke-width="1.5"/><path d="M11 11l3.5 3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span>
                    <input type="text" id="aitrfoel-wiz-search" placeholder="<?php esc_attr_e('Search for a language...', 'ai-translator-for-elementor-and-polylang'); ?>">
                </div>
                <div class="aitrfoel-wiz-search-results" id="aitrfoel-wiz-search-results"></div>

                <!-- Advanced: Language System -->
                <details class="advanced" id="aitrfoel-wiz-advanced">
                    <summary>
                        <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M7 1v2M7 11v2M1 7h2M11 7h2M3.05 3.05l1.41 1.41M9.54 9.54l1.41 1.41M3.05 10.95l1.41-1.41M9.54 4.46l1.41-1.41" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
                        <?php esc_html_e('Advanced - Language management system', 'ai-translator-for-elementor-and-polylang'); ?>
                        <svg class="chev" width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M3 5l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </summary>
                    <div class="system-grid">
                        <!-- Built-in (recommended) -->
                        <label class="system-card selected" data-system="builtin">
                            <input type="radio" name="aitrfoel_system_choice" value="builtin" checked>
                                <div class="badge"><?php esc_html_e('Recommended', 'ai-translator-for-elementor-and-polylang'); ?></div>
                                <div class="icon">
                                    <svg width="32" height="32" viewBox="0 0 32 32" fill="none"><circle cx="16" cy="16" r="14" stroke="#2271b1" stroke-width="2"/><path d="M10 16l4 4 8-8" stroke="#2271b1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </div>
                                <h3><?php esc_html_e('Built-in', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                                <p><?php esc_html_e('Lightweight, no extra plugins needed. URL prefixes, hreflang tags, language switcher.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                        </label>
                        <!-- Polylang -->
                        <label class="system-card" data-system="polylang" id="aitrfoel-wiz-card-polylang">
                            <input type="radio" name="aitrfoel_system_choice" value="polylang">
                                <?php if ($polylang_active): ?>
                                    <div class="badge"><?php esc_html_e('Active', 'ai-translator-for-elementor-and-polylang'); ?></div>
                                <?php elseif ($polylang_installed): ?>
                                    <div class="badge muted"><?php esc_html_e('Installed', 'ai-translator-for-elementor-and-polylang'); ?></div>
                                <?php else: ?>
                                    <div class="badge muted"><?php esc_html_e('Not installed', 'ai-translator-for-elementor-and-polylang'); ?></div>
                                <?php endif; ?>
                                <div class="icon">
                                    <svg width="32" height="32" viewBox="0 0 32 32" fill="none"><circle cx="16" cy="16" r="14" stroke="#00a32a" stroke-width="2"/><text x="16" y="21" text-anchor="middle" font-size="14" font-weight="bold" fill="#00a32a">P</text></svg>
                                </div>
                                <h3>Polylang</h3>
                                <p><?php esc_html_e('Free multilingual plugin. Install and activate it first, then reload this page.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                        </label>
                        <!-- WPML -->
                        <label class="system-card" data-system="wpml" id="aitrfoel-wiz-card-wpml">
                            <input type="radio" name="aitrfoel_system_choice" value="wpml">
                                <?php if ($wpml_active): ?>
                                    <div class="badge"><?php esc_html_e('Active', 'ai-translator-for-elementor-and-polylang'); ?></div>
                                <?php elseif ($wpml_installed): ?>
                                    <div class="badge muted"><?php esc_html_e('Installed', 'ai-translator-for-elementor-and-polylang'); ?></div>
                                <?php else: ?>
                                    <div class="badge warn"><?php esc_html_e('Not installed', 'ai-translator-for-elementor-and-polylang'); ?></div>
                                <?php endif; ?>
                                <div class="icon">
                                    <svg width="32" height="32" viewBox="0 0 32 32" fill="none"><circle cx="16" cy="16" r="14" stroke="#33769e" stroke-width="2"/><text x="16" y="21" text-anchor="middle" font-size="12" font-weight="bold" fill="#33769e">W</text></svg>
                                </div>
                                <h3>WPML</h3>
                                <p><?php esc_html_e('Premium multilingual plugin. Purchase and install it from wpml.org, then reload.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                        </label>
                    </div>
                    <p class="aitrfoel-system-legend">
                        <strong><?php esc_html_e('Status legend:', 'ai-translator-for-elementor-and-polylang'); ?></strong>
                        <?php esc_html_e('Active = ready to use, Installed = activate plugin first, Not installed = install plugin first.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                </details>

                <?php endif; // end not Polylang/WPML active ?>

                <div class="wiz-nav right" style="margin-top: 28px;">
                    <button type="button" class="btn btn-primary" id="aitrfoel-wiz-start"
                        <?php echo (!$polylang_active && !$wpml_active) ? 'disabled' : ''; ?>>
                        <?php if ($polylang_active || $wpml_active): ?>
                            <?php esc_html_e('Connect & continue', 'ai-translator-for-elementor-and-polylang'); ?>
                        <?php else: ?>
                            <?php esc_html_e('Set up my site', 'ai-translator-for-elementor-and-polylang'); ?>
                        <?php endif; ?>
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M6 3l5 5-5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                </div>

            </div><!-- /panel 1 -->

            <!-- ═══ STEP 2: Setup (AJAX fires on enter) ═══ -->
            <div class="wiz-panel" data-panel="2">
                <div class="installing" style="text-align:center; padding: 16px 0;">
                    <div class="spinner-wrap" id="aitrfoel-wiz-spinner">
                        <div class="spinner-ring"></div>
                        <div class="spinner-inner">
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 010 20M12 2a15 15 0 000 20"/></svg>
                        </div>
                    </div>
                    <div id="aitrfoel-wiz-install-done" style="display:none; margin-bottom: 28px;">
                        <svg width="72" height="72" viewBox="0 0 72 72" fill="none">
                            <circle cx="36" cy="36" r="36" fill="#00a32a"/>
                            <path d="M22 36l10 10 18-20" stroke="white" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <div class="eyebrow" style="justify-content:center;"><?php esc_html_e('step.02 - installing', 'ai-translator-for-elementor-and-polylang'); ?></div>
                    <h2 class="install-title" id="aitrfoel-wiz-install-title"><?php esc_html_e('Configuring your site', 'ai-translator-for-elementor-and-polylang'); ?></h2>
                    <p class="install-status" id="aitrfoel-wiz-install-status">
                        <?php esc_html_e('Setting up multilingual support — this takes just a moment.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                    <div class="install-checklist" id="aitrfoel-wiz-install-checklist">
                        <div class="task complete"><span class="task-icon">✓</span><span class="task-name"><?php esc_html_e('Built-in language system enabled', 'ai-translator-for-elementor-and-polylang'); ?></span><span class="task-meta">0.4s</span></div>
                        <div class="task in-progress"><span class="task-icon"></span><span class="task-name"><?php esc_html_e('Applying language settings', 'ai-translator-for-elementor-and-polylang'); ?></span><span class="task-meta"><?php esc_html_e('in progress', 'ai-translator-for-elementor-and-polylang'); ?></span></div>
                        <div class="task"><span class="task-icon"></span><span class="task-name"><?php esc_html_e('Generating hreflang and URL prefixes', 'ai-translator-for-elementor-and-polylang'); ?></span><span class="task-meta"><?php esc_html_e('pending', 'ai-translator-for-elementor-and-polylang'); ?></span></div>
                    </div>
                    <p class="aitrfoel-wiz-install-error" id="aitrfoel-wiz-install-error" style="display:none;"></p>

                    <div class="wiz-nav" style="margin-top: 32px;">
                        <button type="button" class="btn btn-outline" id="aitrfoel-wiz-back-2">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <?php esc_html_e('Back', 'ai-translator-for-elementor-and-polylang'); ?>
                        </button>
                    </div>
                </div>
            </div><!-- /panel 2 -->

            <!-- ═══ STEP 3: Done ═══ -->
            <div class="wiz-panel" data-panel="3">
                <div class="done" style="text-align:center;">
                    <div class="success-wrap">
                        <div class="success-ring"></div>
                        <div class="success-inner">
                            <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5L20 7"/></svg>
                        </div>
                    </div>
                    <div class="eyebrow" style="justify-content:center;"><?php esc_html_e('step.03 - ready', 'ai-translator-for-elementor-and-polylang'); ?></div>
                    <h2 class="done-title"><?php esc_html_e("You're ready to ship", 'ai-translator-for-elementor-and-polylang'); ?> <span class="accent"><?php esc_html_e('multilingual', 'ai-translator-for-elementor-and-polylang'); ?></span>.</h2>
                    <p class="done-sub"><?php esc_html_e('Your site is configured. Translate any page or post and keep Elementor layouts intact.', 'ai-translator-for-elementor-and-polylang'); ?></p>

                    <!-- Language Summary -->
                    <div class="summary-box" id="aitrfoel-wiz-summary-box">
                        <div class="summary-eyebrow"><?php esc_html_e('session.summary', 'ai-translator-for-elementor-and-polylang'); ?></div>
                        <div class="summary-stats">
                            <div class="summary-stat"><span class="num" id="aitrfoel-summary-lang-count">0</span><span class="label"><?php esc_html_e('Languages', 'ai-translator-for-elementor-and-polylang'); ?></span></div>
                            <div class="summary-stat"><span class="num" id="aitrfoel-summary-setup-time">~1 min</span><span class="label"><?php esc_html_e('Setup Time', 'ai-translator-for-elementor-and-polylang'); ?></span></div>
                        </div>
                        <div class="summary-langs" id="aitrfoel-wiz-summary-langs"><!-- filled by JS --></div>
                    </div>

                    <!-- Analytics Opt-in -->
                    <div class="optin">
                        <div class="aitrfoel-wiz-optin-toggle">
                            <input type="checkbox" id="aitrfoel-wiz-analytics">
                            <label class="toggle-slider" for="aitrfoel-wiz-analytics"></label>
                        </div>
                        <div class="aitrfoel-wiz-optin-text">
                            <h4><?php esc_html_e('Help improve Meep AI Translator', 'ai-translator-for-elementor-and-polylang'); ?></h4>
                            <p><?php esc_html_e('Share anonymous usage data to help us improve the plugin. No personal data is collected.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                        </div>
                    </div>

                    <!-- Navigation -->
                    <div class="wiz-nav">
                        <button type="button" class="btn btn-outline" id="aitrfoel-wiz-back-3">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <?php esc_html_e('Back', 'ai-translator-for-elementor-and-polylang'); ?>
                        </button>
                        <button type="button" class="btn btn-primary" id="aitrfoel-wiz-finish-btn">
                            <?php esc_html_e('Translate your first page', 'ai-translator-for-elementor-and-polylang'); ?>
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M6 3l5 5-5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                    </div>
                    <div style="text-align:center; margin-top:12px;">
                        <a href="https://meeptech.com/products/ai-translator-for-elementor-polylang/documentation" target="_blank" class="btn-link">
                            <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 1h7l3 3v8a1 1 0 01-1 1H2a1 1 0 01-1-1V2a1 1 0 011-1z" stroke="currentColor" stroke-width="1.2"/><path d="M4 7h5M4 9.5h3" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
                            <?php esc_html_e('View Documentation', 'ai-translator-for-elementor-and-polylang'); ?>
                        </a>
                    </div>
                </div>
            </div><!-- /panel 3 -->

            <!-- Footer -->
            <div class="wiz-footer">
                <span>
                    <?php esc_html_e('You can change these settings at any time from', 'ai-translator-for-elementor-and-polylang'); ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ai-translator-for-elementor-and-polylang-settings')); ?>">
                        <?php esc_html_e('Settings', 'ai-translator-for-elementor-and-polylang'); ?>
                    </a>
                </span>
            </div>
        </div><!-- .wizard-card -->
        </div><!-- .wizard-stage -->
        </div><!-- .wrap -->

        <script>
        (function() {
            'use strict';

            /* ── State ────────────────────────────────────── */
            var currentStep = 1;
            var selectedLangs = {};
            var setupDone = false;
            var allLanguages   = <?php echo wp_json_encode($available_languages); ?>;
            var popularCodes   = <?php echo wp_json_encode(array_column($popular_languages, 'code')); ?>;
            var flagMap        = <?php
                $full_flag_map = [];
                if (class_exists('AITRFOEL_Language_Mapper')) {
                    foreach ($available_languages as $lang) {
                        $full_flag_map[$lang['code']] = AITRFOEL_Language_Mapper::get_country_code($lang['code']);
                    }
                    foreach ($popular_languages as $plang) {
                        $full_flag_map[$plang['code']] = $plang['flag'];
                    }
                } else {
                    $full_flag_map = array_combine(
                        array_column($popular_languages, 'code'),
                        array_column($popular_languages, 'flag')
                    );
                }
                echo wp_json_encode($full_flag_map);
            ?>;
            var ajaxNonce      = <?php echo wp_json_encode(wp_create_nonce('aitrfoel_builtin_setup')); ?>;
            var detectedLangs  = <?php echo wp_json_encode($detected_languages); ?>;
            var detectedDefaultLang = <?php echo wp_json_encode($detected_default_lang); ?>;
            var polylangActive = <?php echo wp_json_encode($polylang_active); ?>;
            var wpmlActive     = <?php echo wp_json_encode($wpml_active); ?>;
            var dashboardUrl   = <?php echo wp_json_encode(admin_url('admin.php?page=ai-translator-for-elementor-and-polylang')); ?>;

            // Demo translation vars — populated from AJAX response in runSetup()
            var wizDemoPostId    = 0;
            var wizDemoWordCount = 0;
            var wizDemoLang      = '';

            /* ── Step Navigation ──────────────────────────── */
            function goToStep(step) {
                document.querySelectorAll('.step').forEach(function(s) {
                    var n = parseInt(s.dataset.num, 10);
                    s.classList.remove('active', 'complete');
                    if (n < step) s.classList.add('complete');
                    if (n === step) s.classList.add('active');
                });
                document.querySelectorAll('.step-dot').forEach(function(d) {
                    var s = parseInt(d.dataset.step);
                    d.className = 'step-dot';
                    if (s < step)  { d.classList.add('complete'); d.textContent = '✓'; }
                    if (s === step) d.classList.add('active');
                    if (s >= step)  d.textContent = ('0' + s).slice(-2);
                });
                document.querySelectorAll('.step-line').forEach(function(l) {
                    var after = parseInt(l.dataset.after);
                    l.className = 'step-line';
                    if (after < step) l.classList.add('complete');
                });
                document.querySelectorAll('.wiz-panel').forEach(function(p) {
                    p.classList.remove('active');
                });
                var panel = document.querySelector('.wiz-panel[data-panel="' + step + '"]');
                if (panel) panel.classList.add('active');
                currentStep = step;
                // Auto-fire AJAX when entering step 2
                if (step === 2 && !setupDone) {
                    runSetup();
                }
            }

            /* ── Step 1: System card selection ──────────── */
            var systemCards = document.querySelectorAll('.system-card');
            if (systemCards.length) {
                systemCards.forEach(function(card) {
                    card.addEventListener('click', function() {
                        systemCards.forEach(function(c) { c.classList.remove('selected'); });
                        card.classList.add('selected');
                        card.querySelector('input[type=radio]').checked = true;
                    });
                });
            }

            /* ── Step 1: Language selection (only when not Polylang/WPML active) ── */
            var langGrid     = document.getElementById('aitrfoel-wiz-lang-grid');
            var searchInput  = document.getElementById('aitrfoel-wiz-search');
            var searchResults = document.getElementById('aitrfoel-wiz-search-results');
            var chipsContainer = document.getElementById('aitrfoel-wiz-chips');
            var startBtn     = document.getElementById('aitrfoel-wiz-start');

            if (langGrid) {
                langGrid.addEventListener('click', function(e) {
                    var btn = e.target.closest('.lang-btn');
                    if (!btn) return;
                    var code = btn.dataset.code;
                    var name = btn.dataset.name;
                    if (btn.classList.contains('selected')) {
                        btn.classList.remove('selected');
                        delete selectedLangs[code];
                    } else {
                        btn.classList.add('selected');
                        selectedLangs[code] = name;
                    }
                    renderChips();
                    updateStartBtn();
                });
            }

            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    var q = this.value.toLowerCase().trim();
                    if (q.length < 1) { searchResults.classList.remove('visible'); return; }
                    var defaultLang = document.getElementById('aitrfoel-wiz-default-lang').value;
                    var results = allLanguages.filter(function(l) {
                        return l.name.toLowerCase().indexOf(q) !== -1 && l.code !== defaultLang && !selectedLangs[l.code] && popularCodes.indexOf(l.code) === -1;
                    }).slice(0, 8);
                    if (!results.length) { searchResults.classList.remove('visible'); return; }
                    searchResults.innerHTML = results.map(function(l) {
                        var fc = flagMap[l.code] || l.code.substring(0, 2);
                        return '<div class="aitrfoel-wiz-search-result-item" data-code="' + l.code + '" data-name="' + l.name + '">' +
                            '<img src="https://flagcdn.com/w40/' + fc + '.png" width="22" height="16" alt="" style="border-radius:2px;">' +
                            '<span>' + l.name + ' <span style="color:#999;">(' + l.code.toUpperCase() + ')</span></span></div>';
                    }).join('');
                    searchResults.classList.add('visible');
                });
            }

            if (searchResults) {
                searchResults.addEventListener('click', function(e) {
                    var item = e.target.closest('.aitrfoel-wiz-search-result-item');
                    if (!item) return;
                    selectedLangs[item.dataset.code] = item.dataset.name;
                    searchInput.value = '';
                    searchResults.classList.remove('visible');
                    var popBtn = langGrid ? langGrid.querySelector('.lang-btn[data-code="' + item.dataset.code + '"]') : null;
                    if (popBtn) popBtn.classList.add('selected');
                    renderChips();
                    updateStartBtn();
                });

                document.addEventListener('click', function(e) {
                    if (searchInput && !searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                        searchResults.classList.remove('visible');
                    }
                });
            }

            function renderChips() {
                if (!chipsContainer) return;
                chipsContainer.innerHTML = '';
                Object.keys(selectedLangs).forEach(function(code) {
                    var fc = flagMap[code] || code.substring(0, 2);
                    var chip = document.createElement('span');
                    chip.className = 'chip';
                    chip.innerHTML = '<img class="chip-flag" src="https://flagcdn.com/w40/' + fc + '.png" alt="" width="18" height="13">' +
                        selectedLangs[code] +
                        '<span class="chip-code">' + code.toUpperCase() + '</span>' +
                        '<button type="button" class="chip-x chip-remove" data-code="' + code + '">&times;</button>';
                    chipsContainer.appendChild(chip);
                });
                chipsContainer.querySelectorAll('.chip-remove').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var c = this.dataset.code;
                        delete selectedLangs[c];
                        if (langGrid) {
                            var popBtn = langGrid.querySelector('.lang-btn[data-code="' + c + '"]');
                            if (popBtn) popBtn.classList.remove('selected');
                        }
                        renderChips();
                        updateStartBtn();
                    });
                });
            }

            function updateStartBtn() {
                if (!startBtn || polylangActive || wpmlActive) return;
                startBtn.disabled = Object.keys(selectedLangs).length === 0;
                var selectedCountEl = document.getElementById('aitrfoel-selected-count');
                if (selectedCountEl) {
                    selectedCountEl.textContent = '// ' + Object.keys(selectedLangs).length + ' selected';
                }
            }

            /* ── Auto-import languages from Polylang/WPML ── */
            if (detectedLangs && detectedLangs.length > 0 && langGrid) {
                if (detectedDefaultLang) {
                    var defSelect = document.getElementById('aitrfoel-wiz-default-lang');
                    if (defSelect) {
                        for (var oi = 0; oi < defSelect.options.length; oi++) {
                            if (defSelect.options[oi].value === detectedDefaultLang) {
                                defSelect.selectedIndex = oi;
                                break;
                            }
                        }
                    }
                }
                detectedLangs.forEach(function(lang) {
                    if (lang.code === detectedDefaultLang) return;
                    selectedLangs[lang.code] = lang.name;
                    var popBtn = langGrid.querySelector('.lang-btn[data-code="' + lang.code + '"]');
                    if (popBtn) popBtn.classList.add('selected');
                });
                renderChips();
                updateStartBtn();
            }

            /* ── Step 1 → Continue ────────────────────────── */
            if (startBtn) {
                startBtn.addEventListener('click', function() {
                    if (!polylangActive && !wpmlActive) {
                        if (Object.keys(selectedLangs).length === 0) return;
                    }
                    goToStep(2);
                });
            }

            /* ── Step 2: Real AJAX setup ──────────────────── */
            function runSetup() {
                var backBtn2 = document.getElementById('aitrfoel-wiz-back-2');
                if (backBtn2) backBtn2.disabled = true;

                var action, formData = new FormData();
                formData.append('nonce', ajaxNonce);

                if (polylangActive || wpmlActive) {
                    action = 'aitrfoel_complete_wizard';
                    formData.append('action', action);
                } else {
                    action = 'aitrfoel_setup_builtin_languages';
                    formData.append('action', action);
                    var defaultLangSel = document.getElementById('aitrfoel-wiz-default-lang');
                    var defaultLang = defaultLangSel ? defaultLangSel.value : 'en';
                    var defaultLangName = defaultLangSel ? defaultLangSel.selectedOptions[0].text.replace(/\s*\(.*\)$/, '') : 'English';
                    var targetLangs = Object.keys(selectedLangs).map(function(code) {
                        return { code: code, name: selectedLangs[code] };
                    });
                    formData.append('default_language', defaultLang);
                    formData.append('default_language_name', defaultLangName);
                    formData.append('target_languages', JSON.stringify(targetLangs));
                }

                fetch(ajaxurl, { method: 'POST', body: formData })
                    .then(function(r) {
                        if (!r.ok) throw new Error('HTTP ' + r.status + ': ' + r.statusText);
                        return r.text();
                    })
                    .then(function(text) {
                        var res;
                        try { res = JSON.parse(text); }
                        catch(e) {
                            console.error('AJAX response was not JSON:', text.substring(0, 500));
                            throw new Error('Server returned invalid response. Check browser console for details.');
                        }
                        return res;
                    })
                    .then(function(res) {
                        if (res.success) {
                            setupDone = true;
                            // Capture demo translation data returned by the server
                            if (res.data && res.data.demo_post_id) {
                                wizDemoPostId    = parseInt(res.data.demo_post_id, 10) || 0;
                                wizDemoWordCount = parseInt(res.data.demo_word_count, 10) || 0;
                                // Pick the first selected target language for the demo
                                var langKeys = Object.keys(selectedLangs);
                                wizDemoLang = langKeys.length > 0 ? langKeys[0] : '';
                                // For Polylang/WPML path, pick from detectedLangs
                                if ((polylangActive || wpmlActive) && wizDemoLang === '' && detectedLangs.length > 0) {
                                    var defCode = detectedDefaultLang || '';
                                    var nonDefault = detectedLangs.filter(function(l) { return l.code !== defCode; });
                                    wizDemoLang = nonDefault.length > 0 ? nonDefault[0].code : '';
                                }
                            }
                            // Build language summary for step 3
                            if (!polylangActive && !wpmlActive) {
                                var defaultLangSel2 = document.getElementById('aitrfoel-wiz-default-lang');
                                var defName = defaultLangSel2 ? defaultLangSel2.selectedOptions[0].text.replace(/\s*\(.*\)$/, '') : '';
                                var summaryEl = document.getElementById('aitrfoel-wiz-summary-langs');
                                if (summaryEl) {
                                    var totalLangs = Object.keys(selectedLangs).length + 1;
                                    var totalEl = document.getElementById('aitrfoel-summary-lang-count');
                                    var setupTimeEl = document.getElementById('aitrfoel-summary-setup-time');
                                    if (totalEl) totalEl.textContent = totalLangs;
                                    if (setupTimeEl) {
                                        // Estimated setup time: scales with language count.
                                        var estimatedMinutes = Math.max(1, Math.ceil(totalLangs / 8));
                                        setupTimeEl.textContent = '~' + estimatedMinutes + ' min';
                                    }
                                    summaryEl.innerHTML = '<span class="chip"><strong>' + defName + '</strong> <span class="chip-code"><?php echo esc_js(__('default', 'ai-translator-for-elementor-and-polylang')); ?></span></span>';
                                    Object.keys(selectedLangs).forEach(function(code) {
                                        var fc = flagMap[code] || code.substring(0, 2);
                                        summaryEl.innerHTML += '<span class="chip"><img class="chip-flag" src="https://flagcdn.com/w40/' + fc + '.png" width="18" height="13" alt=""> ' + selectedLangs[code] + ' <span class="chip-code">' + code.toUpperCase() + '</span></span>';
                                    });
                                }
                            } else {
                                // Hide summary box for Polylang/WPML — they manage their own languages
                                var summaryBox = document.getElementById('aitrfoel-wiz-summary-box');
                                if (summaryBox) summaryBox.style.display = 'none';
                            }
                            // Show success state briefly then advance
                            document.getElementById('aitrfoel-wiz-spinner').style.display = 'none';
                            document.getElementById('aitrfoel-wiz-install-done').style.display = 'block';
                            document.getElementById('aitrfoel-wiz-install-title').textContent = <?php echo wp_json_encode(__('All Set!', 'ai-translator-for-elementor-and-polylang')); ?>;
                            document.getElementById('aitrfoel-wiz-install-status').textContent = <?php echo wp_json_encode(__('Moving to next step...', 'ai-translator-for-elementor-and-polylang')); ?>;
                            setTimeout(function() { goToStep(3); }, 600);
                        } else {
                            showSetupError(res.data || <?php echo wp_json_encode(__('Setup failed. Please try again.', 'ai-translator-for-elementor-and-polylang')); ?>);
                        }
                    })
                    .catch(function(err) {
                        console.error('Setup error:', err);
                        showSetupError(err.message || <?php echo wp_json_encode(__('Network error. Please try again.', 'ai-translator-for-elementor-and-polylang')); ?>);
                    });
            }

            function showSetupError(msg) {
                document.getElementById('aitrfoel-wiz-spinner').style.display = 'none';
                document.getElementById('aitrfoel-wiz-install-title').textContent = <?php echo wp_json_encode(__('Setup Failed', 'ai-translator-for-elementor-and-polylang')); ?>;
                document.getElementById('aitrfoel-wiz-install-status').style.display = 'none';
                var errEl = document.getElementById('aitrfoel-wiz-install-error');
                if (errEl) { errEl.textContent = msg; errEl.style.display = 'block'; }
                var backBtn2 = document.getElementById('aitrfoel-wiz-back-2');
                if (backBtn2) { backBtn2.disabled = false; backBtn2.innerHTML = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg> <?php echo esc_js(__('Try again', 'ai-translator-for-elementor-and-polylang')); ?>'; }
            }

            /* ── Back Buttons ─────────────────────────────── */
            document.getElementById('aitrfoel-wiz-back-2').addEventListener('click', function() {
                if (setupDone) return;
                // Reset step 2 UI so it's fresh if user goes back and tries again
                document.getElementById('aitrfoel-wiz-spinner').style.display = 'block';
                document.getElementById('aitrfoel-wiz-install-done').style.display = 'none';
                document.getElementById('aitrfoel-wiz-install-title').textContent = <?php echo wp_json_encode(__('Configuring your site', 'ai-translator-for-elementor-and-polylang')); ?>;
                document.getElementById('aitrfoel-wiz-install-status').textContent = <?php echo wp_json_encode(__('Setting up multilingual support — this takes just a moment.', 'ai-translator-for-elementor-and-polylang')); ?>;
                document.getElementById('aitrfoel-wiz-install-status').style.display = 'block';
                var errEl = document.getElementById('aitrfoel-wiz-install-error');
                if (errEl) { errEl.style.display = 'none'; errEl.textContent = ''; }
                goToStep(1);
            });

            document.getElementById('aitrfoel-wiz-back-3').addEventListener('click', function() {
                goToStep(1);
            });

            /* ── Step 3: Finish ───────────────────────────── */
            document.getElementById('aitrfoel-wiz-finish-btn').addEventListener('click', function() {
                var analyticsOptIn = document.getElementById('aitrfoel-wiz-analytics').checked;
                var finishBtn = document.getElementById('aitrfoel-wiz-finish-btn');
                finishBtn.disabled = true;

                // Save analytics opt-in (fire-and-forget)
                var optData = new FormData();
                optData.append('action', 'aitrfoel_save_analytics_optin');
                optData.append('nonce', ajaxNonce);
                optData.append('optin', analyticsOptIn ? '1' : '0');
                fetch(ajaxurl, { method: 'POST', body: optData }).catch(function() {});

                // Demo translation: only run if a post ID was returned and word count is small
                var demoPostId    = typeof wizDemoPostId !== 'undefined' ? wizDemoPostId : 0;
                var demoWordCount = typeof wizDemoWordCount !== 'undefined' ? wizDemoWordCount : 0;
                var demoLang      = typeof wizDemoLang !== 'undefined' ? wizDemoLang : '';

                // Show demo task row
                var taskList = document.querySelector('.install-checklist');
                var demoTask = null;
                if (taskList && demoPostId > 0 && demoWordCount <= 500 && demoLang) {
                    demoTask = document.createElement('div');
                    demoTask.className = 'task in-progress';
                    demoTask.id = 'aitrfoel-demo-task';
                    demoTask.innerHTML = '<span class="task-icon"></span><span class="task-label"><?php echo esc_js(__('Translating your homepage…', 'ai-translator-for-elementor-and-polylang')); ?></span>';
                    taskList.appendChild(demoTask);
                }

                function finish() {
                    window.location.reload();
                }

                if (demoPostId > 0 && demoWordCount <= 500 && demoLang) {
                    var demoData = new FormData();
                    demoData.append('action', 'aitrfoel_wizard_demo_translate');
                    demoData.append('nonce', ajaxNonce);
                    demoData.append('post_id', demoPostId);
                    demoData.append('target_lang', demoLang);
                    fetch(ajaxurl, { method: 'POST', body: demoData })
                        .then(function(r) { return r.json(); })
                        .then(function(res) {
                            if (demoTask) {
                                demoTask.className = 'task complete';
                                if (res.success && !res.data.skipped) {
                                    demoTask.querySelector('.task-label').textContent = <?php echo wp_json_encode(__('Homepage translated!', 'ai-translator-for-elementor-and-polylang')); ?>;
                                } else {
                                    demoTask.querySelector('.task-label').textContent = <?php echo wp_json_encode(__('Homepage skipped (too large)', 'ai-translator-for-elementor-and-polylang')); ?>;
                                }
                            }
                            setTimeout(finish, 800);
                        })
                        .catch(function() {
                            if (demoTask) { demoTask.className = 'task complete'; }
                            setTimeout(finish, 400);
                        });
                } else {
                    finish();
                }
            });

        })();
        </script>
        <?php
    }

    /**
     * AJAX handler: Add/remove/update languages in the built-in system (for settings page)
     */
    public function handle_manage_builtin_language() {
        if (!check_ajax_referer('aitrfoel_ajax_nonce', 'nonce', false)) {
            wp_send_json_error(__('Security check failed.', 'ai-translator-for-elementor-and-polylang'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'ai-translator-for-elementor-and-polylang'));
        }
        
        if (!class_exists('AITRFOEL_Builtin_Integration')) {
            require_once AITRFOEL_PLUGIN_DIR . 'includes/class-multilingual-interface.php';
            require_once AITRFOEL_PLUGIN_DIR . 'includes/class-builtin-integration.php';
        }
        
        $builtin = AITRFOEL_Builtin_Integration::get_instance();
        $operation = sanitize_text_field($_POST['operation'] ?? '');
        
        switch ($operation) {
            case 'add':
                $code = sanitize_text_field($_POST['language_code'] ?? '');
                $name = sanitize_text_field($_POST['language_name'] ?? '');
                if (empty($code) || empty($name)) {
                    wp_send_json_error(__('Language code and name are required.', 'ai-translator-for-elementor-and-polylang'));
                }
                $result = $builtin->add_language($code, $name, $name);
                if ($result) {
                    flush_rewrite_rules();
                    wp_send_json_success(['message' => __('Language added.', 'ai-translator-for-elementor-and-polylang')]);
                } else {
                    wp_send_json_error(__('Language already exists or could not be added.', 'ai-translator-for-elementor-and-polylang'));
                }
                break;
                
            case 'remove':
                $code = sanitize_text_field($_POST['language_code'] ?? '');
                $result = $builtin->remove_language($code);
                if ($result) {
                    flush_rewrite_rules();
                    wp_send_json_success(['message' => __('Language removed.', 'ai-translator-for-elementor-and-polylang')]);
                } else {
                    wp_send_json_error(__('Cannot remove this language.', 'ai-translator-for-elementor-and-polylang'));
                }
                break;
                
            case 'set_default':
                $code = sanitize_text_field($_POST['language_code'] ?? '');
                $result = $builtin->set_default_language($code);
                if ($result) {
                    wp_send_json_success(['message' => __('Default language updated.', 'ai-translator-for-elementor-and-polylang')]);
                } else {
                    wp_send_json_error(__('Could not set default language.', 'ai-translator-for-elementor-and-polylang'));
                }
                break;
                
            case 'disable':
                $builtin->disable();
                delete_option('aitrfoel_dependencies_status');
                wp_send_json_success(['message' => __('Built-in language system disabled.', 'ai-translator-for-elementor-and-polylang')]);
                break;
                
            default:
                wp_send_json_error(__('Invalid operation.', 'ai-translator-for-elementor-and-polylang'));
        }
    }

    /**
     * AJAX handler: Save analytics (PostHog) opt-in preference from onboarding wizard
     */
    public function handle_save_analytics_optin() {
        // Don't require strict nonce for this non-destructive preference save
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.');
        }
        $optin = sanitize_text_field($_POST['optin'] ?? '0');
        update_option('aitrfoel_analytics_optin', $optin === '1' ? true : false);
        wp_send_json_success(['message' => 'Preference saved.']);
    }

    /**
     * Render Dashboard page for bulk translations
     */
    public function render_dashboard_page() {
        // Show license activation notices
        if (isset($_GET['activated']) && $_GET['activated'] === 'true') {
            echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__('License activated successfully!', 'ai-translator-for-elementor-and-polylang') . '</strong> ' . esc_html__('Your plan has been upgraded.', 'ai-translator-for-elementor-and-polylang') . '</p></div>';
        }
        if (isset($_GET['license_pending']) && $_GET['license_pending'] === 'true') {
            echo '<div class="notice notice-warning is-dismissible"><p><strong>' . esc_html__('Almost there!', 'ai-translator-for-elementor-and-polylang') . '</strong> ' . esc_html__('Please check your email and click the confirmation link to complete the license activation.', 'ai-translator-for-elementor-and-polylang') . '</p></div>';
        }
        
        // Check for missing dependencies first
        $deps_status = get_option('aitrfoel_dependencies_status', ['status' => true, 'missing' => []]);
        
        // Check if only multilingual plugin is missing (show friendly onboarding screen)
        $only_multilingual_missing = !empty($deps_status['missing']) && 
                                     count($deps_status['missing']) === 1 && 
                                     in_array('multilingual', $deps_status['missing']);
        
        if ($only_multilingual_missing) {
            $this->render_multilingual_onboarding_screen();
            return;
        }
        
        if (!$deps_status['status'] && !empty($deps_status['missing'])) {
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('Meep AI Translator Dashboard', 'ai-translator-for-elementor-and-polylang'); ?></h1>
                <div class="notice notice-warning" style="padding: 15px;">
                    <h3 style="margin-top: 0;"><?php esc_html_e('Required Plugins Missing', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    <p><?php esc_html_e('The Meep AI Translator plugin requires the following plugins to be installed and activated:', 'ai-translator-for-elementor-and-polylang'); ?></p>
                    <ul style="list-style: disc; margin-left: 20px;">
                        <?php if (in_array('elementor', $deps_status['missing'])): ?>
                            <li><strong>Elementor</strong> - <a href="<?php echo esc_url(admin_url('plugin-install.php?s=elementor&tab=search&type=term')); ?>"><?php esc_html_e('Install now', 'ai-translator-for-elementor-and-polylang'); ?></a></li>
                        <?php endif; ?>
                        <?php if (in_array('elementor_version', $deps_status['missing'])): ?>
                            <li><strong>Elementor 3.0+</strong> - <?php esc_html_e('Please update Elementor to version 3.0 or higher', 'ai-translator-for-elementor-and-polylang'); ?></li>
                        <?php endif; ?>
                        <?php if (in_array('multilingual', $deps_status['missing'])): ?>
                            <li>
                                <strong><?php esc_html_e('Multilingual Plugin', 'ai-translator-for-elementor-and-polylang'); ?></strong> - 
                                <?php esc_html_e('Install one of the following:', 'ai-translator-for-elementor-and-polylang'); ?>
                                <ul style="list-style: circle; margin-left: 20px; margin-top: 5px;">
                                    <li><a href="<?php echo esc_url(admin_url('plugin-install.php?s=polylang&tab=search&type=term')); ?>">Polylang</a> (<?php esc_html_e('free', 'ai-translator-for-elementor-and-polylang'); ?>)</li>
                                    <li><a href="https://wpml.org/" target="_blank">WPML</a> (<?php esc_html_e('premium', 'ai-translator-for-elementor-and-polylang'); ?>)</li>
                                </ul>
                            </li>
                        <?php endif; ?>
                        <?php if (in_array('php_version', $deps_status['missing'])): ?>
                            <li><strong>PHP 7.4+</strong> - <?php esc_html_e('Please contact your hosting provider to upgrade PHP', 'ai-translator-for-elementor-and-polylang'); ?></li>
                        <?php endif; ?>
                    </ul>
                    <p style="margin-bottom: 0;"><em><?php esc_html_e('After installing the required plugins and setting up the languages, please refresh this page.', 'ai-translator-for-elementor-and-polylang'); ?></em></p>
                </div>
            </div>
            <?php
            return;
        }
        
        // Check if multilingual plugin is active
        $multilingual = class_exists('AITRFOEL_Multilingual_Manager') ? AITRFOEL_Multilingual_Manager::get_instance() : null;
        if (!$multilingual || !$multilingual->is_active()) {
            // Show the onboarding/installation screen instead of an error
            $this->render_multilingual_onboarding_screen();
            return;
        }
        
        // Check if languages are configured (need at least 2 for translation)
        $has_languages = method_exists($multilingual, 'has_languages_configured') && $multilingual->has_languages_configured();
        $language_count = method_exists($multilingual, 'get_language_count') ? $multilingual->get_language_count() : 0;
        $plugin_display_name = $multilingual->get_plugin_display_name();
        $detected_plugin = $multilingual->get_detected_plugin();
        
        if (!$has_languages) {
            $polylang_url = admin_url('admin.php?page=mlang');
            $wpml_url = admin_url('admin.php?page=sitepress-multilingual-cms/menu/languages.php');
            $lang_setup_url = $detected_plugin === 'wpml' ? $wpml_url : $polylang_url;
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('Translation Dashboard', 'ai-translator-for-elementor-and-polylang'); ?></h1>
                <div class="notice notice-warning" style="padding: 15px;">
                    <h3 style="margin-top: 0;">
                        <?php echo esc_html(sprintf(__('%s is installed - Languages needed', 'ai-translator-for-elementor-and-polylang'), $plugin_display_name)); ?>
                    </h3>
                    <p>
                        <?php 
                        if ($language_count === 0) {
                            esc_html_e('No languages have been configured yet. You need at least 2 languages to use the translation feature.', 'ai-translator-for-elementor-and-polylang');
                        } elseif ($language_count === 1) {
                            esc_html_e('Only 1 language is configured. You need at least 2 languages to translate between.', 'ai-translator-for-elementor-and-polylang');
                        }
                        ?>
                    </p>
                    <p>
                        <a href="<?php echo esc_url($lang_setup_url); ?>" class="button button-primary">
                            <?php echo esc_html(sprintf(__('Set up languages in %s', 'ai-translator-for-elementor-and-polylang'), $plugin_display_name)); ?>
                        </a>
                    </p>
                    <p style="margin-bottom: 0;"><em><?php esc_html_e('After setting up the languages, please refresh this page.', 'ai-translator-for-elementor-and-polylang'); ?></em></p>
                </div>
            </div>
            <?php
            return;
        }

        // Get pages with translation groups
        $pages_data = $this->get_pages_with_translations();

        // Determine whether any translation has ever been completed
        $has_any_translation = false;
        foreach ($pages_data as $group) {
            if (!empty($group['translations'])) {
                $has_any_translation = true;
                break;
            }
        }
        ?>
        
    <div class="wrap aitrfoel-dashboard-wrapper aitrfoel-tree-v2">
            <?php $this->render_compact_stats_bar(); ?>
            <?php $this->render_plugin_header(__('Dashboard', 'ai-translator-for-elementor-and-polylang')); ?>

            <?php if (!$has_any_translation): ?>
            <div class="aitrfoel-empty-state">
                <div class="aitrfoel-empty-icon" aria-hidden="true">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="9" stroke="#1447E6" stroke-width="1.6"/><path d="M3 12h18" stroke="#1447E6" stroke-width="1.6"/><path d="M12 3c2.5 2.5 4 5.6 4 9s-1.5 6.5-4 9" stroke="#1447E6" stroke-width="1.6"/><path d="M12 3C9.5 5.5 8 8.6 8 12s1.5 6.5 4 9" stroke="#1447E6" stroke-width="1.6"/></svg>
                </div>
                <h2 class="aitrfoel-empty-title"><?php esc_html_e('Your site is translation-ready', 'ai-translator-for-elementor-and-polylang'); ?></h2>
                <p class="aitrfoel-empty-body"><?php esc_html_e('No translations yet. Select your pages below and hit Translate — results appear here.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ai-translator-for-elementor-and-polylang-translate-all')); ?>" class="aitrfoel-empty-cta">
                    <?php esc_html_e('Translate all pages →', 'ai-translator-for-elementor-and-polylang'); ?>
                </a>
                <p class="aitrfoel-empty-hint"><?php esc_html_e('Or use the bar below to translate page by page.', 'ai-translator-for-elementor-and-polylang'); ?></p>
            </div>
            <?php endif; ?>

            <?php $this->render_setup_checklist(); ?>
            
            <!-- Bulk Translation Bar (always visible) -->
            <div id="aitrfoel-bulk-bar" class="aitrfoel-bulk-translation-bar">
                <div class="aitrfoel-bulk-content">
                    <div class="aitrfoel-bulk-selection" style="display: none;">
                        <?php /* translators: %d is the number of pages selected for translation */ ?>
                        <strong id="aitrfoel-selected-count"><?php echo esc_html(sprintf(__('%d pages selected', 'ai-translator-for-elementor-and-polylang'), 0)); ?></strong>
                    </div>
                    
                    <div class="aitrfoel-bulk-languages">
                        <label><?php esc_html_e('From:', 'ai-translator-for-elementor-and-polylang'); ?></label>
                        <div class="aitrfoel-source-languages">
                            <button type="button" class="button" id="aitrfoel-select-source-language">
                                <?php esc_html_e('Auto-detect', 'ai-translator-for-elementor-and-polylang'); ?>
                            </button>
                            <div id="aitrfoel-source-dropdown" class="aitrfoel-dropdown-content" style="display: none;">
                                <div class="aitrfoel-dropdown-item">
                                    <label><input type="radio" name="source-language" value="auto" checked> <?php esc_html_e('Auto-detect', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                </div>
                                <div class="aitrfoel-dropdown-divider"></div>
                                <?php
                                // Add multilingual languages to source selection
                                $multilingual = class_exists('AITRFOEL_Multilingual_Manager') ? AITRFOEL_Multilingual_Manager::get_instance() : null;
                                if ($multilingual && $multilingual->is_active()) {
                                    $languages = $multilingual->get_available_languages();
                                    
                                    if (!empty($languages)) {
                                        foreach ($languages as $code => $lang) {
                                            $name = is_object($lang) ? ($lang->name ?? strtoupper($code)) : (is_array($lang) ? ($lang['name'] ?? strtoupper($code)) : strtoupper($code));
                                            printf(
                                                '<div class="aitrfoel-dropdown-item"><label><input type="radio" name="source-language" value="%s"> %s (%s)</label></div>',
                                                esc_attr($code),
                                                esc_html($name),
                                                esc_html(strtoupper($code))
                                            );
                                        }
                                    }
                                }
                                ?>
                            </div>
                        </div>
                        
                        <label><?php esc_html_e('To:', 'ai-translator-for-elementor-and-polylang'); ?></label>
                        <div class="aitrfoel-target-languages">
                            <button type="button" class="button" id="aitrfoel-select-languages">
                                <?php esc_html_e('Select languages', 'ai-translator-for-elementor-and-polylang'); ?>
                            </button>
                            <div id="aitrfoel-languages-dropdown" class="aitrfoel-dropdown-content" style="display: none;">
                                <!-- Will be populated by JS -->
                            </div>
                        </div>
                    </div>
                    
                    <div class="aitrfoel-bulk-stats">
                        <span id="aitrfoel-estimated-words" style="color: #666; font-size: 12px;"><?php esc_html_e('Select pages for translation', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        <span id="aitrfoel-elapsed-timer" style="color: #666; font-size: 12px; margin-left: 10px; display: none;">⏱️ <span id="aitrfoel-timer-value">0:00</span></span>
                    </div>
                    
                    <div class="aitrfoel-bulk-actions">
                        <button type="button" class="button" id="aitrfoel-bulk-settings" title="Configure translation settings">
                            <span class="aitrfoel-btn-label"><span class="aitrfoel-bulk-action-icon aitrfoel-bulk-action-icon-settings" aria-hidden="true"></span><?php esc_html_e('Settings', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=ai-translator-for-elementor-and-polylang-translate-all')); ?>" class="button" id="aitrfoel-translate-all-site" title="<?php esc_attr_e('Translate your entire site at once', 'ai-translator-for-elementor-and-polylang'); ?>">
                            <span class="aitrfoel-btn-label"><span class="aitrfoel-bulk-action-icon aitrfoel-bulk-action-icon-translate-all" aria-hidden="true"></span><?php esc_html_e('Translate All Site', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </a>
                        <button type="button" class="button button-primary" id="aitrfoel-start-translation" disabled title="Select pages and languages to start">
                            <span class="aitrfoel-btn-label"><span class="aitrfoel-bulk-action-icon aitrfoel-bulk-action-icon-start" aria-hidden="true"></span><?php esc_html_e('Start Translation', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Translation Bulk Action Bar (shown when translation checkboxes are selected) -->
            <div id="aitrfoel-translation-bulk-bar" style="display:none;">
                <span id="aitrfoel-translation-selected-count"></span>
                <button type="button" id="aitrfoel-delete-translations-btn" class="button">
                    🗑️ <?php esc_html_e( 'Delete selected translations', 'ai-translator-for-elementor-and-polylang' ); ?>
                </button>
            </div>

            <?php
            // Separate pages_data into pages, posts, and products
            $pages_only = [];
            $posts_only = [];
            $products_only = [];
            foreach ($pages_data as $group) {
                if ($group['source']->post_type === 'page') {
                    $pages_only[] = $group;
                } elseif ($group['source']->post_type === 'product') {
                    $products_only[] = $group;
                } else {
                    $posts_only[] = $group;
                }
            }
            $pages_count = count($pages_only);
            $posts_count = count($posts_only);
            $products_count = count($products_only);
            $has_woocommerce = class_exists('WooCommerce');
            ?>

            <!-- Content Type Tabs -->
            <div class="aitrfoel-content-tabs">
                <nav class="aitrfoel-tab-nav" role="tablist">
                    <button type="button" class="aitrfoel-tab-btn active" data-tab="pages" role="tab" aria-selected="true" aria-controls="aitrfoel-tab-pages">
                        <span class="meep-tab-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 3H18L21 6V20C21 20.55 20.55 21 20 21H8C7.45 21 7 20.55 7 20V4C7 3.45 7.45 3 8 3Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M18 3V7H21" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 7V20C3 20.55 3.45 21 4 21H7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                        <?php esc_html_e('Pages', 'ai-translator-for-elementor-and-polylang'); ?>
                        <span class="aitrfoel-tab-count"><?php echo absint($pages_count); ?></span>
                    </button>
                    <button type="button" class="aitrfoel-tab-btn" data-tab="posts" role="tab" aria-selected="false" aria-controls="aitrfoel-tab-posts">
                        <span class="meep-tab-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6 4H18C19.1 4 20 4.9 20 6V18C20 19.1 19.1 20 18 20H6C4.9 20 4 19.1 4 18V6C4 4.9 4.9 4 6 4Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 9H16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M8 13H16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M8 17H13" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span>
                        <?php esc_html_e('Posts', 'ai-translator-for-elementor-and-polylang'); ?>
                        <span class="aitrfoel-tab-count"><?php echo absint($posts_count); ?></span>
                    </button>
                    <?php if ($has_woocommerce || $products_count > 0) : ?>
                    <button type="button" class="aitrfoel-tab-btn" data-tab="products" role="tab" aria-selected="false" aria-controls="aitrfoel-tab-products">
                        <span class="meep-tab-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="9" cy="20" r="1.4" fill="currentColor"/><circle cx="18" cy="20" r="1.4" fill="currentColor"/><path d="M3 4H5L7.3 15.2C7.4 15.7 7.9 16 8.4 16H18.5C19 16 19.4 15.7 19.6 15.3L22 8H6.2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                        <?php esc_html_e('Products', 'ai-translator-for-elementor-and-polylang'); ?>
                        <span class="aitrfoel-tab-count"><?php echo absint($products_count); ?></span>
                    </button>
                    <?php endif; ?>
                </nav>

                <!-- Pages Tab -->
                <div id="aitrfoel-tab-pages" class="aitrfoel-tab-panel active" role="tabpanel" data-tab="pages">
                    <div class="aitrfoel-dashboard-table">
                        <table class="wp-list-table widefat fixed">
                            <thead>
                                <tr>
                                    <td id="cb" class="manage-column column-cb check-column">
                                        <input id="cb-select-all" type="checkbox" data-tab="pages">
                                    </td>
                                    <th scope="col" class="manage-column column-title">
                                        <?php esc_html_e('Page', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                    <th scope="col" class="manage-column column-languages">
                                        <?php esc_html_e('Languages', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                    <th scope="col" class="manage-column column-status">
                                        <?php esc_html_e('Translation Status', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                    <th scope="col" class="manage-column column-page-type">
                                        <?php esc_html_e('Page Type', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                    <th scope="col" class="manage-column column-actions">
                                        <?php esc_html_e('Actions', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                    <th scope="col" class="manage-column column-expand">
                                        <span class="meep-expand-col-icon" title="<?php esc_html_e('Expand/Collapse', 'ai-translator-for-elementor-and-polylang'); ?>" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 7H20" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M4 12H20" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M4 17H20" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $this->render_pages_rows($pages_only); ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Posts Tab -->
                <div id="aitrfoel-tab-posts" class="aitrfoel-tab-panel" role="tabpanel" data-tab="posts" style="display: none;">
                    <div class="aitrfoel-dashboard-table">
                        <table class="wp-list-table widefat fixed">
                            <thead>
                                <tr>
                                    <td class="manage-column column-cb check-column">
                                        <input id="cb-select-all-posts" type="checkbox" data-tab="posts">
                                    </td>
                                    <th scope="col" class="manage-column column-title">
                                        <?php esc_html_e('Post', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                    <th scope="col" class="manage-column column-languages">
                                        <?php esc_html_e('Languages', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                    <th scope="col" class="manage-column column-status">
                                        <?php esc_html_e('Translation Status', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                    <th scope="col" class="manage-column column-page-type">
                                        <?php esc_html_e('Page Type', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                    <th scope="col" class="manage-column column-actions">
                                        <?php esc_html_e('Actions', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                    <th scope="col" class="manage-column column-expand">
                                        <span class="meep-expand-col-icon" title="<?php esc_html_e('Expand/Collapse', 'ai-translator-for-elementor-and-polylang'); ?>" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 7H20" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M4 12H20" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M4 17H20" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $this->render_pages_rows($posts_only); ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if ($has_woocommerce || $products_count > 0) : ?>
                <!-- Products Tab -->
                <div id="aitrfoel-tab-products" class="aitrfoel-tab-panel" role="tabpanel" data-tab="products" style="display: none;">
                    <div class="aitrfoel-dashboard-table">
                        <table class="wp-list-table widefat fixed">
                            <thead>
                                <tr>
                                    <td class="manage-column column-cb check-column">
                                        <input id="cb-select-all-products" type="checkbox" data-tab="products">
                                    </td>
                                    <th scope="col" class="manage-column column-title">
                                        <?php esc_html_e('Product', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                    <th scope="col" class="manage-column column-languages">
                                        <?php esc_html_e('Languages', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                    <th scope="col" class="manage-column column-status">
                                        <?php esc_html_e('Translation Status', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                    <th scope="col" class="manage-column column-page-type">
                                        <?php esc_html_e('Product Type', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                    <th scope="col" class="manage-column column-actions">
                                        <?php esc_html_e('Actions', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                    <th scope="col" class="manage-column column-expand">
                                        <span class="meep-expand-col-icon" title="<?php esc_html_e('Expand/Collapse', 'ai-translator-for-elementor-and-polylang'); ?>" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 7H20" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M4 12H20" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M4 17H20" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $this->render_pages_rows($products_only); ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <?php $this->render_quick_tips(); ?>
            
            <!-- MeepTech Footer Branding -->
            <div class="aitrfoel-footer-branding">
                <span class="aitrfoel-footer-text"><?php esc_html_e('Powered by', 'ai-translator-for-elementor-and-polylang'); ?> <a href="https://meeptech.com" target="_blank" rel="noopener noreferrer">Meep Tech</a> &middot; v<?php echo esc_html(AITRFOEL_VERSION); ?></span>
            </div>
        </div>
        
        <?php
        // Enqueue dashboard scripts and styles
        $this->enqueue_dashboard_assets();
    }

    /**
     * Get pages with their translations
     */
    public function get_pages_with_translations() {
        $multilingual = class_exists('AITRFOEL_Multilingual_Manager') ? AITRFOEL_Multilingual_Manager::get_instance() : null;
        if (!$multilingual || !$multilingual->is_active()) {
            return [];
        }

        // Get all pages and posts
        $all_pages = get_posts([
            'post_type' => ['page', 'post', 'product'],
            'post_status' => ['publish', 'draft'],
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'suppress_filters' => true // Get posts in all languages for WPML
        ]);

        $translation_groups = [];
        $processed_pages = [];     // Track all post IDs already assigned to a group
        $source_id_to_group = [];  // Map source page ID to group index for merging

        $default_lang = $multilingual->get_default_language();

        foreach ($all_pages as $page) {
            // Skip if already processed as part of another translation group
            if (in_array($page->ID, $processed_pages)) {
                continue;
            }

            // Check if this is a translated post created by our plugin — skip it
            // (it will be displayed as a translation under its source post)
            $source_page_meta = get_post_meta($page->ID, '_aitrfoel_source_page', true);
            if ($source_page_meta && (int) $source_page_meta !== $page->ID) {
                $source_id = (int) $source_page_meta;
                // If the source is already in a group, add this as a translation
                if (isset($source_id_to_group[$source_id])) {
                    $gi = $source_id_to_group[$source_id];
                    $lang = $multilingual->get_post_language($page->ID);
                    if ($lang && !isset($translation_groups[$gi]['translations'][$lang])) {
                        $translation_groups[$gi]['translations'][$lang] = $page;
                    }
                    $processed_pages[] = $page->ID;
                    continue;
                }
                // Source not yet processed — skip; meta query will pick this up
                // when the source post creates its group
                $source_post = get_post($source_id);
                if ($source_post && $source_post->post_status !== 'trash') {
                    continue;
                }
                // Source is gone — fall through to create own group
            }

            $page_lang = $multilingual->get_post_language($page->ID);
            $translations = $multilingual->get_post_translations($page->ID);
            
            // Check if ANY post in this translation group is already in a processed group
            $existing_group_index = null;
            $all_group_ids = array_values(array_filter((array) $translations));
            $all_group_ids[] = $page->ID;
            
            foreach ($all_group_ids as $check_id) {
                if (in_array($check_id, $processed_pages)) {
                    // Find which group this belongs to
                    foreach ($translation_groups as $gi => $existing_group) {
                        if ($existing_group['source']->ID === $check_id) {
                            $existing_group_index = $gi;
                            break;
                        }
                        foreach ($existing_group['translations'] as $existing_trans) {
                            if ($existing_trans->ID === $check_id) {
                                $existing_group_index = $gi;
                                break 2;
                            }
                        }
                    }
                    break;
                }
            }
            
            // If this page overlaps with an existing group, merge into it and skip
            if ($existing_group_index !== null) {
                // Mark all IDs in this translation group as processed
                foreach ($all_group_ids as $gid) {
                    if (!in_array($gid, $processed_pages)) {
                        $processed_pages[] = $gid;
                        // Add as translation to existing group if not the source
                        if ($gid !== $translation_groups[$existing_group_index]['source']->ID) {
                            $lang = $multilingual->get_post_language($gid);
                            if ($lang && !isset($translation_groups[$existing_group_index]['translations'][$lang])) {
                                $post_obj = get_post($gid);
                                if ($post_obj) {
                                    $translation_groups[$existing_group_index]['translations'][$lang] = $post_obj;
                                }
                            }
                        }
                    }
                }
                continue;
            }
            
            // Find the source page - proper logic for parent determination
            // Priority 1: The post that is the TRUE original — i.e. the one that was NOT
            //   created by our plugin as a translation (has no _aitrfoel_source_page meta
            //   pointing to a different post). This correctly handles sites where the admin
            //   creates content in a non-default language (e.g. FR) and then translates to EN:
            //   the EN translation has _aitrfoel_source_page → FR, so FR is the true source.
            // Priority 2: Default language version (fallback for Polylang/WPML-managed groups
            //   where no _aitrfoel_source_page meta is set on any member).
            // Priority 3: Current page in default language.
            // Priority 4: Oldest page in the group.
            $source_page = null;

            // Build candidate list: current page first, then translation members
            $candidate_ids = array_unique(array_merge(
                [$page->ID],
                array_values(array_filter((array) $translations))
            ));

            foreach ($candidate_ids as $cid) {
                $cmeta = get_post_meta($cid, '_aitrfoel_source_page', true);
                // No source meta (or meta points to itself) → this is the true original
                if (!$cmeta || (int) $cmeta === $cid) {
                    $cpost = ($cid === $page->ID) ? $page : get_post($cid);
                    if ($cpost && $cpost->post_status !== 'trash') {
                        $source_page = $cpost;
                        break;
                    }
                }
            }

            // Fallback: default language version
            if (!$source_page) {
                if (isset($translations[$default_lang])) {
                    $source_page = get_post($translations[$default_lang]);
                } elseif ($page_lang === $default_lang) {
                    $source_page = $page;
                } else {
                    // Final fallback: oldest page in the translation group
                    $oldest_page = $page;
                    $oldest_date = $page->post_date;

                    foreach ($translations as $lang_code => $post_id) {
                        if ($post_id) {
                            $translation_post = get_post($post_id);
                            if ($translation_post && $translation_post->post_date < $oldest_date) {
                                $oldest_page = $translation_post;
                                $oldest_date = $translation_post->post_date;
                            }
                        }
                    }
                    $source_page = $oldest_page;
                }
            }

            if ($source_page) {
                // Deduplicate: if a group with the same source ID already exists, skip
                if (isset($source_id_to_group[$source_page->ID])) {
                    // Mark all translation IDs as processed
                    foreach ($translations as $lang_code => $post_id) {
                        if ($post_id && !in_array($post_id, $processed_pages)) {
                            $processed_pages[] = $post_id;
                        }
                    }
                    continue;
                }

                $group = [
                    'source' => $source_page,
                    'translations' => []
                ];

                // Add all translations to the group
                foreach ($translations as $lang_code => $post_id) {
                    if ($post_id && $post_id !== $source_page->ID) {
                        $translation_post = get_post($post_id);
                        if ($translation_post) {
                            $group['translations'][$lang_code] = $translation_post;
                            $processed_pages[] = $post_id;
                        }
                    }
                }

                // Fallback: find translations via our meta key
                // (covers cases where Polylang/WPML don't track the relationship)
                $meta_translations = get_posts([
                    'post_type'      => $source_page->post_type,
                    'meta_key'       => '_aitrfoel_source_page',
                    'meta_value'     => (string) $source_page->ID,
                    'numberposts'    => -1,
                    'post_status'    => 'any',
                    'suppress_filters' => true,
                ]);
                foreach ($meta_translations as $meta_trans) {
                    if ($meta_trans->ID !== $source_page->ID && !in_array($meta_trans->ID, $processed_pages)) {
                        $lang = $multilingual->get_post_language($meta_trans->ID);
                        if ($lang && !isset($group['translations'][$lang])) {
                            $group['translations'][$lang] = $meta_trans;
                            $processed_pages[] = $meta_trans->ID;
                        }
                    }
                }

                $group_index = count($translation_groups);
                $translation_groups[] = $group;
                $processed_pages[] = $source_page->ID;
                $source_id_to_group[$source_page->ID] = $group_index;
            }
        }

        return $translation_groups;
    }

    /**
     * Get proper display name for post status
     */
    private function get_status_display_name($status) {
        $status_names = [
            'publish' => __('Published', 'ai-translator-for-elementor-and-polylang'),
            'draft' => __('Draft', 'ai-translator-for-elementor-and-polylang'),
            'private' => __('Private', 'ai-translator-for-elementor-and-polylang'),
            'pending' => __('Pending Review', 'ai-translator-for-elementor-and-polylang'),
            'future' => __('Scheduled', 'ai-translator-for-elementor-and-polylang')
        ];
        
        return isset($status_names[$status]) ? $status_names[$status] : ucfirst($status);
    }

    /**
     * Get page type (Elementor or Standard)
     */
    private function get_page_type($page_id) {
        // Use content detector if available
        if (class_exists('AITRFOEL_Content_Detector')) {
            $detector = AITRFOEL_Content_Detector::get_instance();
            $content_type = $detector->detect($page_id);
            
            switch ($content_type) {
                case AITRFOEL_Content_Detector::TYPE_ELEMENTOR:
                    return [
                        'type' => 'elementor',
                        'label' => __('Elementor', 'ai-translator-for-elementor-and-polylang'),
                        'class' => 'aitrfoel-page-elementor',
                        'tooltip' => __('Built with Elementor - full translation support', 'ai-translator-for-elementor-and-polylang')
                    ];
                    
                case AITRFOEL_Content_Detector::TYPE_GUTENBERG:
                    return [
                        'type' => 'gutenberg',
                        'label' => __('Gutenberg', 'ai-translator-for-elementor-and-polylang'),
                        'class' => 'aitrfoel-page-gutenberg',
                        'tooltip' => __('Built with Block Editor - can be translated', 'ai-translator-for-elementor-and-polylang')
                    ];
                    
                case AITRFOEL_Content_Detector::TYPE_CLASSIC:
                    return [
                        'type' => 'classic',
                        'label' => __('Classic', 'ai-translator-for-elementor-and-polylang'),
                        'class' => 'aitrfoel-page-classic',
                        'tooltip' => __('Classic Editor content - can be translated', 'ai-translator-for-elementor-and-polylang')
                    ];
                    
                case AITRFOEL_Content_Detector::TYPE_WOOCOMMERCE:
                    return [
                        'type' => 'woocommerce',
                        'label' => __('WooCommerce', 'ai-translator-for-elementor-and-polylang'),
                        'class' => 'aitrfoel-page-woocommerce',
                        'tooltip' => __('WooCommerce product - title, description, attributes and meta are translated', 'ai-translator-for-elementor-and-polylang')
                    ];
                    
                default:
                    return [
                        'type' => 'unknown',
                        'label' => __('Unknown', 'ai-translator-for-elementor-and-polylang'),
                        'class' => 'aitrfoel-page-unknown',
                        'tooltip' => __('No translatable content detected', 'ai-translator-for-elementor-and-polylang')
                    ];
            }
        }
        
        // Fallback to original Elementor-only detection
        $elementor_data = get_post_meta($page_id, '_elementor_data', true);
        $elementor_edit_mode = get_post_meta($page_id, '_elementor_edit_mode', true);
        
        if (!empty($elementor_data) || $elementor_edit_mode === 'builder') {
            return [
                'type' => 'elementor',
                'label' => '🎨 ' . __('Elementor', 'ai-translator-for-elementor-and-polylang'),
                'class' => 'aitrfoel-page-elementor',
                'tooltip' => __('Built with Elementor - can be translated', 'ai-translator-for-elementor-and-polylang')
            ];
        } else {
            return [
                'type' => 'standard',
                'label' => __('Standard', 'ai-translator-for-elementor-and-polylang'),
                'class' => 'aitrfoel-page-standard',
                'tooltip' => __('Standard WordPress page - limited translation support', 'ai-translator-for-elementor-and-polylang')
            ];
        }
    }

    /**
     * Render table rows for pages
     */
    public function render_pages_rows($pages_data) {
        if (empty($pages_data)) {
            echo '<tr><td colspan="6">' . esc_html__('No pages or posts found.', 'ai-translator-for-elementor-and-polylang') . '</td></tr>';
            return;
        }
        
        // Get multilingual manager instance
        $multilingual = class_exists('AITRFOEL_Multilingual_Manager') ? AITRFOEL_Multilingual_Manager::get_instance() : null;
        if (!$multilingual || !$multilingual->is_active()) {
            echo '<tr><td colspan="6">' . esc_html__('Multilingual plugin not active.', 'ai-translator-for-elementor-and-polylang') . '</td></tr>';
            return;
        }

        foreach ($pages_data as $group) {
            $source = $group['source'];
            $translations = $group['translations'];
            $translation_count = count($translations);
            $available_languages = $multilingual->get_available_languages();
            $available_lang_codes = array_keys($available_languages);
            $total_languages = count($available_languages);
            
            // Render source page row
            $is_source = true;
            $checkbox_disabled = false;
            // Calculate missing languages for this page
            $source_lang = $multilingual->get_post_language($source->ID);
            $missing_languages = [];
            $existing_languages = [];
            
            foreach ($available_lang_codes as $lang_code) {
                if ($lang_code === $source_lang) {
                    $existing_languages[] = $lang_code; // Source language exists
                } elseif (isset($translations[$lang_code])) {
                    $existing_languages[] = $lang_code; // Translation exists
                } else {
                    $missing_languages[] = $lang_code; // Translation missing
                }
            }
            
            // Get the correct permalink using the multilingual manager
            $source_permalink = $multilingual->get_post_permalink($source->ID);
            $post_type_icon_class = 'aitrfoel-title-icon-page';
            $post_type_label = __('Page', 'ai-translator-for-elementor-and-polylang');
            if ($source->post_type === 'post') {
                $post_type_icon_class = 'aitrfoel-title-icon-post';
                $post_type_label = __('Post', 'ai-translator-for-elementor-and-polylang');
            } elseif ($source->post_type === 'product') {
                $post_type_icon_class = 'aitrfoel-title-icon-product';
                $post_type_label = __('Product', 'ai-translator-for-elementor-and-polylang');
            }
            ?>
            <tr class="aitrfoel-source-page" data-page-id="<?php echo absint($source->ID); ?>">
                <th scope="row" class="check-column">
                    <input type="checkbox" 
                           name="bulk_pages[]" 
                           value="<?php echo absint($source->ID); ?>"
                           class="aitrfoel-page-checkbox"
                           data-missing-languages="<?php echo esc_attr(json_encode($missing_languages)); ?>"
                           data-existing-languages="<?php echo esc_attr(json_encode($existing_languages)); ?>"
                           <?php echo $checkbox_disabled ? 'disabled' : ''; ?>>
                </th>
                <td class="column-title" data-label="<?php esc_attr_e('Title', 'ai-translator-for-elementor-and-polylang'); ?>">
                    <strong>
                        <a class="aitrfoel-page-title-link" href="<?php echo esc_url($source_permalink); ?>" target="_blank" rel="noopener">
                            <span class="aitrfoel-title-post-type-icon <?php echo esc_attr($post_type_icon_class); ?>" aria-hidden="true"></span><?php echo esc_html($source->post_title); ?>
                            <span class="aitrfoel-page-lang">(<?php echo esc_html(strtoupper($multilingual->get_post_language($source->ID) ?: '?')); ?>)</span>
                        </a>
                    </strong>
                    <div class="aitrfoel-page-status">
                        <?php echo esc_html($this->get_status_display_name($source->post_status)); ?>
                    </div>
                </td>
                <td class="column-languages" data-label="<?php esc_attr_e('Languages', 'ai-translator-for-elementor-and-polylang'); ?>">
                    <?php 
                    $available_langs = $available_lang_codes;
                    $source_lang = $multilingual->get_post_language($source->ID);
                    
                    if (!empty($available_langs)) {
                        foreach ($available_langs as $lang_code) {
                            // Check if translation exists OR if this is the source language
                            $has_translation = isset($translations[$lang_code]) || ($lang_code === $source_lang);
                            $class = $has_translation ? 'aitrfoel-lang-exists' : 'aitrfoel-lang-missing';
                            echo '<span class="aitrfoel-lang-flag ' . esc_attr($class) . '">' . esc_html(strtoupper($lang_code)) . '</span> ';
                        }
                    }
                    ?>
                </td>
                <td class="column-status" data-label="<?php esc_attr_e('Status', 'ai-translator-for-elementor-and-polylang'); ?>">
                    <span class="aitrfoel-translation-status">
                        <?php echo absint($translation_count) . '/' . absint($total_languages - 1) . ' ' . esc_html__('translated', 'ai-translator-for-elementor-and-polylang'); ?>
                    </span>
                </td>
                <td class="column-page-type" data-label="<?php esc_attr_e('Type', 'ai-translator-for-elementor-and-polylang'); ?>">
                    <?php 
                    $page_type = $this->get_page_type($source->ID);
                    ?>
                    <span class="aitrfoel-page-type <?php echo esc_attr($page_type['class']); ?>" title="<?php echo esc_attr($page_type['tooltip']); ?>">
                        <?php echo esc_html($page_type['label']); ?>
                    </span>
                </td>
                <td class="column-actions" data-label="<?php esc_attr_e('Actions', 'ai-translator-for-elementor-and-polylang'); ?>">
                    <a href="<?php echo esc_url(get_edit_post_link($source->ID)); ?>" class="button button-small button-primary aitrfoel-row-primary">
                        <?php esc_html_e('Edit', 'ai-translator-for-elementor-and-polylang'); ?>
                    </a>
                    <a href="<?php echo esc_url($source_permalink); ?>" class="button button-small" target="_blank">
                        <?php esc_html_e('View', 'ai-translator-for-elementor-and-polylang'); ?>
                    </a>
                    <button type="button" 
                            class="button button-small aitrfoel-delete-page" 
                            data-page-id="<?php echo absint($source->ID); ?>"
                            data-page-title="<?php echo esc_attr($source->post_title); ?>"
                            data-is-translation="false"
                            title="<?php esc_attr_e('Move to trash (can be restored)', 'ai-translator-for-elementor-and-polylang'); ?>">
                        <?php esc_html_e('Delete', 'ai-translator-for-elementor-and-polylang'); ?>
                    </button>
                </td>
                <td class="column-expand">
                    <?php if (!empty($translations)) : ?>
                        <button type="button" 
                                class="aitrfoel-expand-button" 
                                data-source-id="<?php echo absint($source->ID); ?>"
                                data-expanded="false"
                                aria-expanded="false"
                                title="<?php esc_html_e('Expand translations', 'ai-translator-for-elementor-and-polylang'); ?>">
                            <span class="aitrfoel-expand-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7 10L12 15L17 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                        </button>
                    <?php endif; ?>
                </td>
            </tr>
            
            <?php
            // Render translation rows (indented) - initially hidden
            foreach ($translations as $lang_code => $translation) {
                // Get the correct permalink using the multilingual manager
                $translation_permalink = $multilingual->get_post_permalink($translation->ID);
                ?>
                <tr class="aitrfoel-translation-page aitrfoel-translation-collapsed" 
                    data-parent-id="<?php echo absint($source->ID); ?>" 
                    data-page-id="<?php echo absint($translation->ID); ?>"
                    data-language="<?php echo esc_attr($lang_code); ?>"
                    style="display: none;">
                    <th scope="row" class="check-column">
                        <input type="checkbox"
                               class="aitrfoel-translation-checkbox"
                               value="<?php echo absint($translation->ID); ?>"
                               data-parent-id="<?php echo absint($source->ID); ?>"
                               data-title="<?php echo esc_attr($translation->post_title); ?>">
                    </th>
                    <td class="column-title" data-label="<?php esc_attr_e('Title', 'ai-translator-for-elementor-and-polylang'); ?>">
                        <strong class="aitrfoel-translation-indent">
                            <a class="aitrfoel-page-title-link" href="<?php echo esc_url($translation_permalink); ?>" target="_blank" rel="noopener">
                                <?php echo esc_html($translation->post_title); ?> 
                                <span class="aitrfoel-page-lang">(<?php echo esc_html(strtoupper($lang_code)); ?>)</span>
                            </a>
                        </strong>
                        <div class="aitrfoel-page-status">
                            <?php echo esc_html($this->get_status_display_name($translation->post_status)); ?>
                        </div>
                    </td>
                    <td class="column-languages" data-label="<?php esc_attr_e('Languages', 'ai-translator-for-elementor-and-polylang'); ?>">
                        <span class="aitrfoel-translation-note"><?php esc_html_e('Translation of', 'ai-translator-for-elementor-and-polylang'); ?>: <?php echo esc_html($source->post_title); ?></span>
                    </td>
                    <td class="column-status" data-label="<?php esc_attr_e('Status', 'ai-translator-for-elementor-and-polylang'); ?>">
                        <span class="aitrfoel-status-complete">✓ <?php esc_html_e('Complete', 'ai-translator-for-elementor-and-polylang'); ?></span>
                    </td>
                    <td class="column-page-type" data-label="<?php esc_attr_e('Type', 'ai-translator-for-elementor-and-polylang'); ?>">
                        <?php 
                        $page_type = $this->get_page_type($translation->ID);
                        ?>
                        <span class="aitrfoel-page-type <?php echo esc_attr($page_type['class']); ?>" title="<?php echo esc_attr($page_type['tooltip']); ?>">
                            <?php echo esc_html($page_type['label']); ?>
                        </span>
                    </td>
                    <td class="column-actions" data-label="<?php esc_attr_e('Actions', 'ai-translator-for-elementor-and-polylang'); ?>">
                        <a href="<?php echo esc_url(get_edit_post_link($translation->ID)); ?>" class="button button-small button-primary aitrfoel-row-primary">
                            <?php esc_html_e('Edit', 'ai-translator-for-elementor-and-polylang'); ?>
                        </a>
                        <a href="<?php echo esc_url($translation_permalink); ?>" class="button button-small" target="_blank">
                            <?php esc_html_e('View', 'ai-translator-for-elementor-and-polylang'); ?>
                        </a>
                        <?php
                        $review_url = add_query_arg(
                            [
                                'page'      => 'ai-translator-for-elementor-and-polylang-review',
                                'source_id' => absint($source->ID),
                                'post_id'   => absint($translation->ID),
                                'lang'      => rawurlencode($lang_code),
                            ],
                            admin_url('admin.php')
                        );
                        ?>
                        <a href="<?php echo esc_url($review_url); ?>" class="button button-small aitrfoel-review-btn">
                            <?php esc_html_e('Review', 'ai-translator-for-elementor-and-polylang'); ?>
                        </a>
                        <button type="button" 
                                class="button button-small aitrfoel-delete-page" 
                                data-page-id="<?php echo absint($translation->ID); ?>"
                                data-page-title="<?php echo esc_attr($translation->post_title); ?>"
                                data-is-translation="true"
                                title="<?php esc_attr_e('Permanently delete (cannot be undone)', 'ai-translator-for-elementor-and-polylang'); ?>">
                            <?php esc_html_e('Delete', 'ai-translator-for-elementor-and-polylang'); ?>
                        </button>
                    </td>
                    <td class="column-expand">
                        <!-- Empty cell for alignment -->
                    </td>
                </tr>
                <?php
            }
        }
    }

    /**
     * Enqueue dashboard assets
     */
    private function enqueue_dashboard_assets() {
        wp_enqueue_style(
            'aitrfoel-dashboard',
            AITRFOEL_PLUGIN_URL . 'assets/css/dashboard.css',
            ['aitrfoel-meep-admin'],
            AITRFOEL_VERSION
        );

        wp_enqueue_script(
            'aitrfoel-dashboard',
            AITRFOEL_PLUGIN_URL . 'assets/js/dashboard.js',
            ['jquery'],
            AITRFOEL_VERSION,
            true
        );

        wp_enqueue_script(
            'aitrfoel-settings-modal',
            AITRFOEL_PLUGIN_URL . 'assets/js/settings-modal.js',
            ['jquery'],
            AITRFOEL_VERSION,
            true
        );

        // Determine upgrade URL based on version type (PRO uses Freemius, FREE uses external)
        $upgrade_url = (defined('AITRFOEL_VERSION_TYPE') && AITRFOEL_VERSION_TYPE === 'pro')
            ? 'https://meeptech.com/products/ai-translator-for-elementor-polylang/#pricing'
            : 'https://meeptech.com/products/ai-translator-for-elementor-polylang#pricing';

        // Get languages from multilingual manager
        $multilingual_languages = [];
        $multilingual_manager = class_exists('AITRFOEL_Multilingual_Manager') ? AITRFOEL_Multilingual_Manager::get_instance() : null;
        if ($multilingual_manager && $multilingual_manager->is_active()) {
            $available_langs = $multilingual_manager->get_available_languages();
            foreach ($available_langs as $code => $lang) {
                $name = is_object($lang) ? ($lang->name ?? strtoupper($code)) : (is_array($lang) ? ($lang['name'] ?? strtoupper($code)) : strtoupper($code));
                $multilingual_languages[$code] = $name;
            }
        }

        wp_localize_script('aitrfoel-dashboard', 'aitrfoelDashboard', [
            'ajax_url' => esc_url(admin_url('admin-ajax.php')),
            'nonce' => wp_create_nonce('aitrfoel_ajax_nonce'),
            'upgrade_url' => esc_url($upgrade_url),
            'is_pro' => (defined('AITRFOEL_VERSION_TYPE') && AITRFOEL_VERSION_TYPE === 'pro'),
            'languages' => $multilingual_languages,
            'multilingual_plugin' => $multilingual_manager ? $multilingual_manager->get_plugin_display_name() : 'None',
            'strings' => [
                /* translators: %d: Number of pages */
                'pages_selected' => __('%d pages selected', 'ai-translator-for-elementor-and-polylang'),
                /* translators: %d: Estimated word count */
                'estimated_words' => __('Est: ~%d words', 'ai-translator-for-elementor-and-polylang'),
                'select_languages' => __('Select languages', 'ai-translator-for-elementor-and-polylang'),
                /* translators: %d: Number of languages */
                'languages_selected' => __('%d languages', 'ai-translator-for-elementor-and-polylang'),
                
                // Translation process strings
                'start_translation' => __('Start Translation', 'ai-translator-for-elementor-and-polylang'),
                'translation_in_progress' => __('Translation in Progress', 'ai-translator-for-elementor-and-polylang'),
                'translation_completed' => __('Translation completed successfully!', 'ai-translator-for-elementor-and-polylang'),
                'translation_failed' => __('Translation failed. Please try again.', 'ai-translator-for-elementor-and-polylang'),
                'translation_queued' => __('Translation queued, waiting to start', 'ai-translator-for-elementor-and-polylang'),
                'translation_paused_limit' => __('Translation paused: word limit reached', 'ai-translator-for-elementor-and-polylang'),
                'translation_failed_start' => __('Translation failed to start.', 'ai-translator-for-elementor-and-polylang'),
                'translation_already_in_progress' => __('Translation is already in progress. Please wait for completion.', 'ai-translator-for-elementor-and-polylang'),
                'translating' => __('Translating...', 'ai-translator-for-elementor-and-polylang'),
                
                // Status strings
                'checking_status' => __('Checking active translation status...', 'ai-translator-for-elementor-and-polylang'),
                'processing_translation' => __('Processing translation', 'ai-translator-for-elementor-and-polylang'),
                'status_completed' => __('Completed', 'ai-translator-for-elementor-and-polylang'),
                'status_error' => __('Error', 'ai-translator-for-elementor-and-polylang'),
                
                // Warning/validation strings
                'select_page_warning' => __('Please select at least one page to translate.', 'ai-translator-for-elementor-and-polylang'),
                'select_language_warning' => __('Please select at least one target language.', 'ai-translator-for-elementor-and-polylang'),
                'select_page_tooltip' => __('Select at least one page to start translation', 'ai-translator-for-elementor-and-polylang'),
                'select_lang_tooltip' => __('Select target languages to start translation', 'ai-translator-for-elementor-and-polylang'),
                
                // Error strings
                'network_error_analysis' => __('Network error during pre-analysis', 'ai-translator-for-elementor-and-polylang'),
                'pre_analysis_failed' => __('Pre-analysis failed', 'ai-translator-for-elementor-and-polylang'),
                
                // Progress messages
                'waiting_for_ai' => __('AI is preparing your translations', 'ai-translator-for-elementor-and-polylang'),
                'sending_content' => __('Sending content for translation', 'ai-translator-for-elementor-and-polylang'),
                'processing_data' => __('Processing the data', 'ai-translator-for-elementor-and-polylang'),
                'analyzing_structure' => __('Analyzing page structure', 'ai-translator-for-elementor-and-polylang'),
                'preparing_request' => __('Preparing translation request', 'ai-translator-for-elementor-and-polylang'),
                'processing_results' => __('Processing translation results', 'ai-translator-for-elementor-and-polylang'),
                'applying_translations' => __('Applying translations to content', 'ai-translator-for-elementor-and-polylang'),
                'finalizing_creation' => __('Finalizing page creation', 'ai-translator-for-elementor-and-polylang'),
                'optimizing_content' => __('Optimizing translated content', 'ai-translator-for-elementor-and-polylang'),
                'completing_process' => __('Completing translation process', 'ai-translator-for-elementor-and-polylang'),
                
                // Settings
                'settings' => __('Settings', 'ai-translator-for-elementor-and-polylang'),
                
                // Insufficient words modal
                'not_enough_words' => __('Not Enough Words', 'ai-translator-for-elementor-and-polylang'),
                'insufficient_words_message' => __('Insufficient words for this translation.', 'ai-translator-for-elementor-and-polylang'),
                'required' => __('Required', 'ai-translator-for-elementor-and-polylang'),
                'available' => __('Available', 'ai-translator-for-elementor-and-polylang'),
                'missing' => __('Missing', 'ai-translator-for-elementor-and-polylang'),
                'words' => __('words', 'ai-translator-for-elementor-and-polylang'),
                'reduce_or_upgrade' => __('Reduce your selection or upgrade your plan to proceed.', 'ai-translator-for-elementor-and-polylang'),
                'upgrade_plan' => __('Upgrade Plan', 'ai-translator-for-elementor-and-polylang'),
                'close' => __('Close', 'ai-translator-for-elementor-and-polylang'),

                // Review prompt
                'enjoying_plugin'     => __('Enjoying Meep AI Translator? A quick review helps us a lot!', 'ai-translator-for-elementor-and-polylang'),
                'leave_review'        => __('Leave a Review', 'ai-translator-for-elementor-and-polylang'),
                'no_thanks'           => __('No thanks', 'ai-translator-for-elementor-and-polylang'),

                // Dashboard word estimate / selection state
                'choose_pages'        => __('Choose pages to translate', 'ai-translator-for-elementor-and-polylang'),
                /* translators: %d: number of pages selected */
                'selected_pages'      => __('Selected: %d page', 'ai-translator-for-elementor-and-polylang'),
                /* translators: %d: number of pages selected (plural) */
                'selected_pages_plural' => __('Selected: %d pages', 'ai-translator-for-elementor-and-polylang'),
                /* translators: %d: number of translation jobs */
                'ready_jobs'          => __('Ready: %d translation job', 'ai-translator-for-elementor-and-polylang'),
                /* translators: %d: number of translation jobs (plural) */
                'ready_jobs_plural'   => __('Ready: %d translation jobs', 'ai-translator-for-elementor-and-polylang'),
                'all_fully_translated' => __('All selected pages are fully translated', 'ai-translator-for-elementor-and-polylang'),
            ]
        ]);

        // Localize settings modal script
        wp_localize_script('aitrfoel-settings-modal', 'aitrfoel_ajax', [
            'ajax_url' => esc_url(admin_url('admin-ajax.php')),
            'nonce' => wp_create_nonce('aitrfoel_ajax_nonce'),
        ]);
    }
    
    /**
     * Get license information and word limits
     */
    private function get_license_info() {
        // Get license status from server (authoritative source)
        $license_manager = AITRFOEL_License_Manager_Server::get_instance();
        $server_status = $license_manager->get_license_status();
        
        $license_status = $server_status['plan'];
        $word_limit = $server_status['word_limit'];
        $license_expired = isset($server_status['license_expired']) ? $server_status['license_expired'] : false;
        
        // Capitalize for display (preserve multi-word plan names like 'Agency Lifetime')
        $license_status = ucwords(strtolower($license_status));
        
        return [
            'type' => strtolower($license_status),
            'limit' => $word_limit,
            'name' => $license_status,
            'is_active' => (function_exists('eatp_fs') && eatp_fs()->is_registered()),
            'license_expired' => $license_expired,
            'freemius_validated' => isset($server_status['freemius_validated']) ? $server_status['freemius_validated'] : false,
            'addon_active' => isset($server_status['addon_active']) ? $server_status['addon_active'] : false,
            'addon_words' => isset($server_status['addon_words']) ? $server_status['addon_words'] : 0,
        ];
    }

    /**
     * Get total word count from all pages (estimate for words available to translate)
     */
    private function get_total_words_available() {
        global $wpdb;
        
        // Get all pages with Elementor content
        $pages = $this->get_elementor_pages();
        $total_words = 0;
        
        foreach ($pages as $page) {
            // Get basic word count from post content and title
            $content = $page->post_content . ' ' . $page->post_title;
            $basic_words = AITRFOEL_Translation_Engine::count_words(wp_strip_all_tags($content));
            
            // Try to get more accurate count from Elementor data if available
            $elementor_data = get_post_meta($page->ID, '_elementor_data', true);
            if (!empty($elementor_data)) {
                // This is a simplified estimation - in reality you'd want to parse Elementor data
                $elementor_words = AITRFOEL_Translation_Engine::count_words(wp_strip_all_tags($elementor_data));
                $total_words += max($basic_words, $elementor_words);
            } else {
                $total_words += $basic_words;
            }
        }
        
        return $total_words;
    }

    /**
     * Render compact stats bar for top of dashboard
     */
    private function render_compact_stats_bar() {
        // Get pages count
        $pages = $this->get_elementor_pages();
        $total_pages = count($pages);
        
        // Get word statistics from server (force fresh data, not cached)
        $license_manager = AITRFOEL_License_Manager_Server::get_instance();
        
        // Clear local cache to force fresh fetch from server
        delete_option('aitrfoel_words_used_this_month');
        delete_transient('aitrfoel_license_usage_cache');
        
        $license_status = $license_manager->get_license_status();
        
        $words_translated = $license_status['words_used'];
        
        // Get license information (this has the CORRECT limit from Freemius)
        $license_info = $this->get_license_info();
        
        // Use the correct word limit from Freemius, not from server cache
        $total_words = $license_info['limit'];
        $is_unlimited = ($total_words === -1);
        $words_left = $is_unlimited ? -1 : max(0, $total_words - $words_translated);
        
        // Calculate pages left to translate using multilingual manager
        $pages_left = 0;
        if (!empty($pages)) {
            $multilingual = class_exists('AITRFOEL_Multilingual_Manager') ? AITRFOEL_Multilingual_Manager::get_instance() : null;
            $available_languages = ($multilingual && $multilingual->is_active()) ? $multilingual->get_available_languages() : [];
            foreach ($pages as $page) {
                $translations = ($multilingual && $multilingual->is_active()) ? $multilingual->get_post_translations($page->ID) : [];
                $possible_translations = count($available_languages);
                $translations_count = count($translations);
                $missing_translations = max(0, $possible_translations - $translations_count);
                $pages_left += $missing_translations;
            }
        }

        // Usage pool reframe: percentage used + weekly cooldown reset date
        $cooldown_end = get_option('aitrfoel_cooldown_end_date', 0);
        $is_cooldown  = ($cooldown_end > 0 && $cooldown_end > time());
        $pct = 0;
        if (!$is_unlimited && $total_words > 0) {
            $pct = min(100, (int) round(($words_translated / $total_words) * 100));
        }
        $reset_label = '';
        if ($is_cooldown) {
            // translators: this becomes the day-of-week label, e.g. "Monday"
            $reset_label = date_i18n('l', $cooldown_end);
        }
        $show_nudge = !$is_unlimited && ($pct >= 80 || $is_cooldown);
        ?>
        <div class="aitrfoel-compact-stats-bar">
            <div class="aitrfoel-compact-stat">
                <span class="aitrfoel-compact-label"><?php esc_html_e('Total Pages', 'ai-translator-for-elementor-and-polylang'); ?>:</span>
                <span class="aitrfoel-compact-value"><?php echo number_format($total_pages); ?></span>
            </div>
            <div class="aitrfoel-compact-stat aitrfoel-usage-pool-stat <?php echo $is_cooldown ? 'aitrfoel-pool-cooldown' : ($pct >= 80 ? 'aitrfoel-pool-warning' : ''); ?>">
                <div class="aitrfoel-pool-text">
                    <?php if ($is_cooldown): ?>
                        <span class="aitrfoel-pool-label"><?php esc_html_e('Limit reached', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        <span class="aitrfoel-pool-reset"><?php
                            /* translators: %s: day of week when the weekly word pool resets, e.g. "Monday" */
                            printf(esc_html__('Resets %s', 'ai-translator-for-elementor-and-polylang'), esc_html($reset_label));
                        ?></span>
                    <?php elseif ($is_unlimited): ?>
                        <span class="aitrfoel-pool-label"><?php esc_html_e('Unlimited words', 'ai-translator-for-elementor-and-polylang'); ?></span>
                    <?php else: ?>
                        <span class="aitrfoel-pool-label"><?php echo esc_html($pct . '%') . ' ' . esc_html__('used this week', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        <span class="aitrfoel-pool-meta"><?php echo esc_html(number_format($words_translated) . ' / ' . number_format($total_words)); ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!$is_unlimited): ?>
                <div class="aitrfoel-pool-bar">
                    <div class="aitrfoel-pool-bar-fill" style="width:<?php echo esc_attr($pct); ?>%"></div>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($show_nudge): ?>
            <div class="aitrfoel-compact-stat aitrfoel-usage-nudge">
                <?php
                $upgrade_url = (function_exists('eatp_fs') && eatp_fs()->is_registered())
                    ? eatp_fs()->get_upgrade_url()
                    : 'https://meeptech.com/products/ai-translator-for-elementor-polylang/#pricing';
                ?>
                <span class="aitrfoel-nudge-text"><?php
                    echo $is_cooldown
                        ? esc_html__('Upgrade for instant access', 'ai-translator-for-elementor-and-polylang')
                        : esc_html__("You're almost out of words", 'ai-translator-for-elementor-and-polylang');
                ?></span>
                <a href="<?php echo esc_url($upgrade_url); ?>" class="aitrfoel-nudge-btn"><?php esc_html_e('Upgrade →', 'ai-translator-for-elementor-and-polylang'); ?></a>
            </div>
            <?php endif; ?>
            <div class="aitrfoel-compact-stat aitrfoel-compact-license">
                <div class="aitrfoel-compact-license-info">
                    <span class="aitrfoel-compact-label"><?php esc_html_e('License', 'ai-translator-for-elementor-and-polylang'); ?>:</span>
                    <?php if (!empty($license_info['license_expired'])): ?>
                        <span class="aitrfoel-compact-value aitrfoel-license-expired aitrfoel-tooltip" 
                              style="color: #dc3545;"
                              title="<?php esc_attr_e('Your license has expired. You have been downgraded to the Free plan.', 'ai-translator-for-elementor-and-polylang'); ?>">
                            ⚠️ <?php esc_html_e('Expired', 'ai-translator-for-elementor-and-polylang'); ?>
                        </span>
                    <?php else: ?>
                        <span class="aitrfoel-compact-value aitrfoel-tooltip" 
                              title="<?php /* translators: 1: Plan name, 2: Word limit */ printf(esc_attr__('%1$s Plan: %2$s words included', 'ai-translator-for-elementor-and-polylang'), 
                                             esc_html($license_info['name']), 
                                             $is_unlimited ? esc_attr__('Unlimited', 'ai-translator-for-elementor-and-polylang') : number_format($license_info['limit'])); ?>">
                            <?php echo esc_html($license_info['name']); ?>
                        </span>
                        <?php if (!empty($license_info['addon_active'])): ?>
                            <span class="aitrfoel-compact-addon-badge" title="<?php /* translators: %s: Number of extra words */ printf(esc_attr__('Extra %s words pack activated', 'ai-translator-for-elementor-and-polylang'), number_format($license_info['addon_words'])); ?>">
                                +<?php echo esc_html(number_format($license_info['addon_words'])); ?> <?php esc_html_e('bonus', 'ai-translator-for-elementor-and-polylang'); ?>
                            </span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="aitrfoel-compact-actions">
                    <?php if (!empty($license_info['license_expired'])): ?>
                        <a href="<?php echo (function_exists('eatp_fs') && eatp_fs()->is_registered()) ? esc_url(eatp_fs()->get_upgrade_url()) : esc_url('https://meeptech.com/products/ai-translator-for-elementor-polylang/#pricing'); ?>" class="aitrfoel-compact-buy-btn" style="background: #dc3545; border-color: #dc3545;">
                            <?php esc_html_e('Renew License', 'ai-translator-for-elementor-and-polylang'); ?>
                        </a>
                    <?php else: ?>
                        <a href="<?php echo (function_exists('eatp_fs') && eatp_fs()->is_registered()) ? esc_url(eatp_fs()->get_upgrade_url()) : esc_url('https://meeptech.com/products/ai-translator-for-elementor-polylang/#pricing'); ?>" class="aitrfoel-compact-buy-btn">
                            <?php esc_html_e('Buy More Words', 'ai-translator-for-elementor-and-polylang'); ?>
                        </a>
                        <a href="<?php echo (function_exists('eatp_fs') && eatp_fs()->is_registered()) ? esc_url(eatp_fs()->get_upgrade_url()) : esc_url('https://meeptech.com/products/ai-translator-for-elementor-polylang/#pricing'); ?>" class="aitrfoel-compact-upgrade-btn">
                            <?php esc_html_e('Upgrade Plan', 'ai-translator-for-elementor-and-polylang'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render plugin header with breadcrumb navigation
     */
    public function render_plugin_header($current_page = 'Dashboard') {
        // Dynamic plugin name based on version type
        $plugin_name = (defined('AITRFOEL_VERSION_TYPE') && AITRFOEL_VERSION_TYPE === 'free') 
            ? __('Meep AI Translator', 'ai-translator-for-elementor-and-polylang')
            : __('Meep AI Translator', 'ai-translator-for-elementor-and-polylang');

        $normalized_page = strtolower(trim(wp_strip_all_tags((string) $current_page)));
        $default_title = sprintf(
            /* translators: %1$s: opening span tag, %2$s: closing span tag */
            __('Translate your site, %1$spage by page%2$s.', 'ai-translator-for-elementor-and-polylang'),
            '<span class="accent">',
            '</span>'
        );
        $default_subtitle = __('Manage translations for your pages and posts. Select source content to create bulk translations.', 'ai-translator-for-elementor-and-polylang');

        $hero_text_map = [
            'dashboard' => [
                'eyebrow' => __('dashboard', 'ai-translator-for-elementor-and-polylang'),
                'title' => $default_title,
                'subtitle' => $default_subtitle,
            ],
            'settings' => [
                'eyebrow' => __('settings', 'ai-translator-for-elementor-and-polylang'),
                'title' => 'Configure your <span class="accent">translation workspace</span>.',
                'subtitle' => __('Set up languages, switcher behavior, SEO translation, WooCommerce options, glossary, and advanced rules.', 'ai-translator-for-elementor-and-polylang'),
            ],
            'translation history' => [
                'eyebrow' => __('translation history', 'ai-translator-for-elementor-and-polylang'),
                'title' => 'Review your <span class="accent">translation activity</span>.',
                'subtitle' => __('Track completed translations, monitor usage trends, and inspect your recent translation records.', 'ai-translator-for-elementor-and-polylang'),
            ],
            'translate all' => [
                'eyebrow' => __('translate all', 'ai-translator-for-elementor-and-polylang'),
                'title' => 'Translate your full site <span class="accent">in bulk</span>.',
                'subtitle' => __('Select post types and target languages, estimate words, and launch or schedule large translation batches.', 'ai-translator-for-elementor-and-polylang'),
            ],
            'review' => [
                'eyebrow' => __('review translation', 'ai-translator-for-elementor-and-polylang'),
                'title' => 'Review and refine your <span class="accent">translated content</span>.',
                'subtitle' => __('Inspect each translated segment side by side with the source. Click any cell to edit and save changes directly.', 'ai-translator-for-elementor-and-polylang'),
            ],
        ];

        $hero_text = isset($hero_text_map[$normalized_page])
            ? $hero_text_map[$normalized_page]
            : [
                'eyebrow' => $normalized_page,
                'title' => $default_title,
                'subtitle' => $default_subtitle,
            ];
        ?>
        <div class="meep-hero meep-reveal in">
            <div class="meep-hero-meta">
                <div class="meep-hero-brand">
                    <img class="meep-hero-brand-logo" src="<?php echo esc_url(AITRFOEL_PLUGIN_URL . 'assets/img/meep-ai-translator.png'); ?>" alt="" aria-hidden="true">
                    <span class="meep-hero-brand-text"><?php echo esc_html($plugin_name); ?></span>
                </div>
                <div class="meep-hero-eyebrow">// <?php echo esc_html($hero_text['eyebrow']); ?></div>
            </div>
            <h2 class="meep-hero-title"><?php echo wp_kses_post($hero_text['title']); ?></h2>
            <p class="meep-hero-sub"><?php echo esc_html($hero_text['subtitle']); ?></p>
        </div>
        <?php
    }


    
    /**
     * Get total words translated from database
     */
    private function get_total_words_translated() {
        // Use the Usage Tracker method for consistency
        if (class_exists('AITRFOEL_Usage_Tracker')) {
            $tracker = AITRFOEL_Usage_Tracker::get_instance();
            return $tracker->get_total_words_translated();
        }
        
        // Fallback to direct query
        global $wpdb;
        $table_name = $wpdb->prefix . 'aitrfoel_usage_stats';
        
        $result = $wpdb->get_var(
            "SELECT SUM(word_count) FROM $table_name"
        );
        
        return $result ? (int) $result : 0;
    }

    /**
     * Render information card for display on dashboard pages
     */
    private function render_quick_tips() {
        ?>
        <!-- Information & Review Section -->
        <div class="aitrfoel-card aitrfoel-info-review-card">
            <div class="aitrfoel-info-review-grid">
                <!-- Left Column: Information -->
                <div class="aitrfoel-info-column">
                    <h3><?php esc_html_e('Information', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    <div class="aitrfoel-info-content">
                        <p><?php esc_html_e('Translation engine is not ideal - errors can be spotted in translated pages. We are constantly updating algorithm to provide you with the best experience. If you spot a bug in translation contact us via feedback form.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                        <div class="aitrfoel-info-buttons">
                            <a href="https://meeptech.com/contact" target="_blank" class="button button-secondary">
                                <?php esc_html_e('Feedback Form', 'ai-translator-for-elementor-and-polylang'); ?>
                            </a>
                            <a href="<?php echo (function_exists('eatp_fs') && eatp_fs()->is_registered()) ? esc_url(eatp_fs()->get_upgrade_url()) : esc_url('https://meeptech.com/products/ai-translator-for-elementor-polylang/#pricing'); ?>" class="button button-primary">
                                <?php esc_html_e('Upgrade Plan', 'ai-translator-for-elementor-and-polylang'); ?>
                            </a>
                            <a href="https://meeptech.com/products/ai-translator-for-elementor-polylang/documentation" target="_blank" class="button button-secondary">
                                <?php esc_html_e('Documentation', 'ai-translator-for-elementor-and-polylang'); ?>
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column: Review Request -->
                <div class="aitrfoel-review-column">
                    <h3><?php esc_html_e('Rate Our Plugin', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    <div class="aitrfoel-review-content">
                        <p><?php esc_html_e('If you enjoy using Meep AI Translator, please take a moment to rate us on WordPress.org! Your feedback helps us improve the plugin and reach more users who could benefit from it.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                        <p style="margin: 0 0 10px 0; color: #f5b301; font-size: 18px; letter-spacing: 2px;" aria-hidden="true">★★★★★</p>
                        <span class="screen-reader-text"><?php esc_html_e('Rated 5 out of 5 on WordPress.org', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        <a href="https://wordpress.org/support/plugin/ai-translator-for-elementor-and-polylang/reviews/#new-post" target="_blank" class="button button-primary">
                            <?php esc_html_e('Leave a Review', 'ai-translator-for-elementor-and-polylang'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render Clear Translation History page
     */
    public function render_clear_history_page() {
        // Handle form submission
        if (isset($_POST['aitrfoel_clear_history_submit']) && check_admin_referer('aitrfoel_clear_history_action', 'aitrfoel_clear_history_nonce')) {
            global $wpdb;
            
            $results = [];
            
            // 1. Clear translation cache table
            $cache_table = $wpdb->prefix . 'aitrfoel_translation_cache';
            $deleted_cache = $wpdb->query("TRUNCATE TABLE $cache_table");
            /* translators: %d: Number of cache entries cleared */
            $results[] = sprintf(__('Translation cache: %d entries cleared', 'ai-translator-for-elementor-and-polylang'), $deleted_cache === false ? 0 : $deleted_cache);
            
            // 2. Clear usage stats table
            $stats_table = $wpdb->prefix . 'aitrfoel_usage_stats';
            $deleted_stats = $wpdb->query("TRUNCATE TABLE $stats_table");
            /* translators: %d: Number of statistics entries cleared */
            $results[] = sprintf(__('Usage statistics: %d entries cleared', 'ai-translator-for-elementor-and-polylang'), $deleted_stats === false ? 0 : $deleted_stats);
            
            // 3. Clear WordPress options
            delete_option('aitrfoel_words_used_this_month');
            delete_option('aitrfoel_last_usage_reset');
            delete_option('aitrfoel_cooldown_expiry');
            delete_option('aitrfoel_limit_reached_timestamp');
            delete_option('aitrfoel_words_since_verification');
            delete_option('aitrfoel_last_license_verification');
            $results[] = __('WordPress options cleared', 'ai-translator-for-elementor-and-polylang');
            
            // 4. Clear transients
            delete_transient('aitrfoel_debug_logs_' . get_current_user_id());
            $results[] = __('Debug logs cleared', 'ai-translator-for-elementor-and-polylang');
            
            // Show success message
            echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__('✅ Translation History Cleared!', 'ai-translator-for-elementor-and-polylang') . '</strong></p><ul>';
            foreach ($results as $result) {
                echo '<li>' . esc_html($result) . '</li>';
            }
            echo '</ul></div>';
            
            // Log the action
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log('Translation history manually cleared by user', 'info');
            }
        }
        
        // Get current statistics
        global $wpdb;
        $cache_table = $wpdb->prefix . 'aitrfoel_translation_cache';
        $stats_table = $wpdb->prefix . 'aitrfoel_usage_stats';
        
        $cache_count = $wpdb->get_var("SELECT COUNT(*) FROM $cache_table");
        $stats_count = $wpdb->get_var("SELECT COUNT(*) FROM $stats_table");
        $words_used = get_option('aitrfoel_words_used_this_month', 0);
        $cooldown_expiry = get_option('aitrfoel_cooldown_expiry', 0);
        
        ?>
        <div class="wrap">
            <h1 style="color: #dc3232;">🗑️ <?php esc_html_e('Clear Translation History', 'ai-translator-for-elementor-and-polylang'); ?></h1>
            
            <div class="aitrfoel-card" style="max-width: 800px; margin-top: 20px;">
                <h2><?php esc_html_e('⚠️ Warning: Destructive Action', 'ai-translator-for-elementor-and-polylang'); ?></h2>
                
                <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0;">
                    <p style="margin: 0; font-size: 14px;">
                        <strong><?php esc_html_e('This action will permanently delete:', 'ai-translator-for-elementor-and-polylang'); ?></strong>
                    </p>
                    <ul style="margin: 10px 0 0 20px;">
                        <li><?php esc_html_e('All cached translations (to force re-translation)', 'ai-translator-for-elementor-and-polylang'); ?></li>
                        <li><?php esc_html_e('All usage statistics and word counts', 'ai-translator-for-elementor-and-polylang'); ?></li>
                        <li><?php esc_html_e('Cooldown timers and limits', 'ai-translator-for-elementor-and-polylang'); ?></li>
                        <li><?php esc_html_e('Debug logs and temporary data', 'ai-translator-for-elementor-and-polylang'); ?></li>
                    </ul>
                    <p style="margin: 10px 0 0 0; font-size: 13px; color: #856404;">
                        <?php esc_html_e('⚠️ This is useful for testing but will increase API costs as translations will not be cached.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                </div>
                
                <h3><?php esc_html_e('Current Statistics', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                <table class="widefat" style="margin-bottom: 20px;">
                    <tbody>
                        <tr>
                            <td style="width: 40%;"><strong><?php esc_html_e('Cached Translations', 'ai-translator-for-elementor-and-polylang'); ?></strong></td>
                            <td><?php echo number_format($cache_count); ?> <?php esc_html_e('entries', 'ai-translator-for-elementor-and-polylang'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Usage Records', 'ai-translator-for-elementor-and-polylang'); ?></strong></td>
                            <td><?php echo number_format($stats_count); ?> <?php esc_html_e('records', 'ai-translator-for-elementor-and-polylang'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Words This Month', 'ai-translator-for-elementor-and-polylang'); ?></strong></td>
                            <td><?php echo number_format($words_used); ?> <?php esc_html_e('words', 'ai-translator-for-elementor-and-polylang'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Cooldown Status', 'ai-translator-for-elementor-and-polylang'); ?></strong></td>
                            <td>
                                <?php 
                                if ($cooldown_expiry && time() < $cooldown_expiry) {
                                    $remaining = human_time_diff(time(), $cooldown_expiry);
                                    /* translators: %s: Time remaining in human-readable format */
                                    echo '<span style="color: #dc3232;">⏳ ' . sprintf(esc_html__('Active (%s remaining)', 'ai-translator-for-elementor-and-polylang'), esc_html($remaining)) . '</span>';
                                } else {
                                    echo '<span style="color: #46b450;">✅ ' . esc_html__('Not active', 'ai-translator-for-elementor-and-polylang') . '</span>';
                                }
                                ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <form method="post" action="" onsubmit="return confirm('<?php echo esc_js(__('Are you ABSOLUTELY SURE you want to delete ALL translation history? This cannot be undone!', 'ai-translator-for-elementor-and-polylang')); ?>');">
                    <?php wp_nonce_field('aitrfoel_clear_history_action', 'aitrfoel_clear_history_nonce'); ?>
                    
                    <p>
                        <button type="submit" name="aitrfoel_clear_history_submit" class="button button-primary button-large" style="background: #dc3232; border-color: #dc3232; text-shadow: none; box-shadow: none;">
                            🗑️ <?php esc_html_e('Clear All Translation History', 'ai-translator-for-elementor-and-polylang'); ?>
                        </button>
                    </p>
                    
                    <p class="description">
                        <?php esc_html_e('This is a temporary debug tool to help test translations. Use with caution!', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                </form>
                
                <hr style="margin: 30px 0;">
                
                <h3><?php esc_html_e('💡 What happens after clearing?', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                <ul style="line-height: 1.8;">
                    <li>✅ <?php esc_html_e('You can immediately start new translations', 'ai-translator-for-elementor-and-polylang'); ?></li>
                    <li>✅ <?php esc_html_e('Cooldown period will be removed', 'ai-translator-for-elementor-and-polylang'); ?></li>
                    <li>✅ <?php esc_html_e('Word count limit resets to 0', 'ai-translator-for-elementor-and-polylang'); ?></li>
                    <li>⚠️ <?php esc_html_e('All translations will be fetched from OpenAI API again (costs money)', 'ai-translator-for-elementor-and-polylang'); ?></li>
                    <li>⚠️ <?php esc_html_e('Previously translated pages remain unchanged', 'ai-translator-for-elementor-and-polylang'); ?></li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * Render setup checklist widget on the dashboard
     * Shows until all steps are complete or user dismisses it
     */
    private function render_setup_checklist() {
        // Don't show if user dismissed it
        if (get_user_meta(get_current_user_id(), 'aitrfoel_setup_checklist_dismissed', true)) {
            return;
        }

        $checklist = $this->get_setup_checklist_items();

        // Don't show if all items are complete
        $all_complete = true;
        foreach ($checklist as $item) {
            if (!$item['complete']) {
                $all_complete = false;
                break;
            }
        }
        if ($all_complete) {
            return;
        }

        $completed_count = count(array_filter($checklist, function($item) { return $item['complete']; }));
        $total_count = count($checklist);
        $progress_pct = round(($completed_count / $total_count) * 100);
        ?>
        <div class="aitrfoel-setup-checklist" id="aitrfoel-setup-checklist">
            <div class="aitrfoel-setup-checklist-header">
                <div class="aitrfoel-setup-checklist-title">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php esc_html_e('Setup Checklist', 'ai-translator-for-elementor-and-polylang'); ?>
                    <span class="aitrfoel-setup-checklist-progress-text">
                        <?php
                        /* translators: 1: completed steps, 2: total steps */
                        printf(esc_html__('%1$d of %2$d completed', 'ai-translator-for-elementor-and-polylang'), $completed_count, $total_count);
                        ?>
                    </span>
                </div>
                <button type="button" class="aitrfoel-setup-checklist-dismiss" id="aitrfoel-dismiss-checklist" title="<?php esc_attr_e('Dismiss checklist', 'ai-translator-for-elementor-and-polylang'); ?>">
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </div>
            <div class="aitrfoel-setup-checklist-progress">
                <div class="aitrfoel-setup-checklist-progress-bar" style="width: <?php echo absint($progress_pct); ?>%;"></div>
            </div>
            <ul class="aitrfoel-setup-checklist-items">
                <?php foreach ($checklist as $item): ?>
                <li class="aitrfoel-setup-checklist-item <?php echo $item['complete'] ? 'is-complete' : ''; ?>">
                    <span class="aitrfoel-setup-check-icon">
                        <?php if ($item['complete']): ?>
                            <span class="dashicons dashicons-yes"></span>
                        <?php else: ?>
                            <span class="aitrfoel-setup-check-empty"></span>
                        <?php endif; ?>
                    </span>
                    <div class="aitrfoel-setup-check-content">
                        <strong><?php echo esc_html($item['title']); ?></strong>
                        <p><?php echo esc_html($item['description']); ?></p>
                    </div>
                    <?php if (!$item['complete'] && !empty($item['action_url'])): ?>
                        <a href="<?php echo esc_url($item['action_url']); ?>" class="button button-small">
                            <?php echo esc_html($item['action_label']); ?>
                        </a>
                    <?php endif; ?>
                    <?php if (!$item['complete']): ?>
                        <button type="button" class="button button-small button-link aitrfoel-setup-skip-btn" data-step="<?php echo esc_attr($item['key']); ?>">
                            <?php esc_html_e('Skip', 'ai-translator-for-elementor-and-polylang'); ?>
                        </button>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /**
     * Get setup checklist items with their completion status
     */
    private function get_setup_checklist_items() {
        $items = [];
        $skipped = (array) get_user_meta(get_current_user_id(), 'aitrfoel_setup_checklist_skipped', true);

        // 1. Multilingual system configured with 2+ languages
        $multilingual = class_exists('AITRFOEL_Multilingual_Manager') ? AITRFOEL_Multilingual_Manager::get_instance() : null;
        $has_languages = $multilingual && $multilingual->is_active()
            && method_exists($multilingual, 'has_languages_configured') && $multilingual->has_languages_configured();
        $detected_plugin = $multilingual ? $multilingual->get_detected_plugin() : '';
        $lang_settings_url = admin_url('admin.php?page=ai-translator-for-elementor-and-polylang-settings');
        if ($detected_plugin === 'polylang') {
            $lang_settings_url = admin_url('admin.php?page=mlang');
        } elseif ($detected_plugin === 'wpml') {
            $lang_settings_url = admin_url('admin.php?page=sitepress-multilingual-cms/menu/languages.php');
        }

        $items[] = [
            'key'         => 'languages',
            'title'       => __('Languages Configured', 'ai-translator-for-elementor-and-polylang'),
            'description' => __('At least two languages are set up for translation.', 'ai-translator-for-elementor-and-polylang'),
            'complete'    => $has_languages || in_array('languages', $skipped),
            'skipped'     => in_array('languages', $skipped),
            'action_url'  => $lang_settings_url,
            'action_label'=> __('Configure Languages', 'ai-translator-for-elementor-and-polylang'),
        ];

        // 3. First translation completed
        $has_translation = false;
        if ($multilingual && $multilingual->is_active()) {
            $pages = get_posts([
                'post_type'      => ['page', 'post'],
                'post_status'    => ['publish', 'draft'],
                'posts_per_page' => 50,
                'suppress_filters' => true,
                'meta_query'     => [['key' => '_elementor_data', 'compare' => 'EXISTS']],
            ]);
            foreach ($pages as $page) {
                $translations = $multilingual->get_post_translations($page->ID);
                if (count($translations) > 1) {
                    $has_translation = true;
                    break;
                }
            }
        }

        $items[] = [
            'key'         => 'first_translation',
            'title'       => __('First Translation', 'ai-translator-for-elementor-and-polylang'),
            'description' => __('Translate your first page or post into another language.', 'ai-translator-for-elementor-and-polylang'),
            'complete'    => $has_translation || in_array('first_translation', $skipped),
            'skipped'     => in_array('first_translation', $skipped),
            'action_url'  => '',
            'action_label'=> '',
        ];

        // 4. Language switcher configured
        $switcher_configured = false;
        if ($detected_plugin === 'builtin') {
            $switcher_configured = (bool) get_option('aitrfoel_nav_menu_switcher', false);
            if (!$switcher_configured) {
                // Check if shortcode is used in any widget or content
                $switcher_configured = (bool) get_option('aitrfoel_switcher_style', '');
            }
        } else {
            // For Polylang/WPML the switcher is managed by those plugins; mark complete
            $switcher_configured = true;
        }

        $items[] = [
            'key'         => 'switcher',
            'title'       => __('Language Switcher', 'ai-translator-for-elementor-and-polylang'),
            'description' => __('Let visitors switch between languages on the front end.', 'ai-translator-for-elementor-and-polylang'),
            'complete'    => $switcher_configured || in_array('switcher', $skipped),
            'skipped'     => in_array('switcher', $skipped),
            'action_url'  => admin_url('admin.php?page=ai-translator-for-elementor-and-polylang-settings#language-switcher'),
            'action_label'=> __('Set Up Switcher', 'ai-translator-for-elementor-and-polylang'),
        ];

        return $items;
    }

    /**
     * AJAX handler: dismiss the setup checklist
     */
    public function handle_dismiss_setup_checklist() {
        check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        update_user_meta(get_current_user_id(), 'aitrfoel_setup_checklist_dismissed', true);
        wp_send_json_success();
    }

    /**
     * AJAX handler: skip a single setup checklist step
     */
    public function handle_skip_setup_step() {
        check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $step = isset($_POST['step']) ? sanitize_key($_POST['step']) : '';
        $allowed = ['languages', 'first_translation', 'switcher'];
        if (!in_array($step, $allowed, true)) {
            wp_send_json_error('Invalid step', 400);
        }

        $skipped = (array) get_user_meta(get_current_user_id(), 'aitrfoel_setup_checklist_skipped', true);
        if (!in_array($step, $skipped, true)) {
            $skipped[] = $step;
            update_user_meta(get_current_user_id(), 'aitrfoel_setup_checklist_skipped', $skipped);
        }

        wp_send_json_success();
    }

    /**
     * Register setup checklist as a WordPress dashboard widget
     */
    public function register_dashboard_widget() {
        if (!current_user_can('manage_options')) {
            return;
        }
        // Don't register if dismissed or all complete
        if (get_user_meta(get_current_user_id(), 'aitrfoel_setup_checklist_dismissed', true)) {
            return;
        }
        $checklist = $this->get_setup_checklist_items();
        $all_complete = true;
        foreach ($checklist as $item) {
            if (!$item['complete']) { $all_complete = false; break; }
        }
        if ($all_complete) {
            return;
        }

        wp_add_dashboard_widget(
            'aitrfoel_setup_checklist_widget',
            __('AI Translator - Setup Checklist', 'ai-translator-for-elementor-and-polylang'),
            [$this, 'render_wp_dashboard_widget']
        );
    }

    /**
     * Render the WordPress admin dashboard widget content
     */
    public function render_wp_dashboard_widget() {
        // Enqueue our dashboard CSS on the WP dashboard
        wp_enqueue_style(
            'aitrfoel-dashboard',
            AITRFOEL_PLUGIN_URL . 'assets/css/dashboard.css',
            [],
            defined('AITRFOEL_VERSION') ? AITRFOEL_VERSION : '1.0.0'
        );

        $checklist = $this->get_setup_checklist_items();
        $completed_count = count(array_filter($checklist, function($item) { return $item['complete']; }));
        $total_count = count($checklist);
        $progress_pct = round(($completed_count / $total_count) * 100);
        ?>
        <div class="aitrfoel-setup-checklist aitrfoel-setup-checklist--wp-dashboard">
            <div class="aitrfoel-setup-checklist-progress">
                <div class="aitrfoel-setup-checklist-progress-bar" style="width: <?php echo absint($progress_pct); ?>%;"></div>
            </div>
            <p class="aitrfoel-setup-checklist-progress-text" style="margin: 8px 0;">
                <?php
                /* translators: 1: completed steps, 2: total steps */
                printf(esc_html__('%1$d of %2$d steps completed', 'ai-translator-for-elementor-and-polylang'), $completed_count, $total_count);
                ?>
            </p>
            <ul class="aitrfoel-setup-checklist-items">
                <?php foreach ($checklist as $item): ?>
                <li class="aitrfoel-setup-checklist-item <?php echo $item['complete'] ? 'is-complete' : ''; ?>">
                    <span class="aitrfoel-setup-check-icon">
                        <?php if ($item['complete']): ?>
                            <span class="dashicons dashicons-yes"></span>
                        <?php else: ?>
                            <span class="aitrfoel-setup-check-empty"></span>
                        <?php endif; ?>
                    </span>
                    <div class="aitrfoel-setup-check-content">
                        <strong><?php echo esc_html($item['title']); ?></strong>
                        <p><?php echo esc_html($item['description']); ?></p>
                    </div>
                    <?php if (!$item['complete'] && !empty($item['action_url'])): ?>
                        <a href="<?php echo esc_url($item['action_url']); ?>" class="button button-small">
                            <?php echo esc_html($item['action_label']); ?>
                        </a>
                    <?php endif; ?>
                    <?php if (!$item['complete']): ?>
                        <button type="button" class="button button-small button-link aitrfoel-setup-skip-btn" data-step="<?php echo esc_attr($item['key']); ?>">
                            <?php esc_html_e('Skip', 'ai-translator-for-elementor-and-polylang'); ?>
                        </button>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <p style="margin: 8px 0 0;"><a href="<?php echo esc_url(admin_url('admin.php?page=ai-translator-for-elementor-and-polylang')); ?>"><?php esc_html_e('Go to AI Translator Dashboard', 'ai-translator-for-elementor-and-polylang'); ?></a></p>
        </div>
        <script>
        jQuery(function($){
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce = <?php echo wp_json_encode(wp_create_nonce('aitrfoel_ajax_nonce')); ?>;
            $('#aitrfoel_setup_checklist_widget').on('click', '.aitrfoel-setup-skip-btn', function(){
                var $btn = $(this), step = $btn.data('step');
                var $item = $btn.closest('.aitrfoel-setup-checklist-item');
                $item.addClass('is-complete');
                $item.find('.aitrfoel-setup-check-empty').replaceWith('<span class="dashicons dashicons-yes"></span>');
                $item.find('.aitrfoel-setup-check-content strong').css({'text-decoration':'line-through','color':'#64748b'});
                $item.find('.button-small').remove();
                var $cl = $item.closest('.aitrfoel-setup-checklist--wp-dashboard');
                var total = $cl.find('.aitrfoel-setup-checklist-item').length;
                var done = $cl.find('.aitrfoel-setup-checklist-item.is-complete').length;
                $cl.find('.aitrfoel-setup-checklist-progress-bar').css('width', Math.round((done/total)*100)+'%');
                $cl.find('.aitrfoel-setup-checklist-progress-text').text(done+' of '+total+' steps completed');
                if(done>=total){setTimeout(function(){$cl.slideUp(300);},400);}
                $.post(ajaxUrl,{action:'aitrfoel_skip_setup_step',nonce:nonce,step:step});
            });
        });
        </script>
        <?php
    }
}