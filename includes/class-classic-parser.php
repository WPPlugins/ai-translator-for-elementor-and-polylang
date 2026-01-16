<?php
/**
 * Classic Editor Parser Class
 * Parses plain HTML content for translation
 */

if (!defined('ABSPATH')) exit;

class AITRFOEL_Classic_Parser {
    
    private static $instance = null;
    
    /**
     * Get singleton instance
     * 
     * @return AITRFOEL_Classic_Parser
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {}
    
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
        
        $translation_map = [];
        $content = $post->post_content;
        
        // Extract translatable text from HTML
        $extracted = $this->extract_text_from_html($content);
        
        foreach ($extracted as $index => $text_data) {
            if (!empty(trim($text_data['text']))) {
                $translation_map['classic_' . $index] = [
                    'original' => $text_data['text'],
                    'path' => 'post_content',
                    'type' => $text_data['type'],
                    'widget_type' => 'classic_editor',
                ];
            }
        }
        
        return $translation_map;
    }
    
    /**
     * Extract translatable text from HTML content
     * Preserves HTML tags but extracts text for translation
     * 
     * @param string $html HTML content
     * @return array Array of text segments with type info
     */
    private function extract_text_from_html($html) {
        $extracted = [];
        
        // Use DOMDocument to properly parse HTML
        if (empty($html)) {
            return $extracted;
        }
        
        // Preserve the original HTML but extract text nodes
        $doc = new DOMDocument('1.0', 'UTF-8');
        
        // Suppress warnings for malformed HTML
        libxml_use_internal_errors(true);
        
        // Wrap content to ensure proper UTF-8 handling
        $html_wrapped = '<?xml encoding="UTF-8"><div id="aitrfoel-root">' . $html . '</div>';
        $doc->loadHTML($html_wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        
        libxml_clear_errors();
        
        $xpath = new DOMXPath($doc);
        
        // Get all text nodes that are not just whitespace
        $text_nodes = $xpath->query('//text()[normalize-space() != ""]');
        
        foreach ($text_nodes as $index => $node) {
            $parent_tag = $node->parentNode ? $node->parentNode->nodeName : 'unknown';
            
            // Skip script and style content
            if (in_array($parent_tag, ['script', 'style', 'noscript'], true)) {
                continue;
            }
            
            $text = trim($node->nodeValue);
            
            // Only include meaningful text (not just punctuation or whitespace)
            if (strlen($text) > 1 && preg_match('/\w/', $text)) {
                $extracted[] = [
                    'text' => $text,
                    'type' => $this->get_text_type($parent_tag),
                    'parent_tag' => $parent_tag,
                ];
            }
        }
        
        return $extracted;
    }
    
    /**
     * Get semantic type based on parent HTML tag
     * 
     * @param string $tag HTML tag name
     * @return string Semantic type
     */
    private function get_text_type($tag) {
        $type_map = [
            'h1' => 'heading',
            'h2' => 'heading',
            'h3' => 'heading',
            'h4' => 'heading',
            'h5' => 'heading',
            'h6' => 'heading',
            'p' => 'paragraph',
            'a' => 'link_text',
            'li' => 'list_item',
            'td' => 'table_cell',
            'th' => 'table_header',
            'figcaption' => 'caption',
            'blockquote' => 'quote',
            'span' => 'text',
            'div' => 'text',
            'strong' => 'text',
            'em' => 'text',
            'b' => 'text',
            'i' => 'text',
            'label' => 'label',
            'button' => 'button_text',
        ];
        
        return isset($type_map[$tag]) ? $type_map[$tag] : 'text';
    }
    
    /**
     * Apply translations back to the content
     * 
     * @param string $original_content Original HTML content
     * @param array $translations Array of original => translated text
     * @return string Translated content
     */
    public function apply_translations($original_content, $translations) {
        $content = $original_content;
        
        // Sort translations by length (longest first) to avoid partial replacements
        uksort($translations, function($a, $b) {
            return strlen($b) - strlen($a);
        });
        
        foreach ($translations as $original => $translated) {
            if (empty($original) || empty($translated) || $original === $translated) {
                continue;
            }
            
            // Use regex to replace text while preserving surrounding HTML
            // This handles cases where text might be split across elements
            $pattern = '/' . preg_quote($original, '/') . '/u';
            $content = preg_replace($pattern, $translated, $content, 1);
        }
        
        return $content;
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
}
