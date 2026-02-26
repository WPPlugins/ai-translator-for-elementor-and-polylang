<?php
/**
 * Gutenberg Block Parser Class
 * Parses Gutenberg blocks for translation using WordPress block API
 * 
 * Strategy: Translate entire block innerHTML as a whole chunk (preserving HTML tags)
 * rather than extracting individual text nodes. This provides:
 * - Full sentence context for the AI translator → better quality
 * - Fewer API calls → much faster translation
 * - Reliable block-level replacement → no partial-match issues
 */

if (!defined('ABSPATH')) exit;

class AITRFOEL_Gutenberg_Parser {
    
    private static $instance = null;
    
    /**
     * Block types to skip (dynamic blocks that don't need translation)
     */
    private $skip_blocks = [];
    
    /**
     * Get singleton instance
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
            'core/separator',
            'core/more',
            'core/nextpage',
        ];
    }
    
    /**
     * Parse post content for translation
     * Extracts whole block content as translatable chunks (not individual text nodes)
     * 
     * @param int $post_id Post ID
     * @return array Translation map
     */
    public function parse_for_translation($post_id) {
        $post = get_post($post_id);
        
        if (!$post || empty($post->post_content)) {
            return [];
        }
        
        $blocks = $this->parse_blocks($post->post_content);
        
        $translation_map = [];
        $this->extract_translatable_blocks($blocks, $translation_map, '');
        
        return $translation_map;
    }
    
    /**
     * Parse blocks from content
     */
    private function parse_blocks($content) {
        if (function_exists('parse_blocks')) {
            return parse_blocks($content);
        }
        return [];
    }
    
    /**
     * Recursively extract translatable content from blocks
     * Extracts WHOLE block innerHTML as a single translatable string
     */
    private function extract_translatable_blocks($blocks, &$translation_map, $path) {
        // Container blocks that should recurse into innerBlocks
        $container_blocks = [
            'core/columns', 'core/column', 'core/group', 'core/cover',
            'core/media-text', 'core/buttons', 'core/details',
        ];
        
        foreach ($blocks as $index => $block) {
            $current_path = $path . '/' . $index;
            $block_name = isset($block['blockName']) ? $block['blockName'] : null;
            
            // Null/freeform blocks (Classic content or loose HTML)
            if (empty($block_name)) {
                if (!empty($block['innerHTML']) && $this->has_translatable_text($block['innerHTML'])) {
                    $key = 'gb_' . md5($current_path . '_freeform');
                    $translation_map[$key] = [
                        'original' => trim($block['innerHTML']),
                        'path' => $current_path,
                        'type' => 'block_html',
                        'widget_type' => 'gutenberg_freeform',
                        'block_name' => 'freeform',
                    ];
                }
                continue;
            }
            
            // Skip dynamic blocks
            if (in_array($block_name, $this->skip_blocks, true)) {
                continue;
            }
            
            // Container blocks: recurse into inner blocks only
            if (in_array($block_name, $container_blocks, true) && !empty($block['innerBlocks'])) {
                $this->extract_translatable_blocks($block['innerBlocks'], $translation_map, $current_path . '/inner');
                continue;
            }
            
            // List block with inner list-items (newer WP)
            if ($block_name === 'core/list' && !empty($block['innerBlocks'])) {
                $this->extract_translatable_blocks($block['innerBlocks'], $translation_map, $current_path . '/inner');
                continue;
            }
            
            // Content blocks: extract full innerHTML as one translatable chunk
            if (!empty($block['innerHTML']) && $this->has_translatable_text($block['innerHTML'])) {
                $key = 'gb_' . md5($current_path . '_html');
                $translation_map[$key] = [
                    'original' => trim($block['innerHTML']),
                    'path' => $current_path,
                    'type' => 'block_html',
                    'widget_type' => 'gutenberg_' . sanitize_key($block_name),
                    'block_name' => $block_name,
                ];
            }
            
            // Also extract translatable attributes (alt text, captions, etc.)
            if (!empty($block['attrs'])) {
                $this->extract_from_attributes($block['attrs'], $translation_map, $current_path, $block_name);
            }
            
            // Recurse into inner blocks for non-container blocks that may still have children
            if (!empty($block['innerBlocks']) && !in_array($block_name, $container_blocks, true)) {
                $this->extract_translatable_blocks($block['innerBlocks'], $translation_map, $current_path . '/inner');
            }
        }
    }
    
    /**
     * Check if HTML content contains meaningful translatable text
     */
    private function has_translatable_text($html) {
        $text = wp_strip_all_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/', ' ', $text));
        
        return (mb_strlen($text) >= 2 && preg_match('/[\p{L}\p{N}]/u', $text));
    }
    
    /**
     * Extract translatable text from block attributes
     */
    private function extract_from_attributes($attrs, &$translation_map, $path, $block_name) {
        $translatable_attrs = [
            'content', 'citation', 'value', 'text', 'title',
            'alt', 'caption', 'placeholder', 'label', 'summary',
        ];
        
        foreach ($attrs as $key => $value) {
            if (!is_string($value) || empty(trim($value))) {
                continue;
            }
            
            if (in_array($key, $translatable_attrs, true)) {
                $text = wp_strip_all_tags($value);
                $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
                $text = trim($text);
                
                if (strlen($text) > 1 && preg_match('/\w/u', $text)) {
                    $map_key = 'gb_' . md5($path . '_attr_' . $key);
                    $translation_map[$map_key] = [
                        'original' => $value,
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
     * Replaces entire block innerHTML with translated version
     * 
     * @param string $original_content Original post content
     * @param array $translations Array of original => translated text
     * @return string Translated content
     */
    public function apply_translations($original_content, $translations) {
        if (empty($translations)) {
            return $original_content;
        }
        
        $content = $original_content;
        
        // Sort by length (longest first) to prevent partial replacements
        uksort($translations, function($a, $b) {
            return strlen($b) - strlen($a);
        });
        
        foreach ($translations as $original => $translated) {
            if (empty($original) || empty($translated) || $original === $translated) {
                continue;
            }
            
            // Replace all occurrences — handles duplicate blocks with same content
            // Safe because we're replacing whole block innerHTML which won't be substrings of each other
            $content = str_replace($original, $translated, $content);
        }
        
        return $content;
    }
    
    /**
     * Get translatable strings as flat array
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
     */
    public function is_translatable_block($block_name) {
        return !in_array($block_name, $this->skip_blocks, true);
    }
}
