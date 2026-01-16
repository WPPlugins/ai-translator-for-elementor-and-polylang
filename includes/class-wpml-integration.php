<?php
/**
 * WPML Integration Class
 * Handles linking translations with WPML plugin
 */

if (!defined('ABSPATH')) exit;

class AITRFOEL_WPML_Integration extends AITRFOEL_Multilingual_Interface {
    
    private static $instance = null;
    
    /**
     * Get singleton instance
     * 
     * @return AITRFOEL_WPML_Integration
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
        // Initialize hooks if WPML is active
        if ($this->is_active()) {
            add_action('init', [$this, 'init_wpml_hooks']);
        }
    }
    
    /**
     * Initialize WPML hooks
     */
    public function init_wpml_hooks() {
        // Add any WPML-specific hooks here
    }
    
    /**
     * Get the name of the multilingual plugin
     * 
     * @return string
     */
    public function get_plugin_name() {
        return 'WPML';
    }
    
    /**
     * Check if WPML is active and functional
     * 
     * @return bool
     */
    public function is_active() {
        global $sitepress;
        
        // Check if WPML classes exist and sitepress is properly initialized
        if (!defined('ICL_SITEPRESS_VERSION') && !class_exists('SitePress')) {
            return false;
        }
        
        return isset($sitepress) && is_object($sitepress) && class_exists('SitePress') && $sitepress instanceof SitePress;
    }
    
    /**
     * Get the WPML SitePress instance
     * 
     * @return SitePress|null
     */
    private function get_sitepress() {
        global $sitepress;
        
        if (isset($sitepress) && is_object($sitepress) && class_exists('SitePress') && $sitepress instanceof SitePress) {
            return $sitepress;
        }
        
        return null;
    }
    
    /**
     * Get available WPML languages
     * 
     * @return array Array of language data
     */
    public function get_available_languages() {
        if (!$this->is_active()) {
            return [];
        }
        
        $languages = [];
        $sitepress = $this->get_sitepress();
        
        if ($sitepress) {
            $active_languages = $sitepress->get_active_languages();
            
            foreach ($active_languages as $code => $lang_data) {
                $languages[$code] = (object) [
                    'slug' => $code,
                    'code' => $code,
                    'name' => $lang_data['display_name'] ?? $lang_data['english_name'] ?? $code,
                    'native_name' => $lang_data['native_name'] ?? $lang_data['display_name'] ?? $code,
                    'flag' => $this->get_language_flag($code),
                    'is_default' => ($code === $this->get_default_language())
                ];
            }
        }
        
        return $languages;
    }
    
    /**
     * Get the default language code
     * 
     * @return string|null
     */
    public function get_default_language() {
        if (!$this->is_active()) {
            return null;
        }
        
        $sitepress = $this->get_sitepress();
        
        if ($sitepress) {
            return $sitepress->get_default_language();
        }
        
        // Fallback using WPML function
        if (function_exists('wpml_get_default_language')) {
            return wpml_get_default_language();
        }
        
        return null;
    }
    
    /**
     * Get the language of a specific post
     * 
     * @param int $post_id Post ID
     * @return string|null Language code or null
     */
    public function get_post_language($post_id) {
        if (!$this->is_active()) {
            return null;
        }
        
        // Use WPML API
        $language_details = apply_filters('wpml_post_language_details', null, $post_id);
        
        if (is_array($language_details) && isset($language_details['language_code'])) {
            return $language_details['language_code'];
        }
        
        // Fallback method
        if (function_exists('wpml_get_language_information')) {
            $lang_info = wpml_get_language_information(null, $post_id);
            if (isset($lang_info['language_code'])) {
                return $lang_info['language_code'];
            }
        }
        
        return null;
    }
    
    /**
     * Set the language for a post
     * 
     * @param int $post_id Post ID
     * @param string $lang_code Language code
     * @return bool Success
     */
    public function set_post_language($post_id, $lang_code) {
        if (!$this->is_active()) {
            return false;
        }
        
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }
        
        $post_type = $post->post_type;
        
        // Get element type for WPML (e.g., 'post_page', 'post_post')
        $element_type = 'post_' . $post_type;
        
        // Use WPML API to set language
        do_action('wpml_set_element_language_details', [
            'element_id' => $post_id,
            'element_type' => $element_type,
            'trid' => false, // Will create new trid
            'language_code' => $lang_code,
            'source_language_code' => null
        ]);
        
        AITRFOEL_Logger::log(
            sprintf('WPML: Set language "%s" for post %d', $lang_code, $post_id),
            'info',
            $post_id
        );
        
