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
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return;
        }

        $wpdb->insert($table, [
            'original_post_id'   => absint($args['original_post_id'] ?? 0),
            'translated_post_id' => absint($args['translated_post_id'] ?? 0),
            'source_lang'        => sanitize_text_field($args['source_lang'] ?? ''),
            'target_lang'        => sanitize_text_field($args['target_lang'] ?? ''),
            'content_type'       => sanitize_text_field($args['content_type'] ?? 'elementor'),
            'word_count'         => absint($args['word_count'] ?? 0),
            'status'             => sanitize_text_field($args['status'] ?? 'success'),
            'duration'           => floatval($args['duration'] ?? 0),
            'user_id'            => get_current_user_id(),
            'created_at'         => current_time('mysql'),
        ], ['%d', '%d', '%s', '%s', '%s', '%d', '%s', '%f', '%d', '%s']);
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
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;

        $rows = [];
        $total = 0;
        $summary = ['total_translations' => 0, 'total_words' => 0, 'languages' => []];

        if ($table_exists) {
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
            $total = $params ? (int) $wpdb->get_var($wpdb->prepare($count_query, ...$params)) : (int) $wpdb->get_var($count_query);

            // Fetch rows
            $query = "SELECT * FROM $table $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d";
            $all_params = array_merge($params, [$per_page, $offset]);
            $rows = $wpdb->get_results($wpdb->prepare($query, ...$all_params));

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
            <div class="aitrfoel-history-summary" style="display: flex; gap: 16px; margin-bottom: 20px; flex-wrap: wrap;">
                <div class="aitrfoel-history-card" style="background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 16px 24px; min-width: 160px;">
                    <div class="aitrfoel-history-number"><?php echo number_format($summary['total_translations']); ?></div>
                    <div class="aitrfoel-history-label"><?php esc_html_e('Translations', 'ai-translator-for-elementor-and-polylang'); ?></div>
                </div>
                <div class="aitrfoel-history-card" style="background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 16px 24px; min-width: 160px;">
                    <div class="aitrfoel-history-number"><?php echo number_format($summary['total_words']); ?></div>
                    <div class="aitrfoel-history-label"><?php esc_html_e('Words Translated', 'ai-translator-for-elementor-and-polylang'); ?></div>
                </div>
                <div class="aitrfoel-history-card" style="background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 16px 24px; min-width: 160px;">
                    <div class="aitrfoel-history-number"><?php echo count($summary['languages']); ?></div>
                    <div class="aitrfoel-history-label"><?php esc_html_e('Languages', 'ai-translator-for-elementor-and-polylang'); ?></div>
                </div>
                <?php if (!empty($summary['languages'])): ?>
                <div class="aitrfoel-history-card" style="background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 16px 24px; flex: 1; min-width: 200px;">
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <?php foreach ($summary['languages'] as $code => $cnt):
                            $filter_url = add_query_arg(['page' => 'ai-translator-for-elementor-and-polylang-history', 'lang' => $code], admin_url('admin.php'));
                            $is_active = (isset($_GET['lang']) && $_GET['lang'] === $code);
                        ?>
                            <a href="<?php echo esc_url($filter_url); ?>" style="display: inline-block; background: <?php echo $is_active ? '#2271b1' : '#f0f6fc'; ?>; border: 1px solid <?php echo $is_active ? '#2271b1' : '#c3d7ef'; ?>; border-radius: 4px; padding: 2px 8px; font-size: 12px; font-weight: 600; text-decoration: none; color: <?php echo $is_active ? '#fff' : 'inherit'; ?>;" title="<?php echo esc_attr(sprintf(__('Filter by %s', 'ai-translator-for-elementor-and-polylang'), strtoupper($code))); ?>">
                                <?php echo esc_html(strtoupper($code)); ?> <span style="color: <?php echo $is_active ? 'rgba(255,255,255,0.7)' : '#646970'; ?>; font-weight: 400;">(<?php echo $cnt; ?>)</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <div style="color: #646970; font-size: 13px; margin-top: 6px;"><?php esc_html_e('Per Language', 'ai-translator-for-elementor-and-polylang'); ?></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Filters -->
            <form method="get" style="margin-bottom: 12px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                <input type="hidden" name="page" value="ai-translator-for-elementor-and-polylang-history" />
                <style>
                    .aitrfoel-history-summary select { min-width: 130px; padding: 4px 28px 4px 8px; -webkit-appearance: none; -moz-appearance: none; appearance: none; background: #fff url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20width%3D%2220%22%20height%3D%2220%22%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%3E%3Cpath%20d%3D%22M5%206l5%205%205-5%22%20fill%3D%22none%22%20stroke%3D%22%23646970%22%20stroke-width%3D%221.5%22%2F%3E%3C%2Fsvg%3E') no-repeat right 6px center; background-size: 16px; border: 1px solid #8c8f94; border-radius: 4px; cursor: pointer; }
                    form[method="get"] select { min-width: 130px; padding: 4px 28px 4px 8px; -webkit-appearance: none; -moz-appearance: none; appearance: none; background: #fff url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20width%3D%2220%22%20height%3D%2220%22%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%3E%3Cpath%20d%3D%22M5%206l5%205%205-5%22%20fill%3D%22none%22%20stroke%3D%22%23646970%22%20stroke-width%3D%221.5%22%2F%3E%3C%2Fsvg%3E') no-repeat right 6px center; background-size: 16px; border: 1px solid #8c8f94; border-radius: 4px; cursor: pointer; }
                </style>
                <?php if (!empty($summary['languages'])): ?>
                <select name="lang">
                    <option value=""><?php esc_html_e('All Languages', 'ai-translator-for-elementor-and-polylang'); ?></option>
                    <?php foreach (array_keys($summary['languages']) as $code): ?>
                        <option value="<?php echo esc_attr($code); ?>" <?php selected($filter_lang, $code); ?>><?php echo esc_html(strtoupper($code)); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <select name="ctype">
                    <option value=""><?php esc_html_e('All Types', 'ai-translator-for-elementor-and-polylang'); ?></option>
                    <option value="elementor" <?php selected($filter_type, 'elementor'); ?>>Elementor</option>
                    <option value="gutenberg" <?php selected($filter_type, 'gutenberg'); ?>>Gutenberg</option>
                    <option value="classic" <?php selected($filter_type, 'classic'); ?>>Classic</option>
                </select>
                <select name="status">
                    <option value=""><?php esc_html_e('All Statuses', 'ai-translator-for-elementor-and-polylang'); ?></option>
                    <option value="success" <?php selected($filter_status, 'success'); ?>>✅ <?php esc_html_e('Success', 'ai-translator-for-elementor-and-polylang'); ?></option>
                    <option value="error" <?php selected($filter_status, 'error'); ?>>❌ <?php esc_html_e('Error', 'ai-translator-for-elementor-and-polylang'); ?></option>
                </select>
                <button type="submit" class="button"><?php esc_html_e('Filter', 'ai-translator-for-elementor-and-polylang'); ?></button>
                <?php if ($filter_lang || $filter_type || $filter_status): ?>
                    <a href="<?php echo esc_url($base_url); ?>" class="button button-link"><?php esc_html_e('Clear', 'ai-translator-for-elementor-and-polylang'); ?></a>
                <?php endif; ?>

                <span style="flex: 1;"></span>
                <button type="button" id="aitrfoel-clear-history" class="button" style="color: #d63638;">
                    🗑️ <?php esc_html_e('Clear History', 'ai-translator-for-elementor-and-polylang'); ?>
                </button>
            </form>

            <!-- History Table -->
            <table class="widefat striped" style="border-radius: 6px; overflow: hidden;">
                <thead>
                    <tr>
                        <th style="width: 160px;"><?php esc_html_e('Date', 'ai-translator-for-elementor-and-polylang'); ?></th>
                        <th><?php esc_html_e('Original Page', 'ai-translator-for-elementor-and-polylang'); ?></th>
                        <th><?php esc_html_e('Translated Page', 'ai-translator-for-elementor-and-polylang'); ?></th>
                        <th style="width: 60px;"><?php esc_html_e('Lang', 'ai-translator-for-elementor-and-polylang'); ?></th>
                        <th style="width: 90px;"><?php esc_html_e('Type', 'ai-translator-for-elementor-and-polylang'); ?></th>
                        <th style="width: 80px;"><?php esc_html_e('Words', 'ai-translator-for-elementor-and-polylang'); ?></th>
                        <th style="width: 70px;"><?php esc_html_e('Time', 'ai-translator-for-elementor-and-polylang'); ?></th>
                        <th style="width: 70px;"><?php esc_html_e('Status', 'ai-translator-for-elementor-and-polylang'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="8" style="text-align: center; padding: 30px; color: #646970;">
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
                                <td style="color: #646970; font-size: 12px;">
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
                                    <a href="<?php echo esc_url($lang_filter_url); ?>" class="eat-lang-flag eat-lang-exists" style="text-decoration: none; cursor: pointer;" title="<?php echo esc_attr(sprintf(__('Filter by %s', 'ai-translator-for-elementor-and-polylang'), strtoupper($row->target_lang))); ?>"><?php echo esc_html(strtoupper($row->target_lang)); ?></a>
                                </td>
                                <td>
                                    <span style="display: inline-block; background: <?php echo esc_attr($badge_bg); ?>; color: #fff; font-size: 11px; padding: 2px 8px; border-radius: 3px; font-weight: 500;">
                                        <?php echo esc_html(ucfirst($row->content_type)); ?>
                                    </span>
                                </td>
                                <td style="text-align: right;"><?php echo number_format($row->word_count); ?></td>
                                <td style="text-align: right; color: #646970; font-size: 12px;"><?php echo esc_html($duration_display); ?></td>
                                <td>
                                    <?php if ($row->status === 'success'): ?>
                                        <span style="color: #00a32a;">✅</span>
                                    <?php else: ?>
                                        <span style="color: #d63638;">❌</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="tablenav" style="margin-top: 12px;">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php printf(esc_html__('%s items', 'ai-translator-for-elementor-and-polylang'), number_format($total)); ?>
                    </span>
                    <?php
                    $page_links = paginate_links([
                        'base'    => add_query_arg('paged', '%#%'),
                        'format'  => '',
                        'current' => $current_page,
                        'total'   => $total_pages,
                        'type'    => 'plain',
                    ]);
                    echo $page_links;
                    ?>
                </div>
            </div>
            <?php endif; ?>

            <?php endif; // table_exists ?>
            
            <!-- MeepTech Footer Branding -->
            <div class="aitrfoel-footer-branding">
                <a href="https://meeptech.com" target="_blank" rel="noopener noreferrer">
                    <img src="<?php echo esc_url(AITRFOEL_PLUGIN_URL . 'assets/img/meeptech.svg'); ?>" alt="Meep Tech" class="aitrfoel-footer-logo">
                </a>
                <span class="aitrfoel-footer-text"><?php esc_html_e('Powered by', 'ai-translator-for-elementor-and-polylang'); ?> <a href="https://meeptech.com" target="_blank" rel="noopener noreferrer">Meep Tech</a></span>
            </div>
        </div>

        <style>
            .aitrfoel-history-card { transition: box-shadow 0.2s; }
            .aitrfoel-history-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
            .aitrfoel-history-number { font-size: 28px; font-weight: 700; color: #1d2327; margin-bottom: 8px; line-height: 1; }
            .aitrfoel-history-label { color: #646970; font-size: 13px; line-height: 1.3; }
            .eat-lang-flag { display: inline-block; font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 2px 6px; border-radius: 3px; line-height: 1.3; }
            .eat-lang-flag.eat-lang-exists { background: #2271b1; color: #fff; }
        </style>

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
