<?php
/**
 * Widget Parser Class - FIXED VERSION
 * Intelligently parses and maps Elementor widgets for translation
 * 
 * @package ElementorAITranslator
 */

if (!defined('ABSPATH')) {
    exit;
}

class AITRFOEL_Widget_Parser {
    
    /**
     * Instance
     */
    private static $instance = null;
    
    /**
     * Translatable fields whitelist - EXACT field names only
     */
    private $translatable_fields = [];
    
    /**
     * Protected fields blacklist (never translate these)
     */
    private $protected_fields = [];
    
    /**
     * Get instance
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
        $this->init_field_maps();
    }
    
    /**
     * Initialize field mappings
     */
    private function init_field_maps() {
        // EXACT translatable field names used by Elementor
        $this->translatable_fields = [
            // Text widget fields
            'editor', 'text', 'title', 'title_text', 'description_text', 'inner_text', 'text_field',
            'heading', 'subtitle', 'prefix', 'suffix', 'highlighted_text',
            'button', 'button_text', 'link_text', 'read_more_text',
            'field_label', 'placeholder', 'field_html', 'acceptance_text', 'success_message', 'error_message',
            'caption', 'alt', 'image_caption', 'lightbox_description',
            'testimonial_content', 'testimonial_name', 'testimonial_job',
            'tab_title', 'tab_content', 'accordion_item_title', 'accordion_item_content',
            'alert_title', 'alert_description', 'cta_title', 'cta_description', 'cta_button',
            'html', 'shortcode', 'excerpt', 'before_text', 'after_text',
            // Add more specific fields
            'label', 'description', 'content', 'message'
        ];
        
        // Protected fields that should NEVER be translated
        $this->protected_fields = [
            // Core Elementor structure
            '_id', 'id', 'custom_id', 'css_id', 'css_classes', '_css_classes',
            'widgetType', 'elType', 'isInner', 'elements', 'settings',
            
            // Links and media
            'url', 'link', 'video_link', 'custom_url', 'website_link', 'external_link',
            'image', 'icon', 'selected_icon', 'media', 'video', 'gallery', 'background_image',
            
            // Colors and styling
            'color', 'background_color', 'border_color', 'text_color', 'hover_color',
            'background_type', 'background_size', 'background_position', 'background_repeat',
            
            // Layout and positioning  
            'width', 'height', 'min_height', 'max_width', 'position', 'z_index',
            'margin', 'padding', 'border', 'border_radius', 'box_shadow',
            'flex_direction', 'justify_content', 'align_items', 'gap',
            'content_width', 'content_position', 'column_position',
            
            // Animations and effects
            'animation', 'hover_animation', '_animation', 'entrance_animation',
            'motion_fx_motion_fx_scrolling', 'motion_fx_motion_fx_mouse',
            
            // Typography settings (sizes, weights, etc - not text content)
            'font_family', 'font_size', 'font_weight', 'text_transform', 'line_height',
            'letter_spacing', 'text_decoration', 'font_style',
            
            // Technical attributes
            'data_settings', 'custom_css', 'html_tag', 'size', 'display',
            'overflow', 'pointer_events', 'user_select',
            
            // Widget-specific technical fields
            'columns', 'slides_to_show', 'autoplay', 'infinite', 'speed',
            'effect', 'direction', 'navigation', 'pagination', 'scrollbar',
            
            // Form and input attributes
            'name', 'value', 'placeholder_text', 'required', 'multiple',
            'accept', 'capture', 'autocomplete', 'pattern', 'min', 'max', 'step',
            
            // Advanced CSS selectors and custom code
            'selector', 'custom_selector', 'css_filters', 'mix_blend_mode',
            'render_type', 'prefix_class', 'hide_desktop', 'hide_tablet', 'hide_mobile',
            
            // Additional Elementor layout fields
            'stretch_section', 'boxed_width', 'content_width_tablet', 'content_width_mobile',
            'gap', 'gap_tablet', 'gap_mobile', 'column_gap', 'column_gap_tablet', 'column_gap_mobile',
            'structure', 'section_width', 'section_position', 'layout', 'content_layout',
            
            // Elementor column/section specific
            '_column_size', '_inline_size', 'space_between_widgets', 'content_position',
            'content_width', 'element_width', 'element_position', 'custom_width',
            'widget_width', 'widget_position', '_widget_width', '_element_width',
            
            // Image and media specific
            'image_size', 'image_width', 'image_height', 'object_fit', 'object_position',
            'container_class', 'image_class', 'wrapper_class', 'css_class',
            
            // Additional protection for containers and layout
            '_width', '_height', '_max_width', '_max_height', '_min_width', '_min_height',
            'section_width', 'container_width', 'content_width', 'full_width',
            'boxed_width', 'content_width_tablet', 'content_width_mobile',
            'column_width', 'widget_width', 'element_width', 'custom_width',
            
            // CSS and styling that can affect layout
            'css_before', 'css_after', 'css_selector', 'custom_css',
            'css_filters', 'css_transform', '_css_classes', 'css_id',
            'container_class', 'element_class', 'widget_class',
            
            // Responsive settings
            '_element_width_tablet', '_element_width_mobile',
            '_element_custom_width_tablet', '_element_custom_width_mobile',
            'hide_desktop', 'hide_tablet', 'hide_mobile',
            
            // CRITICAL: Any field containing 'width', 'height', 'size' 
            // This is a catch-all protection
        ];
    }
    
