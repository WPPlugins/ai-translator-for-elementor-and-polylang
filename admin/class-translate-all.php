<?php
/**
 * Translate All Admin Page
 * Allows users to translate all content on the site in bulk,
 * choosing post types, viewing word calculations, and scheduling cron jobs.
 */

if (!defined('ABSPATH')) exit;

class AITRFOEL_Translate_All {

    /**
     * Server usage factor: changed to 1 because the API server only counts
     * source words translated.
     */
    const SERVER_USAGE_FACTOR = 1;

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // AJAX endpoints
        add_action('wp_ajax_aitrfoel_translate_all_scan', [$this, 'ajax_scan_content']);
        add_action('wp_ajax_aitrfoel_translate_all_start', [$this, 'ajax_start_translation']);
        add_action('wp_ajax_aitrfoel_translate_all_status', [$this, 'ajax_get_status']);
        add_action('wp_ajax_aitrfoel_translate_all_cancel', [$this, 'ajax_cancel']);
        add_action('wp_ajax_aitrfoel_translate_all_pause', [$this, 'ajax_pause']);
        add_action('wp_ajax_aitrfoel_translate_all_resume', [$this, 'ajax_resume']);
    }

    /**
     * Enqueue page-specific assets
     */
    public function enqueue_assets() {
        wp_enqueue_style(
            'aitrfoel-translate-all',
            AITRFOEL_PLUGIN_URL . 'assets/css/translate-all.css',
            ['aitrfoel-meep-admin'],
            AITRFOEL_VERSION
        );

        wp_enqueue_script(
            'aitrfoel-translate-all',
            AITRFOEL_PLUGIN_URL . 'assets/js/translate-all.js',
            ['jquery'],
            AITRFOEL_VERSION,
            true
        );

        // Get license info for JS
        $license_info = $this->get_license_info();

        $builtin_active = class_exists('AITRFOEL_Builtin_Integration') && AITRFOEL_Builtin_Integration::is_enabled();

        wp_localize_script('aitrfoel-translate-all', 'aitrfoel_translate_all', [
            'ajax_url'  => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('aitrfoel_translate_all_nonce'),
            'ajax_nonce' => wp_create_nonce('aitrfoel_ajax_nonce'),
            'builtin_active' => $builtin_active,
            'plan'      => $license_info['plan'],
            'word_limit' => $license_info['word_limit'],
            'words_used' => $license_info['words_used'],
            'words_left' => $license_info['words_left'],
            'server_usage_factor' => self::SERVER_USAGE_FACTOR,
            'upgrade_url' => $this->get_upgrade_url(),
            'is_pro' => (defined('AITRFOEL_VERSION_TYPE') && AITRFOEL_VERSION_TYPE === 'pro'),
            'strings'   => [
                'scanning'            => __('Scanning content...', 'ai-translator-for-elementor-and-polylang'),
                'scan_complete'       => __('Scan complete', 'ai-translator-for-elementor-and-polylang'),
                'no_content'          => __('No translatable content found for the selected types.', 'ai-translator-for-elementor-and-polylang'),
                'confirm_start'       => __('Start translation? This will translate all selected content.', 'ai-translator-for-elementor-and-polylang'),
                'translating'         => __('Translating...', 'ai-translator-for-elementor-and-polylang'),
                'completed'           => __('Translation completed!', 'ai-translator-for-elementor-and-polylang'),
                'cancelled'           => __('Translation cancelled.', 'ai-translator-for-elementor-and-polylang'),
                'error'               => __('An error occurred.', 'ai-translator-for-elementor-and-polylang'),
                'words'               => __('words', 'ai-translator-for-elementor-and-polylang'),
                'pages'               => __('pages', 'ai-translator-for-elementor-and-polylang'),
                'posts'               => __('posts', 'ai-translator-for-elementor-and-polylang'),
                'items'               => __('items', 'ai-translator-for-elementor-and-polylang'),
                'exceeds_limit'       => __('Total words exceed your plan limit.', 'ai-translator-for-elementor-and-polylang'),
                'upgrade_prompt'      => __('Upgrade your plan for more words or use scheduled translation.', 'ai-translator-for-elementor-and-polylang'),
                'schedule_info'       => __('Scheduled translation will process content over multiple billing cycles using your monthly word allowance.', 'ai-translator-for-elementor-and-polylang'),
                'select_post_type'    => __('Please select at least one content type.', 'ai-translator-for-elementor-and-polylang'),
                'select_language'     => __('Please select at least one target language.', 'ai-translator-for-elementor-and-polylang'),
                'processing'          => __('Processing...', 'ai-translator-for-elementor-and-polylang'),
                'of'                  => __('of', 'ai-translator-for-elementor-and-polylang'),
                'estimated_time'      => __('Estimated time remaining', 'ai-translator-for-elementor-and-polylang'),
                'cron_scheduled'      => __('Scheduled translation started! It will process content using your monthly word allowance.', 'ai-translator-for-elementor-and-polylang'),
                'already_translated'  => __('already translated', 'ai-translator-for-elementor-and-polylang'),
                'needs_translation'   => __('needs translation', 'ai-translator-for-elementor-and-polylang'),
                'translate_all_now'   => __('Translate All Now', 'ai-translator-for-elementor-and-polylang'),
                'translate_selected_now' => /* translators: %d: number of selected items */ __('Translate Selected (%d) Now', 'ai-translator-for-elementor-and-polylang'),

                // Quota check panel
                'good_news'           => __('Good news!', 'ai-translator-for-elementor-and-polylang'),
                /* translators: 1: estimated word count, 2: words remaining */
                'quota_fits'          => __('Your selection will use an estimated %1$s words of API quota and you have %2$s words remaining.', 'ai-translator-for-elementor-and-polylang'),
                'can_translate_now'   => __('You can translate everything now!', 'ai-translator-for-elementor-and-polylang'),
                'already_done'        => __('All content is already translated. Nothing to do!', 'ai-translator-for-elementor-and-polylang'),
                'exceeds_allowance'   => __('Content exceeds your current word allowance', 'ai-translator-for-elementor-and-polylang'),
                /* translators: 1: estimated word count, 2: words remaining */
                'quota_exceeds'       => __('Your selection will use an estimated %1$s words of API quota, but you only have %2$s words remaining this month.', 'ai-translator-for-elementor-and-polylang'),
                'translate_now_partial' => __('Translate Now (Partial)', 'ai-translator-for-elementor-and-polylang'),
                /* translators: %s: words remaining (formatted number) */
                'translate_partial_desc' => __('Translate as much as possible with your remaining %s words.', 'ai-translator-for-elementor-and-polylang'),
                'translation_failed'  => __('Translation failed.', 'ai-translator-for-elementor-and-polylang'),

                // Review prompt
                'enjoying_plugin'     => __('Enjoying Meep AI Translator? A quick review helps us a lot!', 'ai-translator-for-elementor-and-polylang'),
                'leave_review'        => __('Leave a Review', 'ai-translator-for-elementor-and-polylang'),
                'no_thanks'           => __('No thanks', 'ai-translator-for-elementor-and-polylang'),
            ],
        ]);
    }

    /**
     * Get license/usage info
     */
    private function get_license_info() {
        if (class_exists('AITRFOEL_License_Manager_Server')) {
            $license_manager = AITRFOEL_License_Manager_Server::get_instance();
            return $license_manager->get_license_status();
        }

        return [
            'plan'       => 'Free',
            'word_limit' => 7200,
            'words_used' => 0,
            'words_left' => 7200,
        ];
    }

    /**
     * Get upgrade URL
     */
    private function get_upgrade_url() {
        if (function_exists('eatp_fs') && is_object(eatp_fs()) && method_exists(eatp_fs(), 'is_registered') && eatp_fs()->is_registered()) {
            return eatp_fs()->get_upgrade_url();
        }
        if (defined('AITRFOEL_VERSION_TYPE') && AITRFOEL_VERSION_TYPE === 'pro') {
            return 'https://meeptech.com/upgrade?utm_source=wp-plugin&utm_medium=free-plugin&utm_campaign=pro-upgrade';
        }
        return aitrfoel_free_upgrade_url('translate-all');
    }

    /**
     * Render the Translate All admin page
     */
    public function render_page() {
        $this->enqueue_assets();

        // Get multilingual info
        $multilingual = class_exists('AITRFOEL_Multilingual_Manager') ? AITRFOEL_Multilingual_Manager::get_instance() : null;
        $languages = [];
        $default_lang = '';
        if ($multilingual && $multilingual->is_active()) {
            $languages = $multilingual->get_available_languages();
            if (method_exists($multilingual, 'get_default_language')) {
                $default_lang = $multilingual->get_default_language();
            }
        }

        // Get license info
        $license_info = $this->get_license_info();

        // Check if there's a running cron job
        $cron_status = get_option('aitrfoel_translate_all_cron_status', []);
        ?>
        <div class="wrap aitrfoel-admin-wrapper">
            <?php
            $admin_menu = AITRFOEL_Admin_Menu::get_instance();
            $admin_menu->render_plugin_header(__('Translate All', 'ai-translator-for-elementor-and-polylang'));
            ?>

            <!-- Usage Overview Card -->
            <div class="aitrfoel-card aitrfoel-ta-usage-card">
                <h3><svg class="aitrfoel-ta-heading-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="2" y="12" width="4" height="10" rx="1" stroke="currentColor" stroke-width="1.8"/><rect x="9" y="7" width="4" height="15" rx="1" stroke="currentColor" stroke-width="1.8"/><rect x="16" y="2" width="4" height="20" rx="1" stroke="currentColor" stroke-width="1.8"/></svg><?php esc_html_e('Plan & Usage', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                <div class="aitrfoel-ta-usage-grid">
                    <div class="aitrfoel-ta-usage-item">
                        <span class="aitrfoel-ta-usage-label"><?php esc_html_e('Current Plan', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        <span class="aitrfoel-ta-usage-value" id="ta-plan-name"><?php echo esc_html($license_info['plan']); ?></span>
                    </div>
                    <div class="aitrfoel-ta-usage-item">
                        <span class="aitrfoel-ta-usage-label"><?php esc_html_e('Words Used', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        <span class="aitrfoel-ta-usage-value"><?php echo number_format($license_info['words_used']); ?></span>
                    </div>
                    <div class="aitrfoel-ta-usage-item">
                        <span class="aitrfoel-ta-usage-label"><?php esc_html_e('Words Remaining', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        <span class="aitrfoel-ta-usage-value" id="ta-words-left">
                            <?php echo $license_info['words_left'] === -1 ? esc_html__('Unlimited', 'ai-translator-for-elementor-and-polylang') : number_format($license_info['words_left']); ?>
                        </span>
                    </div>
                    <div class="aitrfoel-ta-usage-item">
                        <span class="aitrfoel-ta-usage-label"><?php esc_html_e('Monthly Limit', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        <span class="aitrfoel-ta-usage-value">
                            <?php echo $license_info['word_limit'] === -1 ? esc_html__('Unlimited', 'ai-translator-for-elementor-and-polylang') : number_format($license_info['word_limit']); ?>
                        </span>
                    </div>
                </div>
                <div class="aitrfoel-ta-usage-bar-wrap">
                    <?php
                    $usage_pct = 0;
                    if ($license_info['word_limit'] > 0 && $license_info['word_limit'] !== -1) {
                        $usage_pct = min(100, round(($license_info['words_used'] / $license_info['word_limit']) * 100));
                    }
                    ?>
                    <div class="aitrfoel-ta-usage-bar">
                        <div class="aitrfoel-ta-usage-bar-fill <?php echo $usage_pct > 80 ? 'warning' : ''; ?> <?php echo $usage_pct >= 100 ? 'full' : ''; ?>" style="width: <?php echo esc_attr($usage_pct); ?>%"></div>
                    </div>
                    <span class="aitrfoel-ta-usage-pct"><?php echo esc_html($usage_pct); ?>% <?php esc_html_e('used', 'ai-translator-for-elementor-and-polylang'); ?></span>
                </div>
            </div>

            <!-- Step 1: Select Content Types -->
            <div class="aitrfoel-card aitrfoel-ta-step" id="ta-step-1">
                <h3><?php esc_html_e('Step 1: Select Content Types', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                <p class="description"><?php esc_html_e('Choose which types of content to translate.', 'ai-translator-for-elementor-and-polylang'); ?></p>

                <div class="aitrfoel-ta-selection-actions">
                    <button type="button" class="button button-small" id="ta-select-all-types"><?php esc_html_e('Select All', 'ai-translator-for-elementor-and-polylang'); ?></button>
                    <button type="button" class="button button-small" id="ta-deselect-all-types"><?php esc_html_e('Deselect All', 'ai-translator-for-elementor-and-polylang'); ?></button>
                </div>
                <div class="aitrfoel-ta-post-types">
                    <?php
                    $post_types = $this->get_translatable_post_types();
                    foreach ($post_types as $type_slug => $type_info) :
                        $precheck_type = ($type_slug === 'page' && !empty($type_info['count']));
                    ?>
                    <label class="aitrfoel-ta-post-type-card">
                        <input type="checkbox" name="post_types[]" value="<?php echo esc_attr($type_slug); ?>" <?php checked($precheck_type); ?>>
                        <div class="aitrfoel-ta-post-type-inner">
                            <span class="aitrfoel-ta-post-type-icon aitrfoel-ta-post-type-icon-<?php echo esc_attr($type_info['icon']); ?>" aria-hidden="true"></span>
                            <span class="aitrfoel-ta-post-type-name"><?php echo esc_html($type_info['label']); ?></span>
                            <span class="aitrfoel-ta-post-type-count"><?php
                                /* translators: %s: number of published items */
                                echo esc_html(sprintf(_n('%s published', '%s published', (int) $type_info['count'], 'ai-translator-for-elementor-and-polylang'), number_format_i18n((int) $type_info['count'])));
                            ?></span>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Step 2: Select Target Languages -->
            <div class="aitrfoel-card aitrfoel-ta-step" id="ta-step-2">
                <h3><?php esc_html_e('Step 2: Select Target Languages', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                <p class="description"><?php esc_html_e('Choose which languages to translate your content into. Your default language content will be used as source.', 'ai-translator-for-elementor-and-polylang'); ?></p>

                <?php
                $target_languages_map = [];
                if (!empty($languages)) {
                    foreach ($languages as $code => $lang) {
                        if ($code === $default_lang) {
                            continue;
                        }
                        $target_languages_map[$code] = is_object($lang)
                            ? ($lang->name ?? strtoupper($code))
                            : (is_array($lang) ? ($lang['name'] ?? strtoupper($code)) : strtoupper($code));
                    }
                }
                ?>

                <?php if (!empty($target_languages_map)) : ?>
                    <div class="aitrfoel-ta-selection-actions">
                        <button type="button" class="button button-small" id="ta-select-all-langs"><?php esc_html_e('Select All', 'ai-translator-for-elementor-and-polylang'); ?></button>
                        <button type="button" class="button button-small" id="ta-deselect-all-langs"><?php esc_html_e('Deselect All', 'ai-translator-for-elementor-and-polylang'); ?></button>
                    </div>
                    <div class="aitrfoel-ta-languages">
                        <?php
                        $auto_check_single_target = (count($target_languages_map) === 1);
                        foreach ($target_languages_map as $code => $name) :
                        ?>
                        <label class="aitrfoel-ta-lang-card">
                            <input type="checkbox" name="target_languages[]" value="<?php echo esc_attr($code); ?>" <?php checked($auto_check_single_target); ?>>
                            <div class="aitrfoel-ta-lang-inner">
                                <span class="aitrfoel-ta-lang-code"><?php echo esc_html(strtoupper($code)); ?></span>
                                <span class="aitrfoel-ta-lang-name"><?php echo esc_html($name); ?></span>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php
                    // Inline "+ Add another language" proposition right inside Step 2
                    $ta_all_choices = [
                        'es' => 'Spanish (Español)', 'fr' => 'French (Français)', 'de' => 'German (Deutsch)',
                        'it' => 'Italian (Italiano)', 'nl' => 'Dutch (Nederlands)', 'pl' => 'Polish (Polski)',
                        'pt' => 'Portuguese (Português)', 'uk' => 'Ukrainian (Українська)', 'ru' => 'Russian (Русский)',
                        'ar' => 'Arabic (العربية)', 'zh' => 'Chinese (中文)', 'ja' => 'Japanese (日本語)',
                        'en' => 'English', 'ro' => 'Romanian (Română)', 'cs' => 'Czech (Čeština)',
                        'sv' => 'Swedish (Svenska)', 'da' => 'Danish (Dansk)', 'fi' => 'Finnish (Suomi)',
                        'no' => 'Norwegian (Norsk)', 'tr' => 'Turkish (Türkçe)', 'el' => 'Greek (Ελληνικά)',
                        'hu' => 'Hungarian (Magyar)', 'hr' => 'Croatian (Hrvatski)', 'bg' => 'Bulgarian (Български)',
                        'sk' => 'Slovak (Slovenčina)', 'sl' => 'Slovenian (Slovenščina)', 'he' => 'Hebrew (עברית)',
                        'hi' => 'Hindi (हिन्दी)', 'id' => 'Indonesian (Bahasa Indonesia)', 'ko' => 'Korean (한국어)',
                        'vi' => 'Vietnamese (Tiếng Việt)', 'th' => 'Thai (ไทย)',
                    ];
                    $ta_nonce = wp_create_nonce('aitrfoel_ajax_nonce');
                    ?>
                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 14px; padding-top: 12px; border-top: 1px dashed #e2e8f0;">
                        <span style="font-size: 12.5px; color: #475569; font-weight: 600;">+ <?php esc_html_e('Need another target language?', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        <select id="aitrfoel-ta-add-lang-select" style="min-width: 190px; font-size: 12.5px;">
                            <option value=""><?php esc_html_e('— Add language —', 'ai-translator-for-elementor-and-polylang'); ?></option>
                            <?php foreach ($ta_all_choices as $c_code => $c_label) :
                                if ($c_code === $default_lang || isset($languages[$c_code])) continue;
                            ?>
                                <option value="<?php echo esc_attr($c_code); ?>" data-name="<?php echo esc_attr(explode(' (', $c_label)[0]); ?>"><?php echo esc_html($c_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="button button-secondary button-small" id="aitrfoel-ta-add-lang-btn">
                            <?php esc_html_e('Add Language', 'ai-translator-for-elementor-and-polylang'); ?>
                        </button>
                        <span id="aitrfoel-ta-add-lang-status" style="font-size: 12px; font-weight: 600; color: #2563eb;"></span>
                    </div>
                    <script>
                    (function() {
                        var btn = document.getElementById('aitrfoel-ta-add-lang-btn');
                        var sel = document.getElementById('aitrfoel-ta-add-lang-select');
                        var st  = document.getElementById('aitrfoel-ta-add-lang-status');
                        if (!btn || !sel) return;
                        btn.addEventListener('click', function() {
                            var opt = sel.options[sel.selectedIndex];
                            if (!opt || !opt.value) return;
                            btn.disabled = true;
                            if (st) st.textContent = 'Adding…';
                            var fd = new FormData();
                            fd.append('action', 'aitrfoel_manage_builtin_language');
                            fd.append('nonce', <?php echo wp_json_encode($ta_nonce); ?>);
                            fd.append('operation', 'add');
                            fd.append('language_code', opt.value);
                            fd.append('language_name', opt.getAttribute('data-name') || opt.text);
                            fetch(ajaxurl, { method: 'POST', body: fd })
                                .then(function(r) { return r.json(); })
                                .then(function(res) {
                                    if (res && res.success) {
                                        if (st) { st.style.color = '#059669'; st.textContent = '✓ Added! Reloading…'; }
                                        window.location.reload();
                                    } else {
                                        btn.disabled = false;
                                        if (st) { st.style.color = '#dc2626'; st.textContent = (res && res.data) ? res.data : 'Error'; }
                                    }
                                })
                                .catch(function() { btn.disabled = false; });
                        });
                    })();
                    </script>
                <?php else : ?>
                    <?php
                    if (class_exists('AITRFOEL_Admin_Menu')) {
                        AITRFOEL_Admin_Menu::get_instance()->render_quick_add_language_card($multilingual);
                    }
                    ?>
                <?php endif; ?>

                <?php if (!empty($default_lang)) : ?>
                <p class="description" style="margin-top: 10px;">
                    <?php
                    /* translators: %s: default language code */
                    printf(esc_html__('Source language: %s (default)', 'ai-translator-for-elementor-and-polylang'), '<strong>' . esc_html(strtoupper($default_lang)) . '</strong>');
                    ?>
                </p>
                <?php endif; ?>
            </div>

            <!-- Step 3: Scan & Calculate -->
            <div class="aitrfoel-card aitrfoel-ta-step" id="ta-step-3">
                <h3><?php esc_html_e('Step 3: Scan & Calculate', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                <p class="description"><?php esc_html_e('Scan your content to calculate word counts and estimate translation effort.', 'ai-translator-for-elementor-and-polylang'); ?></p>

                <button type="button" class="button button-primary button-large" id="ta-scan-btn" style="display:inline-flex;align-items:center;gap:6px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <span><?php esc_html_e('Scan Content', 'ai-translator-for-elementor-and-polylang'); ?></span>
                </button>
                <span class="spinner" id="ta-scan-spinner" style="float: none;"></span>

                <!-- Scan Results -->
                <div id="ta-scan-results" style="display: none;">
                    <div class="aitrfoel-ta-results-summary">
                        <h4><?php esc_html_e('Scan Results', 'ai-translator-for-elementor-and-polylang'); ?></h4>
                        <table class="widefat aitrfoel-ta-results-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Content Type', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                    <th><?php esc_html_e('Items', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                    <th><?php esc_html_e('Total Words', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                    <th><?php esc_html_e('Already Translated', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                    <th><?php esc_html_e('Needs Translation', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="ta-results-body">
                                <!-- Populated by JS -->
                            </tbody>
                            <tfoot id="ta-results-footer">
                                <!-- Populated by JS -->
                            </tfoot>
                        </table>
                    </div>

                    <!-- Per-Page Detail -->
                    <div id="ta-posts-detail" class="aitrfoel-ta-posts-detail" style="display: none;">
                        <h4><?php esc_html_e('Page-by-Page Breakdown', 'ai-translator-for-elementor-and-polylang'); ?></h4>
                        <div class="aitrfoel-ta-detail-filters" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                            <button type="button" class="button button-small ta-detail-filter active" data-filter="all"><?php esc_html_e('All', 'ai-translator-for-elementor-and-polylang'); ?></button>
                            <button type="button" class="button button-small ta-detail-filter" data-filter="needs"><?php esc_html_e('Needs Translation', 'ai-translator-for-elementor-and-polylang'); ?></button>
                            <button type="button" class="button button-small ta-detail-filter" data-filter="done"><?php esc_html_e('Fully Translated', 'ai-translator-for-elementor-and-polylang'); ?></button>
                            <span style="flex: 1;"></span>
                            <button type="button" class="button button-small" id="ta-select-all-pages"><?php esc_html_e('Select All', 'ai-translator-for-elementor-and-polylang'); ?></button>
                            <button type="button" class="button button-small" id="ta-select-needs-pages"><?php esc_html_e('Select Untranslated', 'ai-translator-for-elementor-and-polylang'); ?></button>
                            <button type="button" class="button button-small" id="ta-deselect-all-pages"><?php esc_html_e('Deselect All', 'ai-translator-for-elementor-and-polylang'); ?></button>
                        </div>
                        <div id="ta-selection-summary" style="display: none; margin: 8px 0; padding: 8px 14px; background: #f0f6fc; border: 1px solid #c3d7ef; border-radius: 4px; font-size: 13px;">
                            <strong id="ta-selection-count">0</strong> <?php esc_html_e('pages selected', 'ai-translator-for-elementor-and-polylang'); ?>
                            — ~<strong id="ta-selection-words">0</strong> <?php esc_html_e('estimated API words', 'ai-translator-for-elementor-and-polylang'); ?>
                        </div>
                        <table class="widefat aitrfoel-ta-detail-table">
                            <thead>
                                <tr>
                                    <th style="width: 30px;"><input type="checkbox" id="ta-check-all" title="<?php esc_attr_e('Select all', 'ai-translator-for-elementor-and-polylang'); ?>" /></th>
                                    <th><?php esc_html_e('Title', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                    <th><?php esc_html_e('Lang', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                    <th><?php esc_html_e('Type', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                    <th><?php esc_html_e('Words', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                    <th><?php esc_html_e('Editor', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                    <th><?php esc_html_e('Status', 'ai-translator-for-elementor-and-polylang'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="ta-detail-body">
                                <!-- Populated by JS -->
                            </tbody>
                        </table>
                    </div>

                    <!-- Calculation & Recommendation -->
                    <div id="ta-recommendation" class="aitrfoel-ta-recommendation" style="display: none;">
                        <!-- Populated by JS based on word count vs. plan limits -->
                    </div>
                </div>
            </div>

            <!-- Step 4: Translation Actions -->
            <div class="aitrfoel-card aitrfoel-ta-step" id="ta-step-4" style="display: none;">
                <h3><?php esc_html_e('Step 4: Start Translation', 'ai-translator-for-elementor-and-polylang'); ?></h3>

                <div class="aitrfoel-ta-actions" id="ta-actions">
                    <!-- Option A: Translate Now (fits within limit) -->
                    <div id="ta-action-now" class="aitrfoel-ta-action-card" style="display: none;">
                        <div class="aitrfoel-ta-action-icon">
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M13 3 6 13h6l-1 8 9-11h-6l1-7z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </div>
                        <h4><?php esc_html_e('Translate Now', 'ai-translator-for-elementor-and-polylang'); ?></h4>
                        <p><?php esc_html_e('Your content fits within your current word allowance. Translate everything now.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                        <button type="button" class="button button-primary button-large" id="ta-start-now-btn">
                            <svg class="aitrfoel-ta-btn-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M13 3 6 13h6l-1 8 9-11h-6l1-7z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <span id="ta-start-now-label"><?php esc_html_e('Translate All Now', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </button>
                        <p id="ta-selected-notice" style="display: none; margin-top: 10px; font-size: 12.5px; color: #475569; background: #f8fafc; border-left: 3px solid #3b82f6; padding: 8px 12px; border-radius: 4px; text-align: left;">
                            <span style="display:inline-flex;align-items:center;vertical-align:-2px;margin-right:4px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/></svg></span> <strong><?php esc_html_e('Note:', 'ai-translator-for-elementor-and-polylang'); ?></strong> <?php esc_html_e('Translating selected content will also auto-translate site menus, widgets, and form labels so navigation stays synchronized across all languages.', 'ai-translator-for-elementor-and-polylang'); ?>
                        </p>
                    </div>

                    <!-- Option B: Schedule via Cron (exceeds limit) -->
                    <div id="ta-action-schedule" class="aitrfoel-ta-action-card" style="display: none;">
                        <div class="aitrfoel-ta-action-icon">
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="M12 7v5l3.5 3.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </div>
                        <h4><?php esc_html_e('Scheduled Translation', 'ai-translator-for-elementor-and-polylang'); ?></h4>
                        <p><?php esc_html_e('Content exceeds your current word allowance. Schedule translation to process over multiple billing cycles using your monthly word limit.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                        <div class="aitrfoel-ta-schedule-info" id="ta-schedule-info">
                            <!-- Estimated months/cycles shown by JS -->
                        </div>
                        <button type="button" class="button button-primary button-large" id="ta-start-scheduled-btn">
                            <svg class="aitrfoel-ta-btn-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><path d="M12 7v5l3.5 3.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <?php esc_html_e('Start Scheduled Translation', 'ai-translator-for-elementor-and-polylang'); ?>
                        </button>
                    </div>

                    <!-- Option C: Upgrade Plan -->
                    <div id="ta-action-upgrade" class="aitrfoel-ta-action-card" style="display: none;">
                        <div class="aitrfoel-ta-action-icon">
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17l-6.2 4.3 2.4-7.4L2 9.4h7.6L12 2z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                        </div>
                        <h4><?php esc_html_e('Upgrade Plan', 'ai-translator-for-elementor-and-polylang'); ?></h4>
                        <p id="ta-upgrade-text"><?php esc_html_e('Get a bigger word allowance to translate all your content at once.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                        <?php $ta_upgrade_url = $this->get_upgrade_url(); ?>
                        <a href="<?php echo esc_url($ta_upgrade_url); ?>"<?php echo (strpos($ta_upgrade_url, 'meeptech.com') !== false) ? ' target="_blank" rel="noopener noreferrer"' : ''; ?> class="button button-primary button-large" id="ta-upgrade-btn">
                            <svg class="aitrfoel-ta-btn-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17l-6.2 4.3 2.4-7.4L2 9.4h7.6L12 2z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
                            <?php esc_html_e('Upgrade Plan', 'ai-translator-for-elementor-and-polylang'); ?>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Progress Area -->
            <div class="aitrfoel-card aitrfoel-ta-step aitrfoel-sticky-progress-card" id="ta-progress-area" style="display: none;">
                <div class="aitrfoel-sticky-progress-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <h3 style="margin:0;font-size:16px;display:flex;align-items:center;gap:8px;">
                        <span class="spinner is-active" id="ta-progress-spinner" style="float:none;margin:0;"></span>
                        <span id="ta-progress-title"><?php esc_html_e('Translation in Progress', 'ai-translator-for-elementor-and-polylang'); ?></span>
                    </h3>
                    <div class="aitrfoel-ta-progress-actions" style="margin:0;display:flex;gap:8px;">
                        <button type="button" class="button button-secondary" id="ta-pause-btn" style="display:none;align-items:center;gap:5px;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
                            <span><?php esc_html_e('Pause', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </button>
                        <button type="button" class="button button-primary" id="ta-resume-btn" style="display:none;align-items:center;gap:5px;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                            <span><?php esc_html_e('Resume', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </button>
                        <button type="button" class="button button-link-delete" id="ta-cancel-btn" style="display:inline-flex;align-items:center;gap:5px;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            <span><?php esc_html_e('Cancel', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </button>
                    </div>
                </div>
                <div class="aitrfoel-ta-progress-wrap" style="margin-top:14px;">
                    <div class="aitrfoel-ta-progress-bar">
                        <div class="aitrfoel-ta-progress-fill" id="ta-progress-fill" style="width: 0%"></div>
                    </div>
                    <div class="aitrfoel-ta-progress-stats" style="margin-top:8px;display:flex;justify-content:space-between;font-weight:600;font-size:13px;">
                        <span id="ta-progress-text">0%</span>
                        <span id="ta-progress-detail" style="color:#64748b;"></span>
                    </div>
                </div>
                <div id="ta-progress-log" class="aitrfoel-ta-progress-log" style="margin-top:14px;max-height:220px;"></div>
            </div>

            <!-- Active Scheduled Job Status -->
            <?php if (!empty($cron_status) && isset($cron_status['status']) && in_array($cron_status['status'], ['pending', 'processing'])) : ?>
            <div class="aitrfoel-card aitrfoel-ta-step aitrfoel-ta-cron-status">
                <h3>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:6px;" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span><?php esc_html_e('Active Scheduled Translation', 'ai-translator-for-elementor-and-polylang'); ?></span>
                </h3>
                <div class="aitrfoel-ta-cron-info">
                    <p>
                        <strong><?php esc_html_e('Status:', 'ai-translator-for-elementor-and-polylang'); ?></strong>
                        <span class="aitrfoel-ta-cron-badge"><?php echo esc_html(ucfirst($cron_status['status'])); ?></span>
                    </p>
                    <p>
                        <strong><?php esc_html_e('Progress:', 'ai-translator-for-elementor-and-polylang'); ?></strong>
                        <?php
                        $completed = isset($cron_status['completed_items']) ? (int) $cron_status['completed_items'] : 0;
                        $total = isset($cron_status['total_items']) ? (int) $cron_status['total_items'] : 0;
                        echo esc_html("$completed / $total");
                        ?>
                    </p>
                    <?php if (isset($cron_status['next_run'])) : ?>
                    <p>
                        <strong><?php esc_html_e('Next run:', 'ai-translator-for-elementor-and-polylang'); ?></strong>
                        <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $cron_status['next_run'])); ?>
                    </p>
                    <?php endif; ?>
                    <button type="button" class="button button-secondary" id="ta-cancel-cron-btn">
                        <?php esc_html_e('Cancel Scheduled Translation', 'ai-translator-for-elementor-and-polylang'); ?>
                    </button>
                </div>
            </div>
            <?php endif; ?>
            
            <?php
            if (method_exists($admin_menu, 'render_quick_tips')) {
                $admin_menu->render_quick_tips();
            }
            ?>
            
            <!-- MeepTech Footer Branding -->
            <div class="aitrfoel-footer-branding">
                <span class="aitrfoel-footer-text"><?php esc_html_e('Powered by', 'ai-translator-for-elementor-and-polylang'); ?> <a href="https://meeptech.com" target="_blank" rel="noopener noreferrer">Meep Tech</a> &middot; v<?php echo esc_html(AITRFOEL_VERSION); ?></span>
            </div>
        </div>
        <?php
    }

    /**
     * Get translatable post types with counts
     */
    private function get_translatable_post_types() {
        $types = [];

        // When built-in integration is active, only count default-language posts
        $builtin_active = class_exists('AITRFOEL_Builtin_Integration') && AITRFOEL_Builtin_Integration::is_enabled();
        $non_default_langs = [];
        if ($builtin_active) {
            $builtin = AITRFOEL_Builtin_Integration::get_instance();
            $default_lang = $builtin->get_default_language();
            foreach ($builtin->get_available_languages() as $l) {
                if ($l->code !== $default_lang) {
                    $non_default_langs[] = $l->code;
                }
            }
        }

        // Pages
        $types['page'] = [
            'label' => __('Pages', 'ai-translator-for-elementor-and-polylang'),
            'icon'  => 'page',
            'count' => $this->count_default_language_posts('page', $non_default_langs),
        ];

        // Posts
        $types['post'] = [
            'label' => __('Posts', 'ai-translator-for-elementor-and-polylang'),
            'icon'  => 'post',
            'count' => $this->count_default_language_posts('post', $non_default_langs),
        ];

        // Custom post types that support editor or title (including dynamic tag sources like Testimonials & Employees)
        $exclude_system_types = [
            'attachment', 'revision', 'nav_menu_item', 'custom_css', 
            'customize_changeset', 'oembed_cache', 'user_request', 
            'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part', 
            'wp_global_styles', 'elementor_library'
        ];
        $custom_types = get_post_types([
            'show_ui'  => true,
            '_builtin' => false,
        ], 'objects');

        $known_labels = [
            'page'               => __('Pages', 'ai-translator-for-elementor-and-polylang'),
            'post'               => __('Posts', 'ai-translator-for-elementor-and-polylang'),
            'product'            => __('Products', 'ai-translator-for-elementor-and-polylang'),
            'sureforms_form'     => __('SureForms', 'ai-translator-for-elementor-and-polylang'),
            'jet-form-builder'   => __('JetFormBuilder Forms', 'ai-translator-for-elementor-and-polylang'),
            'elementor-hf'       => __('Header & Footer Builder', 'ai-translator-for-elementor-and-polylang'),
            'jet-theme-core'     => __('JetThemeCore Templates', 'ai-translator-for-elementor-and-polylang'),
            'jet-engine'         => __('JetEngine Listings', 'ai-translator-for-elementor-and-polylang'),
            'page-generator-pro' => __('Page Generator Pro', 'ai-translator-for-elementor-and-polylang'),
        ];

        foreach ($custom_types as $cpt) {
            if (in_array($cpt->name, $exclude_system_types, true)) {
                continue;
            }
            // Include CPTs that support the editor OR at least a title. JetEngine
            // (and similar) CPTs often disable editor support and store their content
            // in custom meta fields, but still register a title — they are translatable
            // (title + JetEngine meta fields). This mirrors the dashboard, which lists
            // every public CPT. Truly headless types (no editor, no title) are skipped.
            if (!post_type_supports($cpt->name, 'editor') && !post_type_supports($cpt->name, 'title')) {
                continue;
            }
            $count = $this->count_default_language_posts($cpt->name, $non_default_langs);
            if ($count === 0) continue; // Hide empty custom post types
            $types[$cpt->name] = [
                'label' => isset($known_labels[$cpt->name]) ? $known_labels[$cpt->name] : $cpt->label,
                'icon'  => ($cpt->name === 'product' ? 'product' : 'custom'),
                'count' => $count,
            ];
        }

        // Elementor templates (headers, footers, sections, etc.)
        // These are stored as post_type=elementor_library with _elementor_template_type meta.
        // They are private CPTs so they won't appear in the generic loop above.
        if (post_type_exists('elementor_library')) {
            $count = $this->count_elementor_templates($non_default_langs);
            if ($count > 0) {
                $types['elementor_library'] = [
                    'label' => __('Elementor Templates', 'ai-translator-for-elementor-and-polylang'),
                    'icon'  => 'template',
                    'count' => $count,
                ];
            }
        }

        return $types;
    }

    /**
     * Count published posts of a given type, excluding non-default language translations.
     */
    private function count_default_language_posts($post_type, $non_default_langs) {
        if (empty($non_default_langs)) {
            $counts = wp_count_posts($post_type);
            return isset($counts->publish) ? (int) $counts->publish : 0;
        }

        $query = new WP_Query([
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
            'tax_query'      => [
                [
                    'taxonomy' => 'aitrfoel_language',
                    'field'    => 'slug',
                    'terms'    => $non_default_langs,
                    'operator' => 'NOT IN',
                ],
            ],
        ]);

        return (int) $query->found_posts;
    }

    /**
     * Count published Elementor templates (headers, footers, sections, etc.)
     * that have Elementor data, are not kits, and are not already translations.
     */
    private function count_elementor_templates($non_default_langs) {
        $args = [
            'post_type'      => 'elementor_library',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
            'meta_query'     => [
                [
                    'key'     => '_elementor_data',
                    'compare' => 'EXISTS',
                ],
                [
                    'key'     => '_elementor_template_type',
                    'value'   => 'kit',
                    'compare' => '!=',
                ],
            ],
        ];

        // If built-in language system is active, exclude non-default language posts
        if (!empty($non_default_langs)) {
            $args['tax_query'] = [
                [
                    'taxonomy' => 'aitrfoel_language',
                    'field'    => 'slug',
                    'terms'    => $non_default_langs,
                    'operator' => 'NOT IN',
                ],
            ];
        }

        $query = new \WP_Query($args);
        return (int) $query->found_posts;
    }

    /**
     * AJAX: Scan content and calculate word counts
     */
    public function ajax_scan_content() {
        check_ajax_referer('aitrfoel_translate_all_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'ai-translator-for-elementor-and-polylang'));
        }

        // Wrap the scan in a Throwable guard so a fatal in any parser (malformed
        // _elementor_data, third-party post-meta surprises) becomes a JSON error
        // with the actual message instead of a generic 500.
        try {
            $this->run_scan_content();
        } catch (\Throwable $e) {
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log(
                    'Translate-All scan failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString(),
                    'error'
                );
            }
            wp_send_json_error(sprintf(
                /* translators: %s: PHP exception/error message */
                __('Scan failed: %s', 'ai-translator-for-elementor-and-polylang'),
                $e->getMessage()
            ));
        }
    }

    /**
     * Inner scan implementation. Extracted so ajax_scan_content() can wrap it in
     * a Throwable guard and surface real error messages to the UI.
     */
    private function run_scan_content() {
        $post_types = isset($_POST['post_types']) ? array_map('sanitize_text_field', (array) $_POST['post_types']) : [];
        $target_languages = isset($_POST['target_languages']) ? array_map('sanitize_text_field', (array) $_POST['target_languages']) : [];

        if (empty($post_types)) {
            wp_send_json_error(__('No content types selected.', 'ai-translator-for-elementor-and-polylang'));
        }

        if (empty($target_languages)) {
            wp_send_json_error(__('No target languages selected.', 'ai-translator-for-elementor-and-polylang'));
        }

        $results = [];
        $total_words = 0;
        $total_items = 0;
        $total_already_translated = 0;
        $total_needs_translation = 0;
        $all_post_ids = [];
        $posts_detail = []; // Per-post breakdown

        // Multilingual manager for checking existing translations
        $multilingual = class_exists('AITRFOEL_Multilingual_Manager') ? AITRFOEL_Multilingual_Manager::get_instance() : null;
        $default_lang = ($multilingual && $multilingual->is_active()) ? $multilingual->get_default_language() : '';

        foreach ($post_types as $post_type) {
            $posts = get_posts([
                'post_type'      => $post_type,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ]);

            // Filter to only source-language posts (default language)
            if ($multilingual && $multilingual->is_active() && $default_lang) {
                $posts = array_filter($posts, function($post) use ($multilingual, $default_lang) {
                    $lang = $multilingual->get_post_language($post->ID);
                    // If language is explicitly set, only keep default language
                    if (!empty($lang)) {
                        return $lang === $default_lang;
                    }
                    // For posts without explicit language, check translation groups
                    $translations = $multilingual->get_post_translations($post->ID);
                    if (!empty($translations)) {
                        foreach ($translations as $lang_code => $post_id) {
                            if ((int) $post_id === $post->ID && $lang_code !== $default_lang) {
                                return false; // This post is a non-default translation
                            }
                        }
                    }
                    return true; // No language, not in a group as non-default = source content
                });
                $posts = array_values($posts);
            }

            $type_words = 0;
            $type_already_translated = 0;
            $type_needs_translation = 0;
            $type_post_ids = [];

            foreach ($posts as $post) {
                // Skip Elementor global styles / Default Kit
                if ($post->post_type === 'elementor_library') {
                    $template_type = get_post_meta($post->ID, '_elementor_template_type', true);
                    if ($template_type === 'kit') {
                        continue;
                    }
                }

                // Calculate word count. Wrap per-post so one bad post (malformed
                // _elementor_data, parser exception) doesn't 500 the entire scan.
                try {
                    $word_count = $this->get_post_word_count($post->ID);
                } catch (\Throwable $e) {
                    if (class_exists('AITRFOEL_Logger')) {
                        AITRFOEL_Logger::log(
                            sprintf('Scan word-count failed for post %d: %s', $post->ID, $e->getMessage()),
                            'warning',
                            $post->ID
                        );
                    }
                    $word_count = 0;
                }
                $type_words += $word_count;

                // Check which languages are translated and which are not
                $translated_count = 0;
                $needs_count = 0;
                $translated_langs = [];
                $missing_langs = [];

                if ($multilingual && $multilingual->is_active()) {
                    $existing_translations = $multilingual->get_post_translations($post->ID);
                    foreach ($target_languages as $lang_code) {
                        $translation_id = isset($existing_translations[$lang_code]) ? $existing_translations[$lang_code] : 0;
                        if ($translation_id && $translation_id != $post->ID) {
                            $translated_count++;
                            $translated_langs[] = strtoupper($lang_code);
                        } else {
                            $needs_count++;
                            $missing_langs[] = strtoupper($lang_code);
                        }
                    }
                } else {
                    $needs_count = count($target_languages);
                    foreach ($target_languages as $lang_code) {
                        $missing_langs[] = strtoupper($lang_code);
                    }
                }

                // Content type detection
                $content_type = 'classic';
                if (class_exists('AITRFOEL_Content_Detector')) {
                    $content_type = AITRFOEL_Content_Detector::get_instance()->detect($post->ID);
                }

                // Get the post's own language code
                $post_lang = '';
                if ($multilingual && $multilingual->is_active()) {
                    $post_lang = $multilingual->get_post_language($post->ID);
                }

                $posts_detail[] = [
                    'post_id'          => $post->ID,
                    'title'            => $post->post_title ?: __('(no title)', 'ai-translator-for-elementor-and-polylang'),
                    'post_type'        => $post_type,
                    'post_lang'        => strtoupper($post_lang),
                    'edit_url'         => get_edit_post_link($post->ID, 'raw'),
                    'view_url'         => get_permalink($post->ID),
                    'word_count'       => $word_count,
                    'content_type'     => $content_type,
                    'translated_langs' => $translated_langs,
                    'missing_langs'    => $missing_langs,
                    'is_fully_translated' => ($needs_count === 0),
                ];

                $type_already_translated += $translated_count;
                $type_needs_translation += $needs_count;
                $type_post_ids[] = $post->ID;
            }

            // Words needed = words per post × missing languages × server usage factor
            // The server counts both source and translated words, so multiply by the factor.
            $words_needed = 0;
            $factor = self::SERVER_USAGE_FACTOR;
            foreach ($posts as $post) {
                try {
                    $wc = $this->get_post_word_count($post->ID);
                } catch (\Throwable $e) {
                    $wc = 0;
                }
                if ($multilingual && $multilingual->is_active()) {
                    $existing_translations = $multilingual->get_post_translations($post->ID);
                    foreach ($target_languages as $lang_code) {
                        $translation_id = isset($existing_translations[$lang_code]) ? $existing_translations[$lang_code] : 0;
                        if (!$translation_id || $translation_id == $post->ID) {
                            $words_needed += $wc * $factor;
                        }
                    }
                } else {
                    $words_needed += $wc * count($target_languages) * $factor;
                }
            }

            $post_type_obj = get_post_type_object($post_type);
            $results[] = [
                'post_type'          => $post_type,
                'label'              => $post_type_obj ? $post_type_obj->label : $post_type,
                'count'              => count($posts),
                'total_words'        => $type_words,
                'words_needed'       => $words_needed,
                'already_translated' => $type_already_translated,
                'needs_translation'  => $type_needs_translation,
                'post_ids'           => $type_post_ids,
            ];

            $total_words += $type_words;
            $total_items += count($posts);
            $total_already_translated += $type_already_translated;
            $total_needs_translation += $type_needs_translation;
            $all_post_ids = array_merge($all_post_ids, $type_post_ids);
        }

        // Calculate total words actually needed
        $total_words_needed = 0;
        foreach ($results as $r) {
            $total_words_needed += $r['words_needed'];
        }

        wp_send_json_success([
            'results'              => $results,
            'posts_detail'         => $posts_detail,
            'total_items'          => $total_items,
            'total_words'          => $total_words,
            'total_words_needed'   => $total_words_needed,
            'total_already'        => $total_already_translated,
            'total_needs'          => $total_needs_translation,
            'target_languages'     => $target_languages,
            'post_ids'             => $all_post_ids,
        ]);
    }

    /**
     * Get word count for a post based on its content type.
     * Uses the cached word count from AITRFOEL_Content_Detector.
     */
    private function get_post_word_count($post_id) {
        if (class_exists('AITRFOEL_Content_Detector')) {
            return AITRFOEL_Content_Detector::get_instance()->get_cached_word_count($post_id);
        }
        return 0;
    }

    /**
     * AJAX: Start translation (immediate or scheduled)
     */
    public function ajax_start_translation() {
        check_ajax_referer('aitrfoel_translate_all_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'ai-translator-for-elementor-and-polylang'));
        }

        $mode = sanitize_text_field($_POST['mode'] ?? 'now'); // 'now' or 'scheduled'
        $post_ids = isset($_POST['post_ids']) ? array_map('intval', (array) $_POST['post_ids']) : [];
        $target_languages = isset($_POST['target_languages']) ? array_map('sanitize_text_field', (array) $_POST['target_languages']) : [];
        $source_language = sanitize_text_field($_POST['source_language'] ?? 'auto');

        if (empty($post_ids) || empty($target_languages)) {
            wp_send_json_error(__('No content or languages selected.', 'ai-translator-for-elementor-and-polylang'));
        }

        $job_id = 'ta_' . wp_generate_password(16, false);

        if ($mode === 'scheduled') {
            // Create scheduled job
            $this->create_scheduled_job($job_id, $post_ids, $target_languages, $source_language);

            wp_send_json_success([
                'job_id'  => $job_id,
                'mode'    => 'scheduled',
                'message' => __('Scheduled translation created. It will process content using your monthly word allowance.', 'ai-translator-for-elementor-and-polylang'),
            ]);
        } else {
            // Immediate translation via bulk queue
            $this->create_immediate_job($job_id, $post_ids, $target_languages, $source_language);

            wp_send_json_success([
                'job_id' => $job_id,
                'mode'   => 'now',
                'message' => __('Translation started.', 'ai-translator-for-elementor-and-polylang'),
            ]);
        }
    }

    /**
     * Create a scheduled cron-based translation job
     */
    private function create_scheduled_job($job_id, $post_ids, $target_languages, $source_language) {
        // Build the translation queue (list of post_id + language pairs that need translation)
        $queue = [];
        $multilingual = class_exists('AITRFOEL_Multilingual_Manager') ? AITRFOEL_Multilingual_Manager::get_instance() : null;

        foreach ($post_ids as $post_id) {
            foreach ($target_languages as $lang_code) {
                // Skip if already translated
                if ($multilingual && $multilingual->is_active()) {
                    $existing_translations = $multilingual->get_post_translations($post_id);
                    $existing = isset($existing_translations[$lang_code]) ? $existing_translations[$lang_code] : 0;
                    if ($existing && $existing != $post_id) {
                        continue;
                    }
                }
                $queue[] = [
                    'post_id'  => $post_id,
                    'language' => $lang_code,
                ];
            }
        }

        // Save job status
        update_option('aitrfoel_translate_all_cron_status', [
            'job_id'          => $job_id,
            'status'          => 'pending',
            'queue'           => $queue,
            'completed_items' => 0,
            'total_items'     => count($queue),
            'source_language' => $source_language,
            'errors'          => [],
            'created_at'      => current_time('mysql'),
            'next_run'        => time() + 60, // Start in 1 minute
        ]);

        // Schedule the first cron run
        if (!wp_next_scheduled('aitrfoel_translate_all_cron_process')) {
            wp_schedule_event(time() + 60, 'aitrfoel_five_minutes', 'aitrfoel_translate_all_cron_process');
        }
    }

    /**
     * Create an immediate (now) translation job using the existing bulk translation system
     */
    private function create_immediate_job($job_id, $post_ids, $target_languages, $source_language) {
        // Build the translation queue (skip already translated)
        $queue = [];
        $multilingual = class_exists('AITRFOEL_Multilingual_Manager') ? AITRFOEL_Multilingual_Manager::get_instance() : null;

        foreach ($post_ids as $post_id) {
            foreach ($target_languages as $lang_code) {
                if ($multilingual && $multilingual->is_active()) {
                    $existing_translations = $multilingual->get_post_translations($post_id);
                    $existing = isset($existing_translations[$lang_code]) ? $existing_translations[$lang_code] : 0;
                    if ($existing && $existing != $post_id) {
                        continue;
                    }
                }
                $queue[] = [
                    'post_id'  => $post_id,
                    'language' => $lang_code,
                ];
            }
        }

        // Save job for status tracking
        update_option('aitrfoel_translate_all_job', [
            'job_id'          => $job_id,
            'status'          => 'processing',
            'queue'           => $queue,
            'completed_items' => 0,
            'total_items'     => count($queue),
            'source_language' => $source_language,
            'errors'          => [],
            'results'         => [],
            'created_at'      => current_time('mysql'),
        ]);

        // Processing is driven by the status-poll loop (ajax_get_status), which
        // runs a time-budgeted batch on each call. This keeps the start request
        // instant and removes any WP-Cron dependency for immediate jobs.
    }

    /**
     * Process a batch of immediate translations.
     */
    private function process_immediate_batch($job_id) {
        $job = get_option('aitrfoel_translate_all_job', []);
        if (empty($job) || $job['job_id'] !== $job_id) {
            return;
        }

        // Allow several translations per request rather than one item per poll.
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        @ignore_user_abort(true);

        $queue           = $job['queue'] ?? [];
        $source_language = $job['source_language'] ?? 'auto';
        $total           = count($queue);

        $start  = microtime(true);
        $budget = 12.0; // seconds of work per request, then return progress

        while (($job['completed_items'] ?? 0) < $total) {
            // Re-read the job each iteration so a pause/cancel from another
            // request stops the loop promptly.
            $fresh = get_option('aitrfoel_translate_all_job', []);
            if (empty($fresh) || ($fresh['job_id'] ?? '') !== $job_id) {
                return;
            }
            if (($fresh['status'] ?? '') !== 'processing') {
                return;
            }
            $job = $fresh;

            $completed = (int) $job['completed_items'];
            $item      = $queue[$completed];

            $result = $this->translate_single_post($item['post_id'], $item['language'], $source_language);

            $job['completed_items'] = $completed + 1;

            if (is_wp_error($result)) {
                $error_msg = $result->get_error_message();
                $job['errors'][] = [
                    'post_id'  => $item['post_id'],
                    'language' => $item['language'],
                    'error'    => $error_msg,
                ];

                // Stop processing if word limit is reached
                if (
                    strpos($error_msg, 'words remaining') !== false ||
                    strpos($error_msg, 'word limit') !== false ||
                    strpos($error_msg, 'monthly limit') !== false ||
                    strpos($error_msg, 'reached your') !== false ||
                    strpos($error_msg, 'cooldown') !== false ||
                    strpos($error_msg, 'insufficient_words') !== false
                ) {
                    $job['status'] = 'completed';
                    $job['stopped_reason'] = 'word_limit';
                    update_option('aitrfoel_translate_all_job', $job);
                    return;
                }
            } else {
                // Increment successful translation counter for review prompt
                $count = (int) get_option('aitrfoel_successful_translations_count', 0);
                update_option('aitrfoel_successful_translations_count', $count + 1);

                $job['results'][] = [
                    'post_id'        => $item['post_id'],
                    'title'          => html_entity_decode(get_the_title($item['post_id']), ENT_QUOTES, 'UTF-8'),
                    'post_type'      => get_post_type($item['post_id']),
                    'view_url'       => get_permalink($item['post_id']),
                    'language'       => $item['language'],
                    'new_post_id'    => $result,
                    'new_view_url'   => $result ? get_permalink($result) : '',
                ];
            }

            // Check if done
            if ($job['completed_items'] >= $total) {
                $job['status'] = 'completed';
            }

            // Persist after each item so progress survives if the request ends.
            update_option('aitrfoel_translate_all_job', $job);

            // Stop once the per-request time budget is used up; the next poll continues.
            if ((microtime(true) - $start) >= $budget) {
                break;
            }
        }
    }

    /**
     * Translate a single post to a language
     */
    private function translate_single_post($post_id, $target_language, $source_language) {
        if (!class_exists('AITRFOEL_Translation_Engine')) {
            return new WP_Error('missing_engine', __('Translation engine not available.', 'ai-translator-for-elementor-and-polylang'));
        }

        $engine = AITRFOEL_Translation_Engine::get_instance();

        // Map the language code to the format expected by the engine
        $mapped_lang = $target_language;
        if (class_exists('AITRFOEL_Language_Mapper')) {
            $name = AITRFOEL_Language_Mapper::get_language_name($target_language, false);
            if ($name) {
                $mapped_lang = $name;
            }
        }

        $mapped_source = $source_language;
        if ($source_language !== 'auto' && class_exists('AITRFOEL_Language_Mapper')) {
            $name = AITRFOEL_Language_Mapper::get_language_name($source_language, false);
            if ($name) {
                $mapped_source = $name;
            }
        }

        return $engine->translate_page($post_id, $mapped_lang, $mapped_source);
    }

    /**
     * AJAX: Get translation status
     */
    public function ajax_get_status() {
        check_ajax_referer('aitrfoel_translate_all_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'ai-translator-for-elementor-and-polylang'));
        }

        $job_id = sanitize_text_field($_POST['job_id'] ?? '');
        $mode = sanitize_text_field($_POST['mode'] ?? 'now');

        if ($mode === 'scheduled') {
            $job = get_option('aitrfoel_translate_all_cron_status', []);
        } else {
            $job = get_option('aitrfoel_translate_all_job', []);
        }

        if (empty($job) || (isset($job['job_id']) && $job['job_id'] !== $job_id)) {
            wp_send_json_error(__('Job not found.', 'ai-translator-for-elementor-and-polylang'));
        }

        // For immediate mode, process next item (only if actively processing, not paused)
        if ($mode === 'now' && isset($job['status']) && $job['status'] === 'processing') {
            $this->process_immediate_batch($job_id);
            // Re-read updated job
            $job = get_option('aitrfoel_translate_all_job', []);
        }

        $total = isset($job['total_items']) ? (int) $job['total_items'] : 0;
        $completed = isset($job['completed_items']) ? (int) $job['completed_items'] : 0;
        $progress = $total > 0 ? round(($completed / $total) * 100) : 0;

        // Get current item info
        $current_item_info = '';
        $queue = $job['queue'] ?? [];
        if ($completed < count($queue)) {
            $current = $queue[$completed];
            $post_title = html_entity_decode(get_the_title($current['post_id']), ENT_QUOTES, 'UTF-8');
            $current_item_info = sprintf('%s → %s', $post_title, strtoupper($current['language']));
        }

        $review_eligible = !get_option('aitrfoel_review_dismissed') && ((int) get_option('aitrfoel_successful_translations_count', 0) >= 3);

        wp_send_json_success([
            'status'           => $job['status'] ?? 'unknown',
            'stopped_reason'   => $job['stopped_reason'] ?? '',
            'progress'         => $progress,
            'completed'        => $completed,
            'total'            => $total,
            'current_item'     => $current_item_info,
            'errors'           => $job['errors'] ?? [],
            'results'          => $job['results'] ?? [],
            'review_eligible'  => $review_eligible,
        ]);
    }

    /**
     * AJAX: Pause translation
     */
    public function ajax_pause() {
        check_ajax_referer('aitrfoel_translate_all_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'ai-translator-for-elementor-and-polylang'));
        }

        $mode = sanitize_text_field($_POST['mode'] ?? 'now');
        $option_key = ($mode === 'scheduled') ? 'aitrfoel_translate_all_cron_status' : 'aitrfoel_translate_all_job';
        $job = get_option($option_key, []);

        if (!empty($job) && in_array($job['status'] ?? '', ['processing', 'pending'])) {
            $job['status'] = 'paused';
            update_option($option_key, $job);
        }

        wp_send_json_success(['message' => __('Translation paused.', 'ai-translator-for-elementor-and-polylang')]);
    }

    /**
     * AJAX: Resume translation
     */
    public function ajax_resume() {
        check_ajax_referer('aitrfoel_translate_all_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'ai-translator-for-elementor-and-polylang'));
        }

        $mode = sanitize_text_field($_POST['mode'] ?? 'now');
        $option_key = ($mode === 'scheduled') ? 'aitrfoel_translate_all_cron_status' : 'aitrfoel_translate_all_job';
        $job = get_option($option_key, []);

        if (!empty($job) && ($job['status'] ?? '') === 'paused') {
            $job['status'] = 'processing';
            update_option($option_key, $job);
        }

        wp_send_json_success(['message' => __('Translation resumed.', 'ai-translator-for-elementor-and-polylang')]);
    }

    /**
     * AJAX: Cancel translation
     */
    public function ajax_cancel() {
        check_ajax_referer('aitrfoel_translate_all_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'ai-translator-for-elementor-and-polylang'));
        }

        $mode = sanitize_text_field($_POST['mode'] ?? 'now');

        if ($mode === 'scheduled') {
            // Cancel cron job
            $timestamp = wp_next_scheduled('aitrfoel_translate_all_cron_process');
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'aitrfoel_translate_all_cron_process');
            }
            wp_clear_scheduled_hook('aitrfoel_translate_all_cron_process');

            $job = get_option('aitrfoel_translate_all_cron_status', []);
            $job['status'] = 'cancelled';
            update_option('aitrfoel_translate_all_cron_status', $job);
        } else {
            $job = get_option('aitrfoel_translate_all_job', []);
            $job['status'] = 'cancelled';
            update_option('aitrfoel_translate_all_job', $job);
        }

        wp_send_json_success(['message' => __('Translation cancelled.', 'ai-translator-for-elementor-and-polylang')]);
    }

    /**
     * Process scheduled cron job (called by WP-Cron)
     */
    public static function process_cron_batch() {
        $job = get_option('aitrfoel_translate_all_cron_status', []);

        if (empty($job) || !in_array($job['status'] ?? '', ['pending', 'processing'])) {
            // No active job or job is done, unschedule
            wp_clear_scheduled_hook('aitrfoel_translate_all_cron_process');
            return;
        }

        // Update status to processing
        $job['status'] = 'processing';
        update_option('aitrfoel_translate_all_cron_status', $job);

        $queue = $job['queue'] ?? [];
        $completed = $job['completed_items'] ?? 0;
        $source_language = $job['source_language'] ?? 'auto';

        // Get current word usage
        $license_info = [];
        if (class_exists('AITRFOEL_License_Manager_Server')) {
            $license_manager = AITRFOEL_License_Manager_Server::get_instance();
            $license_info = $license_manager->get_license_status();
        }
        $words_left = isset($license_info['words_left']) ? $license_info['words_left'] : 0;

        // Process items until we run out of words or finish
        $words_used_this_batch = 0;
        $items_this_batch = 0;
        $max_items_per_batch = 5; // Process up to 5 items per cron run to avoid timeout

        $instance = self::get_instance();

        while ($completed < count($queue) && $items_this_batch < $max_items_per_batch) {
            $item = $queue[$completed];

            // Estimate words for this item (apply server usage factor)
            $estimated_words = $instance->get_post_word_count($item['post_id']) * self::SERVER_USAGE_FACTOR;

            // Check if we have enough words left (with some buffer)
            if ($words_left !== -1 && ($words_used_this_batch + $estimated_words) > $words_left) {
                // Not enough words — stop and wait for next billing cycle
                if (class_exists('AITRFOEL_Logger')) {
                    AITRFOEL_Logger::log(
                        sprintf('Translate All cron: pausing — need %d words but only %d left', $estimated_words, $words_left - $words_used_this_batch),
                        'info'
                    );
                }
                break;
            }

            $result = $instance->translate_single_post($item['post_id'], $item['language'], $source_language);

            $completed++;
            $items_this_batch++;

            if (is_wp_error($result)) {
                $job['errors'][] = [
                    'post_id'  => $item['post_id'],
                    'language' => $item['language'],
                    'error'    => $result->get_error_message(),
                ];
            } else {
                $words_used_this_batch += $estimated_words;
            }

            $job['completed_items'] = $completed;
        }

        // Check if job is complete
        if ($completed >= count($queue)) {
            $job['status'] = 'completed';
            wp_clear_scheduled_hook('aitrfoel_translate_all_cron_process');
        } else {
            // Schedule next run
            $job['next_run'] = time() + 300; // 5 minutes
        }

        update_option('aitrfoel_translate_all_cron_status', $job);

        if (class_exists('AITRFOEL_Logger')) {
            AITRFOEL_Logger::log(
                sprintf('Translate All cron: processed %d items, %d/%d total', $items_this_batch, $completed, count($queue)),
                'info'
            );
        }
    }
}
