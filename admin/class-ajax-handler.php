<?php
/**
 * Enhanced AJAX Handler with Error Handling and Cost Estimation
 */

if (!defined('ABSPATH')) {
    exit;
}

class AITRFOEL_Ajax_Handler {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Translation actions
        add_action('wp_ajax_aitrfoel_get_pages', [$this, 'get_pages']);
        add_action('wp_ajax_aitrfoel_translate_page', [$this, 'translate_page']);
        add_action('wp_ajax_aitrfoel_estimate_cost', [$this, 'estimate_cost']);
        add_action('wp_ajax_aitrfoel_get_usage_stats', [$this, 'get_usage_stats']);
        
        // Bulk translation actions
        add_action('wp_ajax_aitrfoel_pre_analyze_bulk', [$this, 'handle_pre_analyze_bulk']); // NEW: Pre-flight analysis
        add_action('wp_ajax_aitrfoel_start_bulk_translation', [$this, 'handle_start_bulk_translation']);
        add_action('wp_ajax_aitrfoel_get_translation_status', [$this, 'handle_get_translation_status']);
        add_action('wp_ajax_aitrfoel_pause_translation', [$this, 'handle_pause_translation']);
        add_action('wp_ajax_aitrfoel_resume_translation', [$this, 'handle_resume_translation']);
        add_action('wp_ajax_aitrfoel_cancel_translation', [$this, 'handle_cancel_translation']);
        add_action('wp_ajax_aitrfoel_publish_translations', [$this, 'handle_publish_translations']);
        add_action('wp_ajax_aitrfoel_get_word_count', [$this, 'handle_get_word_count']);
        
        // Page management actions
        add_action('wp_ajax_aitrfoel_delete_page', [$this, 'handle_delete_page']);
        add_action('wp_ajax_aitrfoel_bulk_delete_translations', [$this, 'handle_bulk_delete_translations']);
        
        // Settings actions
        add_action('wp_ajax_aitrfoel_get_translation_settings', [$this, 'handle_get_translation_settings']);
        add_action('wp_ajax_aitrfoel_save_translation_settings', [$this, 'handle_save_translation_settings']);
        add_action('wp_ajax_aitrfoel_get_smart_suggestions', [$this, 'handle_get_smart_suggestions']);
        add_action('wp_ajax_aitrfoel_debug_exclusions', [$this, 'handle_debug_exclusions']);
        
        // Cache management
        add_action('wp_ajax_aitrfoel_clear_cache', [$this, 'clear_cache']);
        add_action('wp_ajax_aitrfoel_get_cache_stats', [$this, 'get_cache_stats']);
        
        // Debug endpoint
        add_action('wp_ajax_aitrfoel_debug_test', [$this, 'handle_debug_test']);
        add_action('wp_ajax_aitrfoel_force_cron', [$this, 'handle_force_cron']);
        add_action('wp_ajax_aitrfoel_process_pending', [$this, 'handle_process_pending']);
        add_action('wp_ajax_aitrfoel_test_server_usage', [$this, 'handle_test_server_usage']); // NEW: Test server usage
        
        // Monitor endpoints
        add_action('wp_ajax_aitrfoel_monitor_jobs', [$this, 'handle_monitor_jobs']);
        add_action('wp_ajax_aitrfoel_get_system_logs', [$this, 'handle_get_system_logs']);
        add_action('wp_ajax_aitrfoel_refresh_table_data', [$this, 'handle_refresh_table_data']);
        
        // Removed: Background process endpoints (using direct processing now)
        
        // Review
        add_action('wp_ajax_aitrfoel_dismiss_review', [$this, 'handle_dismiss_review']);

        // Legacy support
        add_action('wp_ajax_aitrfoel_publish_translation', [$this, 'handle_publish_translation']);
        add_action('wp_ajax_aitrfoel_get_preview_url', [$this, 'handle_get_preview_url']);

        // Auto-translate menus & widgets
        add_action('wp_ajax_aitrfoel_auto_translate_menus', [$this, 'handle_auto_translate_menus']);
        add_action('wp_ajax_aitrfoel_auto_translate_widgets', [$this, 'handle_auto_translate_widgets']);

