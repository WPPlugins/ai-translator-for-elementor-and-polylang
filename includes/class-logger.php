<?php
if (!defined('ABSPATH')) exit;
class AITRFOEL_Logger {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Constructor kept for singleton pattern
    }

    public static function log($message, $status, $page_id = null, $translated_page_id = null) {
        // Log messages to WordPress debug.log if WP_DEBUG is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("AITRFOEL_Logger [{$status}]: {$message}");
        }
    }


}
