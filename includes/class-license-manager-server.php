<?php
/**
 * Server-Based License Manager
 * Uses your meeptech.com server for translations and license verification
 */

if (!defined('ABSPATH')) {
    exit;
}

class AITRFOEL_License_Manager_Server {
    
    private static $instance = null;
    private $api_base_url = 'https://meeptech.com/api/eat'; // TypeScript Next.js API
    private $cached_license_key = null; // Cache license key to avoid Freemius calls
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Check if cooldown expired and reset word count
        add_action('init', [$this, 'check_cooldown_expiry']);
        
        // Admin notices for cooldown
        add_action('admin_notices', [$this, 'show_cooldown_notices']);
        
        // Freemius activation hooks (initialize user in remote DB)
        add_action('aitrfoel_fs_loaded', [$this, 'init_freemius_hooks']);

        // FREE VERSION: Auto-register on first load
        if (defined('AITRFOEL_VERSION_TYPE') && AITRFOEL_VERSION_TYPE === 'free') {
            add_action('admin_init', [$this, 'maybe_auto_register_free_user']);
        }
    }
    
    /**
     * Initialize Freemius hooks after SDK is loaded
     */
    public function init_freemius_hooks() {
        if (function_exists('eatp_fs')) {
            // When user opts in (connects Freemius account) - Free plan
            eatp_fs()->add_action('after_account_connection', [$this, 'on_freemius_connected']);
            
            // When user activates a paid license
            eatp_fs()->add_action('after_license_activation', [$this, 'on_license_activated']);
            
            // When user changes plan (upgrade/downgrade)
            eatp_fs()->add_action('after_plan_change', [$this, 'on_plan_changed'], 10, 2);
        }
    }
    
    /**
     * Get license key from Freemius (with caching to avoid repeated SDK calls)
     */
    private function get_license_key() {
        // Return cached value if available
        if ($this->cached_license_key !== null) {
            return $this->cached_license_key;
        }
        
        // PERFORMANCE: During AJAX translation, skip Freemius entirely
        $is_translation_ajax = defined('DOING_AJAX') && DOING_AJAX && isset($_REQUEST['action']) && 
            in_array($_REQUEST['action'], [
                'aitrfoel_translate_page',
                'aitrfoel_bulk_translate', 
                'aitrfoel_start_bulk_translation',
                'aitrfoel_process_background_translation',
                'aitrfoel_get_bulk_job_status'
            ]);
        
        if ($is_translation_ajax) {
            // Use cached value from WordPress options (set during admin page load)
            $cached_key = get_option('aitrfoel_cached_license_key', '');
            $this->cached_license_key = $cached_key ?: null;
            return $this->cached_license_key;
        }
        
        // Normal flow: Get from Freemius (only in admin context)
        if (function_exists('eatp_fs') && eatp_fs()->is_registered()) {
            try {
                $license = eatp_fs()->_get_license();
                if ($license && isset($license->secret_key)) {
                    $this->cached_license_key = $license->secret_key;
                    // Cache in WordPress options for AJAX use
                    update_option('aitrfoel_cached_license_key', $this->cached_license_key, false);
                    return $this->cached_license_key;
                }
            } catch (Exception $e) {
                if (class_exists('AITRFOEL_Logger')) {
                    AITRFOEL_Logger::log('Could not get Freemius license: ' . $e->getMessage(), 'warning');
                }
            }
        }
        
        $this->cached_license_key = null;
        return null;
    }
    
    /**
     * Get current license status from server
     */
    public function get_license_status() {
        $plan_name = 'Free';
        $word_limit = 1800;
        
        // Get license key (uses cache during AJAX)
        $license_key = $this->get_license_key();
        
        // Get REAL usage AND plan from server (server is source of truth)
        $server_usage = $this->get_usage_from_server($license_key);
        
        // Use plan and limit from server if available
        if (isset($server_usage['plan'])) {
            $plan_name = $server_usage['plan'];
        }
        if (isset($server_usage['word_limit'])) {
            $word_limit = $server_usage['word_limit'];
        } else {
            // Fallback to calculating from plan name
            $word_limit = $this->get_word_limit_for_plan($plan_name);
        }
        
        $words_used = $server_usage['words_used'];
        $words_left = $word_limit === -1 ? -1 : max(0, $word_limit - $words_used);
        
        return [
            'plan' => $plan_name,
            'word_limit' => $word_limit,
            'words_used' => $words_used,
            'words_left' => $words_left,
            'license_key' => $license_key
        ];
    }
    
    /**
     * Get usage from translation server
     */
    private function get_usage_from_server($license_key = null) {
        try {
            $request_data = [
                'license_key' => $license_key,
                'site_url' => home_url()
            ];
            
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log('🔍 Requesting usage from server: ' . $this->api_base_url . '/get-usage', 'info');
                AITRFOEL_Logger::log('📦 Request data: ' . json_encode($request_data), 'info');
            }
            
            $response = wp_remote_post($this->api_base_url . '/get-usage', [
                'timeout' => 10,
                'body' => json_encode($request_data),
                'headers' => [
                    'Content-Type' => 'application/json'
                ]
            ]);
            
            if (is_wp_error($response)) {
                $error_msg = $response->get_error_message();
                error_log('EAT: ❌ API Error: ' . $error_msg);
                
                if (class_exists('AITRFOEL_Logger')) {
                    AITRFOEL_Logger::log('❌ Failed to get usage from server: ' . $error_msg, 'warning');
                }
                // Fallback to local cache
                $fallback_words = get_option('aitrfoel_words_used_this_month', 0);
                
                if (class_exists('AITRFOEL_Logger')) {
                    AITRFOEL_Logger::log('📊 Using local fallback: ' . $fallback_words . ' words', 'info');
                }
                return ['words_used' => $fallback_words];
            }
            
            $body = wp_remote_retrieve_body($response);
            $status_code = wp_remote_retrieve_response_code($response);
            
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log('📡 Server response status: ' . $status_code, 'info');
                AITRFOEL_Logger::log('📄 Server response body: ' . $body, 'info');
            }
            
            $data = json_decode($body, true);
            
            if (isset($data['success']) && $data['success']) {
                $words_used = isset($data['words_used']) ? intval($data['words_used']) : 0;
                
                if (class_exists('AITRFOEL_Logger')) {
                    AITRFOEL_Logger::log('✅ Server returned words_used: ' . $words_used, 'info');
                }
                
                // Cache the result locally for performance
                update_option('aitrfoel_words_used_this_month', $words_used);
                
                // Return full data including plan and word_limit from server
                $result = ['words_used' => $words_used];
                if (isset($data['plan'])) {
                    $result['plan'] = $data['plan'];
                }
                if (isset($data['word_limit'])) {
                    $result['word_limit'] = intval($data['word_limit']);
                }
                
                return $result;
            }
            
            // Fallback to local cache
            $fallback_words = get_option('aitrfoel_words_used_this_month', 0);
            
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log('⚠️ Server response not successful, using local fallback: ' . $fallback_words . ' words', 'warning');
            }
            return ['words_used' => $fallback_words];
            
        } catch (Exception $e) {
            error_log('EAT: ❌ Exception: ' . $e->getMessage());
            
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log('❌ Error getting usage from server: ' . $e->getMessage(), 'error');
            }
            return ['words_used' => get_option('aitrfoel_words_used_this_month', 0)];
        }
    }
    
    /**
     * Get total usage for a license across all sites (for multi-site licenses)
     * 
     * @param string $license_key The license key to check
     * @return array Array with total_words_used, word_limit, active_sites, plan
     */
    public function get_total_usage_for_license($license_key) {
        if (empty($license_key)) {
            return [
                'total_words_used' => 0,
                'word_limit' => 1800,
                'active_sites' => 1,
                'plan' => 'Free'
            ];
        }
        
        try {
            $response = wp_remote_post($this->api_base_url . '/get-license-usage', [
                'body' => json_encode(['license_key' => $license_key]),
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-API-Key' => $this->api_key
                ],
                'timeout' => 15
            ]);
            
            if (is_wp_error($response)) {
                AITRFOEL_Logger::log('❌ Error getting license usage: ' . $response->get_error_message(), 'error');
                return [
                    'total_words_used' => 0,
                    'word_limit' => 1800,
                    'active_sites' => 1,
                    'plan' => 'Free'
                ];
            }
            
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (isset($data['success']) && $data['success']) {
                return [
                    'total_words_used' => $data['total_words_used'] ?? 0,
                    'word_limit' => $data['word_limit'] ?? 1800,
                    'active_sites' => $data['active_sites'] ?? 1,
                    'plan' => $data['plan'] ?? 'Free'
                ];
            }
            
            return [
                'total_words_used' => 0,
                'word_limit' => 1800,
                'active_sites' => 1,
                'plan' => 'Free'
            ];
            
        } catch (Exception $e) {
            AITRFOEL_Logger::log('❌ Exception getting license usage: ' . $e->getMessage(), 'error');
            return [
                'total_words_used' => 0,
                'word_limit' => 1800,
                'active_sites' => 1,
                'plan' => 'Free'
            ];
        }
    }
    
    /**
     * Translate text via your server
     */
    public function translate_via_server($text, $source_language, $target_language) {
        // Get license info
        $license_status = $this->get_license_status();
        
        // Prepare request data
        $request_data = [
            'text' => $text,
            'source_language' => $source_language,
            'target_language' => $target_language,
            'site_url' => home_url(),
            'plan' => $license_status['plan'],
            'license_key' => $license_status['license_key']
        ];
        
        if (class_exists('AITRFOEL_Logger')) {
            AITRFOEL_Logger::log('Sending translation request to server', 'info', [
                'word_count' => str_word_count($text),
                'plan' => $license_status['plan'],
                'text_preview' => substr($text, 0, 100) . '...'
            ]);
        }
        
        // Make request to hosted API
        $response = wp_remote_post($this->api_base_url . '/translate', [
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'ai-translator-for-elementor-and-polylang/' . (defined('AITRFOEL_VERSION') ? AITRFOEL_VERSION : '1.0')
            ],
            'body' => json_encode($request_data),
            'timeout' => 60
        ]);
        
        if (is_wp_error($response)) {
            $error_msg = __('Failed to connect to translation service: ', 'ai-translator-for-elementor-and-polylang') . $response->get_error_message();
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log($error_msg, 'error');
            }
            throw new Exception(esc_html($error_msg));
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        // Log raw response for debugging
        if (class_exists('AITRFOEL_Logger')) {
            AITRFOEL_Logger::log('🔍 Translation API Response Status: ' . $response_code, 'info');
            AITRFOEL_Logger::log('📄 Translation API Response Body: ' . $response_body, 'info');
        }
        
        if ($response_code !== 200) {
            $error_data = json_decode($response_body, true);
            $error_message = isset($error_data['error']) ? $error_data['error'] : __('Translation service error', 'ai-translator-for-elementor-and-polylang');
            
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log('Translation API Error', 'error', [
                    'status_code' => $response_code,
                    'error' => $error_message,
                    'full_response' => $response_body
                ]);
            }
            
            throw new Exception(esc_html($error_message));
        }
        
        $data = json_decode($response_body, true);
        
        if (!isset($data['success']) || !$data['success']) {
            $error_msg = isset($data['error']) ? $data['error'] : __('Invalid response from translation service', 'ai-translator-for-elementor-and-polylang');
            
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log('Translation API Invalid Response', 'error', [
                    'response_data' => $data,
                    'error' => $error_msg
                ]);
            }
            
            throw new Exception(esc_html($error_msg));
        }
        
        if (!isset($data['translated_text'])) {
            $error_msg = __('No translated text in response', 'ai-translator-for-elementor-and-polylang');
            
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log('Translation API Missing Field', 'error', [
                    'response_data' => $data
                ]);
            }
            
            throw new Exception(esc_html($error_msg));
        }
        
        // Update local cache with server usage
        if (isset($data['total_words_used'])) {
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log('💾 Updating local cache: total_words_used = ' . $data['total_words_used'], 'info');
            }
            update_option('aitrfoel_words_used_this_month', $data['total_words_used']);
        } else {
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log('⚠️ WARNING: translate response missing "total_words_used" field!', 'warning');
                AITRFOEL_Logger::log('📋 Available fields in response: ' . implode(', ', array_keys($data)), 'info');
            }
        }
        
        if (class_exists('AITRFOEL_Logger')) {
            AITRFOEL_Logger::log('Translation completed successfully', 'info', [
                'words_used' => $data['words_used'] ?? 0,
                'total_words_used' => $data['total_words_used'] ?? 0,
                'translated_preview' => substr($data['translated_text'], 0, 100) . '...'
            ]);
        }
        
        return $data['translated_text'];
    }
    
    /**
     * Get word limit for plan
     */
    private function get_word_limit_for_plan($plan_name) {
        $limits = [
            'Free' => 1800,          // Free: 1,800 words/month (1 site)
            'Starter' => 18000,      // Starter: 18,000 words/month (1 site)
            'Agency' => 89000,       // Agency: 89,000 words/month (up to 10 sites, shared)
            'Enterprise' => 355000   // Enterprise: 355,000 words/month (up to 50 sites, shared)
        ];
        
        return $limits[$plan_name] ?? 1800;
    }
    
    /**
     * Check if 7-day cooldown expired and reset word count for free users
     * Also clear cooldown for paid plan users (they shouldn't have cooldown)
     */
    public function check_cooldown_expiry() {
        $cooldown_end = get_option('aitrfoel_cooldown_end_date', 0);
        
        // If cooldown exists, check if user is on paid plan
        if ($cooldown_end > 0) {
            $license_status = $this->get_license_status();
            $is_free_plan = ($license_status['plan'] === 'Free');
            
            // IMPORTANT: Paid plans should NEVER have cooldown
            if (!$is_free_plan) {
                // Clear cooldown immediately for paid users
                delete_option('aitrfoel_cooldown_end_date');
                return;
            }
            
            // For free users, check if cooldown expired
            if ($cooldown_end <= time()) {
                // Cooldown expired - reset word count to 0
                update_option('aitrfoel_words_used_this_month', 0);
                delete_option('aitrfoel_cooldown_end_date');
                
                // Notify server about the reset
                $this->notify_server_of_cooldown_reset();
            }
        }
    }
    
    /**
     * Notify server that 7-day cooldown reset occurred
     */
    private function notify_server_of_cooldown_reset() {
        try {
            $response = wp_remote_post($this->api_base_url . '/reset-usage', [
                'timeout' => 5,
                'body' => json_encode([
                    'site_url' => home_url(),
                    'reset_date' => gmdate('Y-m-d H:i:s'),
                    'event' => 'cooldown_reset'
                ]),
                'headers' => [
                    'Content-Type' => 'application/json'
                ]
            ]);
            
            if (!is_wp_error($response)) {
                $status_code = wp_remote_retrieve_response_code($response);
            } else {
                error_log('EAT: Failed to notify server of reset: ' . $response->get_error_message());
            }
        } catch (Exception $e) {
            error_log('EAT: Exception notifying server of reset: ' . $e->getMessage());
        }
    }
    
    /**
     * Show cooldown admin notices
     */
    public function show_cooldown_notices() {
        // Check if cooldown was just activated
        if (get_transient('aitrfoel_cooldown_activated')) {
            delete_transient('aitrfoel_cooldown_activated');
            
            $cooldown_end = get_option('aitrfoel_cooldown_end_date', 0);
            $days_left = ceil(($cooldown_end - time()) / DAY_IN_SECONDS);
            
            $upgrade_url = admin_url('admin.php?page=ai-translator-for-elementor-and-polylang-pricing');
            
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>' . esc_html__('AI Translator: Monthly Limit Reached', 'ai-translator-for-elementor-and-polylang') . '</strong></p>';
            echo '<p>' . sprintf(
                /* translators: %d: Number of days until word count resets */
                esc_html__('You have used all 1,800 words in your free plan this month. Your word count will reset in %d days, or you can ', 'ai-translator-for-elementor-and-polylang'),
                absint($days_left)
            );
            echo '<a href="' . esc_url($upgrade_url) . '">' . esc_html__('upgrade now', 'ai-translator-for-elementor-and-polylang') . '</a>';
            echo ' ' . esc_html__('for immediate access to more words.', 'ai-translator-for-elementor-and-polylang') . '</p>';
            echo '</div>';
        }
        
        // Check if user is currently in cooldown
        $cooldown_end = get_option('aitrfoel_cooldown_end_date', 0);
        if ($cooldown_end > time()) {
            // Only show on plugin pages
            $screen = get_current_screen();
            if ($screen && strpos($screen->id, 'ai-translator') !== false) {
                $days_left = ceil(($cooldown_end - time()) / DAY_IN_SECONDS);
                $upgrade_url = 'https://meeptech.com/products/ai-translator-for-elementor-polylang/#pricing';
                
                echo '<div class="notice notice-info">';
                echo '<p><strong>' . esc_html__('AI Translator: Cooldown Active', 'ai-translator-for-elementor-and-polylang') . '</strong></p>';
                echo '<p>' . sprintf(
                    /* translators: %d: Number of days until word limit resets */
                    esc_html__('Your free plan word limit will reset in %d days. ', 'ai-translator-for-elementor-and-polylang'),
                    absint($days_left)
                );
                echo '<a href="' . esc_url($upgrade_url) . '">' . esc_html__('Upgrade to a paid plan', 'ai-translator-for-elementor-and-polylang') . '</a>';
                echo ' ' . esc_html__('to start translating immediately.', 'ai-translator-for-elementor-and-polylang') . '</p>';
                echo '</div>';
            }
        }
    }
    
    /**
     * Handle Freemius account connection (Free opt-in)
     * Called when user opts into Freemius and connects their account
     */
    public function on_freemius_connected() {
        AITRFOEL_Logger::log('Freemius account connected - initializing free user in remote database', 'info');
        $this->initialize_user_in_remote_db('Free', null);
    }
    
    /**
     * Handle license activation (Paid plan)
     * Called when user activates a paid license key
     */
    public function on_license_activated($license) {
        AITRFOEL_Logger::log('License activated - initializing paid user in remote database', 'info');
        
        $plan = 'Starter'; // Default to Starter if can't determine
        if (function_exists('eatp_fs') && eatp_fs()->is_registered()) {
            $plan_obj = eatp_fs()->get_plan();
            if ($plan_obj && isset($plan_obj->name)) {
                $plan = $plan_obj->name;
            }
        }
        
        $license_key = isset($license->secret_key) ? $license->secret_key : null;
        
        $this->initialize_user_in_remote_db($plan, $license_key);
    }
    
    /**
     * Handle plan change (Upgrade/Downgrade)
     * Called when user upgrades or downgrades their plan
     */
    public function on_plan_changed($old_plan, $new_plan) {
        $old_plan_name = isset($old_plan->name) ? $old_plan->name : 'Unknown';
        $new_plan_name = isset($new_plan->name) ? $new_plan->name : 'Unknown';

        AITRFOEL_Logger::log(sprintf(
            'Plan changed from %s to %s - updating remote database',
            $old_plan_name,
            $new_plan_name
        ), 'info');

        $license_key = null;
        if (function_exists('eatp_fs') && eatp_fs()->is_registered()) {
            try {
                $license = eatp_fs()->_get_license();
                if ($license && isset($license->secret_key)) {
                    $license_key = $license->secret_key;
                }
            } catch (Exception $e) {
                AITRFOEL_Logger::log('Could not get license key: ' . $e->getMessage(), 'warning');
            }
        }
        
        $this->update_plan_in_remote_db($new_plan_name, $license_key);
    }

    /**
     * Auto-register free user (FREE VERSION ONLY)
     * Called on plugin load to ensure site is registered with remote server
     */
    public function maybe_auto_register_free_user() {
        // Check if already registered
        if (get_option('aitrfoel_remote_user_initialized')) {
            return;
        }
        
        // Auto-register free user
        if (class_exists('AITRFOEL_Logger')) {
            AITRFOEL_Logger::log('Free version - auto-registering user in remote database', 'info');
        }
        
        $this->initialize_user_in_remote_db('Free', null);
    }
    
    /**
     * Initialize user in remote database
     * Creates a new user record with initial word count
     */
    private function initialize_user_in_remote_db($plan, $license_key) {
        $site_url = home_url();
        
        $body = json_encode([
            'site_url' => $site_url,
            'license_key' => $license_key,
            'plan' => $plan,
            'action' => 'initialize',
            'month' => gmdate('Y-m')
        ]);

        AITRFOEL_Logger::log('Initializing user in remote DB: ' . $body, 'info');

        $response = wp_remote_post(
            $this->api_base_url . '/initialize-user',
            [
                'body' => $body,
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 30,
                'sslverify' => true
            ]
        );

        if (is_wp_error($response)) {
            AITRFOEL_Logger::log('Initialize user failed: ' . $response->get_error_message(), 'error');
            return false;
        }

        $result = json_decode(wp_remote_retrieve_body($response), true);
        
        AITRFOEL_Logger::log('Initialize user response: ' . wp_remote_retrieve_body($response), 'info');
        
        if (isset($result['success']) && $result['success']) {
            AITRFOEL_Logger::log('User initialized successfully in remote database', 'success');
            
            // Cache locally
            update_option('aitrfoel_license_activated_at', current_time('mysql'));
            update_option('aitrfoel_remote_user_initialized', true);
            update_option('aitrfoel_initial_plan', $plan);
            
            // Update local cache with initial values
            if (isset($result['data'])) {
                $this->update_local_cache($result['data']);
            }
            
            return true;
        } else {
            $error_msg = isset($result['error']) ? $result['error'] : 'Unknown error';
            AITRFOEL_Logger::log('Failed to initialize user: ' . $error_msg, 'error');
            return false;
        }
    }
    
    /**
     * Update plan in remote database
     * Updates existing user's plan and word limit
     */
    private function update_plan_in_remote_db($plan, $license_key) {
        $site_url = home_url();
        
        $body = json_encode([
            'site_url' => $site_url,
            'license_key' => $license_key,
            'plan' => $plan,
            'action' => 'update_plan'
        ]);

        AITRFOEL_Logger::log('Updating plan in remote DB: ' . $body, 'info');

        $response = wp_remote_post(
            $this->api_base_url . '/update-plan',
            [
                'body' => $body,
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 30,
                'sslverify' => true
            ]
        );

        if (is_wp_error($response)) {
            AITRFOEL_Logger::log('Update plan failed: ' . $response->get_error_message(), 'error');
            return false;
        }

        $result = json_decode(wp_remote_retrieve_body($response), true);
        
        AITRFOEL_Logger::log('Update plan response: ' . wp_remote_retrieve_body($response), 'info');
        
        if (isset($result['success']) && $result['success']) {
            AITRFOEL_Logger::log('Plan updated successfully in remote database', 'success');
            
            // Update local cache
            if (isset($result['data'])) {
                $this->update_local_cache($result['data']);
            }
            
            return true;
        } else {
            $error_msg = isset($result['error']) ? $result['error'] : 'Unknown error';
            AITRFOEL_Logger::log('Failed to update plan: ' . $error_msg, 'error');
            return false;
        }
    }
    
    /**
     * Update local cache with remote data
     */
    private function update_local_cache($data) {
        if (isset($data['words_used'])) {
            update_option('aitrfoel_words_used_this_month', intval($data['words_used']));
        }
        if (isset($data['word_limit'])) {
            update_option('aitrfoel_word_limit', intval($data['word_limit']));
        }
        if (isset($data['plan'])) {
            update_option('aitrfoel_current_plan', sanitize_text_field($data['plan']));
        }
    }
}

