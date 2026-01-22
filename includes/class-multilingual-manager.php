<?php
/**
 * Multilingual Manager Class
 * Factory/Manager that detects which multilingual plugin is active
 * and provides the appropriate integration instance
 */

if (!defined('ABSPATH')) exit;

class AITRFOEL_Multilingual_Manager {
    
    private static $instance = null;
    
    /**
     * The active multilingual integration instance
     * @var AITRFOEL_Multilingual_Interface|null
     */
    private $integration = null;
    
    /**
     * Detected plugin type
     * @var string|null 'polylang', 'wpml', or null
     */
    private $detected_plugin = null;
    
    /**
     * Get singleton instance
     * 
     * @return AITRFOEL_Multilingual_Manager
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor - detects and initializes the appropriate integration
     */
    private function __construct() {
        $this->detect_multilingual_plugin();
    }
    
    /**
     * Detect which multilingual plugin is active
     * Priority: Polylang first (to maintain backward compatibility), then WPML
     */
    private function detect_multilingual_plugin() {
        // Check for Polylang first (backward compatibility)
        if ($this->is_polylang_available()) {
            $this->detected_plugin = 'polylang';
            $this->integration = AITRFOEL_Polylang_Integration::get_instance();
            AITRFOEL_Logger::log('Multilingual Manager: Polylang detected and loaded', 'info');
            return;
        }
        
        // Check for WPML
        if ($this->is_wpml_available()) {
            $this->detected_plugin = 'wpml';
            $this->integration = AITRFOEL_WPML_Integration::get_instance();
            AITRFOEL_Logger::log('Multilingual Manager: WPML detected and loaded', 'info');
            return;
        }
        
        // No multilingual plugin found
        $this->detected_plugin = null;
        $this->integration = null;
        AITRFOEL_Logger::log('Multilingual Manager: No multilingual plugin detected', 'warning');
    }
    
    /**
     * Check if Polylang is available
     * 
     * @return bool
     */
    private function is_polylang_available() {
        return function_exists('pll_get_post_language') && 
               function_exists('pll_set_post_language') &&
               function_exists('pll_get_post_translations') &&
               function_exists('pll_save_post_translations');
    }
    
    /**
     * Check if WPML is available
     * 
     * @return bool
     */
    private function is_wpml_available() {
        // WPML main plugin check
        return defined('ICL_SITEPRESS_VERSION') || 
               class_exists('SitePress') ||
               function_exists('icl_object_id');
    }
    
    /**
     * Get the active multilingual integration
     * 
     * @return AITRFOEL_Multilingual_Interface|null
     */
    public function get_integration() {
        return $this->integration;
    }
    
    /**
     * Check if any multilingual plugin is active
     * 
     * @return bool
     */
    public function has_multilingual_plugin() {
        return $this->integration !== null && $this->integration->is_active();
    }
    
    /**
     * Get the name of the detected multilingual plugin
     * 
     * @return string|null 'polylang', 'wpml', or null
     */
    public function get_detected_plugin() {
        return $this->detected_plugin;
    }
    
    /**
     * Get the display name of the detected plugin
     * 
     * @return string
     */
    public function get_plugin_display_name() {
        if ($this->integration) {
            return $this->integration->get_plugin_name();
        }
        return __('None', 'ai-translator-for-elementor-and-polylang');
    }
    
    /**
     * Check if the detected plugin is Polylang
     * 
     * @return bool
     */
    public function is_polylang() {
        return $this->detected_plugin === 'polylang';
    }
    
    /**
     * Check if the detected plugin is WPML
     * 
     * @return bool
     */
    public function is_wpml() {
        return $this->detected_plugin === 'wpml';
    }
    
    /**
     * Force re-detection of multilingual plugin
     * Useful after plugin activation/deactivation
     */
    public function refresh() {
        $this->integration = null;
        $this->detected_plugin = null;
        $this->detect_multilingual_plugin();
    }
    
    // =========================================================================
    // CONVENIENCE PROXY METHODS
    // These methods proxy to the active integration for easier access
    // =========================================================================
    
    /**
     * Check if multilingual plugin is active
     * 
     * @return bool
     */
    public function is_active() {
        return $this->integration !== null && $this->integration->is_active();
    }
    
    /**
     * Get available languages
     * 
     * @return array
     */
    public function get_available_languages() {
        if (!$this->integration) {
            return [];
        }
        return $this->integration->get_available_languages();
    }
    
