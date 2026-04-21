<?php
/**
 * Gap Filler – second pass to fix missed/under-translated strings
 */

if (!defined('ABSPATH')) exit;

final class AITRFOEL_Gap_Filler {
    /**
     * Run gap-filler on provided strings and translations (by reference)
     *
     * @param array $strings Items from parse_for_translation(): [path, original, type, ph_map?, group_key?]
     * @param array &$translations Map path => translated string (after first pass)
     * @param string $target_lang Target language
     * @param string $source_lang Source language (or 'auto')
     * @param array $opts Options: residue_patterns (regex), levenshtein_threshold (int), max_retry (int), concurrency (int), enable_postprocessor (bool), page_id (int)
     * @return array Metrics: [retried, fixed, skipped, failed]
     */
    public static function run(array $strings, array &$translations, string $target_lang, string $source_lang = 'auto', array $opts = []): array {
        $L    = isset($opts['levenshtein_threshold']) ? (int)$opts['levenshtein_threshold'] : (int)get_option('aitrfoel_gap_lev_threshold', 3);
        $RES  = isset($opts['residue_patterns']) ? (string)$opts['residue_patterns'] : (string)get_option('aitrfoel_gap_residue_patterns', '/\b(Read more|Číst dál|Читать далее)\b/ui');
        $MAX  = isset($opts['max_retry']) ? (int)$opts['max_retry'] : (int)get_option('aitrfoel_gap_max_retry', 2);
        $pp   = isset($opts['enable_postprocessor']) ? (bool)$opts['enable_postprocessor'] : (bool)get_option('aitrfoel_gap_enable_postprocessor', true);
        $page = isset($opts['page_id']) ? (int)$opts['page_id'] : 0;

        // Boost retries for less-common languages where AI may need more attempts
        $less_common_languages = ['ro', 'bg', 'hr', 'sk', 'sl', 'et', 'lv', 'lt', 'uk', 'sr', 'mk', 'bs', 'sq', 'mt', 'ga', 'cy', 'is'];
        $target_code = strtolower(substr($target_lang, 0, 2));
        if (in_array($target_code, $less_common_languages, true)) {
            $MAX = max($MAX, 3); // At least 3 retries for less common languages
            // Also increase Levenshtein threshold since translations may vary more
            $L = max($L, 5);
        }

        $enabled = get_option('aitrfoel_gap_enabled', true);
        if (!$enabled) {
            return ['retried' => 0, 'fixed' => 0, 'skipped' => 0, 'failed' => 0];
        }

        $api = AITRFOEL_OpenAI_API::get_instance();
        $parser = AITRFOEL_Widget_Parser::get_instance();

        // Collect candidates based on current translations state
        $candidates = [];
        foreach ($strings as $row) {
            if (!is_array($row)) continue;
            $path = (string)($row['path'] ?? '');
            if ($path === '') continue;

            $orig = (string)($row['original'] ?? '');
            $tr   = (string)($translations[$path] ?? '');

            // Skip proper nouns and acronyms - they shouldn't be translated
            if (self::isProperNounOrAcronym($orig)) {
                // If translation is empty or same as original, that's correct for names
                if (trim($tr) === '' || $tr === $orig) {
                    $translations[$path] = $orig; // Keep original name
                }
                continue; // Don't retry translating names
            }

            $isEmpty = trim($tr) === '';
            $isAlmostSame = (mb_strlen($orig) > 0)
                ? !self::notAlmostSame($orig, $tr, $L)
                : false;
            $hasResidue = ($tr !== '' && @preg_match($RES, $tr) === 1);
            $hasEscaped = (bool)preg_match('/\\\\<\/[a-z]+>/i', $tr) || (strpos($tr, '<\\ph') !== false);

            if ($isEmpty || $isAlmostSame || $hasResidue || $hasEscaped) {
                $candidates[] = $row + [
                    '__flags' => compact('isEmpty','isAlmostSame','hasResidue','hasEscaped')
                ];
            }
        }

        $metrics = ['retried' => 0, 'fixed' => 0, 'skipped' => 0, 'failed' => 0];

        if (empty($candidates)) {
            return $metrics; // nothing to do
        }

        // Logging summary
        AITRFOEL_Logger::log(sprintf('[EAT gap-filler] page=%s lang=%s candidates=%d', $page ?: 'n/a', $target_lang, count($candidates)), 'info', $page);

        // Process one by one (sequential for now)
        $debug_shown = 0;
        foreach ($candidates as $row) {
            $metrics['retried']++;
            $path = (string)$row['path'];
            $orig = (string)$row['original'];
            $type = (string)($row['type'] ?? 'field');
            $ph_map = isset($row['ph_map']) && is_array($row['ph_map']) ? $row['ph_map'] : null;

            $prepared = $orig;
            $ph_local = null;
            if ($type === 'field') {
                if (!$ph_map && method_exists($parser, 'i18n_extract_with_placeholders')) {
                    list($prepared, $ph_local) = $parser->i18n_extract_with_placeholders($orig);
                }
            }

            $attempts = 0;
            $final = null;
            $best_candidate = null; // Store best translation even if not perfect
            $api_errors = 0;
            
            do {
                $resp = $api->translate_text($prepared, $target_lang, $source_lang);
                if (is_wp_error($resp)) {
                    $attempts++;
                    $api_errors++;
                    // Log API error only on first occurrence
                    if ($api_errors === 1) {
                        AITRFOEL_Logger::log(sprintf('[EAT gap-filler] API error for path=%s: %s', $path, $resp->get_error_message()), 'warning', $page);
                    }
                    continue;
                }

                $tmp = (string)$resp;
                $map = $ph_map ?: $ph_local;
                if ($type === 'field' && $map && method_exists($parser, 'i18n_restore_placeholders')) {
                    $tmp = $parser->i18n_restore_placeholders($tmp, $map);
                }

                // Cleanup and optional post-processing
                if (method_exists($parser, 'i18n_cleanup_artifacts')) {
                    $tmp = $parser->i18n_cleanup_artifacts($tmp);
                }
                if ($pp && class_exists('AITRFOEL_Post_Processor')) {
                    $ctx = [
                        'lang' => $target_lang,
                        'ph_map' => $map,
                        'group_key' => (string)($row['group_key'] ?? ''),
                        'path' => $path,
                    ];
                    $tmp = AITRFOEL_Post_Processor::repair($orig, $tmp, $ctx);
                }

                $okNotEmpty = trim(wp_strip_all_tags((string)$tmp)) !== '';
                $notSame = self::notAlmostSame($orig, (string)$tmp, $L);
                $residueLeft = (@preg_match($RES, (string)$tmp) === 1);

                // Store as best candidate if it's not empty and meaningfully different from original
                // Use html_entity_decode to avoid entity encoding false positives (& vs &amp;)
                $orig_decoded = strtolower(trim(html_entity_decode(wp_strip_all_tags($orig), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                $tmp_decoded = strtolower(trim(html_entity_decode(wp_strip_all_tags((string)$tmp), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                if ($okNotEmpty && $tmp_decoded !== $orig_decoded) {
                    $best_candidate = (string)$tmp;
                }

                if ($okNotEmpty && $notSame) {
                    $final = (string)$tmp;
                    // If residue remains and we still have retries, continue loop
                    if ($residueLeft && $attempts < $MAX) {
                        $attempts++;
                        continue;
                    }
                    break;
                }

                $attempts++;
            } while ($attempts <= $MAX);

            // Fallback: use best candidate if we have one and didn't find a "perfect" translation
            if ($final === null && $best_candidate !== null) {
                $final = $best_candidate;
                AITRFOEL_Logger::log(sprintf('[EAT gap-filler] Using best-effort translation for path=%s (may be similar to original)', $path), 'debug', $page);
            }

            if ($final !== null) {
                $before = (string)($translations[$path] ?? '');
                $translations[$path] = $final;
                $metrics['fixed']++;

                if ($debug_shown < 3) {
                    $debug_shown++;
                    AITRFOEL_Logger::log('[EAT gap-filler debug] path=' . $path
                        . ' orig=' . mb_substr(wp_strip_all_tags($orig), 0, 120)
                        . ' before=' . mb_substr(wp_strip_all_tags($before), 0, 120)
                        . ' after=' . mb_substr(wp_strip_all_tags($final), 0, 120), 'debug', $page);
                }
            } else {
                $metrics['failed']++;
                // Log WHY the translation failed
                $reason = 'unknown';
                $lastTr = $translations[$path] ?? '';
                if (trim($lastTr) === '') {
                    $reason = 'empty_result';
                } elseif (!self::notAlmostSame($orig, (string)$lastTr, $L)) {
                    $reason = 'too_similar_to_original';
                } else {
                    $reason = 'api_error_or_max_retries';
                }
                AITRFOEL_Logger::log(sprintf('[EAT gap-filler FAILED] path=%s reason=%s orig="%s"', 
                    $path, 
                    $reason,
                    mb_substr(wp_strip_all_tags($orig), 0, 80)
                ), 'warning', $page);
            }
        }

        // Final summary
        AITRFOEL_Logger::log(sprintf('[EAT gap-filler] page=%s lang=%s retried=%d fixed=%d failed=%d', $page ?: 'n/a', $target_lang, $metrics['retried'], $metrics['fixed'], $metrics['failed']), 'info', $page);

        return $metrics;
    }

    /**
     * Check if two strings are NOT almost the same (i.e., translation changed enough)
     * Uses levenshtein for short strings, percentage-based for long strings
     */
    private static function notAlmostSame(string $a, string $b, int $thr): bool {
        $a_clean = wp_strip_all_tags($a);
        $b_clean = wp_strip_all_tags($b);
        
        // For strings longer than 200 chars, use percentage-based comparison instead of levenshtein
        // (PHP's levenshtein has a 255 char limit)
        if (mb_strlen($a_clean) > 200 || mb_strlen($b_clean) > 200) {
            // Use similar_text percentage - if less than 85% similar, it's different enough
            similar_text($a_clean, $b_clean, $percent);
            return $percent < 85;
        }
        
        // For short strings, use levenshtein
        return levenshtein(mb_substr($a_clean, 0, 255), mb_substr($b_clean, 0, 255)) > $thr;
    }

    /**
     * Check if a string looks like a proper noun/name that shouldn't be translated
     * Examples: "Matthew Cole", "Juan Soto", "Benjamin Shaw", "CEO", "COO"
     */
    private static function isProperNounOrAcronym(string $text): bool {
        $text = trim(wp_strip_all_tags($text));
        
        // Empty or too long - not a simple name
        if ($text === '' || mb_strlen($text) > 50) {
            return false;
        }
        
        // Common job title acronyms that shouldn't change
        $acronyms = ['CEO', 'COO', 'CFO', 'CTO', 'CIO', 'CMO', 'CPO', 'VP', 'SVP', 'EVP', 'MD', 'PhD', 'MBA'];
        if (in_array(strtoupper($text), $acronyms, true)) {
            return true;
        }
        
        // Pattern: 2-3 capitalized words where NONE are common English words (likely a person's name)
        // Must be at least 2 words to be a name. Single capitalized words could be headings.
        // Matches: "John Smith", "Mary Jane Watson", "Juan Soto"
        // Does NOT match: "Our Story", "Contact Us", "About Us", "Read More"
        if (preg_match('/^[A-Z][a-z]+(\s+[A-Z][a-z]+){1,2}$/', $text)) {
            $common_words = [
                'our', 'my', 'your', 'the', 'a', 'an', 'and', 'or', 'for', 'with', 'about',
                'from', 'this', 'that', 'all', 'new', 'more', 'get', 'read', 'see', 'view',
                'how', 'why', 'what', 'where', 'when', 'who', 'which',
                'contact', 'home', 'story', 'services', 'products', 'blog', 'news',
                'team', 'work', 'portfolio', 'gallery', 'pricing', 'plans', 'features',
                'crafting', 'building', 'creating', 'designing', 'making', 'growing',
                'gardens', 'landscapes', 'expertise', 'care', 'quality', 'best',
                'free', 'quote', 'call', 'now', 'today', 'here', 'us', 'them',
            ];
            $words = preg_split('/\s+/', strtolower($text));
            foreach ($words as $word) {
                if (in_array($word, $common_words, true)) {
                    return false; // Contains a common word — it's a heading, not a name
                }
            }
            return true; // All words look like proper nouns
        }
        
        return false;
    }
}