    /**
     * Parse Elementor data for translation
     * 
     * @param array $elementor_data The Elementor data array
     * @return array Parsed data with translation map
     */
    public function parse_for_translation($elementor_data) {
        $translation_map = [];
        
        AITRFOEL_Logger::log('Starting to parse Elementor data. Root elements count: ' . count($elementor_data), 'debug');
        
        // Parse each root element with index path
        foreach ($elementor_data as $index => $element) {
            $this->parse_element_recursive($element, $translation_map, [$index]);
        }
        
        AITRFOEL_Logger::log('Parsing complete. Found ' . count($translation_map) . ' translatable strings', 'info');
        
        // Debug: Log first 3 translation paths
        $sample_paths = array_slice(array_keys($translation_map), 0, 3);
        foreach ($sample_paths as $path) {
            $item = $translation_map[$path];
            $original_display = is_array($item['original']) ? 'Array[' . count($item['original']) . ']' : substr((string)$item['original'], 0, 50) . '...';
            AITRFOEL_Logger::log("Sample translation path: $path => " . $original_display, 'debug');
        }
        
        return $translation_map;
    }
    
    /**
     * Parse individual element recursively with proper path tracking
     */
    private function parse_element_recursive($element, &$translation_map, $path = []) {
        if (!is_array($element)) {
            return;
        }
        
        // Parse settings if they exist
        if (isset($element['settings']) && is_array($element['settings'])) {
            $this->parse_settings_recursive($element['settings'], $translation_map, array_merge($path, ['settings']));
        }
        
        // Parse child elements if they exist
        if (isset($element['elements']) && is_array($element['elements'])) {
            foreach ($element['elements'] as $child_index => $child_element) {
                $this->parse_element_recursive($child_element, $translation_map, array_merge($path, ['elements', $child_index]));
            }
        }
    }
    
