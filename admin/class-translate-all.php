<?php
/**
 * Translate All Admin Page
 * Allows users to translate all content on the site in bulk,
 * choosing post types, viewing word calculations, and scheduling cron jobs.
 */

if (!defined('ABSPATH')) exit;

class AITRFOEL_Translate_All {

    /**
     * Server usage factor: the API server counts both source and translated
     * words, so actual consumption is approximately 2× the source word count.
     */
    const SERVER_USAGE_FACTOR = 2;

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
            'word_limit' => 1800,
            'words_used' => 0,
            'words_left' => 1800,
        ];
    }

    /**
     * Get upgrade URL
     */
    private function get_upgrade_url() {
        if (function_exists('eatp_fs') && eatp_fs()->is_registered()) {
            return eatp_fs()->get_upgrade_url();
        }
        return admin_url('admin.php?page=ai-translator-for-elementor-and-polylang-pricing');
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
                    ?>
                    <label class="aitrfoel-ta-post-type-card">
                        <input type="checkbox" name="post_types[]" value="<?php echo esc_attr($type_slug); ?>">
                        <div class="aitrfoel-ta-post-type-inner">
                            <span class="aitrfoel-ta-post-type-icon aitrfoel-ta-post-type-icon-<?php echo esc_attr($type_info['icon']); ?>" aria-hidden="true"></span>
                            <span class="aitrfoel-ta-post-type-name"><?php echo esc_html($type_info['label']); ?></span>
                            <span class="aitrfoel-ta-post-type-count"><?php echo esc_html($type_info['count']); ?> <?php esc_html_e('items', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Step 2: Select Target Languages -->
            <div class="aitrfoel-card aitrfoel-ta-step" id="ta-step-2">
                <h3><?php esc_html_e('Step 2: Select Target Languages', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                <p class="description"><?php esc_html_e('Choose which languages to translate your content into. Your default language content will be used as source.', 'ai-translator-for-elementor-and-polylang'); ?></p>

                <div class="aitrfoel-ta-selection-actions">
                    <button type="button" class="button button-small" id="ta-select-all-langs"><?php esc_html_e('Select All', 'ai-translator-for-elementor-and-polylang'); ?></button>
                    <button type="button" class="button button-small" id="ta-deselect-all-langs"><?php esc_html_e('Deselect All', 'ai-translator-for-elementor-and-polylang'); ?></button>
                </div>
                <div class="aitrfoel-ta-languages">
                    <?php if (!empty($languages)) : ?>
                        <?php foreach ($languages as $code => $lang) :
                            $name = is_object($lang) ? ($lang->name ?? strtoupper($code)) : (is_array($lang) ? ($lang['name'] ?? strtoupper($code)) : strtoupper($code));
                            $is_default = ($code === $default_lang);
                            if ($is_default) continue; // Skip default language (it's the source)
                        ?>
                        <label class="aitrfoel-ta-lang-card">
                            <input type="checkbox" name="target_languages[]" value="<?php echo esc_attr($code); ?>">
                            <div class="aitrfoel-ta-lang-inner">
                                <span class="aitrfoel-ta-lang-code"><?php echo esc_html(strtoupper($code)); ?></span>
                                <span class="aitrfoel-ta-lang-name"><?php echo esc_html($name); ?></span>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <div class="notice notice-warning inline">
                            <p><?php esc_html_e('No languages configured. Please set up languages in your multilingual plugin first.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
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

                <button type="button" class="button button-primary button-large" id="ta-scan-btn">
                    <?php esc_html_e('🔍 Scan Content', 'ai-translator-for-elementor-and-polylang'); ?>
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
                            <?php esc_html_e('Translate All Now', 'ai-translator-for-elementor-and-polylang'); ?>
                        </button>
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
                        <a href="<?php echo esc_url($this->get_upgrade_url()); ?>" class="button button-primary button-large" id="ta-upgrade-btn">
                            <svg class="aitrfoel-ta-btn-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17l-6.2 4.3 2.4-7.4L2 9.4h7.6L12 2z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
                            <?php esc_html_e('Upgrade Plan', 'ai-translator-for-elementor-and-polylang'); ?>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Progress Area -->
            <div class="aitrfoel-card aitrfoel-ta-step" id="ta-progress-area" style="display: none;">
                <h3><?php esc_html_e('Translation Progress', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                <div class="aitrfoel-ta-progress-wrap">
                    <div class="aitrfoel-ta-progress-bar">
                        <div class="aitrfoel-ta-progress-fill" id="ta-progress-fill" style="width: 0%"></div>
                    </div>
                    <div class="aitrfoel-ta-progress-stats">
                        <span id="ta-progress-text">0%</span>
                        <span id="ta-progress-detail"></span>
                    </div>
                </div>
                <div id="ta-progress-log" class="aitrfoel-ta-progress-log"></div>
                <div class="aitrfoel-ta-progress-actions">
                    <button type="button" class="button button-secondary" id="ta-pause-btn" style="display:none;">
                        ⏸ <?php esc_html_e('Pause', 'ai-translator-for-elementor-and-polylang'); ?>
                    </button>
                    <button type="button" class="button button-primary" id="ta-resume-btn" style="display:none;">
                        ▶ <?php esc_html_e('Resume', 'ai-translator-for-elementor-and-polylang'); ?>
                    </button>
                    <button type="button" class="button button-link-delete" id="ta-cancel-btn">
                        ✕ <?php esc_html_e('Cancel', 'ai-translator-for-elementor-and-polylang'); ?>
                    </button>
                </div>
            </div>

            <!-- Active Scheduled Job Status -->
            <?php if (!empty($cron_status) && isset($cron_status['status']) && in_array($cron_status['status'], ['pending', 'processing'])) : ?>
            <div class="aitrfoel-card aitrfoel-ta-step aitrfoel-ta-cron-status">
                <h3><?php esc_html_e('📅 Active Scheduled Translation', 'ai-translator-for-elementor-and-polylang'); ?></h3>
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

        // Custom post types that support editor
        $custom_types = get_post_types([
            'public'   => true,
            '_builtin' => false,
        ], 'objects');

        foreach ($custom_types as $cpt) {
            if (!post_type_supports($cpt->name, 'editor')) continue;
            $count = $this->count_default_language_posts($cpt->name, $non_default_langs);
            if ($count === 0) continue; // Hide empty custom post types
            $types[$cpt->name] = [
                'label' => $cpt->label,
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
     * that have Elementor data and are not already translations.
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
                // Calculate word count
                $word_count = $this->get_post_word_count($post->ID);
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
                $wc = $this->get_post_word_count($post->ID);
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
     * Uses the same parsers as the translation engine for accurate estimates.
     */
    private function get_post_word_count($post_id) {
        $content_detector = class_exists('AITRFOEL_Content_Detector') ? AITRFOEL_Content_Detector::get_instance() : null;
        $content_type = $content_detector ? $content_detector->detect($post_id) : 'classic';

        $word_count = 0;
        $translation_map = [];

        switch ($content_type) {
            case 'elementor':
                $elementor_data = get_post_meta($post_id, '_elementor_data', true);
                if (!empty($elementor_data)) {
                    $elements = json_decode($elementor_data, true);
                    if (json_last_error() === JSON_ERROR_NONE && !empty($elements)) {
                        if (class_exists('AITRFOEL_Widget_Parser')) {
                            $parser = AITRFOEL_Widget_Parser::get_instance();
                            $translation_map = $parser->parse_for_translation($elements);
                        }
                    }
                }
                break;

            case 'gutenberg':
                if (class_exists('AITRFOEL_Gutenberg_Parser')) {
                    $parser = AITRFOEL_Gutenberg_Parser::get_instance();
                    $translation_map = $parser->parse_for_translation($post_id);
                }
                break;

            case 'classic':
                if (class_exists('AITRFOEL_Classic_Parser')) {
                    $parser = AITRFOEL_Classic_Parser::get_instance();
                    $translation_map = $parser->parse_for_translation($post_id);
                }
                break;

            case 'woocommerce':
                // WooCommerce product with no editor content — count excerpt words
                $wc_post = get_post($post_id);
                if ($wc_post && !empty(trim($wc_post->post_excerpt))) {
                    $translation_map['wc_excerpt'] = [
                        'original' => $wc_post->post_excerpt,
                        'path' => 'excerpt',
                        'type' => 'text',
                    ];
                }
                break;

            default:
        }

        // Count words from parsed translatable strings (same as translation engine)
        foreach ($translation_map as $entry) {
            $text = is_array($entry) ? ($entry['original'] ?? '') : $entry;
            if (is_string($text) && !empty($text)) {
                $word_count += AITRFOEL_Translation_Engine::count_words(wp_strip_all_tags($text));
            }
        }

        // Add title words
        $post = get_post($post_id);
        if ($post && !empty($post->post_title)) {
            $word_count += AITRFOEL_Translation_Engine::count_words(wp_strip_all_tags($post->post_title));
        }

        return $word_count;
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

        // Process first batch immediately
        $this->process_immediate_batch($job_id);
    }

    /**
     * Process a batch of immediate translations
     */
    private function process_immediate_batch($job_id) {
        $job = get_option('aitrfoel_translate_all_job', []);
        if (empty($job) || $job['job_id'] !== $job_id) {
            return;
        }

        $queue = $job['queue'] ?? [];
        $completed = $job['completed_items'] ?? 0;
        $source_language = $job['source_language'] ?? 'auto';

        // Process one item at a time (AJAX polling will call back for more)
        if ($completed < count($queue)) {
            $item = $queue[$completed];

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
                $count = (int) get_option('aitrfoel_successful_translations', 0);
                update_option('aitrfoel_successful_translations', $count + 1);

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
            if ($job['completed_items'] >= count($queue)) {
                $job['status'] = 'completed';
            }

            update_option('aitrfoel_translate_all_job', $job);
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

        $review_eligible = !get_option('aitrfoel_review_dismissed') && ((int) get_option('aitrfoel_successful_translations', 0) >= 1);

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
