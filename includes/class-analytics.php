<?php
/**
 * PostHog Analytics Integration
 * 
 * Handles anonymous usage tracking with user consent.
 * Only tracks when user has explicitly opted in.
 *
 * @package AI_Translator_For_Elementor_Polylang
 */

if (!defined('ABSPATH')) {
    exit;
}

class AITRFOEL_Analytics {
    
    /**
     * PostHog API key (public, safe to expose)
     */
    private $api_key = 'phc_gobQ1cI27YXdxOVW8pf8Vwi8Ln8BoyeYS0qaqBBQt6H';
    
    /**
     * PostHog API host (EU cluster)
     */
    private $api_host = 'https://eu.i.posthog.com';
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Anonymous site identifier
     */
    private $site_id = null;
    
    /**
     * Get singleton instance
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
        $this->site_id = $this->get_anonymous_site_id();
    }
    
    /**
     * Check if analytics is enabled
     */
    public function is_enabled() {
        return (bool) get_option('aitrfoel_analytics_enabled', false);
    }
    
    /**
     * Get or generate anonymous site ID
     */
    private function get_anonymous_site_id() {
        $site_id = get_option('aitrfoel_anonymous_site_id');
        
        if (!$site_id) {
            // Generate a hash from site URL - anonymous but consistent
            $site_id = 'site_' . substr(hash('sha256', home_url() . wp_salt()), 0, 16);
            update_option('aitrfoel_anonymous_site_id', $site_id);
        }
        
        return $site_id;
    }
    
    /**
     * Capture an event
     *
     * @param string $event_name Event name
     * @param array  $properties Event properties
     */
    public function capture($event_name, $properties = []) {
        if (!$this->is_enabled()) {
            return false;
        }
        
        // Add standard properties
        $properties = array_merge($properties, $this->get_standard_properties());
        
        $payload = [
            'api_key' => $this->api_key,
            'event' => $event_name,
            'properties' => array_merge($properties, [
                'distinct_id' => $this->site_id,
                '$lib' => 'php',
                '$lib_version' => '1.0.0',
            ]),
            'timestamp' => gmdate('c'),
        ];
        
        return $this->send_to_posthog('/capture/', $payload);
    }
    
    /**
     * Send test event (for verification)
     */
    public function send_test_event() {
        // Temporarily bypass is_enabled check for testing
        $payload = [
            'api_key' => $this->api_key,
            'event' => 'test_event',
            'properties' => [
                'distinct_id' => $this->site_id,
                '$lib' => 'php',
                'test' => true,
                'timestamp_sent' => current_time('mysql'),
            ],
            'timestamp' => gmdate('c'),
        ];
        
        return $this->send_to_posthog('/capture/', $payload);
    }
    
    /**
     * Track LLM/AI generation event (for LLM Analytics)
     *
     * @param array $params LLM parameters
     */
    public function capture_llm_generation($params) {
        if (!$this->is_enabled()) {
            return false;
        }
        
        $properties = [
            '$ai_model' => $params['model'] ?? 'unknown',
            '$ai_provider' => 'openai',
            '$ai_input_tokens' => $params['input_tokens'] ?? 0,
            '$ai_output_tokens' => $params['output_tokens'] ?? 0,
            '$ai_total_tokens' => ($params['input_tokens'] ?? 0) + ($params['output_tokens'] ?? 0),
            '$ai_latency' => $params['latency_ms'] ?? 0,
            '$ai_success' => $params['success'] ?? true,
        ];
        
        if (isset($params['error'])) {
            $properties['$ai_error'] = $params['error'];
        }
        
        return $this->capture('$ai_generation', $properties);
    }
    
    /**
     * Track error event
     *
     * @param string $error_type Error type/category
     * @param string $error_message Error message
     * @param array  $context Additional context
     */
    public function capture_error($error_type, $error_message, $context = []) {
        if (!$this->is_enabled()) {
            return false;
        }
        
        $properties = array_merge([
            '$exception_type' => $error_type,
            '$exception_message' => $error_message,
        ], $context);
        
        return $this->capture('$exception', $properties);
    }
    