    /**
     * Parse settings recursively with proper path tracking
     */
    private function parse_settings_recursive($settings, &$translation_map, $path = []) {
        foreach ($settings as $key => $value) {
            // Handle common direct attributes at this settings level (title/alt/aria-label/placeholder)
            if (is_string($value) && in_array($key, ['title','alt','aria-label','placeholder'], true)) {
                if ($this->is_translatable_content($value)) {
                    $attr_path = implode('.', array_merge($path, [$key]));
                    $translation_map[$attr_path] = [
                        'original' => $value,
                        'display_text' => $this->clean_html_for_display($value),
                        'type' => 'attr',
                        'field_name' => $key,
                        'path' => $attr_path,
                        'path_array' => array_merge($path, [$key])
                    ];
                }
                continue; // prevent re-processing by generic rules
            }

            // Special-case: image/media arrays may contain alt/title that should be translated safely
            if (($key === 'image' || $key === 'media') && is_array($value)) {
                $img_path = array_merge($path, [$key]);
                $img_path_str = implode('.', $img_path);
                foreach (['alt','title'] as $attr_name) {
                    if (!empty($value[$attr_name]) && is_string($value[$attr_name]) && $this->is_translatable_content($value[$attr_name])) {
                        $attr_path = $img_path_str . '.' . $attr_name;
                        $translation_map[$attr_path] = [
                            'original' => $value[$attr_name],
                            'display_text' => $this->clean_html_for_display($value[$attr_name]),
                            'type' => 'attr',
                            'field_name' => $attr_name,
                            'path' => $attr_path,
                            'path_array' => array_merge($img_path, [$attr_name])
                        ];
                    }
                }
                // Do not recurse into image/media; skip url/id to avoid accidental processing
                continue;
            }
            // Special-case: inspect 'link' for custom_attributes (aria-label) even if protected
            if ($key === 'link' && is_array($value)) {
                $current_path = array_merge($path, ['link']);
                // Elementor stores custom attributes as a space-separated string
                if (!empty($value['custom_attributes']) && is_string($value['custom_attributes'])) {
                    $attrs = $value['custom_attributes'];
                    if (preg_match('/(^|\s)aria-label\s*=\s*"([^"]*)"/u', $attrs, $m)) {
                        $attr_value = $m[2];
                        if ($this->is_translatable_content($attr_value)) {
                            $container_path = array_merge($current_path, ['custom_attributes']);
                            $container_path_string = implode('.', $container_path);
                            $path_string = $container_path_string . '#attr:aria-label';
                            $translation_map[$path_string] = [
                                'original' => $attr_value,
                                'display_text' => $this->clean_html_for_display($attr_value),
                                'type' => 'attr',
                                'field_name' => 'aria-label',
                                'path' => $path_string,
                                'attr_name' => 'aria-label',
                                'attr_container_path' => $container_path_string,
                                'path_array' => $container_path,
                            ];
                        }
                    }
                }
                // Never process url/rel/target for translation
                continue;
            }

            // Special-case: form fields repeater (common in Elementor forms)
            if ($key === 'form_fields' && is_array($value)) {
                foreach ($value as $idx => $ff) {
                    if (!is_array($ff)) continue;
                    foreach (['placeholder','field_label','title'] as $k) {
                        if (!empty($ff[$k]) && is_string($ff[$k]) && $this->is_translatable_content($ff[$k])) {
                            $p = array_merge($path, ['form_fields', $idx, $k]);
                            $ps = implode('.', $p);
                            $translation_map[$ps] = [
                                'original' => (string)$ff[$k],
                                'display_text' => $this->clean_html_for_display($ff[$k]),
                                'type' => 'attr',
                                'field_name' => $k,
                                'path' => $ps,
                                'path_array' => $p,
                            ];
                        }
                    }
                }
                // continue to next setting key (do not run generic recursion here)
                continue;
            }
            
            // Skip protected fields
            if ($this->is_protected_field($key)) {
                continue;
            }
            
            $current_path = array_merge($path, [$key]);
            
            if (is_string($value) && $this->is_translatable_field($key) && $this->is_translatable_content($value)) {
                // Found translatable content
                $path_string = implode('.', $current_path);
                
                AITRFOEL_Logger::log("Found translatable field '$key' at path: $path_string", 'debug');
                
                $translation_map[$path_string] = [
                    'original' => $value,
                    'display_text' => $this->clean_html_for_display($value),
                    'type' => 'field',
                    'field_name' => $key,
                    'path' => $path_string,
                    'path_array' => $current_path // Store as array for easier processing
                ];
            } elseif (is_array($value)) {
                // Recursively parse nested arrays (like repeater fields, tabs, etc.)
                $this->parse_nested_structure($value, $translation_map, $current_path);
            }
        }
    }
    
    /**
     * Parse nested structures like repeaters, tabs, accordions
     */
    private function parse_nested_structure($array, &$translation_map, $path = []) {
        foreach ($array as $key => $value) {
            // Skip if key is protected
            if (is_string($key) && $this->is_protected_field($key)) {
                continue;
            }
            
            $current_path = array_merge($path, [$key]);
            
            if (is_string($value) && is_string($key) && $this->is_translatable_field($key) && $this->is_translatable_content($value)) {
                $path_string = implode('.', $current_path);
                
                AITRFOEL_Logger::log("Found nested translatable field '$key' at path: $path_string", 'debug');
                
                $translation_map[$path_string] = [
                    'original' => $value,
                    'display_text' => $this->clean_html_for_display($value),
                    'type' => 'nested_field',
                    'field_name' => $key,
                    'path' => $path_string,
                    'path_array' => $current_path
                ];
            } elseif (is_array($value)) {
                // Continue recursion
                $this->parse_nested_structure($value, $translation_map, $current_path);
            }
        }
    }
    
