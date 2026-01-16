<?php
/**
 * Gutenberg Block Parser Class
 * Parses Gutenberg blocks for translation using WordPress block API
 */

if (!defined('ABSPATH')) exit;

class AITRFOEL_Gutenberg_Parser {
    
    private static $instance = null;
    
    /**
     * Block types that contain translatable content
     */
    private $translatable_blocks = [];
    
    /**
     * Block types to skip (dynamic blocks that don't need translation)
     */
    private $skip_blocks = [];
    
    /**
     * Get singleton instance
     * 
     * @return AITRFOEL_Gutenberg_Parser
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_block_maps();
    }
    
    /**
     * Initialize block mappings
     */
    private function init_block_maps() {
        // Blocks with translatable content
        $this->translatable_blocks = [
            // Core blocks with text content
            'core/paragraph',
            'core/heading',
            'core/list',
            'core/list-item',
            'core/quote',
            'core/pullquote',
            'core/preformatted',
            'core/verse',
            'core/table',
            'core/button',
            'core/buttons',
            'core/image',       // alt text, caption
            'core/gallery',     // captions
            'core/media-text',
            'core/cover',
            'core/column',
            'core/columns',
            'core/group',
            'core/freeform',    // Classic block
            'core/html',
            'core/code',
            'core/details',
            'core/separator',
            // Common third-party blocks
            'acf/text',
            'generateblocks/headline',
            'generateblocks/text',
            'generateblocks/button',
        ];
        
        // Blocks to skip (dynamic, don't contain translatable text)
        $this->skip_blocks = [
            'core/latest-posts',
            'core/archives',
            'core/categories',
            'core/calendar',
            'core/tag-cloud',
            'core/page-list',
            'core/navigation',
            'core/site-title',
            'core/site-tagline',
            'core/site-logo',
            'core/query',
            'core/post-template',
            'core/post-title',
            'core/post-excerpt',
            'core/post-content',
            'core/post-date',
            'core/post-author',
            'core/post-terms',
            'core/post-featured-image',
            'core/comments',
            'core/shortcode',
            'core/embed',
            'core/video',
            'core/audio',
            'core/file',
            'core/spacer',
        ];
    }
    
    /**
     * Parse post content for translation
     * 
     * @param int $post_id Post ID
     * @return array Translation map with original strings and their locations
     */
    public function parse_for_translation($post_id) {
        $post = get_post($post_id);
        
        if (!$post || empty($post->post_content)) {
            return [];
        }
        
        // Parse blocks using WordPress function
        $blocks = $this->parse_blocks($post->post_content);
        
        $translation_map = [];
        $this->extract_translatable_strings($blocks, $translation_map, '');
        
        return $translation_map;
    }
    
    /**
     * Parse blocks from content
     * Uses WordPress parse_blocks if available
     * 
     * @param string $content Post content
     * @return array Parsed blocks
     */
    private function parse_blocks($content) {
        if (function_exists('parse_blocks')) {
            return parse_blocks($content);
        }
        
        // Fallback: return empty array if parse_blocks not available
        return [];
    }
    
    /**
     * Recursively extract translatable strings from blocks
     * 
     * @param array $blocks Array of parsed blocks
     * @param array &$translation_map Reference to translation map
     * @param string $path Current path in the block tree
     */
    private function extract_translatable_strings($blocks, &$translation_map, $path) {
        foreach ($blocks as $index => $block) {
            $current_path = $path . '/' . $index;
            $block_name = isset($block['blockName']) ? $block['blockName'] : null;
            
            // Skip null blocks (usually whitespace)
            if (empty($block_name)) {
                // Check if it's a classic block (freeform content)
                if (!empty($block['innerHTML'])) {
                    $this->extract_from_html($block['innerHTML'], $translation_map, $current_path, 'freeform');
                }
                continue;
            }
            
            // Skip dynamic blocks that don't need translation
            if (in_array($block_name, $this->skip_blocks, true)) {
                continue;
            }
            
            // Extract from innerHTML (the rendered content)
            if (!empty($block['innerHTML'])) {
                $this->extract_from_html($block['innerHTML'], $translation_map, $current_path, $block_name);
            }
            
            // Extract from block attributes
            if (!empty($block['attrs'])) {
                $this->extract_from_attributes($block['attrs'], $translation_map, $current_path, $block_name);
            }
            
            // Recursively process inner blocks
            if (!empty($block['innerBlocks'])) {
                $this->extract_translatable_strings($block['innerBlocks'], $translation_map, $current_path . '/innerBlocks');
            }
        }
    }
    
