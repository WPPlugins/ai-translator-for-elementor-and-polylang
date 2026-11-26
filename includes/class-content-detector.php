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
    const TYPE_FORM = 'form';
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

    private function __construct() {
        add_action('save_post', [$this, 'on_save_post'], 25, 2);
        add_action('elementor/document/after_save', [$this, 'on_elementor_save'], 25, 1);
    }

    /**
     * Handle standard post saves to recalculate and cache word count
     */
    public function on_save_post($post_id, $post = null) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if ($post && $post->post_status === 'auto-draft') return;

        // Recalculate and update postmeta cache
        $this->get_cached_word_count($post_id, true);
    }

    /**
     * Handle Elementor saves to recalculate and cache word count
     */
    public function on_elementor_save($document) {
        if ($document && method_exists($document, 'get_main_id')) {
            $post_id = $document->get_main_id();
            if ($post_id) {
                $this->get_cached_word_count($post_id, true);
            }
        }
    }
    
    /**
     * Detect the content type of a post
     * 
     * @param int $post_id Post ID
     * @return string Content type constant
     */
    public function detect($post_id) {
        // SureCart products are API-first: their translatable content is the product
        // name (post_title) and description (post_excerpt), mirrored locally — exactly
        // the same shape as a WooCommerce product. Route them through the product path
        // so the review captures title + description, regardless of any block markup
        // the product-page template leaves in post_content.
        $sc_post = get_post($post_id);
        if ($sc_post && $sc_post->post_type === 'sc_product') {
            return self::TYPE_WOOCOMMERCE;
        }

        $form_post_types = ['wpcf7_contact_form', 'sureforms_form', 'sf_form', 'wpforms', 'jet-form-builder', 'fluentform', 'formidable', 'ninja_forms'];
        $form_post = get_post($post_id);
        if ($form_post && in_array($form_post->post_type, $form_post_types, true)) {
            return self::TYPE_FORM;
        }

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
            self::TYPE_FORM => __('Form', 'ai-translator-for-elementor-and-polylang'),
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
            self::TYPE_ELEMENTOR => '',
            self::TYPE_GUTENBERG => '',
            self::TYPE_CLASSIC => '',
            self::TYPE_WOOCOMMERCE => '',
            self::TYPE_FORM => '',
            self::TYPE_UNKNOWN => '',
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
            self::TYPE_FORM,
        ], true);
    }

    /**
     * Get translatable word count for a post with caching in postmeta.
     *
     * @param int  $post_id
     * @param bool $force_refresh
     * @return int
     */
    public function get_cached_word_count($post_id, $force_refresh = false) {
        $post_id = (int) $post_id;
        if (!$post_id) {
            return 0;
        }

        if (!$force_refresh) {
            $cached = get_post_meta($post_id, '_aitrfoel_word_count', true);
            if ($cached !== '' && is_numeric($cached)) {
                return (int) $cached;
            }
        }

        $word_count = $this->calculate_post_word_count($post_id);
        update_post_meta($post_id, '_aitrfoel_word_count', $word_count);
        return $word_count;
    }

    /**
     * Calculate translatable word count for a post.
     *
     * @param int $post_id
     * @return int
     */
    public function calculate_post_word_count($post_id) {
        $post_id = (int) $post_id;
        if (!$post_id) {
            return 0;
        }

        $content_type = $this->detect($post_id);
        $word_count = 0;
        $translation_map = [];

        try {
            switch ($content_type) {
                case self::TYPE_ELEMENTOR:
                    $elementor_data = get_post_meta($post_id, '_elementor_data', true);
                    if (!empty($elementor_data)) {
                        $elements = json_decode($elementor_data, true);
                        if (json_last_error() === JSON_ERROR_NONE && !empty($elements)) {
                            if (class_exists('AITRFOEL_Widget_Parser')) {
                                $parser = AITRFOEL_Widget_Parser::get_instance();
                                $translation_map = $parser->parse_for_translation($elements);
                            }
                        }
                    }
                    break;

                case self::TYPE_GUTENBERG:
                    if (class_exists('AITRFOEL_Gutenberg_Parser')) {
                        $parser = AITRFOEL_Gutenberg_Parser::get_instance();
                        $translation_map = $parser->parse_for_translation($post_id);
                    }
                    break;

                case self::TYPE_CLASSIC:
                    if (class_exists('AITRFOEL_Classic_Parser')) {
                        $parser = AITRFOEL_Classic_Parser::get_instance();
                        $translation_map = $parser->parse_for_translation($post_id);
                    }
                    break;

                case self::TYPE_WOOCOMMERCE:
                    $wc_post = get_post($post_id);
                    if ($wc_post && !empty(trim($wc_post->post_excerpt))) {
                        $translation_map['wc_excerpt'] = [
                            'original' => $wc_post->post_excerpt,
                            'path'     => 'excerpt',
                            'type'     => 'text',
                        ];
                    }
                    break;

                case self::TYPE_FORM:
                    $fpost = get_post($post_id);
                    if ($fpost && has_blocks($fpost->post_content) && class_exists('AITRFOEL_Gutenberg_Parser')) {
                        $parser = AITRFOEL_Gutenberg_Parser::get_instance();
                        $translation_map = $parser->parse_for_translation($post_id);
                    } elseif ($fpost && !empty($fpost->post_content)) {
                        $translation_map['form_content'] = [
                            'original' => $fpost->post_content,
                            'path'     => 'content',
                            'type'     => 'text',
                        ];
                        $messages = get_post_meta($post_id, '_messages', true);
                        if (is_array($messages)) {
                            foreach ($messages as $m_key => $m_val) {
                                if (!empty($m_val) && is_string($m_val)) {
                                    $translation_map['msg_' . $m_key] = [
                                        'original' => $m_val,
                                        'path'     => 'meta._messages.' . $m_key,
                                        'type'     => 'text',
                                    ];
                                }
                            }
                        }
                    }
                    break;

                default:
                    $p = get_post($post_id);
                    if ($p && !empty($p->post_content)) {
                        $translation_map['content'] = [
                            'original' => $p->post_content,
                            'path'     => 'content',
                            'type'     => 'text',
                        ];
                    }
                    break;
            }
        } catch (\Throwable $e) {
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log(
                    sprintf('Word count calculation error for post %d: %s', $post_id, $e->getMessage()),
                    'warning',
                    $post_id
                );
            }
        }

        // Count words from parsed translatable strings
        foreach ($translation_map as $entry) {
            $text = is_array($entry) ? ($entry['original'] ?? '') : $entry;
            if (is_string($text) && !empty($text)) {
                if (class_exists('AITRFOEL_Translation_Engine')) {
                    $word_count += AITRFOEL_Translation_Engine::count_words(wp_strip_all_tags($text));
                } else {
                    $word_count += str_word_count(wp_strip_all_tags($text));
                }
            }
        }

        // Add title words
        $post = get_post($post_id);
        if ($post && !empty($post->post_title)) {
            if (class_exists('AITRFOEL_Translation_Engine')) {
                $word_count += AITRFOEL_Translation_Engine::count_words(wp_strip_all_tags($post->post_title));
            } else {
                $word_count += str_word_count(wp_strip_all_tags($post->post_title));
            }
        }

        // Add translatable custom-field meta
        if (class_exists('AITRFOEL_Translation_Engine')) {
            $engine = AITRFOEL_Translation_Engine::get_instance();
            if (method_exists($engine, 'get_translatable_meta_texts')) {
                foreach ($engine->get_translatable_meta_texts($post_id) as $meta_text) {
                    $word_count += AITRFOEL_Translation_Engine::count_words(wp_strip_all_tags($meta_text));
                }
            }
        }

        return $word_count;
    }
}
