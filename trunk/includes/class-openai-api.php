<?php
/**
 * OpenAI API Integration Class
 * Handles all communication with OpenAI API for translations
 */

if (!defined('ABSPATH')) exit;

class AITRFOEL_OpenAI_API {
    private static $instance = null;
    private $api_key;
    private $model;
    private $max_retries = 3;
    private $retry_delay = 500; // milliseconds - reduced from 1000ms for better performance
    private $usage_session = [
        'records' => [],
        'total_prompt_tokens' => 0,
        'total_completion_tokens' => 0,
        'total_tokens' => 0,
    ];
    
    const API_ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    const MEEPTECH_TRANSLATE_ENDPOINT = 'https://meeptech.com/api/eat/translate';
    
    /**
     * Available OpenAI models
     */
    const MODELS = [
        'gpt-4o' => 'GPT-4o (Best quality, fastest)',
        'gpt-4o-mini' => 'GPT-4o Mini (Fast, cost-effective)',
        'gpt-4-turbo' => 'GPT-4 Turbo (High quality)',
        'gpt-3.5-turbo' => 'GPT-3.5 Turbo (Fast, economical)'
    ];

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // We always use MeepTech server - no need for local OpenAI key
        $this->api_key = null;
        $this->model = 'gpt-4o-mini'; // Model used on MeepTech server
        $this->reset_usage_session();
    }
    
    /**
     * Check if we should use MeepTech server for translations
     * Always return true - we only use MeepTech server
     */
    private function should_use_meeptech() {
        return true;
    }

    /**
     * Reset usage counters for a fresh aggregation cycle
     */
    public function reset_usage_session() {
        $this->usage_session = [
            'records' => [],
            'total_prompt_tokens' => 0,
            'total_completion_tokens' => 0,
            'total_tokens' => 0,
        ];
    }

    /**
     * Obtain aggregated usage data captured during the current session
     */
    public function get_usage_summary($include_records = true) {
        $summary = [
            'prompt_tokens' => $this->usage_session['total_prompt_tokens'],
            'completion_tokens' => $this->usage_session['total_completion_tokens'],
            'total_tokens' => $this->usage_session['total_tokens'],
            'records_count' => count($this->usage_session['records'])
        ];

        if ($include_records) {
            $summary['records'] = $this->usage_session['records'];
        }

        return $summary;
    }

    /**
     * Store usage metrics for later aggregation/logging
     */
    private function record_usage_metrics(?array $usage, array $meta = [], $attempt = 1) {
        if (empty($usage)) {
            return;
        }

        $prompt_tokens = (int) ($usage['prompt_tokens'] ?? 0);
        $completion_tokens = (int) ($usage['completion_tokens'] ?? 0);
        $total_tokens = (int) ($usage['total_tokens'] ?? ($prompt_tokens + $completion_tokens));

        $meta_for_record = $meta;
        unset($meta_for_record['track_usage']);

        $record = [
            'prompt_tokens' => $prompt_tokens,
            'completion_tokens' => $completion_tokens,
            'total_tokens' => $total_tokens,
            'model' => $usage['model'] ?? ($meta['model'] ?? $this->model),
            'operation' => $meta['operation'] ?? 'unknown',
            'attempt' => (int) $attempt,
            'timestamp' => current_time('timestamp'),
            'raw_usage' => $usage,
            'input_characters' => $meta['input_characters'] ?? 0,
            'input_symbols' => $meta['input_symbols'] ?? 0,
            'is_estimated' => !empty($meta['is_estimated'])
        ];

        if (!empty($meta_for_record)) {
            $record['meta'] = $meta_for_record;
        }

        $this->usage_session['records'][] = $record;
        $this->usage_session['total_prompt_tokens'] += $prompt_tokens;
        $this->usage_session['total_completion_tokens'] += $completion_tokens;
        $this->usage_session['total_tokens'] += $total_tokens;

        if (class_exists('AITRFOEL_Logger')) {
            $context = '';
            if (!empty($meta_for_record)) {
                $encoded_meta = function_exists('wp_json_encode') ? wp_json_encode($meta_for_record) : json_encode($meta_for_record);
                if (!empty($encoded_meta)) {
                    $context = ' meta=' . $encoded_meta;
                }
            }

            $log_message = sprintf(
                '[usage] OpenAI %s (attempt %d): prompt=%d, completion=%d, total=%d%s',
                $record['operation'],
                $record['attempt'],
                $prompt_tokens,
                $completion_tokens,
                $total_tokens,
                $context
            );

            AITRFOEL_Logger::log($log_message, 'info');
            
            // Also log the session totals
            AITRFOEL_Logger::log(sprintf(
                '[usage session] Total so far: prompt=%d, completion=%d, total=%d',
                $this->usage_session['total_prompt_tokens'],
                $this->usage_session['total_completion_tokens'],
                $this->usage_session['total_tokens']
            ), 'info');
        }
    }
    
    /**
     * Test API connection
     */
    public function test_connection() {
        $result = $this->translate_text('Hello', 'Spanish');
        return !is_wp_error($result);
    }

    /**
     * Translate single text
     */
    public function translate_text($text, $target_lang, $source_lang = 'auto') {
        // Always use MeepTech server
        return $this->translate_via_meeptech($text, $target_lang, $source_lang);
    }
    
    /**
     * Translate text via MeepTech server proxy
     */
	private function translate_via_meeptech($text, $target_lang, $source_lang = 'auto') {
		if (empty(trim($text))) {
			return $text;
		}
		
		// Get cached license info (only fetch once per request)
		static $license_info_cache = null;
		if ($license_info_cache === null) {
			$license_manager = AITRFOEL_License_Manager::get_instance();
			$license_info_cache = $license_manager->get_license_status();
		}
		
		$license_key = $license_info_cache['license_key'];
		$site_url = home_url();
		$plan = $license_info_cache['plan'];        // Map language codes to full names for MeepTech API
        $source_language = ($source_lang === 'auto') ? 'auto' : AITRFOEL_Language_Mapper::get_language_name($source_lang);
        $target_language = AITRFOEL_Language_Mapper::get_language_name($target_lang);
        
        // Get language-specific instructions for rare/problematic languages
        $lang_instructions = AITRFOEL_Language_Mapper::get_language_specific_instructions($target_lang);
        
        // Log what we're sending
        if (class_exists('AITRFOEL_Logger')) {
            AITRFOEL_Logger::log('Sending to MeepTech server - Text length: ' . strlen($text) . ' chars, ' . $source_language . ' → ' . $target_language, 'debug');
            if (!empty($lang_instructions)) {
                AITRFOEL_Logger::log('Including language-specific instructions for: ' . $target_lang, 'debug');
            }
        }
        
        $body = [
            'text' => $text,
            'source_language' => $source_language,
            'target_language' => $target_language,
            'target_lang_code' => $target_lang, // Include original code for server-side handling
            'language_instructions' => $lang_instructions, // Special instructions for rare languages
            'site_url' => $site_url,
            'plan' => $plan,
            'license_key' => $license_key
        ];
        
        $args = [
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($body),
            'timeout' => 120,
        ];
        
        $response = wp_remote_post(self::MEEPTECH_TRANSLATE_ENDPOINT, $args);
        
        if (is_wp_error($response)) {
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log('MeepTech translation error: ' . $response->get_error_message(), 'error');
            }
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        if ($response_code !== 200 || !isset($data['success']) || !$data['success']) {
            $error_message = isset($data['error']) ? $data['error'] : 'MeepTech translation failed';
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log('MeepTech translation failed: ' . $error_message, 'error');
            }
            return new WP_Error('meeptech_error', $error_message);
        }
        
        if (!isset($data['translated_text'])) {
            return new WP_Error('meeptech_error', 'No translation returned from server');
        }
        
        // Log success
        if (class_exists('AITRFOEL_Logger')) {
            AITRFOEL_Logger::log('MeepTech translation successful - Words used: ' . ($data['words_used'] ?? 'unknown'), 'debug');
        }
        
        $translated = $data['translated_text'];
        
        // Clean up AI artifacts that sometimes appear in translations
        $translated = $this->cleanup_ai_artifacts($translated);
        
        return $translated;
    }
    
    /**
     * Clean up AI artifacts from translation responses
     * Removes instruction text, wrapper tags, etc.
     */
    private function cleanup_ai_artifacts($text) {
        if (!is_string($text) || $text === '') return $text;
        
        // Remove AI instruction artifacts that sometimes appear
        $ai_artifacts = [
            '/^Translating\s+the\s+text\s+while\s+preserving[^:]*:\s*/iu',
            '/^Here\s+is\s+the\s+translation[^:]*:\s*/iu',
            '/^Translation[^:]*:\s*/iu',
            '/^Translated\s+text[^:]*:\s*/iu',
            '/^The\s+translation[^:]*:\s*/iu',
            '/^Below\s+is\s+the\s+translation[^:]*:\s*/iu',
            '/^Here\'s\s+the\s+translation[^:]*:\s*/iu',
        ];
        foreach ($ai_artifacts as $pattern) {
            $text = preg_replace($pattern, '', $text);
        }
        
        // Strip <translation>...</translation> wrapper tags
        $text = preg_replace('#<translation[^>]*>\s*#iu', '', $text);
        $text = preg_replace('#\s*</translation>#iu', '', $text);
        
        // Strip markdown code blocks
        $text = preg_replace('/^```[a-z]*\s*/i', '', $text);
        $text = preg_replace('/\s*```$/i', '', $text);
        
        return trim($text);
    }
    
    /**
     * Translate multiple texts in batch
     */
    public function translate_batch($texts, $target_lang, $source_lang = 'auto') {
        // Always use MeepTech server for batch translation
        return $this->translate_batch_via_meeptech($texts, $target_lang, $source_lang);
    }
    
    /**
     * Translate multiple texts via MeepTech server in batch
     */
    private function translate_batch_via_meeptech($texts, $target_lang, $source_lang = 'auto') {
        if (empty($texts)) {
            return [];
        }
        
        // Normalize input texts to NFC if possible
        if (class_exists('Normalizer')) {
            foreach ($texts as $i => $t) {
                if (is_string($t)) {
                    $texts[$i] = Normalizer::normalize($t, Normalizer::FORM_C);
                }
            }
        }
        
        // Get cached license info (only fetch once per request)
        static $license_info_cache = null;
        if ($license_info_cache === null) {
            $license_manager = AITRFOEL_License_Manager::get_instance();
            $license_info_cache = $license_manager->get_license_status();
        }
        
        $license_key = $license_info_cache['license_key'];
        $site_url = home_url();
        $plan = $license_info_cache['plan'];
        
        // Map language codes to full names
        $source_language = ($source_lang === 'auto') ? 'auto' : AITRFOEL_Language_Mapper::get_language_name($source_lang);
        $target_language = AITRFOEL_Language_Mapper::get_language_name($target_lang);
        
        // Get language-specific instructions for rare/problematic languages
        $lang_instructions = AITRFOEL_Language_Mapper::get_language_specific_instructions($target_lang);
        
        // Send batch request to MeepTech
        $body = [
            'texts' => $texts,
            'source_language' => $source_language,
            'target_language' => $target_language,
            'target_lang_code' => $target_lang, // Include original code for server-side handling
            'language_instructions' => $lang_instructions, // Special instructions for rare languages
            'site_url' => $site_url,
            'plan' => $plan,
            'license_key' => $license_key
        ];
        
        AITRFOEL_Logger::log('Sending batch to MeepTech server - ' . count($texts) . ' texts, ' . $source_language . ' → ' . $target_language . (!empty($lang_instructions) ? ' (with special instructions)' : ''), 'debug');
        
        $args = [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($body),
            'timeout' => 120,
        ];
        
        $response = wp_remote_post(self::MEEPTECH_TRANSLATE_ENDPOINT . '/batch', $args);
        
        if (is_wp_error($response)) {
            AITRFOEL_Logger::log('MeepTech batch failed: ' . $response->get_error_message() . ', falling back to individual', 'warning');
            
            // Track API error with PostHog
            if (function_exists('aitrfoel_analytics')) {
                aitrfoel_analytics()->capture_llm_generation([
                    'model' => 'gpt-4o-mini',
                    'success' => false,
                    'error' => $response->get_error_message(),
                ]);
            }
            
            // Fallback to individual translations
            return $this->fallback_individual_translation($texts, $target_lang, $source_lang);
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        // Enhanced logging for debugging
        AITRFOEL_Logger::log('MeepTech batch response - Status: ' . $response_code . ', Body length: ' . strlen($response_body), 'debug');
        if ($response_code !== 200) {
            AITRFOEL_Logger::log('MeepTech batch response body: ' . substr($response_body, 0, 500), 'debug');
        }
        
        if ($response_code !== 200 || !isset($data['success']) || !$data['success']) {
            $error_msg = isset($data['error']) ? $data['error'] : 'Unknown error';
            
            // Log detailed error info if available
            if (isset($data['words_used']) && isset($data['word_limit'])) {
                AITRFOEL_Logger::log('MeepTech batch limit info - Used: ' . $data['words_used'] . ', Limit: ' . $data['word_limit'] . ', Plan: ' . ($data['plan'] ?? 'unknown'), 'debug');
            }
            
            AITRFOEL_Logger::log('MeepTech batch error: ' . $error_msg . ', falling back to individual', 'warning');
            // Fallback to individual translations
            return $this->fallback_individual_translation($texts, $target_lang, $source_lang);
        }
        
        AITRFOEL_Logger::log('MeepTech batch successful - Words used: ' . ($data['words_used'] ?? 'unknown'), 'debug');
        
        // Track LLM usage with PostHog Analytics
        if (function_exists('aitrfoel_analytics')) {
            $tokens_used = $data['tokens_used'] ?? ($data['words_used'] ?? 0) * 1.3; // Estimate tokens if not provided
            aitrfoel_analytics()->capture_llm_generation([
                'model' => $data['model'] ?? 'gpt-4o-mini',
                'input_tokens' => isset($data['input_tokens']) ? $data['input_tokens'] : round($tokens_used * 0.6),
                'output_tokens' => isset($data['output_tokens']) ? $data['output_tokens'] : round($tokens_used * 0.4),
                'latency_ms' => isset($data['latency_ms']) ? $data['latency_ms'] : 0,
                'success' => true,
            ]);
        }
        
        // Server might return 'translations' or 'translated_texts'
        $translated_texts = $data['translations'] ?? $data['translated_texts'] ?? [];
        
        // Normalize outputs to NFC
        if (class_exists('Normalizer')) {
            foreach ($translated_texts as $i => $t) {
                if (is_string($t)) {
                    $translated_texts[$i] = Normalizer::normalize($t, Normalizer::FORM_C);
                }
            }
        }
        
        // Map translations back to original texts
        // Server returns numeric array, but we need original => translated mapping
        $result = [];
        foreach ($texts as $index => $original_text) {
            if (isset($translated_texts[$index])) {
                $result[$original_text] = $translated_texts[$index];
            }
        }
        
        return $result;
    }
    
    /**
     * Detect language of given text
     */
    /**
     * Detect language of given text
     * Note: With MeepTech proxy, language detection happens server-side
     */
    public function detect_language($text) {
        // Language detection not needed for MeepTech - server handles it
        // Return 'auto' to let server detect
        return 'auto';
    }
    
    /**
     * Build translation prompt
     */
    private function build_translation_prompt($text, $target_lang, $source_lang = 'auto') {
        // Use comprehensive language mapper for explicit language names
        $target_full = AITRFOEL_Language_Mapper::get_language_name($target_lang);
        $source_full = ($source_lang !== 'auto') ? AITRFOEL_Language_Mapper::get_language_name($source_lang) : null;
        
        // Build source language instructions with explicit names
        $source_instructions = '';
        if ($source_lang === 'ru') {
            $source_instructions = "IMPORTANT: The source text is in RUSSIAN (Cyrillic script). You must translate FROM RUSSIAN to $target_full.\n";
            $source_instruction = "Translate from Russian (Cyrillic) to $target_full";
        } elseif ($source_lang === 'auto') {
            $source_instructions = "First, detect the source language (could be Russian/Cyrillic, English, or any other language).\n";
            $source_instruction = "Automatically detect the source language (pay attention to Cyrillic for Russian) and translate to $target_full";
        } else {
            $source_instruction = "Translate from $source_full to $target_full";
        }
        
        $lang_instructions = $this->get_language_instructions($target_lang);
        
        // Add language-specific instructions from mapper for problematic languages
        $lang_instructions .= AITRFOEL_Language_Mapper::get_language_specific_instructions($target_lang);
        
        // Add source language specific notes
        if ($source_lang === 'ru') {
            $lang_instructions .= "\n\nSOURCE LANGUAGE NOTE: The input text is in Russian. Make sure to correctly understand Russian words, idioms, and cultural context before translating.";
        }

        // Get exclusion settings
        $exclusion_instructions = $this->build_exclusion_instructions();
        
    $system_prompt = "You are a professional website translator. $source_instruction.

$source_instructions$lang_instructions$exclusion_instructions

CRITICAL TRANSLATION RULES:
1. Translate ONLY the meaningful text content - never translate HTML tags, CSS, or code
2. Preserve ALL HTML formatting exactly: <p>, <br>, <strong>, <em>, <a>, etc.
3. Keep placeholders unchanged: {name}, %s, %d, {{variable}}, [shortcode], and <ph id=\"n\"/>
4. Maintain the same tone: formal business text should stay formal
5. For proper nouns, brands, and technical terms - use appropriate localization
6. If source is Cyrillic/Russian, ensure you understand it correctly before translating
7. Do NOT use code fences, comments, or explanations in the output
8. Return ONLY valid JSON (no code fences), exactly: {\"t\":[\"<translated>\"]}";

        return [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user', 'content' => $text]
        ];
    }

    /**
     * Build exclusion instructions from settings
     */
    private function build_exclusion_instructions() {
        $settings = get_option('aitrfoel_translation_settings', []);
        $exclude_words = $settings['exclude_words'] ?? [];
        
        if (empty($exclude_words)) {
            return '';
        }
        
        $exclude_list = implode(', ', $exclude_words);
        $exclusion_text = "\n\nEXCLUSION RULES: Do NOT translate these specific words/terms: {$exclude_list}. Keep them exactly as they appear in the original text, maintaining their original language and capitalization.";
        
        return $exclusion_text;
    }
    
    /**
     * Build batch translation prompt
     */
    private function build_batch_translation_prompt($texts, $target_lang, $source_lang = 'auto') {
        // Use comprehensive language mapper for explicit language names
        $target_full = AITRFOEL_Language_Mapper::get_language_name($target_lang);
        $source_full = ($source_lang !== 'auto') ? AITRFOEL_Language_Mapper::get_language_name($source_lang) : null;
        
        // Source language handling
        if ($source_lang === 'ru') {
            $source_instruction = "Translate from Russian (Cyrillic) to $target_full";
            $source_instructions = "CRITICAL: All source texts are in RUSSIAN (Cyrillic script).";
        } elseif ($source_lang === 'auto') {
            $source_instruction = "Automatically detect the source language and translate to $target_full";
            $source_instructions = "First, detect the source language for each text.";
        } else {
            $source_instruction = "Translate from $source_full to $target_full";
            $source_instructions = '';
        }

        $lang_instructions = $this->get_language_instructions($target_lang);
        
        // Add language-specific instructions from mapper for problematic languages
        $lang_instructions .= AITRFOEL_Language_Mapper::get_language_specific_instructions($target_lang);
        
        $exclusion_instructions = $this->build_exclusion_instructions();

    $system_prompt = "You are a professional website translator. $source_instruction.
Rules:
1) Translate each string independently to $target_full.
2) Preserve placeholders <ph id=\"n\"/> exactly and in order.
3) Do NOT introduce or remove HTML tags beyond placeholders.
$source_instructions
$lang_instructions
$exclusion_instructions
Return ONLY valid JSON (no code fences, no comments), exactly: {\"t\":[\"...\", ... ]}";

        $user_prompt = json_encode(['src' => array_values($texts)], JSON_UNESCAPED_UNICODE);

        return [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user', 'content' => $user_prompt]
        ];
    }
    
    /**
     * Get language-specific instructions
     */
    private function get_language_instructions($lang) {
        $instructions = [
            'ru' => 'Use proper Russian grammar and natural phrasing. Maintain formal business tone.',
            'cs' => 'Use proper Czech (čeština) with correct diacritical marks (ě, š, č, ř, ž, ý, á, í, é, ú, ů). Maintain formal business tone.',
            'pt' => 'Use Portuguese (português) with proper grammar. Use formal business language appropriate for professional websites.',
            'es' => 'Use neutral Spanish suitable for all Spanish-speaking regions. Maintain professional business tone.',
            'fr' => 'Use standard French with proper grammar. Use formal "vous" form for business content.',
            'de' => 'Use standard German with proper grammar. Use formal "Sie" form for business content.',
            'it' => 'Use proper Italian with correct grammar and formal business tone.',
            'pl' => 'Use proper Polish with correct grammar and formal business language.',
            'uk' => 'Use proper Ukrainian (українська мова) with correct Cyrillic script. Use Ukrainian-specific vocabulary.',
            'zh' => 'Use simplified Chinese with proper grammar and formal business tone.',
            'ja' => 'Use proper Japanese with appropriate keigo (formal language) for business content.',
            'ko' => 'Use proper Korean with formal honorific language appropriate for business.',
            'ar' => 'Use Modern Standard Arabic with proper grammar and formal tone.',
            'tr' => 'Use proper Turkish with correct grammar and formal business language.',
            'hi' => 'Use proper Hindi (हिंदी) with Devanagari script and formal language.'
        ];
        
        return $instructions[$lang] ?? "Translate accurately to $lang using proper grammar and formal business tone.";
    }
    
    /**
     * Make API request with retries
     */
    private function make_api_request($messages, $expect_json = false, array $meta = []) {
        // Validate API key
        if (empty($this->api_key)) {
            return new WP_Error('no_api_key', 'OpenAI API key is not configured');
        }
        
        // Validate messages
        if (empty($messages) || !is_array($messages)) {
            return new WP_Error('invalid_messages', 'Invalid messages format');
        }
        
        // Count characters and symbols being sent to OpenAI
        if (!empty($meta['track_usage'])) {
            $total_chars = 0;
            $total_symbols = 0;
            
            foreach ($messages as $message) {
                if (isset($message['content']) && is_string($message['content'])) {
                    $content = $message['content'];
                    $total_chars += mb_strlen($content, 'UTF-8');
                    
                    // Count symbols (non-alphanumeric, non-space characters)
                    $length = mb_strlen($content, 'UTF-8');
                    for ($i = 0; $i < $length; $i++) {
                        $char = mb_substr($content, $i, 1, 'UTF-8');
                        if (!preg_match('/[\p{L}\p{N}\s]/u', $char)) {
                            $total_symbols++;
                        }
                    }
                }
            }
            
            // Store in meta for later use
            $meta['input_characters'] = $total_chars;
            $meta['input_symbols'] = $total_symbols;
            
            // Note: Tiktoken integration removed for server compatibility
            // Character-based estimation is used instead (chars/4 = tokens)
            
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log(sprintf(
                    'Sending to OpenAI: %d characters, %d symbols in %d messages',
                    $total_chars,
                    $total_symbols,
                    count($messages)
                ), 'info');
            }
        }
        
        $url = self::API_ENDPOINT;
        
        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0.2,
            'max_tokens' => 4000,
            'top_p' => 0.9,
            'frequency_penalty' => 0,
            'presence_penalty' => 0
        ];
        if ($expect_json) {
            $body['response_format'] = [ 'type' => 'json_object' ];
        }
        
        // Ensure JSON encoding succeeds
        $json_body = json_encode($body);
        if ($json_body === false) {
            return new WP_Error('json_error', 'Failed to encode request body: ' . json_last_error_msg());
        }
        
        $args = [
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ],
            'body' => $json_body,
            'timeout' => 120, // Increased from 60 to 120 seconds for large pages
        ];
        
        // Retry logic
        $attempt = 0;
        $last_error = null;
        
        while ($attempt < $this->max_retries) {
            $attempt++;
            
            $response = wp_remote_post($url, $args);
            
            if (!is_wp_error($response)) {
                $response_code = wp_remote_retrieve_response_code($response);
                $response_body = wp_remote_retrieve_body($response);
                $data = json_decode($response_body, true);
                
                // Success
                if ($response_code === 200 && isset($data['choices'])) {
                    if (!empty($meta['track_usage'])) {
                        if (isset($data['usage'])) {
                            $usage_meta = $meta;
                            $usage_meta['model'] = $data['model'] ?? $this->model;
                            if (isset($data['id'])) {
                                $usage_meta['response_id'] = $data['id'];
                            }
                            // Include the character and symbol counts we calculated earlier
                            if (isset($meta['input_characters'])) {
                                $usage_meta['input_characters'] = $meta['input_characters'];
                            }
                            if (isset($meta['input_symbols'])) {
                                $usage_meta['input_symbols'] = $meta['input_symbols'];
                            }
                            $this->record_usage_metrics($data['usage'], $usage_meta, $attempt);
                        } elseif (class_exists('AITRFOEL_Logger')) {
                            $operation = $meta['operation'] ?? 'unknown';
                            AITRFOEL_Logger::log('OpenAI usage block missing for ' . $operation . ' response', 'warning');
                            
                            // Still record estimated usage even if OpenAI doesn't provide it
                            if (isset($meta['input_characters'])) {
                                // Use tiktoken if available, otherwise fall back to character estimation
                                if (isset($meta['tiktoken_input_tokens'])) {
                                    $estimated_input_tokens = $meta['tiktoken_input_tokens'];
                                    $estimation_method = 'tiktoken';
                                } else {
                                    $estimated_input_tokens = max(1, intval($meta['input_characters'] / 4));
                                    $estimation_method = 'character_count';
                                }
                                
                                $estimated_usage = [
                                    'prompt_tokens' => $estimated_input_tokens,
                                    'completion_tokens' => intval($estimated_input_tokens * 0.8), // estimate output
                                    'total_tokens' => intval($estimated_input_tokens * 1.8)
                                ];
                                
                                $usage_meta = $meta;
                                $usage_meta['model'] = $this->model;
                                $usage_meta['is_estimated'] = true;
                                $usage_meta['estimation_method'] = $estimation_method;
                                
                                AITRFOEL_Logger::log(sprintf(
                                    'Recording estimated usage (%s method) due to missing OpenAI usage data. Input: %d tokens',
                                    $estimation_method,
                                    $estimated_input_tokens
                                ), 'info');
                                $this->record_usage_metrics($estimated_usage, $usage_meta, $attempt);
                            }
                        }
                    }
                    // Log truncated response for diagnostics
                    $log_chunk = substr((string)wp_remote_retrieve_body($response), 0, 2000);
                    AITRFOEL_Logger::log('OpenAI response (truncated): ' . $log_chunk, 'info');
                    return $data;
                }
                
                // Rate limit - wait and retry
                if ($response_code === 429) {
                    $wait_time = min($this->retry_delay * pow(2, $attempt - 1), 10000);
                    usleep($wait_time * 1000);
                    continue;
                }
                
                // API error
                $error_message = isset($data['error']['message']) 
                    ? $data['error']['message'] 
                    : 'Unknown API error (Code: ' . $response_code . ')';
                    
                $last_error = new WP_Error('api_error', $error_message);
                
                // Don't retry on client errors (4xx except 429)
                if ($response_code >= 400 && $response_code < 500 && $response_code !== 429) {
                    break;
                }
            } else {
                $last_error = $response;
            }
            
            // Wait before retry
            if ($attempt < $this->max_retries) {
                usleep($this->retry_delay * 1000 * $attempt);
            }
        }
        
        return $last_error ?: new WP_Error('api_error', 'Failed after ' . $this->max_retries . ' attempts');
    }
    
    /**
     * Extract translation from API response
     */
    private function extract_translation($response) {
        $raw = trim((string)($response['choices'][0]['message']['content'] ?? ''));
        if ($raw === '') {
            return '';
        }

        // Try direct JSON
        $j = json_decode($raw, true);
        if (is_array($j) && isset($j['t']) && is_array($j['t']) && isset($j['t'][0])) {
            return (string)$j['t'][0];
        }
        if (is_string($j)) {
            return $j;
        }

        // Try fenced JSON
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/si', $raw, $m)) {
            $inner = json_decode(trim($m[1]), true);
            if (is_array($inner) && isset($inner['t']) && is_array($inner['t']) && isset($inner['t'][0])) {
                return (string)$inner['t'][0];
            }
        }

        // Fallback: return raw text (model may have returned plain text)
        return $raw;
    }
    
    /**
     * Parse batch translation response
     */
    private function parse_batch_response($response, $expected_count) {
        $strict = (bool) get_option('aitrfoel_strict_json_batch', false);
        $raw = trim((string)($response['choices'][0]['message']['content'] ?? ''));
        if ($raw === '') {
            return new WP_Error('parse_error', __('Empty translation response', 'ai-translator-for-elementor-and-polylang'));
        }

        // Remove UTF-8 BOM if present
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

        // helper: decode and return t-array or flat array
        $decodeT = function(string $s) {
            $j = json_decode($s, true);
            if (is_array($j) && isset($j['t']) && is_array($j['t'])) return $j['t'];
            if (is_array($j) && array_values($j) === $j) return $j; // raw array
            return null;
        };

        // 1) Fast strict attempt
        $t = $decodeT($raw);
        if ($t !== null) {
            $out = array_slice($t, 0, $expected_count);
            while (count($out) < $expected_count) $out[] = '';
            return $out;
        }

        if ($strict) {
            return new WP_Error('parse_error', __('Invalid JSON format (strict)', 'ai-translator-for-elementor-and-polylang'));
        }

        // 2) Non-strict relaxations
        // 2.1) Strip code fences ```json ... ``` or ``` ... ```
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/si', $raw, $m)) {
            $raw2 = trim($m[1]);
            $t = $decodeT($raw2);
            if ($t !== null) {
                $out = array_slice($t, 0, $expected_count);
                while (count($out) < $expected_count) $out[] = '';
                return $out;
            }
        }

        // 2.2) Extract first JSON object/array from text (handles preambles)
        if (preg_match('/(\{(?:.|\s)*\}|\[(?:.|\s)*\])/U', $raw, $m)) {
            $raw3 = trim($m[1]);
            $t = $decodeT($raw3);
            if ($t !== null) {
                $out = array_slice($t, 0, $expected_count);
                while (count($out) < $expected_count) $out[] = '';
                return $out;
            }
        }

        // Log and fail
        return new WP_Error('parse_error', __('Failed to parse translation response', 'ai-translator-for-elementor-and-polylang'));
    }
    
    /**
     * Fallback to individual translations
     */
    private function fallback_individual_translation($texts, $target_lang, $source_lang) {
        $translations = [];
        
        foreach ($texts as $text) {
            $translation = $this->translate_text($text, $target_lang, $source_lang);
            
            if (is_wp_error($translation)) {
                $translations[$text] = $text; // Keep original on error
                AITRFOEL_Logger::log('Individual translation failed: ' . $translation->get_error_message(), 'error');
            } else {
                $translations[$text] = $translation; // Map original => translated
            }
            
            // PERFORMANCE: Reduced delay from 200ms to 50ms for faster batch processing
            usleep(50000); // 50ms delay
        }
        
        return $translations;
    }
    
    /**
     * Get usage statistics
     */
    public function get_usage_stats() {
        global $wpdb;
        
        $cache_table = $wpdb->prefix . 'aitrfoel_translation_cache';
        $total_cached = $wpdb->get_var("SELECT COUNT(*) FROM $cache_table");
        
        return [
            'cached_translations' => $total_cached,
            'model' => $this->model,
            'api_key_set' => !empty($this->api_key)
        ];
    }

    /**
     * Clean HTML tags and entities from text, typically used for page titles
     * 
     * @param string $text The text to clean
     * @return string Cleaned text
     */
    public function clean_html_from_text($text) {
        if (!is_string($text) || empty($text)) {
            return $text;
        }
        
        // First, decode HTML entities like &lt;p&gt; to <p>
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Then strip all HTML tags
        $cleaned = wp_strip_all_tags($decoded);
        
        // Clean up extra whitespace
        $cleaned = preg_replace('/\s+/', ' ', trim($cleaned));
        
        return $cleaned;
    }

    /**
     * Translate text and clean HTML for titles
     * 
     * @param string $text Text to translate
     * @param string $target_lang Target language code
     * @param string $source_lang Source language code
     * @param bool $clean_html Whether to clean HTML from result (default: true for titles)
     * @return string|WP_Error Translated and cleaned text
     */
    public function translate_title($text, $target_lang, $source_lang = 'auto', $clean_html = true) {
        $translated = $this->translate_text($text, $target_lang, $source_lang);
        
        if (is_wp_error($translated)) {
            return $translated;
        }
        
        if ($clean_html) {
            $translated = $this->clean_html_from_text($translated);
        }
        
        return $translated;
    }
}
