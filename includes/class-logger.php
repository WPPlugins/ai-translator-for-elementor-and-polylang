<?php
if (!defined('ABSPATH')) exit;
class AITRFOEL_Logger {
    private static $instance = null;
    private static $debug_mode_cache = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Constructor kept for singleton pattern
    }
    
    /**
     * Check if debug mode is enabled in plugin settings
     * Cached to avoid repeated database queries
     */
    private static function is_debug_mode_enabled() {
        if (self::$debug_mode_cache === null) {
            self::$debug_mode_cache = (bool) get_option('aitrfoel_debug_mode', false);
        }
        return self::$debug_mode_cache;
    }
    
    /**
     * Clear the debug mode cache (call when settings are updated)
     */
    public static function clear_cache() {
        self::$debug_mode_cache = null;
    }

    public static function log($message, $status, $page_id = null, $translated_page_id = null) {
        // Only log if WP_DEBUG is enabled
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        
        // Only log if debug mode is enabled in plugin settings
        if (!self::is_debug_mode_enabled()) {
            return;
        }
        
        error_log("AITRFOEL_Logger [{$status}]: {$message}");
    }
    
    /**
     * Log debug message (only when debug mode is enabled)
     */
    public static function debug($message, $page_id = null, $translated_page_id = null) {
        self::log($message, 'debug', $page_id, $translated_page_id);
    }
    
    /**
     * Log info message (always logged when WP_DEBUG is on)
     */
    public static function info($message, $page_id = null, $translated_page_id = null) {
        self::log($message, 'info', $page_id, $translated_page_id);
    }
    
    /**
     * Log warning message (always logged when WP_DEBUG is on)
     */
    public static function warning($message, $page_id = null, $translated_page_id = null) {
        self::log($message, 'warning', $page_id, $translated_page_id);
    }
    
    /**
     * Log error message (always logged when WP_DEBUG is on)
     */
    public static function error($message, $page_id = null, $translated_page_id = null) {
        self::log($message, 'error', $page_id, $translated_page_id);
    }
    
    /**
     * Log success message (always logged when WP_DEBUG is on)
     */
    public static function success($message, $page_id = null, $translated_page_id = null) {
        self::log($message, 'success', $page_id, $translated_page_id);
    }
}
