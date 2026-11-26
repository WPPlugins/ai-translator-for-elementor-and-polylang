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

        // Admin Top Bar translation integration
        add_action('admin_post_aitrfoel_admin_bar_translate', [$this, 'handle_admin_bar_translate']);
        add_action('wp_head', [$this, 'admin_bar_quick_translate_css']);
        add_action('admin_head', [$this, 'admin_bar_quick_translate_css']);
        add_action('wp_footer', [$this, 'render_admin_bar_toast']);
        add_action('admin_notices', [$this, 'render_admin_bar_toast']);
        add_action('wp_footer', [$this, 'render_admin_bar_loading_overlay']);
        add_action('admin_footer', [$this, 'render_admin_bar_loading_overlay']);

        // AJAX handlers for the metabox
        add_action('wp_ajax_aitrfoel_metabox_translate', [$this, 'handle_metabox_translate']);
        add_action('wp_ajax_aitrfoel_metabox_status', [$this, 'handle_metabox_status']);
    }

    /**
     * Register meta boxes with HIGH priority so it sits at the top of the sidebar
     */
    public function register_meta_boxes() {
        $post_types = ['post', 'page'];
        $public_cpts = get_post_types(['public' => true], 'names');
        if (!empty($public_cpts)) {
            $post_types = array_unique(array_merge($post_types, array_values($public_cpts)));
        }

        foreach ($post_types as $post_type) {
            // Exclude attachments / internal types
            if (in_array($post_type, ['attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset'], true)) {
                continue;
            }

            add_meta_box(
                'aitrfoel-translation-metabox',
                __('Meep Translator', 'ai-translator-for-elementor-and-polylang'),
                [$this, 'render_metabox'],
                $post_type,
                'side',
                'high' // High priority places it at the very top of sidebar
            );
        }
    }

    /**
     * Enqueue assets only on post/page edit screens
     */
    public function enqueue_assets($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
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
     * Add Quick Translate / Retranslate node to WordPress Admin Bar
     * (Deprecated: unified into AITRFOEL_Admin_Bar under 'aitrfoel-admin-bar-hub')
     *
     * @param WP_Admin_Bar $wp_admin_bar
     */
    public function add_admin_bar_translate_menu($wp_admin_bar) {
        // Unified into AITRFOEL_Admin_Bar
        return;
    }

    /**
     * Handle Admin Bar Translate / Retranslate GET requests
     */
    public function handle_admin_bar_translate() {
        $post_id     = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        $target_lang = isset($_GET['target_lang']) ? sanitize_text_field($_GET['target_lang']) : '';
        $redirect_to = isset($_GET['redirect_to']) ? esc_url_raw(rawurldecode($_GET['redirect_to'])) : home_url('/');

        if (!$post_id || empty($target_lang)) {
            wp_die(esc_html__('Invalid translation request parameters.', 'ai-translator-for-elementor-and-polylang'));
        }

        check_admin_referer('aitrfoel_ab_trans_' . $post_id);

        if (!current_user_can('edit_post', $post_id)) {
            wp_die(esc_html__('You do not have permission to translate this content.', 'ai-translator-for-elementor-and-polylang'));
        }

        if (!class_exists('AITRFOEL_Translation_Engine')) {
            wp_die(esc_html__('Translation engine is not available.', 'ai-translator-for-elementor-and-polylang'));
        }

        $engine = AITRFOEL_Translation_Engine::get_instance();
        $result = $engine->translate_page($post_id, $target_lang, 'auto');

        if (is_wp_error($result)) {
            $err_msg = $result->get_error_message();
            $target_url = add_query_arg('aitrfoel_err', rawurlencode($err_msg), $redirect_to);
            wp_safe_redirect($target_url);
            exit;
        }

        // Successfully translated: redirect to the translated post permalink (or redirect_to with success notice)
        $translated_permalink = get_permalink($result);
        $final_url = $translated_permalink ?: $redirect_to;
        $final_url = add_query_arg('aitrfoel_msg', rawurlencode(sprintf(/* translators: %s: target language */ __('Page successfully translated to %s!', 'ai-translator-for-elementor-and-polylang'), strtoupper($target_lang))), $final_url);

        wp_safe_redirect($final_url);
        exit;
    }

    /**
     * Render toast banner when coming back from admin bar translation
     */
    public function render_admin_bar_toast() {
        if (!empty($_GET['aitrfoel_msg'])) {
            $msg = sanitize_text_field(rawurldecode($_GET['aitrfoel_msg']));
            echo '<div class="aitrfoel-topbar-toast aitrfoel-toast-success" style="position:fixed;top:40px;right:20px;z-index:999999;background:#10b981;color:#fff;padding:12px 18px;border-radius:8px;font-size:13px;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,0.18);display:flex;align-items:center;gap:8px;">';
            echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg> ' . esc_html($msg);
            echo '<button type="button" onclick="this.parentElement.remove();" style="background:none;border:none;color:#fff;cursor:pointer;font-size:16px;margin-left:8px;">&times;</button>';
            echo '</div>';
            echo '<script>setTimeout(function(){ var t = document.querySelector(".aitrfoel-topbar-toast"); if(t) t.remove(); }, 6000);</script>';
        } elseif (!empty($_GET['aitrfoel_err'])) {
            $err = sanitize_text_field(rawurldecode($_GET['aitrfoel_err']));
            echo '<div class="aitrfoel-topbar-toast aitrfoel-toast-error" style="position:fixed;top:40px;right:20px;z-index:999999;background:#ef4444;color:#fff;padding:12px 18px;border-radius:8px;font-size:13px;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,0.18);display:flex;align-items:center;gap:8px;">';
            echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> ' . esc_html($err);
            echo '<button type="button" onclick="this.parentElement.remove();" style="background:none;border:none;color:#fff;cursor:pointer;font-size:16px;margin-left:8px;">&times;</button>';
            echo '</div>';
            echo '<script>setTimeout(function(){ var t = document.querySelector(".aitrfoel-topbar-toast"); if(t) t.remove(); }, 8000);</script>';
        }
    }

    /**
     * Render full-screen frosted glass overlay during translation requests
     */
    public function render_admin_bar_loading_overlay() {
        if (!is_user_logged_in() || !is_admin_bar_showing()) {
            return;
        }
        ?>
        <div id="aitrfoel-translating-overlay" style="display:none;" aria-live="polite" role="dialog" aria-modal="true">
            <div class="aitrfoel-overlay-backdrop"></div>
            <div class="aitrfoel-overlay-card">
                <div class="aitrfoel-overlay-icon-wrap">
                    <div class="aitrfoel-overlay-pulse"></div>
                    <div class="aitrfoel-overlay-icon">
                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                        </svg>
                    </div>
                </div>
                <h3 class="aitrfoel-overlay-title"><?php esc_html_e('Translating with Meep AI...', 'ai-translator-for-elementor-and-polylang'); ?></h3>
                <p class="aitrfoel-overlay-desc"><?php esc_html_e('Updating layouts, content, and elements. Please keep this tab open.', 'ai-translator-for-elementor-and-polylang'); ?></p>
                <div class="aitrfoel-overlay-bar-track">
                    <div class="aitrfoel-overlay-bar-fill"></div>
                </div>
                <div class="aitrfoel-overlay-badge">
                    <span class="aitrfoel-overlay-dot"></span>
                    <span class="aitrfoel-overlay-badge-text"><?php esc_html_e('Preserving existing URL & layout structure', 'ai-translator-for-elementor-and-polylang'); ?></span>
                </div>
            </div>
        </div>
        <style id="aitrfoel-translating-overlay-css">
        #aitrfoel-translating-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            z-index: 99999999;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        #aitrfoel-translating-overlay .aitrfoel-overlay-backdrop {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.78);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            animation: aitrfoelFadeIn 0.25s ease-out;
        }
        #aitrfoel-translating-overlay .aitrfoel-overlay-card {
            position: relative;
            z-index: 2;
            background: #ffffff;
            border-radius: 18px;
            padding: 34px 30px;
            max-width: 420px;
            width: 90%;
            text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(255, 255, 255, 0.1);
            animation: aitrfoelScaleIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            box-sizing: border-box;
        }
        #aitrfoel-translating-overlay .aitrfoel-overlay-icon-wrap {
            position: relative;
            width: 60px;
            height: 60px;
            margin: 0 auto 16px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        #aitrfoel-translating-overlay .aitrfoel-overlay-pulse {
            position: absolute;
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: #1447e6;
            opacity: 0.18;
            animation: aitrfoelPulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        #aitrfoel-translating-overlay .aitrfoel-overlay-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: linear-gradient(135deg, #1447e6 0%, #0f35bb 100%);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 18px -4px rgba(20, 71, 230, 0.45);
        }
        #aitrfoel-translating-overlay .aitrfoel-overlay-title {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 8px;
            line-height: 1.3;
        }
        #aitrfoel-translating-overlay .aitrfoel-overlay-desc {
            font-size: 13px;
            color: #64748b;
            margin: 0 0 20px;
            line-height: 1.5;
        }
        #aitrfoel-translating-overlay .aitrfoel-overlay-bar-track {
            width: 100%;
            height: 6px;
            background: #f1f5f9;
            border-radius: 999px;
            overflow: hidden;
            position: relative;
            margin-bottom: 18px;
        }
        #aitrfoel-translating-overlay .aitrfoel-overlay-bar-fill {
            position: absolute;
            top: 0;
            left: 0;
            height: 100%;
            width: 40%;
            background: linear-gradient(90deg, #1447e6, #06b6d4);
            border-radius: 999px;
            animation: aitrfoelBarMove 1.6s ease-in-out infinite alternate;
        }
        #aitrfoel-translating-overlay .aitrfoel-overlay-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 999px;
            padding: 5px 12px;
            font-size: 11px;
            font-weight: 500;
            color: #475569;
        }
        #aitrfoel-translating-overlay .aitrfoel-overlay-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #10b981;
        }
        @keyframes aitrfoelFadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes aitrfoelScaleIn { from { opacity: 0; transform: scale(0.92); } to { opacity: 1; transform: scale(1); } }
        @keyframes aitrfoelPulse { 0%, 100% { transform: scale(1); opacity: 0.18; } 50% { transform: scale(1.35); opacity: 0.05; } }
        @keyframes aitrfoelBarMove { 0% { left: 0%; width: 25%; } 50% { width: 55%; } 100% { left: 60%; width: 40%; } }
        </style>
        <script id="aitrfoel-translating-overlay-js">
        (function() {
            function bindOverlay() {
                var links = document.querySelectorAll('#wpadminbar a[href*="aitrfoel_admin_bar_translate"]');
                links.forEach(function(link) {
                    if (link.dataset.aitrfoelBound) return;
                    link.dataset.aitrfoelBound = '1';
                    link.addEventListener('click', function() {
                        var overlay = document.getElementById('aitrfoel-translating-overlay');
                        if (overlay) {
                            var titleText = (link.textContent || '').trim().toLowerCase();
                            var titleEl = overlay.querySelector('.aitrfoel-overlay-title');
                            if (titleEl) {
                                if (titleText.indexOf('retranslate') !== -1) {
                                    titleEl.textContent = '<?php echo esc_js(__('Re-translating page with Meep AI...', 'ai-translator-for-elementor-and-polylang')); ?>';
                                } else {
                                    titleEl.textContent = '<?php echo esc_js(__('Translating page with Meep AI...', 'ai-translator-for-elementor-and-polylang')); ?>';
                                }
                            }
                            overlay.style.display = 'flex';
                        }
                    });
                });
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', bindOverlay);
            } else {
                bindOverlay();
            }
        })();
        </script>
        <?php
    }

    /**
     * Custom CSS for Admin Bar Translate node
     */
    public function admin_bar_quick_translate_css() {
        if (!is_admin_bar_showing()) {
            return;
        }
        echo '<style id="aitrfoel-admin-bar-translate-css">
#wpadminbar #wp-admin-bar-aitrfoel-top-bar-translate > .ab-item {
    display: flex !important;
    align-items: center !important;
    height: 32px !important;
    color: #fff !important;
}
@media screen and (max-width: 782px) {
    #wpadminbar #wp-admin-bar-aitrfoel-top-bar-translate > .ab-item {
        height: 46px !important;
    }
}
#wpadminbar #wp-admin-bar-aitrfoel-top-bar-translate .ab-icon {
    float: none !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 15px !important;
    height: 15px !important;
    min-width: 15px !important;
    margin: 0 6px 0 0 !important;
    padding: 0 !important;
    line-height: 1 !important;
}
#wpadminbar #wp-admin-bar-aitrfoel-top-bar-translate .ab-icon svg {
    display: block !important;
    width: 15px !important;
    height: 15px !important;
    margin: 0 !important;
    padding: 0 !important;
}
#wpadminbar #wp-admin-bar-aitrfoel-top-bar-translate .ab-label {
    display: inline-block !important;
    height: auto !important;
    line-height: 1.2 !important;
    margin: 0 !important;
    padding: 0 !important;
}
#wpadminbar #wp-admin-bar-aitrfoel-top-bar-translate:hover > .ab-item {
    background: #1447e6 !important;
    color: #fff !important;
}
#wpadminbar #wp-admin-bar-aitrfoel-top-bar-translate .ab-submenu .ab-item {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
}
</style>';
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

        $post_lang       = $multilingual->get_post_language($post->ID);
        $translations    = $multilingual->get_post_translations($post->ID);
        $available_langs = $multilingual->get_available_languages();
        $default_lang    = $multilingual->get_default_language();

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

            <!-- Header: Language and Content Type badges -->
            <div class="aitrfoel-mb-header-card">
                <div class="aitrfoel-mb-meta-item">
                    <span class="aitrfoel-mb-meta-label"><?php esc_html_e('Language', 'ai-translator-for-elementor-and-polylang'); ?></span>
                    <div class="aitrfoel-mb-lang-pill-wrap">
                        <span class="aitrfoel-mb-lang-code"><?php echo $post_lang ? esc_html(strtoupper($post_lang)) : esc_html__('Not set', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        <?php if ($is_translation) : ?>
                            <span class="aitrfoel-mb-badge aitrfoel-mb-badge-translation"><?php esc_html_e('Translation', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        <?php else : ?>
                            <span class="aitrfoel-mb-badge aitrfoel-mb-badge-source"><?php esc_html_e('Source', 'ai-translator-for-elementor-and-polylang'); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="aitrfoel-mb-meta-item">
                    <span class="aitrfoel-mb-meta-label"><?php esc_html_e('Content Engine', 'ai-translator-for-elementor-and-polylang'); ?></span>
                    <?php
                    $type_labels = [
                        'elementor'  => 'Elementor',
                        'gutenberg'  => 'Gutenberg',
                        'classic'    => 'Classic',
                        'woocommerce'=> 'WooCommerce',
                    ];
                    $type_name = $type_labels[$content_type] ?? ucfirst($content_type);
                    echo '<span class="aitrfoel-mb-type-badge aitrfoel-mb-type-' . esc_attr($content_type) . '">' . esc_html($type_name) . '</span>';
                    ?>
                </div>
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
                <div class="aitrfoel-mb-section-header">
                    <span class="aitrfoel-mb-label"><?php esc_html_e('Quick Translate:', 'ai-translator-for-elementor-and-polylang'); ?></span>
                    <button type="button" class="button-link aitrfoel-mb-toggle-all" id="aitrfoel-mb-select-all"><?php esc_html_e('Select all', 'ai-translator-for-elementor-and-polylang'); ?></button>
                </div>

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
                        <label class="aitrfoel-mb-lang-checkbox <?php echo $has_translation ? 'already-translated' : 'missing-translation'; ?>">
                            <input type="checkbox"
                                   name="aitrfoel_target_langs[]"
                                   value="<?php echo esc_attr($code); ?>"
                                   <?php echo $has_translation ? 'data-has-translation="1"' : 'checked'; ?>>
                            <span class="aitrfoel-mb-checkbox-flag"><?php echo esc_html(strtoupper($code)); ?></span>
                            <span class="aitrfoel-mb-checkbox-name"><?php echo esc_html($lang_name); ?></span>
                            <?php if ($has_translation) : ?>
                                <span class="aitrfoel-mb-retranslate-hint" title="<?php esc_attr_e('Existing translation will be replaced', 'ai-translator-for-elementor-and-polylang'); ?>"><?php esc_html_e('Retranslate', 'ai-translator-for-elementor-and-polylang'); ?></span>
                            <?php else : ?>
                                <span class="aitrfoel-mb-missing-pill"><?php esc_html_e('New', 'ai-translator-for-elementor-and-polylang'); ?></span>
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
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                        <span><?php esc_html_e('Translate with AI', 'ai-translator-for-elementor-and-polylang'); ?></span>
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
                <span class="dashicons dashicons-info-outline" style="color: #1447e6; font-size: 16px; vertical-align: middle;"></span>
                <span style="color: #64748b; font-size: 12px;">
                    <?php esc_html_e('Quick Translate is managed from the primary source post.', 'ai-translator-for-elementor-and-polylang'); ?>
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
            if (!$post) {
                throw new Exception(__('Invalid post.', 'ai-translator-for-elementor-and-polylang'));
            }

            // If translating from a translated post, determine the primary source post
            $multilingual = class_exists('AITRFOEL_Multilingual_Manager')
                ? AITRFOEL_Multilingual_Manager::get_instance()
                : null;
            $source_id = $post_id;
            if ($multilingual && $multilingual->is_active()) {
                $translations = $multilingual->get_post_translations($post_id);
                $default_lang = $multilingual->get_default_language();
                if (!empty($translations[$default_lang]) && (int) $translations[$default_lang] !== $post_id) {
                    $source_id = (int) $translations[$default_lang];
                }
            }

            // Get translation engine
            if (!class_exists('AITRFOEL_Translation_Engine')) {
                throw new Exception(__('Translation engine not available.', 'ai-translator-for-elementor-and-polylang'));
            }

            $engine  = AITRFOEL_Translation_Engine::get_instance();
            $results = [];
            $errors  = [];

            foreach ($target_langs as $lang) {
                $result = $engine->translate_page($source_id, $lang, 'auto');

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
                        $upgrade_url = (function_exists('eatp_fs') && is_object(eatp_fs()) && method_exists(eatp_fs(), 'is_registered') && eatp_fs()->is_registered())
                            ? eatp_fs()->get_upgrade_url()
                            : ((defined('AITRFOEL_VERSION_TYPE') && AITRFOEL_VERSION_TYPE === 'pro')
                                ? 'https://meeptech.com/upgrade?utm_source=wp-plugin&utm_medium=free-plugin&utm_campaign=pro-upgrade'
                                : 'https://meeptech.com/products/ai-translator-for-elementor-polylang/?utm_source=wp-plugin&utm_medium=editor-metabox&utm_campaign=pro-upgrade#pricing');
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
