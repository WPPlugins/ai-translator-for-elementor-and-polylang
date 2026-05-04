<?php
/**
 * Translation Metabox Class
 * Adds a translation widget to the post/page edit screen (postbox-container)
 * Shows translation status and allows quick translation from the editor
 */

if (!defined('ABSPATH')) exit;

class AITRFOEL_Translation_Metabox {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('add_meta_boxes', [$this, 'register_meta_boxes']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // AJAX handlers for the metabox
        add_action('wp_ajax_aitrfoel_metabox_translate', [$this, 'handle_metabox_translate']);
        add_action('wp_ajax_aitrfoel_metabox_status', [$this, 'handle_metabox_status']);
    }

    /**
     * Register meta boxes for pages and posts
     */
    public function register_meta_boxes() {
        $post_types = ['post', 'page'];

        foreach ($post_types as $post_type) {
            add_meta_box(
                'aitrfoel-translation-metabox',
                __('AI Translator', 'ai-translator-for-elementor-and-polylang'),
                [$this, 'render_metabox'],
                $post_type,
                'side',
                'default'
            );
        }
    }

    /**
     * Enqueue assets only on post/page edit screens
     */
    public function enqueue_assets($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'])) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || !in_array($screen->post_type, ['post', 'page'])) {
            return;
        }

        wp_enqueue_style(
            'aitrfoel-metabox',
            AITRFOEL_PLUGIN_URL . 'assets/css/metabox.css',
            [],
            AITRFOEL_VERSION
        );

        wp_enqueue_script(
            'aitrfoel-metabox',
            AITRFOEL_PLUGIN_URL . 'assets/js/metabox.js',
            ['jquery'],
            AITRFOEL_VERSION,
            true
        );

