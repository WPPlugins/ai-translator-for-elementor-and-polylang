<?php
/**
 * Content Detector Class
 * Detects the content type of a post (Elementor, Gutenberg, or Classic Editor)
 */

if (!defined('ABSPATH')) exit;

class AITRFOEL_Content_Detector {
    
    private static $instance = null;
    
    /**
     * Content type constants
     */
    const TYPE_ELEMENTOR = 'elementor';
    const TYPE_GUTENBERG = 'gutenberg';
    const TYPE_CLASSIC = 'classic';
    const TYPE_WOOCOMMERCE = 'woocommerce';
    const TYPE_UNKNOWN = 'unknown';
    
    /**
     * Get singleton instance
     * 
     * @return AITRFOEL_Content_Detector
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {}
    
    /**
     * Detect the content type of a post
     * 
     * @param int $post_id Post ID
     * @return string Content type constant
     */
    public function detect($post_id) {
        $has_elementor = $this->is_elementor_page($post_id);
        $has_gutenberg = $this->is_gutenberg_page($post_id);
        
        // When both Elementor data AND Gutenberg blocks exist, the user likely
        // switched from Elementor to a block-based editor (e.g. Greenshift).
        // Stale _elementor_data remains but post_content now drives rendering.
        // Prefer Gutenberg in this case; pure Elementor pages have no block markers.
        if ($has_elementor && $has_gutenberg) {
            return self::TYPE_GUTENBERG;
        }
        
        // Check for Elementor (page only has Elementor data, no Gutenberg blocks)
        if ($has_elementor) {
            return self::TYPE_ELEMENTOR;
        }
        
        // Check for Gutenberg blocks
        if ($has_gutenberg) {
            return self::TYPE_GUTENBERG;
        }
        
        // Check if it has any content at all
        $post = get_post($post_id);
        if ($post && !empty(trim($post->post_content))) {
            return self::TYPE_CLASSIC;
        }

        // WooCommerce product with no editor content but has translatable data
        // (title, short description, meta, attributes, etc.)
        if ($post && $post->post_type === 'product') {
            return self::TYPE_WOOCOMMERCE;
        }

        // Pages with a title but no body (e.g. WooCommerce shop/cart/checkout/my-account
        // pages) are valid classic pages — the classic path will translate just the title
        // and create a translated shell page needed by the language switcher.
        if ($post && !empty(trim($post->post_title))) {
            return self::TYPE_CLASSIC;
        }

        return self::TYPE_UNKNOWN;
    }
    
    /**
     * Check if page uses Elementor
     * 
     * @param int $post_id Post ID
     * @return bool
     */
    public function is_elementor_page($post_id) {
        // Check for Elementor data meta
        $elementor_data = get_post_meta($post_id, '_elementor_data', true);
        
        if (empty($elementor_data)) {
            return false;
        }
        
        // Verify it's valid JSON with content
        $data = json_decode($elementor_data, true);
        
        if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
            return false;
        }
        
        // Check for Elementor edit mode meta
        $edit_mode = get_post_meta($post_id, '_elementor_edit_mode', true);
        
        return $edit_mode === 'builder' || !empty($data);
    }
    
    /**
     * Check if page uses Gutenberg blocks
     * 
     * @param int $post_id Post ID
     * @return bool
     */
    public function is_gutenberg_page($post_id) {
        $post = get_post($post_id);
        
        if (!$post || empty($post->post_content)) {
            return false;
        }
        
        // Check for Gutenberg block markers (<!-- wp: -->)
        return $this->has_blocks($post->post_content);
    }
    
    /**
     * Check if content contains Gutenberg blocks
     * Uses WordPress function if available, falls back to regex
     * 
     * @param string $content Post content
     * @return bool
     */
    public function has_blocks($content) {
        // Use WordPress built-in function if available (WP 5.0+)
        if (function_exists('has_blocks')) {
            return has_blocks($content);
        }
        
        // Fallback: Check for block comment markers
        return preg_match('/<!-- wp:/', $content) === 1;
    }
    
    /**
     * Get human-readable content type name
     * 
     * @param string $type Content type constant
     * @return string
     */
    public function get_type_label($type) {
        $labels = [
            self::TYPE_ELEMENTOR => __('Elementor', 'ai-translator-for-elementor-and-polylang'),
            self::TYPE_GUTENBERG => __('Gutenberg', 'ai-translator-for-elementor-and-polylang'),
            self::TYPE_CLASSIC => __('Classic Editor', 'ai-translator-for-elementor-and-polylang'),
            self::TYPE_WOOCOMMERCE => __('WooCommerce Product', 'ai-translator-for-elementor-and-polylang'),
            self::TYPE_UNKNOWN => __('Unknown', 'ai-translator-for-elementor-and-polylang'),
        ];
        
        return isset($labels[$type]) ? $labels[$type] : $labels[self::TYPE_UNKNOWN];
    }
    
    /**
     * Get icon for content type
     * 
     * @param string $type Content type constant
     * @return string
     */
    public function get_type_icon($type) {
        $icons = [
            self::TYPE_ELEMENTOR => '🎨',
            self::TYPE_GUTENBERG => '📦',
            self::TYPE_CLASSIC => '📝',
            self::TYPE_WOOCOMMERCE => '🛒',
            self::TYPE_UNKNOWN => '❓',
        ];
        
        return isset($icons[$type]) ? $icons[$type] : $icons[self::TYPE_UNKNOWN];
    }
    
    /**
     * Check if the content type is supported for translation
     * 
     * @param string $type Content type constant
     * @return bool
     */
    public function is_supported($type) {
        return in_array($type, [
            self::TYPE_ELEMENTOR,
            self::TYPE_GUTENBERG,
            self::TYPE_CLASSIC,
            self::TYPE_WOOCOMMERCE,
        ], true);
    }
}
