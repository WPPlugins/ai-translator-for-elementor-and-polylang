<?php
/**
 * Smart Translation Memory (TM) & String Deduplication Engine
 *
 * Automatically caches and reuses translated phrases, paragraphs, and UI strings
 * to accelerate translation speed and save word quota across pages.
 *
 * @package AI_Translator_For_Elementor_And_Polylang
 */

if (!defined('ABSPATH')) {
    exit;
}

class AITRFOEL_Translation_Memory {

    /**
     * Singleton instance
     * @var AITRFOEL_Translation_Memory|null
     */
    private static $instance = null;

    /**
     * Table name
     * @var string
     */
    private $table_name;

    /**
     * Cache table existence check per request
     * @var bool|null
     */
    private $table_exists = null;

    /**
     * Get singleton instance
     * @return AITRFOEL_Translation_Memory
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'aitrfoel_translation_memory';
    }

    /**
     * Check if Translation Memory is enabled in settings
     * @return bool
     */
    public function is_enabled() {
        return (bool) get_option('aitrfoel_enable_translation_memory', 1);
    }

    /**
     * Ensure the TM database table exists
     */
    public function ensure_table() {
        if ($this->table_exists) {
            return;
        }

        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name) {
            $this->table_exists = true;
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            source_hash varchar(64) NOT NULL,
            source_text longtext NOT NULL,
            translated_text longtext NOT NULL,
            source_lang varchar(10) NOT NULL DEFAULT 'auto',
            target_lang varchar(10) NOT NULL,
            word_count int(11) NOT NULL DEFAULT 0,
            usage_count int(11) NOT NULL DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            last_used_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uk_hash (source_hash),
            KEY idx_target_lang (target_lang)
        ) $charset_collate;";

