<?php
/**
 * Classic Editor Content Parser
 * Parses Classic Editor / raw HTML content for translation
 *
 * Strategy: Split HTML into semantic chunks (paragraphs, headings, list items, etc.)
 * and translate each chunk as a whole, preserving inline HTML tags.
 * The AI model handles inline tags well, so we send full paragraph HTML.
 */

if (!defined('ABSPATH')) exit;

class AITRFOEL_Classic_Parser {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {}
    
    /**
     * Parse post content for translation
     * Returns translation map compatible with the translation engine
     * 
     * @param int $post_id Post ID
     * @return array Translation map with 'original', 'path', 'type', 'widget_type' keys
     */
    public function parse_for_translation($post_id) {
        $post = get_post($post_id);
        
        if (!$post || empty($post->post_content)) {
            return [];
        }
        
        $strings = $this->extract_text_from_html($post->post_content);
        
        $translation_map = [];
        foreach ($strings as $index => $text) {
            $key = 'cl_' . md5($index . '_' . $text);
            $translation_map[$key] = [
                'original' => $text,
                'path' => 'classic/' . $index,
                'type' => 'block_html',
                'widget_type' => 'classic_editor',
            ];
        }
        
        return $translation_map;
    }
    
    /**
     * Extract translatable strings from Classic Editor HTML content
     * Returns semantic HTML chunks (whole paragraphs, headings, list items)
     * instead of individual text nodes.
     *
     * @param string $html The HTML content
     * @return array Array of translatable strings
     */
    public function extract_text_from_html($html) {
        if (empty(trim($html))) {
            return [];
        }
        
        $strings = [];
        
        // Normalize content: wpautop-style content often has bare text between <p> tags
        // We split the HTML into top-level block elements and translate each
        $chunks = $this->split_into_chunks($html);
        
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if (empty($chunk)) {
                continue;
            }
            
            // Check if the chunk has meaningful translatable text
            $text_only = wp_strip_all_tags($chunk);
            $text_only = html_entity_decode($text_only, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text_only = trim(preg_replace('/\s+/', ' ', $text_only));
            
            if (mb_strlen($text_only) < 2 || !preg_match('/[\p{L}\p{N}]/u', $text_only)) {
                continue;
            }
            
            // Avoid duplicates
            if (!in_array($chunk, $strings, true)) {
                $strings[] = $chunk;
            }
        }
        
        return $strings;
    }
    
    /**
     * Split HTML content into semantic chunks for translation
     * Each chunk is a top-level block element (p, h1-h6, ul, ol, blockquote, table, div, etc.)
     * 
     * @param string $html
     * @return array
     */
    private function split_into_chunks($html) {
        $chunks = [];
        
        // Block-level elements that we want to split on
        $block_tags = 'p|h[1-6]|ul|ol|li|blockquote|table|thead|tbody|tr|th|td|div|section|article|header|footer|figure|figcaption|pre|address|dl|dt|dd|details|summary|hr|br';
        
        // Try to split by top-level block elements
        // Match: optional whitespace, opening tag, content (greedy), closing tag
        $pattern = '#(<(?:' . $block_tags . ')(?:\s[^>]*)?>[\s\S]*?</(?:' . $block_tags . ')>)#i';
        
        // First try: split by block elements
        if (preg_match_all($pattern, $html, $matches)) {
            $last_end = 0;
            
            // Use preg_match_all with PREG_OFFSET_CAPTURE to get positions
            preg_match_all($pattern, $html, $positioned_matches, PREG_OFFSET_CAPTURE);
            
            foreach ($positioned_matches[0] as $match) {
                $match_text = $match[0];
                $match_pos = $match[1];
                
                // Check for text between blocks
                $between = substr($html, $last_end, $match_pos - $last_end);
                $between = trim($between);
                if (!empty($between) && $this->has_translatable_text($between)) {
                    $chunks[] = $between;
                }
                
                // Add the block element as a chunk
                $chunks[] = $match_text;
                $last_end = $match_pos + strlen($match_text);
            }
            
            // Handle trailing content
            $trailing = trim(substr($html, $last_end));
            if (!empty($trailing) && $this->has_translatable_text($trailing)) {
                $chunks[] = $trailing;
            }
        } else {
            // No block elements found — treat entire content as one chunk
            $chunks[] = trim($html);
        }
        
        return $chunks;
    }
    
    /**
     * Check if text has translatable content
     */
    private function has_translatable_text($text) {
        $stripped = wp_strip_all_tags($text);
        $stripped = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = trim(preg_replace('/\s+/', ' ', $stripped));
        return (mb_strlen($stripped) >= 2 && preg_match('/[\p{L}\p{N}]/u', $stripped));
    }
    
    /**
     * Apply translations to HTML content
     * Replaces entire chunk with its translated version
     *
     * @param string $html Original HTML content
     * @param array $translations Map of original => translated text
     * @return string Translated HTML
     */
    public function apply_translations($html, $translations) {
        if (empty($translations)) {
            return $html;
        }
        
        $result = $html;
        
        // Sort by length descending to replace longest strings first
        uksort($translations, function($a, $b) {
            return strlen($b) - strlen($a);
        });
        
        foreach ($translations as $original => $translated) {
            if (empty($original) || empty($translated) || $original === $translated) {
                continue;
            }
            
            // Replace all occurrences — handles duplicate paragraphs
            // Safe because we're replacing whole semantic HTML chunks
            $result = str_replace($original, $translated, $result);
        }
        
        return $result;
    }
}
