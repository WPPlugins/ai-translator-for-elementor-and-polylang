<?php
/**
 * Multilingual Interface
 * Abstract class that defines the contract for multilingual plugin integrations
 * Both Polylang and WPML integrations must implement this interface
 */

if (!defined('ABSPATH')) exit;

abstract class AITRFOEL_Multilingual_Interface {
    
    /**
     * Get the name of the multilingual plugin
     * 
     * @return string Plugin name (e.g., 'Polylang', 'WPML')
     */
    abstract public function get_plugin_name();
    
    /**
     * Check if the multilingual plugin is active and functional
     * 
     * @return bool
     */
    abstract public function is_active();
    
    /**
     * Get available languages configured in the multilingual plugin
     * 
     * @return array Array of language objects/arrays with at least 'code', 'name', 'slug' keys
     */
    abstract public function get_available_languages();
    
    /**
     * Get the default language code
     * 
     * @return string|null Default language code or null if not set
     */
    abstract public function get_default_language();
    
    /**
     * Get the language of a specific post
     * 
     * @param int $post_id Post ID
     * @return string|null Language code or null if not set
     */
    abstract public function get_post_language($post_id);
    
    /**
     * Set the language for a post
     * 
     * @param int $post_id Post ID
     * @param string $lang_code Language code
     * @return bool Success
     */
    abstract public function set_post_language($post_id, $lang_code);
    
    /**
     * Get all translations of a post
     * 
     * @param int $post_id Post ID
     * @return array Associative array of language_code => post_id
     */
    abstract public function get_post_translations($post_id);
    
    /**
     * Setup translation relationship between original and translated post
     * 
     * @param int $original_post_id Original post ID
     * @param int $translated_post_id Translated post ID  
     * @param string $target_language Target language code (e.g., 'ru', 'es') or full name (e.g., 'Russian')
     * @return bool|WP_Error Success or error
     */
    abstract public function setup_translation_relationship($original_post_id, $translated_post_id, $target_language);
    
    /**
     * Get translation status for a post across all languages
     * 
     * @param int $post_id Post ID
     * @return array Translation status array with language info and translation states
     */
    abstract public function get_translation_status($post_id);
    
    /**
     * Check if a language code exists/is configured in the multilingual plugin
     * 
     * @param string $lang_code Language code to check
     * @return bool
     */
    abstract public function language_exists($lang_code);
    
    /**
     * Convert our language name format to the plugin's language code format
     * 
     * @param string $our_language Our language name (e.g., 'Russian', 'Spanish')
     * @return string|null Plugin's language code (e.g., 'ru', 'es') or null if not found
     */
    abstract public function get_language_code($our_language);
    
    /**
     * Remove translation relationship for a post
     * 
     * @param int $post_id Post ID to remove from translations
     * @return bool Success
     */
    abstract public function remove_translation_relationship($post_id);
    
    /**
     * Get all pages with their translation status
     * Used for displaying translation overview in admin
     * 
     * @return array Array of pages with translation status
     */
    abstract public function get_pages_translation_status();
    
    /**
     * Get language switcher data for admin UI
     * 
     * @return array Array of language data for switcher
     */
    abstract public function get_language_switcher_data();
    
    /**
     * Get translation of a post in a specific language
     * 
     * @param int $post_id Original post ID
     * @param string $lang_code Target language code
     * @return int|null Translated post ID or null if not found
     */
    public function get_post_translation($post_id, $lang_code) {
        $translations = $this->get_post_translations($post_id);
        return isset($translations[$lang_code]) ? $translations[$lang_code] : null;
    }
    
    /**
     * Check if a translation exists for a post in a specific language
     * 
     * @param int $post_id Post ID
     * @param string $lang_code Language code
     * @return bool
     */
    public function has_translation($post_id, $lang_code) {
        return $this->get_post_translation($post_id, $lang_code) !== null;
    }
    
    /**
     * Language code mapping from full names to ISO codes
     * This is a shared mapping used by both integrations
     */
    protected static $language_name_map = [
        'English' => 'en',
        'Russian' => 'ru', 
        'Spanish' => 'es',
        'French' => 'fr',
        'German' => 'de',
        'Italian' => 'it',
        'Portuguese' => 'pt',
        'Ukrainian' => 'uk',
        'Belarusian' => 'be',
        'Czech' => 'cs',
        'Polish' => 'pl',
        'Vietnamese' => 'vi',
        'Chinese' => 'zh',
        'Japanese' => 'ja',
        'Korean' => 'ko',
        'Arabic' => 'ar',
        'Hebrew' => 'he',
        'Hindi' => 'hi',
        'Turkish' => 'tr',
        'Dutch' => 'nl',
        'Swedish' => 'sv',
        'Norwegian' => 'no',
        'Danish' => 'da',
        'Finnish' => 'fi',
        'Greek' => 'el',
        'Hungarian' => 'hu',
        'Romanian' => 'ro',
        'Bulgarian' => 'bg',
        'Croatian' => 'hr',
        'Slovak' => 'sk',
        'Slovenian' => 'sl',
        'Estonian' => 'et',
        'Latvian' => 'lv',
        'Lithuanian' => 'lt',
        'Thai' => 'th',
        'Indonesian' => 'id',
        'Malay' => 'ms',
        'Filipino' => 'fil',
        'Catalan' => 'ca',
        'Basque' => 'eu',
        'Galician' => 'gl'
    ];
    
    /**
     * Get language code from full name using shared mapping
     * 
     * @param string $language_name Full language name
     * @return string|null ISO language code or null
     */
    protected function get_code_from_name($language_name) {
        return self::$language_name_map[$language_name] ?? null;
    }
    
    /**
     * Normalize language code (handle both full names and codes)
     * 
     * @param string $language Language name or code
     * @return string|null Normalized language code
     */
    protected function normalize_language_code($language) {
        // If it's already a short code (2-3 chars), return as-is
        if (strlen($language) <= 3) {
            return strtolower($language);
        }
        
        // Otherwise, try to map from full name
        return $this->get_code_from_name($language);
    }
}
