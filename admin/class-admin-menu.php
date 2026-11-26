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
        // Meep "m" mark (white, from assets/meep-icon.svg) for the dark admin sidebar.
        $menu_icon = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA0NC42OCAyOS4zIj48cGF0aCBmaWxsPSIjRkVGRUZFIiBkPSJNMCAyOS4zbDcuODQgMCAwIC0xNi41NmMwLC01LjAyIDMuMzksLTUuOSA1LjIxLC01LjkgMS44MiwwIDUuMzQsMC44OCA1LjM0LDUuOWwwIDE2LjU2IDcuODQgMCAwIC0xNi41NmMwLC01LjAyIDMuNDUsLTUuOSA1LjIxLC01LjkgMS44MiwwIDUuMzksMC44OCA1LjM5LDUuOWwwIDE2LjU2IDcuODUgMCAwIC0xNy42M2MwLC05LjA0IC01LjcxLC0xMS42NyAtMTAuOCwtMTEuNjcgLTMuMiwwIC03LjAyLDEuMDcgLTkuNDcsNC4zOSAtMi4wNywtMy4yNiAtNS41OCwtNC4zOSAtOC45MSwtNC4zOSAtMi42NCwwIC01LjUyLDEuMDEgLTcuNjYsMy4zM2wwIC0yLjg5IC03Ljg0IDAgMCAyOC44NiAwIDB6Ii8+PC9zdmc+';

        add_menu_page(
            __('Meep AI Translator', 'ai-translator-for-elementor-and-polylang'), // page title (heading / browser tab)
            __('Meep Translator', 'ai-translator-for-elementor-and-polylang'),     // sidebar menu label
            'manage_options',
            'ai-translator-for-elementor-and-polylang',
            [$this, 'render_dashboard_page'],
            $menu_icon,
            30
        );

        // Rename the auto-created first submenu item from the menu label to "Dashboard".
        add_submenu_page(
            'ai-translator-for-elementor-and-polylang',
            __('Dashboard', 'ai-translator-for-elementor-and-polylang'),
            __('Dashboard', 'ai-translator-for-elementor-and-polylang'),
            'manage_options',
            'ai-translator-for-elementor-and-polylang',
            [$this, 'render_dashboard_page']
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
        } else {
            // In the Free version (where Freemius submenu is absent), provide a direct "Upgrade to Pro" item
            global $submenu;
            $upgrade_menu_url = aitrfoel_free_upgrade_url('sidebar-menu');
            $submenu['ai-translator-for-elementor-and-polylang'][] = [
                '<span class="aitrfoel-sidebar-upgrade-link" style="color:#38bdf8;font-weight:600;">' . esc_html__('Upgrade to Pro', 'ai-translator-for-elementor-and-polylang') . ' &rarr;</span>',
                'manage_options',
                $upgrade_menu_url,
            ];
            add_action('admin_footer', function () {
                echo '<script>document.addEventListener("DOMContentLoaded",function(){var el=document.querySelector(".aitrfoel-sidebar-upgrade-link");if(el&&el.parentElement&&el.parentElement.tagName==="A"){el.parentElement.setAttribute("target","_blank");el.parentElement.setAttribute("rel","noopener noreferrer");}});</script>';
            });
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
            
            if (!empty($license_key) && function_exists('eatp_fs') && is_object(eatp_fs())) {
                // If this license is already active in Freemius, sync with server directly
                $current_license = method_exists(eatp_fs(), '_get_license') ? eatp_fs()->_get_license() : null;
                $already_active = ($current_license && isset($current_license->secret_key) && $current_license->secret_key === $license_key);

                if ($already_active) {
                    if (class_exists('AITRFOEL_License_Manager_Server')) {
                        AITRFOEL_License_Manager_Server::get_instance()->clear_status_cache();
                        AITRFOEL_License_Manager_Server::get_instance()->sync_plan_to_server();
                    }
                    $success_message = __('Your license is already active and up to date! Unlimited translations are enabled.', 'ai-translator-for-elementor-and-polylang');
                } else {
                    // Try to activate the license using Freemius
                    try {
                        $result = eatp_fs()->activate_migrated_license($license_key);
                        
                        // activate_migrated_license returns an array, not an object
                        if (is_array($result) && isset($result['error'])) {
                            $error_message = is_string($result['error']) ? $result['error'] : __('License activation failed.', 'ai-translator-for-elementor-and-polylang');
                        } elseif (is_object($result) && isset($result->error)) {
                            $error_message = isset($result->error->message) ? $result->error->message : __('License activation failed.', 'ai-translator-for-elementor-and-polylang');
                        } else {
                            // Clear status cache and force fresh status
                            if (class_exists('AITRFOEL_License_Manager_Server')) {
                                AITRFOEL_License_Manager_Server::get_instance()->clear_status_cache();
                                delete_option('aitrfoel_cached_license_key');
                            }

                            // Check if pending email confirmation
                            if (method_exists(eatp_fs(), 'is_pending_activation') && eatp_fs()->is_pending_activation()) {
                                $redirect_url = admin_url('admin.php?page=ai-translator-for-elementor-and-polylang&license_pending=true');
                            } else {
                                $redirect_url = admin_url('admin.php?page=ai-translator-for-elementor-and-polylang&activated=true');
                                if (is_array($result) && !empty($result['next_page'])) {
                                    $redirect_url = $result['next_page'];
                                }
                            }

                            // Render JS redirect rather than wp_redirect() + exit, because admin headers have already been sent by WordPress
                            echo '<script type="text/javascript">window.location.href = ' . json_encode($redirect_url) . ';</script>';
                            echo '<noscript><meta http-equiv="refresh" content="0;url=' . esc_url($redirect_url) . '"></noscript>';
                            echo '<div class="notice notice-success"><p>' . esc_html__('License activated successfully! Redirecting...', 'ai-translator-for-elementor-and-polylang') . ' <a href="' . esc_url($redirect_url) . '">' . esc_html__('Click here if not redirected automatically.', 'ai-translator-for-elementor-and-polylang') . '</a></p></div>';
                            return;
                        }
                    } catch (Exception $e) {
                        $error_message = $e->getMessage();
                    }
                }
            } else {
                $error_message = __('Please enter a valid license key.', 'ai-translator-for-elementor-and-polylang');
            }
        }
        // Check current license status
        $current_license = null;
        $is_paying = false;
        if (function_exists('eatp_fs') && is_object(eatp_fs())) {
            $current_license = method_exists(eatp_fs(), '_get_license') ? eatp_fs()->_get_license() : null;
            $is_paying = method_exists(eatp_fs(), 'is_paying') ? eatp_fs()->is_paying() : false;
        }
        ?>
        <div class="wrap aitrfoel-admin-wrapper">
            <?php $this->render_plugin_header(__('Activate License', 'ai-translator-for-elementor-and-polylang')); ?>
            
            <?php if (isset($error_message)): ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html($error_message); ?></p>
                </div>
            <?php endif; ?>
            <?php if (isset($success_message)): ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong><?php echo esc_html($success_message); ?></strong></p>
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
                        
                        <?php if (function_exists('eatp_fs') && is_object(eatp_fs()) && method_exists(eatp_fs(), 'is_registered') && eatp_fs()->is_registered()): ?>
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
                    <li><a href="https://meeptech.com/contact" target="_blank"><?php esc_html_e('Contact support if you need assistance', 'ai-translator-for-elementor-and-polylang'); ?></a></li>
                </ul>
            </div>
            
            <?php if (!function_exists('eatp_fs') || !is_object(eatp_fs()) || !method_exists(eatp_fs(), 'is_registered') || !eatp_fs()->is_registered()): ?>
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
                    <button id="aitrfoel-estimate-cost-btn" class="button button-secondary button-large" style="display:inline-flex;align-items:center;gap:6px;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                        <span><?php esc_html_e('Estimate Cost', 'ai-translator-for-elementor-and-polylang'); ?></span>
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
                        <button id="aitrfoel-refresh-stats-btn" class="button button-small" style="display:inline-flex;align-items:center;gap:5px;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                            <span><?php esc_html_e('Refresh Stats', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </button>
                    </div>
                    <div id="aitrfoel-usage-stats-display"></div>
                </div>
                
                <!-- Status Messages -->
                <div id="aitrfoel-status"></div>
            </div>
            
            <?php $this->render_quick_tips(); ?>
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
                <h2><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:6px;" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg><?php esc_html_e('API Key Required', 'ai-translator-for-elementor-and-polylang'); ?></h2>
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
     * Languages configured in other translation plugins (active or left behind).
     *
     * @return array<string,string[]> plugin name => language codes
     */
    private function get_languages_from_other_translation_plugins() {
        $found = [];
        $to_code = function ($locale) {
            $locale = strtolower(str_replace('-', '_', (string) $locale));
            if (in_array($locale, ['pt_br', 'zh_tw', 'zh_hk'], true)) {
                return str_replace('_', '-', $locale);
            }
            return substr($locale, 0, 2);
        };

        // TranslatePress keeps locales in trp_settings.
        $trp = get_option('trp_settings');
        if (is_array($trp)) {
            $locales = !empty($trp['translation-languages']) ? (array) $trp['translation-languages'] : [];
            $default = isset($trp['default-language']) ? $to_code($trp['default-language']) : '';
            $codes   = [];
            foreach ($locales as $loc) {
                $c = $to_code($loc);
                if ($c && $c !== $default) {
                    $codes[] = $c;
                }
            }
            if ($codes) {
                $found['TranslatePress'] = array_values(array_unique($codes));
            }
        }

        // WPML deactivated but its language table remains.
        global $wpdb;
        $icl = $wpdb->prefix . 'icl_languages';
        if (empty($found) && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $icl)) === $icl) {
            $wpml_settings = get_option('icl_sitepress_settings');
            $default = is_array($wpml_settings) && !empty($wpml_settings['default_language']) ? $wpml_settings['default_language'] : '';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from $wpdb->prefix.
            $codes = $wpdb->get_col("SELECT code FROM `{$icl}` WHERE active = 1");
            $codes = array_values(array_diff(array_map('strtolower', (array) $codes), [$default]));
            if ($codes) {
                $found['WPML'] = $codes;
            }
        }

        return $found;
    }

    /**
     * Up to 6 target-language suggestions, most specific signal first:
     * other translation plugins → installed WordPress language packs → WooCommerce
     * selling countries → common pairs for the site's language.
     */
    private function get_suggested_target_languages($default_code, $popular_languages, $detected_languages) {
        $reasons = [];
        $add = function ($code, $reason) use (&$reasons, $default_code) {
            $code = strtolower((string) $code);
            if ($code === '' || $code === $default_code || isset($reasons[$code])) {
                return;
            }
            $reasons[$code] = $reason;
        };

        foreach ((array) $detected_languages as $l) {
            $add($l['code'], __('Already used on this site', 'ai-translator-for-elementor-and-polylang'));
        }

        foreach ((array) get_available_languages() as $locale) {
            $add(substr($locale, 0, 2), __('WordPress language pack installed', 'ai-translator-for-elementor-and-polylang'));
        }

        if (function_exists('WC') || class_exists('WooCommerce')) {
            $country_lang = [
                'DE' => 'de', 'AT' => 'de', 'CH' => 'de', 'FR' => 'fr', 'BE' => 'fr', 'LU' => 'fr', 'ES' => 'es', 'MX' => 'es',
                'AR' => 'es', 'CO' => 'es', 'CL' => 'es', 'IT' => 'it', 'PT' => 'pt', 'BR' => 'pt', 'NL' => 'nl', 'PL' => 'pl',
                'CZ' => 'cs', 'SK' => 'sk', 'UA' => 'uk', 'SE' => 'sv', 'NO' => 'nb', 'DK' => 'da', 'FI' => 'fi', 'GR' => 'el',
                'TR' => 'tr', 'RO' => 'ro', 'HU' => 'hu', 'BG' => 'bg', 'HR' => 'hr', 'SI' => 'sl', 'LT' => 'lt', 'LV' => 'lv',
                'EE' => 'et', 'JP' => 'ja', 'CN' => 'zh', 'KR' => 'ko', 'SA' => 'ar', 'AE' => 'ar', 'IN' => 'hi', 'IL' => 'he',
                'US' => 'en', 'GB' => 'en', 'IE' => 'en', 'AU' => 'en', 'CA' => 'en', 'NZ' => 'en',
            ];
            $countries = [];
            if (get_option('woocommerce_allowed_countries') === 'specific') {
                $countries = (array) get_option('woocommerce_specific_allowed_countries', []);
            } elseif (get_option('woocommerce_ship_to_countries') === 'specific') {
                $countries = (array) get_option('woocommerce_specific_ship_to_countries', []);
            }
            foreach ($countries as $cc) {
                if (isset($country_lang[$cc])) {
                    /* translators: %s: country code */
                    $add($country_lang[$cc], sprintf(__('Your store sells to %s', 'ai-translator-for-elementor-and-polylang'), $cc));
                }
            }
        }

        $common = [
            'en' => ['es', 'fr', 'de', 'it', 'pt', 'nl'],
            'de' => ['en', 'fr', 'it', 'pl', 'nl', 'es'],
            'fr' => ['en', 'de', 'es', 'it', 'nl', 'pt'],
            'es' => ['en', 'pt', 'fr', 'it', 'de', 'ca'],
            'it' => ['en', 'de', 'fr', 'es', 'pt', 'nl'],
            'pl' => ['en', 'de', 'uk', 'cs', 'fr', 'es'],
            'nl' => ['en', 'de', 'fr', 'es', 'it', 'pl'],
            'pt' => ['en', 'es', 'fr', 'de', 'it', 'nl'],
            'uk' => ['en', 'pl', 'de', 'ru', 'fr', 'es'],
            'ru' => ['en', 'uk', 'de', 'pl', 'fr', 'es'],
            'cs' => ['en', 'de', 'sk', 'pl', 'fr', 'es'],
        ];
        foreach ($common[$default_code] ?? ['en', 'es', 'fr', 'de', 'it', 'pt'] as $code) {
            $add($code, '');
        }

        $popular_by_code = [];
        foreach ($popular_languages as $l) {
            $popular_by_code[$l['code']] = $l;
        }
        $out = [];
        foreach ($reasons as $code => $reason) {
            if (count($out) >= 6) {
                break;
            }
            if (isset($popular_by_code[$code])) {
                $lang = $popular_by_code[$code];
            } else {
                $name = class_exists('AITRFOEL_Language_Mapper') ? AITRFOEL_Language_Mapper::get_language_name($code, false) : '';
                if (!$name) {
                    continue;
                }
                $flag = class_exists('AITRFOEL_Language_Mapper') ? AITRFOEL_Language_Mapper::get_country_code($code) : $code;
                $lang = ['code' => $code, 'name' => $name, 'flag' => $flag];
            }
            $lang['reason'] = $reason;
            $out[] = $lang;
        }
        return $out;
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
        
        // Other translation plugins (active or left behind): pre-select their languages too.
        if (empty($detected_languages)) {
            foreach ($this->get_languages_from_other_translation_plugins() as $plugin_name => $codes) {
                if (empty($codes)) {
                    continue;
                }
                foreach ($codes as $code) {
                    $name = class_exists('AITRFOEL_Language_Mapper') ? AITRFOEL_Language_Mapper::get_language_name($code, false) : '';
                    $detected_languages[] = ['code' => $code, 'name' => $name ?: strtoupper($code)];
                }
                if (!$had_previous_plugin) {
                    $had_previous_plugin  = true;
                    $previous_plugin_name = $plugin_name;
                }
                break;
            }
        }

        // Site language → default source language (was hard-coded to English).
        $site_lang_code = strtolower(substr((string) get_locale(), 0, 2)) ?: 'en';
        $wizard_default_lang = $detected_default_lang ?: $site_lang_code;
        if (!in_array($wizard_default_lang, array_column($available_languages, 'code'), true)) {
            $wizard_default_lang = 'en';
        }

        // Up to 6 suggested target languages; the rest of the popular list is folded away.
        $suggested_languages = $this->get_suggested_target_languages($wizard_default_lang, $popular_languages, $detected_languages);
        $suggested_codes     = array_column($suggested_languages, 'code');
        $more_languages      = array_values(array_filter($popular_languages, function ($l) use ($suggested_codes, $wizard_default_lang) {
            return !in_array($l['code'], $suggested_codes, true) && $l['code'] !== $wizard_default_lang;
        }));

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
                    <div class="step-label"><?php esc_html_e('System', 'ai-translator-for-elementor-and-polylang'); ?></div>
                </div>
                <div class="step-line" data-after="1"></div>
                <div class="step" data-num="2">
                    <div class="step-dot" data-step="2">02</div>
                    <div class="step-label"><?php esc_html_e('Languages', 'ai-translator-for-elementor-and-polylang'); ?></div>
                </div>
                <div class="step-line" data-after="2"></div>
                <div class="step" data-num="3">
                    <div class="step-dot" data-step="3">03</div>
                    <div class="step-label"><?php esc_html_e('Setup', 'ai-translator-for-elementor-and-polylang'); ?></div>
                </div>
                <div class="step-line" data-after="3"></div>
                <div class="step" data-num="4">
                    <div class="step-dot" data-step="4">04</div>
                    <div class="step-label"><?php esc_html_e('Ready', 'ai-translator-for-elementor-and-polylang'); ?></div>
                </div>
            </div>

            <!-- ═══ STEP 1: Language Management System ═══ -->
            <div class="wiz-panel active" data-panel="1">

                <div class="brand-row">
                    <div class="aitrfoel-wiz-logo">
                        <img src="<?php echo esc_url(AITRFOEL_PLUGIN_URL . 'assets/img/meep-ai-translator.png'); ?>" alt="Meep AI Translator" style="height: 32px; width: auto;">
                    </div>
                    <span class="brand-pill"><?php esc_html_e('Meep Translator', 'ai-translator-for-elementor-and-polylang'); ?></span>
                </div>

                <div class="eyebrow"><?php esc_html_e('step.01 - language system', 'ai-translator-for-elementor-and-polylang'); ?></div>
                <h1 class="wiz-title"><?php esc_html_e('Choose your multilingual', 'ai-translator-for-elementor-and-polylang'); ?> <span class="accent"><?php esc_html_e('engine', 'ai-translator-for-elementor-and-polylang'); ?></span>.</h1>
                <p class="wiz-sub"><?php esc_html_e('Meep AI Translator includes a lightning-fast native multilingual engine or seamlessly connects to existing WordPress translation plugins.', 'ai-translator-for-elementor-and-polylang'); ?></p>

                <div class="system-grid" style="margin-top: 24px;">
                    <!-- Built-in (recommended) -->
                    <label class="system-card <?php echo (!$polylang_active && !$wpml_active) ? 'selected' : ''; ?>" data-system="builtin">
                        <input type="radio" name="aitrfoel_system_choice" value="builtin" <?php echo (!$polylang_active && !$wpml_active) ? 'checked' : ''; ?>>
                        <div class="badge"><?php esc_html_e('Recommended', 'ai-translator-for-elementor-and-polylang'); ?></div>
                        <div class="icon">
                            <svg width="32" height="32" viewBox="0 0 32 32" fill="none"><circle cx="16" cy="16" r="14" stroke="#2271b1" stroke-width="2"/><path d="M10 16l4 4 8-8" stroke="#2271b1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </div>
                        <h3><?php esc_html_e('Meep Built-in System', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                        <p><?php esc_html_e('Zero extra plugins. Clean /es/ subdirectories, SEO hreflang tags, automatic URL routing, and built-in language switchers.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                    </label>

                    <!-- Polylang -->
                    <label class="system-card <?php echo ($polylang_active) ? 'selected' : ''; ?>" data-system="polylang" id="aitrfoel-wiz-card-polylang">
                        <input type="radio" name="aitrfoel_system_choice" value="polylang" <?php echo ($polylang_active) ? 'checked' : ''; ?>>
                        <?php if ($polylang_active): ?>
                            <div class="badge" style="background:#dcfce7;color:#15803d;"><?php esc_html_e('Active & Detected', 'ai-translator-for-elementor-and-polylang'); ?></div>
                        <?php elseif ($polylang_installed): ?>
                            <div class="badge muted"><?php esc_html_e('Installed', 'ai-translator-for-elementor-and-polylang'); ?></div>
                        <?php else: ?>
                            <div class="badge muted"><?php esc_html_e('Not installed', 'ai-translator-for-elementor-and-polylang'); ?></div>
                        <?php endif; ?>
                        <div class="icon">
                            <svg width="32" height="32" viewBox="0 0 32 32" fill="none"><circle cx="16" cy="16" r="14" stroke="#00a32a" stroke-width="2"/><text x="16" y="21" text-anchor="middle" font-size="14" font-weight="bold" fill="#00a32a">P</text></svg>
                        </div>
                        <h3>Polylang</h3>
                        <p><?php esc_html_e('Use Polylang to manage language taxonomies, translations relationships, and switcher widgets.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                    </label>

                    <!-- WPML -->
                    <label class="system-card <?php echo ($wpml_active) ? 'selected' : ''; ?>" data-system="wpml" id="aitrfoel-wiz-card-wpml">
                        <input type="radio" name="aitrfoel_system_choice" value="wpml" <?php echo ($wpml_active) ? 'checked' : ''; ?>>
                        <?php if ($wpml_active): ?>
                            <div class="badge" style="background:#dcfce7;color:#15803d;"><?php esc_html_e('Active & Detected', 'ai-translator-for-elementor-and-polylang'); ?></div>
                        <?php elseif ($wpml_installed): ?>
                            <div class="badge muted"><?php esc_html_e('Installed', 'ai-translator-for-elementor-and-polylang'); ?></div>
                        <?php else: ?>
                            <div class="badge warn"><?php esc_html_e('Not installed', 'ai-translator-for-elementor-and-polylang'); ?></div>
                        <?php endif; ?>
                        <div class="icon">
                            <svg width="32" height="32" viewBox="0 0 32 32" fill="none"><circle cx="16" cy="16" r="14" stroke="#33769e" stroke-width="2"/><text x="16" y="21" text-anchor="middle" font-size="12" font-weight="bold" fill="#33769e">W</text></svg>
                        </div>
                        <h3>WPML</h3>
                        <p><?php esc_html_e('Connect directly to WPML translation management and ICL language tables.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                    </label>
                </div>

                <div class="wiz-nav right" style="margin-top: 32px;">
                    <button type="button" class="btn btn-primary" id="aitrfoel-wiz-to-step2">
                        <?php esc_html_e('Continue to Languages', 'ai-translator-for-elementor-and-polylang'); ?>
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M6 3l5 5-5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                </div>

            </div><!-- /panel 1 -->

            <!-- ═══ STEP 2: Languages ═══ -->
            <div class="wiz-panel" data-panel="2">

                <div class="brand-row">
                    <div class="aitrfoel-wiz-logo">
                        <img src="<?php echo esc_url(AITRFOEL_PLUGIN_URL . 'assets/img/meep-ai-translator.png'); ?>" alt="Meep AI Translator" style="height: 32px; width: auto;">
                    </div>
                    <span class="brand-pill"><?php esc_html_e('Meep Translator', 'ai-translator-for-elementor-and-polylang'); ?></span>
                </div>

                <div class="eyebrow"><?php esc_html_e('step.02 - languages', 'ai-translator-for-elementor-and-polylang'); ?></div>
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
                            <img class="chip-flag" src="<?php echo esc_url(class_exists('AITRFOEL_Language_Mapper') ? AITRFOEL_Language_Mapper::get_flag_url_for_country($dflag, 40) : 'https://flagcdn.com/w40/' . $dflag . '.png'); ?>" alt="" width="18" height="13">
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
                            <option value="<?php echo esc_attr($lang['code']); ?>" <?php selected($lang['code'], $wizard_default_lang); ?>>
                                <?php echo esc_html($lang['name']); ?> (<?php echo esc_html(strtoupper($lang['code'])); ?>)
                            </option>
                        <?php endforeach; ?>
                </select>

                <!-- Selected Language Chips -->
                <div class="field-label"><?php esc_html_e('Target languages', 'ai-translator-for-elementor-and-polylang'); ?><span class="field-mono" id="aitrfoel-selected-count">// 0 selected</span></div>
                <div class="selected-chips" id="aitrfoel-wiz-chips"></div>

                <!-- Suggested + more languages (one click container: #aitrfoel-wiz-lang-grid) -->
                <div id="aitrfoel-wiz-lang-grid">
                    <div class="field-label"><?php esc_html_e('Suggested for your site', 'ai-translator-for-elementor-and-polylang'); ?><span class="field-mono">// click to add</span></div>
                    <div class="lang-grid">
                    <?php foreach ($suggested_languages as $lang): ?>
                    <button type="button" class="lang-btn" data-code="<?php echo esc_attr($lang['code']); ?>" data-name="<?php echo esc_attr($lang['name']); ?>"<?php echo !empty($lang['reason']) ? ' title="' . esc_attr($lang['reason']) . '"' : ''; ?>>
                        <img class="flag" src="<?php echo esc_url(class_exists('AITRFOEL_Language_Mapper') ? AITRFOEL_Language_Mapper::get_flag_url_for_country($lang['flag'], 40) : 'https://flagcdn.com/w40/' . $lang['flag'] . '.png'); ?>"
                             alt="<?php echo esc_attr($lang['name']); ?>" width="22" height="16" loading="lazy">
                        <span class="name"><?php echo esc_html($lang['name']); ?></span>
                        <span class="code"><?php echo esc_html(strtoupper($lang['code'])); ?></span>
                        <span class="check"><svg width="10" height="10" viewBox="0 0 10 10" fill="none"><path d="M1.5 5l2.5 2.5 4.5-5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span>
                    </button>
                    <?php endforeach; ?>
                    </div>
                    <?php if (!empty($more_languages)): ?>
                    <details class="aitrfoel-wiz-more-langs" style="margin-top:12px;">
                        <summary style="cursor:pointer;font-weight:600;font-size:13px;color:#1447e6;padding:6px 0;"><?php
                            /* translators: %d: number of additional popular languages */
                            echo esc_html(sprintf(__('More languages (%d)', 'ai-translator-for-elementor-and-polylang'), count($more_languages)));
                        ?></summary>
                        <div class="lang-grid" style="margin-top:10px;">
                        <?php foreach ($more_languages as $lang): ?>
                    <button type="button" class="lang-btn" data-code="<?php echo esc_attr($lang['code']); ?>" data-name="<?php echo esc_attr($lang['name']); ?>"<?php echo !empty($lang['reason']) ? ' title="' . esc_attr($lang['reason']) . '"' : ''; ?>>
                        <img class="flag" src="<?php echo esc_url(class_exists('AITRFOEL_Language_Mapper') ? AITRFOEL_Language_Mapper::get_flag_url_for_country($lang['flag'], 40) : 'https://flagcdn.com/w40/' . $lang['flag'] . '.png'); ?>"
                             alt="<?php echo esc_attr($lang['name']); ?>" width="22" height="16" loading="lazy">
                        <span class="name"><?php echo esc_html($lang['name']); ?></span>
                        <span class="code"><?php echo esc_html(strtoupper($lang['code'])); ?></span>
                        <span class="check"><svg width="10" height="10" viewBox="0 0 10 10" fill="none"><path d="M1.5 5l2.5 2.5 4.5-5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span>
                    </button>
                        <?php endforeach; ?>
                        </div>
                    </details>
                    <?php endif; ?>
                </div>

                <!-- Search Other Languages -->
                <div class="field-label"><?php esc_html_e('Add other languages', 'ai-translator-for-elementor-and-polylang'); ?><span class="field-mono">// search</span></div>
                <div class="search-wrap">
                    <span class="search-icon"><svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="7" cy="7" r="5" stroke="currentColor" stroke-width="1.5"/><path d="M11 11l3.5 3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span>
                    <input type="text" id="aitrfoel-wiz-search" placeholder="<?php esc_attr_e('Search for a language...', 'ai-translator-for-elementor-and-polylang'); ?>">
                </div>
                <div class="aitrfoel-wiz-search-results" id="aitrfoel-wiz-search-results"></div>

                <!-- Visitor switcher + publishing behaviour -->
                <div class="optin" style="margin-top:24px;">
                    <div class="aitrfoel-wiz-optin-toggle">
                        <input type="checkbox" id="aitrfoel-wiz-switcher" checked>
                        <label class="toggle-slider" for="aitrfoel-wiz-switcher"></label>
                    </div>
                    <div class="aitrfoel-wiz-optin-text">
                        <h4><?php esc_html_e('Add a language switcher to my main menu', 'ai-translator-for-elementor-and-polylang'); ?></h4>
                        <p><?php esc_html_e('Visitors get a language dropdown in your header menu. You can change its style or position later in Settings.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                    </div>
                </div>
                <div class="optin" style="margin-top:12px;">
                    <div class="aitrfoel-wiz-optin-toggle">
                        <input type="checkbox" id="aitrfoel-wiz-publish" checked>
                        <label class="toggle-slider" for="aitrfoel-wiz-publish"></label>
                    </div>
                    <div class="aitrfoel-wiz-optin-text">
                        <h4><?php esc_html_e('Publish translations immediately', 'ai-translator-for-elementor-and-polylang'); ?></h4>
                        <p><?php esc_html_e('Turn off to save translated pages as drafts so you can review them before they go live.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                    </div>
                </div>

                <?php endif; // end not Polylang/WPML active ?>

                <div class="wiz-nav" style="margin-top: 28px; display: flex; justify-content: space-between;">
                    <button type="button" class="btn btn-outline" id="aitrfoel-wiz-back-to-step1">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <?php esc_html_e('Back', 'ai-translator-for-elementor-and-polylang'); ?>
                    </button>
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

            </div><!-- /panel 2 -->

            <!-- ═══ STEP 3: Setup (AJAX fires on enter) ═══ -->
            <div class="wiz-panel" data-panel="3">
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
                    <div class="eyebrow" style="justify-content:center;"><?php esc_html_e('step.03 - installing', 'ai-translator-for-elementor-and-polylang'); ?></div>
                    <h2 class="install-title" id="aitrfoel-wiz-install-title"><?php esc_html_e('Configuring your site', 'ai-translator-for-elementor-and-polylang'); ?></h2>
                    <p class="install-status" id="aitrfoel-wiz-install-status">
                        <?php esc_html_e('Setting up multilingual support — this takes just a moment.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                    <div class="install-checklist" id="aitrfoel-wiz-install-checklist">
                        <div class="task complete"><span class="task-icon">✓</span><span class="task-name"><?php esc_html_e('Language management system initialized', 'ai-translator-for-elementor-and-polylang'); ?></span><span class="task-meta">0.4s</span></div>
                        <div class="task in-progress"><span class="task-icon"></span><span class="task-name"><?php esc_html_e('Applying language settings & taxonomies', 'ai-translator-for-elementor-and-polylang'); ?></span><span class="task-meta"><?php esc_html_e('in progress', 'ai-translator-for-elementor-and-polylang'); ?></span></div>
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
            </div><!-- /panel 3 -->

            <!-- ═══ STEP 4: Ready ═══ -->
            <div class="wiz-panel" data-panel="4">
                <div class="done" style="text-align:center;">
                    <div class="success-wrap">
                        <div class="success-ring"></div>
                        <div class="success-inner">
                            <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5L20 7"/></svg>
                        </div>
                    </div>
                    <div class="eyebrow" style="justify-content:center;"><?php esc_html_e('step.04 - ready', 'ai-translator-for-elementor-and-polylang'); ?></div>
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
            </div><!-- /panel 4 -->

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
            window.aitrfoelFlagUrl = window.aitrfoelFlagUrl || function (fc) { return '<?php echo esc_js(AITRFOEL_PLUGIN_URL . 'assets/img/flags/'); ?>' + fc + '.svg'; };
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
                // Auto-fire AJAX when entering step 3 (Setup)
                if (step === 3 && !setupDone) {
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

            var toStep2Btn = document.getElementById('aitrfoel-wiz-to-step2');
            if (toStep2Btn) {
                toStep2Btn.addEventListener('click', function() {
                    goToStep(2);
                });
            }

            var backToStep1Btn = document.getElementById('aitrfoel-wiz-back-to-step1');
            if (backToStep1Btn) {
                backToStep1Btn.addEventListener('click', function() {
                    goToStep(1);
                });
            }

            /* ── Step 2: Language selection ── */
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
                            '<img src="' + aitrfoelFlagUrl(fc) + '" onerror="this.onerror=null;this.src=\'https://flagcdn.com/w40/' + fc + '.png\'" width="22" height="16" alt="" style="border-radius:2px;">' +
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
                    chip.innerHTML = '<img class="chip-flag" src="' + aitrfoelFlagUrl(fc) + '" onerror="this.onerror=null;this.src=\'https://flagcdn.com/w40/' + fc + '.png\'" alt="" width="18" height="13">' +
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
                    var popBtn = langGrid ? langGrid.querySelector('.lang-btn[data-code="' + lang.code + '"]') : null;
                    if (popBtn) popBtn.classList.add('selected');
                });
                renderChips();
                updateStartBtn();
            }

            /* ── Step 2 → Step 3 ────────────────────────── */
            if (startBtn) {
                startBtn.addEventListener('click', function() {
                    if (!polylangActive && !wpmlActive) {
                        if (Object.keys(selectedLangs).length === 0) return;
                    }
                    goToStep(3);
                });
            }

            /* ── Step 3: Real AJAX setup ──────────────────── */
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
                    var swEl = document.getElementById('aitrfoel-wiz-switcher');
                    var pubEl = document.getElementById('aitrfoel-wiz-publish');
                    formData.append('add_switcher', swEl && swEl.checked ? '1' : '0');
                    formData.append('publish_translations', !pubEl || pubEl.checked ? '1' : '0');
                }

                // Give up after 90 s so the user isn't left on "in progress" forever.
                var setupAbort = window.AbortController ? new AbortController() : null;
                var setupTimer = setupAbort ? setTimeout(function () { setupAbort.abort(); }, 90000) : null;
                fetch(ajaxurl, { method: 'POST', body: formData, signal: setupAbort ? setupAbort.signal : undefined })
                    .then(function(r) {
                        if (setupTimer) clearTimeout(setupTimer);
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
                            // Build language summary for step 4
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
                                        summaryEl.innerHTML += '<span class="chip"><img class="chip-flag" src="' + aitrfoelFlagUrl(fc) + '" onerror="this.onerror=null;this.src=\'https://flagcdn.com/w40/' + fc + '.png\'" width="18" height="13" alt=""> ' + selectedLangs[code] + ' <span class="chip-code">' + code.toUpperCase() + '</span></span>';
                                    });
                                }
                            } else {
                                // Hide summary box for Polylang/WPML — they manage their own languages
                                var summaryBox = document.getElementById('aitrfoel-wiz-summary-box');
                                if (summaryBox) summaryBox.style.display = 'none';
                            }
                            // Show success state briefly then advance to step 4
                            document.getElementById('aitrfoel-wiz-spinner').style.display = 'none';
                            document.getElementById('aitrfoel-wiz-install-done').style.display = 'block';
                            document.getElementById('aitrfoel-wiz-install-title').textContent = <?php echo wp_json_encode(__('All Set!', 'ai-translator-for-elementor-and-polylang')); ?>;
                            document.getElementById('aitrfoel-wiz-install-status').textContent = <?php echo wp_json_encode(__('Moving to next step...', 'ai-translator-for-elementor-and-polylang')); ?>;
                            setTimeout(function() { goToStep(4); }, 600);
                        } else {
                            showSetupError(res.data || <?php echo wp_json_encode(__('Setup failed. Please try again.', 'ai-translator-for-elementor-and-polylang')); ?>);
                        }
                    })
                    .catch(function(err) {
                        if (setupTimer) clearTimeout(setupTimer);
                        if (err && err.name === 'AbortError') {
                            err = new Error(<?php echo wp_json_encode(__('Setup is taking longer than expected. Your server may be slow; please try again.', 'ai-translator-for-elementor-and-polylang')); ?>);
                        }
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
                // Reset step 3 UI so it's fresh if user goes back and tries again
                document.getElementById('aitrfoel-wiz-spinner').style.display = 'block';
                document.getElementById('aitrfoel-wiz-install-done').style.display = 'none';
                document.getElementById('aitrfoel-wiz-install-title').textContent = <?php echo wp_json_encode(__('Configuring your site', 'ai-translator-for-elementor-and-polylang')); ?>;
                document.getElementById('aitrfoel-wiz-install-status').textContent = <?php echo wp_json_encode(__('Setting up multilingual support — this takes just a moment.', 'ai-translator-for-elementor-and-polylang')); ?>;
                document.getElementById('aitrfoel-wiz-install-status').style.display = 'block';
                var errEl = document.getElementById('aitrfoel-wiz-install-error');
                if (errEl) { errEl.style.display = 'none'; errEl.textContent = ''; }
                goToStep(2);
            });

            document.getElementById('aitrfoel-wiz-back-3').addEventListener('click', function() {
                goToStep(2);
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
                    // Land on the dashboard with the homepage + chosen languages ticked, so the
                    // first translation is one click away.
                    var firstId = demoPostId || (typeof wizDemoPostId !== 'undefined' ? wizDemoPostId : 0);
                    var langs = Object.keys(selectedLangs || {});
                    if (firstId > 0 && langs.length) {
                        var url = new URL(window.location.href);
                        url.searchParams.delete('welcome');
                        url.searchParams.set('aitrfoel_first', String(firstId));
                        url.searchParams.set('aitrfoel_langs', langs.join(','));
                        window.location.href = url.toString();
                    } else {
                        window.location.reload();
                    }
                }

                if (demoPostId > 0 && demoWordCount <= 500 && demoLang) {
                    // The demo translation takes 10-40 s; say so on the button itself (the
                    // checklist row isn't visible on the Ready step).
                    finishBtn.innerHTML = '<span class="spinner is-active" style="float:none;margin:0 8px 0 0;filter:invert(1);"></span>' + <?php echo wp_json_encode(__('Translating your homepage…', 'ai-translator-for-elementor-and-polylang')); ?>;
                    finishBtn.style.opacity = '1';
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
                $code = strtolower(sanitize_text_field($_POST['language_code'] ?? ''));
                $name = sanitize_text_field($_POST['language_name'] ?? '');
                if (empty($code) || empty($name)) {
                    wp_send_json_error(__('Language code and name are required.', 'ai-translator-for-elementor-and-polylang'));
                }

                $multilingual = class_exists('AITRFOEL_Multilingual_Manager') ? AITRFOEL_Multilingual_Manager::get_instance() : null;
                $detected_plugin = ($multilingual && method_exists($multilingual, 'get_detected_plugin')) ? $multilingual->get_detected_plugin() : '';

                // If Polylang is active, add language directly into Polylang
                if ($detected_plugin === 'polylang' && function_exists('PLL') && is_object(PLL()) && isset(PLL()->model)) {
                    $pll_locales = [
                        'en' => ['en_US', 'us', 0, 'English'],
                        'es' => ['es_ES', 'es', 0, 'Español'],
                        'fr' => ['fr_FR', 'fr', 0, 'Français'],
                        'de' => ['de_DE', 'de', 0, 'Deutsch'],
                        'it' => ['it_IT', 'it', 0, 'Italiano'],
                        'nl' => ['nl_NL', 'nl', 0, 'Nederlands'],
                        'pl' => ['pl_PL', 'pl', 0, 'Polski'],
                        'pt' => ['pt_PT', 'pt', 0, 'Português'],
                        'ru' => ['ru_RU', 'ru', 0, 'Русский'],
                        'uk' => ['uk',    'ua', 0, 'Українська'],
                        'ar' => ['ar',    'sa', 1, 'العربية'],
                        'zh' => ['zh_CN', 'cn', 0, '中文'],
                        'ja' => ['ja',    'jp', 0, '日本語'],
                        'ro' => ['ro_RO', 'ro', 0, 'Română'],
                        'cs' => ['cs_CZ', 'cz', 0, 'Čeština'],
                        'sv' => ['sv_SE', 'se', 0, 'Svenska'],
                        'da' => ['da_DK', 'dk', 0, 'Dansk'],
                        'fi' => ['fi',    'fi', 0, 'Suomi'],
                        'no' => ['nb_NO', 'no', 0, 'Norsk'],
                        'tr' => ['tr_TR', 'tr', 0, 'Türkçe'],
                        'el' => ['el',    'gr', 0, 'Ελληνικά'],
                        'hu' => ['hu_HU', 'hu', 0, 'Magyar'],
                        'hr' => ['hr',    'hr', 0, 'Hrvatski'],
                        'bg' => ['bg_BG', 'bg', 0, 'Български'],
                        'sk' => ['sk_SK', 'sk', 0, 'Slovenčina'],
                        'sl' => ['sl_SI', 'si', 0, 'Slovenščina'],
                        'he' => ['he_IL', 'il', 1, 'עברית'],
                        'hi' => ['hi_IN', 'in', 0, 'हिन्दी'],
                        'id' => ['id_ID', 'id', 0, 'Bahasa Indonesia'],
                        'ko' => ['ko_KR', 'kr', 0, '한국어'],
                        'vi' => ['vi',    'vn', 0, 'Tiếng Việt'],
                        'th' => ['th',    'th', 0, 'ไทย'],
                    ];

                    $existing_pll = PLL()->model->get_languages_list();
                    if (empty($existing_pll)) {
                        $wp_locale = get_locale();
                        $wp_short  = strtolower(substr($wp_locale, 0, 2)) ?: 'en';
                        if ($wp_short === $code) {
                            $wp_short = ($code === 'en') ? 'es' : 'en';
                        }
                        $def_info = $pll_locales[$wp_short] ?? [$wp_locale ?: 'en_US', $wp_short, 0, strtoupper($wp_short)];
                        PLL()->model->add_language([
                            'name'       => $def_info[3],
                            'slug'       => $wp_short,
                            'locale'     => $def_info[0],
                            'rtl'        => $def_info[2],
                            'term_group' => 0,
                            'flag'       => $def_info[1],
                        ]);
                    }

                    $target_info = $pll_locales[$code] ?? [$code . '_' . strtoupper($code), $code, 0, $name];
                    $pll_res = PLL()->model->add_language([
                        'name'       => $name,
                        'slug'       => $code,
                        'locale'     => $target_info[0],
                        'rtl'        => $target_info[2],
                        'term_group' => 1,
                        'flag'       => $target_info[1],
                    ]);

                    if (!is_wp_error($pll_res) && $pll_res) {
                        delete_option('aitrfoel_dependencies_status');
                        flush_rewrite_rules();
                        wp_send_json_success(['message' => __('Language added.', 'ai-translator-for-elementor-and-polylang')]);
                    }
                }

                // Built-in language system path
                if (!$builtin->is_enabled()) {
                    $builtin->enable();
                    $builtin->create_table();
                }
                $existing_builtin = $builtin->get_available_languages();
                if (empty($existing_builtin)) {
                    $wp_locale = get_locale();
                    $wp_short  = strtolower(substr($wp_locale, 0, 2)) ?: 'en';
                    $lang_names = [
                        'en' => 'English', 'es' => 'Español', 'fr' => 'Français', 'de' => 'Deutsch',
                        'it' => 'Italiano', 'nl' => 'Nederlands', 'pl' => 'Polski', 'pt' => 'Português',
                        'ru' => 'Русский', 'uk' => 'Українська',
                    ];
                    if ($wp_short !== $code) {
                        $def_name = $lang_names[$wp_short] ?? strtoupper($wp_short);
                        $builtin->add_language($wp_short, $def_name, $def_name, true);
                    }
                }

                $result = $builtin->add_language($code, $name, $name, false, true); // packs install in the background
                if ($result) {
                    delete_option('aitrfoel_dependencies_status');
                    delete_option('aitrfoel_rewrite_rules_version');
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
        check_ajax_referer('aitrfoel_builtin_setup', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.');
        }
        $optin = sanitize_text_field(wp_unslash($_POST['optin'] ?? '0'));
        // Must match the option read by AITRFOEL_Analytics::is_enabled().
        update_option('aitrfoel_analytics_enabled', $optin === '1');
        wp_send_json_success(['message' => 'Preference saved.']);
    }

    /**
     * Render a 1-click "Pick the language you want to translate your site into" card
     * when 0 target languages are configured (works on both Dashboard and Translate All).
     */
    public function render_quick_add_language_card($multilingual = null) {
        if (!$multilingual && class_exists('AITRFOEL_Multilingual_Manager')) {
            $multilingual = AITRFOEL_Multilingual_Manager::get_instance();
        }
        $existing_langs = ($multilingual && method_exists($multilingual, 'get_available_languages')) ? $multilingual->get_available_languages() : [];
        $default_lang   = ($multilingual && method_exists($multilingual, 'get_default_language')) ? $multilingual->get_default_language() : strtolower(substr(get_locale(), 0, 2));
        if (empty($default_lang)) {
            $default_lang = 'en';
        }

        $popular_langs = [
            'es' => ['name' => 'Spanish (Español)',     'short' => 'ES'],
            'fr' => ['name' => 'French (Français)',     'short' => 'FR'],
            'de' => ['name' => 'German (Deutsch)',      'short' => 'DE'],
            'it' => ['name' => 'Italian (Italiano)',    'short' => 'IT'],
            'nl' => ['name' => 'Dutch (Nederlands)',    'short' => 'NL'],
            'pl' => ['name' => 'Polish (Polski)',       'short' => 'PL'],
            'pt' => ['name' => 'Portuguese (Português)','short' => 'PT'],
            'uk' => ['name' => 'Ukrainian (Українська)','short' => 'UK'],
            'ru' => ['name' => 'Russian (Русский)',     'short' => 'RU'],
            'ar' => ['name' => 'Arabic (العربية)',      'short' => 'AR'],
            'zh' => ['name' => 'Chinese (中文)',        'short' => 'ZH'],
            'ja' => ['name' => 'Japanese (日本語)',      'short' => 'JA'],
            'en' => ['name' => 'English',               'short' => 'EN'],
        ];

        $more_langs = [
            'ro' => 'Romanian (Română)',
            'cs' => 'Czech (Čeština)',
            'sv' => 'Swedish (Svenska)',
            'da' => 'Danish (Dansk)',
            'fi' => 'Finnish (Suomi)',
            'no' => 'Norwegian (Norsk)',
            'tr' => 'Turkish (Türkçe)',
            'el' => 'Greek (Ελληνικά)',
            'hu' => 'Hungarian (Magyar)',
            'hr' => 'Croatian (Hrvatski)',
            'bg' => 'Bulgarian (Български)',
            'sk' => 'Slovak (Slovenčina)',
            'sl' => 'Slovenian (Slovenščina)',
            'he' => 'Hebrew (עברית)',
            'hi' => 'Hindi (हिन्दी)',
            'id' => 'Indonesian (Bahasa Indonesia)',
            'ko' => 'Korean (한국어)',
            'vi' => 'Vietnamese (Tiếng Việt)',
            'th' => 'Thai (ไทย)',
        ];

        $ajax_nonce = wp_create_nonce('aitrfoel_ajax_nonce');
        ?>
        <div class="aitrfoel-quick-lang-card" style="background: #ffffff; border: 2px solid #bfdbfe; border-radius: 14px; padding: 24px 28px; margin: 16px 0; box-shadow: 0 8px 24px rgba(20, 71, 230, 0.06);">
            <div style="display: flex; align-items: flex-start; gap: 14px; margin-bottom: 16px;">
                <div style="width: 42px; height: 42px; border-radius: 10px; background: #eff6ff; color: #1d4ed8; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                </div>
                <div>
                    <h3 style="margin: 0 0 4px; font-size: 17px; font-weight: 700; color: #0f172a;">
                        <?php esc_html_e('Pick the language you want to translate your site into', 'ai-translator-for-elementor-and-polylang'); ?>
                    </h3>
                    <p style="margin: 0; font-size: 13.5px; color: #475569; line-height: 1.5;">
                        <?php
                        printf(
                            /* translators: %s: default source language code */
                            esc_html__('Your source language is %s. Click any target language below to add it in 1 click and start translating immediately:', 'ai-translator-for-elementor-and-polylang'),
                            '<strong>' . esc_html(strtoupper($default_lang)) . '</strong>'
                        );
                        ?>
                    </p>
                </div>
            </div>

            <div class="aitrfoel-quick-lang-pills" style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px;">
                <?php foreach ($popular_langs as $code => $info) :
                    if ($code === $default_lang || isset($existing_langs[$code])) continue;
                ?>
                    <button type="button"
                            class="button aitrfoel-quick-add-lang-btn"
                            data-code="<?php echo esc_attr($code); ?>"
                            data-name="<?php echo esc_attr(explode(' (', $info['name'])[0]); ?>"
                            style="display: inline-flex; align-items: center; gap: 7px; padding: 6px 14px; border-radius: 8px; border: 1px solid #cbd5e1; background: #f8fafc; color: #0f172a; font-weight: 600; font-size: 13px; cursor: pointer; transition: all 0.15s ease;">
                        <span style="display: inline-block; padding: 1px 6px; border-radius: 4px; background: #dbeafe; color: #1e40af; font-size: 11px; font-weight: 700;"><?php echo esc_html($info['short']); ?></span>
                        <span><?php echo esc_html($info['name']); ?></span>
                        <span style="color: #2563eb; font-weight: 700;">+</span>
                    </button>
                <?php endforeach; ?>
            </div>

            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding-top: 12px; border-top: 1px solid #f1f5f9;">
                <span style="font-size: 12.5px; color: #64748b; font-weight: 600;"><?php esc_html_e('Or choose another language:', 'ai-translator-for-elementor-and-polylang'); ?></span>
                <select id="aitrfoel-quick-lang-select" style="min-width: 210px; font-size: 13px;">
                    <option value=""><?php esc_html_e('— Select language —', 'ai-translator-for-elementor-and-polylang'); ?></option>
                    <?php foreach ($more_langs as $code => $label) :
                        if ($code === $default_lang || isset($existing_langs[$code])) continue;
                    ?>
                        <option value="<?php echo esc_attr($code); ?>" data-name="<?php echo esc_attr(explode(' (', $label)[0]); ?>"><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="button button-primary" id="aitrfoel-quick-lang-select-btn">
                    <?php esc_html_e('Add Target Language →', 'ai-translator-for-elementor-and-polylang'); ?>
                </button>
                <span id="aitrfoel-quick-lang-status" style="font-size: 12.5px; font-weight: 600; color: #2563eb; margin-left: 6px;"></span>
            </div>
        </div>
        <script>
        (function() {
            var nonce = <?php echo wp_json_encode($ajax_nonce); ?>;
            var statusEl = document.getElementById('aitrfoel-quick-lang-status');

            function addLang(code, name, btnEl) {
                if (!code || !name) return;
                if (btnEl) btnEl.disabled = true;
                if (statusEl) statusEl.textContent = <?php echo wp_json_encode(__('Adding language…', 'ai-translator-for-elementor-and-polylang')); ?>;

                var fd = new FormData();
                fd.append('action', 'aitrfoel_manage_builtin_language');
                fd.append('nonce', nonce);
                fd.append('operation', 'add');
                fd.append('language_code', code);
                fd.append('language_name', name);

                fetch(ajaxurl, { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res && res.success) {
                            if (statusEl) {
                                statusEl.style.color = '#059669';
                                statusEl.textContent = '✓ ' + name + ' added! Reloading…';
                            }
                            window.location.reload();
                        } else {
                            if (btnEl) btnEl.disabled = false;
                            if (statusEl) {
                                statusEl.style.color = '#dc2626';
                                statusEl.textContent = (res && res.data) ? res.data : 'Could not add language.';
                            }
                        }
                    })
                    .catch(function() {
                        if (btnEl) btnEl.disabled = false;
                    });
            }

            document.querySelectorAll('.aitrfoel-quick-add-lang-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    addLang(btn.getAttribute('data-code'), btn.getAttribute('data-name'), btn);
                });
            });

            var selBtn = document.getElementById('aitrfoel-quick-lang-select-btn');
            var selEl  = document.getElementById('aitrfoel-quick-lang-select');
            if (selBtn && selEl) {
                selBtn.addEventListener('click', function() {
                    var opt = selEl.options[selEl.selectedIndex];
                    if (!opt || !opt.value) return;
                    addLang(opt.value, opt.getAttribute('data-name') || opt.text, selBtn);
                });
            }
        })();
        </script>
        <?php
    }

    /**
     * Render Dashboard page for bulk translations
     */
    public function render_dashboard_page() {
        // Clear cached license status on activation or sync to ensure fresh plan info renders immediately
        if (!empty($_GET['activated']) || !empty($_GET['sync']) || (!empty($_GET['fs_action']) && strpos((string)$_GET['fs_action'], 'sync') !== false)) {
            $license_mgr = class_exists('AITRFOEL_License_Manager_Server') ? AITRFOEL_License_Manager_Server::get_instance() : null;
            if ($license_mgr && method_exists($license_mgr, 'clear_status_cache')) {
                $license_mgr->clear_status_cache();
            }
        }

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
            ?>
            <div class="wrap aitrfoel-dashboard-wrapper aitrfoel-tree-v2">
                <?php $this->render_plugin_header(__('Dashboard', 'ai-translator-for-elementor-and-polylang')); ?>
                <?php $this->render_quick_add_language_card($multilingual); ?>
            </div>
            <?php
            return;
        }

        // Get sort setting (default: date_desc - latest first)
        $current_sort = isset($_GET['sort']) ? sanitize_key($_GET['sort']) : 'date_desc';
        if (!in_array($current_sort, ['date_desc', 'date_asc', 'title_asc', 'title_desc'], true)) {
            $current_sort = 'date_desc';
        }

        // Get pages with translation groups
        $pages_data = $this->get_pages_with_translations($current_sort);

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
            <?php $this->render_plugin_header(__('Dashboard', 'ai-translator-for-elementor-and-polylang')); ?>
            <?php $this->render_compact_stats_bar($pages_data); ?>

            <?php
            // Capacity / Upgrade Prompt Banner & Limit Hero Card
            $license_mgr   = AITRFOEL_License_Manager_Server::get_instance();
            $lic_status    = $license_mgr->get_license_status();
            $u_lic_info    = $this->get_license_info();
            $u_is_pro      = !empty($u_lic_info['is_pro']) && empty($u_lic_info['license_expired']);
            $u_used        = isset($lic_status['words_used']) ? (int) $lic_status['words_used'] : (int) ($lic_status['words_translated'] ?? 0);
            $u_limit       = isset($lic_status['word_limit']) ? (int) $lic_status['word_limit'] : (int) ($lic_status['total_words'] ?? 7200);
            $u_unlimited   = ($u_limit === -1 || !empty($lic_status['unlimited']) || !empty($lic_status['is_unlimited']) || strtolower($lic_status['plan'] ?? '') === 'agency lifetime');
            $u_pct         = ($u_limit > 0 && !$u_unlimited) ? min(100, round(($u_used / $u_limit) * 100)) : 0;
            $is_cooldown   = !$u_unlimited && (!empty($lic_status['cooldown_active']) || ($u_pct >= 100));
            $bonus_claimed = (bool) get_option('aitrfoel_share_bonus_claimed', false);
            $pricing_url   = aitrfoel_free_upgrade_url('admin-dashboard');
            $upg_dismissed = (bool) get_option('aitrfoel_upgrade_banner_dismissed', false);
            ?>

            <?php if (!$u_unlimited && $is_cooldown): ?>
            <!-- Limit-Reached High-Converting Hero Card -->
            <div id="aitrfoel-limit-hero-card" class="aitrfoel-limit-hero-card">
                <div class="aitrfoel-limit-hero-head">
                    <div style="flex: 1; min-width: 280px;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
                            <span style="display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 999px; background: #fef3c7; color: #d97706;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            </span>
                            <h2 class="aitrfoel-limit-hero-title"><?php printf(/* translators: %s: monthly word limit */ esc_html__('Monthly Word Limit Reached (%s Words Used)', 'ai-translator-for-elementor-and-polylang'), esc_html(number_format_i18n($u_limit))); ?></h2>
                        </div>
                        <p class="aitrfoel-limit-hero-desc">
                            <?php if ($u_is_pro): ?>
                                <?php printf(/* translators: %s: monthly word limit */ esc_html__('You have used all %s words included in your current monthly cycle. Add a 50K Words top-up pack or upgrade your plan tier to continue translating immediately.', 'ai-translator-for-elementor-and-polylang'), esc_html(number_format_i18n($u_limit))); ?>
                            <?php else: ?>
                                <?php printf(/* translators: %s: monthly word limit */ esc_html__('You have translated all %s monthly words. Upgrade to PRO to unlock Unlimited Words, Claude & DeepL engines, Brand Voice presets, Formality controls, and Priority AI processing.', 'ai-translator-for-elementor-and-polylang'), esc_html(number_format_i18n($u_limit))); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="aitrfoel-limit-hero-actions">
                        <a href="<?php echo esc_url($pricing_url); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary aitrfoel-upgrade-btn" style="padding: 6px 18px; font-weight: 700; background: #0284c7; border-color: #0284c7; display: inline-flex; align-items: center; gap: 6px;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                            <span><?php echo $u_is_pro ? esc_html__('Upgrade Plan Tier', 'ai-translator-for-elementor-and-polylang') : esc_html__('Upgrade to Starter ($9.99/mo · 20% OFF: MEEP20)', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </a>
                        <?php if ($u_is_pro): ?>
                        <a href="<?php echo esc_url($pricing_url); ?>" target="_blank" rel="noopener noreferrer" class="button button-secondary" style="padding: 6px 14px;">
                            <?php esc_html_e('Get Extra 50K Words Pack ($4.99)', 'ai-translator-for-elementor-and-polylang'); ?>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!$u_is_pro): ?>
                <!-- Viral Social Share Bonus Words (100% WordPress.org Compliant) -->
                <div id="bonus-words" class="aitrfoel-share-bonus-box">
                    <div style="flex: 1; min-width: 260px;">
                        <strong style="display: flex; align-items: center; gap: 6px; font-size: 13.5px; color: #0f172a; margin-bottom: 2px;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>
                            <span><?php esc_html_e('Need a quick top-up? Claim +2,500 Free Bonus Words', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </strong>
                        <p style="margin: 0; font-size: 12px; color: #475569; line-height: 1.4;">
                            <?php esc_html_e('Share Meep AI Translator on X (Twitter) or LinkedIn to immediately unlock +2,500 bonus words for your website.', 'ai-translator-for-elementor-and-polylang'); ?>
                        </p>
                    </div>
                    <div class="aitrfoel-share-buttons" style="display: flex; align-items: center; gap: 8px;">
                        <?php if ($bonus_claimed): ?>
                            <span style="display: inline-flex; align-items: center; gap: 6px; background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 700;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                                <?php esc_html_e('+2,500 Bonus Words Claimed', 'ai-translator-for-elementor-and-polylang'); ?>
                            </span>
                        <?php else: ?>
                            <button type="button" class="aitrfoel-share-btn aitrfoel-share-btn-x" data-platform="x">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 24.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                                <span><?php esc_html_e('Share on X', 'ai-translator-for-elementor-and-polylang'); ?></span>
                            </button>
                            <button type="button" class="aitrfoel-share-btn aitrfoel-share-btn-linkedin" data-platform="linkedin">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><path d="M19 3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h14m-.5 15.5v-5.3a3.26 3.26 0 0 0-3.26-3.26c-.85 0-1.84.52-2.28 1.3v-1.11h-2.79v8.37h2.79v-4.93c0-.77.62-1.4 1.39-1.4a1.4 1.4 0 0 1 1.4 1.4v4.93h2.75M6.88 8.56a1.68 1.68 0 0 0 1.68-1.68c0-.93-.75-1.69-1.68-1.69a1.69 1.69 0 0 0-1.69 1.69c0 .93.76 1.68 1.69 1.68m1.39 9.94v-8.37H5.5v8.37h2.77z"/></svg>
                                <span><?php esc_html_e('Share on LinkedIn', 'ai-translator-for-elementor-and-polylang'); ?></span>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php elseif (!$u_unlimited && $u_pct >= ($u_is_pro ? 80 : 50) && !$upg_dismissed): ?>
            <div id="aitrfoel-capacity-upgrade-banner" class="aitrfoel-upgrade-banner aitrfoel-upgrade-warning">
                <div class="aitrfoel-upgrade-banner-content">
                    <div class="aitrfoel-upgrade-banner-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></div>
                    <div class="aitrfoel-upgrade-banner-text">
                        <strong class="aitrfoel-upgrade-banner-title">
                            <?php if ($u_is_pro): ?>
                                <?php printf(/* translators: %1$d: percentage of quota used, %2$s: words used, %3$s: monthly word limit */ esc_html__('You\'ve used %1$d%% of your monthly word quota (%2$s / %3$s words)', 'ai-translator-for-elementor-and-polylang'), (int) $u_pct, esc_html(number_format_i18n($u_used)), esc_html(number_format_i18n($u_limit))); ?>
                            <?php else: ?>
                                <?php printf(/* translators: %1$d: percentage of quota used, %2$s: words used, %3$s: monthly word limit */ esc_html__('You\'ve used %1$d%% of your free monthly word quota (%2$s / %3$s words)', 'ai-translator-for-elementor-and-polylang'), (int) $u_pct, esc_html(number_format_i18n($u_used)), esc_html(number_format_i18n($u_limit))); ?>
                            <?php endif; ?>
                        </strong>
                        <p class="aitrfoel-upgrade-banner-desc">
                            <?php if ($u_is_pro): ?>
                                <?php esc_html_e('Running low on monthly words? Add an Extra 50K Words top-up pack ($4.99) or upgrade your plan tier so scheduled and bulk translations continue uninterrupted.', 'ai-translator-for-elementor-and-polylang'); ?>
                            <?php else: ?>
                                <?php esc_html_e('Upgrade to Starter ($9.99/mo — use code MEEP20 for 20% OFF) to unlock 50,000+ monthly words, Anthropic Claude & DeepL Pro engines, bulk site translation, and auto-sync.', 'ai-translator-for-elementor-and-polylang'); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="aitrfoel-upgrade-banner-actions">
                        <a href="<?php echo esc_url($pricing_url); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary aitrfoel-upgrade-btn" style="display:inline-flex;align-items:center;gap:6px;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                            <span><?php echo $u_is_pro ? esc_html__('Get More Words', 'ai-translator-for-elementor-and-polylang') : esc_html__('Upgrade to Pro (20% OFF)', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </a>
                        <button type="button" class="button button-link aitrfoel-dismiss-upgrade-banner" title="<?php esc_attr_e('Dismiss notice', 'ai-translator-for-elementor-and-polylang'); ?>" style="display:inline-flex;align-items:center;justify-content:center;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php // Ask for a share only after the user has seen at least one successful translation.
            if (!$u_is_pro && !$u_unlimited && !$is_cooldown && !$bonus_claimed && (int) get_option('aitrfoel_successful_translations_count', 0) > 0): ?>
            <!-- Standalone Viral Social Share Bonus Banner for Unclaimed Users -->
            <div id="bonus-words" class="aitrfoel-share-bonus-box" style="margin: 0 0 16px 0; background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); border: 1px solid #bae6fd; border-radius: 10px; padding: 12px 18px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                <div style="flex: 1; min-width: 260px;">
                    <strong style="display: flex; align-items: center; gap: 6px; font-size: 13.5px; color: #0f172a; margin-bottom: 2px;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>
                        <span><?php esc_html_e('Want +2,500 Free Bonus Words?', 'ai-translator-for-elementor-and-polylang'); ?></span>
                    </strong>
                    <p style="margin: 0; font-size: 12px; color: #334155; line-height: 1.4;">
                        <?php esc_html_e('Share Meep AI Translator on X (Twitter) or LinkedIn to immediately unlock +2,500 extra free words for your website.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                </div>
                <div class="aitrfoel-share-buttons" style="display: flex; align-items: center; gap: 8px;">
                    <button type="button" class="aitrfoel-share-btn aitrfoel-share-btn-x" data-platform="x">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 24.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                        <span><?php esc_html_e('Share on X (+2,500)', 'ai-translator-for-elementor-and-polylang'); ?></span>
                    </button>
                    <button type="button" class="aitrfoel-share-btn aitrfoel-share-btn-linkedin" data-platform="linkedin">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><path d="M19 3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h14m-.5 15.5v-5.3a3.26 3.26 0 0 0-3.26-3.26c-.85 0-1.84.52-2.28 1.3v-1.11h-2.79v8.37h2.79v-4.93c0-.77.62-1.4 1.39-1.4a1.4 1.4 0 0 1 1.4 1.4v4.93h2.75M6.88 8.56a1.68 1.68 0 0 0 1.68-1.68c0-.93-.75-1.69-1.68-1.69a1.69 1.69 0 0 0-1.69 1.69c0 .93.76 1.68 1.69 1.68m1.39 9.94v-8.37H5.5v8.37h2.77z"/></svg>
                        <span><?php esc_html_e('Share on LinkedIn (+2,500)', 'ai-translator-for-elementor-and-polylang'); ?></span>
                    </button>
                </div>
            </div>
            <?php endif; ?>

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

                <!-- Modern Dashboard Translation Progress Bar -->
                <div id="aitrfoel-dashboard-progress-wrap" class="aitrfoel-dashboard-progress-wrap" style="display:none; width:100%; box-sizing:border-box; margin-top:14px; padding:14px 18px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 1px 4px rgba(0,0,0,0.04);">
                    <div class="aitrfoel-dashboard-progress-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; flex-wrap:wrap; gap:8px;">
                        <div style="display:flex; align-items:center; gap:8px; min-width:0; flex:1;">
                            <span class="spinner is-active" style="float:none; margin:0; width:20px; height:20px; background-size:20px 20px; flex-shrink:0; visibility:visible;"></span>
                            <span id="aitrfoel-progress-step-text" style="font-weight:600; color:#1e293b; font-size:13px;">
                                <?php esc_html_e('AI is preparing your translations…', 'ai-translator-for-elementor-and-polylang'); ?>
                            </span>
                            <span id="aitrfoel-progress-subtext" style="color:#64748b; font-size:12px; font-style:italic;"></span>
                        </div>
                        <div style="display:flex; align-items:center; gap:12px; flex-shrink:0;">
                            <span id="aitrfoel-progress-timer-pill" style="display:inline-flex; align-items:center; gap:4px; font-size:12px; font-weight:600; background:#e0f2fe; color:#0369a1; padding:2px 10px; border-radius:999px;">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> <span id="aitrfoel-progress-timer-val">0:00</span>
                            </span>
                            <span id="aitrfoel-dashboard-progress-pct" style="font-weight:700; color:#2563eb; font-size:13px; min-width:35px; text-align:right;">0%</span>
                        </div>
                    </div>
                    <div class="aitrfoel-dashboard-progress-track" style="height:8px; background:#e2e8f0; border-radius:999px; overflow:hidden; position:relative;">
                        <div id="aitrfoel-dashboard-progress-bar" class="aitrfoel-dashboard-progress-bar" style="width:0%; height:100%; background:linear-gradient(90deg, #3b82f6, #6366f1, #10b981); border-radius:999px; transition:width 0.35s ease;"></div>
                    </div>
                </div>
            </div>
            
            <?php
            // Per-translation actions (delete, publish) live in each row's actions menu,
            // so the old bulk "Delete selected translations" bar has been removed.
            ?>

            <?php
            // Separate pages_data into pages, posts, products, forms, elements, and custom types
            $pages_only = [];
            $posts_only = [];
            $products_only = [];
            $forms_only = [];
            $elements_only = [];
            $custom_only = [];
            $form_types = ['wpcf7_contact_form', 'sureforms_form', 'sf_form', 'wpforms', 'jet-form-builder', 'fluentform', 'formidable', 'ninja_forms'];
            $element_types = ['elementor_library', 'elementor-hf', 'jet-theme-core', 'jet-engine'];

            foreach ($pages_data as $group) {
                $pt = $group['source']->post_type;
                // Elementor's Kit holds global colours/fonts, not translatable content; listing it
                // (often twice) only confuses users.
                if ($pt === 'elementor_library' && get_post_meta($group['source']->ID, '_elementor_template_type', true) === 'kit') {
                    continue;
                }
                if ($pt === 'page') {
                    $pages_only[] = $group;
                } elseif ($pt === 'product' || $pt === 'sc_product') {
                    $products_only[] = $group;
                } elseif ($pt === 'post') {
                    $posts_only[] = $group;
                } elseif (in_array($pt, $form_types, true)) {
                    $forms_only[] = $group;
                } elseif (in_array($pt, $element_types, true)) {
                    $elements_only[] = $group;
                } else {
                    $custom_only[] = $group;
                }
            }
            $pages_count = count($pages_only);
            $posts_count = count($posts_only);
            $products_count = count($products_only);
            $forms_count = count($forms_only);
            $elements_count = count($elements_only);
            $custom_count = count($custom_only);

            // Build visible tabs list (hide tabs with 0 items)
            $visible_tabs = [];
            if ($pages_count > 0) {
                $visible_tabs['pages'] = [
                    'label' => __('Pages', 'ai-translator-for-elementor-and-polylang'),
                    'count' => $pages_count,
                    'icon'  => '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 3H18L21 6V20C21 20.55 20.55 21 20 21H8C7.45 21 7 20.55 7 20V4C7 3.45 7.45 3 8 3Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M18 3V7H21" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 7V20C3 20.55 3.45 21 4 21H7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>'
                ];
            }
            if ($posts_count > 0) {
                $visible_tabs['posts'] = [
                    'label' => __('Posts', 'ai-translator-for-elementor-and-polylang'),
                    'count' => $posts_count,
                    'icon'  => '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6 4H18C19.1 4 20 4.9 20 6V18C20 19.1 19.1 20 18 20H6C4.9 20 4 19.1 4 18V6C4 4.9 4.9 4 6 4Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 9H16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M8 13H16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M8 17H13" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>'
                ];
            }
            if ($products_count > 0) {
                $visible_tabs['products'] = [
                    'label' => __('Products', 'ai-translator-for-elementor-and-polylang'),
                    'count' => $products_count,
                    'icon'  => '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="9" cy="20" r="1.4" fill="currentColor"/><circle cx="18" cy="20" r="1.4" fill="currentColor"/><path d="M3 4H5L7.3 15.2C7.4 15.7 7.9 16 8.4 16H18.5C19 16 19.4 15.7 19.6 15.3L22 8H6.2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>'
                ];
            }
            if ($forms_count > 0) {
                $visible_tabs['forms'] = [
                    'label' => __('Forms', 'ai-translator-for-elementor-and-polylang'),
                    'count' => $forms_count,
                    'icon'  => '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="4" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M7 8h10M7 12h10M7 16h6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>'
                ];
            }
            if ($elements_count > 0) {
                $visible_tabs['elements'] = [
                    'label' => __('Elements & Templates', 'ai-translator-for-elementor-and-polylang'),
                    'count' => $elements_count,
                    'icon'  => '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="3" width="8" height="8" rx="2" stroke="currentColor" stroke-width="1.8"/><rect x="13" y="3" width="8" height="8" rx="2" stroke="currentColor" stroke-width="1.8"/><rect x="3" y="13" width="8" height="8" rx="2" stroke="currentColor" stroke-width="1.8"/><rect x="13" y="13" width="8" height="8" rx="2" stroke="currentColor" stroke-width="1.8"/></svg>'
                ];
            }
            if ($custom_count > 0) {
                $visible_tabs['custom'] = [
                    'label' => __('Custom Post Types', 'ai-translator-for-elementor-and-polylang'),
                    'count' => $custom_count,
                    'icon'  => '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="4" y="4" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.8"/><rect x="13" y="4" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.8"/><rect x="4" y="13" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.8"/><rect x="13" y="13" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.8"/></svg>'
                ];
            }

            // Fallback: if everything is 0, show Pages tab as empty default
            if (empty($visible_tabs)) {
                $visible_tabs['pages'] = [
                    'label' => __('Pages', 'ai-translator-for-elementor-and-polylang'),
                    'count' => 0,
                    'icon'  => '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 3H18L21 6V20C21 20.55 20.55 21 20 21H8C7.45 21 7 20.55 7 20V4C7 3.45 7.45 3 8 3Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M18 3V7H21" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 7V20C3 20.55 3.45 21 4 21H7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>'
                ];
            }

            $active_tab_key = array_key_first($visible_tabs);
            ?>

            <!-- Bulk actions for selected translations -->
            <div id="aitrfoel-translation-bulk-bar" class="aitrfoel-translation-bulk-bar" style="display:none;">
                <span class="aitrfoel-tbulk-count"><strong id="aitrfoel-tbulk-n">0</strong> <?php esc_html_e('translations selected', 'ai-translator-for-elementor-and-polylang'); ?></span>
                <button type="button" class="button button-primary" id="aitrfoel-tbulk-publish">
                    <?php esc_html_e('Publish selected', 'ai-translator-for-elementor-and-polylang'); ?>
                </button>
                <button type="button" class="button" id="aitrfoel-tbulk-retranslate">
                    <?php esc_html_e('Retranslate selected', 'ai-translator-for-elementor-and-polylang'); ?>
                </button>
                <button type="button" class="button-link" id="aitrfoel-tbulk-clear">
                    <?php esc_html_e('Clear', 'ai-translator-for-elementor-and-polylang'); ?>
                </button>
                <span id="aitrfoel-tbulk-msg" class="aitrfoel-tbulk-msg"></span>
            </div>

            <!-- Content Type Tabs -->
            <div class="aitrfoel-content-tabs">
                <div class="aitrfoel-tab-bar-header">
                    <nav class="aitrfoel-tab-nav" role="tablist">
                        <?php foreach ($visible_tabs as $tab_key => $tab_info) : 
                            $is_active = ($tab_key === $active_tab_key);
                        ?>
                            <button type="button" class="aitrfoel-tab-btn<?php echo $is_active ? ' active' : ''; ?>" data-tab="<?php echo esc_attr($tab_key); ?>" role="tab" aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>" aria-controls="aitrfoel-tab-<?php echo esc_attr($tab_key); ?>">
                                <span class="meep-tab-icon" aria-hidden="true"><?php echo $tab_info['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                <?php echo esc_html($tab_info['label']); ?>
                                <span class="aitrfoel-tab-count"><?php echo absint($tab_info['count']); ?></span>
                            </button>
                        <?php endforeach; ?>
                    </nav>
                    <div class="aitrfoel-tab-tools">
                        <div class="aitrfoel-sort-control">
                            <span class="aitrfoel-sort-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M6 12h12M10 18h4"/></svg>
                            </span>
                            <label for="aitrfoel-sort-select" class="aitrfoel-sort-label"><?php esc_html_e('Sort:', 'ai-translator-for-elementor-and-polylang'); ?></label>
                            <select id="aitrfoel-sort-select" class="aitrfoel-sort-dropdown">
                                <option value="date_desc" <?php selected($current_sort, 'date_desc'); ?>><?php esc_html_e('Date Created (Newest)', 'ai-translator-for-elementor-and-polylang'); ?></option>
                                <option value="date_asc" <?php selected($current_sort, 'date_asc'); ?>><?php esc_html_e('Date Created (Oldest)', 'ai-translator-for-elementor-and-polylang'); ?></option>
                                <option value="title_asc" <?php selected($current_sort, 'title_asc'); ?>><?php esc_html_e('Title (A – Z)', 'ai-translator-for-elementor-and-polylang'); ?></option>
                                <option value="title_desc" <?php selected($current_sort, 'title_desc'); ?>><?php esc_html_e('Title (Z – A)', 'ai-translator-for-elementor-and-polylang'); ?></option>
                            </select>
                        </div>
                        <button type="button" class="button aitrfoel-expand-all-btn" data-action="expand" title="<?php esc_attr_e('Expand or collapse all translation sub-rows', 'ai-translator-for-elementor-and-polylang'); ?>">
                            <span class="aitrfoel-expand-all-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7 8l5-5 5 5M7 16l5 5 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                            <span class="aitrfoel-expand-all-label"><?php esc_html_e('Expand All', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </button>
                    </div>
                </div>

                <?php if (isset($visible_tabs['pages'])) : ?>
                <!-- Pages Tab -->
                <div id="aitrfoel-tab-pages" class="aitrfoel-tab-panel<?php echo ($active_tab_key === 'pages') ? ' active' : ''; ?>" role="tabpanel" data-tab="pages"<?php echo ($active_tab_key === 'pages') ? '' : ' style="display: none;"'; ?>>
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
                                </tr>
                            </thead>
                            <tbody>
                                <?php $this->render_pages_rows($pages_only); ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (isset($visible_tabs['posts'])) : ?>
                <!-- Posts Tab -->
                <div id="aitrfoel-tab-posts" class="aitrfoel-tab-panel<?php echo ($active_tab_key === 'posts') ? ' active' : ''; ?>" role="tabpanel" data-tab="posts"<?php echo ($active_tab_key === 'posts') ? '' : ' style="display: none;"'; ?>>
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
                                </tr>
                            </thead>
                            <tbody>
                                <?php $this->render_pages_rows($posts_only); ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (isset($visible_tabs['products'])) : ?>
                <!-- Products Tab -->
                <div id="aitrfoel-tab-products" class="aitrfoel-tab-panel<?php echo ($active_tab_key === 'products') ? ' active' : ''; ?>" role="tabpanel" data-tab="products"<?php echo ($active_tab_key === 'products') ? '' : ' style="display: none;"'; ?>>
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
                                </tr>
                            </thead>
                            <tbody>
                                <?php $this->render_pages_rows($products_only); ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (isset($visible_tabs['forms'])) : ?>
                <!-- Forms Tab -->
                <div id="aitrfoel-tab-forms" class="aitrfoel-tab-panel<?php echo ($active_tab_key === 'forms') ? ' active' : ''; ?>" role="tabpanel" data-tab="forms"<?php echo ($active_tab_key === 'forms') ? '' : ' style="display: none;"'; ?>>
                    <div class="aitrfoel-dashboard-table">
                        <table class="wp-list-table widefat fixed">
                            <thead>
                                <tr>
                                    <td class="manage-column column-cb check-column">
                                        <input id="cb-select-all-forms" type="checkbox" data-tab="forms">
                                    </td>
                                    <th scope="col" class="manage-column column-title">
                                        <?php esc_html_e('Form', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                    <th scope="col" class="manage-column column-languages">
                                        <?php esc_html_e('Languages', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                    <th scope="col" class="manage-column column-status">
                                        <?php esc_html_e('Translation Status', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                    <th scope="col" class="manage-column column-page-type">
                                        <?php esc_html_e('Form Type', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                    <th scope="col" class="manage-column column-actions">
                                        <?php esc_html_e('Actions', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $this->render_pages_rows($forms_only); ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (isset($visible_tabs['elements'])) : ?>
                <!-- Elements & Templates Tab -->
                <div id="aitrfoel-tab-elements" class="aitrfoel-tab-panel<?php echo ($active_tab_key === 'elements') ? ' active' : ''; ?>" role="tabpanel" data-tab="elements"<?php echo ($active_tab_key === 'elements') ? '' : ' style="display: none;"'; ?>>
                    <div class="aitrfoel-dashboard-table">
                        <table class="wp-list-table widefat fixed">
                            <thead>
                                <tr>
                                    <td class="manage-column column-cb check-column">
                                        <input id="cb-select-all-elements" type="checkbox" data-tab="elements">
                                    </td>
                                    <th scope="col" class="manage-column column-title">
                                        <?php esc_html_e('Element / Template', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                    <th scope="col" class="manage-column column-languages">
                                        <?php esc_html_e('Languages', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                    <th scope="col" class="manage-column column-status">
                                        <?php esc_html_e('Translation Status', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                    <th scope="col" class="manage-column column-page-type">
                                        <?php esc_html_e('Element Type', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                    <th scope="col" class="manage-column column-actions">
                                        <?php esc_html_e('Actions', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $this->render_pages_rows($elements_only); ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (isset($visible_tabs['custom'])) : ?>
                <!-- Custom Post Types Tab -->
                <div id="aitrfoel-tab-custom" class="aitrfoel-tab-panel<?php echo ($active_tab_key === 'custom') ? ' active' : ''; ?>" role="tabpanel" data-tab="custom"<?php echo ($active_tab_key === 'custom') ? '' : ' style="display: none;"'; ?>>
                    <div class="aitrfoel-dashboard-table">
                        <table class="wp-list-table widefat fixed">
                            <thead>
                                <tr>
                                    <td class="manage-column column-cb check-column">
                                        <input id="cb-select-all-custom" type="checkbox" data-tab="custom">
                                    </td>
                                    <th scope="col" class="manage-column column-title">
                                        <?php esc_html_e('Item', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                    <th scope="col" class="manage-column column-languages">
                                        <?php esc_html_e('Languages', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                    <th scope="col" class="manage-column column-status">
                                        <?php esc_html_e('Translation Status', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                    <th scope="col" class="manage-column column-page-type">
                                        <?php esc_html_e('Type', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                    <th scope="col" class="manage-column column-actions">
                                        <?php esc_html_e('Actions', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $this->render_pages_rows($custom_only); ?>
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

            <!-- Review Prompt Modal -->
            <div id="aitrfoel-review-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(15, 23, 42, 0.6); z-index:100050; align-items:center; justify-content:center; padding:20px; backdrop-filter:blur(2px);">
                <div style="background:#fff; border-radius:16px; width:100%; max-width:480px; padding:28px 24px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); text-align:center; position:relative; animation: aitrfoelModalPop 0.25s cubic-bezier(0.16, 1, 0.3, 1);">
                    <div style="display:flex; justify-content:center; gap:6px; margin-bottom:12px; color:#eab308;" aria-hidden="true">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    </div>
                    <h3 style="font-size:20px; font-weight:700; color:#0f172a; margin:0 0 10px 0;">
                        <?php esc_html_e('Enjoying Meep Translator?', 'ai-translator-for-elementor-and-polylang'); ?>
                    </h3>
                    <p style="font-size:13.5px; line-height:1.55; color:#475569; margin:0 0 22px 0;">
                        <?php esc_html_e('You\'ve successfully completed several translations! If our plugin is saving you time, could you take 30 seconds to rate us on WordPress.org? It helps us keep improving the plugin.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                    <div style="display:flex; flex-direction:column; gap:10px;">
                        <a href="https://wordpress.org/support/plugin/ai-translator-for-elementor-and-polylang/reviews/#new-post" target="_blank" rel="noopener noreferrer" class="button button-primary aitrfoel-review-btn-rate" style="padding:10px 16px; height:auto; font-size:14px; font-weight:600; text-align:center; justify-content:center; display:flex; align-items:center; gap:8px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            <span><?php esc_html_e('Leave a Review', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </a>
                        <div style="display:flex; justify-content:center; gap:14px; margin-top:4px;">
                            <button type="button" class="button button-link aitrfoel-review-btn-snooze" style="color:#64748b; font-size:12.5px; text-decoration:none; padding:4px 8px; cursor:pointer; display:inline-flex; align-items:center; gap:5px;">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                <span><?php esc_html_e('Remind me later', 'ai-translator-for-elementor-and-polylang'); ?></span>
                            </button>
                            <button type="button" class="button button-link aitrfoel-review-btn-dismiss" style="color:#94a3b8; font-size:12.5px; text-decoration:none; padding:4px 8px; cursor:pointer; display:inline-flex; align-items:center; gap:5px;">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                <span><?php esc_html_e('Already reviewed / Dismiss', 'ai-translator-for-elementor-and-polylang'); ?></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php
        // Enqueue dashboard scripts and styles
        $this->enqueue_dashboard_assets();
    }

    /**
     * Backward compatibility alias for get_pages_with_translations
     */
    public function get_all_pages_with_translations() {
        return $this->get_pages_with_translations();
    }

    /**
     * Get pages with their translations
     *
     * @param string $sort Sort mode: 'date_desc', 'date_asc', 'title_asc', 'title_desc'
     */
    public function get_pages_with_translations($sort = 'date_desc') {
        $multilingual = class_exists('AITRFOEL_Multilingual_Manager') ? AITRFOEL_Multilingual_Manager::get_instance() : null;
        if (!$multilingual || !$multilingual->is_active()) {
            return [];
        }

        if (!in_array($sort, ['date_desc', 'date_asc', 'title_asc', 'title_desc'], true)) {
            $sort = 'date_desc';
        }

        $query_orderby = 'date';
        $query_order   = 'DESC';
        if ($sort === 'date_asc') {
            $query_orderby = 'date';
            $query_order   = 'ASC';
        } elseif ($sort === 'title_asc') {
            $query_orderby = 'title';
            $query_order   = 'ASC';
        } elseif ($sort === 'title_desc') {
            $query_orderby = 'title';
            $query_order   = 'DESC';
        }

        // Include all content post types that have an admin UI (pages, posts, products,
        // and custom post types like Testimonials, Employees, Portfolios, JetEngine, etc.)
        $exclude_system_types = [
            'attachment', 'revision', 'nav_menu_item', 'custom_css', 
            'customize_changeset', 'oembed_cache', 'user_request', 
            'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part', 
            'wp_global_styles', 'action_scheduler'
        ];

        $dashboard_post_types = [];
        foreach (get_post_types(['show_ui' => true], 'objects') as $pt_name => $pt_obj) {
            if (in_array($pt_name, $exclude_system_types, true)) {
                continue;
            }
            $dashboard_post_types[] = $pt_name;
        }
        /**
         * Filter the post types listed on the translation dashboard.
         *
         * @param string[] $dashboard_post_types
         */
        $dashboard_post_types = apply_filters('aitrfoel_dashboard_post_types', $dashboard_post_types);
        if (empty($dashboard_post_types)) {
            $dashboard_post_types = ['page', 'post', 'product'];
        }
        $element_post_types = ['elementor_library', 'elementor-hf', 'jet-theme-core', 'jet-engine', 'wpcf7_contact_form', 'sureforms_form', 'sf_form', 'wpforms', 'jet-form-builder', 'fluentform', 'formidable', 'ninja_forms'];
        foreach ($element_post_types as $ept) {
            if (post_type_exists($ept) && !in_array($ept, $dashboard_post_types, true)) {
                $dashboard_post_types[] = $ept;
            }
        }

        $all_pages = get_posts([
            'post_type' => $dashboard_post_types,
            'post_status' => ['publish', 'draft'],
            'numberposts' => -1,
            'orderby' => $query_orderby,
            'order' => $query_order,
            'suppress_filters' => true // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- intentional: Polylang/WPML query filters would hide posts in other languages, which the translator must see (Get posts in all languages for WPML)
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
            
            // Find the canonical source page for this translation group
            // Priority 1: Check if any post in the group is explicitly referenced as the original source by another post via _aitrfoel_source_page
            // Priority 2: Post in the site's default language (canonical source for Polylang/WPML/Built-in groups)
            // Priority 3: First post that has no _aitrfoel_source_page (created naturally, not as a translation)
            // Priority 4: Oldest post in the group
            $source_page = null;
            $all_group_ids = array_unique(array_filter(array_merge([$page->ID], array_values((array) $translations))));

            // Priority 1: Explicit source reference
            foreach ($all_group_ids as $cid) {
                $cmeta = get_post_meta($cid, '_aitrfoel_source_page', true);
                if ($cmeta && (int) $cmeta !== $cid && in_array((int) $cmeta, $all_group_ids, true)) {
                    $cpost = get_post((int) $cmeta);
                    if ($cpost && $cpost->post_status !== 'trash') {
                        $source_page = $cpost;
                        break;
                    }
                }
            }

            // Priority 2: Post in default language
            if (!$source_page && $default_lang) {
                if (isset($translations[$default_lang])) {
                    $cpost = get_post($translations[$default_lang]);
                    if ($cpost && $cpost->post_status !== 'trash') {
                        $source_page = $cpost;
                    }
                } elseif ($page_lang === $default_lang && $page->post_status !== 'trash') {
                    $source_page = $page;
                } else {
                    foreach ($all_group_ids as $cid) {
                        if ($multilingual->get_post_language($cid) === $default_lang) {
                            $cpost = ($cid === $page->ID) ? $page : get_post($cid);
                            if ($cpost && $cpost->post_status !== 'trash') {
                                $source_page = $cpost;
                                break;
                            }
                        }
                    }
                }
            }

            // Priority 3: First post that has no _aitrfoel_source_page (created naturally, not as a translation)
            if (!$source_page) {
                foreach ($all_group_ids as $cid) {
                    $cmeta = get_post_meta($cid, '_aitrfoel_source_page', true);
                    if (!$cmeta || (int) $cmeta === $cid) {
                        $cpost = ($cid === $page->ID) ? $page : get_post($cid);
                        if ($cpost && $cpost->post_status !== 'trash') {
                            $source_page = $cpost;
                            break;
                        }
                    }
                }
            }

            // Priority 4: Oldest post in the group
            if (!$source_page) {
                $oldest_page = $page;
                $oldest_date = $page->post_date;
                foreach ($all_group_ids as $cid) {
                    $cpost = ($cid === $page->ID) ? $page : get_post($cid);
                    if ($cpost && $cpost->post_status !== 'trash' && $cpost->post_date < $oldest_date) {
                        $oldest_page = $cpost;
                        $oldest_date = $cpost->post_date;
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
                    'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- intentional: Polylang/WPML query filters would hide posts in other languages, which the translator must see
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

        // Sort translation groups according to the requested criteria
        usort($translation_groups, function ($a, $b) use ($sort) {
            $src_a = $a['source'];
            $src_b = $b['source'];
            switch ($sort) {
                case 'date_asc':
                    return strcmp($src_a->post_date, $src_b->post_date);
                case 'title_asc':
                    return strcasecmp($src_a->post_title, $src_b->post_title);
                case 'title_desc':
                    return strcasecmp($src_b->post_title, $src_a->post_title);
                case 'date_desc':
                default:
                    return strcmp($src_b->post_date, $src_a->post_date);
            }
        });

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

                case AITRFOEL_Content_Detector::TYPE_FORM:
                    return [
                        'type' => 'form',
                        'label' => __('Form', 'ai-translator-for-elementor-and-polylang'),
                        'class' => 'aitrfoel-page-form',
                        'tooltip' => __('Form - fields, options, and messages are translated', 'ai-translator-for-elementor-and-polylang')
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
                'label' => __('Elementor', 'ai-translator-for-elementor-and-polylang'),
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
            } elseif ($source->post_type === 'product' || $source->post_type === 'sc_product') {
                $post_type_icon_class = 'aitrfoel-title-icon-product';
                $post_type_label = __('Product', 'ai-translator-for-elementor-and-polylang');
            } elseif ($source->post_type === 'elementor-hf') {
                $post_type_icon_class = 'aitrfoel-title-icon-template';
                $post_type_label = __('Header/Footer', 'ai-translator-for-elementor-and-polylang');
            } elseif ($source->post_type === 'elementor_library' || $source->post_type === 'jet-theme-core') {
                $post_type_icon_class = 'aitrfoel-title-icon-template';
                $post_type_label = __('Template', 'ai-translator-for-elementor-and-polylang');
            } elseif (strpos($source->post_type, 'form') !== false) {
                $post_type_icon_class = 'aitrfoel-title-icon-form';
                $post_type_label = __('Form', 'ai-translator-for-elementor-and-polylang');
            }
            $cached_words = get_post_meta($source->ID, '_aitrfoel_word_count', true);
            $has_cached_words = ($cached_words !== '' && is_numeric($cached_words));
            $words_val = $has_cached_words ? (int) $cached_words : 0;
            ?>
            <tr class="aitrfoel-source-page" data-page-id="<?php echo absint($source->ID); ?>" data-words="<?php echo esc_attr($words_val); ?>">
                <th scope="row" class="check-column">
                    <input type="checkbox" 
                           name="bulk_pages[]" 
                           value="<?php echo absint($source->ID); ?>"
                           class="aitrfoel-page-checkbox"
                           data-words="<?php echo esc_attr($words_val); ?>"
                           data-missing-languages="<?php echo esc_attr(json_encode($missing_languages)); ?>"
                           data-existing-languages="<?php echo esc_attr(json_encode($existing_languages)); ?>"
                           <?php echo $checkbox_disabled ? 'disabled' : ''; ?>>
                </th>
                <td class="column-title" data-label="<?php esc_attr_e('Title', 'ai-translator-for-elementor-and-polylang'); ?>">
                    <div class="aitrfoel-title-cell-wrap">
                        <?php if (!empty($translations)) : ?>
                            <button type="button" 
                                    class="aitrfoel-expand-button aitrfoel-tree-toggle" 
                                    data-source-id="<?php echo absint($source->ID); ?>"
                                    data-expanded="false"
                                    aria-expanded="false"
                                    title="<?php esc_attr_e('Expand translations', 'ai-translator-for-elementor-and-polylang'); ?>">
                                <span class="aitrfoel-expand-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                            </button>
                        <?php else : ?>
                            <span class="aitrfoel-tree-spacer" aria-hidden="true"></span>
                        <?php endif; ?>
                        <div class="aitrfoel-title-content">
                            <strong>
                                <a class="aitrfoel-page-title-link" href="<?php echo esc_url($source_permalink); ?>" target="_blank" rel="noopener">
                                    <span class="aitrfoel-title-post-type-icon <?php echo esc_attr($post_type_icon_class); ?>" aria-hidden="true"></span><?php echo esc_html($source->post_title !== '' ? $source->post_title : sprintf(/* translators: %d: post ID */ __('(no title) #%d', 'ai-translator-for-elementor-and-polylang'), $source->ID)); ?>
                                    <span class="aitrfoel-page-lang">(<?php echo esc_html(strtoupper($multilingual->get_post_language($source->ID) ?: '?')); ?>)</span>
                                </a>
                            </strong>
                            <div class="aitrfoel-page-meta-row" style="display:flex;align-items:center;gap:6px;margin-top:2px;">
                                <span class="aitrfoel-page-status">
                                    <?php echo esc_html($this->get_status_display_name($source->post_status)); ?>
                                </span>
                                <span class="aitrfoel-word-badge <?php echo $has_cached_words ? '' : 'aitrfoel-word-badge-pending'; ?>" data-post-id="<?php echo absint($source->ID); ?>" title="<?php esc_attr_e('Estimated translatable words', 'ai-translator-for-elementor-and-polylang'); ?>">
                                    <?php
                                    if ($has_cached_words) {
                                        /* translators: %s: formatted word count */
                                        echo esc_html(sprintf(_n('~%s word', '~%s words', (int) $words_val, 'ai-translator-for-elementor-and-polylang'), number_format_i18n($words_val)));
                                    } else {
                                        echo '<span class="aitrfoel-badge-dot"></span>' . esc_html__('Calculating...', 'ai-translator-for-elementor-and-polylang');
                                    }
                                    ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </td>
                <td class="column-languages" data-label="<?php esc_attr_e('Languages', 'ai-translator-for-elementor-and-polylang'); ?>">
                    <?php 
                    $available_langs = $available_lang_codes;
                    $source_lang = $multilingual->get_post_language($source->ID);
                    
                    if (!empty($available_langs)) {
                        foreach ($available_langs as $lang_code) {
                            $is_source_lang = ($lang_code === $source_lang);
                            $has_translation = isset($translations[$lang_code]);
                            if ($is_source_lang) {
                                $class = 'aitrfoel-lang-source';
                                $chip_title = __('Source language', 'ai-translator-for-elementor-and-polylang');
                            } elseif ($has_translation) {
                                $class = 'aitrfoel-lang-exists';
                                $chip_title = __('Translated', 'ai-translator-for-elementor-and-polylang');
                            } else {
                                $class = 'aitrfoel-lang-missing';
                                $chip_title = __('Not translated', 'ai-translator-for-elementor-and-polylang');
                            }
                            echo '<span class="aitrfoel-lang-flag ' . esc_attr($class) . '" title="' . esc_attr($chip_title) . '">' . esc_html(strtoupper($lang_code)) . '</span> ';
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
                    <div class="aitrfoel-row-actions">
                        <a href="<?php echo esc_url(get_edit_post_link($source->ID)); ?>" class="button button-small button-primary aitrfoel-row-primary aitrfoel-act-icon" title="<?php esc_attr_e('Edit', 'ai-translator-for-elementor-and-polylang'); ?>" aria-label="<?php esc_attr_e('Edit', 'ai-translator-for-elementor-and-polylang'); ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="17" height="17" aria-hidden="true" focusable="false" fill="currentColor"><path d="M13.89 3.39l2.71 2.72c.46.46.42 1.24.03 1.64l-8.01 8.02-5.56 1.16 1.16-5.58s7.6-7.63 7.99-8.03c.39-.39 1.22-.39 1.68.07zm-2.73 2.79l-5.59 5.61 1.11 1.11 5.54-5.65zm-2.97 8.23l5.58-5.6-1.07-1.08-5.59 5.6z"/></svg>
                        </a>
                        <a href="<?php echo esc_url($source_permalink); ?>" class="button button-small aitrfoel-act-icon" target="_blank" title="<?php esc_attr_e('View', 'ai-translator-for-elementor-and-polylang'); ?>" aria-label="<?php esc_attr_e('View', 'ai-translator-for-elementor-and-polylang'); ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="17" height="17" aria-hidden="true" focusable="false" fill="currentColor"><path d="M18.3 9.5C15 4.9 8.5 3.8 3.9 7.2c-1.2.9-2.2 2.1-3 3.4.2.4.5.8.8 1.2 3.3 4.6 9.6 5.6 14.2 2.4.9-.7 1.7-1.4 2.4-2.4.3-.4.5-.8.8-1.2-.3-.4-.5-.8-.8-1.1zm-8.2-2.3c.5-.5 1.3-.5 1.8 0s.5 1.3 0 1.8-1.3.5-1.8 0-.5-1.3 0-1.8zm-.1 7.7c-3.1 0-6-1.6-7.7-4.2C3.5 9 5.1 7.8 7 7.2c-.7.8-1 1.7-1 2.7 0 2.2 1.7 4.1 4 4.1 2.2 0 4.1-1.7 4.1-4v-.1c0-1-.4-2-1.1-2.7 1.9.6 3.5 1.8 4.7 3.5-1.7 2.6-4.6 4.2-7.7 4.2z"/></svg>
                        </a>
                        <details class="aitrfoel-row-more">
                            <summary class="aitrfoel-row-more-toggle button button-small aitrfoel-act-icon" title="<?php esc_attr_e('More actions', 'ai-translator-for-elementor-and-polylang'); ?>" aria-label="<?php esc_attr_e('More actions', 'ai-translator-for-elementor-and-polylang'); ?>">
                                <span class="aitrfoel-dots" aria-hidden="true"></span>
                            </summary>
                            <div class="aitrfoel-row-more-menu">
                                <button type="button"
                                        class="aitrfoel-menu-item aitrfoel-menu-danger aitrfoel-delete-page"
                                        data-page-id="<?php echo absint($source->ID); ?>"
                                        data-page-title="<?php echo esc_attr($source->post_title); ?>"
                                        data-is-translation="false"
                                        title="<?php esc_attr_e('Permanently delete (cannot be undone)', 'ai-translator-for-elementor-and-polylang'); ?>">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="17" height="17" aria-hidden="true" focusable="false" fill="currentColor"><path d="M12 4h3c.6 0 1 .4 1 1v1H3V5c0-.6.5-1 1-1h3c.2-1.1 1.3-2 2.5-2s2.3.9 2.5 2zM8 4h3c-.2-.6-.9-1-1.5-1S8.2 3.4 8 4zM4 7h11l-.9 10.1c0 .5-.5.9-1 .9H5.9c-.5 0-.9-.4-1-.9L4 7z"/></svg>
                                    <?php esc_html_e('Delete page', 'ai-translator-for-elementor-and-polylang'); ?>
                                </button>
                                <?php if (!empty($translations)) : ?>
                                <button type="button"
                                        class="aitrfoel-menu-item aitrfoel-menu-danger aitrfoel-delete-translations"
                                        data-page-id="<?php echo absint($source->ID); ?>"
                                        data-page-title="<?php echo esc_attr($source->post_title); ?>"
                                        data-translation-count="<?php echo absint(count($translations)); ?>"
                                        title="<?php esc_attr_e('Permanently delete all translations of this page. The source page is kept. Cannot be undone.', 'ai-translator-for-elementor-and-polylang'); ?>">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="17" height="17" aria-hidden="true" focusable="false" fill="currentColor"><path d="M11 7H9.49c-.63 0-1.25.3-1.59.7L7 5H4.13l-2.39 7h1.69l.74-2H7v4H2c-1.1 0-2-.9-2-2V5c0-1.1.9-2 2-2h7c1.1 0 2 .9 2 2v2zM6.51 9H4.49l1-2.93zM10 8h7c1.1 0 2 .9 2 2v7c0 1.1-.9 2-2 2h-7c-1.1 0-2-.9-2-2v-7c0-1.1.9-2 2-2zm7.25 5v-1.08h-3.17V9.75h-1.16v2.17H9.75V13h1.28c.11.85.56 1.85 1.28 2.62-.87.36-1.89.62-2.31.62-.01.02.22.97.2 1.46.84 0 2.21-.5 3.28-1.15 1.09.65 2.48 1.15 3.34 1.15-.02-.49.2-1.44.2-1.46-.43 0-1.49-.27-2.38-.63.7-.77 1.14-1.77 1.25-2.61h1.36zm-3.81 1.93c-.5-.46-.85-1.13-1.01-1.93h2.09c-.17.8-.51 1.47-1 1.93l-.04.03s-.03-.02-.04-.03z"/></svg>
                                    <?php printf(/* translators: %d: number of translations */ esc_html__('Delete translations (%d)', 'ai-translator-for-elementor-and-polylang'), absint(count($translations))); ?>
                                </button>
                                <?php endif; ?>
                            </div>
                        </details>
                    </div>
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
                                data-page-id="<?php echo absint($translation->ID); ?>"
                                data-source-id="<?php echo absint($source->ID); ?>"
                                data-lang="<?php echo esc_attr($lang_code); ?>"
                                data-status="<?php echo esc_attr($translation->post_status); ?>"
                                data-title="<?php echo esc_attr($translation->post_title); ?>"
                                aria-label="<?php esc_attr_e('Select this translation', 'ai-translator-for-elementor-and-polylang'); ?>" />
                    </th>
                    <td class="column-title" data-label="<?php esc_attr_e('Title', 'ai-translator-for-elementor-and-polylang'); ?>">
                        <div class="aitrfoel-title-cell-wrap aitrfoel-child-title-wrap">
                            <span class="aitrfoel-tree-connector" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M7 4v7a3 3 0 0 0 3 3h8M14 10l4 4-4 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </span>
                            <div class="aitrfoel-title-content">
                                <strong class="aitrfoel-translation-indent">
                                    <a class="aitrfoel-page-title-link" href="<?php echo esc_url($translation_permalink); ?>" target="_blank" rel="noopener">
                                        <?php echo esc_html($translation->post_title); ?> 
                                        <span class="aitrfoel-page-lang">(<?php echo esc_html(strtoupper($lang_code)); ?>)</span>
                                    </a>
                                </strong>
                                <div class="aitrfoel-page-status">
                                    <?php echo esc_html($this->get_status_display_name($translation->post_status)); ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="column-languages" data-label="<?php esc_attr_e('Languages', 'ai-translator-for-elementor-and-polylang'); ?>">
                        <span class="aitrfoel-translation-note"><?php esc_html_e('Translation of', 'ai-translator-for-elementor-and-polylang'); ?>: <?php echo esc_html($source->post_title); ?></span>
                    </td>
                    <td class="column-status" data-label="<?php esc_attr_e('Status', 'ai-translator-for-elementor-and-polylang'); ?>">
                        <span class="aitrfoel-status-complete"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:3px;" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg><?php esc_html_e('Complete', 'ai-translator-for-elementor-and-polylang'); ?></span>
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
                        <div class="aitrfoel-row-actions">
                            <a href="<?php echo esc_url(get_edit_post_link($translation->ID)); ?>" class="button button-small button-primary aitrfoel-row-primary aitrfoel-act-icon" title="<?php esc_attr_e('Edit', 'ai-translator-for-elementor-and-polylang'); ?>" aria-label="<?php esc_attr_e('Edit', 'ai-translator-for-elementor-and-polylang'); ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="17" height="17" aria-hidden="true" focusable="false" fill="currentColor"><path d="M13.89 3.39l2.71 2.72c.46.46.42 1.24.03 1.64l-8.01 8.02-5.56 1.16 1.16-5.58s7.6-7.63 7.99-8.03c.39-.39 1.22-.39 1.68.07zm-2.73 2.79l-5.59 5.61 1.11 1.11 5.54-5.65zm-2.97 8.23l5.58-5.6-1.07-1.08-5.59 5.6z"/></svg>
                            </a>
                            <a href="<?php echo esc_url($translation_permalink); ?>" class="button button-small aitrfoel-act-icon" target="_blank" title="<?php esc_attr_e('View', 'ai-translator-for-elementor-and-polylang'); ?>" aria-label="<?php esc_attr_e('View', 'ai-translator-for-elementor-and-polylang'); ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="17" height="17" aria-hidden="true" focusable="false" fill="currentColor"><path d="M18.3 9.5C15 4.9 8.5 3.8 3.9 7.2c-1.2.9-2.2 2.1-3 3.4.2.4.5.8.8 1.2 3.3 4.6 9.6 5.6 14.2 2.4.9-.7 1.7-1.4 2.4-2.4.3-.4.5-.8.8-1.2-.3-.4-.5-.8-.8-1.1zm-8.2-2.3c.5-.5 1.3-.5 1.8 0s.5 1.3 0 1.8-1.3.5-1.8 0-.5-1.3 0-1.8zm-.1 7.7c-3.1 0-6-1.6-7.7-4.2C3.5 9 5.1 7.8 7 7.2c-.7.8-1 1.7-1 2.7 0 2.2 1.7 4.1 4 4.1 2.2 0 4.1-1.7 4.1-4v-.1c0-1-.4-2-1.1-2.7 1.9.6 3.5 1.8 4.7 3.5-1.7 2.6-4.6 4.2-7.7 4.2z"/></svg>
                            </a>
                            <details class="aitrfoel-row-more">
                                <summary class="aitrfoel-row-more-toggle button button-small aitrfoel-act-icon" title="<?php esc_attr_e('More actions', 'ai-translator-for-elementor-and-polylang'); ?>" aria-label="<?php esc_attr_e('More actions', 'ai-translator-for-elementor-and-polylang'); ?>">
                                    <span class="aitrfoel-dots" aria-hidden="true"></span>
                                </summary>
                                <div class="aitrfoel-row-more-menu">
                                    <?php if (in_array($translation->post_status, ['draft', 'pending'], true)) : ?>
                                    <button type="button"
                                            class="aitrfoel-menu-item aitrfoel-publish-page"
                                            data-page-id="<?php echo absint($translation->ID); ?>"
                                            data-page-title="<?php echo esc_attr($translation->post_title); ?>"
                                            title="<?php esc_attr_e('Publish this translation', 'ai-translator-for-elementor-and-polylang'); ?>">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="17" height="17" aria-hidden="true" focusable="false" fill="currentColor"><path d="M16.7 6.3l-7.9 7.9-3.5-3.5-1.4 1.4 4.9 4.9 9.3-9.3z"/></svg>
                                        <?php esc_html_e('Publish', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </button>
                                    <?php endif; ?>
                                    <a href="<?php echo esc_url($review_url); ?>" class="aitrfoel-menu-item aitrfoel-review-btn">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="17" height="17" aria-hidden="true" focusable="false" fill="currentColor"><path d="M12.14 4.18c1.87 1.87 2.11 4.75.72 6.89.12.1.22.21.36.31.2.16.47.36.81.59.34.24.56.39.66.47.42.31.73.57.94.78.32.32.6.65.84 1 .25.35.44.69.59 1.04.14.35.21.68.18 1-.02.32-.14.59-.36.81s-.49.34-.81.36c-.31.02-.65-.04-.99-.19-.35-.14-.7-.34-1.04-.59-.35-.24-.68-.52-1-.84-.21-.21-.47-.52-.77-.93-.1-.13-.25-.35-.47-.66-.22-.32-.4-.57-.56-.78-.16-.2-.29-.35-.44-.5-2.07 1.09-4.69.76-6.44-.98-2.14-2.15-2.14-5.64 0-7.78 2.15-2.15 5.63-2.15 7.78 0zm-1.41 6.36c1.36-1.37 1.36-3.58 0-4.95-1.37-1.37-3.59-1.37-4.95 0-1.37 1.37-1.37 3.58 0 4.95 1.36 1.37 3.58 1.37 4.95 0z"/></svg>
                                        <?php esc_html_e('Review', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </a>
                                    <button type="button"
                                            class="aitrfoel-menu-item aitrfoel-retranslate-page"
                                            data-source-id="<?php echo absint($source->ID); ?>"
                                            data-page-id="<?php echo absint($translation->ID); ?>"
                                            data-lang="<?php echo esc_attr($lang_code); ?>"
                                            data-page-title="<?php echo esc_attr($translation->post_title); ?>"
                                            title="<?php esc_attr_e('Re-run translation from the source — replaces this translation (e.g. after adding Exclude Words)', 'ai-translator-for-elementor-and-polylang'); ?>">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="17" height="17" aria-hidden="true" focusable="false" fill="currentColor"><path d="M10 3a7 7 0 1 0 6.32 4h-2.2A4.99 4.99 0 1 1 10 5c1.38 0 2.63.56 3.54 1.46L11 9h6V3l-2.05 2.05A6.97 6.97 0 0 0 10 3z"/></svg>
                                        <?php esc_html_e('Retranslate', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </button>
                                    <button type="button"
                                            class="aitrfoel-menu-item aitrfoel-menu-danger aitrfoel-delete-page"
                                            data-page-id="<?php echo absint($translation->ID); ?>"
                                            data-page-title="<?php echo esc_attr($translation->post_title); ?>"
                                            data-is-translation="true"
                                            title="<?php esc_attr_e('Permanently delete (cannot be undone)', 'ai-translator-for-elementor-and-polylang'); ?>">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="17" height="17" aria-hidden="true" focusable="false" fill="currentColor"><path d="M12 4h3c.6 0 1 .4 1 1v1H3V5c0-.6.5-1 1-1h3c.2-1.1 1.3-2 2.5-2s2.3.9 2.5 2zM8 4h3c-.2-.6-.9-1-1.5-1S8.2 3.4 8 4zM4 7h11l-.9 10.1c0 .5-.5.9-1 .9H5.9c-.5 0-.9-.4-1-.9L4 7z"/></svg>
                                        <?php esc_html_e('Delete', 'ai-translator-for-elementor-and-polylang'); ?>
                                    </button>
                                </div>
                            </details>
                        </div>
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
            ? 'https://meeptech.com/upgrade?utm_source=wp-plugin&utm_medium=free-plugin&utm_campaign=pro-upgrade'
            : aitrfoel_free_upgrade_url('admin-dashboard');

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

        // Review eligibility
        $rev_dismissed = (bool) get_option('aitrfoel_review_dismissed', false);
        $rev_snooze    = (int) get_option('aitrfoel_review_snoozed_until', 0);
        $rev_count     = (int) get_option('aitrfoel_successful_translations_count', 0);
        $review_eligible = (!$rev_dismissed && time() > $rev_snooze && $rev_count >= 3);

        $license_info = $this->get_license_info();

        wp_localize_script('aitrfoel-dashboard', 'aitrfoelDashboard', [
            'ajax_url' => esc_url(admin_url('admin-ajax.php')),
            'nonce' => wp_create_nonce('aitrfoel_ajax_nonce'),
            'upgrade_url' => esc_url($upgrade_url),
            'is_pro' => (defined('AITRFOEL_VERSION_TYPE') && AITRFOEL_VERSION_TYPE === 'pro'),
            'review_eligible' => $review_eligible,
            'success_count' => $rev_count,
            'languages' => $multilingual_languages,
            'default_language' => ($multilingual_manager && method_exists($multilingual_manager, 'get_default_language')) ? $multilingual_manager->get_default_language() : 'en',
            'multilingual_plugin' => $multilingual_manager ? $multilingual_manager->get_plugin_display_name() : 'None',
            'word_limit' => $license_info['word_limit'] ?? 7200,
            'words_used' => $license_info['words_used'] ?? 0,
            'words_left' => $license_info['words_left'] ?? 7200,
            'plan' => $license_info['plan'] ?? 'Free',
            'bonus_claimed' => (bool) get_option('aitrfoel_share_bonus_claimed', false),
            // Keep in sync with Translate All and the server (billing is 1x source words per language).
            'server_usage_factor' => class_exists('AITRFOEL_Translate_All') ? AITRFOEL_Translate_All::SERVER_USAGE_FACTOR : 1,
            // Pages translated in parallel from the dashboard (each page's languages stay sequential).
            'concurrency' => max(1, min(4, (int) apply_filters('aitrfoel_dashboard_concurrency', 2))),
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
                'word_label'           => __('word', 'ai-translator-for-elementor-and-polylang'),
                'words_label'          => __('words', 'ai-translator-for-elementor-and-polylang'),
                'quota_exceeds_warning'=> /* translators: %1$s: words needed, %2$s: words over the limit */ __('Needs ~%1$s words (%2$s over limit)', 'ai-translator-for-elementor-and-polylang'),
                'quota_fits_info'      => /* translators: %1$s: number of selected items, %2$s: words needed, %3$s: words left */ __('Selected: %1$s · ~%2$s words (%3$s left)', 'ai-translator-for-elementor-and-polylang'),
                'calculating_words'    => __('Calculating...', 'ai-translator-for-elementor-and-polylang'),
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
        $license_manager = class_exists('AITRFOEL_License_Manager_Server') ? AITRFOEL_License_Manager_Server::get_instance() : null;
        $server_status = $license_manager ? $license_manager->get_license_status() : [];
        
        $license_status = $server_status['plan'] ?? 'Free';
        $word_limit = $server_status['word_limit'] ?? 7200;
        $words_used = $server_status['words_used'] ?? 0;
        $words_left = $server_status['words_left'] ?? max(0, $word_limit - $words_used);
        $license_expired = isset($server_status['license_expired']) ? $server_status['license_expired'] : false;

        // Fallback: If server status still says 'Free' but Freemius SDK is paying, use Freemius plan
        if (strtolower($license_status) === 'free' && function_exists('eatp_fs') && is_object(eatp_fs()) && method_exists(eatp_fs(), 'is_paying') && eatp_fs()->is_paying()) {
            if ($license_manager && method_exists($license_manager, 'get_freemius_plan_name')) {
                $fs_plan = $license_manager->get_freemius_plan_name();
                if (!empty($fs_plan) && strtolower($fs_plan) !== 'free') {
                    $license_status = $fs_plan;
                    if (strtolower($license_status) === 'agency lifetime') {
                        $word_limit = -1;
                        $words_left = -1;
                    }
                }
            }
        }
        
        // Capitalize for display (preserve multi-word plan names like 'Agency Lifetime')
        $display_name = ucwords(strtolower($license_status));
        
        $is_pro = (defined('AITRFOEL_VERSION_TYPE') && AITRFOEL_VERSION_TYPE === 'pro')
            || (strtolower($license_status) !== 'free' && !empty($license_status))
            || (function_exists('eatp_fs') && is_object(eatp_fs()) && method_exists(eatp_fs(), 'is_paying') && eatp_fs()->is_paying());

        return [
            'type' => strtolower($license_status),
            'limit' => $word_limit,
            'name' => $display_name,
            'plan' => $license_status,
            'word_limit' => $word_limit,
            'words_used' => $words_used,
            'words_left' => $words_left,
            'is_active' => (function_exists('eatp_fs') && is_object(eatp_fs()) && method_exists(eatp_fs(), 'is_registered') && eatp_fs()->is_registered()),
            'is_pro' => $is_pro,
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
    /**
     * Keep only real content for progress figures: drop forms, Elementor/theme templates
     * and empty untitled posts, which otherwise inflate the total and make progress look low.
     */
    private function filter_progress_content($pages_data) {
        if (empty($pages_data) || !is_array($pages_data)) {
            return $pages_data;
        }
        $non_content = [
            'wpcf7_contact_form', 'sureforms_form', 'sf_form', 'wpforms', 'jet-form-builder', 'fluentform', 'formidable', 'ninja_forms',
            'elementor_library', 'elementor-hf', 'jet-theme-core', 'jet-engine',
        ];
        return array_values(array_filter($pages_data, function ($group) use ($non_content) {
            $src = $group['source'] ?? null;
            if (!$src || in_array($src->post_type, $non_content, true)) {
                return false;
            }
            if (trim((string) $src->post_title) === '' && trim(wp_strip_all_tags((string) $src->post_content)) === '') {
                return false;
            }
            return true;
        }));
    }

    private function render_compact_stats_bar($pages_data = []) {
        $pages_data = $this->filter_progress_content($pages_data);
        // 1. Calculate Content and Translation Completeness
        $multilingual = class_exists('AITRFOEL_Multilingual_Manager') ? AITRFOEL_Multilingual_Manager::get_instance() : null;
        $all_langs    = ($multilingual && $multilingual->is_active()) ? $multilingual->get_available_languages() : [];
        $default_lang = ($multilingual && $multilingual->is_active()) ? $multilingual->get_default_language() : 'en';

        $target_langs = [];
        foreach ($all_langs as $code => $lang) {
            if ($code !== $default_lang) {
                $name = is_object($lang) ? ($lang->name ?? strtoupper($code)) : (is_array($lang) ? ($lang['name'] ?? strtoupper($code)) : strtoupper($code));
                $target_langs[$code] = $name;
            }
        }
        $target_lang_count = count($target_langs);
        $total_items       = !empty($pages_data) ? count($pages_data) : count($this->get_elementor_pages());
        $total_slots       = max(1, $total_items * max(1, $target_lang_count));
        $completed_slots   = 0;
        $untranslated_items = 0;

        if (!empty($pages_data) && $target_lang_count > 0) {
            foreach ($pages_data as $group) {
                $source = $group['source'];
                $source_lang = $multilingual ? $multilingual->get_post_language($source->ID) : $default_lang;
                $present_langs = [];
                if ($source_lang) {
                    $present_langs[$source_lang] = true;
                }
                if (!empty($group['translations']) && is_array($group['translations'])) {
                    foreach ($group['translations'] as $t_lang => $t_post) {
                        if (!empty($t_post)) {
                            $present_langs[$t_lang] = true;
                        }
                    }
                }
                $item_trans_count = 0;
                foreach ($target_langs as $code => $name) {
                    if (!empty($group['translations'][$code]) || (!empty($present_langs[$code]) && $code !== $default_lang)) {
                        $completed_slots++;
                        $item_trans_count++;
                    }
                }
                if ($item_trans_count < $target_lang_count) {
                    $untranslated_items++;
                }
            }
        }
        $health_pct = ($total_slots > 0) ? min(100, round(($completed_slots / $total_slots) * 100)) : 100;
        if (empty($pages_data) || $target_lang_count === 0) {
            $health_pct = 100;
        }

        // 2. Word usage & license status
        $license_manager = AITRFOEL_License_Manager_Server::get_instance();
        delete_option('aitrfoel_words_used_this_month');
        delete_transient('aitrfoel_license_usage_cache');
        $license_status = $license_manager->get_license_status();
        $words_translated = isset($license_status['words_used']) ? (int) $license_status['words_used'] : (int) ($license_status['words_translated'] ?? 0);
        $license_info = $this->get_license_info();
        $total_words = $license_info['limit'];
        $is_unlimited = ($total_words === -1 || strtolower($license_info['plan'] ?? '') === 'agency lifetime' || !empty($license_status['unlimited']) || !empty($license_status['is_unlimited']));
        $cooldown_end = get_option('aitrfoel_cooldown_end_date', 0);
        $is_cooldown  = (!$is_unlimited && strtolower($license_info['plan'] ?? '') === 'free' && $cooldown_end > 0 && $cooldown_end > time());
        if ($is_unlimited || strtolower($license_info['plan'] ?? '') !== 'free') {
            delete_option('aitrfoel_cooldown_end_date');
        }
        $pct = (!$is_unlimited && $total_words > 0) ? min(100, (int) round(($words_translated / $total_words) * 100)) : 0;
        $reset_label = $is_cooldown ? date_i18n('j M', $cooldown_end) : '';
        $bar_color = $is_unlimited ? '#10b981' : (($pct >= 100 || $is_cooldown) ? '#ef4444' : (($pct >= 80) ? '#f59e0b' : '#3b82f6'));
        $health_color = ($health_pct >= 95) ? '#10b981' : (($health_pct >= 60) ? '#3b82f6' : '#f59e0b');
        ?>
        <div class="aitrfoel-compact-stats-bar">
            <!-- 1. Translation Completeness & Health -->
            <div class="aitrfoel-compact-stat aitrfoel-stat-completeness">
                <div class="aitrfoel-stat-completeness-badge" style="background: <?php echo esc_attr($health_color); ?>18; color: <?php echo esc_attr($health_color); ?>; border-color: <?php echo esc_attr($health_color); ?>35;">
                    <span><?php echo esc_html($health_pct); ?>%</span>
                </div>
                <div class="aitrfoel-stat-completeness-text">
                    <span class="aitrfoel-compact-label"><?php esc_html_e('Translation Progress', 'ai-translator-for-elementor-and-polylang'); ?>:</span>
                    <span class="aitrfoel-compact-value" style="font-size: 13px;">
                        <?php printf(/* translators: %1$d: completed slots, %2$d: total slots */ esc_html__('%1$d / %2$d translated', 'ai-translator-for-elementor-and-polylang'), (int) $completed_slots, (int) $total_slots); ?>
                    </span>
                    <div class="aitrfoel-pool-bar" style="margin-top: 4px; width: 100%; min-width: 130px;">
                        <div class="aitrfoel-pool-bar-fill" style="width:<?php echo esc_attr($health_pct); ?>%; background: <?php echo esc_attr($health_color); ?>;"></div>
                    </div>
                </div>
            </div>

            <!-- 2. Total Content Items -->
            <div class="aitrfoel-compact-stat">
                <span class="aitrfoel-compact-label"><?php esc_html_e('Total Content', 'ai-translator-for-elementor-and-polylang'); ?>:</span>
                <span class="aitrfoel-compact-value"><?php printf(/* translators: %d: total items */ esc_html__('%d items', 'ai-translator-for-elementor-and-polylang'), (int) $total_items); ?></span>
                <?php if ($untranslated_items > 0): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ai-translator-for-elementor-and-polylang-translate-all')); ?>" class="aitrfoel-compact-meta" style="color: #0284c7; text-decoration: none; font-weight: 600; font-size: 11px; margin-top: 2px;">
                        <?php printf(/* translators: %d: untranslated items */ esc_html__('%d remaining →', 'ai-translator-for-elementor-and-polylang'), (int) $untranslated_items); ?>
                    </a>
                <?php else: ?>
                    <span class="aitrfoel-compact-meta" style="color: #10b981; font-weight: 600; font-size: 11px; margin-top: 2px;">
                        <?php esc_html_e('All up to date', 'ai-translator-for-elementor-and-polylang'); ?>
                    </span>
                <?php endif; ?>
            </div>

            <!-- 3. Monthly Word Usage Pool -->
            <div class="aitrfoel-compact-stat aitrfoel-usage-pool-stat <?php echo $is_cooldown ? 'aitrfoel-pool-cooldown' : ($pct >= 80 ? 'aitrfoel-pool-warning' : ''); ?>">
                <div class="aitrfoel-pool-text">
                    <?php if ($is_unlimited): ?>
                        <span class="aitrfoel-pool-label" style="color:#10b981; font-weight:700;"><?php esc_html_e('Unlimited words', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        <span class="aitrfoel-pool-meta"><?php printf(/* translators: %s: number of words translated */ esc_html__('%s words translated', 'ai-translator-for-elementor-and-polylang'), number_format($words_translated)); ?></span>
                    <?php elseif ($is_cooldown): ?>
                        <span class="aitrfoel-pool-label" style="color:#ef4444; font-weight:700;"><?php esc_html_e('Limit reached', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        <span class="aitrfoel-pool-reset"><?php printf(/* translators: %s: when the word quota resets */ esc_html__('Resets %s', 'ai-translator-for-elementor-and-polylang'), esc_html($reset_label)); ?></span>
                    <?php else: ?>
                        <span class="aitrfoel-pool-label"><?php echo esc_html($pct . '%') . ' ' . esc_html__('used this month', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        <span class="aitrfoel-pool-meta"><?php echo esc_html(number_format($words_translated) . ' / ' . number_format($total_words)); ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!$is_unlimited): ?>
                <div class="aitrfoel-pool-bar" style="margin-top: 4px;">
                    <div class="aitrfoel-pool-bar-fill" style="width:<?php echo esc_attr($pct); ?>%; background: <?php echo esc_attr($bar_color); ?>;"></div>
                </div>
                <?php if (empty($license_info['is_pro'])): ?>
                    <?php if (!get_option('aitrfoel_share_bonus_claimed', false)): ?>
                        <a href="#bonus-words" class="aitrfoel-compact-meta" style="color: #0284c7; text-decoration: none; font-weight: 600; font-size: 11px; margin-top: 3px; display: inline-block;">
                            <?php esc_html_e('+2,500 free words →', 'ai-translator-for-elementor-and-polylang'); ?>
                        </a>
                    <?php else: ?>
                        <span class="aitrfoel-compact-meta" style="color: #10b981; font-weight: 600; font-size: 11px; margin-top: 3px; display: inline-block;">
                            <?php esc_html_e('+2,500 bonus active', 'ai-translator-for-elementor-and-polylang'); ?>
                        </span>
                    <?php endif; ?>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- 4. License & Plan Actions -->
            <div class="aitrfoel-compact-stat aitrfoel-compact-license">
                <?php
                $compact_upgrade_url = (function_exists('eatp_fs') && is_object(eatp_fs()) && method_exists(eatp_fs(), 'is_registered') && eatp_fs()->is_registered())
                    ? eatp_fs()->get_upgrade_url()
                    : ((defined('AITRFOEL_VERSION_TYPE') && AITRFOEL_VERSION_TYPE === 'pro')
                        ? 'https://meeptech.com/upgrade?utm_source=wp-plugin&utm_medium=free-plugin&utm_campaign=pro-upgrade'
                        : aitrfoel_free_upgrade_url('dashboard-header'));
                $compact_target = (strpos($compact_upgrade_url, 'meeptech.com') !== false) ? ' target="_blank" rel="noopener noreferrer"' : '';
                ?>
                <div class="aitrfoel-compact-license-info" style="flex-direction: column; align-items: flex-start; gap: 2px;">
                    <div style="display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                        <span class="aitrfoel-compact-label"><?php esc_html_e('License', 'ai-translator-for-elementor-and-polylang'); ?>:</span>
                        <?php if (!empty($license_info['license_expired'])): ?>
                            <span class="aitrfoel-compact-value aitrfoel-license-expired aitrfoel-tooltip" 
                                  style="color: #dc3545; display: inline-flex; align-items: center; gap: 4px;"
                                  title="<?php esc_attr_e('Your license has expired. You have been downgraded to the Free plan.', 'ai-translator-for-elementor-and-polylang'); ?>">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                <span><?php esc_html_e('Expired', 'ai-translator-for-elementor-and-polylang'); ?></span>
                            </span>
                        <?php else: ?>
                            <span class="aitrfoel-compact-value" style="font-weight:700; color:#0f172a;">
                                <?php echo esc_html($license_info['name']); ?>
                            </span>
                            <?php if (!empty($license_info['addon_active'])): ?>
                                <span class="aitrfoel-compact-addon-badge" title="<?php printf(/* translators: %s: number of words in the extra pack */ esc_attr__('Extra %s words pack activated', 'ai-translator-for-elementor-and-polylang'), number_format($license_info['addon_words'])); ?>">
                                    +<?php echo esc_html(number_format($license_info['addon_words'])); ?> <?php esc_html_e('bonus', 'ai-translator-for-elementor-and-polylang'); ?>
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php if (empty($license_info['is_pro']) && empty($license_info['license_expired'])): ?>
                        <a href="<?php echo esc_url($compact_upgrade_url); ?>"<?php echo $compact_target; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string built in this file, no user input ?> class="aitrfoel-compact-meta" style="color: #0284c7; text-decoration: none; font-weight: 600; font-size: 11px; display: inline-block;">
                            <?php esc_html_e('Unlock Claude & DeepL →', 'ai-translator-for-elementor-and-polylang'); ?>
                        </a>
                    <?php endif; ?>
                </div>
                <div class="aitrfoel-compact-actions">
                    <?php if (!empty($license_info['license_expired'])): ?>
                        <a href="<?php echo esc_url($compact_upgrade_url); ?>"<?php echo $compact_target; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string built in this file, no user input ?> class="aitrfoel-compact-buy-btn" style="background: #dc3545; border-color: #dc3545;">
                            <?php esc_html_e('Renew License', 'ai-translator-for-elementor-and-polylang'); ?>
                        </a>
                    <?php elseif (empty($license_info['is_pro'])): ?>
                        <a href="<?php echo esc_url($compact_upgrade_url); ?>"<?php echo $compact_target; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string built in this file, no user input ?> class="aitrfoel-compact-upgrade-btn" title="<?php esc_attr_e('Unlock 50,000+ monthly words, Anthropic Claude & DeepL Pro', 'ai-translator-for-elementor-and-polylang'); ?>">
                            <?php esc_html_e('Upgrade Plan', 'ai-translator-for-elementor-and-polylang'); ?>
                        </a>
                    <?php elseif (!$is_unlimited): ?>
                        <?php if ($pct >= 50): ?>
                            <a href="<?php echo esc_url($compact_upgrade_url); ?>"<?php echo $compact_target; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string built in this file, no user input ?> class="aitrfoel-compact-buy-btn">
                                <?php esc_html_e('Buy More Words', 'ai-translator-for-elementor-and-polylang'); ?>
                            </a>
                        <?php else: ?>
                            <a href="<?php echo esc_url($compact_upgrade_url); ?>"<?php echo $compact_target; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string built in this file, no user input ?> class="aitrfoel-compact-upgrade-btn">
                                <?php esc_html_e('Upgrade Plan', 'ai-translator-for-elementor-and-polylang'); ?>
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render Translation Health & Completeness Meter Widget
     *
     * @param array $pages_data
     */
    private function render_translation_health_meter($pages_data) {
        $multilingual = class_exists('AITRFOEL_Multilingual_Manager') ? AITRFOEL_Multilingual_Manager::get_instance() : null;
        if (!$multilingual || !$multilingual->is_active()) {
            return;
        }

        $all_langs    = $multilingual->get_available_languages();
        $default_lang = $multilingual->get_default_language();

        // Calculate target languages (all active languages minus default)
        $target_langs = [];
        foreach ($all_langs as $code => $lang) {
            if ($code !== $default_lang) {
                $name = is_object($lang) ? ($lang->name ?? strtoupper($code)) : (is_array($lang) ? ($lang['name'] ?? strtoupper($code)) : strtoupper($code));
                $target_langs[$code] = $name;
            }
        }

        $target_lang_count = count($target_langs);
        $pages_data = $this->filter_progress_content($pages_data);
        if ($target_lang_count === 0 || empty($pages_data)) {
            return;
        }

        $total_items        = count($pages_data);
        $total_slots        = $total_items * $target_lang_count;
        $completed_slots    = 0;
        $untranslated_items = 0;

        // Language stats
        $lang_stats = [];
        foreach ($target_langs as $code => $name) {
            $lang_stats[$code] = [
                'name'      => $name,
                'total'     => $total_items,
                'completed' => 0,
                'pct'       => 0,
            ];
        }

        // Category stats
        $form_types    = ['wpcf7_contact_form', 'sureforms_form', 'sf_form', 'wpforms', 'jet-form-builder', 'fluentform', 'formidable', 'ninja_forms'];
        $element_types = ['elementor_library', 'elementor-hf', 'jet-theme-core', 'jet-engine'];

        $cat_data = [
            'pages'     => ['label' => __('Pages', 'ai-translator-for-elementor-and-polylang'), 'total' => 0, 'completed' => 0],
            'posts'     => ['label' => __('Posts', 'ai-translator-for-elementor-and-polylang'), 'total' => 0, 'completed' => 0],
            'products'  => ['label' => __('Products', 'ai-translator-for-elementor-and-polylang'), 'total' => 0, 'completed' => 0],
            'forms'     => ['label' => __('Forms', 'ai-translator-for-elementor-and-polylang'), 'total' => 0, 'completed' => 0],
            'templates' => ['label' => __('Templates', 'ai-translator-for-elementor-and-polylang'), 'total' => 0, 'completed' => 0],
        ];

        foreach ($pages_data as $group) {
            $source = $group['source'];
            $pt = $source->post_type;
            $cat_key = 'pages';
            if ($pt === 'post') {
                $cat_key = 'posts';
            } elseif ($pt === 'product' || $pt === 'sc_product') {
                $cat_key = 'products';
            } elseif (in_array($pt, $form_types, true)) {
                $cat_key = 'forms';
            } elseif (in_array($pt, $element_types, true)) {
                $cat_key = 'templates';
            }

            $cat_data[$cat_key]['total']++;

            $source_lang = $multilingual->get_post_language($source->ID);

            // Group all language codes present in this translation family
            $present_langs = [];
            if ($source_lang) {
                $present_langs[$source_lang] = true;
            }
            if (!empty($group['translations']) && is_array($group['translations'])) {
                foreach ($group['translations'] as $t_lang => $t_post) {
                    if (!empty($t_post)) {
                        $present_langs[$t_lang] = true;
                    }
                }
            }

            $item_translations_count = 0;
            foreach ($target_langs as $code => $name) {
                if (!empty($group['translations'][$code]) || (!empty($present_langs[$code]) && $code !== $default_lang)) {
                    $completed_slots++;
                    $item_translations_count++;
                    $lang_stats[$code]['completed']++;
                    $cat_data[$cat_key]['completed']++;
                }
            }

            if ($item_translations_count < $target_lang_count) {
                $untranslated_items++;
            }
        }

        foreach ($lang_stats as $code => &$stats) {
            $stats['pct'] = ($stats['total'] > 0) ? round(($stats['completed'] / $stats['total']) * 100) : 0;
        }
        unset($stats);

        $health_pct = ($total_slots > 0) ? min(100, round(($completed_slots / $total_slots) * 100)) : 100;

        // Determine score theme
        if ($health_pct >= 95) {
            $score_theme = 'green';
            $score_label = __('Site Fully Translated', 'ai-translator-for-elementor-and-polylang');
            $score_desc  = __('All pages, posts, templates and forms are synchronized.', 'ai-translator-for-elementor-and-polylang');
        } elseif ($health_pct >= 70) {
            $score_theme = 'blue';
            $score_label = __('Good Translation Health', 'ai-translator-for-elementor-and-polylang');
            $score_desc  = sprintf(
                /* translators: %d: number of items */
                __('%d items still need translation across your target languages.', 'ai-translator-for-elementor-and-polylang'),
                $untranslated_items
            );
        } elseif ($health_pct >= 40) {
            $score_theme = 'amber';
            $score_label = __('Translations In Progress', 'ai-translator-for-elementor-and-polylang');
            $score_desc  = sprintf(
                /* translators: %d: number of items */
                __('%d items waiting for translation.', 'ai-translator-for-elementor-and-polylang'),
                $untranslated_items
            );
        } else {
            $score_theme = 'orange';
            $score_label = __('Content Needs Translation', 'ai-translator-for-elementor-and-polylang');
            $score_desc  = sprintf(
                /* translators: %d: number of items */
                __('%d items waiting to be translated.', 'ai-translator-for-elementor-and-polylang'),
                $untranslated_items
            );
        }

        $flag_map = [
            'en' => 'gb', 'es' => 'es', 'fr' => 'fr', 'de' => 'de', 'it' => 'it',
            'pt' => 'pt', 'nl' => 'nl', 'pl' => 'pl', 'ru' => 'ru', 'ja' => 'jp',
            'zh' => 'cn', 'ko' => 'kr', 'ar' => 'sa', 'tr' => 'tr', 'hi' => 'in',
            'sv' => 'se', 'uk' => 'ua', 'da' => 'dk', 'fi' => 'fi', 'nb' => 'no',
            'no' => 'no', 'cs' => 'cz', 'el' => 'gr', 'he' => 'il', 'ro' => 'ro',
            'hu' => 'hu', 'bg' => 'bg', 'sk' => 'sk', 'hr' => 'hr', 'sr' => 'rs',
        ];
        ?>
        <div class="aitrfoel-health-meter-card aitrfoel-health-theme-<?php echo esc_attr($score_theme); ?>">
            <div class="aitrfoel-health-header">
                <div class="aitrfoel-health-summary">
                    <div class="aitrfoel-health-badge-wrap">
                        <div class="aitrfoel-health-badge">
                            <span class="aitrfoel-health-score-val"><?php echo esc_html($health_pct); ?>%</span>
                        </div>
                    </div>
                    <div class="aitrfoel-health-info">
                        <div class="aitrfoel-health-title-row">
                            <h3 class="aitrfoel-health-title"><?php echo esc_html($score_label); ?></h3>
                            <span class="aitrfoel-health-items-pill"><?php printf(/* translators: %1$d: completed slots, %2$d: total slots */ esc_html__('%1$d/%2$d translated', 'ai-translator-for-elementor-and-polylang'), (int) $completed_slots, (int) $total_slots); ?></span>
                        </div>
                        <p class="aitrfoel-health-desc"><?php echo esc_html($score_desc); ?></p>
                    </div>
                </div>

                <div class="aitrfoel-health-actions">
                    <?php if ($untranslated_items > 0): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=ai-translator-for-elementor-and-polylang-translate-all')); ?>" class="button button-primary aitrfoel-health-cta-btn">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                            <span><?php printf(/* translators: %d: untranslated items */ esc_html__('Translate Remaining (%d items)', 'ai-translator-for-elementor-and-polylang'), (int) $untranslated_items); ?></span>
                        </a>
                    <?php else: ?>
                        <div class="aitrfoel-health-complete-badge">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                            <span><?php esc_html_e('100% Up to Date', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Global Health Progress Bar -->
            <div class="aitrfoel-health-track-wrap">
                <div class="aitrfoel-health-track">
                    <div class="aitrfoel-health-fill" style="width: <?php echo esc_attr($health_pct); ?>%;"></div>
                </div>
            </div>

            <!-- Language Breakdown & Category Chips -->
            <div class="aitrfoel-health-details-grid">
                <div class="aitrfoel-health-languages">
                    <?php foreach ($lang_stats as $code => $lstat): 
                        $flag_code = $flag_map[$code] ?? substr($code, 0, 2);
                    ?>
                        <div class="aitrfoel-health-lang-pill" title="<?php printf(/* translators: %1$s: language name, %2$d: translated items, %3$d: total items, %4$d: percentage translated */ esc_attr__('%1$s: %2$d of %3$d translated (%4$d%%)', 'ai-translator-for-elementor-and-polylang'), esc_attr($lstat['name']), (int) $lstat['completed'], (int) $lstat['total'], (int) $lstat['pct']); ?>">
                            <img class="aitrfoel-health-lang-flag" src="<?php echo esc_url(class_exists('AITRFOEL_Language_Mapper') ? AITRFOEL_Language_Mapper::get_flag_url_for_country($flag_code, 40) : 'https://flagcdn.com/w40/' . $flag_code . '.png'); ?>" width="16" height="12" alt="<?php echo esc_attr($code); ?>">
                            <span class="aitrfoel-health-lang-code"><?php echo esc_html(strtoupper($code)); ?></span>
                            <div class="aitrfoel-health-lang-mini-track">
                                <div class="aitrfoel-health-lang-mini-fill" style="width: <?php echo esc_attr($lstat['pct']); ?>%;"></div>
                            </div>
                            <span class="aitrfoel-health-lang-pct"><?php echo esc_html($lstat['pct']); ?>%</span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="aitrfoel-health-categories">
                    <?php foreach ($cat_data as $ckey => $cinfo): 
                        if ($cinfo['total'] === 0) continue;
                        $cat_slots = $cinfo['total'] * $target_lang_count;
                        $cat_pct   = ($cat_slots > 0) ? round(($cinfo['completed'] / $cat_slots) * 100) : 100;
                    ?>
                        <div class="aitrfoel-health-cat-chip">
                            <span class="aitrfoel-health-cat-name"><?php echo esc_html($cinfo['label']); ?></span>
                            <span class="aitrfoel-health-cat-pct <?php echo $cat_pct >= 100 ? 'complete' : ''; ?>"><?php echo esc_html($cat_pct); ?>%</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render plugin header with breadcrumb navigation
     */
    public function render_plugin_header($current_page = 'Dashboard') {
        // Dynamic plugin name based on active plan status
        $license_info = $this->get_license_info();
        $is_pro_active = !empty($license_info['is_pro']);
        $plugin_name = $is_pro_active 
            ? __('Meep AI Translator', 'ai-translator-for-elementor-and-polylang')
            : __('Meep AI Translator', 'ai-translator-for-elementor-and-polylang');

        $to_lower = static function ($str) {
            return function_exists('mb_strtolower') ? mb_strtolower($str, 'UTF-8') : strtolower($str);
        };
        $normalized_page = $to_lower(trim(wp_strip_all_tags((string) $current_page)));
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
                'title' => sprintf(
                    /* translators: %1$s: opening span tag, %2$s: closing span tag */
                    __('Configure your %1$stranslation workspace%2$s.', 'ai-translator-for-elementor-and-polylang'),
                    '<span class="accent">',
                    '</span>'
                ),
                'subtitle' => __('Set up languages, switcher behavior, SEO translation, WooCommerce options, glossary, and advanced rules.', 'ai-translator-for-elementor-and-polylang'),
            ],
            'translation history' => [
                'eyebrow' => __('translation history', 'ai-translator-for-elementor-and-polylang'),
                'title' => sprintf(
                    /* translators: %1$s: opening span tag, %2$s: closing span tag */
                    __('Review your %1$stranslation activity%2$s.', 'ai-translator-for-elementor-and-polylang'),
                    '<span class="accent">',
                    '</span>'
                ),
                'subtitle' => __('Track completed translations, monitor usage trends, and inspect your recent translation records.', 'ai-translator-for-elementor-and-polylang'),
            ],
            'translate all' => [
                'eyebrow' => __('translate all', 'ai-translator-for-elementor-and-polylang'),
                'title' => sprintf(
                    /* translators: %1$s: opening span tag, %2$s: closing span tag */
                    __('Translate your full site %1$sin bulk%2$s.', 'ai-translator-for-elementor-and-polylang'),
                    '<span class="accent">',
                    '</span>'
                ),
                'subtitle' => __('Select post types and target languages, estimate words, and launch or schedule large translation batches.', 'ai-translator-for-elementor-and-polylang'),
            ],
            'review' => [
                'eyebrow' => __('review translation', 'ai-translator-for-elementor-and-polylang'),
                'title' => sprintf(
                    /* translators: %1$s: opening span tag, %2$s: closing span tag */
                    __('Review and refine your %1$stranslated content%2$s.', 'ai-translator-for-elementor-and-polylang'),
                    '<span class="accent">',
                    '</span>'
                ),
                'subtitle' => __('Inspect each translated segment side by side with the source. Click any cell to edit and save changes directly.', 'ai-translator-for-elementor-and-polylang'),
            ],
            'activate license' => [
                'eyebrow' => __('activate license', 'ai-translator-for-elementor-and-polylang'),
                'title' => sprintf(
                    /* translators: %1$s: opening span tag, %2$s: closing span tag */
                    __('Activate your %1$slicense key%2$s.', 'ai-translator-for-elementor-and-polylang'),
                    '<span class="accent">',
                    '</span>'
                ),
                'subtitle' => __('Enter your license key to unlock higher word limits, premium features, and automatic updates.', 'ai-translator-for-elementor-and-polylang'),
            ],
            'clear history' => [
                'eyebrow' => __('clear history', 'ai-translator-for-elementor-and-polylang'),
                'title' => sprintf(
                    /* translators: %1$s: opening span tag, %2$s: closing span tag */
                    __('Reset and clear %1$stranslation data%2$s.', 'ai-translator-for-elementor-and-polylang'),
                    '<span class="accent">',
                    '</span>'
                ),
                'subtitle' => __('Clear translation cache, usage statistics, and temporary plugin options.', 'ai-translator-for-elementor-and-polylang'),
            ],
        ];

        // Callers pass translated labels (e.g. __('Settings')), so on non-English admin screens the
        // English keys above never matched and every page showed the Dashboard hero. Map labels back.
        $label_aliases = [
            'dashboard'           => [__('Dashboard', 'ai-translator-for-elementor-and-polylang')],
            'settings'            => [__('Settings', 'ai-translator-for-elementor-and-polylang')],
            'translation history' => [__('Translation History', 'ai-translator-for-elementor-and-polylang'), 'history'],
            'translate all'       => [__('Translate All', 'ai-translator-for-elementor-and-polylang')],
            'review'              => [__('Review Translations', 'ai-translator-for-elementor-and-polylang'), 'review translations'],
            'activate license'    => [__('Activate License', 'ai-translator-for-elementor-and-polylang')],
            'clear history'       => [__('Clear History', 'ai-translator-for-elementor-and-polylang')],
        ];
        if (!isset($hero_text_map[$normalized_page])) {
            foreach ($label_aliases as $key => $labels) {
                foreach ($labels as $label) {
                    if ($to_lower(trim((string) $label)) === $normalized_page) {
                        $normalized_page = $key;
                        break 2;
                    }
                }
            }
        }

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
    public function render_quick_tips() {
        ?>
        <!-- Information & Review Section -->
        <div class="aitrfoel-card aitrfoel-info-review-card">
            <div class="aitrfoel-info-review-grid">
                <!-- Left Column: Information -->
                <div class="aitrfoel-info-column">
                    <div class="aitrfoel-info-header">
                        <span class="aitrfoel-info-icon" aria-hidden="true" style="display:inline-flex;align-items:center;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/></svg>
                        </span>
                        <h3><?php esc_html_e('Support & Resources', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    </div>
                    <div class="aitrfoel-info-content">
                        <p><?php esc_html_e('We continuously improve our translation algorithms. If you have questions, feedback, or need help with any page, we are here for you.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                        <div class="aitrfoel-info-buttons">
                            <a href="https://meeptech.com/contact" target="_blank" rel="noopener noreferrer" class="button aitrfoel-btn-support" style="display:inline-flex;align-items:center;gap:6px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                <span><?php esc_html_e('Feedback & Support', 'ai-translator-for-elementor-and-polylang'); ?></span>
                            </a>
                            <a href="https://meeptech.com/products/ai-translator-for-elementor-polylang/documentation" target="_blank" rel="noopener noreferrer" class="button aitrfoel-btn-docs" style="display:inline-flex;align-items:center;gap:6px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                                <span><?php esc_html_e('Documentation', 'ai-translator-for-elementor-and-polylang'); ?></span>
                            </a>
                            <?php $footer_lic = $this->get_license_info(); ?>
                            <?php if (empty($footer_lic['is_pro']) || !empty($footer_lic['license_expired'])): ?>
                            <a href="<?php echo (function_exists('eatp_fs') && is_object(eatp_fs()) && method_exists(eatp_fs(), 'is_registered') && eatp_fs()->is_registered()) ? esc_url(eatp_fs()->get_upgrade_url()) : esc_url('https://meeptech.com/upgrade?utm_source=wp-plugin&utm_medium=free-plugin&utm_campaign=pro-upgrade'); ?>" class="button button-primary aitrfoel-btn-upgrade-highlight" style="display:inline-flex;align-items:center;gap:6px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                                <span><?php esc_html_e('Upgrade Plan', 'ai-translator-for-elementor-and-polylang'); ?></span>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column: Review Request -->
                <div class="aitrfoel-review-column">
                    <div class="aitrfoel-review-header">
                        <span class="aitrfoel-review-icon" aria-hidden="true" style="display:inline-flex;align-items:center;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="#eab308" stroke="#eab308" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                        </span>
                        <h3><?php esc_html_e('Love Our Plugin?', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    </div>
                    <div class="aitrfoel-review-content">
                        <p><?php esc_html_e('If you enjoy using Meep AI Translator, please take a moment to rate us on WordPress.org! Your feedback helps us continue improving the plugin.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                        <div class="aitrfoel-stars-rating" aria-hidden="true" style="display:flex;gap:4px;color:#eab308;margin:0 0 14px 0;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                        </div>
                        <span class="screen-reader-text"><?php esc_html_e('Rated 5 out of 5 on WordPress.org', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        <a href="https://wordpress.org/support/plugin/ai-translator-for-elementor-and-polylang/reviews/#new-post" target="_blank" rel="noopener noreferrer" class="button button-primary aitrfoel-btn-rate-gold" style="display:inline-flex;align-items:center;gap:6px;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            <span><?php esc_html_e('Leave a Review', 'ai-translator-for-elementor-and-polylang'); ?></span>
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
            echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__('Translation History Cleared!', 'ai-translator-for-elementor-and-polylang') . '</strong></p><ul>';
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
        <div class="wrap aitrfoel-admin-wrapper">
            <?php $this->render_plugin_header(__('Clear History', 'ai-translator-for-elementor-and-polylang')); ?>
            
            <div class="aitrfoel-card" style="max-width: 800px; margin-top: 20px;">
                <h2>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:6px;" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <?php esc_html_e('Warning: Destructive Action', 'ai-translator-for-elementor-and-polylang'); ?>
                </h2>
                
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
                    <p style="margin: 10px 0 0 0; font-size: 13px; color: #856404; display: flex; align-items: center; gap: 6px;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <span><?php esc_html_e('This is useful for testing but will increase API costs as translations will not be cached.', 'ai-translator-for-elementor-and-polylang'); ?></span>
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
                                    echo '<span style="color: #dc3232; display: inline-flex; align-items: center; gap: 4px;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> ' . sprintf(esc_html__('Active (%s remaining)', 'ai-translator-for-elementor-and-polylang'), esc_html($remaining)) . '</span>';
                                } else {
                                    echo '<span style="color: #16a34a; display: inline-flex; align-items: center; gap: 4px;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> ' . esc_html__('Not active', 'ai-translator-for-elementor-and-polylang') . '</span>';
                                }
                                ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <form method="post" action="" onsubmit="return confirm('<?php echo esc_js(__('Are you ABSOLUTELY SURE you want to delete ALL translation history? This cannot be undone!', 'ai-translator-for-elementor-and-polylang')); ?>');">
                    <?php wp_nonce_field('aitrfoel_clear_history_action', 'aitrfoel_clear_history_nonce'); ?>
                    
                    <p>
                        <button type="submit" name="aitrfoel_clear_history_submit" class="button button-primary button-large" style="background: #dc3232; border-color: #dc3232; text-shadow: none; box-shadow: none; display: inline-flex; align-items: center; gap: 6px;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                            <span><?php esc_html_e('Clear All Translation History', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </button>
                    </p>
                    
                    <p class="description">
                        <?php esc_html_e('This is a temporary debug tool to help test translations. Use with caution!', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                </form>
                
                <hr style="margin: 30px 0;">
                
                <h3 style="display: flex; align-items: center; gap: 6px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/></svg>
                    <span><?php esc_html_e('What happens after clearing?', 'ai-translator-for-elementor-and-polylang'); ?></span>
                </h3>
                <ul style="line-height: 1.8;">
                    <li style="display: flex; align-items: center; gap: 6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> <?php esc_html_e('You can immediately start new translations', 'ai-translator-for-elementor-and-polylang'); ?></li>
                    <li style="display: flex; align-items: center; gap: 6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> <?php esc_html_e('Cooldown period will be removed', 'ai-translator-for-elementor-and-polylang'); ?></li>
                    <li style="display: flex; align-items: center; gap: 6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> <?php esc_html_e('Word count limit resets to 0', 'ai-translator-for-elementor-and-polylang'); ?></li>
                    <li style="display: flex; align-items: center; gap: 6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> <?php esc_html_e('All translations will be fetched from OpenAI API again (costs money)', 'ai-translator-for-elementor-and-polylang'); ?></li>
                    <li style="display: flex; align-items: center; gap: 6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> <?php esc_html_e('Previously translated pages remain unchanged', 'ai-translator-for-elementor-and-polylang'); ?></li>
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
                        printf(esc_html__('%1$d of %2$d completed', 'ai-translator-for-elementor-and-polylang'), (int) $completed_count, (int) $total_count);
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
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="aitrfoel-setup-check-svg"><path d="M5 12l5 5L20 7"/></svg>
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
                'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- intentional: Polylang/WPML query filters would hide posts in other languages, which the translator must see
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
            'action_url'  => admin_url('admin.php?page=ai-translator-for-elementor-and-polylang-settings#tab-languages'),
            'action_label'=> __('Set Up Switcher', 'ai-translator-for-elementor-and-polylang'),
        ];

        // 5. Smart Translation Memory
        $tm_enabled = (bool) get_option('aitrfoel_enable_translation_memory', true);
        $items[] = [
            'key'         => 'translation_memory',
            'title'       => __('Smart Translation Memory', 'ai-translator-for-elementor-and-polylang'),
            'description' => __('Automatically reuses repeated phrases to reduce API load and save word quota.', 'ai-translator-for-elementor-and-polylang'),
            'complete'    => $tm_enabled || in_array('translation_memory', $skipped),
            'skipped'     => in_array('translation_memory', $skipped),
            'action_url'  => admin_url('admin.php?page=ai-translator-for-elementor-and-polylang-settings#tab-glossary'),
            'action_label'=> __('View Memory', 'ai-translator-for-elementor-and-polylang'),
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
            __('Meep Translator - Setup Checklist', 'ai-translator-for-elementor-and-polylang'),
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
                printf(esc_html__('%1$d of %2$d steps completed', 'ai-translator-for-elementor-and-polylang'), (int) $completed_count, (int) $total_count);
                ?>
            </p>
            <ul class="aitrfoel-setup-checklist-items">
                <?php foreach ($checklist as $item): ?>
                <li class="aitrfoel-setup-checklist-item <?php echo $item['complete'] ? 'is-complete' : ''; ?>">
                    <span class="aitrfoel-setup-check-icon">
                        <?php if ($item['complete']): ?>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="aitrfoel-setup-check-svg"><path d="M5 12l5 5L20 7"/></svg>
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
            <p style="margin: 8px 0 0;"><a href="<?php echo esc_url(admin_url('admin.php?page=ai-translator-for-elementor-and-polylang')); ?>"><?php esc_html_e('Go to Meep Translator Dashboard', 'ai-translator-for-elementor-and-polylang'); ?></a></p>
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