    /**
     * Apply translations back to Elementor data - FIXED VERSION
     */
    public function apply_translations($elementor_data, $translations) {
        if (empty($translations)) {
            AITRFOEL_Logger::log('No translations to apply', 'warning');
            return $elementor_data;
        }
        
        // Create a deep copy to avoid modifying the original
        $translated_data = json_decode(json_encode($elementor_data), true);
        
        $success_count = 0;
        $fail_count = 0;
        $total_count = count($translations);
        
        AITRFOEL_Logger::log("Starting to apply $total_count translations", 'info');
        
        foreach ($translations as $path => $translated_text) {
            // Skip empty translations
            if (empty($translated_text)) {
                AITRFOEL_Logger::log("Skipping empty translation for path: $path", 'debug');
                $fail_count++;
                continue;
            }
            
            // Handle attribute updates marked with #attr:<name>
            if (strpos($path, '#attr:') !== false) {
                list($container_path, $attr_name) = explode('#attr:', $path, 2);
                $ok = $this->update_custom_attribute_value($translated_data, $container_path, $attr_name, $translated_text);
                if ($ok) {
                    $success_count++;
                    AITRFOEL_Logger::log("Updated custom attribute '$attr_name' at: $container_path", 'debug');
                } else {
                    $fail_count++;
                    AITRFOEL_Logger::log("Failed to update custom attribute '$attr_name' at: $container_path", 'warning');
                }
                continue;
            }

            // Apply the translation for regular fields
            $result = $this->set_value_by_path_fixed($translated_data, $path, $translated_text);
            
            if ($result !== false) {
                $translated_data = $result;
                $success_count++;
                AITRFOEL_Logger::log("Successfully applied translation to path: $path", 'debug');
            } else {
                $fail_count++;
                AITRFOEL_Logger::log("Failed to apply translation to path: $path", 'warning');
            }
        }
        
        AITRFOEL_Logger::log("Applied $success_count/$total_count translations successfully, $fail_count failed", 'info');
        
        // Validate the final structure
        if (!$this->validate_elementor_structure($translated_data)) {
            AITRFOEL_Logger::log('Structure validation failed after translation, returning original', 'error');
            return $elementor_data;
        }
        
        return $translated_data;
    }

    /**
     * Update a specific attribute (e.g., aria-label) inside a custom_attributes string at the given path.
     * Does not modify href/rel/target; only the provided attribute name.
     */
    private function update_custom_attribute_value(&$data, $container_path, $attr_name, $new_value) {
        // Get current string value
        $current = $this->get_value_by_path($data, $container_path);
        if (!is_string($current)) {
            return false;
        }
        // Only update whitelisted attributes
        $whitelist = ['title','alt','aria-label','placeholder'];
        if (!in_array($attr_name, $whitelist, true)) {
            return false;
        }
        // Build regex to match attribute with double quotes
        $pattern = '/(^|\s)'.preg_quote($attr_name, '/').'\s*=\s*"([^"]*)"/u';
        if (preg_match($pattern, $current)) {
            // Replace value only, keep spacing
            $updated = preg_replace($pattern, '$1'.$attr_name.'="'.addcslashes($new_value, '"').'"', $current, 1);
        } else {
            // If attribute not present, append it
            $sep = trim($current) === '' ? '' : ' ';
            $updated = $current . $sep . $attr_name.'="'.addcslashes($new_value, '"').'"';
        }
        // Set back the updated string
        $res = $this->set_value_by_path_fixed($data, $container_path, $updated);
        return $res !== false;
    }

    /**
     * Fetch a value by dot-path, without modifying structure.
     */
    private function get_value_by_path($array, $path) {
        $keys = explode('.', $path);
        $current = $array;
        foreach ($keys as $key) {
            if (is_numeric($key)) { $key = intval($key); }
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }
        return $current;
    }
    