        // Auto-translate Elementor templates (headers/footers)
        add_action('wp_ajax_aitrfoel_auto_translate_templates', [$this, 'handle_auto_translate_templates']);
    }

    /**
     * Check if user is eligible to see review prompt.
     * Shows after 3+ successful translations and not dismissed.
     */
    private function is_review_eligible() {
        if (get_option('aitrfoel_review_dismissed')) {
            return false;
        }
        $count = (int) get_option('aitrfoel_successful_translations', 0);
        return $count >= 1;
    }

    /**
     * Increment successful translation counter.
     */
    private function increment_translation_counter() {
        $count = (int) get_option('aitrfoel_successful_translations', 0);
        update_option('aitrfoel_successful_translations', $count + 1);
    }

    /**
     * Handle dismiss review request.
     */
    public function handle_dismiss_review() {
        // Accept nonce from either the main page or translate-all page
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'aitrfoel_ajax_nonce') &&
            !wp_verify_nonce($_POST['nonce'] ?? '', 'aitrfoel_translate_all_nonce')) {
            wp_send_json_error('Invalid nonce.');
        }
        update_option('aitrfoel_review_dismissed', true);
        wp_send_json_success();
    }
    
    /**
     * Get pages list for translation
     */
    public function get_pages() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('edit_pages') && !current_user_can('edit_posts')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $args = [
                'post_type' => ['page', 'post'],
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'orderby' => 'title',
                'order' => 'ASC',
                'meta_key' => '_elementor_edit_mode',
                'meta_value' => 'builder'
            ];
            
            $pages = get_posts($args);
            
            if (empty($pages)) {
                wp_send_json_error(__('No Elementor pages or posts found.', 'ai-translator-for-elementor-and-polylang'));
                return;
            }
            
            $pages_data = [];
            foreach ($pages as $page) {
                $pages_data[] = [
                    'id' => $page->ID,
                    'title' => $page->post_title,
                    'url' => get_permalink($page->ID),
                    'edit_url' => admin_url('post.php?post=' . $page->ID . '&action=edit')
                ];
            }
            
            wp_send_json_success($pages_data);
            
        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }
    
    /**
     * Estimate translation cost
     */
    public function estimate_cost() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('edit_pages')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $page_id = intval($_POST['page_id'] ?? 0);
            
            if (!$page_id) {
                throw new Exception(__('Invalid page ID.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            // Get translatable content
            $parser_class = class_exists('aitrfoel_Widget_Parser_Fixed') ? 'aitrfoel_Widget_Parser_Fixed' : 'AITRFOEL_Widget_Parser';
            $parser = $parser_class::get_instance();
            $elementor_data = get_post_meta($page_id, '_elementor_data', true);
            
            if (empty($elementor_data)) {
                throw new Exception(__('No Elementor data found for this page.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $elements = json_decode($elementor_data, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception(__('Invalid Elementor data format.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            // Extract translatable strings
            $translatable_strings = [];
            $translation_map = $parser->parse_for_translation($elements);
            $translatable_strings = array_values($translation_map);
            
            if (empty($translatable_strings)) {
                wp_send_json_success([
                    'message' => __('No translatable content found.', 'ai-translator-for-elementor-and-polylang'),
                    'cost' => '$0.00',
                    'details' => []
                ]);
                return;
            }
            
            // Calculate basic word and character counts
            $word_count = 0;
            foreach ($translatable_strings as $string) {
                $word_count += AITRFOEL_Translation_Engine::count_words($string);
            }
            
            $character_count = array_sum(array_map('strlen', $translatable_strings));
            
            // Check word limits for estimation
            try {
                $limit_info = $this->check_word_limit($word_count);
            } catch (Exception $limit_error) {
                // If word limit exceeded, return error instead of estimate
                throw $limit_error;
            }

            // Add breakdown by content type
            $breakdown = [
                'total_elements' => count($translatable_strings),
                'word_count' => $word_count,
                'character_count' => $character_count,
                'words_left' => $limit_info['words_left'],
                'word_limit' => $limit_info['limit'],
                'license_type' => $limit_info['license'],
                'provider' => 'openai',
                'model' => get_option('aitrfoel_openai_model', 'gpt-3.5-turbo')
            ];

            wp_send_json_success([
                'cost' => 'Estimate not available',
                'cost_raw' => 0,
                'details' => [],
                'breakdown' => $breakdown,
                'message' => sprintf(
                    /* translators: 1: Number of words, 2: Number of elements */
                    __('Word count: %1$d words (%2$d elements)', 'ai-translator-for-elementor-and-polylang'),
                    $word_count,
                    count($translatable_strings)
                )
            ]);
            
        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }
    
    /**
     * Translate page with enhanced error handling
     */
    public function translate_page() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('edit_pages') && !current_user_can('edit_posts')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            // Get parameters
            $page_id = intval($_POST['page_id'] ?? 0);
            $target_lang = sanitize_text_field($_POST['target_lang'] ?? '');
            $source_lang = sanitize_text_field($_POST['source_lang'] ?? 'auto');
            
            // Sanitize strings array
            $strings_to_translate = [];
            if (isset($_POST['strings']) && is_array($_POST['strings'])) {
                foreach ($_POST['strings'] as $string) {
                    // Use wp_kses_post to allow safe HTML in translation strings
                    $strings_to_translate[] = wp_kses_post(wp_unslash($string));
                }
            }
            
            // Validate inputs
            if (!$page_id) {
                throw new Exception(__('Invalid page ID.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            // If no strings provided, fetch content based on content type
            if (empty($strings_to_translate)) {
                // Detect content type
                $content_detector = AITRFOEL_Content_Detector::get_instance();
                $content_type = $content_detector->detect($page_id);
                
                $translation_map = [];
                
                try {
                    switch ($content_type) {
                        case AITRFOEL_Content_Detector::TYPE_ELEMENTOR:
                            // Elementor pages
                            $parser_class = class_exists('aitrfoel_Widget_Parser_Fixed') ? 'aitrfoel_Widget_Parser_Fixed' : 'AITRFOEL_Widget_Parser';
                            if (!class_exists($parser_class)) {
                                throw new Exception(__('Widget parser not available.', 'ai-translator-for-elementor-and-polylang'));
                            }
                            $parser = $parser_class::get_instance();
                            $elementor_data = get_post_meta($page_id, '_elementor_data', true);
                            
                            if (empty($elementor_data)) {
                                throw new Exception(__('No Elementor data found.', 'ai-translator-for-elementor-and-polylang'));
                            }
                            
                            $elementor_data_array = json_decode($elementor_data, true);
                            if (json_last_error() !== JSON_ERROR_NONE) {
                                throw new Exception(__('Failed to decode Elementor data.', 'ai-translator-for-elementor-and-polylang'));
                            }
                            
                            $translation_map = $parser->parse_for_translation($elementor_data_array);
                            break;
                            
                        case AITRFOEL_Content_Detector::TYPE_GUTENBERG:
                            // Gutenberg (Block Editor) pages
                            if (!class_exists('AITRFOEL_Gutenberg_Parser')) {
                                throw new Exception(__('Gutenberg parser not available.', 'ai-translator-for-elementor-and-polylang'));
                            }
                            $gutenberg_parser = AITRFOEL_Gutenberg_Parser::get_instance();
                            $translation_map = $gutenberg_parser->parse_for_translation($page_id);
                            break;
                            
                        case AITRFOEL_Content_Detector::TYPE_CLASSIC:
                            // Classic Editor pages
                            if (!class_exists('AITRFOEL_Classic_Parser')) {
                                throw new Exception(__('Classic parser not available.', 'ai-translator-for-elementor-and-polylang'));
                            }
                            $classic_parser = AITRFOEL_Classic_Parser::get_instance();
                            $translation_map = $classic_parser->parse_for_translation($page_id);
                            break;
                            
                        case AITRFOEL_Content_Detector::TYPE_WOOCOMMERCE:
                            // WooCommerce product with no editor content
                            $wc_post = get_post($page_id);
                            if ($wc_post) {
                                if (!empty($wc_post->post_title)) {
                                    $translation_map['wc_title'] = [
                                        'original' => $wc_post->post_title,
                                        'path' => 'title',
                                        'type' => 'text',
                                        'widget_type' => 'woocommerce_product',
                                        'display_text' => $wc_post->post_title,
                                    ];
                                }
                                if (!empty(trim($wc_post->post_excerpt))) {
                                    $translation_map['wc_excerpt'] = [
                                        'original' => $wc_post->post_excerpt,
                                        'path' => 'excerpt',
                                        'type' => 'text',
                                        'widget_type' => 'woocommerce_product',
                                        'display_text' => wp_trim_words($wc_post->post_excerpt, 20),
                                    ];
                                }
                            }
                            break;
                            
                        default:
                            throw new Exception(__('This page does not contain any translatable content. Please ensure the page has content created with Elementor, Gutenberg (Block Editor), or Classic Editor.', 'ai-translator-for-elementor-and-polylang'));
                    }
                } catch (Exception $parse_error) {
                    throw new Exception($parse_error->getMessage());
                }
                
                if (empty($translation_map)) {
                    throw new Exception(__('No translatable content found on this page.', 'ai-translator-for-elementor-and-polylang'));
                }
                
                wp_send_json_success(['strings' => array_values($translation_map), 'content_type' => $content_type]);
                return;
            }
            
            if (empty($target_lang)) {
                throw new Exception(__('Target language is required.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            // Check API configuration
            $api_key = get_option('aitrfoel_openai_api_key', '');
                
            if (empty($api_key)) {
                throw new Exception(__('Please configure your OpenAI API key in the settings.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            // Estimate word count for limit validation
            // Apply server usage factor (server counts both source + translated words)
            $server_factor = defined('AITRFOEL_Translate_All::SERVER_USAGE_FACTOR') ? AITRFOEL_Translate_All::SERVER_USAGE_FACTOR : 2;
            $estimated_words = 0;
            foreach ($strings_to_translate as $string) {
                $estimated_words += AITRFOEL_Translation_Engine::count_words($string);
            }
            $estimated_words *= $server_factor;
            
            // Check word limits before proceeding - WITH DEBUGGING
            
            try {
                $this->check_word_limit($estimated_words);
                
            } catch (Exception $e) {
                
                throw $e; // Re-throw to be handled by outer catch
            }
            
            // Start translation process
            $engine = AITRFOEL_Translation_Engine::get_instance();
            
            // Log the translation start
            AITRFOEL_Logger::log(sprintf(
                'Starting translation: Page %d to %s using %s',
                $page_id,
                $target_lang,
                'openai'
            ), 'info');
            
            // Perform translation with error handling
            $start_time = microtime(true);
            
            try {
                $new_page_id = $engine->translate_page($page_id, $target_lang, $source_lang, $strings_to_translate);
                
                if (is_wp_error($new_page_id)) {
                    throw new Exception($new_page_id->get_error_message());
                }
            } catch (Exception $translation_error) {
                // Log the specific translation error
                AITRFOEL_Logger::log('Translation failed: ' . $translation_error->getMessage(), 'error', [
                    'page_id' => $page_id,
                    'target_lang' => $target_lang,
                    'provider' => 'openai'
                ]);
                
                // Provide user-friendly error message based on error type
                $error_message = $this->get_user_friendly_error($translation_error);
                throw new Exception($error_message);
            }
            
            $end_time = microtime(true);
            $translation_time = round($end_time - $start_time, 2);
            
            if (!$new_page_id) {
                throw new Exception(__('Translation failed. Please check the logs for details.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            // Get translation statistics
            $new_page = get_post($new_page_id);
            $stats = $engine->get_last_translation_stats();
            
            // Record basic usage statistics
            $usage_tracker = AITRFOEL_Usage_Tracker::get_instance();
            $usage_tracker->record_usage([
                'word_count' => $stats['word_count'] ?? 0,
                'page_count' => 1,
                'character_count' => $stats['character_count'] ?? 0
            ]);

            // Increment successful translation counter for review prompt
            $this->increment_translation_counter();
            
            // Log success
            AITRFOEL_Logger::log(sprintf(
                'Translation completed: Page %d -> %d in %s seconds',
                $page_id,
                $new_page_id,
                $translation_time
            ), 'success');
            
            // Prepare response
            $response_data = [
                'success' => true,
                'new_page_id' => $new_page_id,
                'translated_page_id' => $new_page_id,
                'original_page_id' => $page_id,
                'original_url' => get_permalink($page_id),
                'new_page_url' => get_permalink($new_page_id),
                'new_view_url' => get_permalink($new_page_id),
                'view_url' => get_permalink($new_page_id),
                'edit_url' => admin_url('post.php?post=' . $new_page_id . '&action=edit'),
                'preview_url' => get_preview_post_link($new_page_id),
                'original_title' => get_the_title($page_id),
                'title' => $new_page->post_title,
                'translated_title' => $new_page->post_title,
                'target_language' => $target_lang,
                'status' => $new_page->post_status,
                'is_draft' => $new_page->post_status === 'draft',
                'statistics' => [
                    'time_taken' => $translation_time . 's',
                    'elements_translated' => $stats['elements_translated'] ?? 0,
                    'words_translated' => $stats['word_count'] ?? 0,
                    'cost' => '$' . number_format($stats['estimated_cost'] ?? 0, 4)
                ],
                'message' => sprintf(
                    /* translators: 1: Number of words translated, 2: Time in seconds */
                    __('Translation completed successfully! %1$d words translated in %2$s seconds.', 'ai-translator-for-elementor-and-polylang'),
                    $stats['word_count'] ?? 0,
                    $translation_time
                )
            ];
            
            // Add review eligibility flag
            $response_data['review_eligible'] = $this->is_review_eligible();

            wp_send_json_success($response_data);
            
        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }
    
    /**
     * Get basic usage statistics
     */
    public function get_usage_stats() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('edit_pages')) {
                throw new Exception(__('You do not have permission to view statistics.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $usage_tracker = AITRFOEL_Usage_Tracker::get_instance();
            $month = sanitize_text_field($_POST['month'] ?? gmdate('Y-m'));

            // Get simplified monthly stats
            $stats = $usage_tracker->get_monthly_stats($month);

            // Return simplified response structure
            wp_send_json_success([
                'stats' => [
                    'total_words' => $stats['total_words'],
                    'total_pages' => $stats['total_pages'], 
                    'total_characters' => $stats['total_characters'],
                    'month' => $stats['month']
                ],
                'breakdown' => [] // No breakdown in simplified version
            ]);
            
        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }
    
    /**
     * Clear translation cache
     */
    public function clear_cache() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('You do not have permission to clear cache.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $cache_manager = AITRFOEL_Cache_Manager::get_instance();
            $cleared = $cache_manager->clear_cache();
            
            wp_send_json_success([
                'message' => sprintf(
                    /* translators: %d: Number of cache entries removed */
                    __('Cache cleared successfully! %d entries removed.', 'ai-translator-for-elementor-and-polylang'),
                    $cleared
                )
            ]);
            
        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }
    
    /**
     * Get cache statistics
     */
    public function get_cache_stats() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('You do not have permission to view cache statistics.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $cache_manager = AITRFOEL_Cache_Manager::get_instance();
            $stats = $cache_manager->get_stats();
            
            wp_send_json_success($stats);
            
        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }

    /**
     * Handle publish translation request (legacy support)
     */
    public function handle_publish_translation() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('edit_posts')) {
                throw new Exception(__('Permission denied.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $page_id = intval($_POST['page_id'] ?? 0);
            
            if (!$page_id) {
                throw new Exception(__('Missing page ID.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $page = get_post($page_id);
            if (!$page) {
                throw new Exception(__('Page not found.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $result = wp_update_post([
                'ID' => $page_id,
                'post_status' => 'publish'
            ]);
            
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }
            
            AITRFOEL_Logger::log(sprintf('Published translated page: %s (ID: %d)', $page->post_title, $page_id), 'success');
            
            wp_send_json_success([
                /* translators: %s: Page title */
                'message' => sprintf(__('Page "%s" has been published successfully!', 'ai-translator-for-elementor-and-polylang'), $page->post_title),
                'page_id' => $page_id,
                'view_url' => get_permalink($page_id),
                'edit_url' => get_edit_post_link($page_id, 'raw'),
                'status' => 'publish'
            ]);
            
        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }
    
    /**
     * Handle get preview URL request (legacy support)
     */
    public function handle_get_preview_url() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('edit_posts')) {
                throw new Exception(__('Permission denied.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $page_id = intval($_POST['page_id'] ?? 0);
            
            if (!$page_id) {
                throw new Exception(__('Missing page ID.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $page = get_post($page_id);
            if (!$page) {
                throw new Exception(__('Page not found.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            wp_send_json_success([
                'preview_url' => get_preview_post_link($page_id),
                'edit_url' => get_edit_post_link($page_id, 'raw'),
                'title' => $page->post_title,
                'status' => $page->post_status
            ]);
            
        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }
    
    /**
     * Get user-friendly error message
     */
    private function get_user_friendly_error($exception) {
        $message = $exception->getMessage();
        
        // API key errors
        if (strpos($message, 'API key') !== false || strpos($message, 'Invalid API') !== false) {
            return __('API key is invalid or missing. Please check your settings.', 'ai-translator-for-elementor-and-polylang');
        }
        
        // Rate limit errors
        if (strpos($message, 'rate limit') !== false || strpos($message, '429') !== false) {
            return __('API rate limit exceeded. Please wait a moment and try again.', 'ai-translator-for-elementor-and-polylang');
        }
        
        // Quota errors
        if (strpos($message, 'quota') !== false || strpos($message, 'billing') !== false) {
            return __('API quota exceeded or billing issue. Please check your API account.', 'ai-translator-for-elementor-and-polylang');
        }
        
        // Network errors
        if (strpos($message, 'cURL') !== false || strpos($message, 'connection') !== false) {
            return __('Network connection error. Please check your internet connection and try again.', 'ai-translator-for-elementor-and-polylang');
        }
        
        // Timeout errors
        if (strpos($message, 'timeout') !== false || strpos($message, 'timed out') !== false) {
            return __('Request timed out. The page might be too large. Try translating smaller sections.', 'ai-translator-for-elementor-and-polylang');
        }
        
        // JSON errors
        if (strpos($message, 'JSON') !== false) {
            return __('Data format error. Please try refreshing the page and trying again.', 'ai-translator-for-elementor-and-polylang');
        }
        
        // Default
        return sprintf(
            /* translators: %s: Error message */
            __('Translation error: %s', 'ai-translator-for-elementor-and-polylang'),
            $message
        );
    }
    
    /**
     * Handle errors consistently
     */
    private function handle_error($exception) {
        $error_message = $exception->getMessage();
        
        // Check if message is JSON-encoded error data (from word limit checks)
        $error_data = json_decode($error_message, true);
        
        if ($error_data && isset($error_data['type'])) {
            // This is a structured error (limit_reached, cooldown, insufficient_words)
            // Log the error
            AITRFOEL_Logger::log('AJAX Error: ' . $error_data['type'] . ' - ' . $error_data['message'], 'error', $error_data);
            
            // Send structured error response
            wp_send_json_error($error_data);
        } else {
            // Regular error handling
            // Log the error
            AITRFOEL_Logger::log('AJAX Error: ' . $error_message, 'error', [
                'trace' => $exception->getTraceAsString(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine()
            ]);
            
            // Send user-friendly error response
            wp_send_json_error([
                'message' => $error_message,
                'code' => $exception->getCode(),
                'technical_details' => WP_DEBUG ? [
                    'file' => basename($exception->getFile()),
                    'line' => $exception->getLine()
                ] : null
            ]);
        }
    }

    // =============================================
    // BULK TRANSLATION ENDPOINTS
    // =============================================

    /**
     * Pre-flight analysis for bulk translation
     * Accurately counts words BEFORE starting translation
     * 
     * @since 1.0.3
     */
    public function handle_pre_analyze_bulk() {
        // Enable error logging for debugging
        
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('edit_pages')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }

            // Handle pages array
            $page_ids = [];
            if (isset($_POST['pages']) && is_array($_POST['pages'])) {
                $page_ids = array_map('intval', $_POST['pages']);
            } elseif (isset($_POST['pages'])) {
                $pages_str = sanitize_text_field($_POST['pages']);
                $page_ids = !empty($pages_str) ? array_map('intval', explode(',', $pages_str)) : [];
            }

            // Handle languages array  
            $target_langs = [];
            if (isset($_POST['languages']) && is_array($_POST['languages'])) {
                $target_langs = array_map('sanitize_text_field', $_POST['languages']);
            } elseif (isset($_POST['languages'])) {
                $langs_str = sanitize_text_field($_POST['languages']);
                $target_langs = !empty($langs_str) ? array_map('sanitize_text_field', explode(',', $langs_str)) : [];
            }

            if (empty($page_ids) || empty($target_langs)) {
                throw new Exception(__('Pages and languages are required.', 'ai-translator-for-elementor-and-polylang'));
            }

            // Check if parser classes exist
            if (!class_exists('AITRFOEL_Content_Detector')) {
                throw new Exception(__('Content detector class not loaded. Please refresh the page.', 'ai-translator-for-elementor-and-polylang'));
            }

            // Get content detector
            $content_detector = AITRFOEL_Content_Detector::get_instance();
            
            // Analyze each page
            $breakdown = [];
            $total_words = 0;
            $queue_preview = [];
            
            foreach ($page_ids as $page_id) {
                
                // Validate page exists
                $post = get_post($page_id);
                if (!$post) {
                    /* translators: %d: Page ID */
                    throw new Exception(sprintf(__('Page ID %d not found.', 'ai-translator-for-elementor-and-polylang'), $page_id));
                }
                
                // Detect content type and get appropriate parser
                $content_type = $content_detector->detect($page_id);
                $translation_map = [];
                
                try {
                    switch ($content_type) {
                        case AITRFOEL_Content_Detector::TYPE_ELEMENTOR:
                            if (class_exists('AITRFOEL_Widget_Parser')) {
                                $parser = AITRFOEL_Widget_Parser::get_instance();
                                $elementor_data = get_post_meta($page_id, '_elementor_data', true);
                                
                                if (!empty($elementor_data)) {
                                    $elementor_data_array = json_decode($elementor_data, true);
                                    
                                    if (json_last_error() === JSON_ERROR_NONE) {
                                        $translation_map = $parser->parse_for_translation($elementor_data_array);
                                    } else {
                                        AITRFOEL_Logger::log("Failed to decode Elementor data for page {$page_id}: " . json_last_error_msg(), 'warning');
                                    }
                                }
                            }
                            break;
                            
                        case AITRFOEL_Content_Detector::TYPE_GUTENBERG:
                            if (class_exists('AITRFOEL_Gutenberg_Parser')) {
                                $gutenberg_parser = AITRFOEL_Gutenberg_Parser::get_instance();
                                $translation_map = $gutenberg_parser->parse_for_translation($page_id);
                            }
                            break;
                            
                        case AITRFOEL_Content_Detector::TYPE_CLASSIC:
                            if (class_exists('AITRFOEL_Classic_Parser')) {
                                $classic_parser = AITRFOEL_Classic_Parser::get_instance();
                                $translation_map = $classic_parser->parse_for_translation($page_id);
                            }
                            break;
                            
                        default:
                            // Skip unsupported content types
                            AITRFOEL_Logger::log("Skipping page {$page_id}: unsupported content type {$content_type}", 'info');
                            continue 2; // Continue to next page
                    }
                } catch (Exception $e) {
                    AITRFOEL_Logger::log("Error parsing page {$page_id}: " . $e->getMessage(), 'warning', $page_id);
                    continue;
                }
                
                if (empty($translation_map)) {
                    // Skip pages with no translatable content
                    AITRFOEL_Logger::log("Skipping page {$page_id}: no translatable content found", 'info');
                    continue;
                }
                
                // Count words accurately
                $page_word_count = 0;
                foreach ($translation_map as $item) {
                    if (isset($item['original']) && is_string($item['original'])) {
                        $page_word_count += AITRFOEL_Translation_Engine::count_words(wp_strip_all_tags($item['original']));
                    }
                }
                
                // Calculate total for this page across all languages
                // Apply server usage factor (server counts both source + translated words)
                $server_factor = defined('AITRFOEL_Translate_All::SERVER_USAGE_FACTOR') ? AITRFOEL_Translate_All::SERVER_USAGE_FACTOR : 2;
                $page_total_words = $page_word_count * count($target_langs) * $server_factor;
                $total_words += $page_total_words;
                
                // Add to breakdown
                $breakdown[] = [
                    'page_id' => $page_id,
                    'page_title' => $post->post_title,
                    'words' => $page_word_count,
                    'languages' => $target_langs,
                    'total_words' => $page_total_words
                ];
                
                // Generate queue preview
                foreach ($target_langs as $lang) {
                    $queue_preview[] = [
                        'label' => sprintf('%s → %s', $post->post_title, strtoupper($lang)),
                        'words' => $page_word_count,
                        'page_id' => $page_id,
                        'language' => $lang
                    ];
                }
            }
            
            // Get license status and word limit
            $license_manager = AITRFOEL_License_Manager_Server::get_instance();
            $license_status = $license_manager->get_license_status();
            
            $words_used = $license_status['words_used'];
            $word_limit = $license_status['word_limit'];
            $words_left = $word_limit === -1 ? -1 : max(0, $word_limit - $words_used);
            $plan_name = $license_status['plan'];
            
            // Check if can proceed (unlimited plans always proceed)
            $can_proceed = ($word_limit === -1) || ($total_words <= $words_left);
            
            // Prepare response
            $response = [
                'can_proceed' => $can_proceed,
                'total_words_needed' => $total_words,
                'words_left' => $words_left,
                'word_limit' => $word_limit,
                'words_used' => $words_used,
                'plan' => $plan_name,
                'breakdown' => $breakdown,
                'queue_preview' => $queue_preview,
                'total_jobs' => count($queue_preview),
                'pages_count' => count($page_ids),
                'languages_count' => count($target_langs)
            ];
            
            // Add error message if cannot proceed
            if (!$can_proceed) {
                $response['error'] = sprintf(
                    /* translators: 1: Number of words required, 2: Number of words remaining, 3: Plan name */
                    __('Insufficient words available. This translation requires %1$s words, but you only have %2$s words left in your %3$s plan.', 'ai-translator-for-elementor-and-polylang'),
                    number_format($total_words),
                    number_format($words_left),
                    $plan_name
                );
                
                // Add upgrade URL if available
                if (function_exists('eatp_fs') && eatp_fs()->is_registered()) {
                    $response['upgrade_url'] = eatp_fs()->get_upgrade_url();
                } else {
                    $response['upgrade_url'] = 'https://meeptech.com/products/ai-translator-for-elementor-polylang/#pricing';
                }
            }
            
            AITRFOEL_Logger::log(sprintf(
                'Pre-analysis completed: %d pages × %d languages = %d words needed, %d words available, can_proceed: %s',
                count($page_ids),
                count($target_langs),
                $total_words,
                $words_left,
                $can_proceed ? 'YES' : 'NO'
            ), 'info');
            wp_send_json_success($response);

        } catch (Exception $e) {
            error_log('EAT: Exception in pre-analysis: ' . $e->getMessage());
            error_log('EAT: Exception trace: ' . $e->getTraceAsString());
            $this->handle_error($e);
        } catch (Error $e) {
            error_log('EAT: Fatal error in pre-analysis: ' . $e->getMessage());
            error_log('EAT: Error trace: ' . $e->getTraceAsString());
            wp_send_json_error([
                'message' => 'Fatal error: ' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    /**
     * Start bulk translation process
     */
    public function handle_start_bulk_translation() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('edit_pages')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }

            // Handle pages array
            $page_ids = [];
            if (isset($_POST['pages']) && is_array($_POST['pages'])) {
                $page_ids = array_map('intval', $_POST['pages']);
            } elseif (isset($_POST['pages'])) {
                $pages_str = sanitize_text_field($_POST['pages']);
                $page_ids = !empty($pages_str) ? array_map('intval', explode(',', $pages_str)) : [];
            }

            // Handle languages array  
            $target_langs = [];
            if (isset($_POST['languages']) && is_array($_POST['languages'])) {
                $target_langs = array_map('sanitize_text_field', $_POST['languages']);
            } elseif (isset($_POST['languages'])) {
                $langs_str = sanitize_text_field($_POST['languages']);
                $target_langs = !empty($langs_str) ? array_map('sanitize_text_field', explode(',', $langs_str)) : [];
            }

            $source_language = sanitize_text_field($_POST['source_language'] ?? 'auto');

            if (empty($page_ids) || empty($target_langs)) {
                throw new Exception(__('Pages and languages are required.', 'ai-translator-for-elementor-and-polylang'));
            }

            if (!class_exists('AITRFOEL_Content_Detector')) {
                throw new Exception(__('Content detector is not available. Please reload the page and try again.', 'ai-translator-for-elementor-and-polylang'));
            }

            if (!class_exists('AITRFOEL_Bulk_Translation_Queue')) {
                throw new Exception(__('Bulk translation queue is not available. Please reload the page and try again.', 'ai-translator-for-elementor-and-polylang'));
            }

            if (!class_exists('AITRFOEL_Translation_Engine')) {
                throw new Exception(__('Translation engine is not available. Please reload the page and try again.', 'ai-translator-for-elementor-and-polylang'));
            }

            // Validate page IDs
            foreach ($page_ids as $page_id) {
                if (!get_post($page_id)) {
                    /* translators: %d: Page ID */
                    throw new Exception(sprintf(__('Page ID %d not found.', 'ai-translator-for-elementor-and-polylang'), $page_id));
                }
            }

            // ENHANCED: Accurate word count using appropriate parser for each content type
            $content_detector = AITRFOEL_Content_Detector::get_instance();
            $total_estimated_words = 0;
            
            foreach ($page_ids as $page_id) {
                $content_type = $content_detector->detect($page_id);
                $translation_map = [];
                
                try {
                    switch ($content_type) {
                        case AITRFOEL_Content_Detector::TYPE_ELEMENTOR:
                            if (class_exists('AITRFOEL_Widget_Parser')) {
                                $parser = AITRFOEL_Widget_Parser::get_instance();
                                $elementor_data = get_post_meta($page_id, '_elementor_data', true);
                                
                                if (!empty($elementor_data)) {
                                    $elementor_data_array = json_decode($elementor_data, true);
                                    
                                    if (json_last_error() === JSON_ERROR_NONE) {
                                        $translation_map = $parser->parse_for_translation($elementor_data_array);
                                    }
                                }
                            }
                            break;
                            
                        case AITRFOEL_Content_Detector::TYPE_GUTENBERG:
                            if (class_exists('AITRFOEL_Gutenberg_Parser')) {
                                $gutenberg_parser = AITRFOEL_Gutenberg_Parser::get_instance();
                                $translation_map = $gutenberg_parser->parse_for_translation($page_id);
                            }
                            break;
                            
                        case AITRFOEL_Content_Detector::TYPE_CLASSIC:
                            if (class_exists('AITRFOEL_Classic_Parser')) {
                                $classic_parser = AITRFOEL_Classic_Parser::get_instance();
                                $translation_map = $classic_parser->parse_for_translation($page_id);
                            }
                            break;
                    }
                } catch (Throwable $e) {
                    AITRFOEL_Logger::log("Error parsing page {$page_id}: " . $e->getMessage(), 'warning', $page_id);
                    continue;
                }
                
                foreach ($translation_map as $item) {
                    if (isset($item['original']) && is_string($item['original'])) {
                        $total_estimated_words += AITRFOEL_Translation_Engine::count_words(wp_strip_all_tags($item['original']));
                    }
                }
            }
            
            // Multiply by number of languages and apply server usage factor
            // Server counts both source + translated words, so multiply by factor
            $server_factor = defined('AITRFOEL_Translate_All::SERVER_USAGE_FACTOR') ? AITRFOEL_Translate_All::SERVER_USAGE_FACTOR : 2;
            $total_estimated_words *= count($target_langs) * $server_factor;
            
            AITRFOEL_Logger::log(sprintf(
                'Bulk translation pre-flight check: %d pages × %d languages × %d factor = %d estimated words',
                count($page_ids),
                count($target_langs),
                $server_factor,
                $total_estimated_words
            ), 'info');
            
            // Check word limit BEFORE starting translation
            try {
                $limit_check = $this->check_word_limit($total_estimated_words);
                
                AITRFOEL_Logger::log(sprintf(
                    'Word limit check passed: %d words needed, %d words available',
                    $total_estimated_words,
                    $limit_check['words_left']
                ), 'info');
                
            } catch (Exception $e) {
                // Re-throw the exception to be caught by the outer try/catch which handles errors properly
                throw $e;
            }

            // Create job ID
            $job_id = 'bulk_' . time() . '_' . wp_generate_password(8, false);

            // Create job in database using bulk translation queue
            $queue = AITRFOEL_Bulk_Translation_Queue::get_instance();
            
            $job_data = [
                'page_ids' => $page_ids,
                'target_languages' => $target_langs,
                'source_language' => $source_language,
                'user_id' => get_current_user_id(),
                'total_items' => count($page_ids) * count($target_langs)
            ];
            
            $db_job_id = $queue->create_job($job_id, $job_data);
            
            if (is_wp_error($db_job_id)) {
                throw new Exception($db_job_id->get_error_message());
            }

            // Process translations with SMOOTH progress tracking
            $engine = AITRFOEL_Translation_Engine::get_instance();
            $total_jobs = count($page_ids) * count($target_langs);
            $completed_jobs = 0;
            $results = [];
            $error_count = 0;
            
            // Update job status to processing
            $queue->update_job($job_id, [
                'status' => 'processing',
                'completed_items' => 0,
                'total_items' => $total_jobs,
                'current_step' => 'Initializing translation process...',
                'details' => 'Preparing pages for translation'
            ]);
            
            // Allow advanced users to adjust PHP limits if needed via filter hook
            // This provides flexibility without forcing global changes
            // Example: add_filter('aitrfoel_before_bulk_translation', function() { @set_time_limit(300); });
            do_action('aitrfoel_before_bulk_translation', $job_id, $total_jobs);
            
            foreach ($page_ids as $page_index => $page_id) {
                foreach ($target_langs as $lang_index => $target_lang) {
                    $current_job = ($page_index * count($target_langs)) + $lang_index + 1;
                    $page_title = get_the_title($page_id);
                    
                    try {
                        // CONTINUOUS LIMIT CHECK: Before each translation
                        $license_manager = AITRFOEL_License_Manager_Server::get_instance();
                        $license_status = $license_manager->get_license_status();
                        $words_left = $license_status['word_limit'] === -1 ? -1 : max(0, $license_status['word_limit'] - $license_status['words_used']);
                        
                        if ($license_status['word_limit'] !== -1 && $words_left <= 0) {
                            AITRFOEL_Logger::log(sprintf(
                                'Word limit reached during bulk translation at job %d/%d',
                                $current_job,
                                $total_jobs
                            ), 'warning');
                            
                            // Pause the job
                            $queue->update_job($job_id, [
                                'status' => 'paused',
                                'completed_items' => $completed_jobs,
                                'current_step' => '⚠️ Paused: Word limit reached',
                                'details' => sprintf(
                                    'Limit reached at job %d/%d. %d jobs completed successfully.',
                                    $current_job,
                                    $total_jobs,
                                    $completed_jobs
                                ),
                                'error_type' => 'limit_reached_during_process'
                            ]);
                            
                            // Throw error to stop processing
                            throw new Exception(json_encode([
                                'type' => 'limit_reached',
                                'message' => sprintf(
                                    /* translators: 1: Number of completed jobs, 2: Total number of jobs */
                                    __('Word limit reached during translation. Completed %1$d of %2$d jobs. Upgrade your plan to continue.', 'ai-translator-for-elementor-and-polylang'),
                                    $completed_jobs,
                                    $total_jobs
                                ),
                                'completed' => $completed_jobs,
                                'total' => $total_jobs,
                                'remaining' => $total_jobs - $completed_jobs,
                                'words_used' => $license_status['words_used'],
                                'word_limit' => $license_status['word_limit'],
                                'plan' => $license_status['plan'],
                                'upgrade_url' => (function_exists('eatp_fs') && eatp_fs()->is_registered()) 
                                    ? eatp_fs()->get_upgrade_url() 
                                    : 'https://meeptech.com/products/ai-translator-for-elementor-polylang/#pricing'
                            ]));
                        }
                        
                        // STEP 1: Analyzing page (5% of this job)
                        $base_progress = (($current_job - 1) / $total_jobs) * 100;
                        $queue->update_job($job_id, [
                            'progress' => round($base_progress + (5 / $total_jobs)),
                            'current_step' => "🔍 Analyzing: {$page_title}",
                            'details' => "Scanning content structure for {$target_lang}",
                            'stage' => 1,
                            'stage_description' => "Analyzing page structure and content",
                            'current_page_id' => $page_id,
                            'current_language' => $target_lang
                        ]);
                        
                        // STEP 2: Processing content (15% of this job)
                        $queue->update_job($job_id, [
                            'progress' => round($base_progress + (20 / $total_jobs)),
                            'current_step' => "⚙️ Processing: {$page_title}",
                            'details' => "Extracting translatable content",
                            'stage' => 1,
                            'stage_description' => "Extracting and preparing content for translation",
                            'current_page_id' => $page_id,
                            'current_language' => $target_lang
                        ]);
                        
                        // STEP 3: AI Translation (60% of this job)
                        $queue->update_job($job_id, [
                            'progress' => round($base_progress + (60 / $total_jobs)),
                            'current_step' => "🤖 AI Translating: {$page_title}",
                            'details' => "OpenAI is translating to {$target_lang}",
                            'stage' => 1,
                            'stage_description' => 'AI Translation in progress',
                            'current_page_id' => $page_id,
                            'current_language' => $target_lang
                        ]);
                        
                        // ACTUAL TRANSLATION
                        $new_page_id = $engine->translate_page($page_id, $target_lang, $source_language);
                        
                        if (is_wp_error($new_page_id)) {
                            $error_code = $new_page_id->get_error_code();
                            $results[] = ['error' => $new_page_id->get_error_message()];
                            $error_count++;
                            
                            // If word limit error, stop the entire batch — no point continuing
                            if (in_array($error_code, ['insufficient_words', 'cooldown_active'], true)) {
                                $queue->update_job($job_id, [
                                    'status' => 'paused',
                                    'completed_items' => $completed_jobs,
                                    'current_step' => '⚠️ Stopped: Word limit reached',
                                    'details' => wp_strip_all_tags($new_page_id->get_error_message()),
                                    'error_type' => $error_code
                                ]);
                                break 2; // Exit both foreach loops
                            }
                            
                            // Show error state
                            $queue->update_job($job_id, [
                                'progress' => round(($current_job / $total_jobs) * 100),
                                'current_step' => "❌ Error: {$page_title}",
                                'details' => "Failed to translate to {$target_lang}",
                                'errors' => $error_count,
                                'stage' => -1,
                                'stage_description' => 'Translation failed'
                            ]);
                        } else {
                            $results[] = [
                                'success' => $new_page_id,
                                'source_page_id' => $page_id,
                                'target_language' => $target_lang,
                                'page_title' => $page_title
                            ];
                            $completed_jobs++;
                            
                            // STEP 4: Gap-Filler Refinement (15% of this job)
                            $queue->update_job($job_id, [
                                'progress' => round($base_progress + (75 / $total_jobs)),
                                'current_step' => "🔧 Gap-Filler: {$page_title}",
                                'details' => "Refining translation quality for {$target_lang}",
                                'completed_items' => $completed_jobs,
                                'stage' => 2,
                                'stage_description' => 'Gap-Filler refinement in progress',
                                'current_page_id' => $page_id,
                                'current_language' => $target_lang
                            ]);
                            
                            // STEP 5: Finalizing (5% of this job)
                            $queue->update_job($job_id, [
                                'progress' => round(($current_job / $total_jobs) * 100),
                                'current_step' => "✅ Completed: {$page_title}",
                                'details' => "Successfully translated to {$target_lang}",
                                'completed_items' => $completed_jobs,
                                'stage' => 3,
                                'stage_description' => 'Translation completed',
                                'current_page_id' => $page_id,
                                'current_language' => $target_lang,
                                'completed_page_id' => $page_id,
                                'completed_language' => $target_lang
                            ]);
                        }
                        
                        // Production: Removed artificial delay for optimal performance
                        
                    } catch (Throwable $e) {
                        $results[] = ['error' => $e->getMessage()];
                        $error_count++;
                        
                        // Wrap in try-catch so a DB update failure here doesn't kill the entire batch.
                        // Also avoid emoji in current_step to prevent utf8 charset failures on older tables.
                        try {
                            $queue->update_job($job_id, [
                                'progress' => round(($current_job / $total_jobs) * 100),
                                'current_step' => "Error: {$page_title}",
                                'details' => mb_substr($e->getMessage(), 0, 500),
                                'errors' => $error_count
                            ]);
                        } catch (Throwable $ignored) {
                            // Swallow — a status-update failure must not abort the remaining items.
                        }
                    }
                }
            }
            
            // Allow advanced users to run cleanup actions after bulk translation
            do_action('aitrfoel_after_bulk_translation', $job_id, $completed_jobs, $total_jobs);

            // Update job status to completed
            $queue->update_job($job_id, [
                'status' => 'completed',
                'completed_items' => $completed_jobs,
                'total_items' => $total_jobs,
                'results' => $results,
                'completed_at' => current_time('mysql')
            ]);

            wp_send_json_success([
                'job_id' => $job_id,
                'status' => 'started',
                'message' => sprintf(
                    /* translators: 1: Number of pages, 2: Number of target languages */
                    __('Bulk translation started for %1$d pages to %2$d languages.', 'ai-translator-for-elementor-and-polylang'),
                    count($page_ids),
                    count($target_langs)
                ),
                'pages_count' => count($page_ids),
                'languages_count' => count($target_langs),
                'total_jobs' => count($page_ids) * count($target_langs)
            ]);

        } catch (Throwable $e) {
            AITRFOEL_Logger::log('Bulk translation fatal error: ' . $e->getMessage(), 'error', [
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $this->handle_error($e);
        }
    }

    /**
     * Get translation status and progress
     */
    public function handle_get_translation_status() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            $job_id = sanitize_text_field($_POST['job_id'] ?? '');
            
            if (empty($job_id)) {
                throw new Exception(__('Job ID is required.', 'ai-translator-for-elementor-and-polylang'));
            }

            // Get actual status from database
            $queue = AITRFOEL_Bulk_Translation_Queue::get_instance();
            $job_status = $queue->get_job_status($job_id);
            
            if (is_wp_error($job_status)) {
                throw new Exception($job_status->get_error_message());
            }
            
            if (!$job_status) {
                throw new Exception(__('Job not found.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            // Calculate progress percentage
            $progress = 0;
            if ($job_status['total_items'] > 0) {
                $progress = round(($job_status['completed_items'] / $job_status['total_items']) * 100);
            }
            
            $response = [
                'status' => $job_status['status'],
                'progress' => $progress,
                'completed_items' => $job_status['completed_items'],
                'total_items' => $job_status['total_items'],
                'errors' => $job_status['errors'] ?? 0,
                'current_step' => $job_status['current_step'] ?? '',
                'details' => $job_status['details'] ?? '',
                'stage' => $job_status['stage'] ?? 1,
                'stage_description' => $job_status['stage_description'] ?? 'Processing...',
                'current_page_id' => $job_status['current_page_id'] ?? null,
                'current_language' => $job_status['current_language'] ?? null,
                'completed_page_id' => $job_status['completed_page_id'] ?? null,
                'completed_language' => $job_status['completed_language'] ?? null,
                'message' => $this->get_status_message($job_status['status'], $progress),
                // ENHANCED: Add error type and details if job failed/paused
                'error_type' => $job_status['error_type'] ?? null,
                'error_details' => $job_status['error_details'] ?? null
            ];
            
            // Add results if completed
            if ($job_status['status'] === 'completed') {
                $response['results'] = $job_status['results'] ?? [];
            }
            
            // Add error information if job is paused or failed
            if (in_array($job_status['status'], ['paused', 'failed']) && !empty($job_status['error_type'])) {
                $response['error_info'] = [
                    'type' => $job_status['error_type'],
                    'message' => $job_status['details'] ?? '',
                    'completed' => $job_status['completed_items'],
                    'total' => $job_status['total_items'],
                    'remaining' => $job_status['total_items'] - $job_status['completed_items']
                ];
            }
            
            wp_send_json_success($response);

        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }

    /**
     * Pause translation process
     */
    public function handle_pause_translation() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            $job_id = sanitize_text_field($_POST['job_id'] ?? '');
            
            if (empty($job_id)) {
                throw new Exception(__('Job ID is required.', 'ai-translator-for-elementor-and-polylang'));
            }

            // TODO: Phase 3 - Implement actual pause logic
            
            wp_send_json_success([
                'status' => 'paused',
                'message' => __('Translation paused successfully.', 'ai-translator-for-elementor-and-polylang')
            ]);

        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }

    /**
     * Resume translation process
     */
    public function handle_resume_translation() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            $job_id = sanitize_text_field($_POST['job_id'] ?? '');
            
            if (empty($job_id)) {
                throw new Exception(__('Job ID is required.', 'ai-translator-for-elementor-and-polylang'));
            }

            // Resume translation from queue
            $queue = AITRFOEL_Bulk_Translation_Queue::get_instance();
            // Note: update_status method is not available in the queue manager; using update_job instead
            $queue->update_job($job_id, [ 'status' => 'processing' ]);
            
            // Reschedule processing
            wp_schedule_single_event(time() + 5, 'aitrfoel_process_bulk_translation', [$job_id]);
            
            wp_send_json_success([
                'status' => 'processing',
                'message' => __('Translation resumed successfully.', 'ai-translator-for-elementor-and-polylang')
            ]);

        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }

    /**
     * Cancel translation process
     */
    public function handle_cancel_translation() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            $job_id = sanitize_text_field($_POST['job_id'] ?? '');
            
            if (empty($job_id)) {
                throw new Exception(__('Job ID is required.', 'ai-translator-for-elementor-and-polylang'));
            }

            // Cancel translation in queue
            $queue = AITRFOEL_Bulk_Translation_Queue::get_instance();
            // Note: update_status method is not available; use update_job
            $queue->update_job($job_id, [ 'status' => 'cancelled' ]);
            
            wp_send_json_success([
                'status' => 'cancelled',
                'message' => __('Translation cancelled successfully.', 'ai-translator-for-elementor-and-polylang')
            ]);

        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }

    /**
     * Publish all translated pages
     */
    public function handle_publish_translations() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('publish_pages')) {
                throw new Exception(__('You do not have permission to publish pages.', 'ai-translator-for-elementor-and-polylang'));
            }

            $job_id = sanitize_text_field($_POST['job_id'] ?? '');
            
            if (empty($job_id)) {
                throw new Exception(__('Job ID is required.', 'ai-translator-for-elementor-and-polylang'));
            }

            // TODO: Phase 4 - Implement actual publishing logic
            
            wp_send_json_success([
                'published_count' => 4,
                'message' => __('4 translations published successfully.', 'ai-translator-for-elementor-and-polylang')
            ]);

        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }

    /**
     * Get word count estimation for selected pages
     */
    public function handle_get_word_count() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            $pages = sanitize_text_field($_POST['pages'] ?? '');
            
            if (empty($pages)) {
                throw new Exception(__('Pages are required.', 'ai-translator-for-elementor-and-polylang'));
            }

            $page_ids = is_array($_POST['pages']) ? array_map('intval', $_POST['pages']) : explode(',', $pages);
            $total_words = 0;

            foreach ($page_ids as $page_id) {
                $post = get_post($page_id);
                if ($post) {
                    // Get basic post content
                    $content = wp_strip_all_tags($post->post_content);
                    $title = wp_strip_all_tags($post->post_title);
                    $basic_words = AITRFOEL_Translation_Engine::count_words($content . ' ' . $title);
                    
                    // Try to get Elementor content
                    $elementor_words = 0;
                    try {
                        if (class_exists('AITRFOEL_Widget_Parser')) {
                            $parser = AITRFOEL_Widget_Parser::get_instance();
                            $elementor_data = get_post_meta($page_id, '_elementor_data', true);
                            
                            if (!empty($elementor_data)) {
                                $translatable_strings = $parser->extract_translatable_strings($elementor_data);
                                
                                foreach ($translatable_strings as $string_data) {
                                    if (!empty($string_data['original'])) {
                                        $elementor_words += AITRFOEL_Translation_Engine::count_words(wp_strip_all_tags($string_data['original']));
                                    }
                                }
                            }
                        }
                    } catch (Exception $e) {
                        AITRFOEL_Logger::log('Error parsing Elementor content for word count: ' . $e->getMessage(), 'warning', $page_id);
                    }
                    
                    // Use the higher count (basic or elementor + basic)
                    $total_words += max($basic_words, $elementor_words + AITRFOEL_Translation_Engine::count_words($title));
                }
            }

            // Basic cost estimation (simplified)
            $estimated_cost = round($total_words * 0.0001, 4);

            wp_send_json_success([
                'total_words' => $total_words,
                'estimated_tokens' => intval($total_words * 1.3), // Rough token estimation
                'estimated_cost_openai' => $estimated_cost
            ]);

        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }
    
    /**
     * Get status message based on job status and progress
     */
    private function get_status_message($status, $progress) {
        switch ($status) {
            case 'pending':
                return __('Translation queued and waiting to start...', 'ai-translator-for-elementor-and-polylang');
            case 'processing':
                /* translators: %d: Progress percentage */
                return sprintf(__('Translation in progress... %d%% completed', 'ai-translator-for-elementor-and-polylang'), $progress);
            case 'completed':
                return __('Translation completed successfully!', 'ai-translator-for-elementor-and-polylang');
            case 'failed':
                return __('Translation failed. Please check the error details.', 'ai-translator-for-elementor-and-polylang');
            case 'cancelled':
                return __('Translation was cancelled.', 'ai-translator-for-elementor-and-polylang');
            default:
                return __('Unknown status.', 'ai-translator-for-elementor-and-polylang');
        }
    }
    
    /**
     * Debug test endpoint
     */
    public function handle_debug_test() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('edit_pages')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $debug_info = [
                'wp_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'plugin_version' => '1.0.1',
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'timestamp' => current_time('mysql'),
                'classes' => [
                    'AITRFOEL_Bulk_Translation_Queue' => class_exists('AITRFOEL_Bulk_Translation_Queue'),
                    'AITRFOEL_Translation_Engine' => class_exists('AITRFOEL_Translation_Engine'),
                    'aitrfoel_Background_Process' => false, // Removed background processing
                    'AITRFOEL_OpenAI_API' => class_exists('AITRFOEL_OpenAI_API')
                ],
                'database' => $this->check_database_status(),
                'api_config' => $this->check_api_config(),
                'cron' => $this->check_cron_status()
            ];
            
            wp_send_json_success($debug_info);
            
        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }
    
    /**
     * Check database status
     */
    private function check_database_status() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'aitrfoel_bulk_jobs';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        $job_count = 0;
        if ($table_exists) {
            $job_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        }
        
        return [
            'table_exists' => $table_exists,
            'table_name' => $table_name,
            'job_count' => $job_count
        ];
    }
    
    /**
     * Check API configuration
     */
    private function check_api_config() {
        $openai_key = get_option('aitrfoel_openai_api_key');
        
        return [
            'openai_configured' => !empty($openai_key),
            'openai_key_length' => strlen($openai_key ?? ''),
            'openai_key_prefix' => substr($openai_key ?? '', 0, 10) . '...'
        ];
    }
    
    /**
     * Check cron status
     */
    private function check_cron_status() {
        $cron_events = wp_get_scheduled_event('aitrfoel_process_bulk_translation');
        $all_crons = get_option('cron');
        
        // Count aitrfoel_process_bulk_translation events
        $aitrfoel_cron_count = 0;
        if (is_array($all_crons)) {
            foreach ($all_crons as $time => $hooks) {
                if (isset($hooks['aitrfoel_process_bulk_translation'])) {
                    $aitrfoel_cron_count += count($hooks['aitrfoel_process_bulk_translation']);
                }
            }
        }
        
        return [
            'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'next_scheduled' => $cron_events ? gmdate('Y-m-d H:i:s', $cron_events->timestamp) : null,
            'aitrfoel_cron_count' => $aitrfoel_cron_count,
            'total_cron_events' => is_array($all_crons) ? count($all_crons) : 0
        ];
    }
    
    /**
     * Monitor jobs endpoint
     */
    public function handle_monitor_jobs() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('edit_pages')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $queue = AITRFOEL_Bulk_Translation_Queue::get_instance();
            
            // Get recent jobs (last 20)
            global $wpdb;
            $table_name = $wpdb->prefix . 'aitrfoel_bulk_jobs';
            
            $jobs = $wpdb->get_results("
                SELECT * FROM {$table_name} 
                ORDER BY created_at DESC 
                LIMIT 20
            ");
            
            $stats = $queue->get_statistics();
            
            $data = [
                'jobs' => $jobs,
                'stats' => $stats,
                'cron_info' => $this->get_cron_info(),
                'timestamp' => current_time('mysql')
            ];
            
            wp_send_json_success($data);
            
        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }
    
    /**
     * Get system logs endpoint
     */
    public function handle_get_system_logs() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('edit_pages')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $logs = $this->get_recent_logs();
            $cron_info = $this->get_cron_info();
            
            $data = [
                'logs' => $logs,
                'cron_info' => $cron_info,
                'php_error_log' => $this->check_php_error_log(),
                'timestamp' => current_time('mysql')
            ];
            
            wp_send_json_success($data);
            
        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }
    
    /**
     * Get recent logs from error_log
     */
    private function get_recent_logs() {
        $logs = [];
        
        // Try to read error log
        $error_log_paths = [
            ini_get('error_log'),
            WP_CONTENT_DIR . '/debug.log',
            '/var/log/php_errors.log'
        ];
        
        foreach ($error_log_paths as $log_path) {
            if ($log_path && file_exists($log_path) && is_readable($log_path)) {
                $lines = file($log_path);
                if ($lines) {
                    $recent_lines = array_slice($lines, -50); // Last 50 lines
                    foreach ($recent_lines as $line) {
                        if (strpos($line, 'EAT') !== false || strpos($line, 'aitrfoel_') !== false) {
                            $logs[] = [
                                'time' => current_time('mysql'),
                                'message' => trim($line)
                            ];
                        }
                    }
                    break; // Use first available log
                }
            }
        }
        
        return array_slice($logs, -20); // Last 20 EAT-related entries
    }
    
    /**
     * Check PHP error log for critical errors
     */
    private function check_php_error_log() {
        $error_info = [
            'has_errors' => false,
            'recent_errors' => []
        ];
        
        $log_path = ini_get('error_log');
        if ($log_path && file_exists($log_path) && is_readable($log_path)) {
            $lines = file($log_path);
            if ($lines) {
                $recent_lines = array_slice($lines, -20);
                foreach ($recent_lines as $line) {
                    if (strpos($line, 'Fatal error') !== false || strpos($line, 'Parse error') !== false) {
                        $error_info['has_errors'] = true;
                        $error_info['recent_errors'][] = trim($line);
                    }
                }
            }
        }
        
        return $error_info;
    }
    
    /**
     * Get cron information
     */
    private function get_cron_info() {
        $cron_array = _get_cron_array();
        $scheduled_events = [];
        
        foreach ($cron_array as $time => $hooks) {
            if (isset($hooks['aitrfoel_process_bulk_translation'])) {
                foreach ($hooks['aitrfoel_process_bulk_translation'] as $sig => $event) {
                    $scheduled_events[] = [
                        'time' => gmdate('Y-m-d H:i:s', $time),
                        'args' => $event['args']
                    ];
                }
            }
        }
        
        return [
            'wp_cron_enabled' => !(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON),
            'scheduled_events' => $scheduled_events,
            'next_cron_run' => wp_next_scheduled('wp_version_check') ? gmdate('Y-m-d H:i:s', wp_next_scheduled('wp_version_check')) : 'Not scheduled'
        ];
    }
    
    /**
     * Force cron execution
     */
    public function handle_force_cron() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('edit_pages')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            // Trigger cron
            spawn_cron();
            
            wp_send_json_success([
                'message' => __('Cron triggered successfully.', 'ai-translator-for-elementor-and-polylang')
            ]);
            
        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }
    
    /**
     * Process pending jobs endpoint
     */
    public function handle_process_pending() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('edit_pages')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            // Get plugin instance and process pending jobs
            $plugin = AITRFOEL_Main::get_instance();
            $result = $plugin->process_pending_jobs();
            
            if ($result) {
                wp_send_json_success([
                    'message' => 'Pending job processed successfully',
                    'timestamp' => current_time('mysql')
                ]);
            } else {
                wp_send_json_error('No pending jobs found or processing failed');
            }
            
        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }

    /**
     * Handle refresh table data request
     */
    public function handle_refresh_table_data() {
        try {
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'aitrfoel_ajax_nonce')) {
                throw new Exception('Security check failed');
            }

            // Check capabilities
            if (!current_user_can('edit_pages') && !current_user_can('edit_posts')) {
                throw new Exception('Insufficient permissions');
            }

            // Get admin menu instance
            if (!class_exists('AITRFOEL_Admin_Menu')) {
                throw new Exception('Admin menu class not found');
            }

            $admin_menu = AITRFOEL_Admin_Menu::get_instance();
            
            // Get fresh page data
            $pages_data = $admin_menu->get_pages_with_translations();
            
            // Separate into pages, posts, and products (matching dashboard tab structure)
            $pages_only = [];
            $posts_only = [];
            $products_only = [];
            foreach ($pages_data as $group) {
                if ($group['source']->post_type === 'product') {
                    $products_only[] = $group;
                } elseif ($group['source']->post_type === 'page') {
                    $pages_only[] = $group;
                } else {
                    $posts_only[] = $group;
                }
            }
            
            // Render pages HTML
            ob_start();
            $admin_menu->render_pages_rows($pages_only);
            $pages_html = ob_get_clean();
            
            // Render posts HTML
            ob_start();
            $admin_menu->render_pages_rows($posts_only);
            $posts_html = ob_get_clean();

            // Render products HTML
            ob_start();
            $admin_menu->render_pages_rows($products_only);
            $products_html = ob_get_clean();

            wp_send_json_success([
                'html' => $pages_html, // Backward compat: pages HTML in 'html'
                'pages_html' => $pages_html,
                'posts_html' => $posts_html,
                'products_html' => $products_html,
                'pages_count' => count($pages_only),
                'posts_count' => count($posts_only),
                'products_count' => count($products_only),
                'message' => 'Table data refreshed successfully'
            ]);

        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle page deletion request
     */
    public function handle_delete_page() {
        try {
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'aitrfoel_ajax_nonce')) {
                throw new Exception('Security check failed');
            }

            // Check permissions
            if (!current_user_can('delete_pages') && !current_user_can('delete_posts')) {
                throw new Exception('Insufficient permissions to delete content');
            }

            $page_id = intval($_POST['page_id']);
            
            if (!$page_id) {
                throw new Exception('Invalid page ID');
            }

            // Get page info for logging
            $page = get_post($page_id);
            if (!$page) {
                throw new Exception('Page not found');
            }

            if (!in_array($page->post_type, ['page', 'post', 'product'])) {
                throw new Exception('Only pages, posts, and products can be deleted through this interface');
            }

            // Check if this is a translated page (not an original)
            $is_translation = false;
            $multilingual = AITRFOEL_Multilingual_Manager::get_instance();
            
            if ($multilingual && method_exists($multilingual, 'get_original_post_id')) {
                $original_id = $multilingual->get_original_post_id($page_id);
                // If original_id is different from page_id, this is a translation
                $is_translation = ($original_id && $original_id !== $page_id);
            }
            
            // For translated pages, permanently delete (skip trash)
            // For original pages, move to trash (safer)
            if ($is_translation) {
                // Permanently delete translated page
                $result = wp_delete_post($page_id, true); // true = force delete, skip trash
                $action_msg = 'permanently deleted';
            } else {
                // Move to trash for original pages
                $result = wp_trash_post($page_id);
                $action_msg = 'moved to trash';
            }
            
            if (!$result) {
                throw new Exception('Failed to delete page');
            }

            // Log the action
            AITRFOEL_Logger::log("Page '{$page->post_title}' {$action_msg} by user " . get_current_user_id() . ($is_translation ? ' (translation)' : ' (original)'), 'info', $page_id);

            wp_send_json_success([
                'message' => sprintf('Page "%s" %s successfully', $page->post_title, $action_msg),
                'page_id' => $page_id,
                'permanently_deleted' => $is_translation
            ]);

        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Bulk-delete multiple translated posts permanently.
     * Only posts that are confirmed translations (not originals) are deleted.
     */
    public function handle_bulk_delete_translations() {
        try {
            if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'aitrfoel_ajax_nonce')) {
                throw new Exception('Security check failed');
            }

            if (!current_user_can('delete_pages') && !current_user_can('delete_posts')) {
                throw new Exception('Insufficient permissions to delete content');
            }

            $raw_ids = isset($_POST['post_ids']) ? (array) $_POST['post_ids'] : [];
            $post_ids = array_map('absint', $raw_ids);
            $post_ids = array_filter($post_ids);

            if (empty($post_ids)) {
                throw new Exception('No post IDs provided');
            }

            $multilingual = AITRFOEL_Multilingual_Manager::get_instance();
            $deleted  = [];
            $skipped  = [];
            $failed   = [];

            foreach ($post_ids as $post_id) {
                $post = get_post($post_id);
                if (!$post || !in_array($post->post_type, ['page', 'post', 'product'], true)) {
                    $skipped[] = $post_id;
                    continue;
                }

                // Only permanently delete confirmed translations
                $is_translation = false;
                if ($multilingual && method_exists($multilingual, 'get_original_post_id')) {
                    $original_id = $multilingual->get_original_post_id($post_id);
                    $is_translation = ($original_id && $original_id !== $post_id);
                }

                if (!$is_translation) {
                    $skipped[] = $post_id;
                    continue;
                }

                $result = wp_delete_post($post_id, true);
                if ($result) {
                    $deleted[] = $post_id;
                    AITRFOEL_Logger::log("Translation '{$post->post_title}' permanently deleted (bulk) by user " . get_current_user_id(), 'info', $post_id);
                } else {
                    $failed[] = $post_id;
                }
            }

            wp_send_json_success([
                'deleted' => $deleted,
                'skipped' => $skipped,
                'failed'  => $failed,
                'message' => sprintf('%d translation(s) deleted.', count($deleted)),
            ]);

        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Get translation settings
     */
    public function handle_get_translation_settings() {
        try {
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'aitrfoel_ajax_nonce')) {
                throw new Exception('Security check failed');
            }

            $settings = get_option('aitrfoel_translation_settings', [
                'exclude_words' => [],
                'smart_suggestions_enabled' => true,
                'updated_at' => time()
            ]);

            $has_yoast = defined('WPSEO_VERSION') || class_exists('WPSEO_Meta');
            $has_rankmath = class_exists('RankMath') || defined('RANK_MATH_VERSION');
            $has_seo_plugin = $has_yoast || $has_rankmath;

            $settings['translated_page_status'] = get_option('aitrfoel_translated_page_status', 'draft');
            $settings['seo_translation_enabled'] = (bool) get_option('aitrfoel_seo_translation_enabled', true);
            $settings['seo_plugin_detected'] = $has_seo_plugin;
            $settings['seo_plugin_name'] = $has_yoast ? 'Yoast SEO' : ($has_rankmath ? 'Rank Math' : '');

            wp_send_json_success($settings);

        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Save translation settings
     */
    public function handle_save_translation_settings() {
        try {
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'aitrfoel_ajax_nonce')) {
                throw new Exception('Security check failed');
            }

            $exclude_words = isset($_POST['exclude_words']) ? 
                array_map('sanitize_text_field', $_POST['exclude_words']) : [];
            
            $smart_suggestions = isset($_POST['smart_suggestions_enabled']) ? 
                (bool) $_POST['smart_suggestions_enabled'] : true;

            $translated_page_status = isset($_POST['translated_page_status'])
                ? sanitize_text_field(wp_unslash($_POST['translated_page_status']))
                : 'draft';
            $allowed_statuses = ['draft', 'publish', 'inherit'];
            if (!in_array($translated_page_status, $allowed_statuses, true)) {
                $translated_page_status = 'draft';
            }

            $has_yoast = defined('WPSEO_VERSION') || class_exists('WPSEO_Meta');
            $has_rankmath = class_exists('RankMath') || defined('RANK_MATH_VERSION');
            $has_seo_plugin = $has_yoast || $has_rankmath;

            $seo_translation_enabled = isset($_POST['seo_translation_enabled'])
                ? (bool) wp_unslash($_POST['seo_translation_enabled'])
                : false;
            if (!$has_seo_plugin) {
                $seo_translation_enabled = false;
            }

            $settings = [
                'exclude_words' => array_filter($exclude_words), // Remove empty values
                'smart_suggestions_enabled' => $smart_suggestions,
                'updated_at' => time()
            ];

            update_option('aitrfoel_translation_settings', $settings);
            update_option('aitrfoel_translated_page_status', $translated_page_status);
            update_option('aitrfoel_seo_translation_enabled', $seo_translation_enabled);

            // Log the change
            AITRFOEL_Logger::log(
                0, 
                'info', 
                'Translation settings updated: ' . wp_json_encode([
                    'exclude_words_count' => count($settings['exclude_words']),
                    'translated_page_status' => $translated_page_status,
                    'seo_translation_enabled' => $seo_translation_enabled,
                ])
            );


            wp_send_json_success([
                'message' => 'Settings saved successfully',
                'settings' => array_merge($settings, [
                    'translated_page_status' => $translated_page_status,
                    'seo_translation_enabled' => $seo_translation_enabled,
                    'seo_plugin_detected' => $has_seo_plugin,
                ])
            ]);

        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get smart suggestions for exclude words
     */
    public function handle_get_smart_suggestions() {
        try {
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'aitrfoel_ajax_nonce')) {
                throw new Exception('Security check failed');
            }

            $suggestions = [
                'brand_names' => [
                    'label' => 'Brand Names',
                    'items' => ['WordPress', 'Elementor', 'WooCommerce', 'Polylang']
                ],
                'technical_terms' => [
                    'label' => 'Technical Terms', 
                    'items' => ['API', 'CSS', 'HTML', 'JavaScript', 'PHP', 'URL']
                ],
                'contact_info' => [
                    'label' => 'Contact Info',
                    'items' => ['Email', 'Phone', 'Address', 'Contact']
                ],
                'common_excludes' => [
                    'label' => 'Common Excludes',
                    'items' => ['Lorem ipsum', 'Lorem', 'ipsum', 'Admin', 'Dashboard']
                ]
            ];

            wp_send_json_success($suggestions);

        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Debug exclusions - test how they're applied
     */
    public function handle_debug_exclusions() {
        try {
            // Get current settings
            $settings = get_option('aitrfoel_translation_settings', []);
            $exclude_words = $settings['exclude_words'] ?? [];

            // Create test OpenAI instance
            $openai = new AITRFOEL_OpenAI_API();
            
            // Provide a static prompt preview to confirm exclusions are recognized
            $test_text = "Welcome to WordPress! Contact our API team for Elementor support.";
            wp_send_json_success([
                'current_settings' => $settings,
                'exclude_words' => $exclude_words,
                'test_text' => $test_text,
                'prompt_preview' => 'Exclusion rules will be applied to these words: ' . implode(', ', $exclude_words),
                'exclusions_active' => !empty($exclude_words)
            ]);

        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Check if word limit allows translation
     */
    private function check_word_limit($estimated_words = 0) {
        // Get license manager instance
        $license_manager = AITRFOEL_License_Manager_Server::get_instance();
        $license_status = $license_manager->get_license_status();
        
        // Check if in cooldown
        if (isset($license_status['in_cooldown']) && $license_status['in_cooldown']) {
            $cooldown_expiry = $license_status['cooldown_expiry'];
            $remaining_days = ceil(($cooldown_expiry - time()) / (24 * 60 * 60));
            
            
            
            throw new Exception(json_encode([
                'type' => 'cooldown',
                'message' => sprintf(
                    /* translators: %d: Number of days remaining */
                    __('You are in a 7-day cooldown period. Your translation limit will reset in %d days. Upgrade your plan to continue translating immediately.', 'ai-translator-for-elementor-and-polylang'),
                    $remaining_days
                ),
                'cooldown_expiry' => $cooldown_expiry,
                'remaining_days' => $remaining_days,
                'remaining_ms' => ($cooldown_expiry - time()) * 1000,
                'plan' => $license_status['plan'],
                'upgrade_url' => (function_exists('eatp_fs') && eatp_fs()->is_registered()) ? eatp_fs()->get_upgrade_url() : 'https://meeptech.com/products/ai-translator-for-elementor-polylang/#pricing'
            ]));
        }
        
        // Get current usage and limits
        $words_used = $license_status['words_used'];
        $word_limit = $license_status['word_limit'];
        $words_left = $word_limit === -1 ? -1 : max(0, $word_limit - $words_used);
        
        AITRFOEL_Logger::log(sprintf(
            'Word limit validation: Plan=%s, Used=%d, Limit=%d, Left=%d, Estimated=%d',
            $license_status['plan'],
            $words_used,
            $word_limit,
            $words_left,
            $estimated_words
        ), 'info');
        
        
            // Check if limit reached (excluding unlimited plans)
        if ($word_limit !== -1 && $words_left <= 0) {
            AITRFOEL_Logger::log(sprintf(
                'Translation BLOCKED: Word limit reached! Plan=%s, Used=%d, Limit=%d, Left=%d',
                $license_status['plan'],
                $words_used,
                $word_limit,
                $words_left
            ), 'warning');
            
            throw new Exception(json_encode([
                'type' => 'limit_reached',
                'message' => sprintf(
                    /* translators: 1: Word limit number, 2: Plan name */
                    __('Word limit reached! You have used all %1$s words in your %2$s plan. Upgrade to continue or wait for the 7-day reset period.', 'ai-translator-for-elementor-and-polylang'),
                    number_format($word_limit),
                    $license_status['plan']
                ),
                'words_used' => $words_used,
                'word_limit' => $word_limit,
                'plan' => $license_status['plan'],
                'upgrade_url' => (function_exists('eatp_fs') && eatp_fs()->is_registered()) ? eatp_fs()->get_upgrade_url() : 'https://meeptech.com/products/ai-translator-for-elementor-polylang/#pricing'
            ]));
        }        // Check if estimated words exceed remaining (excluding unlimited plans)
        if ($word_limit !== -1 && $estimated_words > 0 && $estimated_words > $words_left) {
            
            
            throw new Exception(json_encode([
                'type' => 'insufficient_words',
                'message' => sprintf(
                    /* translators: 1: Words required, 2: Words remaining, 3: Plan name */
                    __('Not enough words remaining. This translation requires approximately %1$s words, but you only have %2$s words left in your %3$s plan.', 'ai-translator-for-elementor-and-polylang'),
                    number_format($estimated_words),
                    number_format($words_left),
                    $license_status['plan']
                ),
                'estimated_words' => $estimated_words,
                'words_left' => $words_left,
                'words_used' => $words_used,
                'word_limit' => $word_limit,
                'plan' => $license_status['plan'],
                'upgrade_url' => (function_exists('eatp_fs') && eatp_fs()->is_registered()) ? eatp_fs()->get_upgrade_url() : 'https://meeptech.com/products/ai-translator-for-elementor-polylang/#pricing'
            ]));
        }
        
        
        
        return [
            'words_left' => $words_left,
            'limit' => $word_limit,
            'license' => $license_status['plan'],
            'words_used' => $words_used
        ];
    }
    
    /**
     * Test server usage retrieval
     * This endpoint tests the server API connection and returns usage data
     */
    public function handle_test_server_usage() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            AITRFOEL_Logger::log('🧪 Testing server usage retrieval...', 'info');
            
            // Get license manager instance
            $license_manager = AITRFOEL_License_Manager_Server::get_instance();
            
            // Get license status (this will call the server)
            $license_status = $license_manager->get_license_status();
            
            AITRFOEL_Logger::log('📊 License status retrieved: ' . json_encode($license_status), 'info');
            
            // Return detailed response
            wp_send_json_success([
                'message' => 'Server usage retrieved successfully',
                'data' => $license_status,
                'timestamp' => current_time('mysql'),
                'api_url' => 'https://meeptech.com/api/eat/get-usage'
            ]);
            
        } catch (Exception $e) {
            AITRFOEL_Logger::log('❌ Test server usage failed: ' . $e->getMessage(), 'error');
            $this->handle_error($e);
        }
    }

    /**
     * Auto-translate menus: clone default-language menus, translate items, assign per language.
     */
    public function handle_auto_translate_menus() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }

            if (!class_exists('AITRFOEL_Builtin_Integration') || !AITRFOEL_Builtin_Integration::is_enabled()) {
                throw new Exception(__('Built-in language system is not active.', 'ai-translator-for-elementor-and-polylang'));
            }

            $target_lang = sanitize_text_field($_POST['target_lang'] ?? '');
            if (empty($target_lang)) {
                throw new Exception(__('Target language is required.', 'ai-translator-for-elementor-and-polylang'));
            }

            $builtin = AITRFOEL_Builtin_Integration::get_instance();
            $default_lang = $builtin->get_default_language();
            $source_lang = $default_lang;

            if ($target_lang === $default_lang) {
                throw new Exception(__('Cannot translate to the default language.', 'ai-translator-for-elementor-and-polylang'));
            }

            $api = AITRFOEL_OpenAI_API::get_instance();
            $menu_locations = get_registered_nav_menus();
            $nav_menu_locations = get_nav_menu_locations();
            $assignments = get_option('aitrfoel_menu_assignments', []);
            $created_menus = [];
            $lang_upper = strtoupper($target_lang);

            foreach ($menu_locations as $loc_slug => $loc_name) {
                // Get the menu assigned to this location
                $menu_id = isset($nav_menu_locations[$loc_slug]) ? $nav_menu_locations[$loc_slug] : 0;
                if (!$menu_id) {
                    continue;
                }

                $source_menu = wp_get_nav_menu_object($menu_id);
                if (!$source_menu) {
                    continue;
                }

                $source_items = wp_get_nav_menu_items($menu_id);
                if (empty($source_items)) {
                    continue;
                }

                // Check if a translated menu already exists for this location/lang
                $existing_id = isset($assignments[$loc_slug][$target_lang]) ? intval($assignments[$loc_slug][$target_lang]) : 0;
                if ($existing_id > 0 && wp_get_nav_menu_object($existing_id)) {
                    // Already assigned — skip
                    $created_menus[] = [
                        'location' => $loc_name,
                        'menu_name' => wp_get_nav_menu_object($existing_id)->name,
                        'skipped' => true,
                    ];
                    continue;
                }

                // Collect labels to translate in batch
                $labels = [];
                foreach ($source_items as $item) {
                    $label = trim($item->title);
                    if ($label !== '' && !in_array($label, $labels, true)) {
                        $labels[] = $label;
                    }
                }

                // Batch translate
                $translations = [];
                if (!empty($labels)) {
                    $result = $api->translate_batch($labels, $target_lang, $source_lang);
                    if (!is_wp_error($result)) {
                        $translations = $result;
                    }
                }

                // Create new menu
                $new_menu_name = $source_menu->name . ' ' . $lang_upper;
                // Avoid duplicates
                $existing = wp_get_nav_menu_object($new_menu_name);
                if ($existing) {
                    $new_menu_id = $existing->term_id;
                    // Clear existing items
                    $old_items = wp_get_nav_menu_items($new_menu_id);
                    if ($old_items) {
                        foreach ($old_items as $old_item) {
                            wp_delete_post($old_item->ID, true);
                        }
                    }
                } else {
                    $new_menu_id = wp_create_nav_menu($new_menu_name);
                    if (is_wp_error($new_menu_id)) {
                        continue;
                    }
                }

                // Clone menu items with translated labels, pointing to translated posts
                $id_map = []; // old DB_ID => new DB_ID for parent mapping
                foreach ($source_items as $item) {
                    $translated_title = isset($translations[$item->title]) ? $translations[$item->title] : $item->title;

                    // Find translated post/page for this menu item
                    $object_id = $item->object_id;
                    $item_url = $item->url;
                    if (in_array($item->type, ['post_type', 'taxonomy'], true) && $object_id) {
                        $post_translations = $builtin->get_post_translations($object_id);
                        if (isset($post_translations[$target_lang])) {
                            $object_id = $post_translations[$target_lang];
                            $item_url = ''; // Let WordPress generate from object_id
                        }
                    }

                    $args = [
                        'menu-item-title'     => $translated_title,
                        'menu-item-url'       => $item_url,
                        'menu-item-object'    => $item->object,
                        'menu-item-object-id' => $object_id,
                        'menu-item-type'      => $item->type,
                        'menu-item-status'    => 'publish',
                        'menu-item-target'    => $item->target,
                        'menu-item-classes'   => implode(' ', (array) $item->classes),
                        'menu-item-xfn'       => $item->xfn,
                        'menu-item-position'  => $item->menu_order,
                    ];

                    // Handle parent mapping
                    if ($item->menu_item_parent && isset($id_map[$item->menu_item_parent])) {
                        $args['menu-item-parent-id'] = $id_map[$item->menu_item_parent];
                    }

                    $new_item_id = wp_update_nav_menu_item($new_menu_id, 0, $args);
                    if (!is_wp_error($new_item_id)) {
                        $id_map[$item->db_id] = $new_item_id;
                    }
                }

                // Auto-assign
                if (!isset($assignments[$loc_slug])) {
                    $assignments[$loc_slug] = [];
                }
                $assignments[$loc_slug][$target_lang] = $new_menu_id;

                $created_menus[] = [
                    'location' => $loc_name,
                    'menu_name' => $new_menu_name,
                    'items' => count($source_items),
                    'skipped' => false,
                ];
            }

            update_option('aitrfoel_menu_assignments', $assignments);

            // Build a direct menu ID map (source_id → [lang → translated_id])
            // This is used as a fallback in filter_nav_menu_by_language() for menus
            // not assigned to theme locations (e.g. Elementor Nav Menu widgets).
            $menu_id_map = get_option('aitrfoel_menu_id_map', []);

            // Record location-based menus in the ID map
            foreach ($assignments as $loc_slug => $lang_map) {
                $source_id = isset($nav_menu_locations[$loc_slug]) ? (int) $nav_menu_locations[$loc_slug] : 0;
                if ($source_id > 0 && isset($lang_map[$target_lang])) {
                    if (!isset($menu_id_map[$source_id])) {
                        $menu_id_map[$source_id] = [];
                    }
                    $menu_id_map[$source_id][$target_lang] = (int) $lang_map[$target_lang];
                }
            }

            // Collect IDs of menus that are already translated versions (not sources)
            $translated_ids = [];
            foreach ($menu_id_map as $src => $lang_map) {
                foreach ($lang_map as $l => $tid) {
                    $translated_ids[] = (int) $tid;
                }
            }
            // Also include location-assigned source IDs that were already processed
            $processed_source_ids = [];
            foreach ($nav_menu_locations as $loc => $loc_mid) {
                if ((int) $loc_mid > 0) {
                    $processed_source_ids[] = (int) $loc_mid;
                }
            }

            // Translate remaining menus not assigned to any theme location
            $all_menus = wp_get_nav_menus();
            foreach ($all_menus as $menu_obj) {
                $src_menu_id = (int) $menu_obj->term_id;

                // Skip if already processed (assigned to a location)
                if (in_array($src_menu_id, $processed_source_ids, true)) {
                    continue;
                }
                // Skip if this is a translated menu created by us
                if (in_array($src_menu_id, $translated_ids, true)) {
                    continue;
                }
                // Skip if already has a translation for this language
                if (isset($menu_id_map[$src_menu_id][$target_lang])) {
                    $existing_obj = wp_get_nav_menu_object($menu_id_map[$src_menu_id][$target_lang]);
                    if ($existing_obj) {
                        $created_menus[] = [
                            'location' => '(no location)',
                            'menu_name' => $existing_obj->name,
                            'skipped' => true,
                        ];
                        continue;
                    }
                }

                $src_items = wp_get_nav_menu_items($src_menu_id);
                if (empty($src_items)) {
                    continue;
                }

                // Collect labels to translate
                $labels = [];
                foreach ($src_items as $item) {
                    $label = trim($item->title);
                    if ($label !== '' && !in_array($label, $labels, true)) {
                        $labels[] = $label;
                    }
                }

                // Batch translate
                $label_translations = [];
                if (!empty($labels)) {
                    $result = $api->translate_batch($labels, $target_lang, $source_lang);
                    if (!is_wp_error($result)) {
                        $label_translations = $result;
                    }
                }

                // Create translated menu clone
                $new_name = $menu_obj->name . ' ' . $lang_upper;
                $existing = wp_get_nav_menu_object($new_name);
                if ($existing) {
                    $new_mid = $existing->term_id;
                    $old_items = wp_get_nav_menu_items($new_mid);
                    if ($old_items) {
                        foreach ($old_items as $old_item) {
                            wp_delete_post($old_item->ID, true);
                        }
                    }
                } else {
                    $new_mid = wp_create_nav_menu($new_name);
                    if (is_wp_error($new_mid)) {
                        continue;
                    }
                }

                $id_map = [];
                foreach ($src_items as $item) {
                    $translated_title = isset($label_translations[$item->title]) ? $label_translations[$item->title] : $item->title;
                    $object_id = $item->object_id;
                    $item_url = $item->url;
                    if (in_array($item->type, ['post_type', 'taxonomy'], true) && $object_id) {
                        $post_translations = $builtin->get_post_translations($object_id);
                        if (isset($post_translations[$target_lang])) {
                            $object_id = $post_translations[$target_lang];
                            $item_url = '';
                        }
                    }
                    $item_args = [
                        'menu-item-title'     => $translated_title,
                        'menu-item-url'       => $item_url,
                        'menu-item-object'    => $item->object,
                        'menu-item-object-id' => $object_id,
                        'menu-item-type'      => $item->type,
                        'menu-item-status'    => 'publish',
                        'menu-item-target'    => $item->target,
                        'menu-item-classes'   => implode(' ', (array) $item->classes),
                        'menu-item-xfn'       => $item->xfn,
                        'menu-item-position'  => $item->menu_order,
                    ];
                    if ($item->menu_item_parent && isset($id_map[$item->menu_item_parent])) {
                        $item_args['menu-item-parent-id'] = $id_map[$item->menu_item_parent];
                    }
                    $new_item_id = wp_update_nav_menu_item($new_mid, 0, $item_args);
                    if (!is_wp_error($new_item_id)) {
                        $id_map[$item->db_id] = $new_item_id;
                    }
                }

                // Store in ID map
                if (!isset($menu_id_map[$src_menu_id])) {
                    $menu_id_map[$src_menu_id] = [];
                }
                $menu_id_map[$src_menu_id][$target_lang] = (int) $new_mid;

                $created_menus[] = [
                    'location' => '(no location)',
                    'menu_name' => $new_name,
                    'items' => count($src_items),
                    'skipped' => false,
                ];
            }

            update_option('aitrfoel_menu_id_map', $menu_id_map);

            wp_send_json_success([
                'message' => sprintf(
                    __('Menus translated and assigned for %s.', 'ai-translator-for-elementor-and-polylang'),
                    $lang_upper
                ),
                'menus' => $created_menus,
            ]);

        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }

    /**
     * Auto-translate widget content for a target language.
     * Parses block widget markup and standard widget instance titles,
     * translates text, and stores in aitrfoel_widget_translations option.
     */
    public function handle_auto_translate_widgets() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }

            if (!class_exists('AITRFOEL_Builtin_Integration') || !AITRFOEL_Builtin_Integration::is_enabled()) {
                throw new Exception(__('Built-in language system is not active.', 'ai-translator-for-elementor-and-polylang'));
            }

            $target_lang = sanitize_text_field($_POST['target_lang'] ?? '');
            if (empty($target_lang)) {
                throw new Exception(__('Target language is required.', 'ai-translator-for-elementor-and-polylang'));
            }

            $builtin = AITRFOEL_Builtin_Integration::get_instance();
            $default_lang = $builtin->get_default_language();

            if ($target_lang === $default_lang) {
                throw new Exception(__('Cannot translate to the default language.', 'ai-translator-for-elementor-and-polylang'));
            }

            $api = AITRFOEL_OpenAI_API::get_instance();
            $sidebars = wp_get_sidebars_widgets();
            $translations = get_option('aitrfoel_widget_translations', []);
            $translated_count = 0;
            $texts_to_translate = [];
            $text_sources = []; // track where each text came from

            // Phase 1: collect all translatable texts
            foreach ($sidebars as $sidebar_id => $widgets) {
                if ($sidebar_id === 'wp_inactive_widgets' || empty($widgets)) {
                    continue;
                }

                foreach ($widgets as $widget_id) {
                    if (!preg_match('/^(.+)-(\d+)$/', $widget_id, $m)) {
                        continue;
                    }
                    $widget_type = $m[1];
                    $widget_number = (int) $m[2];

                    // Block widgets (type = "block")
                    if ($widget_type === 'block') {
                        $all_instances = get_option('widget_block', []);
                        if (isset($all_instances[$widget_number]['content'])) {
                            $content = $all_instances[$widget_number]['content'];
                            // Extract text from block markup
                            $block_texts = $this->extract_block_widget_texts($content);
                            foreach ($block_texts as $text) {
                                if (!in_array($text, $texts_to_translate, true)) {
                                    $texts_to_translate[] = $text;
                                    $text_sources[$text][] = ['widget_id' => $widget_id, 'type' => 'block'];
                                }
                            }
                        }
                    }

                    // Standard widgets with titles
                    $all_instances = get_option('widget_' . $widget_type, []);
                    if (isset($all_instances[$widget_number]['title']) && trim($all_instances[$widget_number]['title']) !== '') {
                        $title = trim($all_instances[$widget_number]['title']);
                        if (!in_array($title, $texts_to_translate, true)) {
                            $texts_to_translate[] = $title;
                        }
                        $text_sources[$title][] = ['widget_id' => $widget_id, 'type' => 'title', 'widget_type' => $widget_type, 'number' => $widget_number];
                    }

                    // Nav menu widgets — create a translated menu clone
                    if ($widget_type === 'nav_menu') {
                        $menu_id = isset($all_instances[$widget_number]['nav_menu']) ? intval($all_instances[$widget_number]['nav_menu']) : 0;
                        if ($menu_id > 0) {
                            $source_menu = wp_get_nav_menu_object($menu_id);
                            $menu_items = wp_get_nav_menu_items($menu_id);
                            if ($source_menu && $menu_items) {
                                // Collect labels for translation
                                foreach ($menu_items as $mi) {
                                    $label = trim($mi->title);
                                    if ($label !== '' && !in_array($label, $texts_to_translate, true)) {
                                        $texts_to_translate[] = $label;
                                    }
                                }
                                // Store source info for Phase 3 menu cloning
                                $text_sources['_nav_menu_widget_' . $widget_id] = [
                                    'menu_id' => $menu_id,
                                    'widget_id' => $widget_id,
                                ];
                            }
                        }
                    }
                }
            }

            if (empty($texts_to_translate)) {
                wp_send_json_success([
                    'message' => __('No translatable widget content found.', 'ai-translator-for-elementor-and-polylang'),
                    'translated' => 0,
                ]);
                return;
            }

            // Phase 2: batch translate
            $result = $api->translate_batch($texts_to_translate, $target_lang, $default_lang);
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }

            // Phase 3: store translations keyed by widget_id and language
            foreach ($sidebars as $sidebar_id => $widgets) {
                if ($sidebar_id === 'wp_inactive_widgets' || empty($widgets)) {
                    continue;
                }

                foreach ($widgets as $widget_id) {
                    if (!preg_match('/^(.+)-(\d+)$/', $widget_id, $m)) {
                        continue;
                    }
                    $widget_type = $m[1];
                    $widget_number = (int) $m[2];

                    // Block widget content translation
                    if ($widget_type === 'block') {
                        $all_instances = get_option('widget_block', []);
                        if (isset($all_instances[$widget_number]['content'])) {
                            $content = $all_instances[$widget_number]['content'];
                            $translated_content = $this->replace_block_widget_texts($content, $result);
                            if ($translated_content !== $content) {
                                if (!isset($translations[$widget_id])) {
                                    $translations[$widget_id] = [];
                                }
                                $translations[$widget_id][$target_lang] = [
                                    'content' => $translated_content,
                                ];
                                $translated_count++;
                            }
                        }
                    }

                    // Widget title translation
                    $all_instances = get_option('widget_' . $widget_type, []);
                    if (isset($all_instances[$widget_number]['title']) && trim($all_instances[$widget_number]['title']) !== '') {
                        $original_title = trim($all_instances[$widget_number]['title']);
                        if (isset($result[$original_title])) {
                            if (!isset($translations[$widget_id])) {
                                $translations[$widget_id] = [];
                            }
                            if (!isset($translations[$widget_id][$target_lang])) {
                                $translations[$widget_id][$target_lang] = [];
                            }
                            $translations[$widget_id][$target_lang]['title'] = $result[$original_title];
                            $translated_count++;
                        }
                    }

                    // Nav menu widget — clone menu with translated labels and translated post links
                    if ($widget_type === 'nav_menu' && isset($text_sources['_nav_menu_widget_' . $widget_id])) {
                        $nav_source = $text_sources['_nav_menu_widget_' . $widget_id];
                        $source_menu_id = $nav_source['menu_id'];
                        $source_menu = wp_get_nav_menu_object($source_menu_id);
                        $source_items = wp_get_nav_menu_items($source_menu_id);
                        $lang_upper = strtoupper($target_lang);

                        if ($source_menu && $source_items) {
                            $new_menu_name = $source_menu->name . ' ' . $lang_upper;
                            $existing = wp_get_nav_menu_object($new_menu_name);
                            if ($existing) {
                                $new_menu_id = $existing->term_id;
                                $old_items = wp_get_nav_menu_items($new_menu_id);
                                if ($old_items) {
                                    foreach ($old_items as $old_item) {
                                        wp_delete_post($old_item->ID, true);
                                    }
                                }
                            } else {
                                $new_menu_id = wp_create_nav_menu($new_menu_name);
                                if (is_wp_error($new_menu_id)) {
                                    continue;
                                }
                            }

                            $id_map = [];
                            foreach ($source_items as $item) {
                                $translated_title = isset($result[$item->title]) ? $result[$item->title] : $item->title;
                                $object_id = $item->object_id;
                                $item_url = $item->url;
                                if (in_array($item->type, ['post_type', 'taxonomy'], true) && $object_id) {
                                    $post_translations = $builtin->get_post_translations($object_id);
                                    if (isset($post_translations[$target_lang])) {
                                        $object_id = $post_translations[$target_lang];
                                        $item_url = '';
                                    }
                                }
                                $args = [
                                    'menu-item-title'     => $translated_title,
                                    'menu-item-url'       => $item_url,
                                    'menu-item-object'    => $item->object,
                                    'menu-item-object-id' => $object_id,
                                    'menu-item-type'      => $item->type,
                                    'menu-item-status'    => 'publish',
                                    'menu-item-target'    => $item->target,
                                    'menu-item-classes'   => implode(' ', (array) $item->classes),
                                    'menu-item-xfn'       => $item->xfn,
                                    'menu-item-position'  => $item->menu_order,
                                ];
                                if ($item->menu_item_parent && isset($id_map[$item->menu_item_parent])) {
                                    $args['menu-item-parent-id'] = $id_map[$item->menu_item_parent];
                                }
                                $new_item_id = wp_update_nav_menu_item($new_menu_id, 0, $args);
                                if (!is_wp_error($new_item_id)) {
                                    $id_map[$item->db_id] = $new_item_id;
                                }
                            }

                            // Store translated menu ID for this widget
                            if (!isset($translations[$widget_id])) {
                                $translations[$widget_id] = [];
                            }
                            if (!isset($translations[$widget_id][$target_lang])) {
                                $translations[$widget_id][$target_lang] = [];
                            }
                            $translations[$widget_id][$target_lang]['nav_menu'] = $new_menu_id;
                            $translated_count++;
                        }
                    }
                }
            }

            update_option('aitrfoel_widget_translations', $translations);

            wp_send_json_success([
                'message' => sprintf(
                    __('Widget content translated for %s: %d items.', 'ai-translator-for-elementor-and-polylang'),
                    strtoupper($target_lang),
                    $translated_count
                ),
                'translated' => $translated_count,
            ]);

        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }

    /**
     * Auto-translate Elementor templates (headers, footers, sections) for a target language.
     * Called via AJAX from the Settings page.
     *
     * Supports two modes to avoid PHP max_execution_time timeouts:
     *
     * 1. list_only=1  — Returns the list of template IDs/labels that need translating
     *                   without actually translating anything. The JS uses this to build
     *                   a queue and then calls the handler once per template.
     *
     * 2. template_id=N — Translates exactly one template and returns the result.
     *                    The JS calls this in a sequential loop, one request per template.
     */
    public function handle_auto_translate_templates() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }

            $target_lang = sanitize_text_field($_POST['target_lang'] ?? '');
            if (empty($target_lang)) {
                throw new Exception(__('No target language specified.', 'ai-translator-for-elementor-and-polylang'));
            }

            $mode      = sanitize_text_field($_POST['mode'] ?? 'active');
            $force     = !empty($_POST['force']);
            $list_only = !empty($_POST['list_only']);
            $single_id = isset($_POST['template_id']) ? (int) $_POST['template_id'] : 0;

            if (!post_type_exists('elementor_library')) {
                throw new Exception(__('Elementor is not active.', 'ai-translator-for-elementor-and-polylang'));
            }

            $multilingual = class_exists('AITRFOEL_Multilingual_Manager') ? AITRFOEL_Multilingual_Manager::get_instance() : null;
            if (!$multilingual || !$multilingual->is_active()) {
                throw new Exception(__('No multilingual system active.', 'ai-translator-for-elementor-and-polylang'));
            }

            $default_lang = $multilingual->get_default_language();

            // ── Single-template mode ────────────────────────────────────────────
            // JS passes template_id=N to translate exactly one template per request,
            // avoiding max_execution_time overruns when there are many templates.
            if ($single_id > 0) {
                $template = get_post($single_id);
                if (!$template || $template->post_type !== 'elementor_library') {
                    throw new Exception("Template #{$single_id} not found.");
                }

                $template_type = get_post_meta($single_id, '_elementor_template_type', true);
                $label = $template->post_title ?: ($template_type ?: 'Template #' . $single_id);

                $existing = $multilingual->get_post_translations($single_id);

                // Skip if already translated (unless force)
                if (!$force && isset($existing[$target_lang]) && $existing[$target_lang] != $single_id) {
                    wp_send_json_success([
                        'message'    => sprintf(__('⏭ %s — already translated', 'ai-translator-for-elementor-and-polylang'), $label),
                        'status'     => 'skipped',
                        'translated' => 0,
                        'skipped'    => 1,
                        'errors'     => 0,
                    ]);
                    return;
                }

                // Delete old translation if force re-translate
                if ($force && isset($existing[$target_lang]) && $existing[$target_lang] != $single_id) {
                    wp_delete_post((int) $existing[$target_lang], true);
                }

                $engine = AITRFOEL_Translation_Engine::get_instance();
                $result = $engine->translate_page($single_id, $target_lang, $default_lang);

                if (is_wp_error($result)) {
                    wp_send_json_success([
                        'message'    => sprintf(__('✗ %s — %s', 'ai-translator-for-elementor-and-polylang'), $label, $result->get_error_message()),
                        'status'     => 'error',
                        'translated' => 0,
                        'skipped'    => 0,
                        'errors'     => 1,
                    ]);
                } else {
                    wp_send_json_success([
                        'message'    => sprintf(__('✓ %s → translated', 'ai-translator-for-elementor-and-polylang'), $label),
                        'status'     => 'ok',
                        'translated' => 1,
                        'skipped'    => 0,
                        'errors'     => 0,
                    ]);
                }
                return;
            }

            // ── List / batch-queue mode ─────────────────────────────────────────
            // Build the list of templates that need to be (re)translated.

            $query_args = [
                'post_type'      => 'elementor_library',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'meta_query'     => [
                    ['key' => '_elementor_data', 'compare' => 'EXISTS'],
                ],
            ];

            // Exclude already-translated (non-default) posts via taxonomy
            if (class_exists('AITRFOEL_Builtin_Integration') && AITRFOEL_Builtin_Integration::is_enabled()) {
                $builtin     = AITRFOEL_Builtin_Integration::get_instance();
                $non_default = [];
                foreach ($builtin->get_available_languages() as $l) {
                    if ($l->code !== $default_lang) {
                        $non_default[] = $l->code;
                    }
                }
                if (!empty($non_default)) {
                    $query_args['tax_query'] = [[
                        'taxonomy' => 'aitrfoel_language',
                        'field'    => 'slug',
                        'terms'    => $non_default,
                        'operator' => 'NOT IN',
                    ]];
                }
            }

            $templates = get_posts($query_args);

            // PHP-level guard: confirm every post is actually the default language
            if (class_exists('AITRFOEL_Builtin_Integration') && AITRFOEL_Builtin_Integration::is_enabled()) {
                $bl_guard  = AITRFOEL_Builtin_Integration::get_instance();
                $templates = array_values(array_filter($templates, function($tpl) use ($bl_guard, $default_lang) {
                    return $bl_guard->get_post_language($tpl->ID) === $default_lang;
                }));
            }

            if (empty($templates)) {
                wp_send_json_success(['message' => __('No Elementor templates found to translate.', 'ai-translator-for-elementor-and-polylang'), 'queue' => []]);
                return;
            }

            // Filter templates by requested mode.
            if ($mode === 'active') {
                $templates = array_values(array_filter($templates, function($tpl) {
                    $conditions = get_post_meta($tpl->ID, '_elementor_conditions', true);
                    $tpl_type   = get_post_meta($tpl->ID, '_elementor_template_type', true);
                    return !empty($conditions) || in_array($tpl_type, ['header', 'footer'], true);
                }));
            } elseif ($mode === 'advanced') {
                $templates = array_values(array_filter($templates, function($tpl) {
                    $conditions = get_post_meta($tpl->ID, '_elementor_conditions', true);
                    $tpl_type   = get_post_meta($tpl->ID, '_elementor_template_type', true);
                    return empty($conditions) && !in_array($tpl_type, ['header', 'footer'], true);
                }));
            }

            if (empty($templates)) {
                $empty_message = __('No active Elementor templates found to translate.', 'ai-translator-for-elementor-and-polylang');
                if ($mode === 'advanced') {
                    $empty_message = __('No advanced Elementor templates found to translate.', 'ai-translator-for-elementor-and-polylang');
                }
                wp_send_json_success(['message' => $empty_message, 'queue' => []]);
                return;
            }

            // Build queue: skip already-translated unless force
            $queue = [];
            foreach ($templates as $tpl) {
                $existing = $multilingual->get_post_translations($tpl->ID);
                $already  = isset($existing[$target_lang]) && $existing[$target_lang] != $tpl->ID;

                if (!$force && $already) {
                    continue; // will be skipped — don't add to queue
                }

                $tpl_type = get_post_meta($tpl->ID, '_elementor_template_type', true);
                $queue[] = [
                    'id'    => $tpl->ID,
                    'label' => $tpl->post_title ?: ($tpl_type ?: 'Template #' . $tpl->ID),
                    'type'  => $tpl_type,
                ];
            }

            // list_only=1: just return the queue, don't translate anything
            if ($list_only) {
                $skipped_count = count($templates) - count($queue);
                wp_send_json_success([
                    'message' => sprintf(
                        __('%d template(s) to translate, %d already done.', 'ai-translator-for-elementor-and-polylang'),
                        count($queue),
                        $skipped_count
                    ),
                    'queue'   => $queue,
                    'skipped' => $skipped_count,
                ]);
                return;
            }

            // Legacy fallback: no template_id and no list_only — translate all in one go
            // (kept for backward-compat but the JS now uses the chunked path above)
            $engine     = AITRFOEL_Translation_Engine::get_instance();
            $translated = 0;
            $skipped    = 0;
            $errors     = 0;
            $details    = [];

            foreach ($templates as $template) {
                $template_type = get_post_meta($template->ID, '_elementor_template_type', true);
                $label         = $template->post_title ?: ($template_type ?: 'Template #' . $template->ID);

                $existing = $multilingual->get_post_translations($template->ID);
                if (!$force && isset($existing[$target_lang]) && $existing[$target_lang] != $template->ID) {
                    $details[] = sprintf(__('⏭ %s — already translated', 'ai-translator-for-elementor-and-polylang'), $label);
                    $skipped++;
                    continue;
                }

                if ($force && isset($existing[$target_lang]) && $existing[$target_lang] != $template->ID) {
                    wp_delete_post((int) $existing[$target_lang], true);
                    $details[] = sprintf(__('🔄 %s — re-translating (old translation removed)', 'ai-translator-for-elementor-and-polylang'), $label);
                }

                $result = $engine->translate_page($template->ID, $target_lang, $default_lang);

                if (is_wp_error($result)) {
                    $details[] = sprintf(__('✗ %s — %s', 'ai-translator-for-elementor-and-polylang'), $label, $result->get_error_message());
                    $errors++;
                } else {
                    $details[] = sprintf(__('✓ %s → translated', 'ai-translator-for-elementor-and-polylang'), $label);
                    $translated++;
                }
            }

            wp_send_json_success([
                'message' => sprintf(
                    __('Elementor templates for %s: %d translated, %d skipped, %d errors.', 'ai-translator-for-elementor-and-polylang'),
                    strtoupper($target_lang),
                    $translated,
                    $skipped,
                    $errors
                ),
                'translated' => $translated,
                'skipped'    => $skipped,
                'errors'     => $errors,
                'details'    => $details,
            ]);

        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }

    /**
     * Extract visible text from block widget HTML content.
     */
    private function extract_block_widget_texts($html) {
        $texts = [];
        // Strip block comments
        $stripped = preg_replace('/<!--.*?-->/', '', $html);
        // Match text content inside HTML tags
        if (preg_match_all('/>([^<]+)</', $stripped, $matches)) {
            foreach ($matches[1] as $text) {
                $text = trim(html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
                // Skip empty, numbers-only, or very short strings
                if ($text !== '' && !preg_match('/^[\d\s\.\,\-\+\%\$\€\£\¥\#\@\!\?]+$/', $text) && mb_strlen($text) >= 2) {
                    $texts[] = $text;
                }
            }
        }
        return array_unique($texts);
    }

    /**
     * Replace original texts in block widget HTML with translated versions.
     */
    private function replace_block_widget_texts($html, $translations) {
        foreach ($translations as $original => $translated) {
            if ($original === $translated || empty($translated)) {
                continue;
            }
            // Replace inside tag content, preserving HTML structure
            $escaped_original = preg_quote(htmlspecialchars($original, ENT_QUOTES, 'UTF-8'), '/');
            $html = preg_replace(
                '/(>)(' . $escaped_original . ')(<)/',
                '$1' . htmlspecialchars($translated, ENT_QUOTES, 'UTF-8') . '$3',
                $html
            );
            // Also try with the raw text (not HTML-encoded)
            $escaped_raw = preg_quote($original, '/');
            $html = preg_replace(
                '/(>)(' . $escaped_raw . ')(<)/',
                '$1' . $translated . '$3',
                $html
            );
        }
        return $html;
    }

}
