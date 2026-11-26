<?php
/**
 * API Client Handler
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AITRFOEL_API_Client class
 */
class AITRFOEL_API_Client {
    
    /**
     * Instance
     */
    private static $instance = null;
    
    /**
     * License Manager instance
     */
    private $license_manager;
    
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
        // Initialize license manager for hosted service
        $this->license_manager = AITRFOEL_License_Manager_Server::get_instance();
    }
    
    /**
     * Translate text using hosted OpenAI service
     */
    public function translate_text($text, $source_language, $target_language) {
        try {
            
            $result = $this->license_manager->translate_via_server($text, $source_language, $target_language);
            
            if ($result) {
                return $result;
            } else {
                return false;
            }
            
        } catch (Exception $e) {
            error_log("EAT API Client Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Test API connection
     */
    public function test_connection() {
        try {
            $test_text = "Hello world";
            $result = $this->translate_text($test_text, 'en', 'es');
            
            return !empty($result);
            
        } catch (Exception $e) {
            error_log("EAT API Client Test Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get API usage stats
     */
    public function get_usage_stats() {
        return [
            'total_requests' => get_option('aitrfoel_api_total_requests', 0),
            'total_tokens' => get_option('aitrfoel_api_total_tokens', 0),
            'last_request' => get_option('aitrfoel_api_last_request', null)
        ];
    }
    
    /**
     * Update usage stats
     */
    public function update_usage_stats($tokens_used) {
        $current_requests = get_option('aitrfoel_api_total_requests', 0);
        $current_tokens = get_option('aitrfoel_api_total_tokens', 0);
        
        update_option('aitrfoel_api_total_requests', $current_requests + 1);
        update_option('aitrfoel_api_total_tokens', $current_tokens + $tokens_used);
        update_option('aitrfoel_api_last_request', current_time('mysql'));
    }
}
