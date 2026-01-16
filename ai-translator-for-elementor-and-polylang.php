<?php
/**
 * Plugin Name: AI Translator for Elementor and Polylang
 * Plugin URI: https://meeptech.com/products/ai-translator-for-elementor-and-polylang
 * Description: AI-powered translation for Elementor, Gutenberg, and Classic Editor pages with Polylang or WPML using OpenAI
 * Version: 1.2.0
 * Author: Meeptech
 * Author URI: https://meeptech.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-translator-for-elementor-and-polylang
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Tested up to: 6.9
 * Elementor tested up to: 3.18.0
 * Elementor Pro tested up to: 3.18.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('AITRFOEL_VERSION', '1.2.0');
define('AITRFOEL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AITRFOEL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AITRFOEL_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Version Type
if (!defined('AITRFOEL_VERSION_TYPE')) {
    define('AITRFOEL_VERSION_TYPE', 'free');
}

// Feature Flags for Free Version
if (!defined('AITRFOEL_FEATURES')) {
    define('AITRFOEL_FEATURES', array(
        'bulk_translation' => false,
        'auto_translate' => false,
        'advanced_cache' => false,
        'freemius' => false
    ));
}


// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

    /**
     * Main Plugin Class
 */
class AITRFOEL_Main {
    
    /**
     * Instance
     */
    private static $instance = null;
    
    /**
     * Get instance
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
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Check dependencies
        add_action('plugins_loaded', [$this, 'check_dependencies']);
        
        // Load plugin
        add_action('plugins_loaded', [$this, 'load_plugin']);
        
        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        // Note: load_plugin_textdomain() removed - WordPress 4.6+ automatically loads
        // translations for plugins hosted on WordPress.org
        
        // Enqueue scripts
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        // Hide Elementor admin elements on our plugin pages
        add_action('admin_init', [$this, 'disable_elementor_admin_ui']);
        
        // Welcome popup (shown once after activation)
        add_action('admin_footer', [$this, 'render_welcome_popup']);
        add_action('wp_ajax_aitrfoel_dismiss_welcome', [$this, 'dismiss_welcome_popup']);
        
        // Test analytics (for debugging) - add ?test_analytics=1 to plugin URL
        add_action('admin_init', [$this, 'maybe_test_analytics']);
        
        // Removed: Background processing (using direct processing now)
        // add_action('aitrfoel_process_bulk_translation', [$this, 'process_bulk_translation_background'], 10, 4);
    }
    
    /**
     * Test analytics if URL parameter is set
     */
    public function maybe_test_analytics() {
        if (isset($_GET['test_analytics']) && $_GET['test_analytics'] === '1' && current_user_can('manage_options')) {
            if (function_exists('aitrfoel_analytics')) {
                $result = aitrfoel_analytics()->send_test_event();
                add_action('admin_notices', function() use ($result) {
                    $class = $result ? 'notice-success' : 'notice-error';
                    $message = $result ? 'PostHog test event sent successfully! Check your PostHog dashboard.' : 'PostHog test event failed. Check wp-content/debug.log for details.';
                    echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
                });
            }
        }
    }
    
    /**
     * Check plugin dependencies
     * @param bool $show_notices Whether to show admin notices
     * @return array Array with 'status' (bool) and 'missing' (array of missing dependencies)
     */
    public function check_dependencies($show_notices = true) {
        $missing = [];
        
        // Check if Elementor is active
        if (!did_action('elementor/loaded')) {
            $missing[] = 'elementor';
            if ($show_notices) {
                add_action('admin_notices', [$this, 'admin_notice_elementor_missing']);
            }
        }
        
        // Check Elementor version (only if Elementor is loaded)
        if (did_action('elementor/loaded') && !version_compare(ELEMENTOR_VERSION, '3.0.0', '>=')) {
            $missing[] = 'elementor_version';
            if ($show_notices) {
                add_action('admin_notices', [$this, 'admin_notice_elementor_version']);
            }
        }
        
        // Check if a multilingual plugin (Polylang or WPML) is active
        $has_polylang = function_exists('pll_get_post_language');
        $has_wpml = defined('ICL_SITEPRESS_VERSION') || class_exists('SitePress');
        
        if (!$has_polylang && !$has_wpml) {
            $missing[] = 'multilingual';
            if ($show_notices) {
                add_action('admin_notices', [$this, 'admin_notice_multilingual_missing']);
            }
        }
        
        // Check PHP version
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            $missing[] = 'php_version';
            if ($show_notices) {
                add_action('admin_notices', [$this, 'admin_notice_php_version']);
            }
        }
        
