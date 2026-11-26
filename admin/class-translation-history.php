<?php
/**
 * Translation History Admin Page
 * Shows a log of all translations performed by the plugin.
 */

if (!defined('ABSPATH')) exit;

class AITRFOEL_Translation_History {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_aitrfoel_clear_history', [$this, 'ajax_clear_history']);
    }

    /**
     * Record a translation in the history table.
     *
     * @param array $args {
     *   @type int    $original_post_id
     *   @type int    $translated_post_id
     *   @type string $source_lang
     *   @type string $target_lang
     *   @type string $content_type  elementor|gutenberg|classic
     *   @type int    $word_count
     *   @type string $status        success|error
     *   @type float  $duration      Seconds
     * }
     */
    public static function record($args) {
        global $wpdb;
        $table = $wpdb->prefix . 'aitrfoel_translation_history';

        // Ensure the table exists (may not on first run before re-activation)
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }

        $wpdb->insert($table, [
            'original_post_id'   => absint($args['original_post_id'] ?? 0),
            'translated_post_id' => absint($args['translated_post_id'] ?? 0),
            'source_lang'        => self::normalize_lang($args['source_lang'] ?? ''),
            'target_lang'        => self::normalize_lang($args['target_lang'] ?? ''),
            'content_type'       => sanitize_text_field($args['content_type'] ?? 'elementor'),
            'word_count'         => absint($args['word_count'] ?? 0),
            'status'             => sanitize_text_field($args['status'] ?? 'success'),
            'duration'           => floatval($args['duration'] ?? 0),
            'user_id'            => get_current_user_id(),
            'created_at'         => current_time('mysql'),
        ], ['%d', '%d', '%s', '%s', '%s', '%d', '%s', '%f', '%d', '%s']);
    }

    /**
     * Normalize a language value to a lowercase code.
     * Some engine paths pass full names ("German") and others codes ("de"), which split the
     * History stats into duplicates (DE + GERMAN) and inflated the language count.
     */
    public static function normalize_lang($lang) {
        $lang = trim(sanitize_text_field((string) $lang));
        if ($lang === '') {
            return '';
        }
        $to_lower = static function ($str) {
            return function_exists('mb_strtolower') ? mb_strtolower($str, 'UTF-8') : strtolower($str);
        };
        if (strlen($lang) > 3 && strpos($lang, '-') === false && strpos($lang, '_') === false
            && class_exists('AITRFOEL_Language_Mapper')) {
            $code = AITRFOEL_Language_Mapper::get_code_from_language_name($lang);
            if ($code) {
                return $to_lower($code);
            }
        }
        return $to_lower($lang);
    }

    /**
     * One-time migration: rewrite rows stored with full language names to codes.
     */
    private function maybe_normalize_history_langs($table) {
        if (get_option('aitrfoel_history_langs_normalized')) {
            return;
        }
        global $wpdb;
        $safe_table = esc_sql($table);
        foreach (['source_lang', 'target_lang'] as $col) {
            if (!in_array($col, ['source_lang', 'target_lang'], true)) {
                continue;
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and whitelisted column names cannot be value placeholders.
            $values = $wpdb->get_col("SELECT DISTINCT `{$col}` FROM `{$safe_table}`");
            foreach ((array) $values as $value) {
                $normalized = self::normalize_lang($value);
                if ($normalized !== '' && $normalized !== $value) {
                    $wpdb->update($table, [$col => $normalized], [$col => $value], ['%s'], ['%s']);
                }
            }
        }
        update_option('aitrfoel_history_langs_normalized', 1, false);
    }

    /**
     * Render the History admin page
     */
    public function render_page() {
        $per_page = 30;
        $current_page = max(1, absint($_GET['paged'] ?? 1));
        $offset = ($current_page - 1) * $per_page;

        // Filters
        $filter_lang   = sanitize_text_field($_GET['lang'] ?? '');
        $filter_type   = sanitize_text_field($_GET['ctype'] ?? '');
        $filter_status = sanitize_text_field($_GET['status'] ?? '');

        global $wpdb;
        $table = $wpdb->prefix . 'aitrfoel_translation_history';

        // Check table exists
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;

        $rows = [];
        $total = 0;
        $summary = ['total_translations' => 0, 'total_words' => 0, 'languages' => []];

        if ($table_exists) {
            $this->maybe_normalize_history_langs($table);
            // Build WHERE clause
            $where = [];
            $params = [];
            if ($filter_lang) {
                $where[] = 'target_lang = %s';
                $params[] = $filter_lang;
            }
            if ($filter_type) {
                $where[] = 'content_type = %s';
                $params[] = $filter_type;
            }
            if ($filter_status) {
                $where[] = 'status = %s';
                $params[] = $filter_status;
            }
            $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            // Total count
            $count_query = "SELECT COUNT(*) FROM $table $where_sql";
            $total = $params ? (int) $wpdb->get_var($wpdb->prepare($count_query, ...$params)) : (int) $wpdb->get_var($count_query); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare(); table names are internal ($wpdb->prefix + fixed name)

            // Fetch rows
            $query = "SELECT * FROM $table $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d";
            $all_params = array_merge($params, [$per_page, $offset]);
            $rows = $wpdb->get_results($wpdb->prepare($query, ...$all_params)); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare(); table names are internal ($wpdb->prefix + fixed name)

            // Summary stats
            $summary['total_translations'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'success'");
            $summary['total_words'] = (int) $wpdb->get_var("SELECT COALESCE(SUM(word_count), 0) FROM $table WHERE status = 'success'");
            $lang_rows = $wpdb->get_results("SELECT target_lang, COUNT(*) as cnt FROM $table WHERE status = 'success' GROUP BY target_lang ORDER BY cnt DESC");
            foreach ($lang_rows as $lr) {
                $summary['languages'][$lr->target_lang] = (int) $lr->cnt;
            }
        }

        $total_pages = ceil($total / $per_page);
        $base_url = admin_url('admin.php?page=ai-translator-for-elementor-and-polylang-history');
        ?>
        <div class="wrap aitrfoel-admin-wrapper">
            <?php
            $admin_menu = AITRFOEL_Admin_Menu::get_instance();
            $admin_menu->render_plugin_header(__('Translation History', 'ai-translator-for-elementor-and-polylang'));
            ?>

            <?php if (!$table_exists): ?>
                <div class="notice notice-warning"><p>
                    <?php esc_html_e('History table not found. Please deactivate and reactivate the plugin to create it, or translate a page to start recording.', 'ai-translator-for-elementor-and-polylang'); ?>
                </p></div>
            <?php else: ?>

            <!-- Summary Cards -->
            <div class="aitrfoel-history-summary">
                <div class="aitrfoel-history-card aitrfoel-history-card-stat">
                    <div class="aitrfoel-history-number"><?php echo number_format($summary['total_translations']); ?></div>
                    <div class="aitrfoel-history-label"><?php esc_html_e('Translations', 'ai-translator-for-elementor-and-polylang'); ?></div>
                </div>
                <div class="aitrfoel-history-card aitrfoel-history-card-stat">
                    <div class="aitrfoel-history-number"><?php echo number_format($summary['total_words']); ?></div>
                    <div class="aitrfoel-history-label"><?php esc_html_e('Words Translated', 'ai-translator-for-elementor-and-polylang'); ?></div>
                </div>
                <div class="aitrfoel-history-card aitrfoel-history-card-stat">
                    <div class="aitrfoel-history-number"><?php echo count($summary['languages']); ?></div>
                    <div class="aitrfoel-history-label"><?php esc_html_e('Languages', 'ai-translator-for-elementor-and-polylang'); ?></div>
                </div>
                <?php if (!empty($summary['languages'])): ?>
                <div class="aitrfoel-history-card aitrfoel-history-card-languages">
                    <div class="aitrfoel-history-language-list">
                        <?php foreach ($summary['languages'] as $code => $cnt):
                            $filter_url = add_query_arg(['page' => 'ai-translator-for-elementor-and-polylang-history', 'lang' => $code], admin_url('admin.php'));
                            $is_active = (isset($_GET['lang']) && $_GET['lang'] === $code);
                        ?>
                            <a href="<?php echo esc_url($filter_url); ?>" class="aitrfoel-history-language-chip<?php echo $is_active ? ' is-active' : ''; ?>" title="<?php echo esc_attr(sprintf(/* translators: %s: language code */ __('Filter by %s', 'ai-translator-for-elementor-and-polylang'), strtoupper($code))); ?>">
                                <?php echo esc_html(strtoupper($code)); ?> <span class="aitrfoel-history-language-chip-count">(<?php echo esc_html($cnt); ?>)</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="aitrfoel-history-language-caption"><?php esc_html_e('Per Language', 'ai-translator-for-elementor-and-polylang'); ?></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Filters -->
            <form method="get" class="aitrfoel-history-filters">
                <input type="hidden" name="page" value="ai-translator-for-elementor-and-polylang-history" />
                <?php if (!empty($summary['languages'])): ?>
                <select name="lang" class="aitrfoel-history-filter-select">
                    <option value=""><?php esc_html_e('All Languages', 'ai-translator-for-elementor-and-polylang'); ?></option>
                    <?php foreach (array_keys($summary['languages']) as $code): ?>
                        <option value="<?php echo esc_attr($code); ?>" <?php selected($filter_lang, $code); ?>><?php echo esc_html(strtoupper($code)); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <select name="ctype" class="aitrfoel-history-filter-select">
                    <option value=""><?php esc_html_e('All Types', 'ai-translator-for-elementor-and-polylang'); ?></option>
                    <option value="elementor" <?php selected($filter_type, 'elementor'); ?>>Elementor</option>
                    <option value="gutenberg" <?php selected($filter_type, 'gutenberg'); ?>>Gutenberg</option>
                    <option value="classic" <?php selected($filter_type, 'classic'); ?>>Classic</option>
                </select>
                <select name="status" class="aitrfoel-history-filter-select">
                    <option value=""><?php esc_html_e('All Statuses', 'ai-translator-for-elementor-and-polylang'); ?></option>
                    <option value="success" <?php selected($filter_status, 'success'); ?>><?php esc_html_e('Success', 'ai-translator-for-elementor-and-polylang'); ?></option>
                    <option value="error" <?php selected($filter_status, 'error'); ?>><?php esc_html_e('Error', 'ai-translator-for-elementor-and-polylang'); ?></option>
                </select>
                <button type="submit" class="button"><?php esc_html_e('Filter', 'ai-translator-for-elementor-and-polylang'); ?></button>
                <?php if ($filter_lang || $filter_type || $filter_status): ?>
                    <a href="<?php echo esc_url($base_url); ?>" class="button button-link"><?php esc_html_e('Clear', 'ai-translator-for-elementor-and-polylang'); ?></a>
                <?php endif; ?>

                <span class="aitrfoel-history-filters-spacer"></span>
                <button type="button" id="aitrfoel-clear-history" class="button aitrfoel-history-clear-btn" style="display:inline-flex;align-items:center;gap:5px;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                    <span><?php esc_html_e('Clear History', 'ai-translator-for-elementor-and-polylang'); ?></span>
                </button>
            </form>

            <!-- History Table -->
            <div class="aitrfoel-history-table-wrap">
            <table class="widefat striped aitrfoel-history-table">
                <thead>
                    <tr>
                        <th class="aitrfoel-col-date"><?php esc_html_e('Date', 'ai-translator-for-elementor-and-polylang'); ?></th>
                        <th><?php esc_html_e('Original Page', 'ai-translator-for-elementor-and-polylang'); ?></th>
                        <th><?php esc_html_e('Translated Page', 'ai-translator-for-elementor-and-polylang'); ?></th>
                        <th class="aitrfoel-col-lang"><?php esc_html_e('Lang', 'ai-translator-for-elementor-and-polylang'); ?></th>
                        <th class="aitrfoel-col-type"><?php esc_html_e('Type', 'ai-translator-for-elementor-and-polylang'); ?></th>
                        <th class="aitrfoel-col-words"><?php esc_html_e('Words', 'ai-translator-for-elementor-and-polylang'); ?></th>
                        <th class="aitrfoel-col-time"><?php esc_html_e('Time', 'ai-translator-for-elementor-and-polylang'); ?></th>
                        <th class="aitrfoel-col-status"><?php esc_html_e('Status', 'ai-translator-for-elementor-and-polylang'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="8" class="aitrfoel-history-empty-row">
                            <?php esc_html_e('No translation history yet. Translations will appear here as you translate pages.', 'ai-translator-for-elementor-and-polylang'); ?>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $orig_title = get_the_title($row->original_post_id) ?: '#' . $row->original_post_id;
                            $trans_title = get_the_title($row->translated_post_id) ?: '#' . $row->translated_post_id;
                            $orig_edit = get_edit_post_link($row->original_post_id, 'raw');
                            $trans_edit = get_edit_post_link($row->translated_post_id, 'raw');
                            $type_badge_colors = [
                                'elementor' => '#92003B',
                                'gutenberg' => '#1e8cbe',
                                'classic'   => '#666',
                            ];
                            $badge_bg = $type_badge_colors[$row->content_type] ?? '#888';
                            $duration_display = $row->duration > 0 ? round($row->duration, 1) . 's' : '—';
                            ?>
                            <tr>
                                <td class="aitrfoel-history-date-cell">
                                    <?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($row->created_at))); ?>
                                </td>
                                <td>
                                    <?php if ($orig_edit): ?>
                                        <a href="<?php echo esc_url($orig_edit); ?>"><?php echo esc_html($orig_title); ?></a>
                                    <?php else: ?>
                                        <?php echo esc_html($orig_title); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($trans_edit): ?>
                                        <a href="<?php echo esc_url($trans_edit); ?>"><?php echo esc_html($trans_title); ?></a>
                                    <?php else: ?>
                                        <?php echo esc_html($trans_title); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php $lang_filter_url = add_query_arg(['page' => 'ai-translator-for-elementor-and-polylang-history', 'lang' => $row->target_lang], admin_url('admin.php')); ?>
                                    <a href="<?php echo esc_url($lang_filter_url); ?>" class="aitrfoel-lang-flag aitrfoel-lang-exists aitrfoel-history-lang-flag" title="<?php echo esc_attr(sprintf(/* translators: %s: language code */ __('Filter by %s', 'ai-translator-for-elementor-and-polylang'), strtoupper($row->target_lang))); ?>"><?php echo esc_html(strtoupper($row->target_lang)); ?></a>
                                </td>
                                <td>
                                    <span class="aitrfoel-history-type-badge aitrfoel-history-type-<?php echo esc_attr(sanitize_html_class($row->content_type)); ?>">
                                        <?php echo esc_html(ucfirst($row->content_type)); ?>
                                    </span>
                                </td>
                                <td class="aitrfoel-history-words-cell"><?php echo number_format($row->word_count); ?></td>
                                <td class="aitrfoel-history-time-cell"><?php echo esc_html($duration_display); ?></td>
                                <td>
                                    <?php if ($row->status === 'success'): ?>
                                        <span class="aitrfoel-history-status is-success" style="display:inline-flex;align-items:center;" title="<?php esc_attr_e('Success', 'ai-translator-for-elementor-and-polylang'); ?>"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
                                    <?php else: ?>
                                        <span class="aitrfoel-history-status is-error" style="display:inline-flex;align-items:center;" title="<?php esc_attr_e('Error', 'ai-translator-for-elementor-and-polylang'); ?>"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="tablenav aitrfoel-history-pagination">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php printf(/* translators: %s: number of history entries */ esc_html__('%s items', 'ai-translator-for-elementor-and-polylang'), number_format($total)); ?>
                    </span>
                    <?php
                    $page_links = paginate_links([
                        'base'    => add_query_arg('paged', '%#%'),
                        'format'  => '',
                        'current' => $current_page,
                        'total'   => $total_pages,
                        'type'    => 'plain',
                    ]);
                    echo $page_links; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- output of core paginate_links()
                    ?>
                </div>
            </div>
            <?php endif; ?>

            <?php endif; // table_exists ?>
            
            <!-- MeepTech Footer Branding -->
            <div class="aitrfoel-footer-branding">
                <span class="aitrfoel-footer-text"><?php esc_html_e('Powered by', 'ai-translator-for-elementor-and-polylang'); ?> <a href="https://meeptech.com" target="_blank" rel="noopener noreferrer">Meep Tech</a> &middot; v<?php echo esc_html(AITRFOEL_VERSION); ?></span>
            </div>
        </div>

        <script>
        jQuery(function($) {
            $('#aitrfoel-clear-history').on('click', function() {
                if (!confirm(<?php echo wp_json_encode(__('Are you sure you want to clear all translation history? This cannot be undone.', 'ai-translator-for-elementor-and-polylang')); ?>)) return;
                var $btn = $(this);
                $btn.prop('disabled', true).text(<?php echo wp_json_encode(__('Clearing...', 'ai-translator-for-elementor-and-polylang')); ?>);
                $.post(ajaxurl, {
                    action: 'aitrfoel_clear_history',
                    nonce: <?php echo wp_json_encode(wp_create_nonce('aitrfoel_ajax_nonce')); ?>
                }, function(resp) {
                    if (resp.success) location.reload();
                    else $btn.prop('disabled', false).text(<?php echo wp_json_encode(__('Clear History', 'ai-translator-for-elementor-and-polylang')); ?>);
                });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX: Clear all history
     */
    public function ajax_clear_history() {
        check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'aitrfoel_translation_history';
        $wpdb->query("TRUNCATE TABLE $table");

        wp_send_json_success();
    }
}
