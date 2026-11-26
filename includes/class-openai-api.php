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

    /** Time budget forwarded from the translation engine for large-text chunking. */
    private static $budget_start   = 0.0;
    private static $budget_seconds = PHP_INT_MAX;

    /**
     * Called by the translation engine before the batch loop so that
     * translate_large_text() can honour the same time budget.
     */
    public static function set_time_budget(float $start, int $seconds): void {
        self::$budget_start   = $start;
        self::$budget_seconds = $seconds;
    }
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
     * Get dynamic MeepTech translate endpoint (supports AITRFOEL_API_BASE_URL constant and filters)
     *
     * @param string $subpath Optional subpath (e.g. 'batch', 'async/submit')
     * @return string
     */
    public static function get_translate_endpoint($subpath = '') {
        $base = defined('AITRFOEL_API_BASE_URL') ? AITRFOEL_API_BASE_URL : 'https://meeptech.com/api/eat';
        $base = apply_filters('aitrfoel_api_base_url', $base);
        $endpoint = rtrim($base, '/') . '/translate';
        if (!empty($subpath)) {
            $endpoint .= '/' . ltrim($subpath, '/');
        }
        return $endpoint;
    }

    /**
     * Available OpenAI models
     */
    const MODELS = [
        'gpt-4o' => 'OpenAI ChatGPT (Latest model, best quality)',
        'gpt-4o-mini' => 'OpenAI ChatGPT Fast (High speed, cost-effective)',
        'gpt-4-turbo' => 'OpenAI ChatGPT Pro (High quality)',
        'gpt-3.5-turbo' => 'OpenAI ChatGPT Standard (Economical)'
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
     * Character length above which a single string is split into chunks before
     * being sent to the API.  Keeping chunks ≤ 5 000 chars (~1 000 words) stays
     * well within the 60 s per-request timeout even for slow API responses.
     */
    const LARGE_TEXT_THRESHOLD = 5000;

    /**
     * Translate single text.
     * Strings longer than LARGE_TEXT_THRESHOLD are automatically split into
     * paragraph-level chunks, translated individually and reassembled so that
     * no single API call ever times out on giant legal / long-form content.
     */
    public function translate_text($text, $target_lang, $source_lang = 'auto') {
        if (empty(trim((string) $text))) {
            return $text;
        }

        // Check Translation Memory (TM) first
        $tm = class_exists('AITRFOEL_Translation_Memory') ? AITRFOEL_Translation_Memory::get_instance() : null;
        if ($tm && $tm->is_enabled()) {
            $cached = $tm->lookup_single($text, $target_lang, $source_lang);
            if ($cached !== null) {
                return $cached;
            }
        }

        $map = [];
        $protected_text = $this->protect_excluded_words($text, $map);

        if (strlen($protected_text) > self::LARGE_TEXT_THRESHOLD) {
            $translated = $this->translate_large_text($protected_text, $target_lang, $source_lang);
        } else {
            // Always use MeepTech server
            $translated = $this->translate_via_meeptech($protected_text, $target_lang, $source_lang);
        }

        if (is_wp_error($translated)) {
            return $translated;
        }

        $final = $this->restore_excluded_words($translated, $map);

        // Store into Translation Memory for future instant reuse
        if ($tm && !is_wp_error($final)) {
            $tm->save_single($text, $final, $target_lang, $source_lang);
        }

        return $final;
    }

    /**
     * Translate a string that is too long for a single API call.
     * Splits the content at HTML block-level tag boundaries, groups split
     * segments into chunks ≤ LARGE_TEXT_THRESHOLD chars, translates each
     * chunk and concatenates the results.
     */
    /**
     * Split a (placeholder-prepared) HTML string into chunks no larger than $max
     * characters, breaking only at block-level closing tags so each chunk remains a
     * set of complete elements. Mirrors the chunking inside translate_large_text()
     * so the async submit path produces identical chunks. Returns [] for empty
     * input, or a single-element array when no block tags are present.
     *
     * @param string $text
     * @param int    $max
     * @return string[]
     */
    public static function split_html_into_chunks($text, $max = self::LARGE_TEXT_THRESHOLD) {
        if (!is_string($text) || trim($text) === '') {
            return [];
        }
        $sentinel   = "\x00SPLIT\x00";
        $split_tags = 'p|h[1-6]|li|ul|ol|blockquote|table|thead|tbody|tfoot|tr|td|th|div|section|article|header|footer|figure|figcaption';
        $chunked    = preg_replace('/<\/(' . $split_tags . ')>/iS', '</$1>' . $sentinel, $text);
        $segments   = array_values(array_filter(
            explode($sentinel, $chunked),
            static fn($s) => trim($s) !== ''
        ));
        if (count($segments) <= 1) {
            return [$text];
        }
        $chunks  = [];
        $current = '';
        foreach ($segments as $segment) {
            if ($current !== '' && strlen($current) + strlen($segment) > $max) {
                $chunks[] = $current;
                $current  = $segment;
            } else {
                $current .= $segment;
            }
        }
        if ($current !== '') {
            $chunks[] = $current;
        }
        return $chunks;
    }

    private function translate_large_text($text, $target_lang, $source_lang = 'auto') {
        $max = self::LARGE_TEXT_THRESHOLD;

        // Insert a unique sentinel after every closing block-level tag so we
        // can explode cleanly while keeping each segment a complete element.
        $sentinel = "\x00SPLIT\x00";
        $split_tags = 'p|h[1-6]|li|ul|ol|blockquote|table|thead|tbody|tfoot|tr|td|th|div|section|article|header|footer|figure|figcaption';
        $chunked = preg_replace(
            '/<\/(' . $split_tags . ')>/iS',
            '</$1>' . $sentinel,
            $text
        );

        $segments = array_values(array_filter(
            explode($sentinel, $chunked),
            static fn($s) => trim($s) !== ''
        ));

        // If no block tags found, fall back to sentence-level splitting.
        if (count($segments) <= 1) {
            return $this->translate_large_text_plain($text, $target_lang, $source_lang, $max);
        }

        // Group segments into chunks that fit within $max chars.
        // If a single segment already exceeds $max (one very long <p>),
        // treat it as its own chunk — it is still far smaller than the
        // original 53 k+ string so the API will usually cope.
        $chunks = [];
        $current = '';
        foreach ($segments as $segment) {
            if ($current !== '' && strlen($current) + strlen($segment) > $max) {
                $chunks[] = $current;
                $current  = $segment;
            } else {
                $current .= $segment;
            }
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        $total_chunks   = count($chunks);
        $cache_key_base = 'aitrfoel_chunk_' . md5($text . $target_lang);

        AITRFOEL_Logger::log(
            sprintf('Large string (%d chars) split into %d chunks for translation', strlen($text), $total_chunks),
            'info'
        );

        // Translate chunks and reassemble.
        // Each completed chunk is stored in a short-lived transient so that a
        // timed-out request can be retried without re-doing already-done work.
        // Uncached chunks are sent to the server in PARALLEL (one /translate
        // request per chunk, fired concurrently) instead of one-at-a-time — this
        // is what collapses ~N×20s of sequential calls into ~one chunk's time.
        $translated_chunks    = []; // index => translated text (reassembled by ksort below)
        $chunk_timed_out      = false;
        $chunk_word_limit_hit = false;

        // Pass 1: load any chunks already completed in a previous (timed-out) pass.
        $to_translate = []; // index => chunk text (the uncached ones)
        foreach ($chunks as $i => $chunk) {
            $cached = get_transient($cache_key_base . '_' . $i);
            if ($cached !== false) {
                $translated_chunks[$i] = $cached;
                AITRFOEL_Logger::log(sprintf('Chunk %d/%d loaded from transient cache', $i + 1, $total_chunks), 'debug');
            } else {
                $to_translate[$i] = $chunk;
            }
        }

        // Pass 2: fire all uncached chunks in parallel (unless the time budget is nearly spent).
        if (!empty($to_translate)) {
            if (self::$budget_start > 0.0 && self::$budget_seconds < PHP_INT_MAX &&
                (microtime(true) - self::$budget_start) >= (self::$budget_seconds - 30)) {
                AITRFOEL_Logger::log(
                    sprintf('[TIMING] Time budget nearly exhausted — deferring %d/%d remaining chunks to retry pass.', count($to_translate), $total_chunks),
                    'warning'
                );
                $chunk_timed_out = true;
            } else {
                $results = $this->translate_chunks_parallel($to_translate, $target_lang, $source_lang);

                foreach ($to_translate as $i => $chunk) {
                    $result = isset($results[$i]) ? $results[$i] : new WP_Error('missing_chunk', 'No result returned for chunk');

                    if (is_wp_error($result)) {
                        $err_msg = $result->get_error_message();
                        AITRFOEL_Logger::log(
                            sprintf('Large text chunk %d/%d failed: %s — keeping original', $i + 1, $total_chunks, $err_msg),
                            'error'
                        );
                        // Word-limit errors affect every remaining chunk — abort now so the
                        // transient cache preserves already-translated chunks for the retry.
                        if ($result->get_error_code() === 'word_limit_exceeded' ||
                            stripos($err_msg, 'word limit') !== false || stripos($err_msg, 'limit exceeded') !== false) {
                            AITRFOEL_Logger::log(
                                sprintf('[WORD LIMIT] Aborting large-text chunking due to word limit. Retry will resume from cached chunks.'),
                                'warning'
                            );
                            $chunk_word_limit_hit = true;
                            break;
                        }
                        $translated_chunks[$i] = $chunk; // keep original on hard failure
                    } else {
                        set_transient($cache_key_base . '_' . $i, $result, HOUR_IN_SECONDS);
                        $translated_chunks[$i] = $result;
                    }
                }
            }
        }

        // Reassemble in original chunk order (parallel results may arrive keyed out of order).
        ksort($translated_chunks);

        if ($chunk_word_limit_hit) {
            // The completed-chunk transients ensure a retry only calls the API
            // for the remaining chunks, not the entire string again.
            return new WP_Error('word_limit_exceeded', 'Word limit reached mid-chunk; will resume on retry.');
        }

        if ($chunk_timed_out) {
            // Signal the engine to save a partial page and retry.
            // The completed-chunk transients ensure the retry only calls the API
            // for the remaining chunks, not the entire string again.
            return new WP_Error('chunk_timeout', 'Large text chunking timed out; will resume on retry.');
        }

        // All chunks done — clean up transients.
        for ($i = 0; $i < $total_chunks; $i++) {
            delete_transient($cache_key_base . '_' . $i);
        }

        return implode('', $translated_chunks);
    }

    /**
     * Sentence-level chunking fallback for plain text with no HTML block tags.
     */
    private function translate_large_text_plain($text, $target_lang, $source_lang, $max) {
        // Split on sentence-ending punctuation followed by whitespace.
        $sentences = preg_split('/(?<=[.!?…])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        $chunks  = [];
        $current = '';
        foreach ($sentences as $sentence) {
            $glue = $current !== '' ? ' ' : '';
            if ($current !== '' && strlen($current) + strlen($glue) + strlen($sentence) > $max) {
                $chunks[] = $current;
                $current  = $sentence;
            } else {
                $current .= $glue . $sentence;
            }
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        if (empty($chunks)) {
            // Absolute last resort: hard-split at byte boundaries.
            $chunks = str_split($text, $max);
        }

        $total_chunks   = count($chunks);
        $cache_key_base = 'aitrfoel_chunk_' . md5($text . $target_lang);

        AITRFOEL_Logger::log(
            sprintf('Large plain text (%d chars) split into %d sentence chunks for translation', strlen($text), $total_chunks),
            'info'
        );

        $translated_chunks = [];
        $chunk_timed_out      = false;
        $chunk_word_limit_hit = false;

        foreach ($chunks as $i => $chunk) {
            $tkey   = $cache_key_base . '_' . $i;
            $cached = get_transient($tkey);
            if ($cached !== false) {
                $translated_chunks[] = $cached;
                AITRFOEL_Logger::log(sprintf('Plain chunk %d/%d loaded from transient cache', $i + 1, $total_chunks), 'debug');
                continue;
            }

            if (self::$budget_start > 0.0 && self::$budget_seconds < PHP_INT_MAX &&
                (microtime(true) - self::$budget_start) >= (self::$budget_seconds - 30)) {
                AITRFOEL_Logger::log(
                    sprintf('[TIMING] Time budget nearly exhausted — stopping plain-text chunking after %d/%d chunks. Remaining chunks will be retried.', $i, $total_chunks),
                    'warning'
                );
                $chunk_timed_out = true;
                break;
            }

            $result = $this->translate_via_meeptech($chunk, $target_lang, $source_lang);
            if (is_wp_error($result)) {
                $err_msg = $result->get_error_message();
                AITRFOEL_Logger::log(
                    sprintf('Large plain text chunk %d/%d failed: %s — keeping original', $i + 1, $total_chunks, $err_msg),
                    'error'
                );
                if (stripos($err_msg, 'word limit') !== false || stripos($err_msg, 'limit exceeded') !== false) {
                    AITRFOEL_Logger::log(
                        sprintf('[WORD LIMIT] Aborting plain-text chunking after %d/%d chunks due to word limit. Retry will resume from this chunk.', $i, $total_chunks),
                        'warning'
                    );
                    $chunk_word_limit_hit = true;
                    break;
                }
                $translated_chunks[] = $chunk;
            } else {
                set_transient($tkey, $result, HOUR_IN_SECONDS);
                $translated_chunks[] = $result;
            }
        }

        if ($chunk_word_limit_hit) {
            return new WP_Error('word_limit_exceeded', 'Word limit reached mid-chunk; will resume on retry.');
        }

        if ($chunk_timed_out) {
            return new WP_Error('chunk_timeout', 'Large plain text chunking timed out; will resume on retry.');
        }

        for ($i = 0; $i < $total_chunks; $i++) {
            delete_transient($cache_key_base . '_' . $i);
        }

        return implode(' ', $translated_chunks);
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
        
        // Prepend general translation safety instructions
        $general_instructions = "CRITICAL: If a string is already in the target language or contains only numbers/symbols/short codes (e.g. 36K, 500+, 24/7, 100%), return it EXACTLY as-is. NEVER add explanations, commentary, or notes. Output ONLY the translated text.";
        if (!empty($lang_instructions)) {
            $lang_instructions = $general_instructions . "\n" . $lang_instructions;
        } else {
            $lang_instructions = $general_instructions;
        }

        // Append glossary rules
        $glossary_instructions = $this->get_glossary_instructions($target_lang);
        if (!empty($glossary_instructions)) {
            $lang_instructions .= "\n" . $glossary_instructions;
        }

        // Append Brand Voice & Tone rules (PRO Language Enhancer)
        $tone_instructions = $this->build_brand_tone_instructions($target_lang);
        if (!empty($tone_instructions)) {
            $lang_instructions .= $tone_instructions;
        }
        
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
            'license_key' => $license_key,
            'engine' => get_option('aitrfoel_ai_engine', 'auto'),
            'brand_tone_preset' => get_option('aitrfoel_brand_tone_preset', 'corporate'),
            'brand_tone_custom' => get_option('aitrfoel_brand_tone_custom', ''),
            'formality_mode'    => get_option('aitrfoel_formality_mode', 'auto'),
        ];
        
        $args = [
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($body),
            'timeout' => 60,
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

        // Post-process: detect chatty AI responses and keep original
        $translated = $this->sanitize_chatty_response($text, $translated);

        return $translated;
    }

    /**
     * Translate an ordered set of large-text chunks in PARALLEL via the MeepTech
     * /translate endpoint.
     *
     * Each chunk is sent exactly as translate_via_meeptech() would send it (one
     * chunk per request, plain `text` payload) so HTML fidelity is identical —
     * only the requests are fired concurrently instead of one-at-a-time. This is
     * the transport that turns ~N×20s of sequential chunk calls into roughly the
     * time of the single slowest chunk.
     *
     * Falls back to sequential translate_via_meeptech() when parallel transport
     * is unavailable, and retries any individually-failed chunk sequentially.
     * Word-limit errors are surfaced as a WP_Error('word_limit_exceeded') so the
     * caller can abort and resume from the transient cache.
     *
     * @param array  $chunks      Map of index => chunk text (keys preserved in result)
     * @param string $target_lang Target language code
     * @param string $source_lang Source language code or 'auto'
     * @return array Map of index => translated string | WP_Error (same keys as $chunks)
     */
    public function translate_chunks_parallel(array $chunks, $target_lang, $source_lang = 'auto') {
        if (empty($chunks)) {
            return [];
        }

        $requests_class = class_exists('WpOrg\Requests\Requests') ? 'WpOrg\Requests\Requests' : (class_exists('Requests') ? 'Requests' : null);

        // No parallel transport available, or a single chunk — just go sequential.
        if (!$requests_class || !method_exists($requests_class, 'request_multiple') || count($chunks) < 2) {
            $out = [];
            foreach ($chunks as $i => $chunk) {
                $out[$i] = $this->translate_via_meeptech($chunk, $target_lang, $source_lang);
            }
            return $out;
        }

        static $license_info_cache = null;
        if ($license_info_cache === null) {
            $license_info_cache = AITRFOEL_License_Manager::get_instance()->get_license_status();
        }

        $source_language = ($source_lang === 'auto') ? 'auto' : AITRFOEL_Language_Mapper::get_language_name($source_lang);
        $target_language = AITRFOEL_Language_Mapper::get_language_name($target_lang);
        $lang_instructions = AITRFOEL_Language_Mapper::get_language_specific_instructions($target_lang);
        $general_instructions = "CRITICAL: If a string is already in the target language or contains only numbers/symbols/short codes (e.g. 36K, 500+, 24/7, 100%), return it EXACTLY as-is. NEVER add explanations, commentary, or notes. Output ONLY the translated text.";
        $lang_instructions = !empty($lang_instructions) ? $general_instructions . "\n" . $lang_instructions : $general_instructions;

        $url     = self::MEEPTECH_TRANSLATE_ENDPOINT;
        $headers = ['Content-Type' => 'application/json'];

        $requests = [];
        $sources  = []; // index => normalized source text, for sanitize + sequential retry
        foreach ($chunks as $i => $chunk) {
            $text = $chunk;
            if (class_exists('Normalizer') && is_string($text)) {
                $text = Normalizer::normalize($text, Normalizer::FORM_C);
            }
            $sources[$i] = $text;
            $body = [
                'text'                  => $text,
                'source_language'       => $source_language,
                'target_language'       => $target_language,
                'target_lang_code'      => $target_lang,
                'language_instructions' => $lang_instructions,
                'site_url'              => home_url(),
                'plan'                  => $license_info_cache['plan'],
                'license_key'           => $license_info_cache['license_key'],
            ];
            $requests[$i] = [
                'url'     => $url,
                'headers' => $headers,
                'data'    => json_encode($body),
                'type'    => 'POST',
                'options' => ['timeout' => 90],
            ];
        }

        AITRFOEL_Logger::log(
            sprintf('Sending %d large-text chunks in PARALLEL to MeepTech (%s → %s)', count($requests), $source_language, $target_language),
            'info'
        );

        $responses = $requests_class::request_multiple($requests, []);

        $out = [];
        foreach ($requests as $i => $_unused) {
            $response = isset($responses[$i]) ? $responses[$i] : null;

            // Transport-level failure → retry this one chunk sequentially.
            if ($response instanceof \Exception || $response === null) {
                $msg = $response instanceof \Exception ? $response->getMessage() : 'No response';
                AITRFOEL_Logger::log(sprintf('Parallel chunk %d failed: %s — retrying sequentially', $i, $msg), 'warning');
                $out[$i] = $this->translate_via_meeptech($sources[$i], $target_lang, $source_lang);
                continue;
            }

            $status = isset($response->status_code) ? $response->status_code : 0;
            $data   = json_decode(isset($response->body) ? $response->body : '', true);

            if ($status !== 200 || !isset($data['success']) || !$data['success'] || !isset($data['translated_text'])) {
                $error_message = isset($data['error']) ? $data['error'] : ('Chunk request failed (HTTP ' . $status . ')');
                // Word-limit errors affect every remaining chunk — surface so the caller aborts and resumes.
                if (stripos($error_message, 'word limit') !== false || stripos($error_message, 'limit exceeded') !== false) {
                    $out[$i] = new WP_Error('word_limit_exceeded', $error_message);
                    continue;
                }
                AITRFOEL_Logger::log(sprintf('Parallel chunk %d error (%s): %s — retrying sequentially', $i, $status, $error_message), 'warning');
                $out[$i] = $this->translate_via_meeptech($sources[$i], $target_lang, $source_lang);
                continue;
            }

            $translated = $this->cleanup_ai_artifacts($data['translated_text']);
            $translated = $this->sanitize_chatty_response($sources[$i], $translated);
            $out[$i]    = $translated;
        }

        return $out;
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
         if (empty($texts)) {
             return [];
         }

         // Ensure texts is a sequential 0-indexed array
         $texts = array_values($texts);

         // Check Translation Memory (TM) first to save word quota and accelerate translation
         $tm = class_exists('AITRFOEL_Translation_Memory') ? AITRFOEL_Translation_Memory::get_instance() : null;
         $tm_hits = [];
         $untranslated_texts = [];

         if ($tm && $tm->is_enabled()) {
             $tm_lookup = $tm->lookup_batch($texts, $target_lang, $source_lang);
             $tm_hits = $tm_lookup['hits'] ?? [];
             $untranslated_texts = $tm_lookup['misses'] ?? [];
         } else {
             $untranslated_texts = $texts;
         }

         // If ALL strings were already resolved from Translation Memory, return immediately!
         if (empty($untranslated_texts)) {
             $results = [];
             foreach ($texts as $key => $orig_text) {
                 $results[$orig_text] = $tm_hits[$key] ?? $orig_text;
             }
             return $results;
         }

         $untranslated_keys = array_keys($untranslated_texts);
         $untranslated_values = array_values($untranslated_texts);

         $protected_texts = [];
         $maps = [];
         foreach ($untranslated_values as $seq_idx => $text) {
             $orig_key = $untranslated_keys[$seq_idx];
             $map = [];
             $protected_texts[$seq_idx] = $this->protect_excluded_words($text, $map);
             $maps[$seq_idx] = [
                 'orig_key' => $orig_key,
                 'map'      => $map,
             ];
         }

         // Always use MeepTech server for untranslated batch items (sequential 0-indexed array)
         $translated_batch = $this->translate_batch_via_meeptech(array_values($protected_texts), $target_lang, $source_lang);

         if (is_wp_error($translated_batch)) {
             return $translated_batch;
         }

         $results = [];
         $tm_to_save = [];

         // Map each original string back from either TM or API
         foreach ($texts as $key => $orig_text) {
             // 1) Resolved from Translation Memory
             if (isset($tm_hits[$key])) {
                 $results[$orig_text] = $tm_hits[$key];
                 continue;
             }

             // 2) Freshly translated via API
             $found_seq_idx = array_search($key, $untranslated_keys, true);
             if ($found_seq_idx !== false && isset($protected_texts[$found_seq_idx])) {
                 $prot = $protected_texts[$found_seq_idx];
                 if (isset($translated_batch[$prot])) {
                     $translated = $translated_batch[$prot];
                     if (is_wp_error($translated)) {
                         $results[$orig_text] = $translated;
                     } else {
                         $final_trans = $this->restore_excluded_words($translated, $maps[$found_seq_idx]['map'] ?? []);
                         $results[$orig_text] = $final_trans;
                         $tm_to_save[] = [
                             'source'     => $orig_text,
                             'translated' => $final_trans,
                         ];
                     }
                 }
             }
         }

         // Store new translations into Translation Memory for future instant reuse
        if ($tm && !empty($tm_to_save)) {
            $valid_tm = [];
            foreach ($tm_to_save as $item) {
                if ($item['source'] !== $item['translated'] || !preg_match('/[\p{L}]/u', $item['source'])) {
                    $valid_tm[] = $item;
                }
            }
            if (!empty($valid_tm)) {
                $tm->save_batch($valid_tm, $target_lang, $source_lang);
            }
        }

        return $results;
    }

    /**
     * Translate multiple batches in parallel using WordPress's bundled Requests library.
     * Falls back to sequential processing if parallel requests are unavailable.
     *
     * @param array $batches Array of text arrays (each sub-array is one batch)
     * @param string $target_lang Target language code
     * @param string $source_lang Source language code
     * @return array Combined original => translated mapping
     */
    public function translate_batches_parallel($batches, $target_lang, $source_lang = 'auto') {
        if (count($batches) < 2) {
            // Single batch — no benefit from parallel, use normal path
            return $this->translate_batch($batches[0], $target_lang, $source_lang);
        }

        // Resolve the Requests class (WP 6.2+ uses namespaced version)
        $requests_class = class_exists('WpOrg\Requests\Requests') ? 'WpOrg\Requests\Requests' : (class_exists('Requests') ? 'Requests' : null);
        if (!$requests_class || !method_exists($requests_class, 'request_multiple')) {
            // Fallback to sequential
            AITRFOEL_Logger::log('Parallel requests unavailable, falling back to sequential', 'warning');
            $combined = [];
            foreach ($batches as $batch) {
                $result = $this->translate_batch($batch, $target_lang, $source_lang);
                if (!is_wp_error($result)) {
                    $combined = array_merge($combined, $result);
                }
            }
            return $combined;
        }

        // Build common request parameters (license, languages, instructions)
        static $license_info_cache = null;
        if ($license_info_cache === null) {
            $license_manager = AITRFOEL_License_Manager::get_instance();
            $license_info_cache = $license_manager->get_license_status();
        }

        $source_language = ($source_lang === 'auto') ? 'auto' : AITRFOEL_Language_Mapper::get_language_name($source_lang);
        $target_language = AITRFOEL_Language_Mapper::get_language_name($target_lang);
        $lang_instructions = AITRFOEL_Language_Mapper::get_language_specific_instructions($target_lang);
        $general_instructions = "CRITICAL: If a string is already in the target language or contains only numbers/symbols/short codes (e.g. 36K, 500+, 24/7, 100%, ★, →), return it EXACTLY as-is. NEVER add explanations, commentary, or notes. Your output for each string must be ONLY the translated text (or the original if no translation is needed). Never say 'this text does not need translation' or similar.";
        $lang_instructions = !empty($lang_instructions) ? $general_instructions . "\n" . $lang_instructions : $general_instructions;

        // Append glossary rules
        $glossary_instructions = $this->get_glossary_instructions($target_lang);
        if (!empty($glossary_instructions)) {
            $lang_instructions .= "\n" . $glossary_instructions;
        }

        // Append Brand Voice & Tone rules (PRO Language Enhancer)
        $tone_instructions = $this->build_brand_tone_instructions($target_lang);
        if (!empty($tone_instructions)) {
            $lang_instructions .= $tone_instructions;
        }

        $url = self::MEEPTECH_TRANSLATE_ENDPOINT . '/batch';
        $headers = ['Content-Type' => 'application/json'];

        // Build parallel request definitions
        $requests = [];
        $batch_texts = []; // Keep reference to original texts per batch index
        $batch_maps = [];
        foreach ($batches as $idx => $texts) {
            // Normalize NFC
            if (class_exists('Normalizer')) {
                foreach ($texts as $i => $t) {
                    if (is_string($t)) {
                        $texts[$i] = Normalizer::normalize($t, Normalizer::FORM_C);
                    }
                }
            }
            $batch_texts[$idx] = $texts;

            // Protect excluded words
            $protected_texts = [];
            $maps = [];
            foreach ($texts as $i => $t) {
                $map = [];
                $protected_texts[$i] = $this->protect_excluded_words($t, $map);
                $maps[$i] = $map;
            }
            $batch_maps[$idx] = $maps;

            $body = [
                'texts' => $protected_texts,
                'source_language' => $source_language,
                'target_language' => $target_language,
                'target_lang_code' => $target_lang,
                'language_instructions' => $lang_instructions,
                'site_url' => home_url(),
                'plan' => $license_info_cache['plan'],
                'license_key' => $license_info_cache['license_key'],
                'engine' => get_option('aitrfoel_ai_engine', 'auto'),
                'brand_tone_preset' => get_option('aitrfoel_brand_tone_preset', 'corporate'),
                'brand_tone_custom' => get_option('aitrfoel_brand_tone_custom', ''),
                'formality_mode'    => get_option('aitrfoel_formality_mode', 'auto'),
            ];

            $requests[$idx] = [
                'url' => $url,
                'headers' => $headers,
                'data' => json_encode($body),
                'type' => 'POST',
                'options' => ['timeout' => 60],
            ];
        }

        AITRFOEL_Logger::log(
            sprintf('Sending %d batches in PARALLEL to MeepTech (%s → %s)', count($requests), $source_language, $target_language),
            'info'
        );

        // Fire all requests in parallel
        $responses = $requests_class::request_multiple($requests, []);

        // Process responses
        $combined = [];
        foreach ($responses as $idx => $response) {
            $texts = $batch_texts[$idx];

            if ($response instanceof \Exception || (is_object($response) && property_exists($response, 'success') && !$response->success)) {
                $error_msg = $response instanceof \Exception ? $response->getMessage() : 'Request failed';
                AITRFOEL_Logger::log("Parallel batch $idx failed: $error_msg, falling back to individual for this batch", 'warning');
                $fallback = $this->fallback_individual_translation($texts, $target_lang, $source_lang);
                $combined = array_merge($combined, $fallback);
                continue;
            }

            $status = $response->status_code;
            $data = json_decode($response->body, true);

            if ($status !== 200 || !isset($data['success']) || !$data['success']) {
                $error_msg = $data['error'] ?? 'Unknown error';
                AITRFOEL_Logger::log("Parallel batch $idx error ($status): $error_msg, falling back to individual", 'warning');
                $fallback = $this->fallback_individual_translation($texts, $target_lang, $source_lang);
                $combined = array_merge($combined, $fallback);
                continue;
            }

            AITRFOEL_Logger::log("Parallel batch $idx successful - Words used: " . ($data['words_used'] ?? 'unknown'), 'debug');

            // Track analytics
            if (function_exists('aitrfoel_analytics')) {
                $tokens_used = $data['tokens_used'] ?? ($data['words_used'] ?? 0) * 1.3;
                aitrfoel_analytics()->capture_llm_generation([
                    'model' => $data['model'] ?? 'gpt-4o-mini',
                    'input_tokens' => isset($data['input_tokens']) ? $data['input_tokens'] : round($tokens_used * 0.6),
                    'output_tokens' => isset($data['output_tokens']) ? $data['output_tokens'] : round($tokens_used * 0.4),
                    'latency_ms' => isset($data['latency_ms']) ? $data['latency_ms'] : 0,
                    'success' => true,
                ]);
            }

            $translated_texts = $data['translations'] ?? $data['translated_texts'] ?? [];

            // Normalize NFC
            if (class_exists('Normalizer')) {
                foreach ($translated_texts as $i => $t) {
                    if (is_string($t)) {
                        $translated_texts[$i] = Normalizer::normalize($t, Normalizer::FORM_C);
                    }
                }
            }

            // Map back to original => translated
            foreach ($texts as $index => $original_text) {
                if (isset($translated_texts[$index])) {
                    $restored = $this->restore_excluded_words($translated_texts[$index], $batch_maps[$idx][$index] ?? []);
                    $combined[$original_text] = $this->sanitize_chatty_response($original_text, $restored);
                }
            }
        }

        return $combined;
    }

    /**
     * Submit a batch translation as an async job on the proxy server.
     * Returns the proxy job ID string immediately (< 1 second), or WP_Error.
     *
     * @param array  $texts       Indexed array of plain-text strings to translate
     * @param string $target_lang Target language code
     * @param string $source_lang Source language code or 'auto'
     * @return string|WP_Error Proxy job ID string, or WP_Error on failure
     */
    public function submit_batch_async($texts, $target_lang, $source_lang = 'auto') {
        if (empty($texts)) {
            return new WP_Error('empty_texts', 'No texts to submit for translation.');
        }

        static $lic_cache = null;
        if ($lic_cache === null) {
            $lic_cache = AITRFOEL_License_Manager::get_instance()->get_license_status();
        }

        $source_language = ($source_lang === 'auto') ? 'auto' : AITRFOEL_Language_Mapper::get_language_name($source_lang);
        $target_language = AITRFOEL_Language_Mapper::get_language_name($target_lang);
        $instructions    = AITRFOEL_Language_Mapper::get_language_specific_instructions($target_lang);
        $general         = "CRITICAL: If a string is already in the target language or contains only numbers/symbols/short codes (e.g. 36K, 500+, 24/7, 100%, ★, →), return it EXACTLY as-is. NEVER add explanations. Output ONLY the translated text.";
        $instructions    = !empty($instructions) ? $general . "\n" . $instructions : $general;

        // Append glossary rules
        $glossary_instructions = $this->get_glossary_instructions($target_lang);
        if (!empty($glossary_instructions)) {
            $instructions .= "\n" . $glossary_instructions;
        }

        // Append Brand Voice & Tone rules (PRO Language Enhancer)
        $tone_instructions = $this->build_brand_tone_instructions($target_lang);
        if (!empty($tone_instructions)) {
            $instructions .= $tone_instructions;
        }

        $protected_texts = [];
        $maps = [];
        $index = 0;
        foreach ($texts as $key => $text) {
            $map = [];
            $protected_texts[$index] = $this->protect_excluded_words($text, $map);
            $maps[$index] = $map;
            $index++;
        }

        $response = wp_remote_post(self::MEEPTECH_TRANSLATE_ENDPOINT . '/async/submit', [
            'method'  => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'texts'                 => $protected_texts,
                'source_language'       => $source_language,
                'target_language'       => $target_language,
                'target_lang_code'      => $target_lang,
                'language_instructions' => $instructions,
                'site_url'              => home_url(),
                'plan'                  => $lic_cache['plan'],
                'license_key'           => $lic_cache['license_key'],
                'engine'                => get_option('aitrfoel_ai_engine', 'auto'),
                'brand_tone_preset'     => get_option('aitrfoel_brand_tone_preset', 'corporate'),
                'brand_tone_custom'     => get_option('aitrfoel_brand_tone_custom', ''),
                'formality_mode'        => get_option('aitrfoel_formality_mode', 'auto'),
            ]),
            'timeout' => 15, // submit only queues the job — should respond in < 5s
        ]);

        if (is_wp_error($response)) {
            AITRFOEL_Logger::log('Async submit error: ' . $response->get_error_message(), 'error');
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($data['job_id'])) {
            $msg = $data['error'] ?? 'Async submit failed (HTTP ' . $code . ')';
            AITRFOEL_Logger::log('Async submit failed: ' . $msg, 'error');
            return new WP_Error('async_submit_failed', $msg);
        }

        AITRFOEL_Logger::log('Async job submitted: ' . $data['job_id'] . ' (' . count($texts) . ' strings, ' . $source_language . ' → ' . $target_language . ')', 'info');
        set_transient('aitrfoel_async_maps_' . $data['job_id'], $maps, HOUR_IN_SECONDS);
        return $data['job_id'];
    }

    /**
     * Check status of an async translation job.
     *
     * @param string $job_id Proxy job ID returned by submit_batch_async()
     * @return array|WP_Error Array {status: 'pending'|'processing'|'done'|'error', translations?: string[], error?: string}
     */
    public function get_async_job_status($job_id) {
        static $lic_cache = null;
        if ($lic_cache === null) {
            $lic_cache = AITRFOEL_License_Manager::get_instance()->get_license_status();
        }

        $response = wp_remote_post(self::MEEPTECH_TRANSLATE_ENDPOINT . '/async/status', [
            'method'  => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'job_id'      => $job_id,
                'license_key' => $lic_cache['license_key'],
                'site_url'    => home_url(),
            ]),
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            AITRFOEL_Logger::log('Async status error: ' . $response->get_error_message(), 'error');
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || !isset($data['status'])) {
            $msg = $data['error'] ?? 'Status check failed (HTTP ' . $code . ')';
            return new WP_Error('async_status_failed', $msg);
        }

        if ($data['status'] === 'completed' && isset($data['translations'])) {
            $maps = get_transient('aitrfoel_async_maps_' . $job_id);
            if (!empty($maps)) {
                foreach ($data['translations'] as $key => $translated) {
                    if (is_string($translated)) {
                        $data['translations'][$key] = $this->restore_excluded_words($translated, $maps[$key] ?? []);
                    }
                }
                delete_transient('aitrfoel_async_maps_' . $job_id);
            }
        }

        return $data; // {status, translations?, error?}
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
        
        // Prepend general translation safety instructions
        $general_instructions = "CRITICAL: If a string is already in the target language or contains only numbers/symbols/short codes (e.g. 36K, 500+, 24/7, 100%, ★, →), return it EXACTLY as-is. NEVER add explanations, commentary, or notes. Your output for each string must be ONLY the translated text (or the original if no translation is needed). Never say 'this text does not need translation' or similar.";
        if (!empty($lang_instructions)) {
            $lang_instructions = $general_instructions . "\n" . $lang_instructions;
        } else {
            $lang_instructions = $general_instructions;
        }

        // Append glossary rules
        $glossary_instructions = $this->get_glossary_instructions($target_lang);
        if (!empty($glossary_instructions)) {
            $lang_instructions .= "\n" . $glossary_instructions;
        }

        // Append Brand Voice & Tone rules (PRO Language Enhancer)
        $tone_instructions = $this->build_brand_tone_instructions($target_lang);
        if (!empty($tone_instructions)) {
            $lang_instructions .= $tone_instructions;
        }
        
        // Send batch request to MeepTech
        $body = [
            'texts' => $texts,
            'source_language' => $source_language,
            'target_language' => $target_language,
            'target_lang_code' => $target_lang, // Include original code for server-side handling
            'language_instructions' => $lang_instructions, // Special instructions for rare languages
            'site_url' => $site_url,
            'plan' => $plan,
            'license_key' => $license_key,
            'engine' => get_option('aitrfoel_ai_engine', 'auto'),
            'brand_tone_preset' => get_option('aitrfoel_brand_tone_preset', 'corporate'),
            'brand_tone_custom' => get_option('aitrfoel_brand_tone_custom', ''),
            'formality_mode'    => get_option('aitrfoel_formality_mode', 'auto'),
        ];
        
        AITRFOEL_Logger::log('Sending batch to MeepTech server - ' . count($texts) . ' texts, ' . $source_language . ' → ' . $target_language . (!empty($lang_instructions) ? ' (with special instructions)' : ''), 'debug');
        
        $args = [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($body),
            'timeout' => 60,
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
            
            // On 429 with words_remaining info, retry with smaller sub-batches
            if ($response_code === 429 && isset($data['words_remaining']) && $data['words_remaining'] > 0) {
                $remaining = (int) $data['words_remaining'];
                AITRFOEL_Logger::log('MeepTech batch 429: splitting into sub-batches of ~' . $remaining . ' words', 'info');
                return $this->fallback_subbatch_translation($texts, $target_lang, $source_lang, $remaining);
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
                $translated = $translated_texts[$index];
                
                // Post-process: detect chatty AI responses and keep original
                $translated = $this->sanitize_chatty_response($original_text, $translated);
                
                $result[$original_text] = $translated;
            }
        }
        
        return $result;
    }
    
    /**
     * Detect and fix chatty AI responses where the model adds commentary
     * instead of just translating. Returns original text if response is chatty.
     *
     * @param string $original Original text
     * @param string $translated Translated text from AI
     * @return string Clean translation or original if chatty detected
     */
    public function sanitize_chatty_response($original, $translated) {
        if (empty($original) || empty($translated)) {
            return $translated;
        }
        
        $orig_len = mb_strlen(wp_strip_all_tags($original), 'UTF-8');
        $trans_len = mb_strlen(wp_strip_all_tags($translated), 'UTF-8');
        
        // For very short strings (< 10 chars), if translation is 5x+ longer, it's likely chatty
        if ($orig_len < 10 && $trans_len > $orig_len * 5 && $orig_len > 0) {
            AITRFOEL_Logger::log(
                sprintf('Chatty AI response detected (short string): original="%s" (%d chars), translated=%d chars. Keeping original.',
                    $original, $orig_len, $trans_len),
                'warning'
            );
            return $original;
        }
        
        // Detect known chatty patterns in multiple languages
        $chatty_patterns = [
            '/не (потребує|требует|вимагає) перекладу/ui',
            '/не потрібно перекладати/ui',
            '/does\s?n.t need (to be )?translat/i',
            '/no translation (is )?(needed|required|necessary)/i',
            '/already in (english|the target)/i',
            '/вже (є |на )?(англійською|цільовою)/ui',
            '/уже на (английском|целевом)/ui',
            '/ist bereits (auf|in)/i',
            '/n.a pas besoin (de|d.).*traduction/i',
            '/ya est[áa] en/i',
            '/please provide/i',
            '/будь ласка, (надайте|вкажіть)/ui',
            '/пожалуйста, (предоставьте|укажите)/ui',
        ];
        
        foreach ($chatty_patterns as $pattern) {
            if (preg_match($pattern, $translated)) {
                AITRFOEL_Logger::log(
                    sprintf('Chatty AI response detected (pattern match): original="%s", translated="%s". Keeping original.',
                        mb_substr($original, 0, 50), mb_substr($translated, 0, 100)),
                    'warning'
                );
                return $original;
            }
        }
        
        return $translated;
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
        $glossary_instructions = $this->build_glossary_instructions($target_lang);
        $tone_instructions = $this->build_brand_tone_instructions($target_lang);
        
    $system_prompt = "You are a professional website translator. $source_instruction.

$source_instructions$lang_instructions$exclusion_instructions$glossary_instructions$tone_instructions

CRITICAL TRANSLATION RULES:
1. Translate ONLY the meaningful text content - never translate HTML tags, CSS, or code
2. Preserve ALL HTML formatting exactly: <p>, <br>, <strong>, <em>, <a>, etc.
3. Keep placeholders unchanged: {name}, %s, %d, {{variable}}, [shortcode], <ph id=\"n\"/>, and ≪BR≫ (line break marker)
4. Maintain the specified brand voice and tone consistently throughout
5. For proper nouns, brands, and technical terms - use appropriate localization
6. If source is Cyrillic/Russian, ensure you understand it correctly before translating
7. Do NOT use code fences, comments, or explanations in the output
8. Numbers, abbreviations, units, and short alphanumeric tokens (e.g. 36K, 500+, 24/7, 100%) must be kept EXACTLY as-is — do NOT add commentary or explanations, just return them unchanged
9. Return ONLY valid JSON (no code fences), exactly: {\"t\":[\"<translated>\"]}";

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
        $exclusion_text = "\n\nBRAND PROTECTION — CRITICAL: The following are proprietary BRAND NAMES that must NEVER be translated, modified, or altered in any way: {$exclude_list}. Even if a term looks like a common word or phrase in the source language (for example 'LaVie', 'LaRoche', 'LeChat'), it is a brand name and must be output verbatim and unchanged. This rule overrides all other translation rules.";
        
        return $exclusion_text;
    }

    /**
     * Build glossary instructions from settings
     */
    public function build_glossary_instructions($target_lang) {
        $raw = get_option('aitrfoel_glossary_terms', '');
        if (empty(trim($raw))) {
            return '';
        }

        $target_full = AITRFOEL_Language_Mapper::get_language_name($target_lang);
        $lines = array_filter(array_map('trim', explode("\n", $raw)));
        $entries = [];

        foreach ($lines as $line) {
            if (strpos($line, '=') === false) {
                continue;
            }
            list($term, $translations) = array_map('trim', explode('=', $line, 2));
            if (empty($term) || empty($translations)) {
                continue;
            }

            // Check if there's a language-specific translation for the target
            if (preg_match('/([^,]+)\s*\(' . preg_quote($target_lang, '/') . '\)/i', $translations, $m)) {
                $entries[] = "\"$term\" → \"" . trim($m[1]) . "\"";
            } else {
                // Use the whole right-hand side as a general translation hint
                $entries[] = "\"$term\" → $translations";
            }
        }

        if (empty($entries)) {
            return '';
        }

        return "\n\nGLOSSARY (use these translations consistently when translating to $target_full):\n" . implode("\n", $entries);
    }

    /**
     * Build brand voice, tone and formality instructions from settings (PRO Language Enhancer)
     */
    public function build_brand_tone_instructions($target_lang = '') {
        $preset = get_option('aitrfoel_brand_tone_preset', 'corporate');
        $custom_rules = trim((string) get_option('aitrfoel_brand_tone_custom', ''));
        $formality_mode = get_option('aitrfoel_formality_mode', 'auto');

        $tone_prompts = [
            'hospitality' => "BRAND VOICE & TONE (Warm & Welcoming): Adapt the translation to sound hospitable, warm, inviting, friendly, and emotionally engaging. Ideal for travel, hotels, hospitality, wellness, and culinary experiences. Maintain natural conversational elegance.",
            'corporate'   => "BRAND VOICE & TONE (Professional & Corporate): Maintain a polished, authoritative, clear, and professional business tone. Use precise industry terminology and formal style suited for B2B, SaaS, legal, and financial communication.",
            'sales'       => "BRAND VOICE & TONE (Persuasive & Sales-Driven): Adopt a compelling, benefit-driven, and high-energy marketing tone. Emphasize value, dynamic calls-to-action, and customer benefits while sounding natural and authentic.",
            'casual'      => "BRAND VOICE & TONE (Casual & Modern): Write in an approachable, conversational, relatable, and contemporary voice. Avoid overly stiff, archaic, or bureaucratic phrasing.",
            'custom'      => "BRAND VOICE & TONE (Custom Brand Persona): Follow the custom brand guidelines and persona instructions strictly.",
        ];

        $instructions = [];

        // 1. Preset Tone instruction
        if (!empty($tone_prompts[$preset])) {
            $instructions[] = $tone_prompts[$preset];
        }

        // 2. Formality Mode instruction (for languages with distinct formal/informal modes)
        if ($formality_mode === 'formal') {
            $instructions[] = "FORMALITY REQUIREMENT: Always use FORMAL grammatical address and polite honorifics (e.g. German 'Sie/Ihnen', French 'vous/votre', Spanish 'usted/su', Italian 'Lei/Suo', Portuguese 'você/o senhor', Russian 'Вы/Ваш', Polish 'Pan/Pani').";
        } elseif ($formality_mode === 'informal') {
            $instructions[] = "FORMALITY REQUIREMENT: Use FRIENDLY / INFORMAL grammatical address (e.g. German 'du/dein', French 'tu/ton', Spanish 'tú/tu', Italian 'tu/tuo', Portuguese 'tu', Russian 'ты/твой').";
        }

        // 3. Custom brand rules / persona instructions
        if (!empty($custom_rules)) {
            $instructions[] = "CUSTOM BRAND GUIDELINES & PERSONA RULES:\n" . $custom_rules;
        }

        if (empty($instructions)) {
            return '';
        }

        return "\n\n" . implode("\n\n", $instructions);
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
        $glossary_instructions = $this->build_glossary_instructions($target_lang);
        $tone_instructions = $this->build_brand_tone_instructions($target_lang);

    $system_prompt = "You are a professional website translator. $source_instruction.
Rules:
1) Translate each string independently to $target_full.
2) Preserve placeholders <ph id=\"n\"/> and ≪BR≫ (line break marker) exactly and in order.
3) Do NOT introduce or remove HTML tags beyond placeholders.
4) Numbers, abbreviations, units, and short alphanumeric tokens (e.g. 36K, 500+, 24/7, 100%) must be kept EXACTLY as-is — do NOT add commentary or explanations, just return them unchanged.
$source_instructions
$lang_instructions
$exclusion_instructions
$glossary_instructions
$tone_instructions
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
     * Fallback: split texts into smaller sub-batches and retry via batch API
     */
    private function fallback_subbatch_translation($texts, $target_lang, $source_lang, $max_words) {
        $batches = [];
        $current_batch = [];
        $current_words = 0;
        
        foreach ($texts as $text) {
            $wc = str_word_count(wp_strip_all_tags($text));
            if (!empty($current_batch) && ($current_words + $wc) > $max_words) {
                $batches[] = $current_batch;
                $current_batch = [];
                $current_words = 0;
            }
            $current_batch[] = $text;
            $current_words += $wc;
        }
        if (!empty($current_batch)) {
            $batches[] = $current_batch;
        }
        
        AITRFOEL_Logger::log(sprintf('Sub-batch fallback: splitting %d texts into %d batches (~%d words each)', count($texts), count($batches), $max_words), 'info');
        
        $translations = [];
        foreach ($batches as $i => $batch) {
            $result = $this->translate_batch($batch, $target_lang, $source_lang);
            if (!is_wp_error($result)) {
                $translations = array_merge($translations, $result);
            } else {
                AITRFOEL_Logger::log('Sub-batch ' . ($i + 1) . ' failed: ' . $result->get_error_message(), 'warning');
                // Fall back to individual for this sub-batch only
                $individual = $this->fallback_individual_translation($batch, $target_lang, $source_lang);
                $translations = array_merge($translations, $individual);
            }
        }
        
        return $translations;
    }
    
    /**
     * Fallback to individual translations
     */
    private function fallback_individual_translation($texts, $target_lang, $source_lang) {
        $translations = [];
        $failed_count = 0;
        $last_error = '';
        
        foreach ($texts as $text) {
            $translation = $this->translate_text($text, $target_lang, $source_lang);
            
            if (is_wp_error($translation)) {
                $failed_count++;
                $last_error = $translation->get_error_message();
                AITRFOEL_Logger::log('Individual translation failed: ' . $last_error, 'error');
            } else {
                $translations[$text] = $translation; // Map original => translated
            }
            
            // PERFORMANCE: Reduced delay from 200ms to 50ms for faster batch processing
            usleep(50000); // 50ms delay
        }
        
        // If ALL translations failed, return the WP_Error rather than pretending success
        if ($failed_count > 0 && empty($translations)) {
            return new WP_Error('translation_failed', $last_error ?: __('Individual translation failed.', 'ai-translator-for-elementor-and-polylang'));
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

    /**
     * Protect excluded words using placeholders.
     */
    private function protect_excluded_words($text, &$map) {
        if (!is_string($text) || $text === '') {
            return $text;
        }
        
        $exclude_words = [];
        if (class_exists('AITRFOEL_Settings') && method_exists('AITRFOEL_Settings', 'get_exclude_words')) {
            $exclude_words = AITRFOEL_Settings::get_exclude_words();
        } else {
            $settings = get_option('aitrfoel_translation_settings', []);
            $exclude_words = $settings['exclude_words'] ?? [];
            $raw_exclude = get_option('aitrfoel_exclude_words', '');
            if (!empty($raw_exclude)) {
                $exclude_words = array_unique(array_merge($exclude_words, array_filter(array_map('trim', explode("\n", $raw_exclude)))));
            }
        }

        // Also include 'do_not_translate' terms from the glossary table
        global $wpdb;
        $table_name = $wpdb->prefix . 'aitrfoel_glossary';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
            $db_terms = $wpdb->get_col("SELECT source_text FROM $table_name WHERE action = 'do_not_translate'");
            if (!empty($db_terms)) {
                $exclude_words = array_unique(array_merge($exclude_words, array_map('trim', $db_terms)));
            }
        }
        
        if (empty($exclude_words)) {
            return $text;
        }

        // Sort by length descending to prevent shorter words inside longer ones from matching first
        usort($exclude_words, function($a, $b) {
            return strlen($b) - strlen($a);
        });

        $index = count($map);
        foreach ($exclude_words as $word) {
            if (trim($word) === '') {
                continue;
            }
            
            // Match case-insensitively with word boundaries.
            $pattern = '/\b' . preg_quote($word, '/') . '\b/iu';
            
            // Check if there is a match
            if (preg_match($pattern, $text)) {
                $placeholder = "[exclude_{$index}]";
                $map[$placeholder] = $word;
                $text = preg_replace($pattern, $placeholder, $text);
                $index++;
            }
        }
        
        return $text;
    }

    /**
     * Restore protected excluded words from placeholders.
     */
    private function restore_excluded_words($text, $map) {
        if (!is_string($text) || empty($map)) {
            return $text;
        }
        
        foreach ($map as $placeholder => $word) {
            $text = str_ireplace($placeholder, $word, $text);
        }
        
        return $text;
    }

    /**
     * Get glossary and brand exclusion instructions for a given target language
     */
    private function get_glossary_instructions($target_lang) {
        global $wpdb;
        $do_not_translate = [];
        $replace = [];
        
        // 1. Query database glossary table if it exists
        $table_name = $wpdb->prefix . 'aitrfoel_glossary';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
            $rules = $wpdb->get_results($wpdb->prepare(
                "SELECT source_text, target_text, action FROM $table_name WHERE lang_code = %s OR lang_code = 'all' OR lang_code = '' ORDER BY id ASC",
                $target_lang
            ), ARRAY_A);
            
            if (!empty($rules)) {
                foreach ($rules as $rule) {
                    $source = trim($rule['source_text']);
                    if (empty($source)) continue;
                    if ($rule['action'] === 'do_not_translate') {
                        $do_not_translate[] = '"' . $source . '"';
                    } else {
                        $target = trim($rule['target_text']);
                        if (!empty($target)) {
                            $replace[] = 'Translate "' . $source . '" as "' . $target . '"';
                        }
                    }
                }
            }
        }
        
        // 2. Parse glossary textarea terms
        $raw_glossary = get_option('aitrfoel_glossary_terms', '');
        if (!empty(trim($raw_glossary))) {
            $lines = array_filter(array_map('trim', explode("\n", $raw_glossary)));
            foreach ($lines as $line) {
                if (strpos($line, '=') === false) continue;
                list($term, $translations) = array_map('trim', explode('=', $line, 2));
                if (empty($term) || empty($translations)) continue;
                
                if (preg_match('/([^,]+)\s*\(' . preg_quote($target_lang, '/') . '\)/i', $translations, $m)) {
                    $replace[] = 'Translate "' . $term . '" as "' . trim($m[1]) . '"';
                } else {
                    $replace[] = 'Translate "' . $term . '" as "' . $translations . '"';
                }
            }
        }
        
        // 3. Excluded Words & Brand Protection rules
        $exclude_words = [];
        if (class_exists('AITRFOEL_Settings') && method_exists('AITRFOEL_Settings', 'get_exclude_words')) {
            $exclude_words = AITRFOEL_Settings::get_exclude_words();
        } else {
            $raw_exclude = get_option('aitrfoel_exclude_words', '');
            if (!empty($raw_exclude)) {
                $exclude_words = array_filter(array_map('trim', explode("\n", $raw_exclude)));
            }
        }
        
        if (!empty($exclude_words)) {
            foreach ($exclude_words as $ew) {
                $ew = trim($ew);
                if (!empty($ew) && !in_array('"' . $ew . '"', $do_not_translate, true)) {
                    $do_not_translate[] = '"' . $ew . '"';
                }
            }
        }
        
        if (empty($do_not_translate) && empty($replace)) {
            return '';
        }
        
        $instructions = [];
        if (!empty($do_not_translate)) {
            $instructions[] = 'CRITICAL BRAND & GLOSSARY RULE: Do NOT translate the following phrases, brand names, or terms. Keep them EXACTLY verbatim as in the source: ' . implode(', ', array_unique($do_not_translate)) . '.';
        }
        if (!empty($replace)) {
            $instructions[] = 'GLOSSARY TRANSLATION RULES: ' . implode('. ', array_unique($replace)) . '.';
        }
        
        return implode("\n", $instructions);
    }
}