        return [
            'status' => empty($missing),
            'missing' => $missing
        ];
    }
    
    /**
     * Load plugin components
     */
    public function load_plugin() {
        $deps = $this->check_dependencies(true);
        
        // Store dependencies status early for the dashboard
        update_option('aitrfoel_dependencies_status', $deps);
        
        // Always load admin menu so plugin is visible, even when dependencies aren't met
        if (is_admin()) {
            require_once AITRFOEL_PLUGIN_DIR . 'admin/class-admin-menu.php';
            require_once AITRFOEL_PLUGIN_DIR . 'admin/class-settings.php';
            
            // Initialize admin menu directly (not in a hook since we're already in plugins_loaded)
            AITRFOEL_Admin_Menu::get_instance();
        }
        
        // If dependencies are not met, don't load the rest of the plugin
        if (!$deps['status']) {
            return;
        }
        
        // Load core classes
        $this->load_includes();
        
        // Initialize components
        $this->init_components();
    }
    
    /**
     * Load include files
     */
    private function load_includes() {
        // Core includes - Load logger FIRST (used by many classes)
        require_once AITRFOEL_PLUGIN_DIR . 'includes/class-logger.php';
        
        // Load language mapper and widget parser
        require_once AITRFOEL_PLUGIN_DIR . 'includes/class-language-mapper.php';
        
        $widget_parser_file = AITRFOEL_PLUGIN_DIR . 'includes/class-widget-parser.php';
        if (!file_exists($widget_parser_file)) {
            error_log('AITRFOEL Fatal: Widget parser file not found at: ' . $widget_parser_file);
            throw new Exception('Widget parser file not found');
        }
        require_once $widget_parser_file;
        
        // Verify class was loaded
        if (!class_exists('AITRFOEL_Widget_Parser')) {
            error_log('AITRFOEL Fatal: AITRFOEL_Widget_Parser class not defined after loading file');
            throw new Exception('AITRFOEL_Widget_Parser class not defined');
        }
        
        require_once AITRFOEL_PLUGIN_DIR . 'includes/class-openai-api.php';
        require_once AITRFOEL_PLUGIN_DIR . 'includes/class-post-processor.php';
        require_once AITRFOEL_PLUGIN_DIR . 'includes/class-gap-filler.php';
        require_once AITRFOEL_PLUGIN_DIR . 'includes/class-translation-engine.php';
        require_once AITRFOEL_PLUGIN_DIR . 'includes/class-theme-compatibility.php';
        require_once AITRFOEL_PLUGIN_DIR . 'includes/class-cache-manager.php';
        
        // Content type detection and parsers for different editors
        require_once AITRFOEL_PLUGIN_DIR . 'includes/class-content-detector.php';
        require_once AITRFOEL_PLUGIN_DIR . 'includes/class-classic-parser.php';
        require_once AITRFOEL_PLUGIN_DIR . 'includes/class-gutenberg-parser.php';
        
        // Load debug logger if it exists
        if (file_exists(AITRFOEL_PLUGIN_DIR . 'includes/class-debug-logger.php')) {
            require_once AITRFOEL_PLUGIN_DIR . 'includes/class-debug-logger.php';
        }
        
        require_once AITRFOEL_PLUGIN_DIR . 'includes/class-usage-tracker.php';
        require_once AITRFOEL_PLUGIN_DIR . 'includes/class-bulk-translation-queue.php';
        require_once AITRFOEL_PLUGIN_DIR . 'includes/class-license-manager-server.php';
        require_once AITRFOEL_PLUGIN_DIR . 'includes/class-license-manager.php'; // Backward compatibility alias
        require_once AITRFOEL_PLUGIN_DIR . 'includes/class-api-client.php';
        
        // Admin includes (may already be loaded in load_plugin for dependency-failed cases)
        if (is_admin()) {
            if (!class_exists('AITRFOEL_Admin_Menu')) {
                require_once AITRFOEL_PLUGIN_DIR . 'admin/class-admin-menu.php';
            }
            if (!class_exists('AITRFOEL_Settings')) {
                require_once AITRFOEL_PLUGIN_DIR . 'admin/class-settings.php';
            }
        }
        
        // AJAX handler must be loaded for both admin and frontend AJAX requests
        require_once AITRFOEL_PLUGIN_DIR . 'admin/class-ajax-handler.php';
        
        // Load multilingual integrations (abstract interface, manager, and implementations)
        require_once AITRFOEL_PLUGIN_DIR . 'includes/class-multilingual-interface.php';
        require_once AITRFOEL_PLUGIN_DIR . 'includes/class-polylang-integration.php';
        require_once AITRFOEL_PLUGIN_DIR . 'includes/class-wpml-integration.php';
        require_once AITRFOEL_PLUGIN_DIR . 'includes/class-multilingual-manager.php';
        
        // Load analytics (PostHog integration)
        require_once AITRFOEL_PLUGIN_DIR . 'includes/class-analytics.php';
        
        // Debug tools removed - plugin simplified
    }
    
    /**
     * Initialize plugin components
     */
    private function init_components() {
        try {
            // PERFORMANCE: Disable Freemius background sync during AJAX translation requests
            $this->maybe_disable_freemius_sync();
            
            // Initialize core components
            AITRFOEL_Widget_Parser::get_instance();
            AITRFOEL_License_Manager_Server::get_instance(); // Initialize server-based license manager
            AITRFOEL_OpenAI_API::get_instance();
            AITRFOEL_Translation_Engine::get_instance();
            AITRFOEL_Cache_Manager::get_instance();
            AITRFOEL_Logger::get_instance();
            
            // Initialize debug logger if available
            if (class_exists('AITRFOEL_Debug_Logger')) {
                AITRFOEL_Debug_Logger::get_instance();
            }
            
            AITRFOEL_Usage_Tracker::get_instance(); // Initialize usage tracker
            AITRFOEL_Bulk_Translation_Queue::get_instance(); // Initialize bulk translation queue
            
            // Initialize admin with error checking
            if (is_admin()) {
                AITRFOEL_Admin_Menu::get_instance();
                AITRFOEL_Settings::init();
            }
            
            // Initialize AJAX handler (needed for both admin and frontend AJAX requests)
            if (class_exists('AITRFOEL_Ajax_Handler')) {
                AITRFOEL_Ajax_Handler::get_instance();
            } else {
                error_log('EAT Error: AITRFOEL_Ajax_Handler class not found - using fallback');
            }
            
            // Initialize multilingual integration manager (auto-detects Polylang or WPML)
            AITRFOEL_Multilingual_Manager::get_instance();
            
            // Initialize analytics (PostHog)
            AITRFOEL_Analytics::get_instance();
            
        } catch (Exception $e) {
            error_log('EAT Fatal Error during component initialization: ' . $e->getMessage());
            add_action('admin_notices', function() use ($e) {
                echo '<div class="notice notice-error"><p><strong>AI Translator for Elementor Error:</strong> ' . esc_html($e->getMessage()) . '</p></div>';
            });
        } catch (Error $e) {
            error_log('EAT Fatal PHP Error during component initialization: ' . $e->getMessage());
            add_action('admin_notices', function() use ($e) {
                echo '<div class="notice notice-error"><p><strong>AI Translator for Elementor Fatal Error:</strong> ' . esc_html($e->getMessage()) . '</p></div>';
            });
        }
    }
    
    /**
     * Disable Elementor admin UI elements on our plugin pages
     */
    public function disable_elementor_admin_ui() {
        // Check if we're on one of our plugin pages
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }
        
        // Check if current page is ANY of our plugin pages (including Freemius pages)
        $page = isset($_GET['page']) ? $_GET['page'] : '';
        $is_our_page = (
            strpos($screen->id, 'ai-translator-for-elementor-and-polylang') !== false ||
            strpos($page, 'ai-translator-for-elementor-and-polylang') !== false ||
            $screen->id === 'admin_page_aitrfoel-debug'
        );
        
        if ($is_our_page) {
            // Add immediate CSS to prevent flashing
            add_action('admin_head', [$this, 'add_immediate_elementor_hiding'], 1);

            // Remove Elementor admin top bar actions
            remove_action('admin_notices', '\Elementor\Core\Admin\Admin_Notices::admin_notices');
            remove_action('admin_bar_menu', '\Elementor\Core\Admin\Menu\Admin_Menu_Manager::add_menu', 200);

            // Remove Elementor footer text
            add_filter('admin_footer_text', [$this, 'restore_admin_footer'], 1000);

            // Prevent Elementor from loading its admin styles/scripts on our pages
            remove_action('admin_enqueue_scripts', '\Elementor\Core\Admin\UI\Components\Form_Manager::enqueue_form_manager_scripts');

            // More aggressive: Prevent Elementor admin completely on our pages
            add_action('admin_enqueue_scripts', [$this, 'prevent_elementor_admin_scripts'], 1);

            // Add custom CSS to hide any remaining Elementor elements
            add_action('admin_head', [$this, 'hide_elementor_admin_elements']);
        }
    }
    
    /**
     * PERFORMANCE: Disable Freemius background sync during AJAX translation requests
     */
    private function maybe_disable_freemius_sync() {
        // Check if this is a translation AJAX request
        if ( defined('DOING_AJAX') && DOING_AJAX && isset($_REQUEST['action']) ) {
            $translation_actions = [
                'aitrfoel_translate_page',
                'aitrfoel_bulk_translate',
                'aitrfoel_get_translation_preview',
                'aitrfoel_detect_language',
                'aitrfoel_get_page_strings'
            ];
            
            if ( in_array($_REQUEST['action'], $translation_actions) ) {
                // Disable Freemius background sync during translation
                add_filter( 'fs_is_long_term_user', '__return_false', 999 );
                add_filter( 'fs_is_submenu_visible', '__return_false', 999 );
                
                // Prevent any Freemius API calls during translation
                if ( function_exists('eatp_fs') && isset($GLOBALS['eatp_fs']) ) {
                    // Skip connectivity tests
                    add_filter( 'fs_skip_connectivity_test__20522', '__return_true', 999 );
                }
            }
        }
    }
    
    /**
     * Prevent Elementor from loading admin scripts on our pages
     */
    public function prevent_elementor_admin_scripts() {
        // Get current screen
        $screen = get_current_screen();
        $page = isset($_GET['page']) ? $_GET['page'] : '';

        $is_our_page = (
            strpos($screen->id, 'ai-translator-for-elementor-and-polylang') !== false ||
            strpos($page, 'ai-translator-for-elementor-and-polylang') !== false ||
            $screen->id === 'admin_page_aitrfoel-debug'
        );

        if ($is_our_page) {
            // Dequeue Elementor admin scripts and styles
            wp_dequeue_script('elementor-admin-top-bar');
            wp_dequeue_script('elementor-admin');
            wp_dequeue_script('elementor-admin-feedback');
            wp_dequeue_script('elementor-admin-notifications');
            wp_dequeue_style('elementor-admin-top-bar');
            wp_dequeue_style('elementor-admin');

            // Prevent Elementor admin bar from initializing
            if (class_exists('\Elementor\Core\Admin\Admin_Notices')) {
                remove_action('admin_notices', [\Elementor\Core\Admin\Admin_Notices::instance(), 'admin_notices']);
            }

            // Remove Elementor admin menu
            if (class_exists('\Elementor\Core\Admin\Menu\Admin_Menu_Manager')) {
                remove_action('admin_bar_menu', [\Elementor\Core\Admin\Menu\Admin_Menu_Manager::instance(), 'add_menu'], 200);
            }
        }
    }

    /**
     * Add immediate CSS to hide Elementor elements before they render
     */
    public function add_immediate_elementor_hiding() {
        // Enqueue a minimal inline style early
        wp_register_style('aitrfoel-elementor-hiding-early', false);
        wp_enqueue_style('aitrfoel-elementor-hiding-early');
        
        $css = '
            /* Hide Elementor admin top bar immediately */
            #e-admin-top-bar-root,
            .e-admin-top-bar,
            .e-admin-top-bar--active,
            [id*="e-admin-top-bar"],
            [class*="e-admin-top-bar"] {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                height: 0 !important;
                overflow: hidden !important;
            }

            /* Hide Elementor admin notices */
            .e-admin-notice {
                display: none !important;
            }

            /* Prevent body class interference */
            body.e-admin-top-bar--active {
                padding-top: 0 !important;
            }
        ';
        
        wp_add_inline_style('aitrfoel-elementor-hiding-early', $css);
    }

    /**
     * Hide Elementor admin elements with additional CSS
     */
    public function hide_elementor_admin_elements() {
        // Enqueue additional hiding rules
        wp_register_style('aitrfoel-elementor-hiding-additional', false);
        wp_enqueue_style('aitrfoel-elementor-hiding-additional');
        
        $css = '
            /* Additional Elementor hiding rules */
            #e-admin-top-bar-root,
            .e-admin-top-bar,
            .e-admin-top-bar--active,
            .e-admin-top-bar__container,
            .e-admin-top-bar__main-area,
            [id*="e-admin-top-bar"],
            [class*="e-admin-top-bar"] {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                height: 0 !important;
                width: 0 !important;
                overflow: hidden !important;
                position: absolute !important;
                left: -9999px !important;
            }

            /* Hide Elementor admin notices and components */
            .e-admin-notice,
            .e-admin-components-wrapper,
            .e-admin-feedback,
            .e-admin-announcement {
                display: none !important;
            }

            /* Reset body padding that Elementor might have added */
            body {
                padding-top: 0 !important;
            }

            /* Hide any Elementor React root elements */
            [data-reactroot],
            [data-react-helmet] {
                display: none !important;
            }
        ';
        
        wp_add_inline_style('aitrfoel-elementor-hiding-additional', $css);
    }

    /**
     * Restore admin footer text
     */
    public function restore_admin_footer($text) {
        // Remove Elementor's custom footer text
        if (strpos($text, 'Elementor') !== false) {
            return '';
        }
        return $text;
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our plugin pages - use NEW plugin slug
        // Be more inclusive to catch Freemius pages and any related admin pages
        $is_our_page = (
            strpos($hook, 'ai-translator-for-elementor-and-polylang') !== false ||
            strpos($hook, 'eatp') !== false || // Our plugin prefix
            strpos($hook, 'freemius') !== false || // Freemius pages
            $hook === 'admin_page_aitrfoel-debug' ||
            // Also check for any admin page that might be related
            (isset($_GET['page']) && strpos($_GET['page'], 'ai-translator-for-elementor-and-polylang') !== false)
        );
        
        if (!$is_our_page) {
            return;
        }
        
        // Enqueue styles
        wp_enqueue_style(
            'aitrfoel-admin-style',
            AITRFOEL_PLUGIN_URL . 'assets/css/admin.css',
            [],
            AITRFOEL_VERSION
        );
        

        
        // Enqueue scripts
        wp_enqueue_script(
            'aitrfoel-debug-monitor',
            AITRFOEL_PLUGIN_URL . 'assets/js/debug-monitor.js',
            ['jquery'],
            AITRFOEL_VERSION,
            true
        );

        wp_enqueue_script(
            'aitrfoel-admin-script',
            AITRFOEL_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery', 'aitrfoel-debug-monitor'],
            AITRFOEL_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('aitrfoel-admin-script', 'aitrfoel_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('aitrfoel_ajax_nonce'),
            'diagnose_nonce' => wp_create_nonce('aitrfoel_diagnose_theme_nonce'),
            'validate_word_count_nonce' => wp_create_nonce('aitrfoel_validate_word_count'),
            'save_cooldown_nonce' => wp_create_nonce('aitrfoel_save_cooldown'),
            'clear_usage_nonce' => wp_create_nonce('aitrfoel_clear_usage'),
            'debug' => true, // Add debug flag
            'strings' => [
                // Basic translations
                'plugin_title' => __('AI Translator for Elementor and Polylang', 'ai-translator-for-elementor-and-polylang'),
                'translating' => __('Translating...', 'ai-translator-for-elementor-and-polylang'),
                'success' => __('Translation completed successfully!', 'ai-translator-for-elementor-and-polylang'),
                'error' => __('Translation failed. Please try again.', 'ai-translator-for-elementor-and-polylang'),
                'confirm_translate' => __('Are you sure you want to translate this content?', 'ai-translator-for-elementor-and-polylang'),
                
                // Page loading
                'loading_pages' => __('Loading pages...', 'ai-translator-for-elementor-and-polylang'),
                'select_page' => __('Select a page to translate', 'ai-translator-for-elementor-and-polylang'),
                'error_loading_pages' => __('Error loading pages', 'ai-translator-for-elementor-and-polylang'),
                'select_page_first' => __('Please select a page first', 'ai-translator-for-elementor-and-polylang'),
                
                // Cost estimation
                'calculating' => __('Calculating...', 'ai-translator-for-elementor-and-polylang'),
                'estimated_cost' => __('Estimated Cost', 'ai-translator-for-elementor-and-polylang'),
                'words' => __('Words', 'ai-translator-for-elementor-and-polylang'),
                'tokens' => __('Tokens', 'ai-translator-for-elementor-and-polylang'),
                'provider' => __('Provider', 'ai-translator-for-elementor-and-polylang'),
                'model' => __('Model', 'ai-translator-for-elementor-and-polylang'),
                'large_page_warning' => __('This page contains a lot of content and may take longer to translate.', 'ai-translator-for-elementor-and-polylang'),
                'error_calculating' => __('Error calculating cost', 'ai-translator-for-elementor-and-polylang'),
                'estimate_cost' => __('Estimate Cost', 'ai-translator-for-elementor-and-polylang'),
                
                // Translation process
                'select_language' => __('Please select a target language', 'ai-translator-for-elementor-and-polylang'),
                'time_taken' => __('Time taken', 'ai-translator-for-elementor-and-polylang'),
                'words_translated' => __('Words translated', 'ai-translator-for-elementor-and-polylang'),
                'buy_more_tokens' => __('Buy More Tokens', 'ai-translator-for-elementor-and-polylang'),
                'buy_more' => __('Buy More', 'ai-translator-for-elementor-and-polylang'),
                'buy_more_words' => __('Buy More Words', 'ai-translator-for-elementor-and-polylang'),
                'upgrade_plan' => __('Upgrade Plan', 'ai-translator-for-elementor-and-polylang'),
                'total_words' => __('Total Words', 'ai-translator-for-elementor-and-polylang'),
                'words_left' => __('Words Left', 'ai-translator-for-elementor-and-polylang'),
                'license' => __('License', 'ai-translator-for-elementor-and-polylang'),
                'pages_left' => __('Pages Left', 'ai-translator-for-elementor-and-polylang'),
                
                // Tooltip strings
                /* translators: 1: Plan name, 2: Number of words */
                'plan_tooltip' => __('%1$s Plan: %2$s words included', 'ai-translator-for-elementor-and-polylang'),
                /* translators: 1: Words used, 2: Total words available */
                'usage_tooltip' => __('Used: %1$s of %2$s words', 'ai-translator-for-elementor-and-polylang'),
                'tokens_left' => __('Tokens Left', 'ai-translator-for-elementor-and-polylang'),
                'actual_cost' => __('Actual cost', 'ai-translator-for-elementor-and-polylang'),
                'preview_translation' => __('Preview Translation', 'ai-translator-for-elementor-and-polylang'),
                'edit_translation' => __('Edit Translation', 'ai-translator-for-elementor-and-polylang'),
                'publish_now' => __('Publish Now', 'ai-translator-for-elementor-and-polylang'),
                'translate_page' => __('Translate Page', 'ai-translator-for-elementor-and-polylang'),
                'confirm_publish' => __('Are you sure you want to publish this page?', 'ai-translator-for-elementor-and-polylang'),
                'page_published' => __('Page published successfully!', 'ai-translator-for-elementor-and-polylang'),
                
                // Errors
                'timeout_error' => __('Request timed out. Please try again.', 'ai-translator-for-elementor-and-polylang'),
                'network_error' => __('Network error:', 'ai-translator-for-elementor-and-polylang'),
                
                // Cache management
                'confirm_clear_cache' => __('Are you sure you want to clear the translation cache?', 'ai-translator-for-elementor-and-polylang'),
                'clearing_cache' => __('Clearing translation cache...', 'ai-translator-for-elementor-and-polylang'),
                'cache_error' => __('Failed to clear cache:', 'ai-translator-for-elementor-and-polylang'),
                
                // UI messages
                'found_strings' => __('Found', 'ai-translator-for-elementor-and-polylang'),
                'translatable_strings' => __('translatable strings.', 'ai-translator-for-elementor-and-polylang'),
                'no_content' => __('No translatable content found on this page.', 'ai-translator-for-elementor-and-polylang'),
                'select_strings' => __('Please select at least one string to translate.', 'ai-translator-for-elementor-and-polylang'),
                'ready' => __('Ready to translate another page.', 'ai-translator-for-elementor-and-polylang'),
                'cost_complete' => __('Cost estimation completed successfully!', 'ai-translator-for-elementor-and-polylang'),
                
                // Usage statistics
                'pages_translated' => __('Pages Translated', 'ai-translator-for-elementor-and-polylang'),
                'words_processed' => __('Words Processed', 'ai-translator-for-elementor-and-polylang'),
                'total_cost' => __('Total Cost', 'ai-translator-for-elementor-and-polylang'),
                'daily_average' => __('Daily Average', 'ai-translator-for-elementor-and-polylang'),
                'breakdown_by_provider' => __('Breakdown by Provider', 'ai-translator-for-elementor-and-polylang'),
                'uses' => __('Uses', 'ai-translator-for-elementor-and-polylang'),
                'cost' => __('Cost', 'ai-translator-for-elementor-and-polylang'),
                'error_loading_stats' => __('Error loading statistics', 'ai-translator-for-elementor-and-polylang'),
                
                // Theme compatibility
                'checking' => __('Checking...', 'ai-translator-for-elementor-and-polylang'),
                'theme_check_complete' => __('Theme check complete', 'ai-translator-for-elementor-and-polylang'),
                'recheck_compatibility' => __('Re-check Compatibility', 'ai-translator-for-elementor-and-polylang'),
                'theme' => __('Theme', 'ai-translator-for-elementor-and-polylang'),
                'status' => __('Status', 'ai-translator-for-elementor-and-polylang'),
                'fields_found' => __('Fields found', 'ai-translator-for-elementor-and-polylang'),
                'view_fields' => __('View fields', 'ai-translator-for-elementor-and-polylang'),
                'detected_fields' => __('Detected Fields', 'ai-translator-for-elementor-and-polylang'),
                'copy_all' => __('Copy all fields', 'ai-translator-for-elementor-and-polylang'),
                'no_fields_found' => __('No fields found', 'ai-translator-for-elementor-and-polylang'),
                'fields_copied' => __('Fields copied to clipboard', 'ai-translator-for-elementor-and-polylang'),
                'click_to_check_compatibility' => __('Click the button to check theme compatibility.', 'ai-translator-for-elementor-and-polylang'),
            ]
        ]);
        
        // Add inline script for immediate Elementor removal
        wp_add_inline_script('aitrfoel-admin-script', '
            (function() {
                // Immediate Elementor removal - runs before DOM ready
                function removeElementor() {
                    var selectors = [
                        "#e-admin-top-bar-root",
                        ".e-admin-top-bar",
                        ".e-admin-top-bar--active",
                        "[id*=\"e-admin-top-bar\"]",
                        "[class*=\"e-admin-top-bar\"]"
                    ];
                    
                    selectors.forEach(function(selector) {
                        var elements = document.querySelectorAll(selector);
                        elements.forEach(function(el) {
                            if (el) el.remove();
                        });
                    });
                    
                    // Remove body classes
                    document.body.classList.remove("e-admin-top-bar--active");
                    document.body.className = document.body.className.replace(/e-admin-top-bar[^\s]*/g, "");
                }
                
                // Run immediately
                removeElementor();
                
                // Run when DOM is ready
                if (document.readyState === "loading") {
                    document.addEventListener("DOMContentLoaded", removeElementor);
                } else {
                    removeElementor();
                }
                
                // Continue watching for changes
                if (window.MutationObserver) {
                    var observer = new MutationObserver(function(mutations) {
                        var shouldRemove = false;
                        mutations.forEach(function(mutation) {
                            if (mutation.type === "childList") {
                                mutation.addedNodes.forEach(function(node) {
                                    if (node.nodeType === 1) {
                                        if ((node.id && node.id.includes("e-admin-top-bar")) ||
                                            (node.className && node.className.includes && node.className.includes("e-admin-top-bar"))) {
                                            shouldRemove = true;
                                        }
                                    }
                                });
                            }
                        });
                        if (shouldRemove) removeElementor();
                    });
                    
                    observer.observe(document.body, {
                        childList: true,
                        subtree: true
                    });
                }
            })();
        ', 'before');
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create database tables if needed
        $this->create_tables();
        
        // Set default options
        $this->set_default_options();
        
        // Create cache directory
        $this->create_cache_directory();
        
        // Log activation
        if (class_exists('AITRFOEL_Logger')) {
            AITRFOEL_Logger::log('Plugin activated', 'info');
        }
        
        // Clear rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clean up scheduled tasks
        wp_clear_scheduled_hook('aitrfoel_cleanup_cache');
        
        // Log deactivation
        if (class_exists('AITRFOEL_Logger')) {
            AITRFOEL_Logger::log('Plugin deactivated', 'info');
        }
        
        // Clear rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Create database tables
     */
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Translation cache table
        $table_name = $wpdb->prefix . 'aitrfoel_translation_cache';
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            source_hash varchar(32) NOT NULL,
            source_text longtext NOT NULL,
            translated_text longtext NOT NULL,
            source_lang varchar(10) NOT NULL,
            target_lang varchar(10) NOT NULL,
            model varchar(50) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY source_hash_langs (source_hash, source_lang, target_lang),
            KEY target_lang (target_lang),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Usage statistics table
        $table_name = $wpdb->prefix . 'aitrfoel_usage_stats';
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            provider varchar(20) NOT NULL,
            model varchar(50) NOT NULL,
            input_tokens int DEFAULT 0,
            output_tokens int DEFAULT 0,
            word_count int DEFAULT 0,
            page_count int DEFAULT 0,
            estimated_cost decimal(10,6) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            month_year varchar(7) NOT NULL,
            PRIMARY KEY (id),
            KEY user_month (user_id, month_year),
            KEY provider_model (provider, model),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        dbDelta($sql);
    }
    
    /**
     * Set default plugin options
     */
    private function set_default_options() {
        // Provider selection - use the correct option name that settings page uses
        add_option('aitrfoel_api_provider', 'openai'); // Default to OpenAI
        
        // Legacy option for backwards compatibility  
        add_option('aitrfoel_translation_provider', 'openai'); // Default to OpenAI
        
        // Model settings (used on MeepTech server)
        add_option('aitrfoel_model', 'gpt-4o-mini');
        add_option('aitrfoel_temperature', 0.3);
        add_option('aitrfoel_max_tokens', 4000);
        
        // Translation settings
        add_option('aitrfoel_cache_enabled', true);
        add_option('aitrfoel_cache_duration', 30); // days
        add_option('aitrfoel_batch_size', 5);
        add_option('aitrfoel_rate_limit', 10); // requests per minute
        
        // UI settings
        add_option('aitrfoel_preview_enabled', true);
        add_option('aitrfoel_auto_save', false);
        add_option('aitrfoel_debug_mode', false);
        
        // License
        add_option('aitrfoel_license_key', '');
        add_option('aitrfoel_license_status', 'inactive');

        // Gap-Filler defaults
        add_option('aitrfoel_gap_enabled', true);
        add_option('aitrfoel_gap_max_retry', 1);
        add_option('aitrfoel_gap_lev_threshold', 3);
        add_option('aitrfoel_gap_residue_patterns', '/\\b(Read more|Číst dál|Читать далее)\\b/ui');
        add_option('aitrfoel_gap_enable_postprocessor', true);
    }
    
    /**
     * Create cache directory in uploads folder
     */
    private function create_cache_directory() {
        // Use uploads directory instead of wp-content/cache
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/ai-translator-cache';
        
        if (!file_exists($cache_dir)) {
            wp_mkdir_p($cache_dir);
            
            // Add .htaccess for security
            $htaccess = $cache_dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, 'Deny from all');
            }
            
            // Add index.php for additional security
            $index = $cache_dir . '/index.php';
            if (!file_exists($index)) {
                file_put_contents($index, '<?php // Silence is golden');
            }
        }
    }
    
    /**
     * Admin notice - Elementor missing
     */
    public function admin_notice_elementor_missing() {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p><?php esc_html_e('AI Translator for Elementor requires Elementor to be installed and activated.', 'ai-translator-for-elementor-and-polylang'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Admin notice - Elementor version
     */
    public function admin_notice_elementor_version() {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p><?php esc_html_e('AI Translator for Elementor requires Elementor version 3.0.0 or higher.', 'ai-translator-for-elementor-and-polylang'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Admin notice - Polylang missing (legacy, kept for backward compatibility)
     */
    public function admin_notice_polylang_missing() {
        $this->admin_notice_multilingual_missing();
    }
    
    /**
     * Admin notice - No multilingual plugin (Polylang or WPML)
     */
    public function admin_notice_multilingual_missing() {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p>
                <strong><?php esc_html_e('AI Translator for Elementor', 'ai-translator-for-elementor-and-polylang'); ?></strong>: 
                <?php esc_html_e('This plugin requires a multilingual plugin to be installed and activated.', 'ai-translator-for-elementor-and-polylang'); ?>
            </p>
            <p>
                <?php esc_html_e('Supported plugins:', 'ai-translator-for-elementor-and-polylang'); ?>
                <a href="https://wordpress.org/plugins/polylang/" target="_blank">Polylang</a> <?php esc_html_e('or', 'ai-translator-for-elementor-and-polylang'); ?>
                <a href="https://wpml.org/" target="_blank">WPML</a>
            </p>
        </div>
        <?php
    }
    
    /**
     * Admin notice - PHP version
     */
    public function admin_notice_php_version() {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p><?php esc_html_e('AI Translator for Elementor requires PHP version 7.4 or higher.', 'ai-translator-for-elementor-and-polylang'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Process pending jobs manually (fallback method)
     * Note: Background processing was removed, using direct processing now
     */
    public function process_pending_jobs() {
        if (!class_exists('AITRFOEL_Bulk_Translation_Queue')) {
            error_log("EAT Error: AITRFOEL_Bulk_Translation_Queue class not found");
            return false;
        }
        
        $queue = AITRFOEL_Bulk_Translation_Queue::get_instance();
        
        // Get first pending job
        global $wpdb;
        $table_name = $wpdb->prefix . 'aitrfoel_bulk_jobs';
        
        $pending_job = $wpdb->get_row("
            SELECT * FROM {$table_name} 
            WHERE status = 'pending' 
            ORDER BY created_at ASC 
            LIMIT 1
        ");
        
        if (!$pending_job) {
            return false;
        }
        
        // Decode job data
        $job_data = json_decode($pending_job->job_data, true);
        
        if (!$job_data) {
            error_log("EAT Error: Could not decode job data for {$pending_job->job_id}");
            return false;
        }
        
        // Extract parameters
        $page_ids = $job_data['page_ids'] ?? [];
        $target_languages = $job_data['target_languages'] ?? [];
        $source_language = $job_data['source_language'] ?? 'auto';
        
        // Note: Background processing was removed
        // Jobs are now processed directly via AJAX handlers
        // This method is kept for compatibility but does nothing
        
        return true;
    }
    
    /**
     * Render welcome popup (shown once after activation)
     */
    public function render_welcome_popup() {
        // Only show on our plugin pages
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'ai-translator-for-elementor-and-polylang') === false) {
            return;
        }
        
        // Allow forcing welcome popup with URL parameter for testing: ?show_welcome=1
        $force_show = isset($_GET['show_welcome']) && $_GET['show_welcome'] === '1';
        
        // Check if we should show the popup
        $show_popup = get_option('aitrfoel_show_welcome_popup', 'no');
        if ($show_popup !== 'yes' && !$force_show) {
            return;
        }
        
        // URLs
        $polylang_url = admin_url('admin.php?page=mlang');
        $docs_url = 'https://meeptech.com/products/ai-translator-for-elementor-polylang/documentation';
        $video_id = 'RzcxmipDas4';
        $video_url = 'https://www.youtube.com/watch?v=' . $video_id;
        $video_thumbnail = 'https://img.youtube.com/vi/' . $video_id . '/maxresdefault.jpg';
        
        ?>
        <div id="aitrfoel-welcome-popup" class="aitrfoel-welcome-fullscreen">
            <!-- Close button -->
            <button type="button" class="aitrfoel-welcome-close aitrfoel-welcome-dismiss" aria-label="<?php esc_attr_e('Close', 'ai-translator-for-elementor-and-polylang'); ?>">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
            
            <div class="aitrfoel-welcome-container">
                <!-- Header -->
                <div class="aitrfoel-welcome-header">
                    <h1>👋 <?php esc_html_e('Welcome to AI Translator!', 'ai-translator-for-elementor-and-polylang'); ?></h1>
                    <p class="aitrfoel-welcome-subtitle">
                        <?php esc_html_e('Translate your Elementor pages instantly with AI.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </p>
                </div>
                
                <!-- Video - Click to open on YouTube -->
                <div class="aitrfoel-welcome-video-wrapper">
                    <a href="<?php echo esc_url($video_url); ?>" target="_blank" rel="noopener noreferrer" class="aitrfoel-video-link">
                        <img 
                            src="<?php echo esc_url($video_thumbnail); ?>" 
                            alt="<?php esc_attr_e('AI Translator Tutorial', 'ai-translator-for-elementor-and-polylang'); ?>"
                            class="aitrfoel-welcome-video-thumb"
                        />
                        <div class="aitrfoel-play-button">
                            <svg viewBox="0 0 68 48" width="68" height="48">
                                <path class="aitrfoel-play-bg" d="M66.52,7.74c-0.78-2.93-2.49-5.41-5.42-6.19C55.79,.13,34,0,34,0S12.21,.13,6.9,1.55 C3.97,2.33,2.27,4.81,1.48,7.74C0.06,13.05,0,24,0,24s0.06,10.95,1.48,16.26c0.78,2.93,2.49,5.41,5.42,6.19 C12.21,47.87,34,48,34,48s21.79-0.13,27.1-1.55c2.93-0.78,4.64-3.26,5.42-6.19C67.94,34.95,68,24,68,24S67.94,13.05,66.52,7.74z" fill="#f00"></path>
                                <path d="M 45,24 27,14 27,34" fill="#fff"></path>
                            </svg>
                        </div>
                        <span class="aitrfoel-video-label"><?php esc_html_e('▶ Watch Tutorial on YouTube', 'ai-translator-for-elementor-and-polylang'); ?></span>
                    </a>
                </div>
                
                <!-- Quick Steps - Single Line -->
                <div class="aitrfoel-welcome-steps">
                    <span class="aitrfoel-step"><span class="aitrfoel-step-number">1</span> <?php esc_html_e('Set up languages in', 'ai-translator-for-elementor-and-polylang'); ?> <a href="<?php echo esc_url($polylang_url); ?>">Polylang</a></span>
                    <span class="aitrfoel-step-separator">→</span>
                    <span class="aitrfoel-step"><span class="aitrfoel-step-number">2</span> <?php esc_html_e('Select pages & target languages', 'ai-translator-for-elementor-and-polylang'); ?></span>
                    <span class="aitrfoel-step-separator">→</span>
                    <span class="aitrfoel-step"><span class="aitrfoel-step-number">3</span> <?php esc_html_e('Click translate!', 'ai-translator-for-elementor-and-polylang'); ?></span>
                </div>
                
                <!-- Analytics Consent -->
                <div class="aitrfoel-welcome-consent">
                    <label class="aitrfoel-consent-label">
                        <input type="checkbox" id="aitrfoel-analytics-consent" class="aitrfoel-consent-checkbox">
                        <span class="aitrfoel-consent-text">
                            <?php esc_html_e('Help us improve by enabling anonymous usage tracking', 'ai-translator-for-elementor-and-polylang'); ?>
                        </span>
                        <span class="aitrfoel-consent-tooltip">
                            <span class="aitrfoel-tooltip-icon">?</span>
                            <span class="aitrfoel-tooltip-content">
                                <strong><?php esc_html_e('What we collect:', 'ai-translator-for-elementor-and-polylang'); ?></strong><br>
                                • <?php esc_html_e('Plugin version & WordPress version', 'ai-translator-for-elementor-and-polylang'); ?><br>
                                • <?php esc_html_e('Features used (translation, bulk mode)', 'ai-translator-for-elementor-and-polylang'); ?><br>
                                • <?php esc_html_e('Error reports for debugging', 'ai-translator-for-elementor-and-polylang'); ?><br>
                                • <?php esc_html_e('AI model usage statistics', 'ai-translator-for-elementor-and-polylang'); ?><br><br>
                                <strong><?php esc_html_e('We never collect:', 'ai-translator-for-elementor-and-polylang'); ?></strong><br>
                                • <?php esc_html_e('Personal data or content', 'ai-translator-for-elementor-and-polylang'); ?><br>
                                • <?php esc_html_e('API keys or credentials', 'ai-translator-for-elementor-and-polylang'); ?><br><br>
                                <em><?php esc_html_e('Data is never shared with third parties.', 'ai-translator-for-elementor-and-polylang'); ?></em>
                            </span>
                        </span>
                    </label>
                </div>
                
                <!-- Action Buttons -->
                <div class="aitrfoel-welcome-actions">
                    <button type="button" class="aitrfoel-btn-primary aitrfoel-welcome-dismiss">
                        <?php esc_html_e("Let's Get Started!", 'ai-translator-for-elementor-and-polylang'); ?>
                    </button>
                    <a href="<?php echo esc_url($docs_url); ?>" target="_blank" class="aitrfoel-btn-secondary">
                        <?php esc_html_e('Documentation', 'ai-translator-for-elementor-and-polylang'); ?>
                    </a>
                </div>
            </div>
        </div>
        
        <style>
            /* Fullscreen Overlay */
            .aitrfoel-welcome-fullscreen {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: linear-gradient(135deg, #1e3a5ff2 0%, #0d1b2a 100%);
                z-index: 100001;
                display: flex;
                align-items: center;
                justify-content: center;
                animation: aitrfoel-fade-in 0.4s ease;
                overflow: hidden;
                padding: 20px;
                box-sizing: border-box;
            }
            
            @keyframes aitrfoel-fade-in {
                from { opacity: 0; }
                to { opacity: 1; }
            }
            
            /* Close Button */
            .aitrfoel-welcome-close {
                position: fixed;
                top: 20px;
                right: 20px;
                background: rgba(255, 255, 255, 0.1);
                border: none;
                color: #fff;
                width: 44px;
                height: 44px;
                border-radius: 50%;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: all 0.2s ease;
                z-index: 100002;
            }
            
            .aitrfoel-welcome-close:hover {
                background: rgba(255, 255, 255, 0.2);
                transform: scale(1.1);
            }
            
            .aitrfoel-welcome-close .dashicons {
                font-size: 24px;
                width: 24px;
                height: 24px;
            }
            
            /* Container - Centered Column */
            .aitrfoel-welcome-container {
                max-width: 700px;
                width: 100%;
                text-align: center;
                animation: aitrfoel-slide-up 0.5s ease;
            }
            
            @keyframes aitrfoel-slide-up {
                from { transform: translateY(30px); opacity: 0; }
                to { transform: translateY(0); opacity: 1; }
            }
            
            /* Header */
            .aitrfoel-welcome-header {
                margin-bottom: 24px;
            }
            
            .aitrfoel-welcome-header h1 {
                color: #fff;
                font-size: 32px;
                font-weight: 700;
                margin: 0 0 10px 0;
                line-height: 1.2;
            }
            
            .aitrfoel-welcome-subtitle {
                color: rgba(255, 255, 255, 0.7);
                font-size: 16px;
                margin: 0;
                line-height: 1.5;
            }
            
            /* Video */
            .aitrfoel-welcome-video-wrapper {
                background: #000;
                border-radius: 16px;
                overflow: hidden;
                box-shadow: 0 25px 80px rgba(0, 0, 0, 0.5);
                width: 100%;
                aspect-ratio: 16 / 9;
                margin-bottom: 24px;
                position: relative;
            }
            
            .aitrfoel-video-link {
                display: block;
                width: 100%;
                height: 100%;
                position: relative;
                text-decoration: none;
            }
            
            .aitrfoel-welcome-video-thumb {
                width: 100%;
                height: 100%;
                object-fit: cover;
                display: block;
            }
            
            .aitrfoel-play-button {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                opacity: 0.9;
                transition: opacity 0.2s, transform 0.2s;
            }
            
            .aitrfoel-video-link:hover .aitrfoel-play-button {
                opacity: 1;
                transform: translate(-50%, -50%) scale(1.1);
            }
            
            .aitrfoel-video-label {
                position: absolute;
                bottom: 16px;
                left: 50%;
                transform: translateX(-50%);
                background: rgba(0, 0, 0, 0.8);
                color: #fff;
                padding: 8px 16px;
                border-radius: 20px;
                font-size: 14px;
                font-weight: 500;
            }
            
            .aitrfoel-welcome-video {
                width: 100%;
                height: 100%;
                border: none;
                background: #000;
            }
            
            /* Steps - Single Line */
            .aitrfoel-welcome-steps {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 12px;
                margin-bottom: 24px;
                flex-wrap: wrap;
            }
            
            .aitrfoel-step {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                color: rgba(255, 255, 255, 0.9);
                font-size: 14px;
            }
            
            .aitrfoel-step-number {
                width: 22px;
                height: 22px;
                min-width: 22px;
                background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
                border-radius: 50%;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                color: #fff;
                font-weight: 700;
                font-size: 11px;
            }
            
            .aitrfoel-step-separator {
                color: rgba(255, 255, 255, 0.4);
                font-size: 14px;
            }
            
            .aitrfoel-step a {
                color: #60a5fa;
                text-decoration: none;
            }
            
            .aitrfoel-step a:hover {
                text-decoration: underline;
            }
            
            /* Analytics Consent */
            .aitrfoel-welcome-consent {
                margin-bottom: 20px;
                display: flex;
                justify-content: center;
            }
            
            .aitrfoel-consent-label {
                display: inline-flex;
                align-items: center;
                gap: 10px;
                cursor: pointer;
                color: rgba(255, 255, 255, 0.8);
                font-size: 13px;
            }
            
            .aitrfoel-consent-checkbox {
                width: 18px;
                height: 18px;
                cursor: pointer;
                accent-color: #3b82f6;
            }
            
            .aitrfoel-consent-text {
                user-select: none;
            }
            
            .aitrfoel-consent-tooltip {
                position: relative;
                display: inline-flex;
            }
            
            .aitrfoel-tooltip-icon {
                width: 18px;
                height: 18px;
                background: rgba(255, 255, 255, 0.2);
                border-radius: 50%;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                font-size: 11px;
                font-weight: 700;
                color: #fff;
                cursor: help;
                transition: background 0.2s;
            }
            
            .aitrfoel-tooltip-icon:hover {
                background: rgba(255, 255, 255, 0.3);
            }
            
            .aitrfoel-tooltip-content {
                position: absolute;
                bottom: 100%;
                left: 50%;
                transform: translateX(-50%);
                background: #1a1a2e;
                border: 1px solid rgba(255, 255, 255, 0.15);
                border-radius: 8px;
                padding: 12px 14px;
                width: 260px;
                font-size: 12px;
                line-height: 1.5;
                color: rgba(255, 255, 255, 0.85);
                box-shadow: 0 10px 40px rgba(0, 0, 0, 0.4);
                opacity: 0;
                visibility: hidden;
                transition: all 0.2s ease;
                margin-bottom: 8px;
                z-index: 10;
                text-align: left;
            }
            
            .aitrfoel-tooltip-content::after {
                content: '';
                position: absolute;
                top: 100%;
                left: 50%;
                transform: translateX(-50%);
                border: 6px solid transparent;
                border-top-color: #1a1a2e;
            }
            
            .aitrfoel-consent-tooltip:hover .aitrfoel-tooltip-content {
                opacity: 1;
                visibility: visible;
            }
            
            .aitrfoel-tooltip-content strong {
                color: #fff;
            }
            
            /* Action Buttons */
            .aitrfoel-welcome-actions {
                display: flex;
                gap: 12px;
                justify-content: center;
            }
            
            .aitrfoel-btn-primary {
                background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
                color: #fff;
                border: none;
                padding: 14px 28px;
                font-size: 15px;
                font-weight: 600;
                border-radius: 8px;
                cursor: pointer;
                transition: all 0.3s ease;
                box-shadow: 0 4px 20px rgba(59, 130, 246, 0.4);
            }
            
            .aitrfoel-btn-primary:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 30px rgba(59, 130, 246, 0.5);
            }
            
            .aitrfoel-btn-secondary {
                background: transparent;
                color: #fff;
                border: 2px solid rgba(255, 255, 255, 0.3);
                padding: 12px 24px;
                font-size: 15px;
                font-weight: 500;
                border-radius: 8px;
                cursor: pointer;
                transition: all 0.3s ease;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
            }
            
            .aitrfoel-btn-secondary:hover {
                background: rgba(255, 255, 255, 0.1);
                border-color: rgba(255, 255, 255, 0.5);
                color: #fff;
            }
            
            /* Responsive */
            @media (max-width: 600px) {
                .aitrfoel-welcome-header h1 {
                    font-size: 26px;
                }
                
                .aitrfoel-welcome-steps {
                    flex-direction: column;
                    gap: 8px;
                }
                
                .aitrfoel-step-separator {
                    display: none;
                }
                
                .aitrfoel-welcome-actions {
                    flex-direction: column;
                }
                
                .aitrfoel-btn-primary,
                .aitrfoel-btn-secondary {
                    width: 100%;
                    justify-content: center;
                }
            }
        </style>
        
        <script>
            jQuery(document).ready(function($) {
                // Dismiss welcome popup
                $('.aitrfoel-welcome-dismiss').on('click', function(e) {
                    e.preventDefault();
                    
                    $('#aitrfoel-welcome-popup').fadeOut(300, function() {
                        $(this).remove();
                    });
                    
                    // Get analytics consent status
                    var analyticsConsent = $('#aitrfoel-analytics-consent').is(':checked') ? '1' : '0';
                    
                    // Save dismissal and consent via AJAX
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'aitrfoel_dismiss_welcome',
                            nonce: '<?php echo esc_js( wp_create_nonce('aitrfoel_dismiss_welcome') ); ?>',
                            analytics_consent: analyticsConsent
                        }
                    });
                });
                
                // Close on Escape key
                $(document).on('keyup', function(e) {
                    if (e.key === 'Escape' && $('#aitrfoel-welcome-popup').length) {
                        $('.aitrfoel-welcome-dismiss').first().click();
                    }
                });
            });
        </script>
        <?php
    }
    
    /**
     * AJAX handler to dismiss welcome popup
     */
    public function dismiss_welcome_popup() {
        check_ajax_referer('aitrfoel_dismiss_welcome', 'nonce');
        
        delete_option('aitrfoel_show_welcome_popup');
        
        // Save analytics consent
        $analytics_consent = isset($_POST['analytics_consent']) && $_POST['analytics_consent'] === '1';
        update_option('aitrfoel_analytics_enabled', $analytics_consent);
        
        wp_send_json_success();
    }
}

// Initialize the plugin
AITRFOEL_Main::get_instance();