        dbDelta($sql);
        $this->table_exists = true;
    }

    /**
     * Generate lookup hash
     *
     * @param string $text
     * @param string $target_lang
     * @param string $source_lang
     * @return string
     */
    public function get_hash($text, $target_lang, $source_lang = 'auto') {
        $normalized = trim((string) $text);
        return md5($normalized . '|' . strtolower(trim($source_lang)) . '|' . strtolower(trim($target_lang)));
    }

    /**
     * Estimate word count of a string
     *
     * @param string $text
     * @return int
     */
    public function count_words($text) {
        $clean = wp_strip_all_tags((string) $text);
        $clean = preg_replace('/\s+/', ' ', trim($clean));
        if (empty($clean)) {
            return 0;
        }
        $words = preg_split('/[\s\p{Z}\p{P}]+/u', $clean, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($words) ? count($words) : 0;
    }

    /**
     * Lookup a single string from Translation Memory
     *
     * @param string $text
     * @param string $target_lang
     * @param string $source_lang
     * @return string|null
     */
    public function lookup_single($text, $target_lang, $source_lang = 'auto') {
        if (!$this->is_enabled() || empty(trim((string) $text))) {
            return null;
        }

        $res = $this->lookup_batch([$text], $target_lang, $source_lang);
        if (!empty($res['hits']) && isset($res['hits'][0])) {
            return $res['hits'][0];
        }

        return null;
    }

    /**
     * Batch lookup strings from Translation Memory
     *
     * @param array $texts Indexed array of source texts
     * @param string $target_lang Target language code
     * @param string $source_lang Source language code
     * @return array Array with ['hits' => [index => translation], 'misses' => [index => text], 'words_saved' => int]
     */
    public function lookup_batch(array $texts, $target_lang, $source_lang = 'auto') {
        $result = [
            'hits'        => [],
            'misses'      => [],
            'words_saved' => 0,
        ];

        if (empty($texts)) {
            return $result;
        }

        if (!$this->is_enabled()) {
            foreach ($texts as $idx => $txt) {
                $result['misses'][$idx] = $txt;
            }
            return $result;
        }

        $this->ensure_table();
        global $wpdb;

        // Compute hashes and index mapping
        $hash_to_indices = [];
        $hashes = [];

        foreach ($texts as $idx => $text) {
            $trimmed = trim((string) $text);
            if (empty($trimmed)) {
                $result['hits'][$idx] = $text; // Empty text passes through directly
                continue;
            }

            $hash = $this->get_hash($text, $target_lang, $source_lang);
            $hashes[] = $hash;
            if (!isset($hash_to_indices[$hash])) {
                $hash_to_indices[$hash] = [];
            }
            $hash_to_indices[$hash][] = $idx;
        }

        if (empty($hashes)) {
            return $result;
        }

        $unique_hashes = array_unique($hashes);
        $placeholders = implode(',', array_fill(0, count($unique_hashes), '%s'));

        $query = $wpdb->prepare(
            "SELECT id, source_hash, translated_text, word_count FROM {$this->table_name} WHERE source_hash IN ($placeholders)",
            $unique_hashes
        );

        $rows = $wpdb->get_results($query); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare(); table names are internal ($wpdb->prefix + fixed name)
        $matched_ids = [];
        $words_saved_batch = 0;

        if (!empty($rows)) {
            foreach ($rows as $row) {
                $hash = $row->source_hash;
                if (isset($hash_to_indices[$hash])) {
                    $has_valid_hit = false;
                    foreach ($hash_to_indices[$hash] as $orig_idx) {
                        $orig_text = trim((string) ($texts[$orig_idx] ?? ''));
                        $trans_text = trim((string) $row->translated_text);

                        // If translation is identical to source text and contains letters,
                        // this was an erroneously saved un-translated string. Prune it and treat as miss.
                        if ($orig_text === $trans_text && preg_match('/[\p{L}]/u', $orig_text)) {
                            $wpdb->delete($this->table_name, ['id' => $row->id]);
                            continue;
                        }

                        $result['hits'][$orig_idx] = $row->translated_text;
                        $has_valid_hit = true;
                    }
                    if ($has_valid_hit) {
                        $matched_ids[] = (int) $row->id;
                        $words_saved_batch += (int) $row->word_count * count($hash_to_indices[$hash]);
                    }
                }
            }
        }

        // Identify misses
        foreach ($texts as $idx => $text) {
            if (!isset($result['hits'][$idx])) {
                $result['misses'][$idx] = $text;
            }
        }

        // Increment usage stats for matches
        if (!empty($matched_ids)) {
            $ids_str = implode(',', array_map('intval', $matched_ids));
            $wpdb->query("UPDATE {$this->table_name} SET usage_count = usage_count + 1, last_used_at = NOW() WHERE id IN ($ids_str)");

            // Record cumulative words saved in site options
            $prev_saved = (int) get_option('aitrfoel_tm_total_words_saved', 0);
            update_option('aitrfoel_tm_total_words_saved', $prev_saved + $words_saved_batch, false);
            $result['words_saved'] = $words_saved_batch;
        }

        return $result;
    }

    /**
     * Save a single translation into Memory
     *
     * @param string $source_text
     * @param string $translated_text
     * @param string $target_lang
     * @param string $source_lang
     * @return bool
     */
    public function save_single($source_text, $translated_text, $target_lang, $source_lang = 'auto') {
        return $this->save_batch([
            ['source' => $source_text, 'translated' => $translated_text]
        ], $target_lang, $source_lang);
    }

    /**
     * Save a batch of translations into Memory
     *
     * @param array $pairs Array of ['source' => ..., 'translated' => ...]
     * @param string $target_lang
     * @param string $source_lang
     * @return bool
     */
    public function save_batch(array $pairs, $target_lang, $source_lang = 'auto') {
        if (!$this->is_enabled() || empty($pairs)) {
            return false;
        }

        $this->ensure_table();
        global $wpdb;

        $values = [];
        $placeholders = [];

        foreach ($pairs as $item) {
            $source = trim((string) ($item['source'] ?? ''));
            $translated = trim((string) ($item['translated'] ?? ''));

            if (empty($source) || empty($translated)) {
                continue;
            }

            // Don't cache error strings
            if (strpos($translated, 'AITRFOEL_ERROR') !== false) {
                continue;
            }

            // Do NOT save identical source==translated if text contains letters (untranslated text)
            if ($source === $translated && preg_match('/[\p{L}]/u', $source)) {
                continue;
            }

            $hash = $this->get_hash($source, $target_lang, $source_lang);
            $words = $this->count_words($source);

            $values[] = $hash;
            $values[] = $source;
            $values[] = $translated;
            $values[] = strtolower(trim($source_lang));
            $values[] = strtolower(trim($target_lang));
            $values[] = $words;
            $values[] = 1;
            $values[] = current_time('mysql');
            $values[] = current_time('mysql');

            $placeholders[] = '(%s, %s, %s, %s, %s, %d, %d, %s, %s)';
        }

        if (empty($placeholders)) {
            return true;
        }

        $sql = "INSERT INTO {$this->table_name} 
            (source_hash, source_text, translated_text, source_lang, target_lang, word_count, usage_count, created_at, last_used_at) 
            VALUES " . implode(',', $placeholders) . "
            ON DUPLICATE KEY UPDATE 
                translated_text = VALUES(translated_text),
                word_count = VALUES(word_count),
                last_used_at = VALUES(last_used_at)";

        $wpdb->query($wpdb->prepare($sql, $values)); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare(); table names are internal ($wpdb->prefix + fixed name)
        return true;
    }

    /**
     * Get Translation Memory statistics
     *
     * @return array
     */
    public function get_stats() {
        $this->ensure_table();
        global $wpdb;

        $total_entries = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $total_words_saved = (int) get_option('aitrfoel_tm_total_words_saved', 0);
        $total_hits = (int) $wpdb->get_var("SELECT SUM(usage_count - 1) FROM {$this->table_name} WHERE usage_count > 1");

        return [
            'total_entries'     => $total_entries,
            'total_words_saved' => $total_words_saved,
            'total_hits'        => max(0, $total_hits),
            'is_enabled'        => $this->is_enabled(),
        ];
    }

    /**
     * Clear all Translation Memory records
     *
     * @return bool
     */
    public function clear_memory() {
        $this->ensure_table();
        global $wpdb;

        $wpdb->query("TRUNCATE TABLE {$this->table_name}");
        update_option('aitrfoel_tm_total_words_saved', 0, false);
        return true;
    }
}