    /**
     * Set value by path - FIXED VERSION
     */
    private function set_value_by_path_fixed(&$array, $path, $value) {
        $keys = explode('.', $path);
        $current = &$array;
        
        AITRFOEL_Logger::log("Setting value at path: $path", 'debug');
        
        // Navigate to the target location
        for ($i = 0; $i < count($keys) - 1; $i++) {
            $key = $keys[$i];
            
            // Convert numeric strings to integers for array indices
            if (is_numeric($key)) {
                $key = intval($key);
            }
            
            // Check if the key exists
            if (!isset($current[$key])) {
                AITRFOEL_Logger::log("Path not found at key '$key' (position $i) in path: $path", 'warning');
                return false;
            }
            
            // Check if we can navigate further
            if (!is_array($current[$key])) {
                AITRFOEL_Logger::log("Cannot navigate through non-array at key '$key' in path: $path", 'warning');
                return false;
            }
            
            $current = &$current[$key];
        }
        
        // Set the final value
        $final_key = end($keys);
        if (is_numeric($final_key)) {
            $final_key = intval($final_key);
        }
        
        // Check if the final key exists before setting
        if (!array_key_exists($final_key, $current)) {
            AITRFOEL_Logger::log("Final key '$final_key' does not exist in path: $path", 'warning');
            return false;
        }
        
        // Store old value for debugging
        $old_value = $current[$final_key];
        
        // Set the new value
        $current[$final_key] = $value;
        
        // Safe logging with type checking
        $old_display = is_array($old_value) ? 'Array[' . count($old_value) . ']' : substr((string)$old_value, 0, 50) . '...';
        $new_display = is_array($value) ? 'Array[' . count($value) . ']' : substr((string)$value, 0, 50) . '...';
        
        AITRFOEL_Logger::log("Successfully set value at path: $path (old: '$old_display', new: '$new_display')", 'debug');
        
        return $array;
    }
    