        return true;
    }
    
    /**
     * Get all translations of a post
     * 
     * @param int $post_id Post ID
     * @return array Associative array of language_code => post_id
     */
    public function get_post_translations($post_id) {
        if (!$this->is_active()) {
            return [];
        }
        
        $translations = [];
        
        // Get element type
        $post = get_post($post_id);
        if (!$post) {
            return [];
        }
        
        $element_type = 'post_' . $post->post_type;
        
        // Get trid (translation group ID)
        $trid = apply_filters('wpml_element_trid', null, $post_id, $element_type);
        
        if ($trid) {
            // Get all translations in this group
            $element_translations = apply_filters('wpml_get_element_translations', null, $trid, $element_type);
            
            if (is_array($element_translations)) {
                foreach ($element_translations as $lang_code => $translation) {
                    if (isset($translation->element_id)) {
                        $translations[$lang_code] = (int) $translation->element_id;
                    }
                }
            }
        }
        
        return $translations;
    }
    
    /**
     * Setup translation relationship between original and translated post
     * 
     * @param int $original_post_id Original post ID
     * @param int $translated_post_id Translated post ID  
     * @param string $target_language Target language code or full name
     * @return bool|WP_Error Success or error
     */
    public function setup_translation_relationship($original_post_id, $translated_post_id, $target_language) {
        if (!$this->is_active()) {
            AITRFOEL_Logger::log('WPML is not active', 'error', $original_post_id);
            return new WP_Error('wpml_inactive', 'WPML is not active.');
        }
        
        // Normalize language code
        $wpml_lang_code = $this->normalize_language_code($target_language);
        
        if (!$wpml_lang_code || !$this->language_exists($wpml_lang_code)) {
            AITRFOEL_Logger::log(
                sprintf('Language "%s" not found in WPML.', $target_language),
                'error',
                $original_post_id
            );
            return new WP_Error('language_not_found', sprintf('Language "%s" not found in WPML.', $target_language));
        }
        
        // Get original post info
        $original_post = get_post($original_post_id);
        $translated_post = get_post($translated_post_id);
        
        if (!$original_post || !$translated_post) {
            return new WP_Error('invalid_posts', 'Invalid post IDs provided.');
        }
        
        $post_type = $original_post->post_type;
        $element_type = 'post_' . $post_type;
        
        // Get or set language for original post
        $original_lang_code = $this->get_post_language($original_post_id);
        
        if (!$original_lang_code) {
            // Set default language for original
            $default_lang = $this->get_default_language();
            
            if (!$default_lang) {
                $available = $this->get_available_languages();
                if (!empty($available)) {
                    $first = reset($available);
                    $default_lang = $first->slug;
                }
            }
            
            if ($default_lang) {
                $this->set_post_language($original_post_id, $default_lang);
                $original_lang_code = $default_lang;
                AITRFOEL_Logger::log(
                    sprintf('WPML: Set default language "%s" for original post %d', $default_lang, $original_post_id),
                    'info',
                    $original_post_id
                );
            } else {
                return new WP_Error('no_default_language', 'Cannot determine default language.');
            }
        }
        
        // Get trid from original post
        $trid = apply_filters('wpml_element_trid', null, $original_post_id, $element_type);
        
        if (!$trid) {
            // Original post doesn't have a trid - create the relationship
            AITRFOEL_Logger::log(
                sprintf('WPML: Original post %d has no trid, will create new translation group', $original_post_id),
                'info',
                $original_post_id
            );
        }
        
        // Link translated post to original using WPML API
        do_action('wpml_set_element_language_details', [
            'element_id' => $translated_post_id,
            'element_type' => $element_type,
            'trid' => $trid,
            'language_code' => $wpml_lang_code,
            'source_language_code' => $original_lang_code
        ]);
        
        AITRFOEL_Logger::log(
            sprintf('WPML: Linked translation %d (lang: %s) to original %d (lang: %s, trid: %s)',
                $translated_post_id,
                $wpml_lang_code,
                $original_post_id,
                $original_lang_code,
                $trid ?: 'new'
            ),
            'info',
            $original_post_id,
            $translated_post_id
        );
        
        // Verify the relationship was created
        $verify_translations = $this->get_post_translations($translated_post_id);
        
        if (isset($verify_translations[$wpml_lang_code]) && $verify_translations[$wpml_lang_code] == $translated_post_id) {
            AITRFOEL_Logger::log(
                sprintf('✅ WPML relationship created successfully! Group: %s', json_encode($verify_translations)),
                'success',
                $original_post_id,
                $translated_post_id
            );
            return true;
        } else {
            AITRFOEL_Logger::log(
                sprintf('⚠️ WPML relationship may not be complete. Translations: %s', json_encode($verify_translations)),
                'warning',
                $original_post_id,
                $translated_post_id
            );
            return true; // Still return true as we did our best
        }
    }
    
    /**
     * Get translation status for a post across all languages
     * 
     * @param int $post_id Post ID
     * @return array Translation status array
     */
    public function get_translation_status($post_id) {
        if (!$this->is_active()) {
            return [];
        }
        
        $status = [];
        $translations = $this->get_post_translations($post_id);
        $available_languages = $this->get_available_languages();
        
        foreach ($available_languages as $lang) {
            $lang_code = $lang->slug;
            $has_translation = isset($translations[$lang_code]);
            $translation_id = $has_translation ? $translations[$lang_code] : null;
            
            $status[$lang_code] = [
                'language_name' => $lang->name,
                'language_code' => $lang_code,
                'translated' => $has_translation,
                'post_id' => $translation_id,
                'edit_url' => $translation_id ? get_edit_post_link($translation_id) : null,
                'view_url' => $translation_id ? get_permalink($translation_id) : null,
                'status' => $translation_id ? get_post_status($translation_id) : null
            ];
        }
        
        return $status;
    }
    
    /**
     * Check if a language code exists in WPML
     * 
     * @param string $lang_code Language code to check
     * @return bool
     */
    public function language_exists($lang_code) {
        if (!$this->is_active()) {
            return false;
        }
        
        $languages = $this->get_available_languages();
        
        foreach ($languages as $lang) {
            if ($lang->slug === $lang_code) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Convert language name to WPML language code
     * 
     * @param string $our_language Our language name (e.g., 'Russian')
     * @return string|null WPML language code (e.g., 'ru') or null
     */
    public function get_language_code($our_language) {
        // Use parent's mapping
        return $this->get_code_from_name($our_language);
    }
    
    /**
     * Remove translation relationship
     * 
     * @param int $post_id Post ID to remove from translations
     * @return bool Success
     */
    public function remove_translation_relationship($post_id) {
        if (!$this->is_active()) {
            return false;
        }
        
        try {
            $post = get_post($post_id);
            if (!$post) {
                return false;
            }
            
            $element_type = 'post_' . $post->post_type;
            
            // Remove the translation link by setting trid to false
            do_action('wpml_set_element_language_details', [
                'element_id' => $post_id,
                'element_type' => $element_type,
                'trid' => false,
                'language_code' => null,
                'source_language_code' => null
            ]);
            
            AITRFOEL_Logger::log(
                sprintf('WPML: Removed translation relationship for post %d', $post_id),
                'info',
                $post_id
            );
            
            return true;
            
        } catch (Exception $e) {
            AITRFOEL_Logger::log(
                sprintf('Failed to remove WPML translation relationship for post %d: %s', $post_id, $e->getMessage()),
                'error',
                $post_id
            );
            return false;
        }
    }
    
    /**
     * Get all pages with their translation status
     * 
     * @return array Array of pages with translation status
     */
    public function get_pages_translation_status() {
        if (!$this->is_active()) {
            return [];
        }
        
        // Get all pages with Elementor data
        $pages = get_posts([
            'post_type' => 'page',
            'posts_per_page' => -1,
            'post_status' => ['publish', 'draft'],
            'meta_query' => [
                [
                    'key' => '_elementor_data',
                    'compare' => 'EXISTS'
                ]
            ],
            'orderby' => 'title',
            'order' => 'ASC',
            'suppress_filters' => true // Get posts in all languages
        ]);
        
        $pages_status = [];
        
        foreach ($pages as $page) {
            $translation_status = $this->get_translation_status($page->ID);
            
            $pages_status[] = [
                'id' => $page->ID,
                'title' => $page->post_title,
                'status' => $page->post_status,
                'edit_url' => get_edit_post_link($page->ID),
                'view_url' => get_permalink($page->ID),
                'translations' => $translation_status,
                'has_elementor' => !empty(get_post_meta($page->ID, '_elementor_data', true))
            ];
        }
        
        return $pages_status;
    }
    
    /**
     * Get language switcher data for admin
     * 
     * @return array Language switcher data
     */
    public function get_language_switcher_data() {
        if (!$this->is_active()) {
            return [];
        }
        
        $languages = $this->get_available_languages();
        $default_lang = $this->get_default_language();
        $switcher_data = [];
        
        foreach ($languages as $lang) {
            $switcher_data[] = [
                'code' => $lang->slug,
                'name' => $lang->name,
                'native_name' => $lang->native_name ?? $lang->name,
                'flag' => $lang->flag ?? '',
                'is_default' => $lang->slug === $default_lang
            ];
        }
        
        return $switcher_data;
    }
    
    /**
     * Get language flag URL from WPML
     * 
     * @param string $lang_code Language code
     * @return string Flag URL or empty string
     */
    private function get_language_flag($lang_code) {
        $sitepress = $this->get_sitepress();
        
        if ($sitepress && method_exists($sitepress, 'get_flag_url')) {
            return $sitepress->get_flag_url($lang_code);
        }
        
        // Try using WPML filter
        $flag_url = apply_filters('wpml_language_flag_url', '', $lang_code);
        
        return $flag_url ?: '';
    }
    
    /**
     * Get translated post ID using WPML's icl_object_id function
     * 
     * @param int $post_id Original post ID
     * @param string $lang_code Target language code
     * @param string $post_type Post type (default: 'page')
     * @return int|null Translated post ID or null
     */
    public function get_translated_post_id($post_id, $lang_code, $post_type = 'page') {
        if (!$this->is_active()) {
            return null;
        }
        
        // Use WPML's object ID function
        if (function_exists('icl_object_id')) {
            $translated_id = icl_object_id($post_id, $post_type, false, $lang_code);
            return $translated_id ?: null;
        }
        
        // Fallback to filter
        $translated_id = apply_filters('wpml_object_id', $post_id, $post_type, false, $lang_code);
        
        return $translated_id ?: null;
    }
}
