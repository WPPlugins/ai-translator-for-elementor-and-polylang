<?php
/**
 * WP Admin List Table (edit.php) Meep Translator Integration
 *
 * Provides real-time dynamic word calculation, floating translation toolbar,
 * native bulk actions integration, and 1-click batch translation for standard
 * WordPress Posts, Pages, Products, and custom post types list screens.
 */

if (!defined('ABSPATH')) {
    exit;
}

class AITRFOEL_WP_List_Translator {

    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return AITRFOEL_WP_List_Translator
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_init', [$this, 'init']);
    }

    /**
     * Initialize hooks on edit.php screens
     */
    public function init() {
        if (!is_admin()) {
            return;
        }

        // Register bulk actions for all supported translatable post types
        $post_types = $this->get_supported_post_types();
        foreach ($post_types as $pt) {
            add_filter("bulk_actions-edit-{$pt}", [$this, 'register_bulk_action']);
        }

        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_footer-edit.php', [$this, 'render_translator_ui']);
        add_action('wp_ajax_aitrfoel_save_toolbar_visibility', [$this, 'handle_save_toolbar_visibility']);
    }

    /**
     * AJAX handler to save floating toolbar visibility preference
     */
    public function handle_save_toolbar_visibility() {
        check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
        if (!current_user_can('edit_pages')) {
            wp_send_json_error('Unauthorized');
        }
        $enabled = isset($_POST['enabled']) ? (int) $_POST['enabled'] : 1;
        update_option('aitrfoel_list_toolbar_enabled', (bool) $enabled);
        wp_send_json_success(['enabled' => (bool) $enabled]);
    }

    /**
     * Get supported post types for list table translation
     *
     * @return array
     */
    public function get_supported_post_types() {
        $exclude = [
            'attachment', 'revision', 'nav_menu_item', 'custom_css',
            'customize_changeset', 'oembed_cache', 'user_request',
            'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part',
            'wp_global_styles', 'action_scheduler'
        ];

        $post_types = [];
        foreach (get_post_types(['show_ui' => true], 'names') as $pt) {
            if (!in_array($pt, $exclude, true)) {
                $post_types[] = $pt;
            }
        }

        return apply_filters('aitrfoel_list_translator_post_types', $post_types);
    }

    /**
     * Add Meep Translate to native WordPress Bulk Actions dropdown
     *
     * @param array $bulk_actions
     * @return array
     */
    public function register_bulk_action($bulk_actions) {
        $multilingual = class_exists('AITRFOEL_Multilingual_Manager') ? AITRFOEL_Multilingual_Manager::get_instance() : null;
        if ($multilingual && $multilingual->is_active()) {
            $bulk_actions['aitrfoel_translate'] = __('Translate with Meep', 'ai-translator-for-elementor-and-polylang');
        }
        return $bulk_actions;
    }

    /**
     * Check if the current admin screen is a supported list table
     *
     * @param string $hook
     * @return bool
     */
    private function is_target_screen($hook) {
        if ($hook !== 'edit.php') {
            return false;
        }

        $screen = get_current_screen();
        if (!$screen || empty($screen->post_type)) {
            return false;
        }

        return in_array($screen->post_type, $this->get_supported_post_types(), true);
    }

    /**
     * Enqueue assets and localize real-time word counting data on edit.php
     *
     * @param string $hook
     */
    public function enqueue_assets($hook) {
        if (!$this->is_target_screen($hook)) {
            return;
        }

        $multilingual = class_exists('AITRFOEL_Multilingual_Manager') ? AITRFOEL_Multilingual_Manager::get_instance() : null;
        if (!$multilingual || !$multilingual->is_active()) {
            return;
        }

        $available_languages = $multilingual->get_available_languages();
        if (empty($available_languages) || count($available_languages) < 2) {
            return;
        }

        $default_lang = $multilingual->get_default_language();

        // Build language list formatted for UI
        $languages_data = [];
        foreach ($available_languages as $code => $lang) {
            $name = is_object($lang)
                ? ($lang->name ?? strtoupper($code))
                : (is_array($lang) ? ($lang['name'] ?? strtoupper($code)) : strtoupper($code));

            $flag_html = class_exists('AITRFOEL_Language_Mapper')
                ? AITRFOEL_Language_Mapper::get_flag_html($code, 16, 'margin-right:6px;vertical-align:middle;')
                : '';

            $languages_data[$code] = [
                'code'       => $code,
                'name'       => $name,
                'flag'       => $flag_html,
                'is_default' => ($code === $default_lang),
            ];
        }

        // Get license & quota data
        $license_mgr = class_exists('AITRFOEL_License_Manager_Server') ? AITRFOEL_License_Manager_Server::get_instance() : null;
        $lic_status  = $license_mgr ? $license_mgr->get_license_status() : [];
        $words_used  = isset($lic_status['words_used']) ? (int) $lic_status['words_used'] : (int) ($lic_status['words_translated'] ?? 0);
        $word_limit  = isset($lic_status['word_limit']) ? (int) $lic_status['word_limit'] : (int) ($lic_status['total_words'] ?? 7200);
        $is_unlimited = (!empty($lic_status['unlimited']) || !empty($lic_status['is_unlimited']));
        $words_left  = $is_unlimited ? 999999999 : max(0, $word_limit - $words_used);
        $plan_name   = !empty($lic_status['plan_name']) ? $lic_status['plan_name'] : (!empty($lic_status['plan']) ? ucfirst($lic_status['plan']) : 'Free');

        // Pre-populate word counts for all posts currently loaded in WP_Query
        $word_counts = [];
        $detector    = class_exists('AITRFOEL_Content_Detector') ? AITRFOEL_Content_Detector::get_instance() : null;

        global $wp_query;
        if (!empty($wp_query->posts) && is_array($wp_query->posts)) {
            foreach ($wp_query->posts as $post) {
                if ($detector) {
                    $word_counts[$post->ID] = (int) $detector->get_cached_word_count($post->ID);
                } else {
                    $cached = get_post_meta($post->ID, '_aitrfoel_word_count', true);
                    $word_counts[$post->ID] = ($cached !== '' && is_numeric($cached)) ? (int) $cached : 0;
                }
            }
        }

        wp_enqueue_style(
            'aitrfoel-wp-list-translator',
            AITRFOEL_PLUGIN_URL . 'assets/css/wp-list-translator.css',
            [],
            AITRFOEL_VERSION
        );

        wp_enqueue_script(
            'aitrfoel-wp-list-translator',
            AITRFOEL_PLUGIN_URL . 'assets/js/wp-list-translator.js',
            ['jquery'],
            AITRFOEL_VERSION,
            true
        );

        $toolbar_enabled = (bool) get_option('aitrfoel_list_toolbar_enabled', true);

        wp_localize_script('aitrfoel-wp-list-translator', 'aitrfoelListTranslator', [
            'ajax_url'        => esc_url(admin_url('admin-ajax.php')),
            'nonce'           => wp_create_nonce('aitrfoel_ajax_nonce'),
            'default_lang'    => $default_lang,
            'languages'       => $languages_data,
            'word_counts'     => $word_counts,
            'words_left'      => $words_left,
            'words_used'      => $words_used,
            'word_limit'      => $word_limit,
            'is_unlimited'    => $is_unlimited,
            'plan_name'       => $plan_name,
            'is_pro'          => ($plan_name !== '' && strtolower($plan_name) !== 'free'),
            'bonus_claimed'   => (bool) get_option('aitrfoel_share_bonus_claimed', false),
            'upgrade_url'     => 'https://meeptech.com/products/ai-translator-for-elementor-polylang/?utm_source=wp-plugin&utm_medium=list-modal&utm_campaign=pro-upgrade#pricing',
            'topup_url'       => 'https://meeptech.com/products/ai-translator-for-elementor-polylang/?utm_source=wp-plugin&utm_medium=list-modal&utm_campaign=extra-words#pricing',
            'toolbar_enabled' => $toolbar_enabled,
            'settings_url'    => esc_url(admin_url('admin.php?page=ai-translator-for-elementor-and-polylang-settings')),
            'strings'         => [
                'items_selected'      => /* translators: %d: number of selected items */ _n('%d item selected', '%d items selected', 1, 'ai-translator-for-elementor-and-polylang'),
                'items_selected_pl'   => /* translators: %d: number of selected items */ _n('%d item selected', '%d items selected', 2, 'ai-translator-for-elementor-and-polylang'),
                'words_needed'        => /* translators: %s: number of words needed */ __('~%s words needed', 'ai-translator-for-elementor-and-polylang'),
                'words_remaining'     => /* translators: %s: number of words remaining */ __('%s words remaining', 'ai-translator-for-elementor-and-polylang'),
                'unlimited_words'     => __('Unlimited plan words', 'ai-translator-for-elementor-and-polylang'),
                'quota_exceeded'      => /* translators: %s: number of words left */ __('Exceeds plan limit (%s left)', 'ai-translator-for-elementor-and-polylang'),
                'select_target_langs' => __('Select languages', 'ai-translator-for-elementor-and-polylang'),
                'translate_selected'  => __('Translate Selected', 'ai-translator-for-elementor-and-polylang'),
                'translating'         => __('AI is translating content…', 'ai-translator-for-elementor-and-polylang'),
                'translating_item'    => /* translators: 1: item title, 2: target language */ __('Translating "%s" to %s…', 'ai-translator-for-elementor-and-polylang'),
                'done'                => __('Translation completed!', 'ai-translator-for-elementor-and-polylang'),
                'error'               => __('Translation failed. Please try again.', 'ai-translator-for-elementor-and-polylang'),
                'close'               => __('Close', 'ai-translator-for-elementor-and-polylang'),
                'view_page'           => __('View', 'ai-translator-for-elementor-and-polylang'),
                'edit_page'           => __('Edit', 'ai-translator-for-elementor-and-polylang'),
                'deselect'            => __('Deselect', 'ai-translator-for-elementor-and-polylang'),
                'all_languages'       => __('All target languages', 'ai-translator-for-elementor-and-polylang'),
                'hidden_session'      => __('Toolbar hidden for this session', 'ai-translator-for-elementor-and-polylang'),
                'hidden_forever'      => __('Toolbar hidden permanently (re-enable in Settings)', 'ai-translator-for-elementor-and-polylang'),
            ],
        ]);
    }

    /**
     * Render floating toolbar and modal templates in admin footer
     */
    public function render_translator_ui() {
        $screen = get_current_screen();
        if (!$screen || empty($screen->post_type) || !in_array($screen->post_type, $this->get_supported_post_types(), true)) {
            return;
        }

        $multilingual = class_exists('AITRFOEL_Multilingual_Manager') ? AITRFOEL_Multilingual_Manager::get_instance() : null;
        if (!$multilingual || !$multilingual->is_active()) {
            return;
        }

        $available_languages = $multilingual->get_available_languages();
        if (empty($available_languages) || count($available_languages) < 2) {
            return;
        }

        $default_lang = $multilingual->get_default_language();
        $toolbar_enabled = (bool) get_option('aitrfoel_list_toolbar_enabled', true);
        ?>
        <?php if ($toolbar_enabled) : ?>
        <!-- Meep Floating List Translator Bar -->
        <div id="aitrfoel-list-bar" class="aitrfoel-list-bar" style="display:none;" aria-live="polite">
            <div class="aitrfoel-list-bar-inner">
                <div class="aitrfoel-list-bar-left">
                    <span class="aitrfoel-list-logo">
                        <svg viewBox="0 0 44.68 29.3" width="18" height="12" style="display:inline-block;vertical-align:middle;"><path fill="currentColor" d="M0 29.3l7.84 0 0 -16.56c0,-5.02 3.39,-5.9 5.21,-5.9 1.82,0 5.34,0.88 5.34,5.9l0 16.56 7.84 0 0 -16.56c0,-5.02 3.45,-5.9 5.21,-5.9 1.82,0 5.39,0.88 5.39,5.9l0 16.56 7.85 0 0 -17.63c0,-9.04 -5.71,-11.67 -10.8,-11.67 -3.2,0 -7.02,1.07 -9.47,4.39 -2.07,-3.26 -5.58,-4.39 -8.91,-4.39 -2.64,0 -5.52,1.01 -7.66,3.33l0 -2.89 -7.84 0 0 28.86 0 0z"/></svg>
                        <strong><?php esc_html_e('Meep', 'ai-translator-for-elementor-and-polylang'); ?></strong>
                    </span>
                    <span class="aitrfoel-list-count-badge">
                        <span id="aitrfoel-list-count">0</span> <?php esc_html_e('selected', 'ai-translator-for-elementor-and-polylang'); ?>
                    </span>
                    <button type="button" class="aitrfoel-list-clear-btn" id="aitrfoel-list-clear" title="<?php esc_attr_e('Deselect all', 'ai-translator-for-elementor-and-polylang'); ?>">
                        <?php esc_html_e('Deselect', 'ai-translator-for-elementor-and-polylang'); ?>
                    </button>
                </div>

                <div class="aitrfoel-list-bar-center">
                    <div class="aitrfoel-list-words-box">
                        <span class="aitrfoel-list-words-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                        </span>
                        <strong id="aitrfoel-list-words-num">~0</strong>
                        <span class="aitrfoel-list-words-label"><?php esc_html_e('words needed', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        <span id="aitrfoel-list-quota-pill" class="aitrfoel-list-quota-pill"></span>
                    </div>

                    <div class="aitrfoel-list-lang-picker">
                        <span class="aitrfoel-list-lang-label"><?php esc_html_e('To:', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        <div class="aitrfoel-list-lang-dropdown-wrap">
                            <button type="button" class="button button-secondary aitrfoel-list-lang-btn" id="aitrfoel-list-lang-btn">
                                <span id="aitrfoel-list-lang-btn-text"><?php esc_html_e('Select languages', 'ai-translator-for-elementor-and-polylang'); ?></span>
                                <span class="dashicons dashicons-arrow-down-alt2"></span>
                            </button>
                            <div id="aitrfoel-list-lang-dropdown" class="aitrfoel-list-lang-dropdown" style="display:none;">
                                <div class="aitrfoel-list-lang-select-all">
                                    <label>
                                        <input type="checkbox" id="aitrfoel-list-lang-toggle-all" checked>
                                        <strong><?php esc_html_e('All Languages', 'ai-translator-for-elementor-and-polylang'); ?></strong>
                                    </label>
                                </div>
                                <div class="aitrfoel-list-lang-items">
                                    <?php foreach ($available_languages as $code => $lang) : 
                                        if ($code === $default_lang) continue;
                                        $name = is_object($lang) ? ($lang->name ?? strtoupper($code)) : (is_array($lang) ? ($lang['name'] ?? strtoupper($code)) : strtoupper($code));
                                        $flag = class_exists('AITRFOEL_Language_Mapper') ? AITRFOEL_Language_Mapper::get_flag_html($code, 16, 'margin-right:6px;vertical-align:middle;') : '';
                                    ?>
                                        <label class="aitrfoel-list-lang-item">
                                            <input type="checkbox" class="aitrfoel-list-target-lang-cb" value="<?php echo esc_attr($code); ?>" checked>
                                            <?php echo $flag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                            <span><?php echo esc_html($name); ?></span>
                                            <span class="aitrfoel-list-lang-code">(<?php echo esc_html(strtoupper($code)); ?>)</span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="aitrfoel-list-bar-right">
                    <button type="button" class="button button-primary aitrfoel-list-action-btn" id="aitrfoel-list-translate-btn">
                        <span class="dashicons dashicons-translation" style="margin-top:2px;margin-right:4px;"></span>
                        <span><?php esc_html_e('Translate Selected', 'ai-translator-for-elementor-and-polylang'); ?></span>
                    </button>
                    
                    <div class="aitrfoel-list-dismiss-wrap">
                        <button type="button" class="aitrfoel-list-dismiss-btn" id="aitrfoel-list-dismiss" title="<?php esc_attr_e('Toolbar options', 'ai-translator-for-elementor-and-polylang'); ?>">
                            <span class="dashicons dashicons-no-alt"></span>
                        </button>
                        <div id="aitrfoel-list-dismiss-menu" class="aitrfoel-list-dismiss-menu" style="display:none;">
                            <button type="button" class="aitrfoel-dismiss-menu-item" id="aitrfoel-dismiss-session">
                                <span class="dashicons dashicons-visibility"></span>
                                <?php esc_html_e('Hide for this session', 'ai-translator-for-elementor-and-polylang'); ?>
                            </button>
                            <button type="button" class="aitrfoel-dismiss-menu-item aitrfoel-dismiss-danger" id="aitrfoel-dismiss-forever">
                                <span class="dashicons dashicons-hidden"></span>
                                <?php esc_html_e('Hide permanently', 'ai-translator-for-elementor-and-polylang'); ?>
                            </button>
                            <div class="aitrfoel-dismiss-menu-divider"></div>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=ai-translator-for-elementor-and-polylang-settings')); ?>" class="aitrfoel-dismiss-menu-item aitrfoel-dismiss-settings-link" target="_blank">
                                <span class="dashicons dashicons-admin-generic"></span>
                                <?php esc_html_e('Configure in Settings', 'ai-translator-for-elementor-and-polylang'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Meep List Translation Progress Modal -->
        <div id="aitrfoel-list-modal" class="aitrfoel-list-modal" style="display:none;" role="dialog" aria-modal="true">
            <div class="aitrfoel-list-modal-backdrop"></div>
            <div class="aitrfoel-list-modal-dialog">
                <div class="aitrfoel-list-modal-header">
                    <div class="aitrfoel-list-modal-brand">
                        <svg viewBox="0 0 44.68 29.3" width="22" height="14" fill="#2563eb"><path fill="currentColor" d="M0 29.3l7.84 0 0 -16.56c0,-5.02 3.39,-5.9 5.21,-5.9 1.82,0 5.34,0.88 5.34,5.9l0 16.56 7.84 0 0 -16.56c0,-5.02 3.45,-5.9 5.21,-5.9 1.82,0 5.39,0.88 5.39,5.9l0 16.56 7.85 0 0 -17.63c0,-9.04 -5.71,-11.67 -10.8,-11.67 -3.2,0 -7.02,1.07 -9.47,4.39 -2.07,-3.26 -5.58,-4.39 -8.91,-4.39 -2.64,0 -5.52,1.01 -7.66,3.33l0 -2.89 -7.84 0 0 28.86 0 0z"/></svg>
                        <h3><?php esc_html_e('Meep Translation', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                    </div>
                    <span id="aitrfoel-list-modal-timer" class="aitrfoel-list-modal-timer">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> <span id="aitrfoel-list-timer-val">0:00</span>
                    </span>
                </div>

                <div class="aitrfoel-list-modal-body">
                    <!-- In Progress View -->
                    <div id="aitrfoel-list-modal-progress-view">
                        <div class="aitrfoel-list-progress-top">
                            <div class="aitrfoel-list-progress-status">
                                <span class="spinner is-active" style="float:none; margin:0; width:18px; height:18px; background-size:18px 18px;"></span>
                                <span id="aitrfoel-list-progress-title"><?php esc_html_e('Preparing AI translations…', 'ai-translator-for-elementor-and-polylang'); ?></span>
                            </div>
                            <span id="aitrfoel-list-progress-pct">0%</span>
                        </div>

                        <div class="aitrfoel-list-progress-track">
                            <div id="aitrfoel-list-progress-fill" class="aitrfoel-list-progress-fill" style="width: 0%;"></div>
                        </div>

                        <div id="aitrfoel-list-progress-details" class="aitrfoel-list-progress-details">
                            <span id="aitrfoel-list-current-item-text"><?php esc_html_e('Initializing pipeline…', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </div>

                        <div id="aitrfoel-list-log" class="aitrfoel-list-log"></div>
                    </div>

                    <!-- Completed View -->
                    <div id="aitrfoel-list-modal-done-view" style="display:none;">
                        <div class="aitrfoel-list-done-hero">
                            <div class="aitrfoel-list-done-icon">✓</div>
                            <h4><?php esc_html_e('Translation Complete!', 'ai-translator-for-elementor-and-polylang'); ?></h4>
                            <p id="aitrfoel-list-done-summary"></p>
                        </div>
                        <div id="aitrfoel-list-results-list" class="aitrfoel-list-results-list"></div>
                    </div>
                </div>

                <div class="aitrfoel-list-modal-footer">
                    <button type="button" class="button" id="aitrfoel-list-modal-cancel"><?php esc_html_e('Cancel', 'ai-translator-for-elementor-and-polylang'); ?></button>
                    <button type="button" class="button button-primary" id="aitrfoel-list-modal-done" style="display:none;"><?php esc_html_e('Done', 'ai-translator-for-elementor-and-polylang'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }
}