    /**
     * Check if field is translatable
     */
    private function is_translatable_field($field_name) {
        // SUPER PROTECTION: Block any field containing layout keywords
        if (preg_match('/(width|height|size|position|margin|padding|border|flex|grid|display|float|clear)/i', $field_name)) {
            return false;
        }
        
        // BLOCK internal/hidden titles and technical fields
        if (preg_match('/^_/', $field_name) || in_array($field_name, ['_title', '_id', '_css_classes', '_element_id'])) {
            return false;
        }
        
        // Check if it's protected first
        if ($this->is_protected_field($field_name)) {
            return false;
        }
        
        // Check exact match
        if (in_array($field_name, $this->translatable_fields, true)) {
            return true;
        }
        
        // Check patterns for common translatable fields
        $translatable_patterns = [
            '/^(title|heading|text|content|description|caption|label|message|subtitle|editor|html)$/i',
            '/^(button_text|link_text|placeholder|prefix|suffix)$/i',
            '/_(title|text|content|description|label|message)$/i',
            '/^field_(label|placeholder|html)$/i'
        ];
        
        foreach ($translatable_patterns as $pattern) {
            if (preg_match($pattern, $field_name)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if field is protected
     */
    private function is_protected_field($field_name) {
        return in_array($field_name, $this->protected_fields, true);
    }
    
    /**
     * Check if content should be translated
     */
    private function is_translatable_content($content) {
        if (empty($content) || !is_string($content)) {
            return false;
        }
        
        $content = trim($content);
        
        // Too short content - use mb_strlen for proper UTF-8 handling
        if (mb_strlen($content, 'UTF-8') < 2) {
            return false;
        }
        
        // Skip technical values (colors, CSS values, layout terms, etc.)
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $content) ||
            preg_match('/^rgba?\(.+\)$/i', $content) ||
            preg_match('/^hsla?\(.+\)$/i', $content) ||
            preg_match('/^-?\d+\.?\d*(px|em|rem|%|vh|vw|pt|pc|in|cm|mm|ex|ch|vmin|vmax)$/i', $content) ||
            preg_match('/^(left|right|center|justify|none|auto|inherit|initial|unset)$/i', $content) ||
            preg_match('/^(flex|grid|block|inline|table|absolute|relative|fixed|static|sticky)$/i', $content) ||
            preg_match('/^(normal|bold|lighter|bolder|[1-9]00)$/i', $content) ||
            preg_match('/^(top|bottom|middle|baseline|sub|super)$/i', $content) ||
            preg_match('/^(hidden|visible|scroll|overlay)$/i', $content) ||
            preg_match('/^(solid|dashed|dotted|double|groove|ridge|inset|outset)$/i', $content) ||
            preg_match('/^(linear|radial|conic)-gradient\(.+\)$/i', $content) ||
            preg_match('/^(ease|linear|ease-in|ease-out|ease-in-out|step-start|step-end)$/i', $content) ||
            preg_match('/^[0-9]+s?$/', $content) || // Duration values like 300ms, 2s
            preg_match('/^(cover|contain|fill|scale-down)$/i', $content) ||
            preg_match('/^(repeat|no-repeat|repeat-x|repeat-y)$/i', $content) ||
            preg_match('/^(full|boxed|wide)$/i', $content) || // Elementor width settings
            preg_match('/^(default|large|medium|small|xl|xs)$/i', $content) || // Size values
            preg_match('/^col-\d+$/i', $content) || // Column classes
            preg_match('/^elementor-|^e-|^fa-|^fab-|^fas-|^far-/', $content) || // CSS classes
            preg_match('/^(yes|no|true|false|on|off|1|0)$/i', $content) || // Boolean values
            preg_match('/^(desktop|tablet|mobile|_blank|_self|_parent|_top)$/i', $content) || // Device/target values
            preg_match('/^(start|end|space-between|space-around|space-evenly|stretch)$/i', $content) || // Flexbox values
            preg_match('/^(wrap|nowrap|wrap-reverse)$/i', $content) || // Flex wrap values
            preg_match('/^(uppercase|lowercase|capitalize|none)$/i', $content) || // Text transform
            preg_match('/^(underline|overline|line-through|none)$/i', $content) || // Text decoration
            preg_match('/^(serif|sans-serif|monospace|cursive|fantasy)$/i', $content) || // Font families
            preg_match('/^(thin|extra-light|light|normal|medium|semi-bold|bold|extra-bold|black)$/i', $content) || // Font weights
            preg_match('/^(italic|oblique|normal)$/i', $content) || // Font styles
            preg_match('/^(\d+\.?\d*|\.\d+)$/', $content)) { // Pure numbers
            return false;
        }
        
        // Skip URLs and emails
        if (filter_var($content, FILTER_VALIDATE_URL) ||
            filter_var($content, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        
        // Must contain at least some letters (including Cyrillic, Arabic, Asian scripts)
        // \p{L} matches any Unicode letter
        if (!preg_match('/\p{L}/u', $content)) {
            return false;
        }
        
        // Special validation for Cyrillic content - ensure it's not a technical term
        if (preg_match('/[а-яёА-ЯЁ]/u', $content)) {
            // Log for debugging
            AITRFOEL_Logger::log('Found Cyrillic content: ' . mb_substr($content, 0, 50) . '...', 'debug');
        }
        
        return true;
    }
    
    /**
     * Validate Elementor structure integrity
     */
    private function validate_elementor_structure($data) {
        if (!is_array($data) || empty($data)) {
            return false;
        }
        
        foreach ($data as $element) {
            if (!$this->validate_element_structure($element)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Validate individual element structure
     */
    private function validate_element_structure($element) {
        if (!is_array($element)) {
            return false;
        }
        
        // Required fields for Elementor elements
        if (!isset($element['id']) || !isset($element['elType'])) {
            return false;
        }
        
        // For widgets, widgetType is required
        if ($element['elType'] === 'widget' && !isset($element['widgetType'])) {
            return false;
        }
        
        // Recursively check child elements
        if (isset($element['elements']) && is_array($element['elements'])) {
            foreach ($element['elements'] as $child) {
                if (!$this->validate_element_structure($child)) {
                    return false;
                }
            }
        }
        
        return true;
    }
    
    /**
     * Clean HTML for display
     */
    private function clean_html_for_display($text) {
        $text = wp_strip_all_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim($text);
    }

    /**
     * Extract inline HTML into placeholder tokens <ph id="n"/>
     * Returns array: [$text_with_placeholders, $map]
     * Inline tags preserved: a, strong, em, b, i, u, span, small, sup, sub, mark, code, kbd
     */
    public function i18n_extract_with_placeholders($html) {
        if (!is_string($html) || trim($html) === '') {
            return [$html, []];
        }
    // Protect only a subset of inline tags with structural risk.
    // IMPORTANT: Do NOT placeholderize strong/em/b/i/span so their inner text is visible to the model and can be translated.
    // Links ('a') are already excluded on purpose to preserve anchors.
    $inline_tags = ['u','small','sup','sub','mark','code','kbd'];
        $map = [];
        $counter = 0;

        // Load as HTML fragment
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->encoding = 'UTF-8';
        libxml_use_internal_errors(true);
        $wrapped = '<div>' . $html . '</div>';
        // Force UTF-8 parsing using XML prolog trick
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8">' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        if (!$loaded) {
            return [$html, []];
        }

        $xpath = new DOMXPath($doc);
        // Query all inline tags
        $query = './/' . implode('|.//', array_map('strval', $inline_tags));
        $nodes = $xpath->query($query);
        // We need to collect nodes first to avoid live NodeList issues
        $toReplace = [];
        foreach ($nodes as $n) { $toReplace[] = $n; }

        foreach ($toReplace as $node) {
            $id = $counter++;
            $ph = $doc->createDocumentFragment();
            $ph->appendXML('<ph id="' . $id . '"/>');

            // Serialize the original node
            $tmpDoc = new DOMDocument('1.0', 'UTF-8');
            $tmpDoc->encoding = 'UTF-8';
            $tmpDoc->appendChild($tmpDoc->importNode($node, true));
            $orig_html = trim($tmpDoc->saveHTML($tmpDoc->documentElement));
            $map[$id] = $orig_html;

            // Replace node with placeholder
            $node->parentNode->replaceChild($ph, $node);
        }

        // Get innerHTML of wrapper div
        $container = $doc->getElementsByTagName('div')->item(0);
        $result_html = '';
        if ($container) {
            foreach ($container->childNodes as $child) {
                $result_html .= $doc->saveHTML($child);
            }
        } else {
            $result_html = $html;
        }
        return [$result_html, $map];
    }

    /**
     * Restore <ph id="n"/> tokens back to original inline HTML using map.
     */
    public function i18n_restore_placeholders($text, $map) {
        if (!is_string($text) || empty($map)) {
            return $text;
        }
        // Replace self-closing <ph id="n"/> or <ph id="n"></ph>
        foreach ($map as $id => $orig_html) {
            $patterns = [
                '#<ph\s+id=\"'.preg_quote((string)$id,'#').'\"\s*/>#u',
                '#<ph\s+id=\"'.preg_quote((string)$id,'#').'\"\s*>\s*</ph>#u',
            ];
            foreach ($patterns as $p) {
                $text = preg_replace($p, $orig_html, $text);
            }
        }
        return $text;
    }

    /**
     * Cleanup common AI artifacts after restoration:
     * - Fix JSON-escaped closing tags like <\/p> → </p>
     * - Normalize <ph id="n"></ph> → <ph id="n"/>
     * - Remove any remaining orphan <ph> or </ph>
     * - Drop guillemet placeholder markers ≪PGR_x≫, ≪/PGR_x≫, etc.
     */
    public function i18n_cleanup_artifacts($text) {
        if (!is_string($text) || $text === '') return $text;
        // 1) Fix JSON-escaped closing tags for common inline/block tags, allowing spaces and optional backslash before '/'
        $tags = '(ph|p|a|strong|em|span|b|i|u|small|sup|sub|mark|code|kbd|h1|h2|h3|h4|h5|h6|div|li|ul|ol)';
        // Matches variants like: </p>, < /p>, <\/p>, < \/ p >
        // Note: use \\\\?/ to represent an optional single backslash in PHP single-quoted string
        $text = preg_replace('#<\s*\\\\?/\s*' . $tags . '\s*>#i', '</$1>', $text);
        
        // 2) Normalize <ph>...</ph> (including escaped </ph>) → self-closing when empty
        $text = preg_replace('#<ph\b([^>]*)>\s*<\s*\\\\?/\s*ph\s*>#i', '<ph$1/>', $text);
        
        // 3) Remove any remaining orphan </ph> (including escaped) and opening <ph ...>
        // 3a) Closing tags (</ph> or <\/ph>)
        $text = preg_replace('#<\s*\\\\?/\s*ph\b[^>]*>#i', '', $text);
        // 3b) Opening tags (<ph ...>)
        $text = preg_replace('#<\s*ph\b[^>]*>#i', '', $text);
        
        // 4) Remove guillemet placeholder markers like ≪PGR_xxx≫ and ≪/PGR_xxx≫
        $text = preg_replace('/≪\/?[A-Z]{3}_[0-9a-fA-F]{6,16}≫/u', '', $text);
        return $text;
    }
    
    /**
     * Try to restore lost links using simple pattern matching
     * This is a fallback when OpenAI removes link tags
     */
    public function restore_lost_links($translated_text, $original_text) {
        if (!is_string($translated_text) || !is_string($original_text)) {
            return $translated_text;
        }
        
        // Extract all links from original
        if (preg_match_all('/<a\s+([^>]*href=["\']([^"\']+)["\'][^>]*)>([^<]*)<\/a>/i', $original_text, $orig_matches, PREG_SET_ORDER)) {
            
            foreach ($orig_matches as $orig_match) {
                $full_link = $orig_match[0];
                $attributes = $orig_match[1];
                $href = $orig_match[2];
                $link_text = $orig_match[3];
                
                // Check if this link text appears in translated text without tags
                $translated_link_text = $this->find_translated_text($link_text, $translated_text, $original_text);
                
                if ($translated_link_text && strpos($translated_text, '<a') === false) {
                    // Link was completely lost, restore it
                    $restored_link = '<a href="' . htmlspecialchars($href) . '">' . $translated_link_text . '</a>';
                    $translated_text = str_replace($translated_link_text, $restored_link, $translated_text);
                }
            }
        }
        
        return $translated_text;
    }
    
    /**
     * Find translated version of text by context matching
     */
    private function find_translated_text($original_phrase, $translated_text, $original_text) {
        // Common link text patterns and their Czech translations
        $link_patterns = [
            '/read more/i' => ['přečtěte si více', 'číst více', 'více informací'],
            '/learn more/i' => ['dozvědět se více', 'zjistit více'],
            '/click here/i' => ['klikněte zde', 'klikni zde'],
            '/see more/i' => ['zobrazit více', 'ukázat více'],
        ];
        
        $original_phrase_lower = strtolower(trim($original_phrase));
        
        // Check if it matches common patterns
        foreach ($link_patterns as $pattern => $translations) {
            if (preg_match($pattern, $original_phrase_lower)) {
                foreach ($translations as $translation) {
                    if (stripos($translated_text, $translation) !== false) {
                        return $translation;
                    }
                }
            }
        }
        
        // If no pattern match, try to find by word similarity
        // Look for phrases in translated text that could be the translation
        if (preg_match_all('/\b[\w\s]{2,30}\b/u', $translated_text, $phrases)) {
            foreach ($phrases[0] as $phrase) {
                $phrase = trim($phrase);
                if (strlen($phrase) > 2 && abs(strlen($phrase) - strlen($original_phrase)) < 5) {
                    // Could be the translated link text
                    return $phrase;
                }
            }
        }
        
        return null;
    }

    /**
     * Validate Elementor data structure
     * 
     * @param array $data Elementor data to validate
     * @return bool True if structure is valid
     */
    public function validate_structure($data) {
        if (!is_array($data)) {
            AITRFOEL_Logger::log('Structure validation failed: not an array', 'error');
            return false;
        }
        
        // Check if it's empty (empty structure is valid)
        if (empty($data)) {
            return true;
        }
        
        // Use existing validation method
        return $this->validate_elementor_structure($data);
    }
    
    /**
     * Extract a flat list of translatable strings from Elementor data.
     * Accepts raw meta value (JSON string/serialized) or decoded array.
     *
     * @param string|array $elementor_data
     * @return array<int, array{original:string, display_text:string, path:string, field_name:string, type:string}>
     */
    public function extract_translatable_strings($elementor_data) {
        try {
            // Normalize input to array
            $data = [];
            if (is_array($elementor_data)) {
                $data = $elementor_data;
            } elseif (is_string($elementor_data)) {
                $raw = $elementor_data;
                // Handle slashed JSON stored by WP
                $json = function_exists('wp_unslash') ? wp_unslash($raw) : $raw;
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                } else {
                    // Some sites may store serialized JSON
                    $maybe = function_exists('maybe_unserialize') ? maybe_unserialize($raw) : $raw;
                    if (is_string($maybe)) {
                        $maybe_decoded = json_decode($maybe, true);
                        if (is_array($maybe_decoded)) {
                            $data = $maybe_decoded;
                        }
                    } elseif (is_array($maybe)) {
                        $data = $maybe;
                    }
                }
            }
            
            if (!is_array($data) || empty($data)) {
                AITRFOEL_Logger::log('extract_translatable_strings: Empty or invalid Elementor data', 'warning');
                return [];
            }
            
            $map = $this->parse_for_translation($data);
            $result = [];
            foreach ($map as $path => $item) {
                $result[] = [
                    'original' => isset($item['original']) ? (string)$item['original'] : '',
                    'display_text' => isset($item['display_text']) ? (string)$item['display_text'] : '',
                    'path' => (string)$path,
                    'field_name' => isset($item['field_name']) ? (string)$item['field_name'] : '',
                    'type' => isset($item['type']) ? (string)$item['type'] : 'field',
                ];
            }
            
            return $result;
        } catch (Exception $e) {
            AITRFOEL_Logger::log('extract_translatable_strings error: ' . $e->getMessage(), 'error');
            return [];
        }
    }
} 
