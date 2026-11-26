<?php
/**
 * DEPRECATED: Legacy compatibility alias
 * 
 * This file provides backward compatibility for the old AITRFOEL_License_Manager class.
 * All functionality has been moved to AITRFOEL_License_Manager_Server.
 * 
 * @deprecated Use AITRFOEL_License_Manager_Server instead
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load the new server-based license manager
require_once plugin_dir_path(__FILE__) . 'class-license-manager-server.php';

/**
 * Backward compatibility alias
 * Redirects all calls to AITRFOEL_License_Manager_Server
 */
class AITRFOEL_License_Manager extends AITRFOEL_License_Manager_Server {
    // This class is now just an alias for backward compatibility
}
