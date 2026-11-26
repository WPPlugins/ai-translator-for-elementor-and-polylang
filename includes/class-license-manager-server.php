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
    private $api_base_url = 'https://api.meeptech.com/api/eat'; // Dedicated High-Speed Translation API
    private $cached_license_key = null; // Cache license key to avoid Freemius calls
    private $cached_license_status = null; // Cache license status per-request to avoid repeat HTTP calls
    
    /**
     * Get dynamic API base URL (supports constant override and filters)
     * @return string
     */
    public function get_api_base_url() {
        if (defined('AITRFOEL_API_BASE_URL')) {
            return AITRFOEL_API_BASE_URL;
        }
        return apply_filters('aitrfoel_api_base_url', $this->api_base_url);
    }

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
        
        // Admin notices for license expired
        add_action('admin_notices', [$this, 'show_license_expired_notice']);
        
        // Word limit usage notices (80% warning + review nudge / 100% blocking warning)
        add_action('admin_notices', [$this, 'show_word_limit_notices']);
        
        // AJAX handler for dismissing license expired notice
        add_action('wp_ajax_aitrfoel_dismiss_license_expired', [$this, 'dismiss_license_expired_notice']);
        
        // AJAX handlers for dismissing word limit notices
        add_action('wp_ajax_aitrfoel_dismiss_80_notice', [$this, 'dismiss_80_notice']);
        add_action('wp_ajax_aitrfoel_dismiss_100_notice', [$this, 'dismiss_100_notice']);
        
        // Freemius activation hooks (initialize user in remote DB)
        add_action('aitrfoel_fs_loaded', [$this, 'init_freemius_hooks']);

        // Auto-register Free/unlicensed site in remote DB (once per month)
        add_action('admin_init', [$this, 'maybe_auto_register_free_user']);
        
        // Auto-sync plan on admin pages (checks once per hour)
        add_action('admin_init', [$this, 'maybe_sync_plan']);
        
        // AJAX handler for manual plan sync
        add_action('wp_ajax_aitrfoel_sync_plan', [$this, 'ajax_sync_plan']);
    }
    
    /**
     * Initialize Freemius hooks after SDK is loaded
     */
    public function init_freemius_hooks() {
        if (function_exists('eatp_fs') && is_object(eatp_fs()) && method_exists(eatp_fs(), 'add_action')) {
            // When user opts in (connects Freemius account) - Free plan
            eatp_fs()->add_action('after_account_connection', [$this, 'on_freemius_connected']);
            
            // When user activates a paid license
            eatp_fs()->add_action('after_license_activation', [$this, 'on_license_activated']);
            
            // When user changes plan (upgrade/downgrade)
            eatp_fs()->add_action('after_plan_change', [$this, 'on_plan_changed'], 10, 2);

            // When license or plan is synced (e.g. "Sync License" button, cron, or account sync)
            eatp_fs()->add_action('after_account_plan_sync', [$this, 'on_account_plan_synced']);
            eatp_fs()->add_action('after_license_change', [$this, 'on_license_changed'], 10, 2);
            eatp_fs()->add_action('after_plans_sync', [$this, 'clear_status_cache']);
        }
    }

    /**
     * Get plan name directly from local Freemius SDK if active
     */
    public function get_freemius_plan_name() {
        if (!function_exists('eatp_fs') || !is_object(eatp_fs())) {
            return null;
        }

        try {
            if (method_exists(eatp_fs(), 'is_registered') && eatp_fs()->is_registered()) {
                // Method 1: Check active license plan_id
                if (method_exists(eatp_fs(), '_get_license')) {
                    $license = eatp_fs()->_get_license();
                    if ($license && !empty($license->plan_id) && method_exists(eatp_fs(), '_get_plan_by_id')) {
                        $plan = eatp_fs()->_get_plan_by_id($license->plan_id);
                        if ($plan && !empty($plan->name)) {
                            return $plan->name;
                        }
                    }
                }

                // Method 2: Check get_plan_name()
                if (method_exists(eatp_fs(), 'get_plan_name')) {
                    $plan_name = eatp_fs()->get_plan_name();
                    if (!empty($plan_name) && strtolower($plan_name) !== 'free' && strtolower($plan_name) !== 'plan_name') {
                        return $plan_name;
                    }
                }

                // Method 3: Check get_plan()
                if (method_exists(eatp_fs(), 'get_plan')) {
                    $plan = eatp_fs()->get_plan();
                    if ($plan && !empty($plan->name)) {
                        return $plan->name;
                    }
                }
            }
        } catch (Exception $e) {
            // Ignore
        }

        return null;
    }
    
    /**
     * Get license key from Freemius (with caching to avoid repeated SDK calls)
     */
    public function get_license_key() {
        // Return cached value if available
        if ($this->cached_license_key !== null) {
            return $this->cached_license_key;
        }
        
        // During background translation AJAX, avoid calling Freemius SDK
        $is_translation_ajax = defined('DOING_AJAX') && DOING_AJAX && 
                              isset($_POST['action']) && 
                              in_array($_POST['action'], ['aitrfoel_translate_page', 'aitrfoel_process_bulk_batch']);
        
        if ($is_translation_ajax) {
            // Use cached value from WordPress options (set during admin page load)
            $cached_key = get_option('aitrfoel_cached_license_key', '');
            $this->cached_license_key = $cached_key ?: null;
            return $this->cached_license_key;
        }
        
        // Normal flow: Get from Freemius (only in admin context)
        if (function_exists('eatp_fs') && is_object(eatp_fs()) && method_exists(eatp_fs(), 'is_registered') && eatp_fs()->is_registered()) {
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
     * Clear cached license status so the next call to get_license_status() re-fetches from server.
     */
    public function clear_status_cache() {
        $this->cached_license_status = null;
        $this->cached_license_key = null;
        delete_transient('aitrfoel_license_status_cache');
        delete_transient('aitrfoel_last_plan_sync_check');
        delete_option('aitrfoel_cached_license_key');
    }
    
    /**
     * Get current license status from server
     */
    public function get_license_status() {
        // Return cached result if already fetched this request
        if ($this->cached_license_status !== null) {
            return $this->cached_license_status;
        }
        
        // PERFORMANCE: Check if there is a transient cache
        $cached = get_transient('aitrfoel_license_status_cache');
        if ($cached !== false && is_array($cached)) {
            // Check if Freemius locally upgraded to a paid tier while transient was cached as Free
            $fs_plan = $this->get_freemius_plan_name();
            $cached_is_free = empty($cached['plan']) || strtolower($cached['plan']) === 'free';
            $fs_is_paid = !empty($fs_plan) && strtolower($fs_plan) !== 'free';

            if ($fs_is_paid && $cached_is_free) {
                // Cache is stale - bust it
                $this->clear_status_cache();
            } else {
                $this->cached_license_status = $cached;
                return $this->cached_license_status;
            }
        }
        
        $plan_name = 'Free';
        $word_limit = 7200;
        
        // Get license key (uses cache during AJAX)
        $license_key = $this->get_license_key();
        
        // Get REAL usage AND plan from server (server is source of truth)
        $server_usage = $this->get_usage_from_server($license_key);
        
        // Use plan and limit from server if available
        if (isset($server_usage['plan'])) {
            $plan_name = $server_usage['plan'];
        }

        // AUTHORITY OVERRIDE: If Freemius locally is registered with a verified paid plan,
        // but the remote server returned 'Free' or empty (e.g. before webhook arrival or delay),
        // Freemius's verified paid plan takes precedence!
        $fs_plan = $this->get_freemius_plan_name();
        if (!empty($fs_plan) && strtolower($fs_plan) !== 'free') {
            if (empty($plan_name) || strtolower($plan_name) === 'free' || (strtolower($fs_plan) === 'agency lifetime' && strtolower($plan_name) !== 'agency lifetime')) {
                $plan_name = $fs_plan;
                // Dispatch update to remote server so DB is synced immediately
                if (!empty($license_key)) {
                    $this->update_plan_in_remote_db($plan_name, $license_key);
                }
            }
        }

        if (isset($server_usage['word_limit']) && strtolower($plan_name) === strtolower($server_usage['plan'] ?? '')) {
            $word_limit = $server_usage['word_limit'];
        } else {
            // Calculate from authoritative plan name
            $word_limit = $this->get_word_limit_for_plan($plan_name);
        }
        
        // Add addon words to the effective limit when the 50K addon is active.
        $addon_words = 0;
        if (!empty($server_usage['addon_active']) && isset($server_usage['addon_words'])) {
            $addon_words = intval($server_usage['addon_words']);
            if ($word_limit !== -1) {
                $word_limit += $addon_words;
            }
        }

        $words_used = $server_usage['words_used'];
        $words_left = $word_limit === -1 ? -1 : max(0, $word_limit - $words_used);
        
        $is_unlimited = ($word_limit === -1 || strtolower($plan_name) === 'agency lifetime');
        $cooldown_end = (int) get_option('aitrfoel_cooldown_end_date', 0);
        $cooldown_active = false;
        if (!$is_unlimited && strtolower($plan_name) === 'free') {
            $cooldown_active = isset($server_usage['cooldown_active'])
                ? (bool) $server_usage['cooldown_active']
                : ($cooldown_end > 0 && $cooldown_end > time() && $words_used >= $word_limit);
        }

        $this->cached_license_status = [
            'plan' => $plan_name,
            'word_limit' => $word_limit,
            'words_used' => $words_used,
            'words_left' => $words_left,
            'license_key' => $license_key,
            'unlimited' => $is_unlimited,
            'is_unlimited' => $is_unlimited,
            'freemius_validated' => isset($server_usage['freemius_validated']) ? $server_usage['freemius_validated'] : false,
            'license_expired' => isset($server_usage['license_expired']) ? $server_usage['license_expired'] : false,
            'addon_active' => isset($server_usage['addon_active']) ? (bool) $server_usage['addon_active'] : false,
            'addon_words' => $addon_words,
            'cooldown_active' => $cooldown_active,
            'social_bonus_claimed' => isset($server_usage['social_bonus_claimed']) ? (bool) $server_usage['social_bonus_claimed'] : (bool) get_option('aitrfoel_share_bonus_claimed', false),
        ];
        
        // Cache for 10 minutes to prevent excessive server requests on general page loads
        set_transient('aitrfoel_license_status_cache', $this->cached_license_status, 10 * MINUTE_IN_SECONDS);
        
        return $this->cached_license_status;
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
            
            // Include addon license key if the 50K Words Pack addon is active
            if (function_exists('e50wp_fs')) {
                $addon_fs = e50wp_fs();
                if (method_exists($addon_fs, '_get_license')) {
                    $addon_license = $addon_fs->_get_license();
                    if ($addon_license && !empty($addon_license->secret_key)) {
                        $request_data['addon_license_key'] = $addon_license->secret_key;
                    }
                }
            }
            
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log('🔍 Requesting usage from server: ' . $this->api_base_url . '/get-usage', 'info');
                AITRFOEL_Logger::log('📦 Request data: ' . json_encode($request_data), 'info');
            }
            
            $response = wp_remote_post($this->api_base_url . '/get-usage', [
                'timeout' => 5,
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
                
                // NEW: Handle Freemius validation fields
                if (isset($data['freemius_validated'])) {
                    $result['freemius_validated'] = (bool) $data['freemius_validated'];
                }
                if (isset($data['license_expired'])) {
                    $result['license_expired'] = (bool) $data['license_expired'];
                    
                    // If license expired, show admin notice
                    if ($data['license_expired']) {
                        set_transient('aitrfoel_license_expired', true, HOUR_IN_SECONDS);
                        
                        if (class_exists('AITRFOEL_Logger')) {
                            AITRFOEL_Logger::log('⚠️ License expired - downgraded to Free plan', 'warning');
                        }
                    } else {
                        // License is valid, clear any expired notice
                        delete_transient('aitrfoel_license_expired');
                    }
                }
                
                // Handle addon boost fields
                if (isset($data['addon_active'])) {
                    $result['addon_active'] = (bool) $data['addon_active'];
                }
                if (isset($data['addon_words'])) {
                    $result['addon_words'] = intval($data['addon_words']);
                }

                // Sync social share bonus status from server (auto-heals any prior failed local locks)
                if (isset($data['social_bonus_claimed'])) {
                    $result['social_bonus_claimed'] = (bool) $data['social_bonus_claimed'];
                    if ($data['social_bonus_claimed'] === true) {
                        if (!get_option('aitrfoel_share_bonus_claimed', false)) {
                            update_option('aitrfoel_share_bonus_claimed', time());
                        }
                    } elseif ($data['social_bonus_claimed'] === false && get_option('aitrfoel_share_bonus_claimed', false)) {
                        delete_option('aitrfoel_share_bonus_claimed');
                    }
                }
                
                // Handle cooldown status from server (syncs when admin resets usage)
                if (isset($data['cooldown_active'])) {
                    $result['cooldown_active'] = (bool) $data['cooldown_active'];
                    if ($data['cooldown_active'] === true && isset($data['cooldown_ends_at'])) {
                        // Server says cooldown is active - sync to local
                        $cooldown_end = strtotime($data['cooldown_ends_at']);
                        if ($cooldown_end && $cooldown_end > time()) {
                            update_option('aitrfoel_cooldown_end_date', $cooldown_end);
                        }
                    } elseif ($data['cooldown_active'] === false) {
                        // Server says NO cooldown - clear local (admin reset usage)
                        $local_cooldown = get_option('aitrfoel_cooldown_end_date', 0);
                        if ($local_cooldown > 0) {
                            delete_option('aitrfoel_cooldown_end_date');
                            if (class_exists('AITRFOEL_Logger')) {
                                AITRFOEL_Logger::log('✅ Cooldown cleared - server confirmed no active cooldown', 'info');
                            }
                        }
                    }
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
                'word_limit' => 7200,
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
                    'word_limit' => 7200,
                    'active_sites' => 1,
                    'plan' => 'Free'
                ];
            }
            
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (isset($data['success']) && $data['success']) {
                return [
                    'total_words_used' => $data['total_words_used'] ?? 0,
                    'word_limit' => $data['word_limit'] ?? 7200,
                    'active_sites' => $data['active_sites'] ?? 1,
                    'plan' => $data['plan'] ?? 'Free'
                ];
            }
            
            return [
                'total_words_used' => 0,
                'word_limit' => 7200,
                'active_sites' => 1,
                'plan' => 'Free'
            ];
            
        } catch (Exception $e) {
            AITRFOEL_Logger::log('❌ Exception getting license usage: ' . $e->getMessage(), 'error');
            return [
                'total_words_used' => 0,
                'word_limit' => 7200,
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
                'word_count' => AITRFOEL_Translation_Engine::count_words($text),
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
            // Clear status cache to force fresh data on next read
            $this->clear_status_cache();
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
        $normalized = strtolower(trim((string) $plan_name));
        $limits = [
            'free'            => 7200,               // Free: 7,200 words/month (1 site)
            'starter'         => 18000,              // Starter: 18,000 words/month (1 site)
            'professional'    => 60000,              // Professional: 60,000 words/month
            'agency'          => 89000,              // Agency: 89,000 words/month (up to 10 sites, shared)
            'agency lifetime' => -1,                 // Agency Lifetime: unlimited words (up to 50 sites)
            'enterprise'      => 355000,             // Enterprise: 355,000 words/month (up to 50 sites, shared)
        ];
        
        return $limits[$normalized] ?? 7200;
    }
    
    /**
     * Check if monthly cooldown expired and reset word count for free users
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
                // Cooldown expired - the server is the source of truth for usage (monthly quota);
                // local count is only cleared if the server confirms words_used === 0.
                delete_option('aitrfoel_cooldown_end_date');
                
                // Notify server about the reset
                $this->notify_server_of_cooldown_reset();
            }
        }
    }
    
    /**
     * Notify server that monthly cooldown reset occurred
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
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);
                
                // Handle server response - clear cooldown when server confirms reset
                if (isset($data['success']) && $data['success'] && isset($data['words_used']) && $data['words_used'] === 0) {
                    delete_option('aitrfoel_cooldown_end_date');
                    update_option('aitrfoel_words_used_this_month', 0);
                }
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
        // If user has a paid/unlimited license, cooldown notices don't apply.
        // This handles the case where a site has both a free entry and a paid
        // license on the server — the free entry may trigger cooldown but the
        // paid license should take priority.
        $status = $this->get_license_status();
        if ($status && (
            (isset($status['word_limit']) && $status['word_limit'] === -1) ||
            (isset($status['plan']) && $status['plan'] !== 'Free' && $status['plan'] !== 'Free User')
        )) {
            // Paid plan — clear any stale cooldown state and skip notice
            delete_transient('aitrfoel_cooldown_activated');
            delete_option('aitrfoel_cooldown_end_date');
            return;
        }

        // Check if cooldown was just activated
        if (get_transient('aitrfoel_cooldown_activated')) {
            delete_transient('aitrfoel_cooldown_activated');
            
            $cooldown_end = get_option('aitrfoel_cooldown_end_date', 0);
            $days_left = ceil(($cooldown_end - time()) / DAY_IN_SECONDS);
            
            $upgrade_url = (function_exists('eatp_fs') && is_object(eatp_fs()) && method_exists(eatp_fs(), 'is_registered') && eatp_fs()->is_registered())
                ? eatp_fs()->get_upgrade_url()
                : ((defined('AITRFOEL_VERSION_TYPE') && AITRFOEL_VERSION_TYPE === 'pro')
                    ? 'https://meeptech.com/upgrade?utm_source=wp-plugin&utm_medium=free-plugin&utm_campaign=pro-upgrade'
                    : aitrfoel_free_upgrade_url('cooldown-notice'));
            
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>' . esc_html__('Meep Translator: Monthly Limit Reached', 'ai-translator-for-elementor-and-polylang') . '</strong></p>';
            echo '<p>' . sprintf(
                /* translators: %d: Number of days until word count resets */
                esc_html__('You have used all 7,200 words in your free plan this month. Your word count will reset in %d days, or you can ', 'ai-translator-for-elementor-and-polylang'),
                absint($days_left)
            );
            echo '<a href="' . esc_url($upgrade_url) . '">' . esc_html__('upgrade now', 'ai-translator-for-elementor-and-polylang') . '</a>';
            echo ' ' . esc_html__('for immediate access to more words.', 'ai-translator-for-elementor-and-polylang') . '</p>';
            echo '</div>';
        }
        
        // Note: Persistent cooldown notice has been moved to show_word_limit_notices()
    }
    
    /**
     * Show license expired admin notice
     */
    public function show_license_expired_notice() {
        // Check if license is expired
        if (!get_transient('aitrfoel_license_expired')) {
            return;
        }
        
        // Check if user dismissed the notice recently (24 hours)
        if (get_transient('aitrfoel_license_expired_dismissed')) {
            return;
        }
        
        // Only show on plugin pages or dashboard
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }
        
        $show_on_screens = [
            'dashboard',
            'toplevel_page_ai-translator-for-elementor-and-polylang',
            'ai-translator_page_ai-translator-for-elementor-and-polylang-pricing',
            'ai-translator_page_ai-translator-for-elementor-and-polylang-account'
        ];
        
        if (!in_array($screen->id, $show_on_screens) && strpos($screen->id, 'ai-translator') === false) {
            return;
        }
        
        $renew_url = (function_exists('eatp_fs') && is_object(eatp_fs()) && method_exists(eatp_fs(), 'is_registered') && eatp_fs()->is_registered())
            ? eatp_fs()->get_upgrade_url() 
            : aitrfoel_free_upgrade_url('limit-notice');
        
        ?>
        <div class="notice notice-warning is-dismissible aitrfoel-license-expired-notice">
            <h3 style="margin: 0.5em 0; color: #856404;">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#856404" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -3px; margin-right: 4px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <?php esc_html_e('Meep Translator License Expired', 'ai-translator-for-elementor-and-polylang'); ?>
            </h3>
            <p>
                <?php esc_html_e('Your Meep AI Translator license has expired or been cancelled.', 'ai-translator-for-elementor-and-polylang'); ?>
                <br>
                <?php esc_html_e('You have been downgraded to the', 'ai-translator-for-elementor-and-polylang'); ?>
                <strong><?php esc_html_e('Free plan (7,200 words/month)', 'ai-translator-for-elementor-and-polylang'); ?></strong>.
            </p>
            <p>
                <a href="<?php echo esc_url($renew_url); ?>" class="button button-primary">
                    <?php esc_html_e('Renew License', 'ai-translator-for-elementor-and-polylang'); ?>
                </a>
                <a href="#" class="button aitrfoel-dismiss-expired-notice" style="margin-left: 10px;">
                    <?php esc_html_e('Dismiss', 'ai-translator-for-elementor-and-polylang'); ?>
                </a>
            </p>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('.aitrfoel-dismiss-expired-notice').on('click', function(e) {
                e.preventDefault();
                $.post(ajaxurl, {
                    action: 'aitrfoel_dismiss_license_expired',
                    nonce: '<?php echo esc_js(wp_create_nonce('aitrfoel_dismiss_license_expired')); ?>'
                });
                $(this).closest('.notice').fadeOut();
            });
            
            // Also handle the X button dismiss
            $(document).on('click', '.aitrfoel-license-expired-notice .notice-dismiss', function() {
                $.post(ajaxurl, {
                    action: 'aitrfoel_dismiss_license_expired',
                    nonce: '<?php echo esc_js(wp_create_nonce('aitrfoel_dismiss_license_expired')); ?>'
                });
            });
        });
        </script>
        <style>
            .aitrfoel-license-expired-notice {
                border-left-color: #f0ad4e;
                background: #fff9e6;
                padding: 10px 15px;
            }
        </style>
        <?php
    }
    
    /**
     * AJAX handler to dismiss license expired notice
     */
    public function dismiss_license_expired_notice() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aitrfoel_dismiss_license_expired')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        // Set transient to hide notice for 24 hours
        set_transient('aitrfoel_license_expired_dismissed', true, DAY_IN_SECONDS);
        
        wp_send_json_success();
    }
    
    /**
     * Show premium word limit usage notices (80% nudge + review request, 100% blocking notice)
     */
    public function show_word_limit_notices() {
        // Only run for users who can manage options
        if (!current_user_can('manage_options')) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        // Get license status
        $status = $this->get_license_status();
        if (!$status) {
            return;
        }

        $plan = isset($status['plan']) ? $status['plan'] : 'Free';
        $limit = isset($status['word_limit']) ? intval($status['word_limit']) : 0;
        $used = isset($status['words_used']) ? intval($status['words_used']) : 0;

        // Skip if unlimited plan
        if ($limit === -1 || $limit <= 0) {
            return;
        }

        // Calculate usage percentage
        // Calculate usage percentage
        $pct = min(100, round(($used / $limit) * 100));

        // CASE 0: Review Prompt after 3+ translated pages (or 2,500+ words) when not showing usage warnings
        if ($pct < 80) {
            $rev_dismissed = (bool) get_option('aitrfoel_review_dismissed', false);
            $rev_snoozed   = (int) get_option('aitrfoel_review_snoozed_until', 0);
            $rev_count     = (int) get_option('aitrfoel_successful_translations_count', 0);
            $rev_activated = strtotime((string) get_option('aitrfoel_license_activated_at', '')) ?: time();
            if (!$rev_dismissed && time() > $rev_snoozed && ($rev_count >= 3 || $used >= 2500) && (time() - $rev_activated) >= 3 * DAY_IN_SECONDS) {
                ?>
                <div class="notice notice-info is-dismissible aitrfoel-review-milestone-notice" id="aitrfoel-review-milestone-notice" style="border-left: 4px solid #f59e0b !important; background: #ffffff !important; border-radius: 10px !important; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04) !important; padding: 16px 20px !important; margin: 15px 0 !important;">
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                        <div style="display: flex; align-items: flex-start; gap: 14px; flex: 1 1 360px;">
                            <div style="color: #f59e0b; font-size: 22px; line-height: 1; margin-top: 2px;">★★★★★</div>
                            <div>
                                <h4 style="font-size: 15px !important; font-weight: 700 !important; color: #0f172a !important; margin: 0 0 4px 0 !important;">
                                    <?php
                                    if ($rev_count >= 3) {
                                        printf(/* translators: %d: number of pages translated */ esc_html__('Milestone unlocked: %d+ pages translated with Meep AI Translator!', 'ai-translator-for-elementor-and-polylang'), absint($rev_count));
                                    } else {
                                        printf(/* translators: %s: number of words translated */ esc_html__('Milestone unlocked: %s+ words translated with Meep AI Translator!', 'ai-translator-for-elementor-and-polylang'), esc_html(number_format_i18n($used)));
                                    }
                                    ?>
                                </h4>
                                <p style="font-size: 13px !important; color: #475569 !important; margin: 0 !important; line-height: 1.5 !important;">
                                    <?php esc_html_e('Has Meep saved you time translating your site? A quick review on WordPress.org takes 30 seconds and helps our small team keep building great features!', 'ai-translator-for-elementor-and-polylang'); ?>
                                </p>
                            </div>
                        </div>
                        <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 8px;">
                            <a href="https://wordpress.org/support/plugin/ai-translator-for-elementor-and-polylang/reviews/?rate=5#new-post" target="_blank" rel="noopener noreferrer" class="button button-primary aitrfoel-review-action-btn" data-mode="done" style="background: #1447e6 !important; border-color: #1447e6 !important; border-radius: 6px !important; font-weight: 600 !important; padding: 0 14px !important; height: 32px !important; line-height: 30px !important; color: #fff !important;">
                                ★ <?php esc_html_e('Leave a Review', 'ai-translator-for-elementor-and-polylang'); ?>
                            </a>
                            <button type="button" class="button aitrfoel-review-action-btn" data-mode="snooze" style="border-radius: 6px !important; height: 32px !important; line-height: 30px !important;">
                                <?php esc_html_e('Remind me later', 'ai-translator-for-elementor-and-polylang'); ?>
                            </button>
                            <button type="button" class="button-link aitrfoel-review-action-btn" data-mode="dismiss" style="color: #64748b !important; font-size: 12px !important; text-decoration: none !important; margin-left: 4px !important;">
                                <?php esc_html_e('Already reviewed', 'ai-translator-for-elementor-and-polylang'); ?>
                            </button>
                        </div>
                    </div>
                </div>
                <script>
                    jQuery(document).ready(function($) {
                        $('#aitrfoel-review-milestone-notice').on('click', '.aitrfoel-review-action-btn, .notice-dismiss', function(e) {
                            var mode = $(this).data('mode') || 'dismiss';
                            $('#aitrfoel-review-milestone-notice').fadeOut(150, function() { $(this).remove(); });
                            $.post(ajaxurl, {
                                action: 'aitrfoel_dismiss_review',
                                nonce: '<?php echo esc_js(wp_create_nonce("aitrfoel_ajax_nonce")); ?>',
                                mode: mode
                            });
                        });
                    });
                </script>
                <?php
            }
            return;
        }

        // Check if in cooldown (only applicable to Free plan)
        $cooldown_end = get_option('aitrfoel_cooldown_end_date', 0);
        $is_cooldown = (strtolower($plan) === 'free' && $cooldown_end > time());
        
        $upgrade_url = (function_exists('eatp_fs') && is_object(eatp_fs()) && method_exists(eatp_fs(), 'is_registered') && eatp_fs()->is_registered())
            ? eatp_fs()->get_upgrade_url()
            : ((defined('AITRFOEL_VERSION_TYPE') && AITRFOEL_VERSION_TYPE === 'pro') ? 'https://meeptech.com/upgrade?utm_source=wp-plugin&utm_medium=free-plugin&utm_campaign=pro-upgrade' : aitrfoel_free_upgrade_url('limit-notice'));

        // Check if we are on a plugin page
        $is_plugin_page = (strpos($screen->id, 'ai-translator') !== false);

        // CASE 1: 100% Usage or Cooldown Active (Blocking Warning)
        if ($used >= $limit || $is_cooldown) {
            // Check if dismissed on general (non-plugin) pages
            if (!$is_plugin_page && get_transient('aitrfoel_limit_100_dismissed')) {
                return;
            }

            // Determine if notice should be dismissible
            $is_dismissible = !$is_plugin_page;
            
            // Calculate days left for cooldown
            $days_left = 0;
            if ($is_cooldown) {
                $days_left = ceil(($cooldown_end - time()) / DAY_IN_SECONDS);
            }

            $tm_stats = class_exists('AITRFOEL_Translation_Memory') ? AITRFOEL_Translation_Memory::get_instance()->get_stats() : ['total_words_saved' => 0];
            $words_saved_tm = isset($tm_stats['total_words_saved']) ? intval($tm_stats['total_words_saved']) : 0;

            $inventory = class_exists('AITRFOEL_Settings') ? AITRFOEL_Settings::get_site_content_inventory() : null;
            $untranslated_words = $inventory && isset($inventory['untranslated_words']) ? intval($inventory['untranslated_words']) : 0;
            $rec_plan = class_exists('AITRFOEL_Settings') ? AITRFOEL_Settings::get_recommended_plan_for_words($untranslated_words) : null;
            $rec_plan_name = $rec_plan ? $rec_plan['name'] : 'Starter';
            $rec_plan_url = $rec_plan && !empty($rec_plan['upgrade_url']) ? $rec_plan['upgrade_url'] : $upgrade_url;

            ?>
            <div class="notice notice-error aitrfoel-usage-notice-100 <?php echo $is_dismissible ? 'is-dismissible' : ''; ?>" id="aitrfoel-usage-notice-100" style="border-left: 4px solid #1447e6 !important; background: linear-gradient(135deg, #ffffff 75%, #eff6ff 100%) !important; border-radius: 12px !important; border: 1px solid #dbeafe !important; box-shadow: 0 10px 25px -5px rgba(20, 71, 230, 0.08) !important; padding: 22px 26px !important; margin: 15px 0 !important; position: relative;">
                <div class="aitrfoel-notice-flex-100" style="display: flex; align-items: flex-start; gap: 18px;">
                    <div class="aitrfoel-notice-icon-100" style="font-size: 0; line-height: 1; margin-top: 2px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#1447e6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                    </div>
                    <div class="aitrfoel-notice-text-wrapper-100" style="display: flex; flex-direction: column; gap: 6px; width: 100%;">
                        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                            <span class="aitrfoel-notice-eyebrow-100" style="font-size: 11px; font-family: 'JetBrains Mono', monospace; text-transform: uppercase; color: #1e40af; background: #dbeafe; padding: 2px 8px; border-radius: 4px; letter-spacing: 0.05em; font-weight: 600;">
                                <?php esc_html_e('Monthly Free Word Cap Reached', 'ai-translator-for-elementor-and-polylang'); ?>
                            </span>
                            <?php if ($days_left > 0): ?>
                                <span style="font-size: 12px; color: #64748b; font-weight: 500; display: inline-flex; align-items: center; gap: 4px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                    <?php printf(/* translators: %d: days left */ esc_html__('Resets in %d days', 'ai-translator-for-elementor-and-polylang'), absint($days_left)); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <h4 class="aitrfoel-notice-title-100" style="font-size: 17px !important; font-weight: 700 !important; color: #0e1116 !important; margin: 2px 0 0 0 !important;">
                            <?php printf(/* translators: %s: number of words translated */ esc_html__("You've translated %s words with Meep AI!", 'ai-translator-for-elementor-and-polylang'), esc_html(number_format_i18n($limit))); ?>
                        </h4>
                        
                        <!-- Value & ROI Breakdown Metrics Grid -->
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin: 10px 0 12px 0;">
                            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                                <div style="font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 600;"><?php esc_html_e('Plan Words Used', 'ai-translator-for-elementor-and-polylang'); ?></div>
                                <div style="font-size: 16px; font-weight: 700; color: #0f172a; margin-top: 2px;"><?php echo esc_html(number_format_i18n($used)); ?> / <?php echo esc_html(number_format_i18n($limit)); ?></div>
                            </div>
                            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 10px 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                                <div style="font-size: 11px; color: #166534; text-transform: uppercase; font-weight: 600; display: flex; align-items: center; gap: 4px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#166534" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                                    <?php esc_html_e('Translation Memory Bonus', 'ai-translator-for-elementor-and-polylang'); ?>
                                </div>
                                <div style="font-size: 16px; font-weight: 700; color: #15803d; margin-top: 2px;">
                                    <?php printf(/* translators: %s: number of words saved */ esc_html__('+%s words saved for FREE', 'ai-translator-for-elementor-and-polylang'), esc_html(number_format_i18n($words_saved_tm))); ?>
                                </div>
                            </div>
                            <?php if ($untranslated_words > 0): ?>
                            <div style="background: #fffbeb; border: 1px solid #fef3c7; border-radius: 8px; padding: 10px 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                                <div style="font-size: 11px; color: #92400e; text-transform: uppercase; font-weight: 600; display: flex; align-items: center; gap: 4px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#92400e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                                    <?php esc_html_e('Remaining Content', 'ai-translator-for-elementor-and-polylang'); ?>
                                </div>
                                <div style="font-size: 16px; font-weight: 700; color: #b45309; margin-top: 2px;">
                                    <?php printf(/* translators: %s: number of words still to translate */ esc_html__('~%s words to translate', 'ai-translator-for-elementor-and-polylang'), esc_html(number_format_i18n($untranslated_words))); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <p class="aitrfoel-notice-desc-100" style="font-size: 13.5px !important; color: #334155 !important; margin: 0 0 14px 0 !important; line-height: 1.5 !important;">
                            <?php if ($untranslated_words > 0 && $rec_plan): ?>
                                <?php printf(/* translators: %1$s: plan name, %2$s: plan monthly word limit */ esc_html__('Upgrade to the %1$s plan (%2$s words/mo) to seamlessly finish translating all remaining pages and products across your website without interruption.', 'ai-translator-for-elementor-and-polylang'), '<strong>' . esc_html($rec_plan_name) . '</strong>', esc_html(number_format_i18n($rec_plan['limit']))); ?>
                            <?php else: ?>
                                <?php esc_html_e('Upgrade to a premium plan to resume translating immediately and unlock unlimited autonomous auto-sync.', 'ai-translator-for-elementor-and-polylang'); ?>
                            <?php endif; ?>
                        </p>
                        <div class="aitrfoel-notice-actions-100" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                            <a href="<?php echo esc_url($rec_plan_url); ?>" class="button button-primary aitrfoel-btn-upgrade" style="background: #1447e6 !important; border-color: #1447e6 !important; border-radius: 6px !important; padding: 0 18px !important; height: 36px !important; line-height: 34px !important; font-weight: 600 !important; color: #fff !important; box-shadow: 0 4px 10px -1px rgba(20, 71, 230, 0.25) !important; transition: all 0.2s ease !important; text-decoration: none; font-size: 13.5px !important;">
                                <?php printf(/* translators: %s: plan name */ esc_html__('Upgrade to %s & Resume →', 'ai-translator-for-elementor-and-polylang'), esc_html($rec_plan_name)); ?>
                            </a>
                            <a href="<?php echo esc_url($upgrade_url); ?>" class="button button-secondary" style="border-radius: 6px !important; padding: 0 16px !important; height: 36px !important; line-height: 34px !important; font-weight: 500 !important; border-color: #cbd5e1 !important; background: #ffffff !important; color: #1e293b !important; text-decoration: none; font-size: 13px !important;">
                                <?php esc_html_e('View All Plans', 'ai-translator-for-elementor-and-polylang'); ?>
                            </a>
                            <?php if ($is_dismissible): ?>
                                <button type="button" class="button button-secondary aitrfoel-dismiss-100-btn" style="border-radius: 6px !important; padding: 0 14px !important; height: 36px !important; line-height: 34px !important; font-weight: 500 !important; border-color: transparent !important; background: transparent !important; color: #64748b !important; cursor: pointer;">
                                    <?php esc_html_e('Dismiss for 24h', 'ai-translator-for-elementor-and-polylang'); ?>
                                </button>
                            <?php endif; ?>
                        </div>

                        <?php if (strtolower($plan) === 'free' && !get_option('aitrfoel_share_bonus_claimed', false)): ?>
                        <div class="aitrfoel-notice-bonus-strip" style="margin-top: 12px; padding: 10px 14px; background: #f8fafc; border: 1px dashed #93c5fd; border-radius: 8px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                            <div style="font-size: 12.5px; color: #1e293b;">
                                <strong style="color: #0284c7;"><?php esc_html_e('Need a quick top-up? Claim +2,500 Free Bonus Words:', 'ai-translator-for-elementor-and-polylang'); ?></strong>
                                <span><?php esc_html_e('Share Meep AI Translator on X or LinkedIn to unlock +2,500 words immediately.', 'ai-translator-for-elementor-and-polylang'); ?></span>
                            </div>
                            <div class="aitrfoel-share-buttons" style="display: flex; align-items: center; gap: 8px;">
                                <button type="button" class="aitrfoel-global-share-btn" data-platform="x" style="display: inline-flex; align-items: center; gap: 6px; background: #0f172a; color: #fff; border: none; border-radius: 6px; padding: 6px 12px; font-size: 12px; font-weight: 600; cursor: pointer;">
                                    <span>𝕏</span>
                                    <span><?php esc_html_e('Share on X (+2,500)', 'ai-translator-for-elementor-and-polylang'); ?></span>
                                </button>
                                <button type="button" class="aitrfoel-global-share-btn" data-platform="linkedin" style="display: inline-flex; align-items: center; gap: 6px; background: #0a66c2; color: #fff; border: none; border-radius: 6px; padding: 6px 12px; font-size: 12px; font-weight: 600; cursor: pointer;">
                                    <span>in</span>
                                    <span><?php esc_html_e('Share on LinkedIn (+2,500)', 'ai-translator-for-elementor-and-polylang'); ?></span>
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <script>
                jQuery(document).ready(function($) {
                    $('#aitrfoel-usage-notice-100').on('click', '.aitrfoel-dismiss-100-btn, .notice-dismiss', function(e) {
                        e.preventDefault();
                        $('#aitrfoel-usage-notice-100').fadeTo(100, 0, function() {
                            $(this).slideUp(100, function() {
                                $(this).remove();
                            });
                        });
                        $.post(ajaxurl, {
                            action: 'aitrfoel_dismiss_100_notice',
                            nonce: '<?php echo esc_js(wp_create_nonce("aitrfoel_dismiss_100_notice")); ?>'
                        });
                    });
                    $('#aitrfoel-usage-notice-100').on('click', '.aitrfoel-global-share-btn', function(e) {
                        e.preventDefault();
                        var $btn = $(this);
                        var platform = $btn.data('platform') || 'x';
                        var productUrl = 'https://meeptech.com/products/ai-translator-for-elementor-polylang';
                        var shareText = "I'm using Meep AI Translator for WordPress to effortlessly translate Elementor pages with AI! Check it out:";
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(shareText + ' ' + productUrl).catch(function(){});
                        }
                        var shareUrl = platform === 'linkedin'
                            ? 'https://www.linkedin.com/sharing/share-offsite/?url=' + encodeURIComponent(productUrl)
                            : 'https://twitter.com/intent/tweet?text=' + encodeURIComponent(shareText) + '&url=' + encodeURIComponent(productUrl) + '&hashtags=WordPress,AI,Elementor,Polylang';
                        window.open(shareUrl, 'meep_share', 'width=600,height=450,toolbar=0,status=0');
                        var $container = $btn.closest('.aitrfoel-share-buttons');
                        $container.html('<span style="color:#0284c7;font-weight:600;font-size:12px;">Claiming +2,500 bonus words...</span>');
                        $.post(ajaxurl, {
                            action: 'aitrfoel_claim_share_bonus',
                            nonce: '<?php echo esc_js(wp_create_nonce("aitrfoel_ajax_nonce")); ?>',
                            platform: platform
                        }, function(res) {
                            if (res && res.success) {
                                $container.html('<span style="color:#059669;font-weight:700;font-size:12px;">✓ +2,500 Bonus Words Unlocked! Refreshing...</span>');
                                setTimeout(function() { window.location.reload(); }, 1200);
                            } else {
                                $container.html('<span style="color:#dc2626;font-size:12px;">' + (res && res.data && res.data.message ? res.data.message : 'Could not claim bonus words.') + '</span>');
                            }
                        });
                    });
                });
            </script>
            <?php
            return;
        }

        // CASE 2: 80% to 99.9% Usage (Gentle Warning Nudge + Viral Bonus on Free). Review invite intentionally kept separate from the bonus offer (WP.org review guidelines).
        if ($pct >= 80) {
            // Dismissed per billing month (YYYY-MM) so the warning re-arms on a new month
            $current_month = gmdate('Y-m');
            $dismissed = get_user_meta(get_current_user_id(), 'aitrfoel_dismiss_80_nudge', true);
            if ($dismissed === $current_month) {
                return;
            }

            $is_free_plan = (strtolower($plan) === 'free');
            $show_bonus_strip = ($is_free_plan && !get_option('aitrfoel_share_bonus_claimed', false));

            ?>
            <div class="notice notice-warning is-dismissible aitrfoel-usage-notice-80" id="aitrfoel-usage-notice-80" style="border-left: 4px solid #f4a622 !important; background: linear-gradient(135deg, #ffffff 70%, #fef3c7 100%) !important; border-radius: 12px !important; border: 1px solid #e6e8ee !important; box-shadow: 0 10px 25px -5px rgba(14, 17, 22, 0.05), 0 8px 10px -6px rgba(14, 17, 22, 0.05) !important; padding: 20px 24px !important; margin: 15px 0 !important; position: relative;">
                <div class="aitrfoel-notice-flex" style="display: flex; flex-wrap: wrap; align-items: stretch; gap: 24px;">
                    <div class="aitrfoel-notice-content-left" style="flex: 1 1 450px; display: flex; gap: 16px;">
                        <div class="aitrfoel-notice-icon" style="align-self: flex-start; margin-top: 4px; color: #1447e6;">
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                        </div>
                        <div class="aitrfoel-notice-text-wrapper" style="display: flex; flex-direction: column; gap: 4px;">
                            <span class="aitrfoel-notice-eyebrow" style="font-size: 10px; font-family: 'JetBrains Mono', monospace; text-transform: uppercase; color: #6b7280; letter-spacing: 0.05em; font-weight: 500;">
                                <?php esc_html_e('Meep AI Translator', 'ai-translator-for-elementor-and-polylang'); ?>
                            </span>
                            <h4 class="aitrfoel-notice-title" style="font-size: 15px !important; font-weight: 700 !important; color: #0e1116 !important; margin: 0 !important;">
                                <?php esc_html_e("You're getting close to your monthly word limit", 'ai-translator-for-elementor-and-polylang'); ?>
                            </h4>
                            <p class="aitrfoel-notice-desc" style="font-size: 13px !important; color: #2a2f3a !important; margin: 4px 0 12px 0 !important; line-height: 1.5 !important;">
                                <?php
                                if ($show_bonus_strip) {
                                    printf(
                                        /* translators: 1: Percentage of usage, 2: Words used, 3: Word limit */
                                        esc_html__("You have used %1\$s%% of your monthly translation words (%2\$s / %3\$s words). Upgrade your plan anytime or claim +2,500 free bonus words below.", 'ai-translator-for-elementor-and-polylang'),
                                        esc_html($pct),
                                        esc_html(number_format_i18n($used)),
                                        esc_html(number_format_i18n($limit))
                                    );
                                } else {
                                    printf(
                                        /* translators: 1: Percentage of usage, 2: Words used, 3: Word limit */
                                        esc_html__("You have used %1\$s%% of your monthly translation words (%2\$s / %3\$s words). Upgrade your plan anytime to keep translating without interruption.", 'ai-translator-for-elementor-and-polylang'),
                                        esc_html($pct),
                                        esc_html(number_format_i18n($used)),
                                        esc_html(number_format_i18n($limit))
                                    );
                                }
                                ?>
                            </p>
                            <div class="aitrfoel-notice-actions" style="display: flex; flex-wrap: wrap; align-items: center; gap: 8px;">
                                <a href="<?php echo esc_url($upgrade_url); ?>" class="button button-primary aitrfoel-btn-upgrade" style="background: #1447e6 !important; border-color: #1447e6 !important; border-radius: 6px !important; padding: 0 16px !important; height: 32px !important; line-height: 30px !important; font-weight: 600 !important; color: #fff !important; box-shadow: 0 4px 6px -1px rgba(20, 71, 230, 0.15) !important; transition: all 0.2s ease !important; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                                    <span><?php esc_html_e('Upgrade Plan', 'ai-translator-for-elementor-and-polylang'); ?></span>
                                </a>
                                <?php if ($show_bonus_strip): ?>
                                <span class="aitrfoel-share-buttons" style="display: inline-flex; align-items: center; gap: 6px;">
                                    <button type="button" class="aitrfoel-global-share-btn" data-platform="x" style="display: inline-flex; align-items: center; gap: 5px; background: #0f172a; color: #fff; border: none; border-radius: 6px; padding: 0 12px; height: 32px; font-size: 12px; font-weight: 600; cursor: pointer;">
                                        <span><?php esc_html_e('Share on X (+2,500 Free Words)', 'ai-translator-for-elementor-and-polylang'); ?></span>
                                    </button>
                                    <button type="button" class="aitrfoel-global-share-btn" data-platform="linkedin" style="display: inline-flex; align-items: center; gap: 5px; background: #0a66c2; color: #fff; border: none; border-radius: 6px; padding: 0 12px; height: 32px; font-size: 12px; font-weight: 600; cursor: pointer;">
                                        <span><?php esc_html_e('LinkedIn (+2,500)', 'ai-translator-for-elementor-and-polylang'); ?></span>
                                    </button>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
            <style>
                @media (max-width: 782px) {
                    .aitrfoel-notice-divider {
                        display: none !important;
                    }
                    .aitrfoel-notice-flex {
                        flex-direction: column !important;
                    }
                }
            </style>
            <script>
                jQuery(document).ready(function($) {
                    $('#aitrfoel-usage-notice-80').on('click', '.notice-dismiss', function(e) {
                        e.preventDefault();
                        $.post(ajaxurl, {
                            action: 'aitrfoel_dismiss_80_notice',
                            nonce: '<?php echo esc_js(wp_create_nonce("aitrfoel_dismiss_80_notice")); ?>'
                        });
                    });
                    $('#aitrfoel-usage-notice-80').on('click', '.aitrfoel-global-share-btn', function(e) {
                        e.preventDefault();
                        var $btn = $(this);
                        var platform = $btn.data('platform') || 'x';
                        var productUrl = 'https://meeptech.com/products/ai-translator-for-elementor-polylang';
                        var shareText = "I'm using Meep AI Translator for WordPress to effortlessly translate Elementor pages with AI! Check it out:";
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(shareText + ' ' + productUrl).catch(function(){});
                        }
                        var shareUrl = platform === 'linkedin'
                            ? 'https://www.linkedin.com/sharing/share-offsite/?url=' + encodeURIComponent(productUrl)
                            : 'https://twitter.com/intent/tweet?text=' + encodeURIComponent(shareText) + '&url=' + encodeURIComponent(productUrl) + '&hashtags=WordPress,AI,Elementor,Polylang';
                        window.open(shareUrl, 'meep_share', 'width=600,height=450,toolbar=0,status=0');
                        var $container = $btn.closest('.aitrfoel-share-buttons');
                        $container.html('<span style="color:#0284c7;font-weight:600;font-size:12px;">Claiming +2,500 bonus words...</span>');
                        $.post(ajaxurl, {
                            action: 'aitrfoel_claim_share_bonus',
                            nonce: '<?php echo esc_js(wp_create_nonce("aitrfoel_ajax_nonce")); ?>',
                            platform: platform
                        }, function(res) {
                            if (res && res.success) {
                                $container.html('<span style="color:#059669;font-weight:700;font-size:12px;">✓ +2,500 Bonus Words Unlocked! Refreshing...</span>');
                                setTimeout(function() { window.location.reload(); }, 1200);
                            } else {
                                $container.html('<span style="color:#dc2626;font-size:12px;">' + (res && res.data && res.data.message ? res.data.message : 'Could not claim bonus words.') + '</span>');
                            }
                        });
                    });
                });
            </script>
            <?php
        }
    }

    /**
     * AJAX handler to dismiss 80% usage notice
     */
    public function dismiss_80_notice() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aitrfoel_dismiss_80_notice')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        update_user_meta(get_current_user_id(), 'aitrfoel_dismiss_80_nudge', gmdate('Y-m'));
        wp_send_json_success();
    }

    /**
     * AJAX handler to dismiss 100% usage notice for 24h
     */
    public function dismiss_100_notice() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aitrfoel_dismiss_100_notice')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        set_transient('aitrfoel_limit_100_dismissed', true, DAY_IN_SECONDS);
        wp_send_json_success();
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
     * Auto-register free user on admin_init (once per calendar month)
     * Ensures every active Free installation is registered in the remote database
     */
    public function maybe_auto_register_free_user() {
        $current_month = gmdate('Y-m');
        if (get_option('aitrfoel_remote_user_initialized') && get_option('aitrfoel_remote_user_initialized_month') === $current_month) {
            return;
        }
        $license_key = $this->get_license_key();
        if (!empty($license_key)) {
            return;
        }
        // Back off after a failed attempt so an unreachable API doesn't block every admin page load (30s timeout).
        if (get_transient('aitrfoel_auto_register_backoff')) {
            return;
        }
        if (class_exists('AITRFOEL_Logger')) {
            AITRFOEL_Logger::log('Auto-registering free site in remote database for month ' . $current_month, 'info');
        }
        if (!$this->initialize_user_in_remote_db('Free', null)) {
            set_transient('aitrfoel_auto_register_backoff', 1, HOUR_IN_SECONDS);
        }
    }
    
    /**
     * Handle license activation (Paid plan)
     * Called when user activates a paid license key
     */
    public function on_license_activated($license = null) {
        $this->clear_status_cache();
        delete_option('aitrfoel_cooldown_end_date');

        // Resolve license object from Freemius if not passed
        if ((!$license || !is_object($license)) && function_exists('eatp_fs') && is_object(eatp_fs()) && method_exists(eatp_fs(), '_get_license')) {
            try {
                $license = eatp_fs()->_get_license();
            } catch (Exception $e) {}
        }

        $license_key = (is_object($license) && !empty($license->secret_key)) ? $license->secret_key : $this->get_license_key();
        $plan = $this->get_freemius_plan_name() ?: 'Starter';

        if (is_object($license) && !empty($license->plan_id) && function_exists('eatp_fs') && method_exists(eatp_fs(), '_get_plan_by_id')) {
            try {
                $plan_obj = eatp_fs()->_get_plan_by_id($license->plan_id);
                if ($plan_obj && !empty($plan_obj->name)) {
                    $plan = $plan_obj->name;
                }
            } catch (Exception $e) {}
        }

        AITRFOEL_Logger::log('License activated - initializing paid user in remote DB: ' . $plan, 'info', [
            'license_key' => $license_key ? substr($license_key, 0, 10) . '...' : 'null',
            'plan' => $plan,
        ]);

        $this->initialize_user_in_remote_db($plan, $license_key);
        $this->update_plan_in_remote_db($plan, $license_key);
        $this->clear_status_cache();
    }
    
    /**
     * Handle plan change (Upgrade/Downgrade)
     * Called when user upgrades or downgrades their plan
     */
    public function on_plan_changed($old_plan, $new_plan) {
        $this->clear_status_cache();
        delete_option('aitrfoel_cooldown_end_date');

        $old_plan_name = isset($old_plan->name) ? $old_plan->name : 'Unknown';
        $new_plan_name = isset($new_plan->name) ? $new_plan->name : ($this->get_freemius_plan_name() ?: 'Unknown');
        
        AITRFOEL_Logger::log(sprintf(
            'Plan changed from %s to %s - updating remote database',
            $old_plan_name,
            $new_plan_name
        ), 'info');
        
        $license_key = $this->get_license_key();
        $this->update_plan_in_remote_db($new_plan_name, $license_key);
        $this->clear_status_cache();
    }

    /**
     * Handle Freemius plan sync (Sync License button or periodic sync)
     */
    public function on_account_plan_synced($plan_name = null) {
        $this->clear_status_cache();
        delete_option('aitrfoel_cooldown_end_date');

        $plan = !empty($plan_name) ? $plan_name : $this->get_freemius_plan_name();
        $license_key = $this->get_license_key();

        if (!empty($plan) && strtolower($plan) !== 'free') {
            AITRFOEL_Logger::log('Freemius account plan synced: ' . $plan . ' - updating remote DB', 'info');
            $this->update_plan_in_remote_db($plan, $license_key);
            $this->clear_status_cache();
        }
    }

    /**
     * Handle Freemius license change
     */
    public function on_license_changed($plan_change = null, $plan = null) {
        $this->clear_status_cache();
        delete_option('aitrfoel_cooldown_end_date');

        $plan_name = (is_object($plan) && !empty($plan->name)) ? $plan->name : $this->get_freemius_plan_name();
        $license_key = $this->get_license_key();

        if (!empty($plan_name) && strtolower($plan_name) !== 'free') {
            AITRFOEL_Logger::log('Freemius license changed to ' . $plan_name . ' - updating remote DB', 'info');
            $this->update_plan_in_remote_db($plan_name, $license_key);
            $this->clear_status_cache();
        }
    }
    
    /**
     * Initialize user in remote database
     * Creates a new user record with initial word count
     */
    public function initialize_user_in_remote_db($plan, $license_key) {
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
            
            // Cache locally (preserve first activation timestamp across monthly re-initializations)
            if (!get_option('aitrfoel_license_activated_at')) {
                update_option('aitrfoel_license_activated_at', current_time('mysql'));
            }
            update_option('aitrfoel_remote_user_initialized', true);
            update_option('aitrfoel_remote_user_initialized_month', gmdate('Y-m'));
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
            $this->clear_status_cache();
            
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
        $this->clear_status_cache();
    }
    
    /**
     * Sync the current Freemius plan to the remote server
     * This can be called manually or automatically to fix plan mismatches
     * 
     * @return array Result with success status and message
     */
    public function sync_plan_to_server() {
        if (!function_exists('eatp_fs') || !is_object(eatp_fs()) || !method_exists(eatp_fs(), 'is_registered') || !eatp_fs()->is_registered()) {
            AITRFOEL_Logger::log('Cannot sync plan - Freemius not registered', 'warning');
            return [
                'success' => false,
                'message' => 'Freemius not registered'
            ];
        }
        
        $license_key = $this->get_license_key();
        if (empty($license_key)) {
            AITRFOEL_Logger::log('Cannot sync plan - no license key found', 'warning');
            return [
                'success' => false,
                'message' => 'No license key found'
            ];
        }
        
        // Get the current plan from Freemius using multiple methods
        $plan = 'Free';
        
        // Method 1: Try to get plan from license's plan_id
        try {
            $license = method_exists(eatp_fs(), '_get_license') ? eatp_fs()->_get_license() : null;
            if ($license && isset($license->plan_id) && !empty($license->plan_id)) {
                $plan_obj = method_exists(eatp_fs(), '_get_plan_by_id') ? eatp_fs()->_get_plan_by_id($license->plan_id) : null;
                if ($plan_obj && isset($plan_obj->name)) {
                    $plan = $plan_obj->name;
                    AITRFOEL_Logger::log('sync_plan_to_server: Got plan from license plan_id: ' . $plan, 'info');
                }
            }
        } catch (Exception $e) {
            AITRFOEL_Logger::log('sync_plan_to_server: Could not get plan from license: ' . $e->getMessage(), 'warning');
        }
        
        // Method 2: Fallback to get_plan_name()
        if ($plan === 'Free') {
            try {
                $plan_name = method_exists(eatp_fs(), 'get_plan_name') ? eatp_fs()->get_plan_name() : null;
                if ($plan_name && $plan_name !== 'PLAN_NAME' && $plan_name !== 'free') {
                    $plan = $plan_name;
                    AITRFOEL_Logger::log('sync_plan_to_server: Got plan from get_plan_name(): ' . $plan, 'info');
                }
            } catch (Exception $e) {
                AITRFOEL_Logger::log('sync_plan_to_server: Could not get plan name: ' . $e->getMessage(), 'warning');
            }
        }
        
        // Method 3: Fallback to get_plan()
        if ($plan === 'Free') {
            try {
                $plan_obj = method_exists(eatp_fs(), 'get_plan') ? eatp_fs()->get_plan() : null;
                if ($plan_obj && isset($plan_obj->name)) {
                    $plan = $plan_obj->name;
                    AITRFOEL_Logger::log('sync_plan_to_server: Got plan from get_plan(): ' . $plan, 'info');
                }
            } catch (Exception $e) {
                AITRFOEL_Logger::log('sync_plan_to_server: Could not get plan: ' . $e->getMessage(), 'warning');
            }
        }
        
        AITRFOEL_Logger::log('sync_plan_to_server: Syncing plan "' . $plan . '" to server', 'info');
        
        // Update the plan on the server
        $result = $this->update_plan_in_remote_db($plan, $license_key);
        
        if ($result) {
            return [
                'success' => true,
                'message' => 'Plan synced successfully: ' . $plan,
                'plan' => $plan
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Failed to sync plan to server'
            ];
        }
    }
    
    /**
     * Check if plan needs to be synced and sync if necessary
     * Called on admin page loads to auto-fix plan mismatches
     */
    public function maybe_sync_plan() {
        // Only run in admin context, not during AJAX
        if (!is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            return;
        }
        
        // Only run if Freemius is available and registered with a paid license
        if (!function_exists('eatp_fs') || !eatp_fs()->is_registered()) {
            return;
        }
        
        $license_key = $this->get_license_key();
        if (empty($license_key)) {
            return;
        }
        
        // If a sync was explicitly requested in URL (like fs_action=sync_license or activated=true), bypass 1-hour throttle
        $force_sync = !empty($_GET['activated']) || (!empty($_GET['fs_action']) && strpos((string)$_GET['fs_action'], 'sync') !== false);
        if (!$force_sync) {
            $last_sync_check = get_transient('aitrfoel_last_plan_sync_check');
            if ($last_sync_check) {
                return;
            }
            set_transient('aitrfoel_last_plan_sync_check', time(), HOUR_IN_SECONDS);
        }
        
        // Get current plan from Freemius
        $freemius_plan = 'Free';
        try {
            $license = eatp_fs()->_get_license();
            if ($license && isset($license->plan_id) && !empty($license->plan_id)) {
                $plan_obj = eatp_fs()->_get_plan_by_id($license->plan_id);
                if ($plan_obj && isset($plan_obj->name)) {
                    $freemius_plan = $plan_obj->name;
                }
            }
            
            if ($freemius_plan === 'Free') {
                $plan_name = eatp_fs()->get_plan_name();
                if ($plan_name && $plan_name !== 'PLAN_NAME' && $plan_name !== 'free') {
                    $freemius_plan = $plan_name;
                }
            }
        } catch (Exception $e) {
            return; // Don't try to sync if we can't get the plan
        }
        
        // Get current plan from server
        $server_usage = $this->get_usage_from_server($license_key);
        $server_plan = isset($server_usage['plan']) ? $server_usage['plan'] : 'Unknown';
        
        // If plans don't match and Freemius has a valid paid plan, sync it
        if ($freemius_plan !== 'Free' && strcasecmp($freemius_plan, $server_plan) !== 0) {
            AITRFOEL_Logger::log(sprintf(
                'Plan mismatch detected! Freemius: %s, Server: %s - Auto-syncing...',
                $freemius_plan,
                $server_plan
            ), 'warning');
            
            $this->update_plan_in_remote_db($freemius_plan, $license_key);
            $this->clear_status_cache();
        }
    }
    
    /**
     * AJAX handler for manual plan sync
     */
    public function ajax_sync_plan() {
        // Security check
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        check_ajax_referer('aitrfoel_nonce', 'nonce');
        
        // Clear the sync check transient to force a fresh sync
        delete_transient('aitrfoel_last_plan_sync_check');
        
        $result = $this->sync_plan_to_server();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
}