    /**
     * Get standard properties added to all events
     */
    private function get_standard_properties() {
        global $wp_version;
        
        return [
            'plugin_version' => defined('AITRFOEL_VERSION') ? AITRFOEL_VERSION : '1.0.0',
            'wp_version' => $wp_version,
            'php_version' => PHP_VERSION,
            'elementor_version' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : 'not_installed',
            'polylang_active' => function_exists('pll_languages_list'),
            'locale' => get_locale(),
            'is_multisite' => is_multisite(),
        ];
    }
    
    /**
     * Send data to PostHog
     *
     * @param string $endpoint API endpoint
     * @param array  $payload Data payload
     */
    private function send_to_posthog($endpoint, $payload) {
        $url = $this->api_host . $endpoint;
        
        $response = wp_remote_post($url, [
            'timeout' => 5,
            'blocking' => false, // Non-blocking for better performance
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Track plugin activation
     */
    public function track_activation() {
        $this->capture('plugin_activated', [
            'activation_type' => get_option('aitrfoel_has_activated_before') ? 'reactivation' : 'first_activation',
        ]);
        
        update_option('aitrfoel_has_activated_before', true);
    }
    
    /**
     * Track translation started
     *
     * @param int    $page_count Number of pages
     * @param string $source_lang Source language
     * @param array  $target_langs Target languages
     * @param string $mode Translation mode (single/bulk)
     */
    public function track_translation_started($page_count, $source_lang, $target_langs, $mode = 'single') {
        $this->capture('translation_started', [
            'page_count' => $page_count,
            'source_language' => $source_lang,
            'target_languages' => $target_langs,
            'target_language_count' => count($target_langs),
            'mode' => $mode,
        ]);
    }
    
    /**
     * Track translation completed
     *
     * @param array $params Translation results
     */
    public function track_translation_completed($params) {
        $this->capture('translation_completed', [
            'duration_seconds' => $params['duration'] ?? 0,
            'word_count' => $params['word_count'] ?? 0,
            'widget_count' => $params['widget_count'] ?? 0,
            'success' => $params['success'] ?? true,
            'mode' => $params['mode'] ?? 'single',
            'ai_model' => $params['model'] ?? 'unknown',
        ]);
    }
    
    /**
     * Track translation failed
     *
     * @param string $error_type Error type
     * @param string $error_message Error message
     */
    public function track_translation_failed($error_type, $error_message) {
        $this->capture('translation_failed', [
            'error_type' => $error_type,
            'error_message' => substr($error_message, 0, 500), // Limit message length
        ]);
        
        // Also track as exception for Error Tracking
        $this->capture_error($error_type, $error_message, [
            'feature' => 'translation',
        ]);
    }
    
    /**
     * Track feature usage
     *
     * @param string $feature_name Feature name
     * @param array  $properties Additional properties
     */
    public function track_feature_used($feature_name, $properties = []) {
        $this->capture('feature_used', array_merge([
            'feature' => $feature_name,
        ], $properties));
    }
    
    /**
     * Track settings changed
     *
     * @param string $setting_name Setting name
     * @param mixed  $new_value New value (will be sanitized)
     */
    public function track_settings_changed($setting_name, $new_value) {
        // Don't track sensitive values, just that settings changed
        $safe_settings = ['ai_model', 'cache_enabled', 'debug_mode', 'default_source_lang'];
        
        $properties = [
            'setting' => $setting_name,
        ];
        
        // Only include value for safe settings
        if (in_array($setting_name, $safe_settings)) {
            $properties['value'] = $new_value;
        }
        
        $this->capture('settings_changed', $properties);
    }
}

/**
 * Get analytics instance
 */
function aitrfoel_analytics() {
    return AITRFOEL_Analytics::get_instance();
}
