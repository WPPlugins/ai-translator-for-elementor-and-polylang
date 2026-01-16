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
    }

    public function add_admin_menu() {
        // Create main menu page
        add_menu_page(
            __('Dashboard', 'ai-translator-for-elementor-and-polylang'),
            __('AI Translator', 'ai-translator-for-elementor-and-polylang'),
            'manage_options',
            'ai-translator-for-elementor-and-polylang',
            [$this, 'render_dashboard_page'],
            'dashicons-translation',
            30
        );

        // Add Settings submenu
        add_submenu_page(
            'ai-translator-for-elementor-and-polylang',
            __('Settings', 'ai-translator-for-elementor-and-polylang'),
            __('Settings', 'ai-translator-for-elementor-and-polylang'),
            'manage_options',
            'ai-translator-for-elementor-and-polylang-settings',
            ['AITRFOEL_Settings', 'render_page']
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
                    if (is_object($result) && isset($result->error)) {
                        $error_message = $result->error->message ?? __('License activation failed.', 'ai-translator-for-elementor-and-polylang');
                    } else {
                        // Success - redirect to account page
                        wp_redirect(admin_url('admin.php?page=ai-translator-for-elementor-and-polylang-account&activated=true'));
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
                    <li><?php esc_html_e('Deactivate "AI Translator for Elementor and Polylang"', 'ai-translator-for-elementor-and-polylang'); ?></li>
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
            <h1><?php esc_html_e('AI Translator for Elementor and Polylang', 'ai-translator-for-elementor-and-polylang'); ?></h1>
            
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
                            <p><?php esc_html_e('If you enjoy using AI Translator for Elementor & Polylang, please take a moment to rate us on WordPress.org! Your feedback helps us improve the plugin and reach more users who could benefit from it.', 'ai-translator-for-elementor-and-polylang'); ?></p>
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
            'post_type' => 'page',
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
     * Render Dashboard page for bulk translations
     */
    public function render_dashboard_page() {
        // Check for missing dependencies first
        $deps_status = get_option('aitrfoel_dependencies_status', ['status' => true, 'missing' => []]);
        
        if (!$deps_status['status'] && !empty($deps_status['missing'])) {
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('AI Translator Dashboard', 'ai-translator-for-elementor-and-polylang'); ?></h1>
                <div class="notice notice-warning" style="padding: 15px;">
                    <h3 style="margin-top: 0;"><?php esc_html_e('Required Plugins Missing', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    <p><?php esc_html_e('The AI Translator plugin requires the following plugins to be installed and activated:', 'ai-translator-for-elementor-and-polylang'); ?></p>
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
                    <p style="margin-bottom: 0;"><em><?php esc_html_e('After installing the required plugins, please refresh this page.', 'ai-translator-for-elementor-and-polylang'); ?></em></p>
                </div>
            </div>
            <?php
            return;
        }
        
        // Check if multilingual plugin is active
        $multilingual = class_exists('AITRFOEL_Multilingual_Manager') ? AITRFOEL_Multilingual_Manager::get_instance() : null;
        if (!$multilingual || !$multilingual->is_active()) {
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('Translation Dashboard', 'ai-translator-for-elementor-and-polylang'); ?></h1>
                <div class="notice notice-error">
                    <p><?php esc_html_e('A multilingual plugin (Polylang or WPML) is required for the Dashboard functionality.', 'ai-translator-for-elementor-and-polylang'); ?></p>
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
            
            <p class="description"><?php esc_html_e('Manage translations for your pages. Select source pages to create bulk translations.', 'ai-translator-for-elementor-and-polylang'); ?></p>
            
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
                                <?php esc_html_e('🌐 Auto-detect ▼', 'ai-translator-for-elementor-and-polylang'); ?>
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
                                <?php esc_html_e('Select languages ▼', 'ai-translator-for-elementor-and-polylang'); ?>
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
                            ⚙️ <?php esc_html_e('Settings', 'ai-translator-for-elementor-and-polylang'); ?>
                        </button>
                        <button type="button" class="button button-primary" id="eat-start-translation" disabled title="Select pages and languages to start">
                            🚀 <?php esc_html_e('Start Translation', 'ai-translator-for-elementor-and-polylang'); ?>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Pages Table -->
            <div class="aitrfoel-dashboard-table">
                <table class="wp-list-table widefat fixed">
                    <thead>
                        <tr>
                            <td id="cb" class="manage-column column-cb check-column">
                                <input id="cb-select-all" type="checkbox">
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
                        <?php $this->render_pages_rows($pages_data); ?>
                    </tbody>
                </table>
            </div>
            
            <?php $this->render_quick_tips(); ?>
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

        // Get all pages
        $all_pages = get_posts([
            'post_type' => 'page',
            'post_status' => ['publish', 'draft'],
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'suppress_filters' => true // Get posts in all languages for WPML
        ]);

        $translation_groups = [];
        $processed_pages = [];

        foreach ($all_pages as $page) {
            // Skip if already processed as part of another translation group
            if (in_array($page->ID, $processed_pages)) {
                continue;
            }

            $page_lang = $multilingual->get_post_language($page->ID);
            $translations = $multilingual->get_post_translations($page->ID);
            
            // Find the source page - proper logic for parent determination
            $default_lang = $multilingual->get_default_language();
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

                $translation_groups[] = $group;
                $processed_pages[] = $source_page->ID;
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
            echo '<tr><td colspan="6">' . esc_html__('No pages found.', 'ai-translator-for-elementor-and-polylang') . '</td></tr>';
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
                        <a class="eat-page-title-link" href="<?php echo esc_url(get_permalink($source->ID)); ?>" target="_blank" rel="noopener">
                            🏠 <?php echo esc_html($source->post_title); ?>
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
                    <a href="<?php echo esc_url(get_permalink($source->ID)); ?>" class="button button-small" target="_blank">
                        <?php esc_html_e('View', 'ai-translator-for-elementor-and-polylang'); ?>
                    </a>
                    <button type="button" 
                            class="button button-small eat-delete-page" 
                            data-page-id="<?php echo absint($source->ID); ?>"
                            data-page-title="<?php echo esc_attr($source->post_title); ?>"
                            title="Move to trash">
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
                ?>
                <tr class="eat-translation-page eat-translation-collapsed" 
                    data-parent-id="<?php echo absint($source->ID); ?>" 
                    data-page-id="<?php echo absint($translation->ID); ?>"
                    style="display: none;">
                    <th scope="row" class="check-column">
                        <!-- No checkbox for translations -->
                    </th>
                    <td class="column-title">
                        <strong class="eat-translation-indent">
                            <a class="eat-page-title-link" href="<?php echo esc_url(get_permalink($translation->ID)); ?>" target="_blank" rel="noopener">
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
                        <a href="<?php echo esc_url(get_permalink($translation->ID)); ?>" class="button button-small" target="_blank">
                            <?php esc_html_e('View', 'ai-translator-for-elementor-and-polylang'); ?>
                        </a>
                        <button type="button" 
                                class="button button-small eat-delete-page" 
                                data-page-id="<?php echo absint($translation->ID); ?>"
                                data-page-title="<?php echo esc_attr($translation->post_title); ?>"
                                title="Move to trash">
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
        
        // Capitalize for display
        $license_status = ucfirst(strtolower($license_status));
        
        return [
            'type' => strtolower($license_status),
            'limit' => $word_limit,
            'name' => $license_status,
            'is_active' => (function_exists('eatp_fs') && eatp_fs()->is_registered()),
            'license_expired' => $license_expired,
            'freemius_validated' => isset($server_status['freemius_validated']) ? $server_status['freemius_validated'] : false
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
            $basic_words = str_word_count(wp_strip_all_tags($content));
            
            // Try to get more accurate count from Elementor data if available
            $elementor_data = get_post_meta($page->ID, '_elementor_data', true);
            if (!empty($elementor_data)) {
                // This is a simplified estimation - in reality you'd want to parse Elementor data
                $elementor_words = str_word_count(wp_strip_all_tags($elementor_data));
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
        $words_left = max(0, $total_words - $words_translated);
        
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
                <span class="eat-compact-value eat-words-left-value eat-tooltip <?php echo $words_left <= 0 ? 'eat-compact-limit-reached' : ''; ?>" 
                      title="<?php /* translators: 1: Words translated, 2: Word limit */ printf(esc_attr__('Used: %1$s of %2$s words', 'ai-translator-for-elementor-and-polylang'), 
                                     number_format($words_translated),
                                     number_format($license_info['limit'])); ?>">
                    <?php echo number_format($words_left); ?><?php if ($words_left <= 0) echo ' ⚠️'; ?>
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
                                             number_format($license_info['limit'])); ?>">
                            <?php echo esc_html($license_info['name']); ?>
                        </span>
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
            ? __('AI Translator for Elementor and Polylang', 'ai-translator-for-elementor-and-polylang')
            : __('AI Translator for Elementor and Polylang', 'ai-translator-for-elementor-and-polylang');
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
                        <p><?php esc_html_e('If you enjoy using AI Translator for Elementor & Polylang, please take a moment to rate us on WordPress.org! Your feedback helps us improve the plugin and reach more users who could benefit from it.', 'ai-translator-for-elementor-and-polylang'); ?></p>
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
}