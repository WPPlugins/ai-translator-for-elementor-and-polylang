<?php
/**
 * Basic Usage Statistics Tracker
 * 
 * Handles basic tracking of Pages Translated, Words, and Characters Sent
 */

if (!defined('ABSPATH')) {
    exit;
}

class AITRFOEL_Usage_Tracker {
    
    /**
     * Singleton instance
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
        $this->ensure_table_exists();
    }
    
    /**
     * Ensure the usage stats table exists with simplified structure
     */
    private function ensure_table_exists() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'aitrfoel_usage_stats';
        $charset_collate = $wpdb->get_charset_collate();
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        
        if (!$table_exists) {
            // Create simplified table structure
            $sql = "CREATE TABLE $table_name (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                user_id bigint(20) NOT NULL,
                word_count int DEFAULT 0,
                page_count int DEFAULT 1,
                character_count int DEFAULT 0,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                month_year varchar(7) NOT NULL,
                PRIMARY KEY (id),
                KEY month_year (month_year),
                KEY user_id (user_id)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
            
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log('Basic usage stats table created successfully', 'info');
            }
        }
    }
    
    /**
     * Record basic usage statistics
     */
    public function record_usage($data) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'aitrfoel_usage_stats';
        $current_month = gmdate('Y-m');
        
        $user_id = isset($data['user_id']) ? (int) $data['user_id'] : get_current_user_id();
        if ($user_id < 0) {
            $user_id = 0;
        }
        
        $word_count = isset($data['word_count']) ? (int) $data['word_count'] : 0;
        $page_count = isset($data['page_count']) ? (int) $data['page_count'] : 1;
        $character_count = isset($data['character_count']) ? (int) $data['character_count'] : 0;
        
        // Insert basic usage data
        $result = $wpdb->insert(
            $table_name,
            [
                'user_id' => $user_id,
                'word_count' => $word_count,
                'page_count' => $page_count,
                'character_count' => $character_count,
                'month_year' => $current_month,
                'created_at' => current_time('mysql')
            ],
            ['%d', '%d', '%d', '%d', '%s', '%s']
        );
        
        if ($result === false && class_exists('AITRFOEL_Logger')) {
            AITRFOEL_Logger::log('Failed to record basic usage stats: ' . $wpdb->last_error, 'error');
        }
        
        // SECURITY: Track words for periodic license verification
        if ($word_count > 0) {
            $words_since_verification = get_option('aitrfoel_words_since_verification', 0);
            update_option('aitrfoel_words_since_verification', $words_since_verification + $word_count);
        }
        
        return $result !== false;
    }
    
    /**
     * Get total words translated from database
     */
    public function get_total_words_translated() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aitrfoel_usage_stats';
        
        $result = $wpdb->get_var(
            "SELECT SUM(word_count) FROM $table_name"
        );
        
        return $result ? (int) $result : 0;
    }
    
    /**
     * Get total pages translated from database
     */
    public function get_total_pages_translated() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aitrfoel_usage_stats';
        
        $result = $wpdb->get_var(
            "SELECT SUM(page_count) FROM $table_name"
        );
        
        return $result ? (int) $result : 0;
    }
    
    /**
     * Get total characters sent from database
     */
    public function get_total_characters_sent() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aitrfoel_usage_stats';
        
        $result = $wpdb->get_var(
            "SELECT SUM(character_count) FROM $table_name"
        );
        
        return $result ? (int) $result : 0;
    }
    
    /**
     * Get basic monthly stats
     */
    public function get_monthly_stats($month = null) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aitrfoel_usage_stats';
        
        if (!$month) {
            $month = gmdate('Y-m');
        }
        
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                SUM(word_count) as total_words,
                SUM(page_count) as total_pages,
                SUM(character_count) as total_characters
            FROM $table_name 
            WHERE month_year = %s",
            $month
        ));
        
        return [
            'total_words' => $stats ? (int) $stats->total_words : 0,
            'total_pages' => $stats ? (int) $stats->total_pages : 0,
            'total_characters' => $stats ? (int) $stats->total_characters : 0,
            'month' => $month
        ];
    }
}