        wp_localize_script('aitrfoel-metabox', 'aitrfoelMetabox', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('aitrfoel_ajax_nonce'),
            'post_id'  => get_the_ID(),
            'strings'  => [
                'translating'          => __('Translating...', 'ai-translator-for-elementor-and-polylang'),
                'translate'            => __('Translate', 'ai-translator-for-elementor-and-polylang'),
                'translation_complete' => __('Translation completed!', 'ai-translator-for-elementor-and-polylang'),
                'translation_failed'   => __('Translation failed.', 'ai-translator-for-elementor-and-polylang'),
                'select_language'      => __('Please select at least one language.', 'ai-translator-for-elementor-and-polylang'),
                'confirm_translate'    => __('Start translating to the selected languages?', 'ai-translator-for-elementor-and-polylang'),
                'view'                 => __('View', 'ai-translator-for-elementor-and-polylang'),
                'edit'                 => __('Edit', 'ai-translator-for-elementor-and-polylang'),
                'draft'                => __('Draft', 'ai-translator-for-elementor-and-polylang'),
                'published'            => __('Published', 'ai-translator-for-elementor-and-polylang'),
                'pending'              => __('Pending', 'ai-translator-for-elementor-and-polylang'),
                'no_multilingual'      => __('Set up a multilingual plugin to enable quick translations.', 'ai-translator-for-elementor-and-polylang'),
                'no_content'           => __('No translatable content detected.', 'ai-translator-for-elementor-and-polylang'),
                'word_limit_reached'   => __('Word limit reached. Please upgrade your plan.', 'ai-translator-for-elementor-and-polylang'),
            ],
        ]);
    }

    /**
     * Render the metabox content
     */
    public function render_metabox($post) {
        $multilingual = class_exists('AITRFOEL_Multilingual_Manager')
            ? AITRFOEL_Multilingual_Manager::get_instance()
            : null;

        // No multilingual plugin
        if (!$multilingual || !$multilingual->is_active()) {
            $dashboard_url = admin_url('admin.php?page=ai-translator-for-elementor-and-polylang');
            echo '<p class="aitrfoel-mb-notice">';
            printf(
                /* translators: %1$s: opening link tag, %2$s: closing link tag */
                esc_html__('Set up your languages for quick translations. %1$sGo to Dashboard%2$s', 'ai-translator-for-elementor-and-polylang'),
                '<a href="' . esc_url($dashboard_url) . '">',
                '</a>'
            );
            echo '</p>';
            return;
        }

        // Languages must be configured
        if (!$multilingual->has_languages_configured()) {
            $detected = $multilingual->get_detected_plugin();
            $setup_url = ($detected === 'wpml')
                ? admin_url('admin.php?page=sitepress-multilingual-cms/menu/languages.php')
                : admin_url('admin.php?page=mlang');
            echo '<p class="aitrfoel-mb-notice">';
            printf(
                /* translators: %s: URL to language settings page */
                esc_html__('Please %sconfigure your languages%s first.', 'ai-translator-for-elementor-and-polylang'),
                '<a href="' . esc_url($setup_url) . '">',
                '</a>'
            );
            echo '</p>';
            return;
        }

        $post_lang      = $multilingual->get_post_language($post->ID);
        $translations   = $multilingual->get_post_translations($post->ID);
        $available_langs = $multilingual->get_available_languages();
        $default_lang   = $multilingual->get_default_language();

        // Detect content type
        $content_type = 'unknown';
        if (class_exists('AITRFOEL_Content_Detector')) {
            $detector     = AITRFOEL_Content_Detector::get_instance();
            $content_type = $detector->detect($post->ID);
        }

        // Determine which is the source post for this translation group
        $source_id = $post->ID;
        if (!empty($translations)) {
            // If the default language version exists, that's the source
            if (isset($translations[$default_lang]) && $translations[$default_lang] != $post->ID) {
                $source_id = $translations[$default_lang];
            }
        }

        $is_translation = ($source_id !== $post->ID);
        ?>

        <div class="aitrfoel-mb-wrapper" data-post-id="<?php echo absint($post->ID); ?>" data-source-id="<?php echo absint($source_id); ?>">

            <!-- Current language info -->
            <div class="aitrfoel-mb-current-lang">
                <span class="aitrfoel-mb-label"><?php esc_html_e('Language:', 'ai-translator-for-elementor-and-polylang'); ?></span>
                <strong><?php echo $post_lang ? esc_html(strtoupper($post_lang)) : esc_html__('Not set', 'ai-translator-for-elementor-and-polylang'); ?></strong>
                <?php if ($is_translation) : ?>
                    <span class="aitrfoel-mb-badge aitrfoel-mb-badge-translation"><?php esc_html_e('Translation', 'ai-translator-for-elementor-and-polylang'); ?></span>
                <?php else : ?>
                    <span class="aitrfoel-mb-badge aitrfoel-mb-badge-source"><?php esc_html_e('Source', 'ai-translator-for-elementor-and-polylang'); ?></span>
                <?php endif; ?>
            </div>

            <!-- Content type badge -->
            <div class="aitrfoel-mb-content-type">
                <span class="aitrfoel-mb-label"><?php esc_html_e('Content:', 'ai-translator-for-elementor-and-polylang'); ?></span>
                <?php
                $type_labels = [
                    'elementor'  => '🎨 Elementor',
                    'gutenberg'  => '📦 Gutenberg',
                    'classic'    => '📝 Classic',
                ];
                echo '<span class="aitrfoel-mb-type-badge">' . esc_html($type_labels[$content_type] ?? '❓ ' . __('Unknown', 'ai-translator-for-elementor-and-polylang')) . '</span>';
                ?>
            </div>

            <hr class="aitrfoel-mb-divider">

            <!-- Existing translations -->
            <div class="aitrfoel-mb-translations">
                <span class="aitrfoel-mb-label"><?php esc_html_e('Translations:', 'ai-translator-for-elementor-and-polylang'); ?></span>

                <ul class="aitrfoel-mb-lang-list" id="aitrfoel-mb-translations-list">
                    <?php
                    foreach ($available_langs as $code => $lang) {
                        $lang_name = is_object($lang)
                            ? ($lang->name ?? strtoupper($code))
                            : (is_array($lang) ? ($lang['name'] ?? strtoupper($code)) : strtoupper($code));

                        if ($code === $post_lang) {
                            // Current language
                            continue;
                        }

                        $has_translation = isset($translations[$code]) && $translations[$code];
                        $trans_post = $has_translation ? get_post($translations[$code]) : null;
                        $trans_status = $trans_post ? $trans_post->post_status : '';
                        ?>
                        <li class="aitrfoel-mb-lang-item <?php echo $has_translation ? 'has-translation' : 'no-translation'; ?>"
                            data-lang="<?php echo esc_attr($code); ?>">
                            <span class="aitrfoel-mb-lang-flag"><?php echo esc_html(strtoupper($code)); ?></span>
                            <span class="aitrfoel-mb-lang-name"><?php echo esc_html($lang_name); ?></span>
                            <?php if ($has_translation && $trans_post) : ?>
                                <span class="aitrfoel-mb-status aitrfoel-mb-status-<?php echo esc_attr($trans_status); ?>">
                                    <?php
                                    $status_map = [
                                        'publish' => __('Published', 'ai-translator-for-elementor-and-polylang'),
                                        'draft'   => __('Draft', 'ai-translator-for-elementor-and-polylang'),
                                        'pending' => __('Pending', 'ai-translator-for-elementor-and-polylang'),
                                        'private' => __('Private', 'ai-translator-for-elementor-and-polylang'),
                                    ];
                                    echo esc_html($status_map[$trans_status] ?? ucfirst($trans_status));
                                    ?>
                                </span>
                                <span class="aitrfoel-mb-actions">
                                    <a href="<?php echo esc_url(get_edit_post_link($trans_post->ID)); ?>" title="<?php esc_attr_e('Edit', 'ai-translator-for-elementor-and-polylang'); ?>">
                                        <span class="dashicons dashicons-edit"></span>
                                    </a>
                                    <a href="<?php echo esc_url(get_permalink($trans_post->ID)); ?>" target="_blank" title="<?php esc_attr_e('View', 'ai-translator-for-elementor-and-polylang'); ?>">
                                        <span class="dashicons dashicons-visibility"></span>
                                    </a>
                                </span>
                            <?php else : ?>
                                <span class="aitrfoel-mb-status aitrfoel-mb-status-missing">
                                    <?php esc_html_e('Missing', 'ai-translator-for-elementor-and-polylang'); ?>
                                </span>
                            <?php endif; ?>
                        </li>
                        <?php
                    }
                    ?>
                </ul>
            </div>

            <?php if (!$is_translation) : ?>
            <hr class="aitrfoel-mb-divider">

            <!-- Quick translate section -->
            <div class="aitrfoel-mb-translate-section">
                <span class="aitrfoel-mb-label"><?php esc_html_e('Quick Translate:', 'ai-translator-for-elementor-and-polylang'); ?></span>

                <div class="aitrfoel-mb-lang-checkboxes" id="aitrfoel-mb-target-langs">
                    <?php
                    foreach ($available_langs as $code => $lang) {
                        if ($code === $post_lang) {
                            continue;
                        }
                        $lang_name = is_object($lang)
                            ? ($lang->name ?? strtoupper($code))
                            : (is_array($lang) ? ($lang['name'] ?? strtoupper($code)) : strtoupper($code));

                        $has_translation = isset($translations[$code]) && $translations[$code];
                        ?>
                        <label class="aitrfoel-mb-lang-checkbox <?php echo $has_translation ? 'already-translated' : ''; ?>">
                            <input type="checkbox"
                                   name="aitrfoel_target_langs[]"
                                   value="<?php echo esc_attr($code); ?>"
                                   <?php echo $has_translation ? 'data-has-translation="1"' : ''; ?>>
                            <span><?php echo esc_html($lang_name); ?> (<?php echo esc_html(strtoupper($code)); ?>)</span>
                            <?php if ($has_translation) : ?>
                                <span class="aitrfoel-mb-retranslate-hint" title="<?php esc_attr_e('Will create a new translation (overwrites existing)', 'ai-translator-for-elementor-and-polylang'); ?>">⟳</span>
                            <?php endif; ?>
                        </label>
                        <?php
                    }
                    ?>
                </div>

                <div class="aitrfoel-mb-translate-actions">
                    <button type="button"
                            class="button button-primary aitrfoel-mb-translate-btn"
                            id="aitrfoel-mb-translate-btn"
                            data-post-id="<?php echo absint($post->ID); ?>">
                        🚀 <?php esc_html_e('Translate', 'ai-translator-for-elementor-and-polylang'); ?>
                    </button>
                    <span class="spinner" id="aitrfoel-mb-spinner"></span>
                </div>

                <!-- Progress area -->
                <div class="aitrfoel-mb-progress" id="aitrfoel-mb-progress" style="display:none;">
                    <div class="aitrfoel-mb-progress-bar">
                        <div class="aitrfoel-mb-progress-fill" id="aitrfoel-mb-progress-fill"></div>
                    </div>
                    <span class="aitrfoel-mb-progress-text" id="aitrfoel-mb-progress-text"></span>
                </div>

                <!-- Status message -->
                <div class="aitrfoel-mb-message" id="aitrfoel-mb-message" style="display:none;"></div>
            </div>
            <?php endif; ?>

            <?php if ($is_translation) : ?>
            <hr class="aitrfoel-mb-divider">

            <!-- Info: Quick translate available on source post -->
            <div class="aitrfoel-mb-source-hint">
                <span class="dashicons dashicons-info-outline" style="color: #0073aa; font-size: 16px; vertical-align: middle;"></span>
                <span style="color: #666; font-size: 12px;">
                    <?php esc_html_e('Quick Translate is available on the source post.', 'ai-translator-for-elementor-and-polylang'); ?>
                </span>
                <br>
                <a href="<?php echo esc_url(get_edit_post_link($source_id)); ?>" class="button button-small" style="margin-top: 8px;">
                    <?php esc_html_e('Go to Source Post →', 'ai-translator-for-elementor-and-polylang'); ?>
                </a>
            </div>
            <?php endif; ?>

            <!-- Link to full dashboard -->
            <div class="aitrfoel-mb-footer">
                <a href="<?php echo esc_url(admin_url('admin.php?page=ai-translator-for-elementor-and-polylang')); ?>">
                    <?php esc_html_e('Open Translation Dashboard →', 'ai-translator-for-elementor-and-polylang'); ?>
                </a>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Translate from metabox
     */
    public function handle_metabox_translate() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');

            if (!current_user_can('edit_posts') && !current_user_can('edit_pages')) {
                throw new Exception(__('Insufficient permissions.', 'ai-translator-for-elementor-and-polylang'));
            }

            $post_id      = absint($_POST['post_id'] ?? 0);
            $target_langs = isset($_POST['target_langs']) && is_array($_POST['target_langs'])
                ? array_map('sanitize_text_field', $_POST['target_langs'])
                : [];

            if (!$post_id || empty($target_langs)) {
                throw new Exception(__('Missing required parameters.', 'ai-translator-for-elementor-and-polylang'));
            }

            $post = get_post($post_id);
            if (!$post || !in_array($post->post_type, ['page', 'post'])) {
                throw new Exception(__('Invalid post.', 'ai-translator-for-elementor-and-polylang'));
            }

            // Get translation engine
            if (!class_exists('AITRFOEL_Translation_Engine')) {
                throw new Exception(__('Translation engine not available.', 'ai-translator-for-elementor-and-polylang'));
            }

            $engine  = AITRFOEL_Translation_Engine::get_instance();
            $results = [];
            $errors  = [];

            foreach ($target_langs as $lang) {
                $result = $engine->translate_page($post_id, $lang, 'auto');

                if (is_wp_error($result)) {
                    $errors[$lang] = $result->get_error_message();
                } else {
                    $new_post = get_post($result);
                    $results[$lang] = [
                        'post_id'   => $result,
                        'title'     => $new_post ? $new_post->post_title : '',
                        'status'    => $new_post ? $new_post->post_status : 'draft',
                        'edit_url'  => get_edit_post_link($result, 'raw'),
                        'view_url'  => get_permalink($result),
                    ];
                }
            }

            if (!empty($errors) && empty($results)) {
                // All failed
                wp_send_json_error([
                    'message' => __('All translations failed.', 'ai-translator-for-elementor-and-polylang'),
                    'errors'  => $errors,
                ]);
            } else {
                // Check if words are running low and append a nudge
                $nudge = null;
                if (class_exists('AITRFOEL_License_Manager_Server')) {
                    $status = AITRFOEL_License_Manager_Server::get_instance()->get_license_status();
                    $words_left  = isset($status['words_left'])  ? (int) $status['words_left']  : -1;
                    $word_limit  = isset($status['word_limit'])  ? (int) $status['word_limit']  : -1;
                    $is_free     = isset($status['plan']) && strtolower($status['plan']) === 'free';
                    if ($is_free && $word_limit > 0 && $words_left !== -1 && $words_left <= 200) {
                        $upgrade_url = (function_exists('eatp_fs') && eatp_fs()->is_registered())
                            ? eatp_fs()->get_upgrade_url()
                            : admin_url('admin.php?page=ai-translator-for-elementor-and-polylang-pricing');
                        $nudge = [
                            'words_left'  => $words_left,
                            'upgrade_url' => $upgrade_url,
                        ];
                    }
                }

                wp_send_json_success([
                    'message'       => sprintf(
                        /* translators: %d: number of successful translations */
                        __('%d translation(s) completed successfully.', 'ai-translator-for-elementor-and-polylang'),
                        count($results)
                    ),
                    'translations'  => $results,
                    'errors'        => $errors,
                    'upgrade_nudge' => $nudge,
                ]);
            }
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Get translation status for metabox refresh
     */
    public function handle_metabox_status() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');

            $post_id = absint($_POST['post_id'] ?? 0);
            if (!$post_id) {
                throw new Exception('Invalid post ID');
            }

            $multilingual = class_exists('AITRFOEL_Multilingual_Manager')
                ? AITRFOEL_Multilingual_Manager::get_instance()
                : null;

            if (!$multilingual || !$multilingual->is_active()) {
                throw new Exception('Multilingual plugin not active');
            }

            $translations   = $multilingual->get_post_translations($post_id);
            $available_langs = $multilingual->get_available_languages();
            $post_lang       = $multilingual->get_post_language($post_id);
            $status_data     = [];

            foreach ($available_langs as $code => $lang) {
                if ($code === $post_lang) {
                    continue;
                }

                $lang_name = is_object($lang)
                    ? ($lang->name ?? strtoupper($code))
                    : (is_array($lang) ? ($lang['name'] ?? strtoupper($code)) : strtoupper($code));

                $has_translation = isset($translations[$code]) && $translations[$code];
                $trans_post      = $has_translation ? get_post($translations[$code]) : null;

                $status_data[$code] = [
                    'name'       => $lang_name,
                    'translated' => $has_translation,
                    'status'     => $trans_post ? $trans_post->post_status : null,
                    'post_id'    => $trans_post ? $trans_post->ID : null,
                    'edit_url'   => $trans_post ? get_edit_post_link($trans_post->ID, 'raw') : null,
                    'view_url'   => $trans_post ? get_permalink($trans_post->ID) : null,
                ];
            }

            wp_send_json_success([
                'languages' => $status_data,
                'post_lang' => $post_lang,
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
}