    /**
     * Extract translatable text from HTML content
     * Extracts individual text nodes to ensure replacements work correctly
     * 
     * @param string $html HTML content
     * @param array &$translation_map Reference to translation map
     * @param string $path Current path
     * @param string $block_name Block type name
     */
    private function extract_from_html($html, &$translation_map, $path, $block_name) {
        // Split HTML into tags and text nodes
        $parts = preg_split('/(<[^>]*>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        
        if ($parts === false) {
            return;
        }
        
        $text_node_index = 0;
        foreach ($parts as $part) {
            // Skip HTML tags (start with <)
            if (isset($part[0]) && $part[0] === '<') {
                continue;
            }
            
            // This is a text node - decode and clean it
            $text = html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim($text);
            
            // Only include meaningful text (more than just whitespace/punctuation)
            // Must have at least one letter or number and be at least 2 chars
            if (strlen($text) >= 2 && preg_match('/[\p{L}\p{N}]/u', $text)) {
                $key = 'gb_' . md5($path . '_textnode_' . $text_node_index);
                $translation_map[$key] = [
                    'original' => $text,
                    'path' => $path . '/text_' . $text_node_index,
                    'type' => 'content',
                    'widget_type' => 'gutenberg_' . sanitize_key($block_name),
                    'block_name' => $block_name,
                ];
                $text_node_index++;
            }
        }
    }
    
    /**
     * Extract translatable text from block attributes
     * 
     * @param array $attrs Block attributes
     * @param array &$translation_map Reference to translation map
     * @param string $path Current path
     * @param string $block_name Block type name
     */
    private function extract_from_attributes($attrs, &$translation_map, $path, $block_name) {
        // Translatable attribute keys
        $translatable_attrs = [
            'content',      // Paragraph, heading content
            'citation',     // Quote citation
            'value',        // Some blocks use 'value'
            'text',         // Button text
            'title',        // Various titles
            'alt',          // Image alt text
            'caption',      // Image/gallery caption
            'placeholder',  // Form placeholders
            'label',        // Form labels
            'summary',      // Details block summary
        ];
        
        foreach ($attrs as $key => $value) {
            // Only process string values
            if (!is_string($value) || empty(trim($value))) {
                continue;
            }
            
            // Check if this attribute is translatable
            if (in_array($key, $translatable_attrs, true)) {
                $text = wp_strip_all_tags($value);
                $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
                $text = trim($text);
                
                if (strlen($text) > 1 && preg_match('/\w/u', $text)) {
                    $map_key = 'gb_' . md5($path . '_attr_' . $key);
                    $translation_map[$map_key] = [
                        'original' => $text,
                        'path' => $path . '/attrs/' . $key,
                        'type' => 'attribute',
                        'attr_key' => $key,
                        'widget_type' => 'gutenberg_' . sanitize_key($block_name),
                        'block_name' => $block_name,
                    ];
                }
            }
        }
    }
    
    /**
     * Apply translations to block content
     * Uses DOMDocument to safely replace only text content, not HTML tags
     * 
     * @param string $original_content Original post content
     * @param array $translations Array of original => translated text
     * @return string Translated content
     */
    public function apply_translations($original_content, $translations) {
        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[AITRFOEL] apply_translations called with ' . count($translations) . ' translations');
            error_log('[AITRFOEL] Original content length: ' . strlen($original_content));
        }
        
        if (empty($translations)) {
            return $original_content;
        }
        
        // Sort by length (longest first) to prevent partial replacements
        uksort($translations, function($a, $b) {
            return strlen($b) - strlen($a);
        });
        
        // Skip translations with very short originals (could match inside tags)
        $safe_translations = [];
        foreach ($translations as $original => $translated) {
            // Skip if original is less than 3 chars or is just a number
            if (strlen($original) < 3 || preg_match('/^\d+\.?$/', $original)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[AITRFOEL] Skipping short/numeric original: "' . $original . '"');
                }
                continue;
            }
            if (!empty($original) && !empty($translated) && $original !== $translated) {
                $safe_translations[$original] = $translated;
            }
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[AITRFOEL] Processing ' . count($safe_translations) . ' safe translations');
        }
        
        // Process the content block by block using Gutenberg comments as anchors
        // This preserves the Gutenberg block structure while translating innerHTML
        $content = $original_content;
        
        foreach ($safe_translations as $original => $translated) {
            // Use a regex that only matches text BETWEEN > and < (text nodes only)
            // This is safer than str_replace as it won't touch tag names or attributes
            $content = $this->safe_text_replace($content, $original, $translated);
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[AITRFOEL] Final content length: ' . strlen($content));
        }
        
        return $content;
    }
    
    /**
     * Safely replace text only in text nodes (between > and <)
     * Never touches content inside HTML tags
     * 
     * @param string $html The HTML content
     * @param string $search The text to search for
     * @param string $replace The replacement text
     * @return string Modified HTML
     */
    private function safe_text_replace($html, $search, $replace) {
        // Split HTML into parts: tags and text nodes
        // Pattern captures HTML tags (including Gutenberg comments) as delimiters
        $parts = preg_split('/(<[^>]*>|<!--[\s\S]*?-->)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        
        if ($parts === false) {
            return $html;
        }
        
        $result = '';
        $found = false;
        
        foreach ($parts as $part) {
            // Check if this is an HTML tag or comment (starts with <)
            if (isset($part[0]) && $part[0] === '<') {
                // It's a tag or comment - keep unchanged
                $result .= $part;
            } else {
                // It's a text node - safe to search and replace
                // First decode HTML entities in the part for comparison
                $decoded_part = html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                
                if (strpos($decoded_part, $search) !== false) {
                    // Found the text - replace in decoded version
                    $new_text = str_replace($search, $replace, $decoded_part);
                    $result .= $new_text;
                    $found = true;
                    
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[AITRFOEL] Replaced in text node: "' . substr($search, 0, 40) . '..."');
                    }
                } else {
                    // No match - keep original (including any HTML entities)
                    $result .= $part;
                }
            }
        }
        
        if (!$found && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[AITRFOEL] No match found for: "' . substr($search, 0, 50) . '..."');
        }
        
        return $result;
    }
    
    /**
     * Get translatable strings as flat array for batch translation
     * 
     * @param array $translation_map Translation map from parse_for_translation
     * @return array Array of strings to translate
     */
    public function get_strings_for_translation($translation_map) {
        $strings = [];
        
        foreach ($translation_map as $key => $data) {
            if (isset($data['original']) && !empty(trim($data['original']))) {
                $strings[$key] = $data['original'];
            }
        }
        
        return $strings;
    }
    
    /**
     * Check if a block type should be translated
     * 
     * @param string $block_name Block type name
     * @return bool
     */
    public function is_translatable_block($block_name) {
        return !in_array($block_name, $this->skip_blocks, true);
    }
}
