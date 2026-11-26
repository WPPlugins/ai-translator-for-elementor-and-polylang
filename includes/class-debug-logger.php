<?php
/**
 * Debug Logger for AI Translator
 * Sends server-side debug information to JavaScript debug monitor
 */

if (!defined('ABSPATH')) {
    exit;
}

class AITRFOEL_Debug_Logger {
    
    private static $instance = null;
    private static $logs = [];
    private static $enabled = true;
    
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
        // Enable debug mode if WP_DEBUG is on
        self::$enabled = defined('WP_DEBUG') && WP_DEBUG;
        
        // Add AJAX hook to get debug logs
        add_action('wp_ajax_aitrfoel_get_debug_logs', [$this, 'ajax_get_debug_logs']);
    }
    
    /**
     * Log a debug step
     */
    public static function log_step($title, $message, $type = 'info', $data = null) {
        if (!self::$enabled) {
            return;
        }
        
        $step = [
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'data' => $data,
            'timestamp' => current_time('timestamp'),
            'memory' => memory_get_usage(true),
            'time' => microtime(true)
        ];
        
        self::$logs[] = $step;
        
        // Also log to WordPress debug.log if available
        if (class_exists('AITRFOEL_Logger')) {
            AITRFOEL_Logger::log("[DEBUG] $title: $message", $type);
        }
        
        // Send to JavaScript if this is an AJAX request
        if (defined('DOING_AJAX') && DOING_AJAX) {
            // Store in transient for JavaScript to retrieve
            $transient_key = 'aitrfoel_debug_logs_' . get_current_user_id();
            $current_logs = get_transient($transient_key) ?: [];
            $current_logs[] = $step;
            set_transient($transient_key, $current_logs, 300); // 5 minutes
        }
    }
    
    /**
     * Get all logs
     */
    public static function get_logs() {
        return self::$logs;
    }
    
    /**
     * Clear all logs
     */
    public static function clear_logs() {
        self::$logs = [];
        
        // Clear transient
        $transient_key = 'aitrfoel_debug_logs_' . get_current_user_id();
        delete_transient($transient_key);
    }
    
    /**
     * AJAX handler to get debug logs
     */
    public function ajax_get_debug_logs() {
        check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
        
        $transient_key = 'aitrfoel_debug_logs_' . get_current_user_id();
        $logs = get_transient($transient_key) ?: [];
        
        // Clear after retrieval
        delete_transient($transient_key);
        
        wp_send_json_success([
            'logs' => $logs,
            'count' => count($logs)
        ]);
    }
    
    /**
     * Format data for logging
     */
    public static function format_data($data) {
        if (is_array($data) || is_object($data)) {
            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
        return (string) $data;
    }
    
    /**
     * Log translation start
     */
    public static function log_translation_start($page_id, $source_lang, $target_lang, $strings_count) {
        self::log_step(
            'Translation Started',
            "Translating page #$page_id from $source_lang to $target_lang",
            'info',
            [
                'page_id' => $page_id,
                'source_lang' => $source_lang,
                'target_lang' => $target_lang,
                'strings_count' => $strings_count
            ]
        );
    }
    
    /**
     * Log cache check
     */
    public static function log_cache_check($cached_count, $uncached_count) {
        self::log_step(
            'Cache Check',
            "Found $cached_count cached, $uncached_count need translation",
            'info',
            [
                'cached' => $cached_count,
                'uncached' => $uncached_count
            ]
        );
    }
    
    /**
     * Log API request
     */
    public static function log_api_request($batch_number, $strings_count, $provider = 'openai') {
        self::log_step(
            "API Request #$batch_number",
            "Sending $strings_count strings to $provider",
            'loading',
            [
                'batch' => $batch_number,
                'count' => $strings_count,
                'provider' => $provider
            ]
        );
    }
    
    /**
     * Log API response
     */
    public static function log_api_response($batch_number, $success, $error_message = null) {
        if ($success) {
            self::log_step(
                "API Response #$batch_number",
                "Translation batch completed successfully",
                'success'
            );
        } else {
            self::log_step(
                "API Error #$batch_number",
                $error_message ?: 'Unknown API error',
                'error',
                ['error' => $error_message]
            );
        }
    }
    
    /**
     * Log page creation
     */
    public static function log_page_creation($new_page_id, $title) {
        self::log_step(
            'Page Created',
            "Created translated page #$new_page_id: $title",
            'success',
            ['page_id' => $new_page_id]
        );
    }
    
    /**
     * Log error
     */
    public static function log_error($title, $message, $data = null) {
        self::log_step($title, $message, 'error', $data);
    }
    
    /**
     * Log warning
     */
    public static function log_warning($title, $message, $data = null) {
        self::log_step($title, $message, 'warning', $data);
    }
}
