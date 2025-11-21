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
        $MAX  = isset($opts['max_retry']) ? (int)$opts['max_retry'] : (int)get_option('aitrfoel_gap_max_retry', 1);
        $pp   = isset($opts['enable_postprocessor']) ? (bool)$opts['enable_postprocessor'] : (bool)get_option('aitrfoel_gap_enable_postprocessor', true);
        $page = isset($opts['page_id']) ? (int)$opts['page_id'] : 0;

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

            $isEmpty = trim($tr) === '';
            $isAlmostSame = (mb_strlen($orig) > 0)
                ? (levenshtein(mb_substr($orig, 0, 255), mb_substr($tr, 0, 255)) <= $L)
                : false;
            $hasResidue = ($tr !== '' && @preg_match($RES, $tr) === 1);
            $hasEscaped = (bool)preg_match('/\\<\/[a-z]+>/i', $tr) || (strpos($tr, '<\\ph') !== false);

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
            do {
                $resp = $api->translate_text($prepared, $target_lang, $source_lang);
                if (is_wp_error($resp)) {
                    $attempts++;
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
            }
        }

        // Final summary
        AITRFOEL_Logger::log(sprintf('[EAT gap-filler] page=%s lang=%s retried=%d fixed=%d failed=%d', $page ?: 'n/a', $target_lang, $metrics['retried'], $metrics['fixed'], $metrics['failed']), 'info', $page);

        return $metrics;
    }

    private static function notAlmostSame(string $a, string $b, int $thr): bool {
        return levenshtein(mb_substr($a, 0, 255), mb_substr($b, 0, 255)) > $thr;
    }
}
