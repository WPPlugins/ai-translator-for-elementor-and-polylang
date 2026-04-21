<?php
/**
 * Lightweight post-processor to repair common artifacts after translation
 */

if (!defined('ABSPATH')) exit;

final class AITRFOEL_Post_Processor {
    /**
     * Repair translated string against original context.
     * - Unescape tags like <\/p>
     * - Normalize <ph> artifacts and clean guillemets via parser helpers
     * - Try to restore lost links using parser heuristics
     */
    public static function repair(string $original, string $translated, array $ctx = []) : string {
        $parser = AITRFOEL_Widget_Parser::get_instance();

        // 1) Fix escaped closing tags and ph artifacts
        if (method_exists($parser, 'i18n_cleanup_artifacts')) {
            $translated = $parser->i18n_cleanup_artifacts($translated);
        } else {
            // Minimal fallback: unescape </tag> where slash was escaped (e.g., <\/p>)
            $translated = preg_replace('#<\\/(\w+)>#', '</$1>', $translated);
        }

        // 2) Restore lost links if any
        if (method_exists($parser, 'restore_lost_links')) {
            $translated = $parser->restore_lost_links($translated, $original);
        }

        return $translated;
    }
}
