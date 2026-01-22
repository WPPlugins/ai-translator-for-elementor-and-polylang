<?php
/**
 * Translation Engine Class
 * Core translation logic with batch processing and error handling
 */

if (!defined('ABSPATH')) exit;

class AITRFOEL_Translation_Engine {
    private static $instance = null;
    
    /**
     * Batch size for API calls
     */
    private $batch_size = 5;
    
    /**
     * Rate limit delay (milliseconds) - Reduced from 500ms to 100ms for better performance
     */
    private $rate_limit_delay = 100;
    
    /**
     * Last translation statistics
     */
    private $last_translation_stats = [];
    
    /**
     * HTML placeholders storage - maps original text hash to placeholders
     */
    private $html_placeholders_storage = [];

    /**
     * Whether cache usage is enabled via settings
     */
    private $cache_enabled = true;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->batch_size = get_option('aitrfoel_batch_size', 5);
        $this->cache_enabled = (bool) get_option('aitrfoel_cache_enabled', true);
    }

    /**
     * Main translation function
     * Supports Elementor, Gutenberg, and Classic Editor content
     * 
     * @param int $page_id Original page ID
     * @param string $target_lang Target language
     * @param string $source_lang Source language
     * @param array $strings_to_translate Selected strings for translation (optional)
     * @return int|WP_Error New page ID or error
     */
    public function translate_page($page_id, $target_lang, $source_lang = 'auto', $strings_to_translate = []) {
        // Track translation start time for analytics
        $translation_start_time = microtime(true);
        
        // Detect content type
        $content_detector = AITRFOEL_Content_Detector::get_instance();
        $content_type = $content_detector->detect($page_id);
        
        AITRFOEL_Logger::log(
            sprintf('Content type detected: %s for page %d', $content_type, $page_id),
            'info',
            $page_id
        );
        
        // Route to appropriate translation method based on content type
        switch ($content_type) {
            case AITRFOEL_Content_Detector::TYPE_ELEMENTOR:
                return $this->translate_elementor_page($page_id, $target_lang, $source_lang, $strings_to_translate, $translation_start_time);
                
            case AITRFOEL_Content_Detector::TYPE_GUTENBERG:
                return $this->translate_gutenberg_page($page_id, $target_lang, $source_lang, $translation_start_time);
                
            case AITRFOEL_Content_Detector::TYPE_CLASSIC:
                return $this->translate_classic_page($page_id, $target_lang, $source_lang, $translation_start_time);
                
            default:
                $this->track_translation_failure('unsupported_content', 'Unsupported content type', $translation_start_time);
                return new WP_Error(
                    'unsupported_content',
                    __('This page does not contain any translatable content. Please ensure the page has content created with Elementor, Gutenberg (Block Editor), or Classic Editor.', 'ai-translator-for-elementor-and-polylang')
                );
        }
    }
    
    /**
     * Translate Elementor page (original functionality preserved)
     * 
     * @param int $page_id Original page ID
     * @param string $target_lang Target language
     * @param string $source_lang Source language
     * @param array $strings_to_translate Selected strings for translation (optional)
     * @param float $translation_start_time Start time for analytics
     * @return int|WP_Error New page ID or error
     */
    private function translate_elementor_page($page_id, $target_lang, $source_lang = 'auto', $strings_to_translate = [], $translation_start_time = null) {
        if (!$translation_start_time) {
            $translation_start_time = microtime(true);
        }
        
        // Get Elementor data
        $elementor_data = get_post_meta($page_id, '_elementor_data', true);
        
        if (empty($elementor_data)) {
            $this->track_translation_failure('no_elementor_data', 'No Elementor data found', $translation_start_time);
            return new WP_Error('no_elementor_data', __('No Elementor data found for this page.', 'ai-translator-for-elementor-and-polylang'));
        }
        
        $elementor_data_array = json_decode($elementor_data, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->track_translation_failure('json_decode_error', 'Failed to decode Elementor data', $translation_start_time);
            return new WP_Error('json_decode_error', __('Failed to decode Elementor data.', 'ai-translator-for-elementor-and-polylang'));
        }
        
        // If no specific strings provided, parse all translatable content
        if (empty($strings_to_translate)) {
            $parser = AITRFOEL_Widget_Parser::get_instance();
            $translation_map = $parser->parse_for_translation($elementor_data_array);
            $strings_to_translate = array_values($translation_map);
        }
        
        // ==========================================
        // WORD LIMIT & COOLDOWN ENFORCEMENT
        // ==========================================
        
        // Get current license status and usage
        $license_manager = AITRFOEL_License_Manager_Server::get_instance();
        $license_status = $license_manager->get_license_status();
        
        $plan = $license_status['plan'];
        $word_limit = $license_status['word_limit'];
        $words_used = $license_status['words_used'];
        $words_left = $license_status['words_left'];
        
        // Calculate estimated word count for this translation
        $estimated_words = 0;
        foreach ($strings_to_translate as $string_data) {
            if (isset($string_data['original'])) {
                $estimated_words += str_word_count(wp_strip_all_tags($string_data['original']));
            }
        }
        
        AITRFOEL_Logger::log(
            sprintf('Word limit check: Plan=%s, Limit=%d, Used=%d, Left=%d, Estimated=%d', 
                $plan, $word_limit, $words_used, $words_left, $estimated_words),
            'info',
            $page_id
        );
        
        // Check if user has enough words remaining
        if ($word_limit !== -1 && $estimated_words > $words_left) {
            // Check if user is on cooldown (Free plan only)
            if (strtolower($plan) === 'free') {
                $cooldown_end = get_option('aitrfoel_cooldown_end_date', 0);
                
                if ($cooldown_end > time()) {
                    // Still in cooldown period
                    $days_left = ceil(($cooldown_end - time()) / DAY_IN_SECONDS);
                    
                    AITRFOEL_Logger::log(
                        sprintf('Translation blocked: Free plan in cooldown for %d more days', $days_left),
                        'warning',
                        $page_id
                    );
                    
                    return new WP_Error(
                        'cooldown_active',
                        sprintf(
                            /* translators: 1: Word limit (formatted number), 2: Number of days remaining */
                            __('You have reached your monthly limit of %1$s words. Free plan users must wait %2$d more days for the limit to reset. Upgrade to a paid plan for immediate access.', 'ai-translator-for-elementor-and-polylang'),
                            number_format($word_limit),
                            $days_left
                        )
                    );
                }
            }
            
            // Not enough words for this translation
            AITRFOEL_Logger::log(
                sprintf('Translation blocked: Insufficient words (need %d, have %d)', $estimated_words, $words_left),
                'warning',
                $page_id
            );
            
            $upgrade_link = admin_url('admin.php?page=ai-translator-for-elementor-and-polylang-pricing');
            
            return new WP_Error(
                'insufficient_words',
                sprintf(
                    /* translators: 1: Estimated words needed (formatted number), 2: Words remaining (formatted number), 3: Upgrade URL */
                    __('This translation requires approximately %1$s words, but you only have %2$s words remaining in your plan. Please <a href="%3$s">upgrade your plan</a> or wait for your monthly limit to reset.', 'ai-translator-for-elementor-and-polylang'),
                    number_format($estimated_words),
                    number_format($words_left),
                    esc_url($upgrade_link)
                )
            );
        }
        
        // ==========================================
        // END WORD LIMIT CHECK
        // ==========================================
        // Language detection if auto
        AITRFOEL_Logger::log("Source language parameter: '$source_lang'", 'debug', $page_id);
        $detected_lang = $source_lang;
        if ($source_lang === 'auto') {
            AITRFOEL_Logger::log('Starting language auto-detection', 'debug', $page_id);
            
            // Log sample of text for debugging
            if (!empty($strings_to_translate)) {
                $sample_text = isset($strings_to_translate[0]['original']) ? $strings_to_translate[0]['original'] : '';
                $sample_display = mb_substr($sample_text, 0, 100) . '...';
                AITRFOEL_Logger::log("Sample text for detection: '$sample_display'", 'debug', $page_id);
            }
            
            $detected_lang = $this->detect_source_language($strings_to_translate, $page_id);
            if (!$detected_lang) {
                AITRFOEL_Logger::log('Auto-detection failed, using fallback: en', 'warning', $page_id);
                $detected_lang = 'en'; // sensible fallback
            } else {
                AITRFOEL_Logger::log("Language detected: '$detected_lang'", 'info', $page_id);
            }
    }
        
        // Enhanced logging for debugging
        AITRFOEL_Logger::log(
            sprintf('Translation summary: Page %d, %s → %s, %d strings to translate', 
                $page_id, $detected_lang, $target_lang, count($strings_to_translate)),
            'info',
            $page_id
        );
        
        // Log translation start
        AITRFOEL_Logger::log(
            sprintf('Starting translation of page %d to %s', $page_id, $target_lang),
            'info',
            $page_id
        );
        
        // Initialize services based on selected provider (OpenAI only)
        $api_provider = 'openai'; // Simplified to OpenAI only
        AITRFOEL_Logger::log("Using API provider: '$api_provider'", 'debug', $page_id);
        
        $api = AITRFOEL_OpenAI_API::get_instance();
        if (method_exists($api, 'reset_usage_session')) {
            $api->reset_usage_session();
        }
        
        $cache = AITRFOEL_Cache_Manager::get_instance();
        $parser = AITRFOEL_Widget_Parser::get_instance();
        
        // Process translations in batches
        $translations = [];
        $total_strings = count($strings_to_translate);
        $processed = 0;
        
        // Group strings by cache status - uncached strings get batched
        $cached_translations = [];
        $uncached_strings = [];
        $uncached_paths = [];
        
        $uncached_items = [];
        foreach ($strings_to_translate as $string_data) {
            // Validate string data structure
            if (!is_array($string_data) || !isset($string_data['original'])) {
                AITRFOEL_Logger::log('Invalid string data structure, skipping', 'warning', $page_id);
                continue;
            }
            
            $raw_original = (string)$string_data['original'];
            $path = $string_data['path'] ?? '';

            // Extract inline placeholders using parser helper
            $ph_map = [];
            $prepared_text = $raw_original;
            if (method_exists($parser, 'i18n_extract_with_placeholders')) {
                list($prepared_text, $ph_map) = $parser->i18n_extract_with_placeholders($raw_original);
                // Diagnostics: log placeholder count per string
                if (is_array($ph_map) && !empty($ph_map)) {
                    AITRFOEL_Logger::log(sprintf('Placeholders extracted: %d for path %s', count($ph_map), (string)($path ?? 'n/a')), 'debug', $page_id);
                }
            }
            
            // Normalize to NFC if possible
            if (class_exists('Normalizer') && is_string($prepared_text)) {
                $prepared_text = Normalizer::normalize($prepared_text, Normalizer::FORM_C);
            }
            
            // Skip empty or invalid text
            if (empty(trim((string)$prepared_text))) {
                continue;
            }
            
            // Check cache first (only if enabled)
            $cached = null;
            if ($this->cache_enabled) {
                $cached = $cache->get_from_cache($prepared_text, $target_lang, $detected_lang);
            }
                if ($cached) {
                // Restore placeholders before applying
                if (!empty($ph_map) && method_exists($parser, 'i18n_restore_placeholders')) {
                    $restored = $parser->i18n_restore_placeholders($cached, $ph_map);
                    if (method_exists($parser, 'i18n_cleanup_artifacts')) {
                        $restored = $parser->i18n_cleanup_artifacts($restored);
                    }
                    // Try to restore lost links
                    if (method_exists($parser, 'restore_lost_links')) {
                        $restored = $parser->restore_lost_links($restored, $raw_original);
                    }
                    $cached_translations[$path] = $restored;
                } else {
                    $tmp = $this->restore_html_formatting($cached, $raw_original);
                    if (method_exists($parser, 'i18n_cleanup_artifacts')) {
                        $tmp = $parser->i18n_cleanup_artifacts($tmp);
                    }
                    // Try to restore lost links
                    if (method_exists($parser, 'restore_lost_links')) {
                        $tmp = $parser->restore_lost_links($tmp, $raw_original);
                    }
                    $cached_translations[$path] = $tmp;
                }
                $processed++;
            } else {
                // Queue item for batch translation
                $uncached_items[] = [
                    'src' => $prepared_text,
                    'path' => $path,
                    'original' => $raw_original,
                    'ph_map' => $ph_map,
                ];
            }
        }
        
        // Process uncached strings in batches
        if (!empty($uncached_items)) {
            // OPTIMIZED: Process all strings together for maximum batch efficiency
            // No grouping by widget - creates larger batches = faster translation
            $batches = array_chunk($uncached_items, $this->batch_size);
            
            foreach ($batches as $batch_index => $batch_items) {
                // Group key is now just "batch" since we're not grouping by widget
                $gkey = 'batch';
                    $batch_texts = array_map(function($it){ return $it['src']; }, $batch_items);
                    $start = microtime(true);
                    AITRFOEL_Logger::log(sprintf('[EAT] page=%d lang=%s group="%s" batch=%d size=%d', $page_id, $target_lang, (string)$gkey, $batch_index, count($batch_texts)), 'info', $page_id);
                    // Use batch translation API (expects placeholderized texts)
                    $batch_results = $api->translate_batch($batch_texts, $target_lang, $detected_lang);
                    $elapsed_ms = (int)round((microtime(true) - $start) * 1000);
                    if (!is_wp_error($batch_results)) {
                        AITRFOEL_Logger::log(sprintf('[EAT] page=%d lang=%s group="%s" batch=%d results=%d time_ms=%d', $page_id, $target_lang, (string)$gkey, $batch_index, count((array)$batch_results), $elapsed_ms), 'info', $page_id);
                    }
                
                if (is_wp_error($batch_results)) {
                    AITRFOEL_Logger::log(
                        'Batch translation failed, falling back to individual: ' . $batch_results->get_error_message(),
                        'warning',
                        $page_id
                    );
                    
                    // Fallback to individual translation
                    foreach ($batch_items as $item) {
                        $text = $item['src'];
                        $translated = $api->translate_text($text, $target_lang, $detected_lang);
                        
                        // CRITICAL DEBUG: Log what OpenAI returns for individual translations
                        AITRFOEL_Logger::log("Individual Translation Debug - Original text (first 200 chars): " . substr($text, 0, 200), 'debug');
                        AITRFOEL_Logger::log("Individual Translation Debug - Translated text (first 200 chars): " . substr(is_wp_error($translated) ? 'ERROR' : $translated, 0, 200), 'debug');
                        AITRFOEL_Logger::log("Individual Translation Debug - Original contains placeholders: " . (strpos($text, '≪') !== false ? 'YES' : 'NO'), 'debug');
                        AITRFOEL_Logger::log("Individual Translation Debug - Translated contains placeholders: " . (!is_wp_error($translated) && strpos($translated, '≪') !== false ? 'YES' : 'NO'), 'debug');
                        
                        if (!is_wp_error($translated)) {
                            $path = $item['path'];
                            $original = $item['original'];
                            $ph_map = $item['ph_map'];
                            
                            // Cache the translation (only if enabled)
                            if ($this->cache_enabled) {
                                $cache->set_to_cache(
                                    $text, // cache keyed by placeholderized source
                                    $translated, // store raw model output
                                    $target_lang,
                                    $detected_lang,
                                    get_option('aitrfoel_openai_model', 'gpt-3.5-turbo')
                                );
                            }
                            
                            // Residue retry: check for leftover phrases
                            $needs_retry = false;
                            foreach (['Read more','Číst dál','Читать далее'] as $marker) {
                                if (is_string($translated) && mb_stripos($translated, $marker, 0, 'UTF-8') !== false) {
                                    $needs_retry = true; break;
                                }
                            }
                            if ($needs_retry) {
                                $retry = $api->translate_text($text, $target_lang, $detected_lang);
                                if (!is_wp_error($retry) && is_string($retry) && $retry !== '') {
                                    $translated = $retry;
                                }
                            }
                            // Restore placeholders
                            if (!empty($ph_map) && method_exists($parser, 'i18n_restore_placeholders')) {
                                $final = $parser->i18n_restore_placeholders($translated, $ph_map);
                            } else {
                                $final = $this->restore_html_formatting($translated, $original);
                            }
                            if (method_exists($parser, 'i18n_cleanup_artifacts')) {
                                $final = $parser->i18n_cleanup_artifacts($final);
                            }
                            // Try to restore lost links
                            if (method_exists($parser, 'restore_lost_links')) {
                                $final = $parser->restore_lost_links($final, $original);
                            }
                            $translations[$path] = $final;
                        } else {
                            AITRFOEL_Logger::log(
                                'Individual translation failed for text: ' . substr($text, 0, 50) . '...',
                                'error',
                                $page_id
                            );
                        }
                        
                        $processed++;
                        usleep($this->rate_limit_delay * 1000); // Rate limiting
                    }
                } else {
                    // Process successful batch results
                    // NOTE: batch_results is keyed by SOURCE TEXT, not numeric index
                    foreach ($batch_items as $index => $item) {
                        $text = $item['src'];
                        // Check if translation exists by source text key OR numeric index (for compatibility)
                        $translated = null;
                        if (isset($batch_results[$text])) {
                            $translated = $batch_results[$text]; // Keyed by source text
                        } elseif (isset($batch_results[$index])) {
                            $translated = $batch_results[$index]; // Fallback to numeric index
                        }
                        
                        if ($translated !== null) {
                            // Guard: if batch returned non-string or empty, fallback to individual to avoid applying raw/empty
                            if (!is_string($translated) || trim($translated) === '') {
                                AITRFOEL_Logger::log('Empty/non-string batch item detected, using individual fallback for path: ' . ($item['path'] ?? 'n/a'), 'warning', $page_id);
                                $translated = $api->translate_text($text, $target_lang, $detected_lang);
                            }
                            $path = $item['path'];
                            $original = $item['original'];
                            $ph_map = $item['ph_map'];
                            
                            // Cache the translation (only if enabled)
                            if ($this->cache_enabled) {
                                $cache->set_to_cache(
                                    $text,
                                    $translated, // raw model output (placeholders still present)
                                    $target_lang,
                                    $detected_lang,
                                    get_option('aitrfoel_openai_model', 'gpt-3.5-turbo')
                                );
                            }
                            
                            // Residue retry
                            $needs_retry = false;
                            foreach (['Read more','Číst dál','Читать далее'] as $marker) {
                                if (is_string($translated) && mb_stripos($translated, $marker, 0, 'UTF-8') !== false) {
                                    $needs_retry = true; break;
                                }
                            }
                            if ($needs_retry) {
                                $retry = $api->translate_text($text, $target_lang, $detected_lang);
                                if (!is_wp_error($retry) && is_string($retry) && $retry !== '') {
                                    $translated = $retry;
                                }
                            }
                            // Restore placeholders (primary) or formatting (fallback)
                            if (!empty($ph_map) && method_exists($parser, 'i18n_restore_placeholders')) {
                                $final = $parser->i18n_restore_placeholders($translated, $ph_map);
                            } else {
                                $final = $this->restore_html_formatting($translated, $original);
                            }
                            if (method_exists($parser, 'i18n_cleanup_artifacts')) {
                                $final = $parser->i18n_cleanup_artifacts($final);
                            }
                            $translations[$path] = $final;
                        } else {
                            // No translation found in batch results - fallback to individual
                            AITRFOEL_Logger::log('Batch result missing for text (fallback to individual): ' . substr($text, 0, 50) . '...', 'warning', $page_id);
                            $translated = $api->translate_text($text, $target_lang, $detected_lang);
                            if (!is_wp_error($translated) && is_string($translated) && trim($translated) !== '') {
                                $path = $item['path'];
                                $original = $item['original'];
                                $ph_map = $item['ph_map'];
                                
                                if (!empty($ph_map) && method_exists($parser, 'i18n_restore_placeholders')) {
                                    $final = $parser->i18n_restore_placeholders($translated, $ph_map);
                                } else {
                                    $final = $this->restore_html_formatting($translated, $original);
                                }
                                if (method_exists($parser, 'i18n_cleanup_artifacts')) {
                                    $final = $parser->i18n_cleanup_artifacts($final);
                                }
                                $translations[$path] = $final;
                            }
                        }
                        
                        $processed++;
                    }
                    
                    // Rate limiting between batches
                    usleep($this->rate_limit_delay * 1000);
                }
                
                // Update progress
                $progress = round(($processed / $total_strings) * 100);
                AITRFOEL_Logger::log(
                    sprintf('Translation progress: %d%% (%d/%d strings processed)', 
                        $progress, $processed, $total_strings),
                    'info',
                    $page_id
                );
            }
        }
        
        // Final processing summary
    $cached_count = count($cached_translations);
    $translated_count = count($translations);
    $uncached_item_count = isset($uncached_items) ? count($uncached_items) : 0;
    $total_elements_processed = $cached_count + $translated_count;
        
        // Calculate statistics for this translation
        $word_count = 0;
        foreach ($strings_to_translate as $string_data) {
            if (isset($string_data['original'])) {
                $word_count += str_word_count(wp_strip_all_tags($string_data['original']));
            }
        }
        
        // Prepare estimated cost as fallback
        $usage_tracker = class_exists('AITRFOEL_Usage_Tracker') ? AITRFOEL_Usage_Tracker::get_instance() : null;
        // Simplified usage tracking - no cost estimation in basic version
        $estimated_cost = 0;

        // Actual usage summary from OpenAI API
        $usage_summary = method_exists($api, 'get_usage_summary') ? $api->get_usage_summary(true) : [];
        $prompt_tokens_actual = (int) ($usage_summary['prompt_tokens'] ?? 0);
        $completion_tokens_actual = (int) ($usage_summary['completion_tokens'] ?? 0);
        $total_tokens_actual = (int) ($usage_summary['total_tokens'] ?? ($prompt_tokens_actual + $completion_tokens_actual));
        $usage_records = $usage_summary['records'] ?? [];
        $usage_records_count = (int) ($usage_summary['records_count'] ?? count($usage_records));

        $has_actual_usage = ($total_tokens_actual > 0) || $usage_records_count > 0;
        $expected_usage = $uncached_item_count > 0;

        $input_tokens_final = 0;
        $output_tokens_final = 0;
        $total_tokens_final = 0;
        $cost_value = $estimated_cost;
        $cost_formatted = isset($estimated_cost_data['cost_formatted']) ? $estimated_cost_data['cost_formatted'] : ('$' . number_format($estimated_cost, 4));
        $is_estimated_stats = true;

        if ($has_actual_usage) {
            $is_estimated_stats = false;
            $input_tokens_final = $prompt_tokens_actual;
            $output_tokens_final = $completion_tokens_actual;
            $total_tokens_final = $total_tokens_actual;

            // Simplified cost calculation - no detailed cost tracking in basic version
            $cost_value = 0;
            $cost_formatted = '$0.0000';
        } elseif (!$expected_usage) {
            // All strings were served from cache
            $is_estimated_stats = false;
            $cost_value = 0;
            $cost_formatted = '$0.0000';
        } else {
            // Fallback to estimation when usage metrics are missing
            if (!empty($estimated_cost_data)) {
                $input_tokens_final = $estimated_cost_data['input_tokens'] ?? 0;
                $output_tokens_final = $estimated_cost_data['output_tokens'] ?? 0;
                $total_tokens_final = $estimated_cost_data['total_tokens'] ?? ($input_tokens_final + $output_tokens_final);
                $cost_value = $estimated_cost_data['estimated_cost'] ?? $cost_value;
                $cost_formatted = $estimated_cost_data['cost_formatted'] ?? ('$' . number_format($cost_value, 4));
            }
        }

        $usage_records_summary = [];
        if (!empty($usage_records)) {
            $usage_records_summary = array_map(function($record) {
                return [
                    'operation' => $record['operation'] ?? 'unknown',
                    'attempt' => $record['attempt'] ?? 0,
                    'prompt_tokens' => $record['prompt_tokens'] ?? 0,
                    'completion_tokens' => $record['completion_tokens'] ?? 0,
                    'total_tokens' => $record['total_tokens'] ?? 0,
                ];
            }, $usage_records);

            $encoded_records = function_exists('wp_json_encode') ? wp_json_encode($usage_records_summary) : json_encode($usage_records_summary);
            if (!empty($encoded_records)) {
                AITRFOEL_Logger::log('Usage breakdown (per call): ' . $encoded_records, 'debug', $page_id);
            }
        }

        // Get character and symbol counts from OpenAI usage records (more accurate)
        $character_count = 0;
        $symbol_count = 0;
        
        if (!empty($usage_records)) {
            // Sum up characters and symbols from all API calls
            foreach ($usage_records as $record) {
                $character_count += $record['input_characters'] ?? 0;
                $symbol_count += $record['input_symbols'] ?? 0;
            }
        }
        
        // Fallback: calculate from strings if not available from API records
        if ($character_count === 0 && class_exists('AITRFOEL_Usage_Tracker')) {
            $usage_tracker = AITRFOEL_Usage_Tracker::get_instance();
            
            // Extract original text from strings_to_translate
            $all_original_texts = [];
            foreach ($strings_to_translate as $string_data) {
                if (isset($string_data['original'])) {
                    $all_original_texts[] = $string_data['original'];
                }
            }
            
            // Basic character counting for simplified usage tracker
            $character_count = 0;
            $symbol_count = 0;
            foreach ($all_original_texts as $text) {
                $character_count += mb_strlen($text, 'UTF-8');
                // Count symbols (non-alphanumeric, non-space characters)
                $symbol_count += preg_match_all('/[^\p{L}\p{N}\s]/u', $text);
            }
            
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log("Using fallback character counting: {$character_count} chars, {$symbol_count} symbols", 'debug');
            }
        } else if (class_exists('AITRFOEL_Logger')) {
            AITRFOEL_Logger::log("Using API record character counts: {$character_count} chars, {$symbol_count} symbols", 'debug');
        }

        // Store statistics
        $this->last_translation_stats = [
            'elements_translated' => $total_elements_processed,
            'word_count' => $word_count,
            'character_count' => $character_count,
            'symbol_count' => $symbol_count,
            'input_tokens' => $input_tokens_final,
            'output_tokens' => $output_tokens_final,
            'total_tokens' => $total_tokens_final,
            'estimated_cost' => $cost_value,
            'cost_formatted' => $cost_formatted,
            'is_estimated' => $is_estimated_stats,
            'cached_strings' => $cached_count,
            'uncached_strings' => $uncached_item_count,
            'usage_records' => $usage_records_summary
        ];
        
        $usage_mode_label = $is_estimated_stats ? 'estimated' : 'actual';
        AITRFOEL_Logger::log(
            sprintf(
                'Usage totals (%s): prompt=%d, completion=%d, total=%d, cost=%s, pages=%d',
                $usage_mode_label,
                $this->last_translation_stats['input_tokens'],
                $this->last_translation_stats['output_tokens'],
                $this->last_translation_stats['total_tokens'],
                $cost_formatted,
                1
            ),
            'info',
            $page_id
        );
        
        AITRFOEL_Logger::log(
            sprintf('Translation completed: %d cached, %d new translations, %d total', 
                $cached_count, $translated_count, $total_elements_processed),
            'info',
            $page_id
        );
        
        // Merge cached and newly translated results
        $translations = array_merge($cached_translations, $translations);

        // Gap-Filler second pass: fix missed/under-translated strings before applying
        if (class_exists('AITRFOEL_Gap_Filler')) {
            // Prepare minimal strings format expected by gap-filler
            $strings = $strings_to_translate;
            // Inject group_key heuristically (prefix path up to .settings)
            foreach ($strings as &$row) {
                if (is_array($row)) {
                    $p = (string)($row['path'] ?? '');
                    $g = preg_replace('~(\.settings.*)$~', '', $p);
                    if ($g === null) { $g = ''; }
                    $row['group_key'] = $g;
                }
            }
            unset($row);

            $opts = [
                'levenshtein_threshold' => (int) get_option('aitrfoel_gap_lev_threshold', 3),
                'residue_patterns' => (string) get_option('aitrfoel_gap_residue_patterns', '/\\b(Read more|Číst dál|Читать далее)\\b/ui'),
                'max_retry' => (int) get_option('aitrfoel_gap_max_retry', 1),
                'enable_postprocessor' => (bool) get_option('aitrfoel_gap_enable_postprocessor', true),
                'page_id' => $page_id,
            ];
            $metrics = AITRFOEL_Gap_Filler::run($strings, $translations, $target_lang, $detected_lang, $opts);
            AITRFOEL_Logger::log(sprintf('[EAT gap-filler] page=%d lang=%s retried=%d fixed=%d failed=%d', $page_id, $target_lang, $metrics['retried'] ?? 0, $metrics['fixed'] ?? 0, $metrics['failed'] ?? 0), 'info', $page_id);
        }
        
    // Apply translations back to Elementor data
        $translated_data = $parser->apply_translations($elementor_data_array, $translations);

        // Deep cleanup pass across all string fields to ensure no escaped tags/orphan placeholders remain
        $deepChanged = 0;
        $walker = function (&$node) use (&$walker, $parser, &$deepChanged) {
            if (is_string($node)) {
                $clean = $parser->i18n_cleanup_artifacts($node);
                if ($clean !== $node) { $node = $clean; $deepChanged++; }
                return;
            }
            if (is_array($node)) {
                foreach ($node as &$v) { $walker($v); }
                unset($v);
            }
        };
        $tmp = $translated_data;
        $walker($tmp);
        if ($deepChanged > 0) {
            AITRFOEL_Logger::log("Deep cleanup adjusted $deepChanged fields before save", 'info', $page_id);
            $translated_data = $tmp;
        }

        // Enhanced validation: ensure counts match and quality check
        if (!is_array($translated_data) || !$parser->validate_structure($translated_data)) {
            AITRFOEL_Logger::log('Validation failed after translation apply (structure). Rolling back.', 'error', $page_id);
            return new WP_Error('invalid_structure', __('Translation resulted in invalid Elementor structure.', 'ai-translator-for-elementor-and-polylang'));
        }
        
        // Quality validation
        $quality_issues = $this->validate_translation_quality($translations, $target_lang);
        if (!empty($quality_issues)) {
            AITRFOEL_Logger::log('Translation quality issues detected: ' . implode(', ', $quality_issues), 'warning', $page_id);
        }
        
        // Localize internal links (href) to the target language before saving
        try {
            $this->localize_internal_links($translated_data, $target_lang);
        } catch (Exception $e) {
            AITRFOEL_Logger::log('URL localization failed: ' . $e->getMessage(), 'warning', $page_id);
        }

        // Create new page with translations
        $new_page_id = $this->create_translated_page($page_id, $translated_data, $target_lang, $detected_lang);
        
        if (is_wp_error($new_page_id)) {
            AITRFOEL_Logger::log(
                'Failed to create translated page: ' . $new_page_id->get_error_message(),
                'error',
                $page_id
            );
            return $new_page_id;
        }
        
        // Log success
        AITRFOEL_Logger::log(
            sprintf('Successfully translated page %d to %s. New page ID: %d', $page_id, $target_lang, $new_page_id),
            'success',
            $page_id,
            $new_page_id
        );
        
        // Report usage to server API
        // Since we're using OpenAI directly (not through server API),
        // we need to manually report the word count
        if (isset($this->last_translation_stats['word_count']) && $this->last_translation_stats['word_count'] > 0) {
            $word_count = $this->last_translation_stats['word_count'];
            
            try {
                // Report usage to the server API for tracking
                $license_manager = AITRFOEL_License_Manager_Server::get_instance();
                $response = wp_remote_post('https://meeptech.com/api/eat/report-usage', [
                    'timeout' => 10,
                    'body' => json_encode([
                        'site_url' => home_url(),
                        'license_key' => null,
                        'words_used' => $word_count,
                        'target_lang' => $target_lang
                    ]),
                    'headers' => [
                        'Content-Type' => 'application/json'
                    ]
                ]);
                
                if (!is_wp_error($response)) {
                    $status_code = wp_remote_retrieve_response_code($response);
                    $body = wp_remote_retrieve_body($response);
                    
                    // Update local cache
                    $data = json_decode($body, true);
                    if (isset($data['total_words_used'])) {
                        update_option('aitrfoel_words_used_this_month', $data['total_words_used']);
                        
                        // Check if free user hit limit - activate cooldown
                        $license_status = $license_manager->get_license_status();
                        $plan = $license_status['plan'];
                        $word_limit = $license_status['word_limit'];
                        $words_used_after = $data['total_words_used'];
                        
                        if (strtolower($plan) === 'free' && $word_limit !== -1 && $words_used_after >= $word_limit) {
                            // User just hit the limit - activate 7-day cooldown
                            $cooldown_end = time() + (7 * DAY_IN_SECONDS);
                            update_option('aitrfoel_cooldown_end_date', $cooldown_end);
                            
                            // Set a transient to show admin notice
                            set_transient('aitrfoel_cooldown_activated', true, 60);
                        }
                    }
                } else {
                    error_log('EAT: Failed to report usage: ' . $response->get_error_message());
                }
            } catch (Exception $e) {
                error_log('EAT: Exception reporting usage: ' . $e->getMessage());
            }
        }
        
        // Track successful translation with PostHog Analytics
        if (function_exists('aitrfoel_analytics')) {
            $translation_duration = microtime(true) - $translation_start_time;
            aitrfoel_analytics()->track_translation_completed([
                'duration' => round($translation_duration, 2),
                'word_count' => $this->last_translation_stats['word_count'] ?? 0,
                'widget_count' => $this->last_translation_stats['strings_count'] ?? 0,
                'success' => true,
                'mode' => 'single',
                'model' => get_option('aitrfoel_ai_model', 'gpt-4o-mini'),
                'source_lang' => $detected_lang,
                'target_lang' => $target_lang,
            ]);
        }
        
        return $new_page_id;
    }
    
    /**
     * Translate Gutenberg (Block Editor) page
     * 
     * @param int $page_id Original page ID
     * @param string $target_lang Target language
     * @param string $source_lang Source language
     * @param float $translation_start_time Start time for analytics
     * @return int|WP_Error New page ID or error
     */
    private function translate_gutenberg_page($page_id, $target_lang, $source_lang = 'auto', $translation_start_time = null) {
        if (!$translation_start_time) {
            $translation_start_time = microtime(true);
        }
        
        AITRFOEL_Logger::log(
            sprintf('Starting Gutenberg translation for page %d to %s', $page_id, $target_lang),
            'info',
            $page_id
        );
        
        $post = get_post($page_id);
        if (!$post || empty($post->post_content)) {
            $this->track_translation_failure('no_content', 'No content found in Gutenberg page', $translation_start_time);
            return new WP_Error('no_content', __('No content found in this page.', 'ai-translator-for-elementor-and-polylang'));
        }
        
        AITRFOEL_Logger::log(
            sprintf('Gutenberg page %d has %d characters of content', $page_id, strlen($post->post_content)),
            'info',
            $page_id
        );
        
        // Parse Gutenberg blocks
        $parser = AITRFOEL_Gutenberg_Parser::get_instance();
        $translation_map = $parser->parse_for_translation($page_id);
        
        AITRFOEL_Logger::log(
            sprintf('Gutenberg parser found %d translatable items', count($translation_map)),
            'info',
            $page_id
        );
        
        if (empty($translation_map)) {
            $this->track_translation_failure('no_translatable_content', 'No translatable content found in Gutenberg blocks', $translation_start_time);
            return new WP_Error('no_translatable_content', __('No translatable content found in this page.', 'ai-translator-for-elementor-and-polylang'));
        }
        
        // Convert to strings array for word limit check
        $strings_to_translate = [];
        foreach ($translation_map as $key => $data) {
            $strings_to_translate[] = $data;
        }
        
        // Word limit check (reuse existing logic)
        $word_limit_check = $this->check_word_limit($strings_to_translate, $page_id);
        if (is_wp_error($word_limit_check)) {
            AITRFOEL_Logger::log(
                'Word limit check failed: ' . $word_limit_check->get_error_message(),
                'warning',
                $page_id
            );
            return $word_limit_check;
        }
        
        // Detect source language
        $detected_lang = $this->detect_source_language_from_content($strings_to_translate, $source_lang, $page_id);
        
        AITRFOEL_Logger::log(
            sprintf('Gutenberg translation: Page %d, %s → %s, %d strings', 
                $page_id, $detected_lang, $target_lang, count($strings_to_translate)),
            'info',
            $page_id
        );
        
        // Translate strings
        $translations = $this->batch_translate_strings($strings_to_translate, $target_lang, $detected_lang, $page_id);
        
        if (is_wp_error($translations)) {
            AITRFOEL_Logger::log(
                'Batch translation failed: ' . $translations->get_error_message(),
                'error',
                $page_id
            );
            return $translations;
        }
        
        AITRFOEL_Logger::log(
            sprintf('Received %d translations from API', count($translations)),
            'info',
            $page_id
        );
        
        // Apply translations to content
        $original_content = $post->post_content;
        $translated_content = $parser->apply_translations($original_content, $translations);
        
        // Create translated page
        $new_page_id = $this->create_translated_page_generic($page_id, $translated_content, $target_lang, $detected_lang, 'gutenberg');
        
        if (is_wp_error($new_page_id)) {
            AITRFOEL_Logger::log(
                'Failed to create translated page: ' . $new_page_id->get_error_message(),
                'error',
                $page_id
            );
            return $new_page_id;
        }
        
        // Track success
        $this->track_translation_success($page_id, $new_page_id, $target_lang, $detected_lang, $translation_start_time, 'gutenberg');
        
        AITRFOEL_Logger::log(
            sprintf('Gutenberg translation complete: Page %d -> %d', $page_id, $new_page_id),
            'success',
            $page_id
        );
        
        return $new_page_id;
    }
    
    /**
     * Translate Classic Editor page
     * 
     * @param int $page_id Original page ID
     * @param string $target_lang Target language
     * @param string $source_lang Source language
     * @param float $translation_start_time Start time for analytics
     * @return int|WP_Error New page ID or error
     */
    private function translate_classic_page($page_id, $target_lang, $source_lang = 'auto', $translation_start_time = null) {
        if (!$translation_start_time) {
            $translation_start_time = microtime(true);
        }
        
        $post = get_post($page_id);
        if (!$post || empty($post->post_content)) {
            $this->track_translation_failure('no_content', 'No content found in Classic page', $translation_start_time);
            return new WP_Error('no_content', __('No content found in this page.', 'ai-translator-for-elementor-and-polylang'));
        }
        
        // Parse Classic Editor content
        $parser = AITRFOEL_Classic_Parser::get_instance();
        $translation_map = $parser->parse_for_translation($page_id);
        
        if (empty($translation_map)) {
            $this->track_translation_failure('no_translatable_content', 'No translatable content found in Classic Editor', $translation_start_time);
            return new WP_Error('no_translatable_content', __('No translatable content found in this page.', 'ai-translator-for-elementor-and-polylang'));
        }
        
        // Convert to strings array for word limit check
        $strings_to_translate = [];
        foreach ($translation_map as $key => $data) {
            $strings_to_translate[] = $data;
        }
        
        // Word limit check (reuse existing logic)
        $word_limit_check = $this->check_word_limit($strings_to_translate, $page_id);
        if (is_wp_error($word_limit_check)) {
            return $word_limit_check;
        }
        
        // Detect source language
        $detected_lang = $this->detect_source_language_from_content($strings_to_translate, $source_lang, $page_id);
        
        AITRFOEL_Logger::log(
            sprintf('Classic translation: Page %d, %s → %s, %d strings', 
                $page_id, $detected_lang, $target_lang, count($strings_to_translate)),
            'info',
            $page_id
        );
        
        // Translate strings
        $translations = $this->batch_translate_strings($strings_to_translate, $target_lang, $detected_lang, $page_id);
        
        if (is_wp_error($translations)) {
            return $translations;
        }
        
        // Apply translations to content
        $original_content = $post->post_content;
        $translated_content = $parser->apply_translations($original_content, $translations);
        
        // Create translated page
        $new_page_id = $this->create_translated_page_generic($page_id, $translated_content, $target_lang, $detected_lang, 'classic');
        
        if (is_wp_error($new_page_id)) {
            return $new_page_id;
        }
        
        // Track success
        $this->track_translation_success($page_id, $new_page_id, $target_lang, $detected_lang, $translation_start_time, 'classic');
        
        return $new_page_id;
    }
    
    /**
     * Check word limit for translation
     * 
     * @param array $strings_to_translate Strings to translate
     * @param int $page_id Page ID for logging
     * @return true|WP_Error True if within limit, error otherwise
     */
    private function check_word_limit($strings_to_translate, $page_id) {
        $license_manager = AITRFOEL_License_Manager_Server::get_instance();
        $license_status = $license_manager->get_license_status();
        
        $plan = $license_status['plan'];
        $word_limit = $license_status['word_limit'];
        $words_left = $license_status['words_left'];
        
        // Calculate estimated word count
        $estimated_words = 0;
        foreach ($strings_to_translate as $string_data) {
            if (isset($string_data['original'])) {
                $estimated_words += str_word_count(wp_strip_all_tags($string_data['original']));
            }
        }
        
        if ($word_limit !== -1 && $estimated_words > $words_left) {
            // Check cooldown for free plan
            if (strtolower($plan) === 'free') {
                $cooldown_end = get_option('aitrfoel_cooldown_end_date', 0);
                if ($cooldown_end > time()) {
                    $days_left = ceil(($cooldown_end - time()) / DAY_IN_SECONDS);
                    return new WP_Error(
                        'cooldown_active',
                        sprintf(
                            __('You have reached your monthly limit. Free plan users must wait %d more days for the limit to reset.', 'ai-translator-for-elementor-and-polylang'),
                            $days_left
                        )
                    );
                }
            }
            
            return new WP_Error(
                'insufficient_words',
                sprintf(
                    __('This translation requires approximately %s words, but you only have %s words remaining.', 'ai-translator-for-elementor-and-polylang'),
                    number_format($estimated_words),
                    number_format($words_left)
                )
            );
        }
        
        return true;
    }
    
    /**
     * Detect source language from content
     * 
     * @param array $strings_to_translate Strings to analyze
     * @param string $source_lang Provided source language (or 'auto')
     * @param int $page_id Page ID for logging
     * @return string Detected language code
     */
    private function detect_source_language_from_content($strings_to_translate, $source_lang, $page_id) {
        if ($source_lang !== 'auto') {
            return $source_lang;
        }
        
        $detected = $this->detect_source_language($strings_to_translate, $page_id);
        return $detected ? $detected : 'en';
    }
    
    /**
     * Batch translate strings using the OpenAI API
     * 
     * @param array $strings_to_translate Array of string data with 'original' key
     * @param string $target_lang Target language
     * @param string $source_lang Source language
     * @param int $page_id Page ID for logging
     * @return array|WP_Error Array of original => translated, or error
     */
    private function batch_translate_strings($strings_to_translate, $target_lang, $source_lang, $page_id) {
        $api = AITRFOEL_OpenAI_API::get_instance();
        $cache = AITRFOEL_Cache_Manager::get_instance();
        
        $translations = [];
        $uncached_strings = [];
        
        AITRFOEL_Logger::log(
            sprintf('batch_translate_strings: Processing %d strings for translation', count($strings_to_translate)),
            'info',
            $page_id
        );
        
        // Check cache first
        foreach ($strings_to_translate as $data) {
            $original = isset($data['original']) ? $data['original'] : '';
            if (empty(trim($original))) {
                continue;
            }
            
            $cached = null;
            if ($this->cache_enabled) {
                $cached = $cache->get_from_cache($original, $target_lang, $source_lang);
            }
            
            if ($cached) {
                $translations[$original] = $cached;
            } else {
                $uncached_strings[] = $original;
            }
        }
        
        AITRFOEL_Logger::log(
            sprintf('batch_translate_strings: %d cached, %d need translation', 
                count($translations), count($uncached_strings)),
            'info',
            $page_id
        );
        
        // Batch translate uncached strings
        if (!empty($uncached_strings)) {
            $batches = array_chunk($uncached_strings, $this->batch_size);
            
            AITRFOEL_Logger::log(
                sprintf('batch_translate_strings: Sending %d batches to API', count($batches)),
                'info',
                $page_id
            );
            
            foreach ($batches as $batch_index => $batch) {
                AITRFOEL_Logger::log(
                    sprintf('batch_translate_strings: Processing batch %d with %d strings', $batch_index + 1, count($batch)),
                    'info',
                    $page_id
                );
                
                $result = $api->translate_batch($batch, $target_lang, $source_lang);
                
                if (is_wp_error($result)) {
                    AITRFOEL_Logger::log('Batch translation error: ' . $result->get_error_message(), 'error', $page_id);
                    continue;
                }
                
                // Process results
                foreach ($result as $original => $translated) {
                    $translations[$original] = $translated;
                    
                    // Save to cache
                    if ($this->cache_enabled && !empty($translated)) {
                        $cache->set_to_cache($original, $translated, $target_lang, $source_lang, 'gpt-4o-mini');
                    }
                }
                
                // Rate limiting
                usleep($this->rate_limit_delay * 1000);
            }
        }
        
        // Track word count
        $word_count = 0;
        foreach ($translations as $original => $translated) {
            $word_count += str_word_count(wp_strip_all_tags($original));
        }
        $this->last_translation_stats['word_count'] = $word_count;
        $this->last_translation_stats['strings_count'] = count($translations);
        
        AITRFOEL_Logger::log(
            sprintf('batch_translate_strings: Complete. %d translations, %d words', 
                count($translations), $word_count),
            'info',
            $page_id
        );
        
        return $translations;
    }
    
    /**
     * Create translated page for Gutenberg/Classic content
     * 
     * @param int $original_page_id Original page ID
     * @param string $translated_content Translated post content
     * @param string $target_lang Target language
     * @param string $source_lang Source language
     * @param string $content_type Content type (gutenberg/classic)
     * @return int|WP_Error New page ID or error
     */
    private function create_translated_page_generic($original_page_id, $translated_content, $target_lang, $source_lang, $content_type) {
        $original_page = get_post($original_page_id);
        
        if (!$original_page) {
            return new WP_Error('page_not_found', __('Original page not found.', 'ai-translator-for-elementor-and-polylang'));
        }
        
        // Translate title
        $api = AITRFOEL_OpenAI_API::get_instance();
        $translated_title = $api->translate_title($original_page->post_title, $target_lang, $source_lang);
        
        if (is_wp_error($translated_title)) {
            $translated_title = $original_page->post_title . ' (' . strtoupper($target_lang) . ')';
        }
        
        // Create new page
        $target_status = get_option('aitrfoel_translated_page_status', 'draft');
        
        $new_page_args = [
            'post_title' => $translated_title,
            'post_content' => $translated_content,
            'post_status' => $target_status,
            'post_type' => $original_page->post_type,
            'post_parent' => $original_page->post_parent,
            'menu_order' => $original_page->menu_order,
            'post_excerpt' => $original_page->post_excerpt,
        ];
        
        $new_page_id = wp_insert_post($new_page_args);
        
        if (is_wp_error($new_page_id)) {
            return $new_page_id;
        }
        
        // Copy relevant meta
        $meta_keys_to_copy = [
            '_wp_page_template',
            'site-post-title',
            'ast-title-bar-display',
            'ast-featured-img',
            'ast-site-content-layout',
            'site-sidebar-layout',
        ];
        
        foreach ($meta_keys_to_copy as $meta_key) {
            $meta_value = get_post_meta($original_page_id, $meta_key, true);
            if ($meta_value !== '') {
                update_post_meta($new_page_id, $meta_key, $meta_value);
            }
        }
        
        // Store content type for reference
        update_post_meta($new_page_id, '_aitrfoel_content_type', $content_type);
        update_post_meta($new_page_id, '_aitrfoel_source_page', $original_page_id);
        
        // Setup multilingual relationship
        $multilingual = AITRFOEL_Multilingual_Manager::get_instance();
        if ($multilingual->is_active()) {
            $result = $multilingual->setup_translation_relationship($original_page_id, $new_page_id, $target_lang);
            
            if (is_wp_error($result)) {
                AITRFOEL_Logger::log(
                    'Multilingual setup failed: ' . $result->get_error_message(),
                    'warning',
                    $original_page_id,
                    $new_page_id
                );
            }
        }
        
        AITRFOEL_Logger::log(
            sprintf('Created %s translated page: %d -> %d (%s)', $content_type, $original_page_id, $new_page_id, $target_lang),
            'success',
            $original_page_id,
            $new_page_id
        );
        
        return $new_page_id;
    }
    
    /**
     * Track translation success with analytics
     * 
     * @param int $page_id Original page ID
     * @param int $new_page_id New page ID
     * @param string $target_lang Target language
     * @param string $source_lang Source language
     * @param float $translation_start_time Start time
     * @param string $content_type Content type
     */
    private function track_translation_success($page_id, $new_page_id, $target_lang, $source_lang, $translation_start_time, $content_type) {
        AITRFOEL_Logger::log(
            sprintf('Successfully translated page %d to %s. New page ID: %d (type: %s)', $page_id, $target_lang, $new_page_id, $content_type),
            'success',
            $page_id,
            $new_page_id
        );
        
        // Report usage to server
        if (isset($this->last_translation_stats['word_count']) && $this->last_translation_stats['word_count'] > 0) {
            $this->report_usage_to_server($this->last_translation_stats['word_count'], $target_lang);
        }
        
        // Track with PostHog Analytics
        if (function_exists('aitrfoel_analytics')) {
            $translation_duration = microtime(true) - $translation_start_time;
            aitrfoel_analytics()->track_translation_completed([
                'duration' => round($translation_duration, 2),
                'word_count' => $this->last_translation_stats['word_count'] ?? 0,
                'widget_count' => $this->last_translation_stats['strings_count'] ?? 0,
                'success' => true,
                'mode' => 'single',
                'content_type' => $content_type,
                'source_lang' => $source_lang,
                'target_lang' => $target_lang,
            ]);
        }
    }
    
    /**
     * Report usage to server API
     * 
     * @param int $word_count Words translated
     * @param string $target_lang Target language
     */
    private function report_usage_to_server($word_count, $target_lang) {
        try {
            $response = wp_remote_post('https://meeptech.com/api/eat/report-usage', [
                'timeout' => 10,
                'body' => json_encode([
                    'site_url' => home_url(),
                    'license_key' => null,
                    'words_used' => $word_count,
                    'target_lang' => $target_lang
                ]),
                'headers' => [
                    'Content-Type' => 'application/json'
                ]
            ]);
            
            if (!is_wp_error($response)) {
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);
                
                if (isset($data['total_words_used'])) {
                    update_option('aitrfoel_words_used_this_month', $data['total_words_used']);
                    
                    // Check cooldown for free plan
                    $license_manager = AITRFOEL_License_Manager_Server::get_instance();
                    $license_status = $license_manager->get_license_status();
                    $plan = $license_status['plan'];
                    $word_limit = $license_status['word_limit'];
                    
                    if (strtolower($plan) === 'free' && $word_limit !== -1 && $data['total_words_used'] >= $word_limit) {
                        $cooldown_end = time() + (7 * DAY_IN_SECONDS);
                        update_option('aitrfoel_cooldown_end_date', $cooldown_end);
                        set_transient('aitrfoel_cooldown_activated', true, 60);
                    }
                }
            }
        } catch (Exception $e) {
            error_log('EAT: Exception reporting usage: ' . $e->getMessage());
        }
    }
    
    /**
     * Clean text for translation (remove HTML but preserve structure)
     * 
     * @param string $text Original text with HTML
     * @return string Clean text
     */
    private function clean_text_for_translation($text) {
        // Store original text for hash key
        $original_text = $text;
        
        // Ensure input is string
        if (!is_string($text)) {
            return '';
        }
        
        // Skip if text is too short to be meaningful (use mb_strlen for UTF-8)
        if (mb_strlen(trim($text), 'UTF-8') < 2) {
            return '';
        }
        
        // Preserve emojis and special Unicode characters
        // Convert to UTF-8 if needed
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', mb_detect_encoding($text));
        }
        
        // Preserve important line breaks and spacing elements with placeholders
        $html_placeholders = [];
        $placeholder_counter = 0;
        
        // Preserve complete HTML elements while cleaning content
        // Process in order that preserves nested structures correctly
        // SIMPLIFIED APPROACH: Only preserve major structural elements, let OpenAI handle inline formatting
        $preserved_patterns = [
            '/<(div)([^>]*)>(.*?)<\/\1>/s' => 'DIV',
            '/<(h[1-6])([^>]*)>(.*?)<\/\1>/s' => 'HDG',
            '/<(p)([^>]*)>(.*?)<\/\1>/s' => 'PGR',
            '/<(ul|ol)([^>]*)>(.*?)<\/\1>/s' => 'LST', 
            '/<(li)([^>]*)>(.*?)<\/\1>/s' => 'ITM',
        ];
        
        // Store HTML structure and replace with placeholders
        // Only for BLOCK elements - let inline elements pass through to OpenAI
        foreach ($preserved_patterns as $pattern => $placeholder_prefix) {
            AITRFOEL_Logger::log("Processing pattern for: $placeholder_prefix", 'debug');
            
            $match_count = 0;
            $text = preg_replace_callback($pattern, function($matches) use (&$html_placeholders, &$placeholder_counter, $placeholder_prefix, &$match_count) {
                $match_count++;
                $tag = $matches[1];
                $attributes = $matches[2];
                $content = $matches[3];
                
                // Create translation-resistant placeholder using random string
                $random_id = substr(md5(uniqid() . $placeholder_counter), 0, 8);
                $placeholder = $placeholder_prefix . '_' . $random_id;
                $placeholder_counter++;
                
                // Store the HTML structure with the base placeholder name
                $html_placeholders[$placeholder] = [
                    'tag' => $tag,
                    'attributes' => $attributes,
                    'is_self_closing' => false
                ];
                
                AITRFOEL_Logger::log("Match $match_count: Storing placeholder: $placeholder for tag: <$tag$attributes>", 'debug');
                
                // For all block elements, use newlines
                return "\n≪" . $placeholder . "≫\n" . $content . "\n≪/" . $placeholder . "≫\n";
            }, $text);
            
            AITRFOEL_Logger::log("Pattern $placeholder_prefix found $match_count matches", 'debug');
        }
        
        // Handle self-closing tags with translation-resistant markers
        $text = str_replace(['<br>', '<br/>', '<br />'], "\n≪BR≫\n", $text);
        
        // Strip HTML tags but preserve content and placeholders
        $text = wp_strip_all_tags($text);
        
        // Decode HTML entities (preserve Unicode)
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Clean up whitespace but preserve intentional line breaks and placeholders
        $text = preg_replace('/[ \t]+/u', ' ', $text); // Normalize spaces and tabs (with Unicode flag)
        $text = preg_replace('/\n\s*\n/u', "\n", $text); // Remove excess line breaks
        $text = trim($text);
        
        // Skip text that's only numbers, special characters, or whitespace
        // BUT allow Unicode letters (Cyrillic, Arabic, Chinese, etc.)
        if (!preg_match('/\p{L}/u', $text)) {
            return '';
        }
        
        // Store the HTML placeholders for restoration using original text hash
        $text_hash = md5($original_text);
        $this->html_placeholders_storage[$text_hash] = $html_placeholders;
        
        AITRFOEL_Logger::log("Stored placeholders for hash: $text_hash (original text hash)", 'debug');
        
        return $text;
    }
    
    /**
     * Restore HTML formatting after translation
     * 
     * @param string $translated_text Clean translated text
     * @param string $original_text Original text with HTML
     * @return string Translated text with HTML restored
     */
    private function restore_html_formatting($translated_text, $original_text) {
        // Ensure inputs are strings
        if (!is_string($translated_text) || !is_string($original_text)) {
            return is_string($translated_text) ? $translated_text : '';
        }
        
        AITRFOEL_Logger::log("restore_html_formatting called", 'debug');
    AITRFOEL_Logger::log("Original text: " . substr((string)$original_text, 0, 200) . (strlen((string)$original_text) > 200 ? '...' : ''), 'debug');
    AITRFOEL_Logger::log("Translated text: " . substr((string)$translated_text, 0, 200) . (strlen((string)$translated_text) > 200 ? '...' : ''), 'debug');
        
        // CRITICAL: Strip any HTML that the AI might have added to the translation
        // The AI should only return plain text, but sometimes adds its own HTML interpretation
        $cleaned_translated = wp_strip_all_tags($translated_text);
        if ($cleaned_translated !== $translated_text) {
            AITRFOEL_Logger::log("WARNING: AI added HTML to translation, stripping it", 'warning');
            AITRFOEL_Logger::log("Original AI response: " . $translated_text, 'warning');
            AITRFOEL_Logger::log("Cleaned response: " . $cleaned_translated, 'debug');
        }
        
        // If no HTML in original, return cleaned translation
        if ($original_text === wp_strip_all_tags($original_text)) {
            AITRFOEL_Logger::log("No HTML in original text, returning cleaned translation", 'debug');
            return $cleaned_translated;
        }
        
        // If we have stored HTML placeholders, restore them
        $original_hash = md5($original_text);
        AITRFOEL_Logger::log("Looking for placeholders with hash: $original_hash", 'debug');
        AITRFOEL_Logger::log("Available hashes: " . implode(', ', array_keys($this->html_placeholders_storage)), 'debug');
        
        if (!empty($this->html_placeholders_storage[$original_hash])) {
            AITRFOEL_Logger::log("Using placeholder restoration with " . count($this->html_placeholders_storage[$original_hash]) . " placeholders for hash: $original_hash", 'debug');
            $result = $this->restore_html_with_placeholders($cleaned_translated, $this->html_placeholders_storage[$original_hash]);
            
            // Check if placeholder restoration actually worked
            // If the result is the same as input, placeholder restoration didn't work because
            // the AI response doesn't contain the placeholder markers
            if ($result === $cleaned_translated) {
                AITRFOEL_Logger::log("Placeholder restoration had no effect (AI didn't return placeholders), falling back to pattern matching", 'warning');
                return $this->restore_html_simple_patterns($cleaned_translated, $original_text);
            }
            
            return $result;
        } else {
            AITRFOEL_Logger::log("No placeholders found for hash: $original_hash", 'warning');
        }
        
        // Fallback to simple pattern matching for backward compatibility
        AITRFOEL_Logger::log("Using fallback pattern matching", 'debug');
        return $this->restore_html_simple_patterns($cleaned_translated, $original_text);
    }
    
    /**
     * Restore HTML using stored placeholders
     */
    private function restore_html_with_placeholders($translated_text, $html_placeholders) {
        AITRFOEL_Logger::log("Restoring HTML with placeholders. Text length: " . strlen($translated_text), 'debug');
        AITRFOEL_Logger::log("Available placeholders: " . count($html_placeholders), 'debug');
        
        // First restore complex HTML elements (BEFORE BR tag replacement)
        foreach ($html_placeholders as $placeholder => $html_info) {
            $start_marker = '≪' . $placeholder . '≫';
            $end_marker = '≪/' . $placeholder . '≫';
            
            AITRFOEL_Logger::log("Looking for placeholder: $placeholder", 'debug');
            AITRFOEL_Logger::log("Start marker: $start_marker", 'debug');
            AITRFOEL_Logger::log("End marker: $end_marker", 'debug');
            
            // Check if markers exist in the text
            if (strpos($translated_text, $start_marker) !== false && strpos($translated_text, $end_marker) !== false) {
                AITRFOEL_Logger::log("Found markers for placeholder: $placeholder", 'debug');
                
                // Find content between markers - handle inline vs block elements differently
                $is_inline = in_array($html_info['tag'], ['strong', 'b', 'em', 'i', 'a', 'span']);
                
                if ($is_inline) {
                    // For inline elements, preserve space around markers
                    $pattern = '/\s*' . preg_quote($start_marker, '/') . '\s+(.*?)\s+' . preg_quote($end_marker, '/') . '\s*/s';
                } else {
                    // For block elements, use the original pattern
                    $pattern = '/\s*' . preg_quote($start_marker, '/') . '\s*(.*?)\s*' . preg_quote($end_marker, '/') . '\s*/s';
                }
                
                $translated_text = preg_replace_callback($pattern, function($matches) use ($html_info, $placeholder, $is_inline) {
                    $translated_content = trim($matches[1]);
                    $tag = $html_info['tag'];
                    $attributes = $html_info['attributes'];
                    
                    AITRFOEL_Logger::log("Restoring placeholder $placeholder: <$tag$attributes>$translated_content</$tag>", 'debug');
                    
                    $restored = "<{$tag}{$attributes}>{$translated_content}</{$tag}>";
                    
                    // For inline elements, add a space after to prevent concatenation
                    if ($is_inline) {
                        return $restored . ' ';
                    }
                    
                    return $restored;
                }, $translated_text);
            } else {
                AITRFOEL_Logger::log("Markers not found for placeholder: $placeholder", 'warning');
            }
        }
        
        // Then restore simple BR tags (AFTER placeholder restoration) with new marker format
        $translated_text = str_replace('≪BR≫', '<br>', $translated_text);
        
        AITRFOEL_Logger::log("HTML restoration complete", 'debug');
        return $translated_text;
    }
    
    /**
     * Simple pattern matching for HTML restoration (fallback)
     */
    private function restore_html_simple_patterns($translated_text, $original_text) {
        AITRFOEL_Logger::log("Fallback restoration - Original: " . substr($original_text, 0, 200), 'debug');
        AITRFOEL_Logger::log("Fallback restoration - Translated: " . substr($translated_text, 0, 200), 'debug');
        
        // Strip any HTML that might have been added by the AI translation service
        $clean_translated = wp_strip_all_tags($translated_text);
        
        // STEP 1: Try to restore inline HTML elements using text matching
        $restored_with_inline = $this->restore_inline_html_fallback($clean_translated, $original_text);
        
        AITRFOEL_Logger::log("After inline HTML restoration: " . substr($restored_with_inline, 0, 200), 'debug');
        
        // STEP 2: Handle structural restoration
        $trimmed_original = trim($original_text);
        
        // Count the number of top-level HTML elements in the original
        $element_count = preg_match_all('/<([a-zA-Z0-9]+)(?:[^>]*)>.*?<\/\1>/s', $trimmed_original, $matches);
        
        AITRFOEL_Logger::log("Fallback: Found $element_count top-level HTML elements", 'debug');
        
        // If there's only ONE top-level element, we can safely restore it
        if ($element_count == 1) {
            // Handle nested elements FIRST (like <h3><strong>Title</strong></h3>)
            if (preg_match('/^<(h[1-6])([^>]*)><(strong|b|em|i)([^>]*)>(.*?)<\/\3><\/\1>$/s', $trimmed_original, $matches)) {
                $outer_tag = $matches[1];
                $outer_attrs = $matches[2];
                $inner_tag = $matches[3];
                $inner_attrs = $matches[4];
                $content = $matches[5];
                
                $result = "<{$outer_tag}{$outer_attrs}><{$inner_tag}{$inner_attrs}>{$restored_with_inline}</{$inner_tag}></{$outer_tag}>";
                AITRFOEL_Logger::log("Fallback: Restored nested element: $result", 'debug');
                return $result;
            }
            
            // Handle simple single element cases
            if (preg_match('/^<([a-zA-Z0-9]+)([^>]*)>(.*?)<\/\1>$/s', $trimmed_original, $matches)) {
                $tag = $matches[1];
                $attrs = $matches[2];
                $content = $matches[3];
                
                $result = "<{$tag}{$attrs}>{$restored_with_inline}</{$tag}>";
                AITRFOEL_Logger::log("Fallback: Restored single element <$tag>: $result", 'debug');
                return $result;
            }
        }
        
        // If we have MULTIPLE elements or complex structure, DO NOT attempt automatic splitting
        // This prevents the over-splitting issue you're experiencing
        if ($element_count > 1) {
            AITRFOEL_Logger::log("Fallback: Multiple elements detected - returning original structure with translated text inserted in first element only", 'warning');
            
            // Find the first paragraph or main content element and replace its content
            $result = preg_replace_callback('/<(p|h[1-6]|div)([^>]*)>(.*?)<\/\1>/s', function($matches) use ($restored_with_inline) {
                $tag = $matches[1];
                $attrs = $matches[2];
                $original_content = $matches[3];
                
                // Only replace the first match, preserve the rest as-is
                static $first_replacement = true;
                if ($first_replacement) {
                    $first_replacement = false;
                    AITRFOEL_Logger::log("Fallback: Replacing content in first <$tag> element", 'debug');
                    return "<{$tag}{$attrs}>{$restored_with_inline}</{$tag}>";
                }
                
                // Keep subsequent elements unchanged
                return $matches[0];
            }, $trimmed_original, 1); // Limit to 1 replacement
            
            if ($result !== $trimmed_original) {
                AITRFOEL_Logger::log("Fallback: Partial restoration completed", 'debug');
                return $result;
            }
        }
        
        // Last resort: return the translated text with any inline HTML we managed to restore
        AITRFOEL_Logger::log("Fallback: Could not restore HTML structure - returning text with inline HTML", 'warning');
        return $restored_with_inline;
    }
    
    /**
     * Try to restore inline HTML elements by matching text content with flexible patterns
     */
    private function restore_inline_html_fallback($translated_text, $original_text) {
        // Strategy 1: Extract all inline HTML elements and their text content
        $inline_patterns = [
            '/<strong[^>]*>(.*?)<\/strong>/i' => 'strong',
            '/<em[^>]*>(.*?)<\/em>/i' => 'em',  
            '/<a [^>]*href[^>]*>(.*?)<\/a>/i' => 'a',
            '/<span[^>]*>(.*?)<\/span>/i' => 'span',
            '/<b[^>]*>(.*?)<\/b>/i' => 'b',
            '/<i[^>]*>(.*?)<\/i>/i' => 'i'
        ];
        
        $found_elements = [];
        foreach ($inline_patterns as $pattern => $tag_type) {
            if (preg_match_all($pattern, $original_text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $full_tag = $match[0];
                    $text_content = trim(wp_strip_all_tags($match[1]));
                    
                    if (!empty($text_content)) {
                        $found_elements[] = [
                            'full_tag' => $full_tag,
                            'text' => $text_content,
                            'type' => $tag_type
                        ];
                        
                        AITRFOEL_Logger::log("Found $tag_type: '" . $text_content . "' in " . substr($full_tag, 0, 50), 'debug');
                    }
                }
            }
        }
        
        AITRFOEL_Logger::log("Found " . count($found_elements) . " inline HTML elements for flexible restoration", 'debug');
        
        $restored = $translated_text;
        $restorations = 0;
        
        // Strategy 2: Flexible pattern matching for different element types
        foreach ($found_elements as $element) {
            $original_text_clean = $element['text'];
            $tag_type = $element['type'];
            $full_original_tag = $element['full_tag'];
            
            if ($tag_type === 'strong' || $tag_type === 'b') {
                // Look for numeric patterns in strong tags
                if (preg_match('/(\d+(?:[.,]\d+)*)\s*(million|thousand|individuals|foreigners|%)/i', $original_text_clean, $orig_matches)) {
                    // Try multiple patterns for different languages
                    $patterns_to_try = [
                        '/(\d+[.,]?\d*)\s*%/',  // Percentage
                        '/(\d+[.,\s]?\d*)\s*(milion[ůy]?|tisíc)/ui', // Million/thousand in Czech
                        '/(\d+[.,\s]?\d*)\s*(jednotlivců|osob|lidí)/ui', // Individuals in Czech
                        '/(\d+[.,\s]?\d*)\s*(cizinců|cizích)/ui', // Foreigners in Czech
                    ];
                    
                    foreach ($patterns_to_try as $pattern) {
                        if (preg_match($pattern, $restored, $trans_matches)) {
                            $translated_match = trim($trans_matches[0]);
                            
                            // Check if it's not already wrapped
                            if (strpos($restored, "<$tag_type>$translated_match</$tag_type>") === false) {
                                $restored = str_replace($translated_match, "<$tag_type>$translated_match</$tag_type>", $restored);
                                AITRFOEL_Logger::log("Restored $tag_type around: '$translated_match'", 'debug');
                                $restorations++;
                                break; // Stop after first successful match
                            }
                        }
                    }
                }
            } else if ($tag_type === 'a') {
                // For links, look for common link text patterns
                $link_patterns = [
                    '/(číst více|čtěte více)/ui',
                    '/(read more)/i',
                    '/(více informací|více info)/ui',
                    '/(pokračovat|continue)/ui',
                    '/(zjistit více|learn more)/ui'
                ];
                
                foreach ($link_patterns as $pattern) {
                    if (preg_match($pattern, $restored, $trans_matches)) {
                        $translated_link_text = $trans_matches[0];
                        
                        // Extract href from original
                        if (preg_match('/href=(["\'])([^"\']+)\1/i', $full_original_tag, $href_match)) {
                            $href = $href_match[2];
                            $new_link = "<a href=\"$href\">$translated_link_text</a>";
                            
                            // Only replace if not already a link
                            if (strpos($restored, $new_link) === false) {
                                $restored = str_replace($translated_link_text, $new_link, $restored);
                                AITRFOEL_Logger::log("Restored link: '$translated_link_text'", 'debug');
                                $restorations++;
                                break;
                            }
                        }
                    }
                }
            }
        }
        
        AITRFOEL_Logger::log("Total flexible restorations made: $restorations", 'debug');
        return $restored;
    }

    /**
     * Normalize a language value (name or code) to multilingual plugin language code.
     */
    private function normalize_lang_code($lang) {
        $code = $lang;
        if (is_string($lang) && strlen($lang) > 2 && class_exists('AITRFOEL_Multilingual_Manager')) {
            $multilingual = AITRFOEL_Multilingual_Manager::get_instance();
            $mapped = $multilingual->get_language_code($lang);
            if ($mapped) { $code = $mapped; }
        }
        return $code;
    }

    /**
     * Determine if a URL is internal to this site.
     */
    private function is_internal_url($url) {
        if (!is_string($url) || $url === '') return false;
        // Skip special schemes
        if (preg_match('~^(#|mailto:|tel:|javascript:)~i', $url)) return false;
        $home = home_url('/');
        $home_parts = wp_parse_url($home);
        $u = wp_parse_url($url);
        // Relative URL => internal
        if ($u === false || !isset($u['host'])) return true;
        return isset($home_parts['host']) && strcasecmp($home_parts['host'], $u['host']) === 0;
    }

    /**
     * Build absolute URL for a (possibly relative) href.
     */
    private function to_absolute_url($href) {
        if (!is_string($href) || $href === '') return $href;
        $u = wp_parse_url($href);
        if ($u === false) return $href;
        if (isset($u['scheme']) && isset($u['host'])) return $href; // already absolute
        // Relative path
        if (isset($u['path']) && substr($u['path'], 0, 1) === '/') {
            return home_url($u['path'] . (isset($u['query']) ? ('?' . $u['query']) : '') . (isset($u['fragment']) ? ('#' . $u['fragment']) : ''));
        }
        // Bare relative like "contact"
        $path = isset($u['path']) ? ('/' . ltrim($u['path'], '/')) : '/';
        return home_url($path . (isset($u['query']) ? ('?' . $u['query']) : '') . (isset($u['fragment']) ? ('#' . $u['fragment']) : ''));
    }

    /**
     * Localize a single URL to the target language using Polylang mappings when possible.
     */
    private function localize_single_url($href, $target_lang) {
        if (!is_string($href) || $href === '') return $href;
        if (!$this->is_internal_url($href)) return $href;

        $target_code = $this->normalize_lang_code($target_lang);
        $abs = $this->to_absolute_url($href);

        // Separate query/fragment
        $parts = wp_parse_url($abs);
        if ($parts === false) return $href;
        $base_no_qf = (isset($parts['scheme']) ? $parts['scheme'] . '://' : '') .
                      (isset($parts['host']) ? $parts['host'] : '') .
                      (isset($parts['port']) ? (':' . $parts['port']) : '') .
                      (isset($parts['path']) ? $parts['path'] : '');
        $suffix = (isset($parts['query']) ? ('?' . $parts['query']) : '') . (isset($parts['fragment']) ? ('#' . $parts['fragment']) : '');

        // 1) Best-effort: map post ID to its translation
        $post_id = url_to_postid($base_no_qf);
        if ($post_id) {
            if (function_exists('pll_get_post') && $target_code) {
                $translated_id = pll_get_post($post_id, $target_code);
                if ($translated_id) {
                    $new = get_permalink($translated_id);
                    if (is_string($new) && $new !== '') {
                        return $new . $suffix;
                    }
                }
            }
        }

        // 2) Fallback: swap language home base, keep path
        // Use multilingual manager if available, fallback to Polylang functions for backward compatibility
        $multilingual = class_exists('AITRFOEL_Multilingual_Manager') ? AITRFOEL_Multilingual_Manager::get_instance() : null;
        $default_lang = $multilingual ? $multilingual->get_default_language() : (function_exists('pll_default_language') ? pll_default_language() : null);
        
        // Get home URLs - Polylang has pll_home_url, WPML uses filters
        if (function_exists('pll_home_url')) {
            $home_default = pll_home_url($default_lang) ?: home_url('/');
            $home_target = pll_home_url($target_code) ?: home_url('/');
        } elseif (function_exists('icl_get_home_url')) {
            // WPML home URL function
            $home_default = icl_get_home_url() ?: home_url('/');
            $home_target = $target_code ? apply_filters('wpml_home_url', home_url('/'), $target_code) : home_url('/');
        } else {
            $home_default = home_url('/');
            $home_target = home_url('/');
        }

        // Normalize trailing slashes
        $home_default = trailingslashit($home_default);
        $home_target = trailingslashit($home_target);

        if (stripos($abs, $home_default) === 0) {
            $tail = substr($abs, strlen($home_default));
            return $home_target . $tail; // suffix already included in $abs
        }
        // Also try plain home_url as base
        $home_plain = trailingslashit(home_url('/'));
        if (stripos($abs, $home_plain) === 0) {
            $tail = substr($abs, strlen($home_plain));
            return $home_target . $tail;
        }

        return $href; // unchanged
    }

    /**
     * Rewrite href attributes inside an HTML blob to the target language.
     */
    private function localize_hrefs_in_html($html, $target_lang) {
        if (!is_string($html) || stripos($html, '<a') === false || stripos($html, 'href=') === false) return $html;
        $self = $this;
        $cb = function($m) use ($self, $target_lang) {
            $full_tag = $m[0];
            $q = $m[1];
            $href = $m[2];
            $new = $self->localize_single_url($href, $target_lang);
            if ($new === $href) return $full_tag;
            // Replace only the first href occurrence within this tag
            $replaced = preg_replace('/href=([\"\'])[^\"\']*\1/i', 'href=' . $q . esc_url($new) . $q, $full_tag, 1);
            return is_string($replaced) ? $replaced : $full_tag;
        };
        return preg_replace_callback('/<a\s[^>]*href=([\"\'])([^\"\']+)\1[^>]*>/i', $cb, $html);
    }

    /**
     * Walk Elementor translated data and localize internal links for target language.
     */
    private function localize_internal_links(&$data, $target_lang) {
        $walker = function (&$node, $keyPath = []) use (&$walker, $target_lang) {
            if (is_array($node)) {
                // Special-case: Elementor setting 'link' => ['url' => ...]
                if (isset($node['url']) && is_string($node['url'])) {
                    // Heuristic: if parent path ends with '.settings.link' or contains '.link.' treat as link url
                    $pathStr = implode('.', $keyPath);
                    if (preg_match('~(^|\.)link(\.|$)~', $pathStr)) {
                        $new = $this->localize_single_url($node['url'], $target_lang);
                        if ($new !== $node['url']) {
                            $node['url'] = $new;
                        }
                    }
                }
                foreach ($node as $k => &$v) {
                    $walker($v, array_merge($keyPath, [$k]));
                }
                unset($v);
                return;
            }
            if (is_string($node)) {
                // Only touch strings that look like HTML with anchors
                if (stripos($node, '<a') !== false && stripos($node, 'href=') !== false) {
                    $newHtml = $this->localize_hrefs_in_html($node, $target_lang);
                    if (is_string($newHtml) && $newHtml !== $node) {
                        $node = $newHtml;
                    }
                }
                return;
            }
        };
        $walker($data, []);
    }

    /**
     * Split translated content into parts matching the number of original elements
     */
    private function split_translated_content($translated_text, $expected_parts) {
        AITRFOEL_Logger::log("Splitting translated content into $expected_parts parts", 'debug');
        
        // Try different splitting strategies
        
        // Strategy 1: Split by double newlines (paragraph breaks)
        $parts = preg_split('/\n\s*\n/', trim($translated_text));
        $parts = array_filter(array_map('trim', $parts)); // Remove empty parts
        
        if (count($parts) == $expected_parts) {
            AITRFOEL_Logger::log("Split by paragraph breaks successful", 'debug');
            return array_values($parts);
        }
        
        // Strategy 2: Split by single newlines
        if (count($parts) < $expected_parts) {
            $parts = preg_split('/\n/', trim($translated_text));
            $parts = array_filter(array_map('trim', $parts));
            
            if (count($parts) == $expected_parts) {
                AITRFOEL_Logger::log("Split by single newlines successful", 'debug');
                return array_values($parts);
            }
        }
        
        // Strategy 3: Split by sentences (periods followed by space/newline)
        if (count($parts) < $expected_parts) {
            $parts = preg_split('/\.\s*(?=[A-Z])|\n/', trim($translated_text));
            $parts = array_filter(array_map(function($part) {
                $part = trim($part);
                // Re-add period if it was removed by splitting
                if (!empty($part) && !preg_match('/[.!?]$/', $part)) {
                    $part .= '.';
                }
                return $part;
            }, $parts));
            
            if (count($parts) >= $expected_parts) {
                AITRFOEL_Logger::log("Split by sentences successful", 'debug');
                return array_slice(array_values($parts), 0, $expected_parts);
            }
        }
        
        // Strategy 4: If we have more parts than expected, try to merge some
        if (count($parts) > $expected_parts) {
            $merged_parts = [];
            $parts_per_expected = ceil(count($parts) / $expected_parts);
            
            for ($i = 0; $i < $expected_parts; $i++) {
                $start = $i * $parts_per_expected;
                $end = min(($i + 1) * $parts_per_expected, count($parts));
                $merged_parts[] = implode(' ', array_slice($parts, $start, $end - $start));
            }
            
            AITRFOEL_Logger::log("Merged parts strategy used", 'debug');
            return $merged_parts;
        }
        
        // Fallback: Just return the whole text as a single part for the first element
        AITRFOEL_Logger::log("Using fallback strategy - single part", 'debug');
        return [$translated_text];
    }
    
    /**
     * Create a new page with translated content
     * 
     * @param int $original_page_id Original page ID
     * @param array $translated_data Translated Elementor data
     * @param string $target_lang Target language
     * @param string $detected_lang Detected source language
     * @return int|WP_Error New page ID or error
     */
    private function create_translated_page($original_page_id, $translated_data, $target_lang, $detected_lang = 'en') {
        $original_page = get_post($original_page_id);
        
        if (!$original_page) {
            return new WP_Error('page_not_found', __('Original page not found.', 'ai-translator-for-elementor-and-polylang'));
        }
        
        // Translate page title with better fallback
        $api = AITRFOEL_OpenAI_API::get_instance();
        
        $translated_title = $api->translate_title($original_page->post_title, $target_lang, $detected_lang);
        
        if (is_wp_error($translated_title)) {
            // Better fallback: try to translate just the meaningful part
            $title_parts = explode('(', $original_page->post_title);
            $base_title = trim($title_parts[0]);
            
            // Try translating just the base title
            $base_translated = $api->translate_title($base_title, $target_lang, $detected_lang);
            if (!is_wp_error($base_translated)) {
                // Get language name for display
                $lang_names = [
                    'es' => 'Español', 'pt' => 'Português', 'fr' => 'Français', 
                    'de' => 'Deutsch', 'it' => 'Italiano', 'cs' => 'Čeština',
                    'ru' => 'Русский', 'pl' => 'Polski', 'uk' => 'Українська',
                    'zh' => '中文', 'ja' => '日本語', 'ko' => '한국어'
                ];
                $lang_display = $lang_names[$target_lang] ?? ucfirst($target_lang);
                $translated_title = $base_translated . ' (' . $lang_display . ')';
            } else {
                // Last resort fallback
                $translated_title = $original_page->post_title . ' (' . strtoupper($target_lang) . ')';
            }
        }
        
        // Create new page with intelligent status selection
        $target_status = get_option('aitrfoel_translated_page_status', 'draft');
        
        // If original page is published and setting allows, publish translation too
        if ($target_status === 'inherit' && $original_page->post_status === 'publish') {
            $target_status = 'publish';
        } elseif ($target_status === 'inherit') {
            $target_status = 'draft'; // Safe fallback
        }
        
        $new_page_args = [
            'post_title' => $translated_title,
            'post_content' => $original_page->post_content,
            'post_status' => $target_status,
            'post_type' => $original_page->post_type,
            'post_parent' => $original_page->post_parent,
            'menu_order' => $original_page->menu_order,
            'post_excerpt' => $original_page->post_excerpt,
        ];
        
        $new_page_id = wp_insert_post($new_page_args);
        
        if (is_wp_error($new_page_id)) {
            return $new_page_id;
        }
        
        // Update Elementor data
    update_post_meta($new_page_id, '_elementor_data', wp_slash(json_encode($translated_data)));
        update_post_meta($new_page_id, '_elementor_edit_mode', 'builder');
        
        // Copy other Elementor meta
        $elementor_meta_keys = [
            '_elementor_template_type',
            '_elementor_version',
            '_elementor_pro_version',
            '_elementor_page_settings',
            '_elementor_controls_usage'
        ];
        
        // Copy Astra theme meta fields to preserve page layout settings
        $astra_meta_keys = [
            '_wp_page_template',             // The base page template (e.g., 'default')
            'site-post-title',               // Controls page title visibility ('disabled')
            'ast-title-bar-display',         // Disables the title bar area ('disabled')
            'ast-featured-img',              // Disables the featured image ('disabled')
            'ast-site-content-layout',       // **CRITICAL**: Sets the page layout (e.g., 'full-width-container')
            'site-sidebar-layout',           // **CRITICAL**: Sets the sidebar layout ('no-sidebar')
            'ast-main-header-display',       // Header display setting
            'footer-sml-layout',             // Footer layout
            'theme-transparent-header-meta'  // Transparent header setting
        ];
        
        // Combine all meta keys to copy
        $all_meta_keys = array_merge($elementor_meta_keys, $astra_meta_keys);
        
        foreach ($all_meta_keys as $meta_key) {
            $meta_value = get_post_meta($original_page_id, $meta_key, true);
            if ($meta_value !== '') { // Copy even if false/0, but not if completely empty
                update_post_meta($new_page_id, $meta_key, $meta_value);
            }
        }
        
        // Handle multilingual plugin (Polylang or WPML) via abstraction layer
        $multilingual = AITRFOEL_Multilingual_Manager::get_instance();
        if ($multilingual->is_active()) {
            $plugin_name = $multilingual->get_plugin_display_name();
            AITRFOEL_Logger::log(
                sprintf('Calling %s integration for %d -> %d, target lang: %s', $plugin_name, $original_page_id, $new_page_id, $target_lang),
                'debug',
                $original_page_id
            );
            
            // Pass the 2-letter language code directly
            $result = $multilingual->setup_translation_relationship($original_page_id, $new_page_id, $target_lang);
            
            if (is_wp_error($result)) {
                AITRFOEL_Logger::log(
                    $plugin_name . ' setup FAILED: ' . $result->get_error_message() . ' (Code: ' . $result->get_error_code() . ')',
                    'error',
                    $original_page_id,
                    $new_page_id
                );
            } else {
                AITRFOEL_Logger::log(
                    sprintf('✓ %s integration successful for pages %d -> %d', $plugin_name, $original_page_id, $new_page_id),
                    'success',
                    $original_page_id,
                    $new_page_id
                );
            }
        } else {
            AITRFOEL_Logger::log('No multilingual plugin is active, skipping translation relationship setup', 'info', $original_page_id);
        }
        
        // Clear Elementor cache
        if (class_exists('\Elementor\Plugin')) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }
        
        // Post-creation processing for proper LiteSpeed integration
        $this->post_creation_processing($new_page_id);
        
        return $new_page_id;
    }

    /**
     * Post-creation processing for translated pages
     * Handles cache warming, HTML regeneration, and LiteSpeed optimizations
     * 
     * @param int $page_id Translated page ID
     * @return void
     */
    public function post_creation_processing($page_id) {
        AITRFOEL_Logger::log("Starting post-creation processing for page $page_id", 'info', $page_id);
        
        // Step 1: Force Elementor to regenerate HTML content
        if (class_exists('\Elementor\Plugin')) {
            try {
                // Get the document for this page
                $document = \Elementor\Plugin::$instance->documents->get($page_id);
                
                if ($document && !is_wp_error($document)) {
                    AITRFOEL_Logger::log("Regenerating Elementor HTML for page $page_id", 'info', $page_id);
                    
                    // Force save to regenerate HTML - this updates post_content
                    $document->save([]);
                    
                    // Alternative method for older Elementor versions
                    if (method_exists($document, 'get_main_post')) {
                        $post = $document->get_main_post();
                        if ($post) {
                            // Get the latest Elementor data and regenerate HTML
                            $elementor_data = get_post_meta($page_id, '_elementor_data', true);
                            if ($elementor_data) {
                                // Update post content with fresh HTML
                                $updated_content = $document->get_content();
                                wp_update_post([
                                    'ID' => $page_id,
                                    'post_content' => $updated_content
                                ]);
                                AITRFOEL_Logger::log("Updated post_content with regenerated HTML", 'info', $page_id);
                            }
                        }
                    }
                } else {
                    AITRFOEL_Logger::log("Could not get Elementor document for page $page_id", 'warning', $page_id);
                }
            } catch (Exception $e) {
                AITRFOEL_Logger::log("Elementor regeneration failed: " . $e->getMessage(), 'error', $page_id);
            }
        }
        
        // Step 2: Clear all relevant caches
        AITRFOEL_Logger::log("Clearing caches for page $page_id", 'info', $page_id);
        
        // Clear Elementor cache
        if (class_exists('\Elementor\Plugin')) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }
        
        // Clear LiteSpeed cache if available
        if (defined('LSCWP_V') && class_exists('LiteSpeed\Purge')) {
            try {
                // Purge specific page
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party LiteSpeed Cache hook
                do_action('litespeed_purge_single_post', $page_id);
                AITRFOEL_Logger::log("LiteSpeed cache purged for page $page_id", 'info', $page_id);
            } catch (Exception $e) {
                AITRFOEL_Logger::log("LiteSpeed cache purge failed: " . $e->getMessage(), 'warning', $page_id);
            }
        }
        
        // Step 3: REMOVED sleep(2) for performance - database consistency is instant
        // Note: Removed 2-second delay that was slowing down translations
        
        // Step 4: REMOVED cache warming for performance (saves 3-5 seconds per translation)
        // Reason: wp_remote_get() triggers new PHP process → Freemius initialization → 3-5s delay
        // Impact: LiteSpeed will cache page on first real visitor, which is acceptable
        // Note: Cache warming was adding 5-10% overhead to translation time
        AITRFOEL_Logger::log("Skipping cache warm-up for performance (cache will build on first visitor)", 'info', $page_id);
        
        // Step 5: Additional LiteSpeed-specific optimizations
        if (defined('LSCWP_V')) {
            AITRFOEL_Logger::log("Triggering LiteSpeed post-processing hooks", 'info', $page_id);
            
            // Trigger LiteSpeed's optimization pipeline
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party LiteSpeed Cache hook
            do_action('litespeed_cache_finalize');
            
            // Force image optimization check
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party LiteSpeed Cache hook
            do_action('litespeed_media_finalize', $page_id);
        }
        
        AITRFOEL_Logger::log("Post-creation processing completed for page $page_id", 'success', $page_id);
    }

    /**
     * Detect source language from strings
     * 
     * @param array $strings Array of strings to analyze
     * @return string|null Detected language code or null on failure
     */
    private function detect_source_language($strings, $page_id = null) {
        // Get the API instance (OpenAI only now)
        $api = AITRFOEL_OpenAI_API::get_instance();
        
        $sample_parts = [];
        $total_len = 0;
        foreach ($strings as $item) {
            if (!isset($item['original'])) continue;
            $t = wp_strip_all_tags($item['original']);
            if ($t === '') continue;
            $sample_parts[] = $t;
            $total_len += strlen($t);
            if ($total_len > 800) break; // enough sample
        }
        if (empty($sample_parts)) return null;
        $sample_text = implode("\n", $sample_parts);
        $detected = $api->detect_language($sample_text);
        if (is_wp_error($detected)) {
            AITRFOEL_Logger::log('Language auto-detect failed: ' . $detected->get_error_message(), 'warning', $page_id);
            return null;
        }
        AITRFOEL_Logger::log('Detected source language: ' . $detected, 'info', $page_id);
        return $detected;
    }

    /**
     * Get last translation statistics
     * 
     * @return array Translation statistics
     */
    public function get_last_translation_stats() {
        return $this->last_translation_stats ?? [
            'elements_translated' => 0,
            'word_count' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'estimated_cost' => 0,
            'cost_formatted' => '$0.0000',
            'is_estimated' => true,
            'cached_strings' => 0,
            'uncached_strings' => 0,
            'usage_records' => []
        ];
    }
    
    /**
     * Validate translation quality
     * 
     * @param array $translations Array of translations
     * @param string $target_lang Target language
     * @return array Array of quality issues
     */
    private function validate_translation_quality($translations, $target_lang) {
        $issues = [];
        $suspicious_count = 0;
        $total_count = count($translations);
        
        // Character set validation for different languages
        $charset_patterns = [
            'ru' => '/[а-яё]/ui',          // Cyrillic for Russian
            'cs' => '/[ěščřžýáíéúů]/ui',   // Czech specific characters
            'pt' => '/[ãõçáéíóúâêôà]/ui', // Portuguese specific characters
            'zh' => '/[\x{4e00}-\x{9fff}]/u', // Chinese characters
            'ar' => '/[\x{0600}-\x{06ff}]/u', // Arabic characters
            'ja' => '/[\x{3040}-\x{309f}\x{30a0}-\x{30ff}\x{4e00}-\x{9faf}]/u', // Japanese
            'ko' => '/[\x{ac00}-\x{d7af}]/u', // Korean Hangul
            'th' => '/[\x{0e00}-\x{0e7f}]/u', // Thai
            'hi' => '/[\x{0900}-\x{097f}]/u', // Hindi Devanagari
            'uk' => '/[а-яєіїґё]/ui',       // Ukrainian Cyrillic
            'bg' => '/[а-яьъ]/ui',         // Bulgarian Cyrillic
            'sr' => '/[а-яљњћџ]/ui',      // Serbian Cyrillic
            'el' => '/[α-ωάέήίόύώ]/ui',    // Greek
            'he' => '/[\x{0590}-\x{05ff}]/u', // Hebrew
        ];
        
        // Language similarity patterns to detect non-translations
        $similarity_check = [
            'es' => ['en' => 0.7],  // Spanish-English similarity threshold
            'pt' => ['es' => 0.8, 'en' => 0.6],  // Portuguese similarities
            'it' => ['es' => 0.7, 'en' => 0.6],  // Italian similarities
            'fr' => ['en' => 0.6],  // French-English
        ];
        
        foreach ($translations as $path => $translated_text) {
            if (empty($translated_text) || !is_string($translated_text)) {
                $issues[] = "Empty translation at path: $path";
                $suspicious_count++;
                continue;
            }
            
            // Check if translation is too similar to original (possible untranslated)
            // This would require original text comparison - skip for now
            
            // Check for appropriate character set
            if (isset($charset_patterns[$target_lang])) {
                $pattern = $charset_patterns[$target_lang];
                $clean_text = wp_strip_all_tags($translated_text);
                
                if (strlen($clean_text) > 3 && !preg_match($pattern, $clean_text)) {
                    $issues[] = "Translation may not be in target language ($target_lang) at path: $path";
                    $suspicious_count++;
                }
            }
            
            // Check for common translation failures
            if (strpos($translated_text, 'I cannot translate') !== false ||
                strpos($translated_text, 'Unable to translate') !== false ||
                strpos($translated_text, 'Error:') !== false ||
                strpos($translated_text, 'Sorry, I can\'t') !== false ||
                strpos($translated_text, 'Translation not available') !== false ||
                preg_match('/\b(error|failed|unable)\b/i', $translated_text)) {
                $issues[] = "Translation error response at path: $path";
                $suspicious_count++;
            }
            
            // Check for obvious non-translations (text too short after translation)
            $clean_original_estimate = strlen(wp_strip_all_tags($translated_text));
            if ($clean_original_estimate < 3 && strlen($translated_text) > 10) {
                $issues[] = "Suspiciously short translation at path: $path";
                $suspicious_count++;
            }
            
            // Check for HTML-only content (translations that lost all text)
            if (strlen(wp_strip_all_tags($translated_text)) === 0 && strlen($translated_text) > 0) {
                $issues[] = "Translation contains only HTML tags at path: $path";
                $suspicious_count++;
            }
        }
        
        // Overall quality check
        if ($total_count > 0) {
            $failure_rate = ($suspicious_count / $total_count) * 100;
            if ($failure_rate > 30) {
                $issues[] = "High failure rate: {$failure_rate}% of translations may be problematic";
            } elseif ($failure_rate > 10) {
                $issues[] = "Moderate failure rate: {$failure_rate}% of translations flagged for review";
            }
            
            // Log quality summary
            if (empty($issues)) {
                AITRFOEL_Logger::log("Translation quality: EXCELLENT (no issues detected)", 'success');
            } elseif ($failure_rate < 10) {
                AITRFOEL_Logger::log("Translation quality: GOOD (minor issues: {$failure_rate}%)", 'info');
            } elseif ($failure_rate < 30) {
                AITRFOEL_Logger::log("Translation quality: FAIR (issues: {$failure_rate}%)", 'warning');
            } else {
                AITRFOEL_Logger::log("Translation quality: POOR (major issues: {$failure_rate}%)", 'error');
            }
        }
        
        return $issues;
    }

    /**
     * Public test method for debugging HTML restoration
     */
    public function test_html_restoration($original_html, $translated_text = null) {
        AITRFOEL_Logger::log("=== HTML Restoration Test Started ===", 'debug');
        AITRFOEL_Logger::log("Original HTML: " . $original_html, 'debug');
        
        // Clean the text first
        $cleaned = $this->clean_text_for_translation($original_html);
        AITRFOEL_Logger::log("Cleaned text: " . $cleaned, 'debug');
        
        // If no translated text provided, use the cleaned text as mock translation
        if ($translated_text === null) {
            $translated_text = $cleaned;
        }
        
        AITRFOEL_Logger::log("Translated text: " . $translated_text, 'debug');
        
        // Restore HTML (now works with hash-based storage)
        $restored = $this->restore_html_formatting($translated_text, $original_html);
        AITRFOEL_Logger::log("Restored HTML: " . $restored, 'debug');
        AITRFOEL_Logger::log("=== HTML Restoration Test Ended ===", 'debug');
        
        return [
            'original' => $original_html,
            'cleaned' => $cleaned,
            'translated' => $translated_text,
            'restored' => $restored
        ];
    }

    /**
     * Track translation failure with analytics
     * 
     * @param string $error_code Error code
     * @param string $error_message Error message
     * @param float|null $start_time Translation start time for duration calculation
     */
    private function track_translation_failure($error_code, $error_message, $start_time = null) {
        if (!function_exists('aitrfoel_analytics')) {
            return;
        }
        
        $duration = $start_time ? round(microtime(true) - $start_time, 2) : 0;
        
        aitrfoel_analytics()->track_translation_failed($error_code, $error_message);
        aitrfoel_analytics()->track_translation_completed([
            'duration' => $duration,
            'word_count' => 0,
            'widget_count' => 0,
            'success' => false,
            'error_type' => $error_code,
            'mode' => 'single',
        ]);
    }

}
