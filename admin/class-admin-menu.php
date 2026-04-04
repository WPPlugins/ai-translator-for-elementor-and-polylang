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
        add_action('wp_ajax_aitrfoel_setup_builtin_languages', [$this, 'handle_setup_builtin_languages']);
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
            <div class="eat-stats-grid">
                <div class="eat-stat-card eat-stat-flex">
                    <div class="eat-stat-label"><?php esc_html_e('Cached Translations', 'ai-translator-for-elementor-and-polylang'); ?></div>
                    <div class="eat-stat-number"><?php echo number_format($stats['cached_translations']); ?></div>
                </div>
                <div class="eat-stat-card eat-stat-flex">
                    <div class="eat-stat-label"><?php esc_html_e('Recent API Calls', 'ai-translator-for-elementor-and-polylang'); ?></div>
                    <div class="eat-stat-number"><?php echo number_format($stats['recent_api_calls']); ?></div>
                </div>
                <div class="eat-stat-card eat-stat-flex">
                    <div class="eat-stat-label"><?php esc_html_e('Active Model', 'ai-translator-for-elementor-and-polylang'); ?></div>
                    <div class="eat-stat-number" style="font-size: 1.2em;"><?php echo esc_html($stats['model']); ?></div>
                </div>
                <div class="eat-stat-card eat-stat-flex">
                    <div class="eat-stat-label"><?php esc_html_e('Pages Available', 'ai-translator-for-elementor-and-polylang'); ?></div>
                    <div class="eat-stat-number"><?php echo count($pages); ?></div>
                </div>
            </div>
            
            <!-- Translation Interface -->
            <div class="eat-card" id="eat-translator-app">
                <h2><?php esc_html_e('Translate Content', 'ai-translator-for-elementor-and-polylang'); ?></h2>
                
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="eat-page-select"><?php esc_html_e('Select Page', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <select id="eat-page-select" style="width: 100%; max-width: 500px;">
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
                                <label for="eat-source-lang"><?php esc_html_e('Source Language', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <select id="eat-source-lang" style="width: 100%; max-width: 500px;">
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
                                <label for="eat-target-lang"><?php esc_html_e('Target Language', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            </th>
                            <td>
                                <select id="eat-target-lang" style="width: 100%; max-width: 500px;">
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
                <div id="eat-string-selection-area" style="display: none;">
                    <h3><?php esc_html_e('Select Content to Translate', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    <p>
                        <label>
                            <input type="checkbox" id="eat-select-all" />
                            <strong><?php esc_html_e('Select All', 'ai-translator-for-elementor-and-polylang'); ?></strong>
                        </label>
                        <span style="margin-left: 20px; color: #666;">
                            <?php esc_html_e('Total strings:', 'ai-translator-for-elementor-and-polylang'); ?>
                            <span id="eat-string-count">0</span>
                        </span>
                    </p>
                    <div id="eat-string-list"></div>
                </div>
                
                <!-- Action Buttons -->
                <div class="eat-button-group">
                    <button id="eat-fetch-strings-button" class="button button-primary button-large">
                        <?php esc_html_e('Fetch Content', 'ai-translator-for-elementor-and-polylang'); ?>
                    </button>
                    <button id="eat-estimate-cost-btn" class="button button-secondary button-large">
                        <?php esc_html_e('💰 Estimate Cost', 'ai-translator-for-elementor-and-polylang'); ?>
                    </button>
                    <button id="eat-translate-button" class="button button-primary button-large" style="display: none;">
                        <?php esc_html_e('Translate Selected', 'ai-translator-for-elementor-and-polylang'); ?>
                    </button>
                    <button id="aitrfoel-clear-cache-button" class="button button-secondary">
                        <?php esc_html_e('Clear Translation Cache', 'ai-translator-for-elementor-and-polylang'); ?>
                    </button>
                    <button id="eat-reset-button" class="button button-secondary" style="display: none;">
                        <?php esc_html_e('Reset', 'ai-translator-for-elementor-and-polylang'); ?>
                    </button>
                    <span class="spinner"></span>
                </div>
                
                <!-- Cost Estimation Area -->
                <div id="eat-cost-estimation-area"></div>
                
                <!-- Usage Statistics -->
                <div id="eat-usage-stats-container">
                    <div class="eat-usage-stats-header">
                        <h3><?php esc_html_e('Monthly Usage Statistics', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                        <button id="eat-refresh-stats-btn" class="button button-small">
                            <?php esc_html_e('🔄 Refresh Stats', 'ai-translator-for-elementor-and-polylang'); ?>
                        </button>
                    </div>
                    <div id="eat-usage-stats-display"></div>
                </div>
                
                <!-- Status Messages -->
                <div id="eat-status"></div>
            </div>
            
            <!-- Information & Review Section -->
            <div class="eat-card eat-info-review-card">
                <div class="eat-info-review-grid">
                    <!-- Left Column: Information -->
                    <div class="eat-info-column">
                        <h3><?php esc_html_e('Information', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                        <div class="eat-info-content">
                            <p><?php esc_html_e('Translation engine is not ideal - errors can be spotted in translated pages. We are constantly updating algorithm to provide you with the best experience. If you spot a bug in translation contact us via feedback form.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                            <div class="eat-info-buttons">
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
                    <div class="eat-review-column">
                        <h3><?php esc_html_e('Rate Our Plugin', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                        <div class="eat-review-content">
                            <p><?php esc_html_e('If you enjoy using Meep AI Translator, please take a moment to rate us on WordPress.org! Your feedback helps us improve the plugin and reach more users who could benefit from it.', 'ai-translator-for-elementor-and-polylang'); ?></p>
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
            
            <div class="eat-card" style="border-left: 4px solid #ff9800;">
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
        // Enqueue wizard CSS
        wp_enqueue_style(
            'aitrfoel-onboarding-wizard',
            AITRFOEL_PLUGIN_URL . 'assets/css/onboarding-wizard.css',
            [],
            defined('AITRFOEL_VERSION') ? AITRFOEL_VERSION : '1.3.2'
        );
        
        wp_enqueue_style(
            'aitrfoel-modern-design',
            AITRFOEL_PLUGIN_URL . 'assets/css/modern-design.css',
            ['aitrfoel-onboarding-wizard'],
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
        $polylang_active = function_exists('pll_languages_list');
        $wpml_active = defined('ICL_SITEPRESS_VERSION');
        $polylang_installed = $polylang_active || file_exists(WP_PLUGIN_DIR . '/polylang/polylang.php');
        $wpml_installed = $wpml_active || file_exists(WP_PLUGIN_DIR . '/sitepress-multilingual-cms/sitepress.php');
        ?>
        <!-- Hide default WP admin notices and Elementor updater -->
        <style>
            .wrap > .notice, .wrap > .error, .wrap > .updated,
            #wpbody-content > .notice, #wpbody-content > .error, #wpbody-content > .updated,
            #wpbody-content > div.notice, .notice:not(.aitrfoel-wizard-wrap .notice),
            div.error, div.updated,
            .e-notice, .e-notice--warning, .e-notice--dismissible,
            .aitrfoel-wizard-wrap .e-notice,
            .aitrfoel-wizard-wrap .notice,
            .aitrfoel-wiz-panel .e-notice,
            .aitrfoel-wiz-panel .notice { display: none !important; }
        </style>

        <div class="wrap">
        <div class="aitrfoel-wizard-wrap">
        
        <!-- Progress Bar -->
        <div class="aitrfoel-wiz-progress">
            <div class="aitrfoel-wiz-step-indicator">
                <div class="aitrfoel-wiz-dot active" data-step="1">1</div>
                <div class="aitrfoel-wiz-line" data-after="1"></div>
                <div class="aitrfoel-wiz-dot" data-step="2">2</div>
                <div class="aitrfoel-wiz-line" data-after="2"></div>
                <div class="aitrfoel-wiz-dot" data-step="3">3</div>
                <div class="aitrfoel-wiz-line" data-after="3"></div>
                <div class="aitrfoel-wiz-dot" data-step="4">4</div>
            </div>
        </div>

        <div class="aitrfoel-wiz-card">

            <!-- ═══ STEP 1: Welcome + System Choice ═══ -->
            <div class="aitrfoel-wiz-panel active" data-panel="1">
                <div class="aitrfoel-wiz-welcome">
                    <div class="aitrfoel-wiz-logo">
                        <img src="<?php echo esc_url(AITRFOEL_PLUGIN_URL . 'assets/img/meeptech.svg'); ?>" alt="Meep Tech" width="200" height="39">
                    </div>
                    <h1><?php esc_html_e('Welcome to Meep AI Translator', 'ai-translator-for-elementor-and-polylang'); ?></h1>
                    <p class="aitrfoel-wiz-subtitle">
                        <?php esc_html_e('Choose how you want to manage languages on your site.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                    
                    <div class="aitrfoel-wiz-system-choice">
                        <!-- Built-in (recommended) -->
                        <label class="aitrfoel-wiz-system-card selected" data-system="builtin">
                            <input type="radio" name="aitrfoel_system_choice" value="builtin" checked>
                            <div class="aitrfoel-wiz-system-card-inner">
                                <div class="aitrfoel-wiz-system-badge"><?php esc_html_e('Recommended', 'ai-translator-for-elementor-and-polylang'); ?></div>
                                <div class="aitrfoel-wiz-system-icon">
                                    <svg width="32" height="32" viewBox="0 0 32 32" fill="none"><circle cx="16" cy="16" r="14" stroke="#2271b1" stroke-width="2"/><path d="M10 16l4 4 8-8" stroke="#2271b1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </div>
                                <h3><?php esc_html_e('Built-in Language System', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                                <p><?php esc_html_e('Lightweight, no extra plugins needed. Includes URL prefixes, hreflang tags, and a language switcher.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                            </div>
                        </label>
                        
                        <!-- Polylang -->
                        <label class="aitrfoel-wiz-system-card" data-system="polylang">
                            <input type="radio" name="aitrfoel_system_choice" value="polylang">
                            <div class="aitrfoel-wiz-system-card-inner">
                                <?php if ($polylang_active): ?>
                                    <div class="aitrfoel-wiz-system-status status-active"><?php esc_html_e('Active', 'ai-translator-for-elementor-and-polylang'); ?></div>
                                <?php elseif ($polylang_installed): ?>
                                    <div class="aitrfoel-wiz-system-status status-inactive"><?php esc_html_e('Installed (inactive)', 'ai-translator-for-elementor-and-polylang'); ?></div>
                                <?php else: ?>
                                    <div class="aitrfoel-wiz-system-status status-missing"><?php esc_html_e('Not installed', 'ai-translator-for-elementor-and-polylang'); ?></div>
                                <?php endif; ?>
                                <div class="aitrfoel-wiz-system-icon">
                                    <svg width="32" height="32" viewBox="0 0 32 32" fill="none"><circle cx="16" cy="16" r="14" stroke="#00a32a" stroke-width="2"/><text x="16" y="21" text-anchor="middle" font-size="14" font-weight="bold" fill="#00a32a">P</text></svg>
                                </div>
                                <h3>Polylang</h3>
                                <p><?php esc_html_e('Full-featured multilingual plugin with extensive add-ons and community support.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                            </div>
                        </label>
                        
                        <!-- WPML -->
                        <label class="aitrfoel-wiz-system-card" data-system="wpml">
                            <input type="radio" name="aitrfoel_system_choice" value="wpml">
                            <div class="aitrfoel-wiz-system-card-inner">
                                <?php if ($wpml_active): ?>
                                    <div class="aitrfoel-wiz-system-status status-active"><?php esc_html_e('Active', 'ai-translator-for-elementor-and-polylang'); ?></div>
                                <?php elseif ($wpml_installed): ?>
                                    <div class="aitrfoel-wiz-system-status status-inactive"><?php esc_html_e('Installed (inactive)', 'ai-translator-for-elementor-and-polylang'); ?></div>
                                <?php else: ?>
                                    <div class="aitrfoel-wiz-system-status status-missing"><?php esc_html_e('Not installed', 'ai-translator-for-elementor-and-polylang'); ?></div>
                                <?php endif; ?>
                                <div class="aitrfoel-wiz-system-icon">
                                    <svg width="32" height="32" viewBox="0 0 32 32" fill="none"><circle cx="16" cy="16" r="14" stroke="#33769e" stroke-width="2"/><text x="16" y="21" text-anchor="middle" font-size="12" font-weight="bold" fill="#33769e">W</text></svg>
                                </div>
                                <h3>WPML</h3>
                                <p><?php esc_html_e('Premium multilingual solution with professional translation management.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                            </div>
                        </label>
                    </div>

                    <button type="button" class="aitrfoel-wiz-btn aitrfoel-wiz-btn-primary" id="aitrfoel-wiz-start">
                        <?php esc_html_e('Continue', 'ai-translator-for-elementor-and-polylang'); ?>
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M6 3l5 5-5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                </div>
            </div>

            <!-- ═══ STEP 2: Installing ═══ -->
            <div class="aitrfoel-wiz-panel" data-panel="2">
                <div class="aitrfoel-wiz-installing">
                    <div class="aitrfoel-wiz-spinner-ring" id="aitrfoel-wiz-spinner">
                        <svg viewBox="0 0 80 80" width="80" height="80">
                            <circle cx="40" cy="40" r="34" stroke="#e0e0e0" stroke-width="6" fill="none"/>
                            <circle cx="40" cy="40" r="34" stroke="url(#spinGrad)" stroke-width="6" fill="none" stroke-linecap="round" stroke-dasharray="140 214"/>
                            <defs><linearGradient id="spinGrad"><stop stop-color="#2271b1"/><stop offset="1" stop-color="#00a32a"/></linearGradient></defs>
                        </svg>
                    </div>
                    <div id="aitrfoel-wiz-install-done" style="display:none; margin-bottom: 28px;">
                        <svg width="72" height="72" viewBox="0 0 72 72" fill="none">
                            <circle cx="36" cy="36" r="36" fill="#00a32a"/>
                            <path d="M22 36l10 10 18-20" stroke="white" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <h2 id="aitrfoel-wiz-install-title"><?php esc_html_e('Setting Things Up', 'ai-translator-for-elementor-and-polylang'); ?></h2>
                    <p class="aitrfoel-wiz-install-status" id="aitrfoel-wiz-install-status">
                        <?php esc_html_e('Preparing your site for multilingual content...', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                    <div class="aitrfoel-wiz-checklist" id="aitrfoel-wiz-checklist">
                        <div class="aitrfoel-wiz-check-item" data-check="taxonomy">
                            <span class="check-icon"><svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2 6l3 3 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></span>
                            <span><?php esc_html_e('Registering language taxonomy', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </div>
                        <div class="aitrfoel-wiz-check-item" data-check="database">
                            <span class="check-icon"><svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2 6l3 3 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></span>
                            <span><?php esc_html_e('Creating translation database', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </div>
                        <div class="aitrfoel-wiz-check-item" data-check="urls">
                            <span class="check-icon"><svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2 6l3 3 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></span>
                            <span><?php esc_html_e('Configuring URL structure', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </div>
                        <div class="aitrfoel-wiz-check-item" data-check="ready">
                            <span class="check-icon"><svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2 6l3 3 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></span>
                            <span><?php esc_html_e('Finalizing setup', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ STEP 3: Add Languages ═══ -->
            <div class="aitrfoel-wiz-panel" data-panel="3">
                <div class="aitrfoel-wiz-languages">
                    <h2><?php esc_html_e('Choose Your Languages', 'ai-translator-for-elementor-and-polylang'); ?></h2>
                    <p class="aitrfoel-wiz-lang-subtitle"><?php esc_html_e('Select your default language and the languages you want to translate into.', 'ai-translator-for-elementor-and-polylang'); ?></p>

                    <?php if ($had_previous_plugin): ?>
                    <div class="aitrfoel-wiz-import-notice">
                        <span class="notice-icon">💡</span>
                        <div class="notice-text">
                            <?php if (!empty($detected_languages)): ?>
                                <strong><?php echo esc_html(sprintf(
                                    __('%d languages detected from %s:', 'ai-translator-for-elementor-and-polylang'),
                                    count($detected_languages),
                                    $previous_plugin_name
                                )); ?></strong>
                                <p style="margin-top: 4px; margin-bottom: 4px;">
                                    <?php
                                    $lang_names = array_map(function($l) { return $l['name']; }, $detected_languages);
                                    echo esc_html(implode(', ', $lang_names));
                                    ?>
                                </p>
                                <p><?php esc_html_e('We pre-selected them below. Review and adjust as needed.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                            <?php else: ?>
                                <strong><?php echo esc_html(sprintf(__('%s was previously installed', 'ai-translator-for-elementor-and-polylang'), $previous_plugin_name)); ?></strong>
                                <p><?php esc_html_e('Your existing translations will remain. The built-in system will manage languages going forward.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Default Language -->
                    <div class="aitrfoel-wiz-default-lang">
                        <label for="aitrfoel-wiz-default-lang">
                            <?php esc_html_e('Your site language (default):', 'ai-translator-for-elementor-and-polylang'); ?>
                        </label>
                        <select id="aitrfoel-wiz-default-lang">
                            <?php foreach ($available_languages as $lang): ?>
                                <option value="<?php echo esc_attr($lang['code']); ?>" <?php echo $lang['code'] === 'en' ? 'selected' : ''; ?>>
                                    <?php echo esc_html($lang['name']); ?> (<?php echo esc_html(strtoupper($lang['code'])); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Selected Chips -->
                    <div class="aitrfoel-wiz-selected-chips" id="aitrfoel-wiz-chips"></div>

                    <!-- Popular Languages Grid -->
                    <div class="aitrfoel-wiz-lang-section-title">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M8 1l2.245 4.55L15 6.257l-3.5 3.412.826 4.818L8 12.29l-4.326 2.197.826-4.818L1 6.257l4.755-.707L8 1z" fill="#f0b849"/></svg>
                        <?php esc_html_e('Popular Languages', 'ai-translator-for-elementor-and-polylang'); ?>
                    </div>
                    <div class="aitrfoel-wiz-lang-grid" id="aitrfoel-wiz-lang-grid">
                        <?php foreach ($popular_languages as $lang): ?>
                        <button type="button" class="aitrfoel-wiz-lang-btn" data-code="<?php echo esc_attr($lang['code']); ?>" data-name="<?php echo esc_attr($lang['name']); ?>">
                            <img class="flag-icon" src="https://flagcdn.com/w40/<?php echo esc_attr($lang['flag']); ?>.png" 
                                 srcset="https://flagcdn.com/w80/<?php echo esc_attr($lang['flag']); ?>.png 2x"
                                 alt="<?php echo esc_attr($lang['name']); ?>" width="22" height="16" loading="lazy">
                            <span class="lang-name"><?php echo esc_html($lang['name']); ?></span>
                            <span class="lang-check"><svg width="10" height="10" viewBox="0 0 10 10" fill="none"><path d="M1.5 5l2.5 2.5 4.5-5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span>
                        </button>
                        <?php endforeach; ?>
                    </div>

                    <!-- Search Other Languages -->
                    <div class="aitrfoel-wiz-lang-section-title">
                        <?php esc_html_e('Add Other Languages', 'ai-translator-for-elementor-and-polylang'); ?>
                    </div>
                    <div class="aitrfoel-wiz-lang-search-wrap">
                        <span class="search-icon"><svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="7" cy="7" r="5" stroke="currentColor" stroke-width="1.5"/><path d="M11 11l3.5 3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span>
                        <input type="text" id="aitrfoel-wiz-search" placeholder="<?php esc_attr_e('Search for a language...', 'ai-translator-for-elementor-and-polylang'); ?>">
                    </div>
                    <div class="aitrfoel-wiz-search-results" id="aitrfoel-wiz-search-results"></div>

                    <div style="text-align: right; margin-top: 24px;">
                        <button type="button" class="aitrfoel-wiz-btn aitrfoel-wiz-btn-primary" id="aitrfoel-wiz-save-langs" disabled>
                            <?php esc_html_e('Continue', 'ai-translator-for-elementor-and-polylang'); ?>
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M6 3l5 5-5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- ═══ STEP 4: Finish ═══ -->
            <div class="aitrfoel-wiz-panel" data-panel="4">
                <div class="aitrfoel-wiz-finish">
                    <div class="aitrfoel-wiz-success-icon">
                        <svg width="80" height="80" viewBox="0 0 80 80" fill="none">
                            <circle cx="40" cy="40" r="40" fill="url(#successGrad)"/>
                            <path d="M24 40l12 12 20-22" stroke="white" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>
                            <defs><linearGradient id="successGrad" x1="0" y1="0" x2="80" y2="80"><stop stop-color="#00a32a"/><stop offset="1" stop-color="#007a1e"/></linearGradient></defs>
                        </svg>
                    </div>
                    <h2><?php esc_html_e("You're All Set!", 'ai-translator-for-elementor-and-polylang'); ?></h2>
                    <p class="aitrfoel-wiz-finish-subtitle">
                        <?php esc_html_e('Your site is now ready for multilingual content. Start translating your pages from the dashboard.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>

                    <!-- Language Summary -->
                    <div class="aitrfoel-wiz-summary-box">
                        <h4><?php esc_html_e('Languages configured:', 'ai-translator-for-elementor-and-polylang'); ?></h4>
                        <div class="aitrfoel-wiz-summary-langs" id="aitrfoel-wiz-summary-langs">
                            <!-- Filled by JS -->
                        </div>
                    </div>

                    <!-- PostHog Analytics Opt-in -->
                    <div class="aitrfoel-wiz-optin">
                        <div class="aitrfoel-wiz-optin-toggle">
                            <input type="checkbox" id="aitrfoel-wiz-analytics">
                            <label class="toggle-slider" for="aitrfoel-wiz-analytics"></label>
                        </div>
                        <div class="aitrfoel-wiz-optin-text">
                            <h4><?php esc_html_e('Help improve Meep AI Translator', 'ai-translator-for-elementor-and-polylang'); ?></h4>
                            <p><?php esc_html_e('Share anonymous usage data to help us improve the plugin. No personal data is collected — only feature usage and error reports.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                        </div>
                    </div>

                    <!-- Finish Actions -->
                    <div class="aitrfoel-wiz-finish-actions">
                        <button type="button" class="aitrfoel-wiz-btn aitrfoel-wiz-btn-success" id="aitrfoel-wiz-finish-btn">
                            <?php esc_html_e('Go to Dashboard', 'ai-translator-for-elementor-and-polylang'); ?>
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M6 3l5 5-5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                        <a href="https://meeptech.com/products/ai-translator-for-elementor-polylang/documentation" target="_blank" class="aitrfoel-wiz-docs-link">
                            <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 1h7l3 3v8a1 1 0 01-1 1H2a1 1 0 01-1-1V2a1 1 0 011-1z" stroke="currentColor" stroke-width="1.2"/><path d="M4 7h5M4 9.5h3" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
                            <?php esc_html_e('View Documentation', 'ai-translator-for-elementor-and-polylang'); ?>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="aitrfoel-wiz-footer">
                <span class="aitrfoel-wiz-footer-note">
                    <?php esc_html_e('You can change these settings at any time from', 'ai-translator-for-elementor-and-polylang'); ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ai-translator-for-elementor-and-polylang-settings')); ?>">
                        <?php esc_html_e('Settings', 'ai-translator-for-elementor-and-polylang'); ?>
                    </a>
                </span>
                <div class="aitrfoel-wiz-footer-branding">
                    <a href="https://meeptech.com" target="_blank" rel="noopener noreferrer">
                        <img src="<?php echo esc_url(AITRFOEL_PLUGIN_URL . 'assets/img/meeptech.svg'); ?>" alt="Meep Tech" style="height: 18px; width: auto; opacity: 0.6; transition: opacity 0.3s;">
                    </a>
                </div>
            </div>
        </div><!-- .aitrfoel-wiz-card -->
        </div><!-- .aitrfoel-wizard-wrap -->
        </div><!-- .wrap -->

        <script>
        (function() {
            'use strict';

            /* ── State ────────────────────────────────────── */
            var currentStep = 1;
            var selectedLangs = {};
            var allLanguages = <?php echo wp_json_encode($available_languages); ?>;
            var popularCodes = <?php echo wp_json_encode(array_column($popular_languages, 'code')); ?>;
            var flagMap = <?php 
                // Build comprehensive flag map for ALL available languages
                $full_flag_map = [];
                if (class_exists('AITRFOEL_Language_Mapper')) {
                    foreach ($available_languages as $lang) {
                        $full_flag_map[$lang['code']] = AITRFOEL_Language_Mapper::get_country_code($lang['code']);
                    }
                    // Also include popular language flags
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
            var ajaxNonce = <?php echo wp_json_encode(wp_create_nonce('aitrfoel_builtin_setup')); ?>;
            var detectedLangs = <?php echo wp_json_encode($detected_languages); ?>;
            var detectedDefaultLang = <?php echo wp_json_encode($detected_default_lang); ?>;

            /* ── Step Navigation ──────────────────────────── */
            function goToStep(step) {
                // Update dots
                document.querySelectorAll('.aitrfoel-wiz-dot').forEach(function(d) {
                    var s = parseInt(d.dataset.step);
                    d.className = 'aitrfoel-wiz-dot';
                    if (s < step) d.classList.add('completed');
                    if (s === step) d.classList.add('active');
                    if (s < step) d.textContent = '✓';
                });
                // Update lines
                document.querySelectorAll('.aitrfoel-wiz-line').forEach(function(l) {
                    var after = parseInt(l.dataset.after);
                    l.className = 'aitrfoel-wiz-line';
                    if (after < step) l.classList.add('completed');
                });
                // Show panel
                document.querySelectorAll('.aitrfoel-wiz-panel').forEach(function(p) {
                    p.classList.remove('active');
                });
                var panel = document.querySelector('.aitrfoel-wiz-panel[data-panel="' + step + '"]');
                if (panel) panel.classList.add('active');
                currentStep = step;
            }

            /* ── Step 1: System choice card selection ──── */
            var systemCards = document.querySelectorAll('.aitrfoel-wiz-system-card');
            systemCards.forEach(function(card) {
                card.addEventListener('click', function() {
                    systemCards.forEach(function(c) { c.classList.remove('selected'); });
                    card.classList.add('selected');
                    card.querySelector('input[type=radio]').checked = true;
                });
            });

            var polylangInstalled = <?php echo wp_json_encode($polylang_installed); ?>;
            var wpmlInstalled = <?php echo wp_json_encode($wpml_installed); ?>;

            /* ── Step 1 → Continue based on system choice ── */
            document.getElementById('aitrfoel-wiz-start').addEventListener('click', function() {
                var choice = document.querySelector('input[name="aitrfoel_system_choice"]:checked').value;
                
                if (choice === 'builtin') {
                    goToStep(2);
                    runInstallSequence();
                } else if (choice === 'polylang') {
                    if (!polylangInstalled) {
                        window.location.href = <?php echo wp_json_encode(admin_url('plugin-install.php?s=polylang&tab=search&type=term')); ?>;
                    } else {
                        // Polylang is installed (maybe inactive) — redirect to plugins page to activate
                        window.location.href = <?php echo wp_json_encode(admin_url('plugins.php?s=polylang')); ?>;
                    }
                } else if (choice === 'wpml') {
                    if (!wpmlInstalled) {
                        // WPML is premium and not on wp.org — go to wpml.org
                        window.open('https://wpml.org/', '_blank');
                        alert(<?php echo wp_json_encode(__('WPML is a premium plugin. Please purchase and install it from wpml.org, then reload this page.', 'ai-translator-for-elementor-and-polylang')); ?>);
                    } else {
                        // WPML is installed (maybe inactive) — redirect to plugins page to activate
                        window.location.href = <?php echo wp_json_encode(admin_url('plugins.php?s=wpml')); ?>;
                    }
                }
            });

            function runInstallSequence() {
                var checks = ['taxonomy', 'database', 'urls', 'ready'];
                var i = 0;
                function nextCheck() {
                    if (i >= checks.length) {
                        // All done → show success & go step 3
                        document.getElementById('aitrfoel-wiz-spinner').style.display = 'none';
                        document.getElementById('aitrfoel-wiz-install-done').style.display = 'block';
                        document.getElementById('aitrfoel-wiz-install-title').textContent = <?php echo wp_json_encode(__('All Set!', 'ai-translator-for-elementor-and-polylang')); ?>;
                        document.getElementById('aitrfoel-wiz-install-status').textContent = <?php echo wp_json_encode(__('Moving to language selection...', 'ai-translator-for-elementor-and-polylang')); ?>;
                        setTimeout(function() { goToStep(3); }, 800);
                        return;
                    }
                    var item = document.querySelector('.aitrfoel-wiz-check-item[data-check="' + checks[i] + '"]');
                    if (item) {
                        item.classList.add('in-progress');
                    }
                    setTimeout(function() {
                        if (item) {
                            item.classList.remove('in-progress');
                            item.classList.add('done');
                        }
                        i++;
                        nextCheck();
                    }, 600 + Math.random() * 400);
                }
                nextCheck();
            }

            /* ── Step 3: Language Selection ────────────────── */
            var langGrid = document.getElementById('aitrfoel-wiz-lang-grid');
            var searchInput = document.getElementById('aitrfoel-wiz-search');
            var searchResults = document.getElementById('aitrfoel-wiz-search-results');
            var chipsContainer = document.getElementById('aitrfoel-wiz-chips');
            var saveLangsBtn = document.getElementById('aitrfoel-wiz-save-langs');

            // Popular lang buttons
            langGrid.addEventListener('click', function(e) {
                var btn = e.target.closest('.aitrfoel-wiz-lang-btn');
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
                updateSaveBtn();
            });

            // Search
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

            searchResults.addEventListener('click', function(e) {
                var item = e.target.closest('.aitrfoel-wiz-search-result-item');
                if (!item) return;
                selectedLangs[item.dataset.code] = item.dataset.name;
                searchInput.value = '';
                searchResults.classList.remove('visible');
                // Check if it's a popular lang button
                var popBtn = langGrid.querySelector('.aitrfoel-wiz-lang-btn[data-code="' + item.dataset.code + '"]');
                if (popBtn) popBtn.classList.add('selected');
                renderChips();
                updateSaveBtn();
            });

            document.addEventListener('click', function(e) {
                if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                    searchResults.classList.remove('visible');
                }
            });

            function renderChips() {
                chipsContainer.innerHTML = '';
                Object.keys(selectedLangs).forEach(function(code) {
                    var fc = flagMap[code] || code.substring(0, 2);
                    var chip = document.createElement('span');
                    chip.className = 'aitrfoel-wiz-chip';
                    chip.innerHTML = '<img class="chip-flag" src="https://flagcdn.com/w40/' + fc + '.png" alt="">' +
                        selectedLangs[code] +
                        '<span class="chip-remove" data-code="' + code + '">&times;</span>';
                    chipsContainer.appendChild(chip);
                });
                // Remove handler
                chipsContainer.querySelectorAll('.chip-remove').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var c = this.dataset.code;
                        delete selectedLangs[c];
                        var popBtn = langGrid.querySelector('.aitrfoel-wiz-lang-btn[data-code="' + c + '"]');
                        if (popBtn) popBtn.classList.remove('selected');
                        renderChips();
                        updateSaveBtn();
                    });
                });
            }

            function updateSaveBtn() {
                saveLangsBtn.disabled = Object.keys(selectedLangs).length === 0;
            }

            /* ── Auto-import languages from Polylang/WPML ── */
            if (detectedLangs && detectedLangs.length > 0) {
                // Set default language dropdown
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
                // Pre-select detected target languages
                detectedLangs.forEach(function(lang) {
                    if (lang.code === detectedDefaultLang) return; // skip default
                    selectedLangs[lang.code] = lang.name;
                    // Also select the popular grid button if present
                    var popBtn = langGrid.querySelector('.aitrfoel-wiz-lang-btn[data-code="' + lang.code + '"]');
                    if (popBtn) popBtn.classList.add('selected');
                });
                renderChips();
                updateSaveBtn();
            }

            /* ── Step 3 → AJAX save → Step 4 ──────────────── */
            saveLangsBtn.addEventListener('click', function() {
                var btn = this;
                btn.disabled = true;
                btn.textContent = <?php echo wp_json_encode(__('Saving...', 'ai-translator-for-elementor-and-polylang')); ?>;

                var defaultLangSel = document.getElementById('aitrfoel-wiz-default-lang');
                var defaultLang = defaultLangSel.value;
                var defaultLangName = defaultLangSel.selectedOptions[0].text.replace(/\s*\(.*\)$/, '');

                var targetLangs = Object.keys(selectedLangs).map(function(code) {
                    return { code: code, name: selectedLangs[code] };
                });

                var data = new FormData();
                data.append('action', 'aitrfoel_setup_builtin_languages');
                data.append('nonce', ajaxNonce);
                data.append('default_language', defaultLang);
                data.append('default_language_name', defaultLangName);
                data.append('target_languages', JSON.stringify(targetLangs));

                fetch(ajaxurl, { method: 'POST', body: data })
                    .then(function(r) {
                        if (!r.ok) {
                            throw new Error('HTTP ' + r.status + ': ' + r.statusText);
                        }
                        return r.text();
                    })
                    .then(function(text) {
                        var res;
                        try {
                            res = JSON.parse(text);
                        } catch(e) {
                            // Response was not JSON (likely a PHP fatal error)
                            console.error('AJAX response was not JSON:', text.substring(0, 500));
                            throw new Error('Server returned invalid response. Check browser console for details.');
                        }
                        return res;
                    })
                    .then(function(res) {
                        if (res.success) {
                            // Build summary
                            var summaryEl = document.getElementById('aitrfoel-wiz-summary-langs');
                            summaryEl.innerHTML = '<span class="aitrfoel-wiz-summary-lang"><strong>' + defaultLangName + '</strong> (' + <?php echo wp_json_encode(__('default', 'ai-translator-for-elementor-and-polylang')); ?> + ')</span>';
                            targetLangs.forEach(function(l) {
                                var fc = flagMap[l.code] || l.code.substring(0, 2);
                                summaryEl.innerHTML += '<span class="aitrfoel-wiz-summary-lang"><img src="https://flagcdn.com/w40/' + fc + '.png" width="18" height="13" alt="" style="border-radius:2px; vertical-align:middle;"> ' + l.name + '</span>';
                            });
                            goToStep(4);
                        } else {
                            btn.disabled = false;
                            btn.textContent = <?php echo wp_json_encode(__('Continue', 'ai-translator-for-elementor-and-polylang')); ?>;
                            alert(res.data || <?php echo wp_json_encode(__('Setup failed. Please try again.', 'ai-translator-for-elementor-and-polylang')); ?>);
                        }
                    })
                    .catch(function(err) {
                        btn.disabled = false;
                        btn.textContent = <?php echo wp_json_encode(__('Continue', 'ai-translator-for-elementor-and-polylang')); ?>;
                        console.error('Setup error:', err);
                        alert(err.message || <?php echo wp_json_encode(__('Network error. Please try again.', 'ai-translator-for-elementor-and-polylang')); ?>);
                    });
            });

            /* ── Step 4: Finish ───────────────────────────── */
            document.getElementById('aitrfoel-wiz-finish-btn').addEventListener('click', function() {
                // Save analytics preference
                var analyticsOptIn = document.getElementById('aitrfoel-wiz-analytics').checked;
                var data = new FormData();
                data.append('action', 'aitrfoel_save_analytics_optin');
                data.append('nonce', ajaxNonce);
                data.append('optin', analyticsOptIn ? '1' : '0');
                fetch(ajaxurl, { method: 'POST', body: data }).catch(function() {});
                // Reload
                window.location.reload();
            });

        })();
        </script>
        <?php
    }

    /**
     * AJAX handler: Set up built-in language system from the onboarding wizard
     */
    public function handle_setup_builtin_languages() {
        // Verify nonce and capabilities
        if (!check_ajax_referer('aitrfoel_builtin_setup', 'nonce', false)) {
            wp_send_json_error(__('Security check failed.', 'ai-translator-for-elementor-and-polylang'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'ai-translator-for-elementor-and-polylang'));
        }
        
        $default_lang_code = sanitize_text_field($_POST['default_language'] ?? 'en');
        $default_lang_name = sanitize_text_field($_POST['default_language_name'] ?? 'English');
        // Don't use sanitize_text_field on JSON — it can strip valid characters
        $target_langs_json = wp_unslash($_POST['target_languages'] ?? '[]');
        $target_langs = json_decode($target_langs_json, true);
        
        if (empty($target_langs) || !is_array($target_langs)) {
            wp_send_json_error(__('Please select at least one target language.', 'ai-translator-for-elementor-and-polylang'));
        }
        
        // Load the built-in integration class if not already loaded
        if (!class_exists('AITRFOEL_Builtin_Integration')) {
            require_once AITRFOEL_PLUGIN_DIR . 'includes/class-multilingual-interface.php';
            require_once AITRFOEL_PLUGIN_DIR . 'includes/class-builtin-integration.php';
        }
        
        // Enable the built-in system
        $builtin = AITRFOEL_Builtin_Integration::get_instance();
        $builtin->enable();
        
        // Create DB table
        $builtin->create_table();
        
        // Reset rewrite rules version so they get flushed on the next normal page load
        // (during AJAX, the init hook has already fired so add_rewrite_rules() won't run)
        delete_option('aitrfoel_rewrite_rules_version');
        
        // Add default language
        $builtin->add_language($default_lang_code, $default_lang_name, $default_lang_name, true);
        
        // Add target languages
        $added = 0;
        foreach ($target_langs as $lang) {
            if (!empty($lang['code']) && !empty($lang['name'])) {
                $lang_code = sanitize_text_field($lang['code']);
                $lang_name = sanitize_text_field($lang['name']);
                if ($builtin->add_language($lang_code, $lang_name, $lang_name, false)) {
                    $added++;
                }
            }
        }
        
        // Import Polylang translation relationships if Polylang data exists
        $this->import_polylang_translations($builtin, $default_lang_code);
        
        // Clear cached dependency status so the dashboard re-evaluates
        delete_option('aitrfoel_dependencies_status');
        
        // Flush rewrite rules for language URL prefixes
        flush_rewrite_rules();
        
        wp_send_json_success([
            'message' => sprintf(
                __('Languages configured! %d languages set up. Redirecting to dashboard...', 'ai-translator-for-elementor-and-polylang'),
                $added + 1
            ),
            'languages_count' => $added + 1,
        ]);
    }
    
    /**
     * Import Polylang translation relationships into the built-in system.
     * 
     * Polylang stores translation groups in the 'post_translations' taxonomy.
     * Each term's description is a serialized array of lang_code => post_id.
     * This method reads those groups and creates equivalent rows in the
     * built-in aitrfoel_translation_groups table.
     *
     * @param AITRFOEL_Builtin_Integration $builtin
     * @param string $default_lang_code
     */
    private function import_polylang_translations($builtin, $default_lang_code) {
        global $wpdb;
        
        // Polylang stores translation links in the 'post_translations' taxonomy.
        // Each term description is a serialized array: ['en' => 123, 'de' => 456, ...]
        // The term is linked to all posts in the group via term_relationships.
        
        // Query the DB directly because Polylang may be deactivated (taxonomy unregistered)
        $pll_groups = $wpdb->get_results(
            "SELECT tt.term_taxonomy_id, t.slug, tt.description 
             FROM {$wpdb->term_taxonomy} tt
             INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
             WHERE tt.taxonomy = 'post_translations'"
        );
        
        if (empty($pll_groups) || !is_array($pll_groups)) {
            return; // No Polylang data to import
        }
        
        $table = $wpdb->prefix . 'aitrfoel_translation_groups';
        $imported = 0;
        
        // Get builtin configured languages for validation
        $builtin_langs = [];
        foreach ($builtin->get_available_languages() as $lang) {
            $builtin_langs[] = is_object($lang) ? $lang->code : $lang['code'];
        }
        
        foreach ($pll_groups as $group) {
            $translations = maybe_unserialize($group->description);
            if (!is_array($translations) || count($translations) < 2) {
                continue; // Need at least 2 posts to form a group
            }
            
            // Filter to only include languages that exist in our built-in system
            $valid_translations = [];
            foreach ($translations as $lang_code => $post_id) {
                $post_id = intval($post_id);
                if ($post_id > 0 && in_array($lang_code, $builtin_langs, true)) {
                    // Verify the post actually exists
                    if (get_post_status($post_id) !== false) {
                        $valid_translations[$lang_code] = $post_id;
                    }
                }
            }
            
            if (count($valid_translations) < 2) {
                continue;
            }
            
            // Check if any post in this group already has a built-in group
            $already_imported = false;
            foreach ($valid_translations as $lang_code => $post_id) {
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT group_id FROM {$table} WHERE post_id = %d LIMIT 1",
                    $post_id
                ));
                if ($existing) {
                    $already_imported = true;
                    break;
                }
            }
            if ($already_imported) {
                continue;
            }
            
            // Get next group_id
            $max_group = $wpdb->get_var("SELECT MAX(group_id) FROM {$table}");
            $new_group_id = ($max_group ? intval($max_group) : 0) + 1;
            
            // Insert all posts in this translation group
            foreach ($valid_translations as $lang_code => $post_id) {
                $wpdb->replace($table, [
                    'group_id'      => $new_group_id,
                    'post_id'       => $post_id,
                    'language_code' => $lang_code,
                ], ['%d', '%d', '%s']);
                
                // Also set the built-in language taxonomy on the post
                $builtin->set_post_language($post_id, $lang_code);
            }
            
            $imported++;
        }
        
        if ($imported > 0 && class_exists('AITRFOEL_Logger')) {
            AITRFOEL_Logger::log("Imported {$imported} Polylang translation groups into built-in system.", 'info');
        }
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
        ?>
        
    <div class="wrap aitrfoel-dashboard-wrapper eat-tree-v2">
            <?php $this->render_compact_stats_bar(); ?>
            <?php $this->render_plugin_header(__('Dashboard', 'ai-translator-for-elementor-and-polylang')); ?>
            
            <?php $this->render_setup_checklist(); ?>
            
            <p class="description"><?php esc_html_e('Manage translations for your pages and posts. Select source content to create bulk translations.', 'ai-translator-for-elementor-and-polylang'); ?></p>
            
            <!-- Bulk Translation Bar (always visible) -->
            <div id="eat-bulk-bar" class="eat-bulk-translation-bar">
                <div class="eat-bulk-content">
                    <div class="eat-bulk-selection" style="display: none;">
                        <?php /* translators: %d is the number of pages selected for translation */ ?>
                        <strong id="eat-selected-count"><?php echo esc_html(sprintf(__('%d pages selected', 'ai-translator-for-elementor-and-polylang'), 0)); ?></strong>
                    </div>
                    
                    <div class="eat-bulk-languages">
                        <label><?php esc_html_e('From:', 'ai-translator-for-elementor-and-polylang'); ?></label>
                        <div class="eat-source-languages">
                            <button type="button" class="button" id="eat-select-source-language">
                                <?php esc_html_e('🌐 Auto-detect', 'ai-translator-for-elementor-and-polylang'); ?>
                            </button>
                            <div id="eat-source-dropdown" class="eat-dropdown-content" style="display: none;">
                                <div class="eat-dropdown-item">
                                    <label><input type="radio" name="source-language" value="auto" checked> 🌐 <?php esc_html_e('Auto-detect', 'ai-translator-for-elementor-and-polylang'); ?></label>
                                </div>
                                <div class="eat-dropdown-divider"></div>
                                <?php
                                // Add multilingual languages to source selection
                                $multilingual = class_exists('AITRFOEL_Multilingual_Manager') ? AITRFOEL_Multilingual_Manager::get_instance() : null;
                                if ($multilingual && $multilingual->is_active()) {
                                    $languages = $multilingual->get_available_languages();
                                    
                                    if (!empty($languages)) {
                                        foreach ($languages as $code => $lang) {
                                            $name = is_object($lang) ? ($lang->name ?? strtoupper($code)) : (is_array($lang) ? ($lang['name'] ?? strtoupper($code)) : strtoupper($code));
                                            printf(
                                                '<div class="eat-dropdown-item"><label><input type="radio" name="source-language" value="%s"> %s (%s)</label></div>',
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
                        <div class="eat-target-languages">
                            <button type="button" class="button" id="eat-select-languages">
                                <?php esc_html_e('Select languages', 'ai-translator-for-elementor-and-polylang'); ?>
                            </button>
                            <div id="eat-languages-dropdown" class="eat-dropdown-content" style="display: none;">
                                <!-- Will be populated by JS -->
                            </div>
                        </div>
                    </div>
                    
                    <div class="eat-bulk-stats">
                        <span id="eat-estimated-words" style="color: #666; font-size: 12px;"><?php esc_html_e('Select pages for translation', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        <span id="eat-elapsed-timer" style="color: #666; font-size: 12px; margin-left: 10px; display: none;">⏱️ <span id="eat-timer-value">0:00</span></span>
                    </div>
                    
                    <div class="eat-bulk-actions">
                        <button type="button" class="button" id="eat-bulk-settings" title="Configure translation settings">
                            <span class="eat-btn-label">⚙️ <?php esc_html_e('Settings', 'ai-translator-for-elementor-and-polylang'); ?></span>
                            <span class="eat-btn-sub"><?php esc_html_e('exclude words', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=ai-translator-for-elementor-and-polylang-translate-all')); ?>" class="button" id="eat-translate-all-site" title="<?php esc_attr_e('Translate your entire site at once', 'ai-translator-for-elementor-and-polylang'); ?>">
                            <span class="eat-btn-label">🌐 <?php esc_html_e('Translate All Site', 'ai-translator-for-elementor-and-polylang'); ?></span>
                            <span class="eat-btn-sub"><?php esc_html_e('experimental', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </a>
                        <button type="button" class="button button-primary" id="eat-start-translation" disabled title="Select pages and languages to start">
                            🚀 <?php esc_html_e('Start Translation', 'ai-translator-for-elementor-and-polylang'); ?>
                        </button>
                    </div>
                </div>
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
            <div class="eat-content-tabs">
                <nav class="eat-tab-nav" role="tablist">
                    <button type="button" class="eat-tab-btn active" data-tab="pages" role="tab" aria-selected="true" aria-controls="eat-tab-pages">
                        <span class="dashicons dashicons-admin-page"></span>
                        <?php esc_html_e('Pages', 'ai-translator-for-elementor-and-polylang'); ?>
                        <span class="eat-tab-count"><?php echo absint($pages_count); ?></span>
                    </button>
                    <button type="button" class="eat-tab-btn" data-tab="posts" role="tab" aria-selected="false" aria-controls="eat-tab-posts">
                        <span class="dashicons dashicons-admin-post"></span>
                        <?php esc_html_e('Posts', 'ai-translator-for-elementor-and-polylang'); ?>
                        <span class="eat-tab-count"><?php echo absint($posts_count); ?></span>
                    </button>
                    <?php if ($has_woocommerce || $products_count > 0) : ?>
                    <button type="button" class="eat-tab-btn" data-tab="products" role="tab" aria-selected="false" aria-controls="eat-tab-products">
                        <span class="dashicons dashicons-cart"></span>
                        <?php esc_html_e('Products', 'ai-translator-for-elementor-and-polylang'); ?>
                        <span class="eat-tab-count"><?php echo absint($products_count); ?></span>
                    </button>
                    <?php endif; ?>
                </nav>

                <!-- Pages Tab -->
                <div id="eat-tab-pages" class="eat-tab-panel active" role="tabpanel" data-tab="pages">
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
                                        <span class="dashicons dashicons-list-view" title="<?php esc_html_e('Expand/Collapse', 'ai-translator-for-elementor-and-polylang'); ?>"></span>
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
                <div id="eat-tab-posts" class="eat-tab-panel" role="tabpanel" data-tab="posts" style="display: none;">
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
                                        <span class="dashicons dashicons-list-view" title="<?php esc_html_e('Expand/Collapse', 'ai-translator-for-elementor-and-polylang'); ?>"></span>
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
                <div id="eat-tab-products" class="eat-tab-panel" role="tabpanel" data-tab="products" style="display: none;">
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
                                        <span class="dashicons dashicons-list-view" title="<?php esc_html_e('Expand/Collapse', 'ai-translator-for-elementor-and-polylang'); ?>"></span>
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
                <a href="https://meeptech.com" target="_blank" rel="noopener noreferrer">
                    <img src="<?php echo esc_url(AITRFOEL_PLUGIN_URL . 'assets/img/meeptech.svg'); ?>" alt="Meep Tech" class="aitrfoel-footer-logo">
                </a>
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
            $source_page = null;
            
            // First priority: default language version
            if (isset($translations[$default_lang])) {
                $source_page = get_post($translations[$default_lang]);
            }
            // Second priority: current page if it's in default language
            else if ($page_lang === $default_lang) {
                $source_page = $page;
            }
            // Third priority: find the "original" page (usually the first created one)
            else {
                // Get the oldest page in the translation group
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
                        'label' => '🎨 ' . __('Elementor', 'ai-translator-for-elementor-and-polylang'),
                        'class' => 'eat-page-elementor',
                        'tooltip' => __('Built with Elementor - full translation support', 'ai-translator-for-elementor-and-polylang')
                    ];
                    
                case AITRFOEL_Content_Detector::TYPE_GUTENBERG:
                    return [
                        'type' => 'gutenberg',
                        'label' => '📦 ' . __('Gutenberg', 'ai-translator-for-elementor-and-polylang'),
                        'class' => 'eat-page-gutenberg',
                        'tooltip' => __('Built with Block Editor - can be translated', 'ai-translator-for-elementor-and-polylang')
                    ];
                    
                case AITRFOEL_Content_Detector::TYPE_CLASSIC:
                    return [
                        'type' => 'classic',
                        'label' => '📝 ' . __('Classic', 'ai-translator-for-elementor-and-polylang'),
                        'class' => 'eat-page-classic',
                        'tooltip' => __('Classic Editor content - can be translated', 'ai-translator-for-elementor-and-polylang')
                    ];
                    
                case AITRFOEL_Content_Detector::TYPE_WOOCOMMERCE:
                    return [
                        'type' => 'woocommerce',
                        'label' => '🛒 ' . __('WooCommerce', 'ai-translator-for-elementor-and-polylang'),
                        'class' => 'eat-page-woocommerce',
                        'tooltip' => __('WooCommerce product - title, description, attributes and meta are translated', 'ai-translator-for-elementor-and-polylang')
                    ];
                    
                default:
                    return [
                        'type' => 'unknown',
                        'label' => '❓ ' . __('Unknown', 'ai-translator-for-elementor-and-polylang'),
                        'class' => 'eat-page-unknown',
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
                'class' => 'eat-page-elementor',
                'tooltip' => __('Built with Elementor - can be translated', 'ai-translator-for-elementor-and-polylang')
            ];
        } else {
            return [
                'type' => 'standard',
                'label' => __('Standard', 'ai-translator-for-elementor-and-polylang'),
                'class' => 'eat-page-standard',
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
            $post_type_icon = '🏠';
            $post_type_label = __('Page', 'ai-translator-for-elementor-and-polylang');
            if ($source->post_type === 'post') {
                $post_type_icon = '📄';
                $post_type_label = __('Post', 'ai-translator-for-elementor-and-polylang');
            } elseif ($source->post_type === 'product') {
                $post_type_icon = '🛒';
                $post_type_label = __('Product', 'ai-translator-for-elementor-and-polylang');
            }
            ?>
            <tr class="eat-source-page" data-page-id="<?php echo absint($source->ID); ?>">
                <th scope="row" class="check-column">
                    <input type="checkbox" 
                           name="bulk_pages[]" 
                           value="<?php echo absint($source->ID); ?>"
                           class="eat-page-checkbox"
                           data-missing-languages="<?php echo esc_attr(json_encode($missing_languages)); ?>"
                           data-existing-languages="<?php echo esc_attr(json_encode($existing_languages)); ?>"
                           <?php echo $checkbox_disabled ? 'disabled' : ''; ?>>
                </th>
                <td class="column-title">
                    <strong>
                        <a class="eat-page-title-link" href="<?php echo esc_url($source_permalink); ?>" target="_blank" rel="noopener">
                            <?php echo esc_html($post_type_icon); ?> <?php echo esc_html($source->post_title); ?>
                            <span class="eat-page-lang">(<?php echo esc_html(strtoupper($multilingual->get_post_language($source->ID) ?: '?')); ?>)</span>
                        </a>
                    </strong>
                    <div class="eat-page-status">
                        <?php echo esc_html($this->get_status_display_name($source->post_status)); ?>
                    </div>
                </td>
                <td class="column-languages">
                    <?php 
                    $available_langs = $available_lang_codes;
                    $source_lang = $multilingual->get_post_language($source->ID);
                    
                    if (!empty($available_langs)) {
                        foreach ($available_langs as $lang_code) {
                            // Check if translation exists OR if this is the source language
                            $has_translation = isset($translations[$lang_code]) || ($lang_code === $source_lang);
                            $class = $has_translation ? 'eat-lang-exists' : 'eat-lang-missing';
                            echo '<span class="eat-lang-flag ' . esc_attr($class) . '">' . esc_html(strtoupper($lang_code)) . '</span> ';
                        }
                    }
                    ?>
                </td>
                <td class="column-status">
                    <span class="eat-translation-status">
                        <?php echo absint($translation_count) . '/' . absint($total_languages - 1) . ' ' . esc_html__('translated', 'ai-translator-for-elementor-and-polylang'); ?>
                    </span>
                </td>
                <td class="column-page-type">
                    <?php 
                    $page_type = $this->get_page_type($source->ID);
                    ?>
                    <span class="eat-page-type <?php echo esc_attr($page_type['class']); ?>" title="<?php echo esc_attr($page_type['tooltip']); ?>">
                        <?php echo esc_html($page_type['label']); ?>
                    </span>
                </td>
                <td class="column-actions">
                    <a href="<?php echo esc_url(get_edit_post_link($source->ID)); ?>" class="button button-small">
                        <?php esc_html_e('Edit', 'ai-translator-for-elementor-and-polylang'); ?>
                    </a>
                    <a href="<?php echo esc_url($source_permalink); ?>" class="button button-small" target="_blank">
                        <?php esc_html_e('View', 'ai-translator-for-elementor-and-polylang'); ?>
                    </a>
                    <button type="button" 
                            class="button button-small eat-delete-page" 
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
                                class="eat-expand-button" 
                                data-source-id="<?php echo absint($source->ID); ?>"
                                data-expanded="false"
                                title="<?php esc_html_e('Show/hide translations', 'ai-translator-for-elementor-and-polylang'); ?>">
                            <span class="dashicons dashicons-arrow-down-alt2"></span>
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
                <tr class="eat-translation-page eat-translation-collapsed" 
                    data-parent-id="<?php echo absint($source->ID); ?>" 
                    data-page-id="<?php echo absint($translation->ID); ?>"
                    data-language="<?php echo esc_attr($lang_code); ?>"
                    style="display: none;">
                    <th scope="row" class="check-column">
                        <!-- No checkbox for translations -->
                    </th>
                    <td class="column-title">
                        <strong class="eat-translation-indent">
                            <a class="eat-page-title-link" href="<?php echo esc_url($translation_permalink); ?>" target="_blank" rel="noopener">
                                <?php echo esc_html($translation->post_title); ?> 
                                <span class="eat-page-lang">(<?php echo esc_html(strtoupper($lang_code)); ?>)</span>
                            </a>
                        </strong>
                        <div class="eat-page-status">
                            <?php echo esc_html($this->get_status_display_name($translation->post_status)); ?>
                        </div>
                    </td>
                    <td class="column-languages">
                        <span class="eat-translation-note"><?php esc_html_e('Translation of', 'ai-translator-for-elementor-and-polylang'); ?>: <?php echo esc_html($source->post_title); ?></span>
                    </td>
                    <td class="column-status">
                        <span class="eat-status-complete">✓ <?php esc_html_e('Complete', 'ai-translator-for-elementor-and-polylang'); ?></span>
                    </td>
                    <td class="column-page-type">
                        <?php 
                        $page_type = $this->get_page_type($translation->ID);
                        ?>
                        <span class="eat-page-type <?php echo esc_attr($page_type['class']); ?>" title="<?php echo esc_attr($page_type['tooltip']); ?>">
                            <?php echo esc_html($page_type['label']); ?>
                        </span>
                    </td>
                    <td class="column-actions">
                        <a href="<?php echo esc_url(get_edit_post_link($translation->ID)); ?>" class="button button-small">
                            <?php esc_html_e('Edit', 'ai-translator-for-elementor-and-polylang'); ?>
                        </a>
                        <a href="<?php echo esc_url($translation_permalink); ?>" class="button button-small" target="_blank">
                            <?php esc_html_e('View', 'ai-translator-for-elementor-and-polylang'); ?>
                        </a>
                        <button type="button" 
                                class="button button-small eat-delete-page" 
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
            [],
            AITRFOEL_VERSION
        );
        
        wp_enqueue_style(
            'aitrfoel-modern-design',
            AITRFOEL_PLUGIN_URL . 'assets/css/modern-design.css',
            ['aitrfoel-dashboard'],
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
                'select_languages' => __('Select languages ▼', 'ai-translator-for-elementor-and-polylang'),
                /* translators: %d: Number of languages */
                'languages_selected' => __('%d languages ▼', 'ai-translator-for-elementor-and-polylang'),
                
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
                'waiting_for_ai' => __('Waiting for AI response', 'ai-translator-for-elementor-and-polylang'),
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
        ?>
        <div class="eat-compact-stats-bar">
            <div class="eat-compact-stat">
                <span class="eat-compact-label"><?php esc_html_e('Total Pages', 'ai-translator-for-elementor-and-polylang'); ?>:</span>
                <span class="eat-compact-value"><?php echo number_format($total_pages); ?></span>
            </div>
            <div class="eat-compact-stat">
                <span class="eat-compact-label"><?php esc_html_e('Words Translated', 'ai-translator-for-elementor-and-polylang'); ?>:</span>
                <span class="eat-compact-value"><?php echo number_format($words_translated); ?></span>
            </div>
            <div class="eat-compact-stat eat-words-left-stat">
                <span class="eat-compact-label eat-words-left-label"><?php esc_html_e('Words Left', 'ai-translator-for-elementor-and-polylang'); ?>:</span>
                <span class="eat-compact-value eat-words-left-value eat-tooltip <?php echo (!$is_unlimited && $words_left <= 0) ? 'eat-compact-limit-reached' : ''; ?>" 
                      title="<?php /* translators: 1: Words translated, 2: Word limit */ printf(esc_attr__('Used: %1$s of %2$s words', 'ai-translator-for-elementor-and-polylang'), 
                                     number_format($words_translated),
                                     $is_unlimited ? esc_attr__('Unlimited', 'ai-translator-for-elementor-and-polylang') : number_format($license_info['limit'])); ?>">
                    <?php echo $is_unlimited ? esc_html__('Unlimited', 'ai-translator-for-elementor-and-polylang') : number_format($words_left); ?><?php if (!$is_unlimited && $words_left <= 0) echo ' ⚠️'; ?>
                </span>
            </div>
            <div class="eat-compact-stat eat-compact-license">
                <div class="eat-compact-license-info">
                    <span class="eat-compact-label"><?php esc_html_e('License', 'ai-translator-for-elementor-and-polylang'); ?>:</span>
                    <?php if (!empty($license_info['license_expired'])): ?>
                        <span class="eat-compact-value eat-license-expired eat-tooltip" 
                              style="color: #dc3545;"
                              title="<?php esc_attr_e('Your license has expired. You have been downgraded to the Free plan.', 'ai-translator-for-elementor-and-polylang'); ?>">
                            ⚠️ <?php esc_html_e('Expired', 'ai-translator-for-elementor-and-polylang'); ?>
                        </span>
                    <?php else: ?>
                        <span class="eat-compact-value eat-tooltip" 
                              title="<?php /* translators: 1: Plan name, 2: Word limit */ printf(esc_attr__('%1$s Plan: %2$s words included', 'ai-translator-for-elementor-and-polylang'), 
                                             esc_html($license_info['name']), 
                                             $is_unlimited ? esc_attr__('Unlimited', 'ai-translator-for-elementor-and-polylang') : number_format($license_info['limit'])); ?>">
                            <?php echo esc_html($license_info['name']); ?>
                        </span>
                        <?php if (!empty($license_info['addon_active'])): ?>
                            <span class="eat-compact-addon-badge" title="<?php /* translators: %s: Number of extra words */ printf(esc_attr__('Extra %s words pack activated', 'ai-translator-for-elementor-and-polylang'), number_format($license_info['addon_words'])); ?>">
                                +<?php echo esc_html(number_format($license_info['addon_words'])); ?> <?php esc_html_e('bonus', 'ai-translator-for-elementor-and-polylang'); ?>
                            </span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="eat-compact-actions">
                    <?php if (!empty($license_info['license_expired'])): ?>
                        <a href="<?php echo (function_exists('eatp_fs') && eatp_fs()->is_registered()) ? esc_url(eatp_fs()->get_upgrade_url()) : esc_url('https://meeptech.com/products/ai-translator-for-elementor-polylang/#pricing'); ?>" class="eat-compact-buy-btn" style="background: #dc3545; border-color: #dc3545;">
                            <?php esc_html_e('Renew License', 'ai-translator-for-elementor-and-polylang'); ?>
                        </a>
                    <?php else: ?>
                        <a href="<?php echo (function_exists('eatp_fs') && eatp_fs()->is_registered()) ? esc_url(eatp_fs()->get_upgrade_url()) : esc_url('https://meeptech.com/products/ai-translator-for-elementor-polylang/#pricing'); ?>" class="eat-compact-buy-btn">
                            <?php esc_html_e('Buy More Words', 'ai-translator-for-elementor-and-polylang'); ?>
                        </a>
                        <a href="<?php echo (function_exists('eatp_fs') && eatp_fs()->is_registered()) ? esc_url(eatp_fs()->get_upgrade_url()) : esc_url('https://meeptech.com/products/ai-translator-for-elementor-polylang/#pricing'); ?>" class="eat-compact-upgrade-btn">
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
        ?>
        <div class="eat-plugin-header">
            <h1 class="eat-plugin-title">
                <?php echo esc_html($plugin_name); ?>
                <span class="eat-breadcrumb-separator"> / </span>
                <span class="eat-current-page"><?php echo esc_html($current_page); ?></span>
            </h1>
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
        <div class="eat-card eat-info-review-card">
            <div class="eat-info-review-grid">
                <!-- Left Column: Information -->
                <div class="eat-info-column">
                    <h3><?php esc_html_e('Information', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    <div class="eat-info-content">
                        <p><?php esc_html_e('Translation engine is not ideal - errors can be spotted in translated pages. We are constantly updating algorithm to provide you with the best experience. If you spot a bug in translation contact us via feedback form.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                        <div class="eat-info-buttons">
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
                <div class="eat-review-column">
                    <h3><?php esc_html_e('Rate Our Plugin', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    <div class="eat-review-content">
                        <p><?php esc_html_e('If you enjoy using Meep AI Translator, please take a moment to rate us on WordPress.org! Your feedback helps us improve the plugin and reach more users who could benefit from it.', 'ai-translator-for-elementor-and-polylang'); ?></p>
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
            
            <div class="eat-card" style="max-width: 800px; margin-top: 20px;">
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
        <div class="eat-setup-checklist" id="eat-setup-checklist">
            <div class="eat-setup-checklist-header">
                <div class="eat-setup-checklist-title">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php esc_html_e('Setup Checklist', 'ai-translator-for-elementor-and-polylang'); ?>
                    <span class="eat-setup-checklist-progress-text">
                        <?php
                        /* translators: 1: completed steps, 2: total steps */
                        printf(esc_html__('%1$d of %2$d completed', 'ai-translator-for-elementor-and-polylang'), $completed_count, $total_count);
                        ?>
                    </span>
                </div>
                <button type="button" class="eat-setup-checklist-dismiss" id="eat-dismiss-checklist" title="<?php esc_attr_e('Dismiss checklist', 'ai-translator-for-elementor-and-polylang'); ?>">
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </div>
            <div class="eat-setup-checklist-progress">
                <div class="eat-setup-checklist-progress-bar" style="width: <?php echo absint($progress_pct); ?>%;"></div>
            </div>
            <ul class="eat-setup-checklist-items">
                <?php foreach ($checklist as $item): ?>
                <li class="eat-setup-checklist-item <?php echo $item['complete'] ? 'is-complete' : ''; ?>">
                    <span class="eat-setup-check-icon">
                        <?php if ($item['complete']): ?>
                            <span class="dashicons dashicons-yes"></span>
                        <?php else: ?>
                            <span class="eat-setup-check-empty"></span>
                        <?php endif; ?>
                    </span>
                    <div class="eat-setup-check-content">
                        <strong><?php echo esc_html($item['title']); ?></strong>
                        <p><?php echo esc_html($item['description']); ?></p>
                    </div>
                    <?php if (!$item['complete'] && !empty($item['action_url'])): ?>
                        <a href="<?php echo esc_url($item['action_url']); ?>" class="button button-small">
                            <?php echo esc_html($item['action_label']); ?>
                        </a>
                    <?php endif; ?>
                    <?php if (!$item['complete']): ?>
                        <button type="button" class="button button-small button-link eat-setup-skip-btn" data-step="<?php echo esc_attr($item['key']); ?>">
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
        <div class="eat-setup-checklist eat-setup-checklist--wp-dashboard">
            <div class="eat-setup-checklist-progress">
                <div class="eat-setup-checklist-progress-bar" style="width: <?php echo absint($progress_pct); ?>%;"></div>
            </div>
            <p class="eat-setup-checklist-progress-text" style="margin: 8px 0;">
                <?php
                /* translators: 1: completed steps, 2: total steps */
                printf(esc_html__('%1$d of %2$d steps completed', 'ai-translator-for-elementor-and-polylang'), $completed_count, $total_count);
                ?>
            </p>
            <ul class="eat-setup-checklist-items">
                <?php foreach ($checklist as $item): ?>
                <li class="eat-setup-checklist-item <?php echo $item['complete'] ? 'is-complete' : ''; ?>">
                    <span class="eat-setup-check-icon">
                        <?php if ($item['complete']): ?>
                            <span class="dashicons dashicons-yes"></span>
                        <?php else: ?>
                            <span class="eat-setup-check-empty"></span>
                        <?php endif; ?>
                    </span>
                    <div class="eat-setup-check-content">
                        <strong><?php echo esc_html($item['title']); ?></strong>
                        <p><?php echo esc_html($item['description']); ?></p>
                    </div>
                    <?php if (!$item['complete'] && !empty($item['action_url'])): ?>
                        <a href="<?php echo esc_url($item['action_url']); ?>" class="button button-small">
                            <?php echo esc_html($item['action_label']); ?>
                        </a>
                    <?php endif; ?>
                    <?php if (!$item['complete']): ?>
                        <button type="button" class="button button-small button-link eat-setup-skip-btn" data-step="<?php echo esc_attr($item['key']); ?>">
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
            $('#aitrfoel_setup_checklist_widget').on('click', '.eat-setup-skip-btn', function(){
                var $btn = $(this), step = $btn.data('step');
                var $item = $btn.closest('.eat-setup-checklist-item');
                $item.addClass('is-complete');
                $item.find('.eat-setup-check-empty').replaceWith('<span class="dashicons dashicons-yes"></span>');
                $item.find('.eat-setup-check-content strong').css({'text-decoration':'line-through','color':'#64748b'});
                $item.find('.button-small').remove();
                var $cl = $item.closest('.eat-setup-checklist--wp-dashboard');
                var total = $cl.find('.eat-setup-checklist-item').length;
                var done = $cl.find('.eat-setup-checklist-item.is-complete').length;
                $cl.find('.eat-setup-checklist-progress-bar').css('width', Math.round((done/total)*100)+'%');
                $cl.find('.eat-setup-checklist-progress-text').text(done+' of '+total+' steps completed');
                if(done>=total){setTimeout(function(){$cl.slideUp(300);},400);}
                $.post(ajaxUrl,{action:'aitrfoel_skip_setup_step',nonce:nonce,step:step});
            });
        });
        </script>
        <?php
    }
}