    /**
     * Get default language
     * 
     * @return string|null
     */
    public function get_default_language() {
        if (!$this->integration) {
            return null;
        }
        return $this->integration->get_default_language();
    }
    
    /**
     * Get post language
     * 
     * @param int $post_id
     * @return string|null
     */
    public function get_post_language($post_id) {
        if (!$this->integration) {
            return null;
        }
        return $this->integration->get_post_language($post_id);
    }
    
    /**
     * Set post language
     * 
     * @param int $post_id
     * @param string $lang_code
     * @return bool
     */
    public function set_post_language($post_id, $lang_code) {
        if (!$this->integration) {
            return false;
        }
        return $this->integration->set_post_language($post_id, $lang_code);
    }
    
    /**
     * Get post translations
     * 
     * @param int $post_id
     * @return array
     */
    public function get_post_translations($post_id) {
        if (!$this->integration) {
            return [];
        }
        return $this->integration->get_post_translations($post_id);
    }
    
    /**
     * Setup translation relationship
     * 
     * @param int $original_post_id
     * @param int $translated_post_id
     * @param string $target_language
     * @return bool|WP_Error
     */
    public function setup_translation_relationship($original_post_id, $translated_post_id, $target_language) {
        if (!$this->integration) {
            return new WP_Error('no_multilingual_plugin', __('No multilingual plugin is active.', 'ai-translator-for-elementor-and-polylang'));
        }
        return $this->integration->setup_translation_relationship($original_post_id, $translated_post_id, $target_language);
    }
    
    /**
     * Get translation status
     * 
     * @param int $post_id
     * @return array
     */
    public function get_translation_status($post_id) {
        if (!$this->integration) {
            return [];
        }
        return $this->integration->get_translation_status($post_id);
    }
    
    /**
     * Check if language exists
     * 
     * @param string $lang_code
     * @return bool
     */
    public function language_exists($lang_code) {
        if (!$this->integration) {
            return false;
        }
        return $this->integration->language_exists($lang_code);
    }
    
    /**
     * Get language code from name
     * 
     * @param string $our_language
     * @return string|null
     */
    public function get_language_code($our_language) {
        if (!$this->integration) {
            return null;
        }
        return $this->integration->get_language_code($our_language);
    }
    
    /**
     * Get pages translation status
     * 
     * @return array
     */
    public function get_pages_translation_status() {
        if (!$this->integration) {
            return [];
        }
        return $this->integration->get_pages_translation_status();
    }
    
    /**
     * Get language switcher data
     * 
     * @return array
     */
    public function get_language_switcher_data() {
        if (!$this->integration) {
            return [];
        }
        return $this->integration->get_language_switcher_data();
    }
    
    /**
     * Get the correct permalink for a post in its assigned language
     * This ensures the multilingual plugin returns the URL with the correct language
     * 
     * @param int $post_id Post ID
     * @return string The permalink with correct language
     */
    public function get_post_permalink($post_id) {
        if (!$this->integration) {
            return get_permalink($post_id);
        }
        return $this->integration->get_post_permalink($post_id);
    }
    
    /**
     * Get the original post ID for a translation
     * Returns the post ID of the source/original post in the default language
     * 
     * @param int $post_id Post ID to check
     * @return int|null Original post ID, or null if not a translation (or is the original)
     */
    public function get_original_post_id($post_id) {
        if (!$this->integration) {
            return null;
        }
        
        // Get all translations for this post
        $translations = $this->integration->get_post_translations($post_id);
        if (empty($translations)) {
            return null;
        }
        
        // Get the default language
        $default_lang = $this->get_default_language();
        
        // If the post's language is the default language, it's the original
        $post_lang = $this->get_post_language($post_id);
        if ($post_lang === $default_lang) {
            return $post_id; // This IS the original
        }
        
        // Return the original (default language) post ID
        if (isset($translations[$default_lang])) {
            return intval($translations[$default_lang]);
        }
        
        // Fallback: find any post in the group that isn't this one
        foreach ($translations as $lang => $tid) {
            if (intval($tid) !== intval($post_id)) {
                return intval($tid);
            }
        }
        
        return null;
    }
}

/**
 * Helper function to get the multilingual manager instance
 * 
 * @return AITRFOEL_Multilingual_Manager
 */
function aitrfoel_multilingual() {
    return AITRFOEL_Multilingual_Manager::get_instance();
}
