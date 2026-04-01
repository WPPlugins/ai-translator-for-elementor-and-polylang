<?php
if (!defined('ABSPATH')) exit;

class AITRFOEL_Cache_Manager {
    private static $instance = null;
    private $wpdb;
    private $table_name;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'aitrfoel_translation_cache';
    }

    /**
     * Get a translation from the cache.
     *
     * @param string $source_text The original text.
     * @param string $target_lang The target language.
     * @param string $source_lang The source language.
     * @return string|null The translated text or null if not found.
     */
    public function get_from_cache($source_text, $target_lang, $source_lang) {
        $source_hash = md5($source_text);

        // Table name is safely constructed in __construct() with $wpdb->prefix
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $result = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT translated_text FROM {$this->table_name} WHERE source_hash = %s AND target_lang = %s AND source_lang = %s",
            $source_hash, $target_lang, $source_lang
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        return $result;
    }

    /**
     * Add a translation to the cache.
     *
     * @param string $source_text The original text.
     * @param string $translated_text The translated text.
     * @param string $target_lang The target language.
     * @param string $source_lang The source language.
     * @param string $model The model used for translation.
     */
    public function set_to_cache($source_text, $translated_text, $target_lang, $source_lang, $model) {
        $source_hash = md5($source_text);

        // Use replace() instead of insert() to handle duplicate cache entries gracefully
        // (same source text translated to same language pair)
        $this->wpdb->replace(
            $this->table_name,
            [
                'source_hash' => $source_hash,
                'source_text' => $source_text,
                'translated_text' => $translated_text,
                'source_lang' => $source_lang,
                'target_lang' => $target_lang,
                'model' => $model,
            ],
            [
                '%s', '%s', '%s', '%s', '%s', '%s'
            ]
        );
    }

    /**
     * Clear the translation cache.
     * Deletes all rows and returns the number of deleted entries.
     *
     * @return int Number of entries removed
     */
    public function clear_cache() {
        // Use DELETE for accurate affected rows count (TRUNCATE returns 0)
        $deleted = $this->wpdb->query("DELETE FROM {$this->table_name}");
        return is_wp_error($deleted) ? 0 : intval($deleted);
    }

    /**
     * Get cache statistics summary.
     *
     * @return array
     */
    public function get_stats() {
        $total = intval($this->wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}"));

        // Group by target language
        $by_language_rows = $this->wpdb->get_results(
            "SELECT target_lang, COUNT(*) as cnt FROM {$this->table_name} GROUP BY target_lang",
            ARRAY_A
        );
        $by_language = [];
        if (is_array($by_language_rows)) {
            foreach ($by_language_rows as $row) {
                $by_language[$row['target_lang']] = intval($row['cnt']);
            }
        }

        // Oldest/Newest timestamps
        $oldest = $this->wpdb->get_var("SELECT MIN(created_at) FROM {$this->table_name}");
        $newest = $this->wpdb->get_var("SELECT MAX(created_at) FROM {$this->table_name}");

        return [
            'total_entries' => $total,
            'by_language' => $by_language,
            'oldest' => $oldest,
            'newest' => $newest,
        ];
    }
}
